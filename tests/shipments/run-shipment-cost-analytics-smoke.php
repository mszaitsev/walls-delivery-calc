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
use WallsShop\WDC\Shipments\Admin\ShipmentCostAnalyticsAdminSection;
use WallsShop\WDC\Shipments\Analytics\OrderAnalyticsShipmentSelector;
use WallsShop\WDC\Shipments\Analytics\OrderSelectedDeliveryIdentityResolver;
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
		unset( $type );
		return '2026-07-21';
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $value, string $domain = 'default' ): string {
		unset( $domain );
		return $value;
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( string $value ): string {
		return $value;
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		return $url . '?' . http_build_query( $args );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( string $value ): string {
		return strip_tags( $value );
	}
}
if ( ! function_exists( 'number_format_i18n' ) ) {
	function number_format_i18n( float $number, int $decimals = 0 ): string {
		return number_format( $number, $decimals, '.', '' );
	}
}
if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( array $args ): object {
		$GLOBALS['shipment_cost_analytics_wc_get_orders_calls'][] = $args;
		$orders = array_values( $GLOBALS['shipment_cost_analytics_orders'] ?? array() );
		usort(
			$orders,
			static fn( ShipmentCostAnalyticsOrder $a, ShipmentCostAnalyticsOrder $b ): int =>
				strcmp( $b->created_at_value(), $a->created_at_value() )
		);
		$limit = max( 1, (int) ( $args['limit'] ?? 10 ) );
		$page = max( 1, (int) ( $args['page'] ?? 1 ) );
		$ids = array_map(
			static fn( ShipmentCostAnalyticsOrder $order ): int => $order->get_id(),
			array_slice( $orders, ( $page - 1 ) * $limit, $limit )
		);

		return (object) array(
			'orders' => $ids,
			'total' => count( $orders ),
			'max_num_pages' => (int) ceil( count( $orders ) / $limit ),
		);
	}
}
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( int $order_id ): ?ShipmentCostAnalyticsOrder {
		$GLOBALS['shipment_cost_analytics_wc_get_order_calls'][] = $order_id;

		return $GLOBALS['shipment_cost_analytics_order_map'][ $order_id ] ?? null;
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
		private array $shipments,
		private ?string $selected_carrier,
		private string $selected_service = ''
	) {
	}

	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return $this->number; }
	public function get_date_created(): ShipmentCostAnalyticsDate { return new ShipmentCostAnalyticsDate( $this->created_at ); }
	public function get_edit_order_url(): string { return 'https://example.test/order/' . $this->id; }
	public function created_at_value(): string { return $this->created_at; }
	public function get_meta( string $key, bool $single = true ): mixed {
		unset( $single );
		if ( OrderShipmentRepository::META_KEY === $key ) {
			return $this->shipments;
		}
		if ( OrderShippingMetaPersister::CALCULATION_META_KEY === $key ) {
			$meta = array( 'api' => array( 'api_base_price_kopecks' => $this->base_kopecks ) );
			if ( null !== $this->selected_carrier ) {
				$meta['carrier_key'] = $this->selected_carrier;
				$meta['service_key'] = $this->selected_service;
			}

			return $meta;
		}

		return '';
	}
}

function shipment_cost_analytics_registry(): CarrierRegistry {
	$registry = new CarrierRegistry();
	$registry->register( new ShipmentCostAnalyticsCarrier( 'alpha', 'Alpha Carrier' ) );
	$registry->register( new ShipmentCostAnalyticsCarrier( 'beta', 'Beta Carrier' ) );
	$registry->register( new ShipmentCostAnalyticsCarrier( 'fresh', 'Fresh Dynamic Carrier' ) );

	return $registry;
}

function shipment_cost_analytics_service( int $batch_size = 2 ): ShipmentCostAnalyticsService {
	return new ShipmentCostAnalyticsService(
		new ShipmentCostAnalyticsQuery(),
		new OrderShipmentRepository(),
		new ShipmentBaseApiCostResolver(),
		new OrderAnalyticsShipmentSelector( new OrderSelectedDeliveryIdentityResolver() ),
		shipment_cost_analytics_registry(),
		new ShipmentCostThresholdPolicy(),
		$batch_size
	);
}

/** @param array<int,ShipmentCostAnalyticsOrder> $orders */
function shipment_cost_analytics_set_orders( array $orders ): void {
	$GLOBALS['shipment_cost_analytics_orders'] = $orders;
	$GLOBALS['shipment_cost_analytics_order_map'] = array();
	$GLOBALS['shipment_cost_analytics_wc_get_orders_calls'] = array();
	$GLOBALS['shipment_cost_analytics_wc_get_order_calls'] = array();
	foreach ( $orders as $order ) {
		$GLOBALS['shipment_cost_analytics_order_map'][ $order->get_id() ] = $order;
	}
}

