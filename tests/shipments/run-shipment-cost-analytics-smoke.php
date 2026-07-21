<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsFilter;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsQuery;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsService;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostThresholdPolicy;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function shipment_cost_analytics_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ?? '' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone(): DateTimeZone {
		return new DateTimeZone( 'Europe/Moscow' );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string {
		return '2026-07-21';
	}
}
if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( array $args ): array {
		unset( $args );
		return $GLOBALS['shipment_cost_analytics_orders'] ?? array();
	}
}

final class ShipmentCostAnalyticsCarrier implements CarrierAdapterInterface {
	public function __construct( private string $key, private string $name ) {}
	public function get_identity(): CarrierIdentity { return new CarrierIdentity( $this->key, $this->name ); }
	public function get_capabilities(): CarrierCapabilities { return new CarrierCapabilities(); }
	public function supports_country( string $countryCode ): bool { unset( $countryCode ); return true; }
	public function quote( QuoteRequest $request ): DeliveryQuote { throw new RuntimeException( 'Analytics must not call carrier APIs.' ); }
}

final class ShipmentCostAnalyticsDate extends DateTimeImmutable {
	public function date( string $format ): string {
		return $this->format( $format );
	}
}

final class ShipmentCostAnalyticsOrder {
	/** @param array<string,mixed> $shipments */
	public function __construct(
		private int $id,
		private string $number,
		private string $created_at,
		private int $base_kopecks,
		private array $shipments
	) {
	}

	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return $this->number; }
	public function get_date_created(): ShipmentCostAnalyticsDate { return new ShipmentCostAnalyticsDate( $this->created_at ); }
	public function get_edit_order_url(): string { return 'https://example.test/order/' . $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed {
		unset( $single );
		if ( OrderShipmentRepository::META_KEY === $key ) {
			return $this->shipments;
		}
		if ( OrderShippingMetaPersister::CALCULATION_META_KEY === $key ) {
			return array( 'api' => array( 'api_base_price_kopecks' => $this->base_kopecks ) );
		}

		return '';
	}
}

function shipment_cost_analytics_service(): ShipmentCostAnalyticsService {
	$registry = new CarrierRegistry();
	$registry->register( new ShipmentCostAnalyticsCarrier( 'alpha', 'Alpha Carrier' ) );
	$registry->register( new ShipmentCostAnalyticsCarrier( 'beta', 'Beta Carrier' ) );
	$registry->register( new ShipmentCostAnalyticsCarrier( 'fresh', 'Fresh Dynamic Carrier' ) );

	return new ShipmentCostAnalyticsService(
		new ShipmentCostAnalyticsQuery(),
		new OrderShipmentRepository(),
		new ShipmentBaseApiCostResolver(),
		$registry,
		new ShipmentCostThresholdPolicy()
	);
}

$GLOBALS['shipment_cost_analytics_orders'] = array(
	new ShipmentCostAnalyticsOrder(
		1001,
		'WC-1001',
		'2026-07-20 10:00:00',
		10000,
		array(
			'alpha' => array( 'carrier_key' => 'alpha', 'service_key' => 'alpha_service', 'service_title' => 'Alpha Door', 'tracking_number' => 'A-1', 'actual_cost_kopecks' => 12000, 'actual_cost_source' => 'carrier_api', 'actual_cost_source_detail' => 'alpha_create' ),
			'beta' => array( 'carrier_key' => 'beta', 'service_key' => 'beta_service', 'service_title' => 'Beta Pickup', 'barcode' => 'B-1', 'actual_cost_kopecks' => 9000, 'actual_cost_source' => 'manual', 'actual_cost_source_detail' => 'admin_manual' ),
		)
	),
	new ShipmentCostAnalyticsOrder(
		1002,
		'CUSTOM-2',
		'2026-07-19 12:00:00',
		20000,
		array(
			'alpha' => array( 'carrier_key' => 'alpha', 'service_title' => 'Alpha Pickup', 'tracking_number' => 'A-2' ),
			'failed' => array( 'carrier_key' => 'alpha', 'status' => 'failed', 'error_code' => 'failed' ),
		)
	),
	new ShipmentCostAnalyticsOrder(
		1003,
		'WC-1003',
		'2026-07-18 12:00:00',
		5000,
		array(
			'fresh' => array( 'carrier_key' => 'fresh', 'service_title' => 'Fresh Service', 'external_id' => 'F-1', 'actual_cost_kopecks' => 5150, 'actual_cost_source' => 'carrier_status' ),
		)
	),
);

$service = shipment_cost_analytics_service();
$options = $service->carrier_options();
shipment_cost_analytics_assert( isset( $options['fresh'] ) && 'Fresh Dynamic Carrier' === $options['fresh'], 'Dynamic carrier must appear in filter options from registry.' );