function shipment_cost_analytics_filter( array $request, array $options ): ShipmentCostAnalyticsFilter {
	return ShipmentCostAnalyticsFilter::from_request( $request, $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) );
}

$service = shipment_cost_analytics_service();
$options = $service->carrier_options();
shipment_cost_analytics_assert( isset( $options['fresh'] ) && 'Fresh Dynamic Carrier' === $options['fresh'], 'Dynamic carrier must appear in filter options from registry.' );

shipment_cost_analytics_set_orders(
	array(
		new ShipmentCostAnalyticsOrder(
			1001,
			'WC-1001',
			'2026-07-20 10:00:00',
			10000,
			array(
				'alpha' => array( 'carrier_key' => 'alpha', 'service_key' => 'alpha_service', 'service_title' => 'Alpha Door', 'tracking_number' => 'A-1', 'actual_cost_kopecks' => 12000, 'actual_cost_source' => 'carrier_api', 'actual_cost_source_detail' => 'alpha_create' ),
				'beta' => array( 'carrier_key' => 'beta', 'service_key' => 'beta_service', 'service_title' => 'Beta Pickup', 'barcode' => 'B-1', 'actual_cost_kopecks' => 9000, 'actual_cost_source' => 'manual', 'actual_cost_source_detail' => 'admin_manual' ),
			),
			'alpha',
			'alpha_service'
		),
		new ShipmentCostAnalyticsOrder(
			1002,
			'CUSTOM-2',
			'2026-07-19 12:00:00',
			20000,
			array(
				'alpha' => array( 'carrier_key' => 'alpha', 'service_title' => 'Alpha Pickup', 'tracking_number' => 'A-2' ),
				'failed' => array( 'carrier_key' => 'alpha', 'status' => 'failed', 'error_code' => 'failed' ),
			),
			'alpha'
		),
		new ShipmentCostAnalyticsOrder(
			1003,
			'WC-1003',
			'2026-07-18 12:00:00',
			5000,
			array(
				'fresh' => array( 'carrier_key' => 'fresh', 'service_title' => 'Fresh Service', 'external_id' => 'F-1', 'actual_cost_kopecks' => 5150, 'actual_cost_source' => 'carrier_status' ),
			),
			'fresh'
		),
	)
);

$filter = shipment_cost_analytics_filter( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'per_page' => 25 ), $options );
$result = $service->result( $filter );
shipment_cost_analytics_assert( 3 === $result->total_rows && 3 === count( $result->rows ) && 1 === $result->total_pages, 'Analytics must build at most one row per order selected shipment.' );
shipment_cost_analytics_assert( 3 === $result->summary->shipment_count && 2 === $result->summary->with_actual_count && 1 === $result->summary->without_actual_count, 'Summary must count actual/missing actual rows over selected shipments only.' );
shipment_cost_analytics_assert( 35000 === $result->summary->planned_total_kopecks && 17150 === $result->summary->actual_total_kopecks, 'Summary must aggregate selected shipment plan once per order and actual totals.' );
shipment_cost_analytics_assert( 2150 === $result->summary->difference_total_kopecks, 'Comparable economy/overrun must sum only comparable selected shipments.' );
shipment_cost_analytics_assert( 1 === $result->summary->over_threshold_count && 5000 === $result->summary->over_threshold_share_basis_points(), 'Summary must count and share over-threshold selected shipments.' );
shipment_cost_analytics_assert( 1150 === $result->summary->average_difference_percent_basis_points, 'Average percent must use comparable selected shipments.' );
foreach ( $GLOBALS['shipment_cost_analytics_wc_get_orders_calls'] as $call ) {
	shipment_cost_analytics_assert( -1 !== (int) ( $call['limit'] ?? 0 ) && 'ids' === (string) ( $call['return'] ?? '' ), 'Analytics query must request paged order IDs, not unlimited order objects.' );
}
shipment_cost_analytics_assert( 3 === count( $GLOBALS['shipment_cost_analytics_wc_get_order_calls'] ), 'Orders must be loaded by current batch IDs.' );

$actual_only = $service->result( shipment_cost_analytics_filter( array( 'analytics_period' => 'month' ), $options ) );
shipment_cost_analytics_assert( 2 === $actual_only->total_rows, 'Default filter must show only selected shipments with actual cost.' );

$alpha_filter = $service->result( shipment_cost_analytics_filter( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'carrier' => 'alpha' ), $options ) );
shipment_cost_analytics_assert( 2 === $alpha_filter->total_rows && 'alpha' === $alpha_filter->rows[0]->carrier_key, 'Concrete carrier filter must apply to selected shipment carrier.' );
$beta_filter = $service->result( shipment_cost_analytics_filter( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'carrier' => 'beta' ), $options ) );
shipment_cost_analytics_assert( 0 === $beta_filter->total_rows, 'Other created carrier shipments must be ignored when order selected carrier differs.' );

$search_id = $service->result( shipment_cost_analytics_filter( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'order_search' => '1001' ), $options ) );
shipment_cost_analytics_assert( 1 === $search_id->total_rows && 'alpha' === $search_id->rows[0]->carrier_key, 'Order ID search must return the selected shipment only.' );
$search_number = $service->result( shipment_cost_analytics_filter( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'order_search' => 'CUSTOM-2' ), $options ) );
shipment_cost_analytics_assert( 1 === $search_number->total_rows && null === $search_number->rows[0]->actual_cost_kopecks, 'Order number search must support custom order numbers.' );

$custom_invalid = ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'custom', 'date_from' => '2026-07-22', 'date_to' => '2026-07-01' ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) );
shipment_cost_analytics_assert( 'month' === $custom_invalid->period && array() !== $custom_invalid->notices, 'Invalid custom range must fall back to safe month period with notice.' );
foreach ( array( 'week', 'month', 'quarter', 'year' ) as $period ) {
	$preset = ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => $period ), $options, new DateTimeImmutable( '2026-07-21 00:00:00' ) );
	shipment_cost_analytics_assert( $period === $preset->period && '' !== $preset->date_from && '' !== $preset->date_to, 'Preset period must normalize: ' . $period );
}

$sorted_actual = $service->result( shipment_cost_analytics_filter( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'orderby' => 'actual', 'order' => 'desc', 'per_page' => 100 ), $options ) );
$last = $sorted_actual->rows[ count( $sorted_actual->rows ) - 1 ];
shipment_cost_analytics_assert( null === $last->actual_cost_kopecks, 'Null actual costs must sort last for DESC.' );

foreach ( array( 'order_number', 'date', 'carrier', 'base', 'actual', 'difference', 'difference_percent' ) as $orderby ) {
	$asc = $service->result( shipment_cost_analytics_filter( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'orderby' => $orderby, 'order' => 'asc', 'per_page' => 100 ), $options ) );
	$desc = $service->result( shipment_cost_analytics_filter( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'orderby' => $orderby, 'order' => 'desc', 'per_page' => 100 ), $options ) );
	shipment_cost_analytics_assert( 3 === count( $asc->rows ) && 3 === count( $desc->rows ), 'Sortable column must keep selected row set: ' . $orderby );
}

shipment_cost_analytics_assert( 2000 === $result->rows[0]->difference_kopecks && 2000 === $result->rows[0]->difference_percent_basis_points, 'Difference must be computed in integer kopecks and basis points.' );