$filter = ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'per_page' => 25 ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) );
$result = $service->result( $filter );
shipment_cost_analytics_assert( 4 === $result->total_rows && 4 === count( $result->rows ) && 1 === $result->total_pages, 'Analytics must build one row per created shipment.' );
shipment_cost_analytics_assert( 4 === $result->summary->shipment_count && 3 === $result->summary->with_actual_count && 1 === $result->summary->without_actual_count, 'Summary must count actual/missing actual shipments over full dataset.' );
shipment_cost_analytics_assert( 45000 === $result->summary->planned_total_kopecks && 26150 === $result->summary->actual_total_kopecks, 'Summary must aggregate plan and actual totals.' );
shipment_cost_analytics_assert( 1150 === $result->summary->difference_total_kopecks, 'Comparable economy/overrun must sum only comparable rows.' );
shipment_cost_analytics_assert( 1 === $result->summary->over_threshold_count && 3333 === $result->summary->over_threshold_share_basis_points(), 'Summary must count and share over-threshold rows.' );

$actual_only = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month' ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) ) );
shipment_cost_analytics_assert( 3 === $actual_only->total_rows, 'Default filter must show only rows with actual cost.' );

$carrier_filter = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'carrier' => 'beta' ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) ) );
shipment_cost_analytics_assert( 1 === $carrier_filter->total_rows && 'beta' === $carrier_filter->rows[0]->carrier_key && 'Beta Carrier' === $carrier_filter->rows[0]->carrier_title, 'Concrete carrier filter must use registry titles.' );

$search_id = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'order_search' => '1001' ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) ) );
shipment_cost_analytics_assert( 2 === $search_id->total_rows, 'Order ID search must work with other filters.' );
$search_number = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'order_search' => 'CUSTOM-2' ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) ) );
shipment_cost_analytics_assert( 1 === $search_number->total_rows && null === $search_number->rows[0]->actual_cost_kopecks, 'Order number search must support custom order numbers.' );

$custom_invalid = ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'custom', 'date_from' => '2026-07-22', 'date_to' => '2026-07-01' ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) );
shipment_cost_analytics_assert( 'month' === $custom_invalid->period && array() !== $custom_invalid->notices, 'Invalid custom range must fall back to safe month period with notice.' );
foreach ( array( 'week', 'month', 'quarter', 'year' ) as $period ) {
	$preset = ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => $period ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) );
	shipment_cost_analytics_assert( $period === $preset->period && '' !== $preset->date_from && '' !== $preset->date_to, 'Preset period must normalize: ' . $period );
}

$sorted_actual = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'orderby' => 'actual', 'order' => 'desc', 'per_page' => 100 ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) ) );
$last = $sorted_actual->rows[ count( $sorted_actual->rows ) - 1 ];
shipment_cost_analytics_assert( null === $last->actual_cost_kopecks, 'Null actual costs must sort last for DESC.' );

foreach ( array( 'order_number', 'date', 'carrier', 'base', 'actual', 'difference', 'difference_percent' ) as $orderby ) {
	$asc = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'orderby' => $orderby, 'order' => 'asc', 'per_page' => 100 ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) ) );
	$desc = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'orderby' => $orderby, 'order' => 'desc', 'per_page' => 100 ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) ) );
	shipment_cost_analytics_assert( 4 === count( $asc->rows ) && 4 === count( $desc->rows ), 'Sortable column must keep row set: ' . $orderby );
}

shipment_cost_analytics_assert( 2000 === $result->rows[0]->difference_kopecks && 2000 === $result->rows[0]->difference_percent_basis_points, 'Difference must be computed in integer kopecks and basis points.' );

$paged_orders = array();
for ( $i = 1; $i <= 30; ++$i ) {
	$paged_orders[] = new ShipmentCostAnalyticsOrder(
		2000 + $i,
		'PG-' . $i,
		'2026-07-10 10:00:00',
		10000,
		array(
			'alpha' => array( 'carrier_key' => 'alpha', 'tracking_number' => 'PG-' . $i, 'actual_cost_kopecks' => 10000, 'actual_cost_source' => 'carrier_api' ),
		)
	);
}
$GLOBALS['shipment_cost_analytics_orders'] = $paged_orders;
$paged = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'per_page' => 25, 'paged' => 2 ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) ) );
shipment_cost_analytics_assert( 30 === $paged->total_rows && 5 === count( $paged->rows ) && 2 === $paged->total_pages && 30 === $paged->summary->shipment_count, 'Pagination must page rows while summary covers full filtered dataset.' );

echo "Shipment cost analytics smoke passed.\n";