shipment_cost_analytics_set_orders(
	array(
		new ShipmentCostAnalyticsOrder(
			1101,
			'MATCH-1',
			'2026-07-20 09:00:00',
			10000,
			array(
				'alpha_pickup' => array( 'carrier_key' => 'alpha', 'service_key' => 'alpha_pickup', 'service_title' => 'Alpha Pickup', 'tracking_number' => 'AP-1', 'actual_cost_kopecks' => 10000 ),
				'alpha_courier' => array( 'carrier_key' => 'alpha', 'service_key' => 'alpha_courier', 'service_title' => 'Alpha Courier', 'tracking_number' => 'AC-1', 'actual_cost_kopecks' => 10100 ),
				'beta' => array( 'carrier_key' => 'beta', 'service_key' => 'beta_courier', 'tracking_number' => 'B-2', 'actual_cost_kopecks' => 9000 ),
			),
			'alpha',
			'alpha_courier'
		),
	)
);
$exact = $service->result( shipment_cost_analytics_filter( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all' ), $options ) );
shipment_cost_analytics_assert( 1 === $exact->total_rows && 'alpha_courier' === $exact->rows[0]->service_key, 'Exact carrier and service match must select the matching shipment.' );

shipment_cost_analytics_set_orders(
	array(
		new ShipmentCostAnalyticsOrder( 1201, 'FALLBACK-1', '2026-07-20 09:00:00', 10000, array( 'alpha' => array( 'carrier_key' => 'alpha', 'tracking_number' => 'A-FB', 'actual_cost_kopecks' => 10000 ) ), 'alpha' ),
		new ShipmentCostAnalyticsOrder( 1202, 'NO-IDENTITY', '2026-07-20 08:00:00', 10000, array( 'alpha' => array( 'carrier_key' => 'alpha', 'tracking_number' => 'A-NO', 'actual_cost_kopecks' => 10000 ) ), null ),
		new ShipmentCostAnalyticsOrder( 1203, 'NO-MATCH', '2026-07-20 07:00:00', 10000, array( 'beta' => array( 'carrier_key' => 'beta', 'tracking_number' => 'B-NO', 'actual_cost_kopecks' => 10000 ) ), 'alpha' ),
		new ShipmentCostAnalyticsOrder(
			1204,
			'AMBIGUOUS',
			'2026-07-20 06:00:00',
			10000,
			array(
				'alpha_a' => array( 'carrier_key' => 'alpha', 'service_key' => 'alpha_a', 'tracking_number' => 'A-A', 'actual_cost_kopecks' => 10000 ),
				'alpha_b' => array( 'carrier_key' => 'alpha', 'service_key' => 'alpha_b', 'tracking_number' => 'A-B', 'actual_cost_kopecks' => 10000 ),
			),
			'alpha'
		),
	)
);
$selection = $service->result( shipment_cost_analytics_filter( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all' ), $options ) );
shipment_cost_analytics_assert( 1 === $selection->total_rows && 'FALLBACK-1' === $selection->rows[0]->order_number, 'Carrier fallback must select a single matching created shipment only.' );
shipment_cost_analytics_assert( 1 === $selection->summary->skipped_without_selected_carrier && 1 === $selection->summary->skipped_without_matching_shipment && 1 === $selection->summary->skipped_ambiguous, 'Selector diagnostics must count skipped identity, missing shipment and ambiguous matches.' );

shipment_cost_analytics_set_orders(
	array(
		new ShipmentCostAnalyticsOrder(
			1301,
			'PLAN-ONCE',
			'2026-07-20 09:00:00',
			10000,
			array(
				'alpha' => array( 'carrier_key' => 'alpha', 'tracking_number' => 'A-ONCE', 'actual_cost_kopecks' => 10000 ),
				'beta' => array( 'carrier_key' => 'beta', 'tracking_number' => 'B-ONCE', 'actual_cost_kopecks' => 10000 ),
			),
			'alpha'
		),
	)
);
$plan_once = $service->result( shipment_cost_analytics_filter( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all' ), $options ) );
shipment_cost_analytics_assert( 1 === $plan_once->summary->shipment_count && 10000 === $plan_once->summary->planned_total_kopecks, 'Plan price must be counted once for one order even when other carrier shipment records exist.' );

$paged_orders = array();
for ( $i = 1; $i <= 30; ++$i ) {
	$paged_orders[] = new ShipmentCostAnalyticsOrder(
		2000 + $i,
		'PG-' . $i,
		'2026-07-10 10:00:' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ),
		10000,
		array(
			'alpha' => array( 'carrier_key' => 'alpha', 'tracking_number' => 'PG-' . $i, 'actual_cost_kopecks' => 10000, 'actual_cost_source' => 'carrier_api' ),
		),
		'alpha'
	);
}
shipment_cost_analytics_set_orders( $paged_orders );
$paged = $service->result( shipment_cost_analytics_filter( array( 'analytics_period' => 'month', 'per_page' => 25, 'paged' => 999 ), $options ) );
shipment_cost_analytics_assert( 30 === $paged->total_rows && 5 === count( $paged->rows ) && 2 === $paged->total_pages && 2 === $paged->current_page && 30 === $paged->summary->shipment_count, 'Pagination must clamp current_page and summary must cover full filtered dataset.' );

$_GET = array( 'page' => 'wdc-platform', 'analytics_period' => 'month', 'paged' => '999', 'per_page' => '25' );
ob_start();
( new ShipmentCostAnalyticsAdminSection( $service, new ShipmentCostThresholdPolicy() ) )->render();
$html = (string) ob_get_clean();
shipment_cost_analytics_assert( str_contains( $html, '<strong>2</strong>' ), 'Renderer must mark normalized current_page as active.' );

shipment_cost_analytics_set_orders(
	array(
		new ShipmentCostAnalyticsOrder(
			3001,
			'EMPTY-ACTUAL',
			'2026-07-20 09:00:00',
			10000,
			array( 'alpha' => array( 'carrier_key' => 'alpha', 'tracking_number' => 'A-MISS' ) ),
			'alpha'
		),
	)
);
$_GET = array( 'page' => 'wdc-platform', 'analytics_period' => 'month' );
ob_start();
( new ShipmentCostAnalyticsAdminSection( $service, new ShipmentCostThresholdPolicy() ) )->render();
$html = (string) ob_get_clean();
shipment_cost_analytics_assert( str_contains( $html, 'Включите «Показать без фактической стоимости»' ), 'Empty state must point to the visible checkbox label.' );
$old_empty_state_phrase = 'Отключите фильтр «' . 'Только с фактической стоимостью' . '»';
shipment_cost_analytics_assert( ! str_contains( $html, $old_empty_state_phrase ), 'Old empty state wording must not remain.' );

echo "Shipment cost analytics smoke passed.\n";
