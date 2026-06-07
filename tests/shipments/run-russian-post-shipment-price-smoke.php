<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Shipments\Admin\OrderShipmentsMetabox;
use WallsShop\WDC\Shipments\Application\ShipmentBacklogService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function rp_shipment_price_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class RussianPostShipmentPriceOrder {
	public function __construct( private array|string $calculation = array() ) {
	}

	public function get_meta( string $key, bool $single = true ): mixed {
		if ( OrderShippingMetaPersister::CALCULATION_META_KEY === $key ) {
			return $this->calculation;
		}

		return '';
	}
}

$backlog_reflection = new ReflectionClass( ShipmentBacklogService::class );
$backlog = $backlog_reflection->newInstanceWithoutConstructor();
$actual_cost_fields = $backlog_reflection->getMethod( 'actual_cost_fields' );
$actual_cost_fields->setAccessible( true );

$fields = $actual_cost_fields->invoke(
	$backlog,
	array(
		'barcode' => '80080822636157',
		'total-rate-wo-vat' => 32785,
		'total-vat' => 7213,
	),
	'backlog_search'
);
rp_shipment_price_assert( 39998 === (int) ( $fields['russian_post_actual_cost_kopecks'] ?? 0 ), 'backlog/search total-rate-wo-vat + total-vat must be saved as kopecks.' );
rp_shipment_price_assert( 399.98 === (float) ( $fields['russian_post_actual_cost_rub'] ?? 0.0 ), 'Actual Russian Post cost must also be stored in rubles.' );
rp_shipment_price_assert( 'backlog_search' === (string) ( $fields['russian_post_actual_cost_source'] ?? '' ), 'Actual cost source must be backlog_search.' );

$missing = $actual_cost_fields->invoke( $backlog, array( 'barcode' => '80080822636157' ), 'backlog_search' );
rp_shipment_price_assert( array() === $missing, 'Missing total-rate-wo-vat / total-vat must not create price fields.' );

$fallback = $actual_cost_fields->invoke(
	$backlog,
	array(
		'barcode' => '80080822636157',
		'total-rate-wo-vat' => 32785,
		'total-vat' => 7213,
	),
	'shipment_search'
);
rp_shipment_price_assert( array() === $fallback, 'shipment/search fallback must not invent an actual cost.' );

$status_reflection = new ReflectionClass( ShipmentStatusUpdateService::class );
$status_service = $status_reflection->newInstanceWithoutConstructor();
$shipment = array( 'russian_post_actual_cost_kopecks' => 39998 );

$payload_equal = $status_service->status_payload(
	$shipment,
	new RussianPostShipmentPriceOrder( array( 'api' => array( 'api_base_price_rub' => 399.98 ) ) )
);
rp_shipment_price_assert( '399.98 руб.' === (string) ( $payload_equal['actual_cost_label'] ?? '' ), 'Status payload must format actual cost as rubles.' );
rp_shipment_price_assert( 39998 === (int) ( $payload_equal['base_api_cost_kopecks'] ?? 0 ), 'Base API rubles must be converted to kopecks.' );
rp_shipment_price_assert( 'ok' === (string) ( $payload_equal['actual_cost_compare_status'] ?? '' ), 'Equal actual and base API costs must be ok.' );

$payload_within_three_percent = $status_service->status_payload(
	$shipment,
	new RussianPostShipmentPriceOrder( array( 'api' => array( 'api_base_price_rub' => 390.00 ) ) )
);
rp_shipment_price_assert( 'ok' === (string) ( $payload_within_three_percent['actual_cost_compare_status'] ?? '' ), 'Actual cost within 3% over base API cost must be ok.' );

$payload_warning = $status_service->status_payload(
	$shipment,
	new RussianPostShipmentPriceOrder( array( 'api' => array( 'api_base_price_rub' => 380.00 ) ) )
);
rp_shipment_price_assert( 'warning' === (string) ( $payload_warning['actual_cost_compare_status'] ?? '' ), 'Actual cost more than 3% over base API cost must be warning.' );

$payload_neutral = $status_service->status_payload( $shipment, new RussianPostShipmentPriceOrder() );
rp_shipment_price_assert( 'neutral' === (string) ( $payload_neutral['actual_cost_compare_status'] ?? '' ), 'Missing base API cost must produce neutral compare status.' );

$payload_no_price = $status_service->status_payload( array(), new RussianPostShipmentPriceOrder( array( 'api' => array( 'api_base_price_rub' => 399.98 ) ) ) );
rp_shipment_price_assert( '' === (string) ( $payload_no_price['actual_cost_label'] ?? '' ), 'Missing actual cost must not show a price label.' );
rp_shipment_price_assert( '' === (string) ( $payload_no_price['actual_cost_compare_status'] ?? '' ), 'Missing actual cost must not set a compare status.' );

$payload_json_meta = $status_service->status_payload(
	$shipment,
	new RussianPostShipmentPriceOrder( '{"api":{"api_base_price_kopecks":39000}}' )
);
rp_shipment_price_assert( 'ok' === (string) ( $payload_json_meta['actual_cost_compare_status'] ?? '' ), 'JSON calculation meta with kopecks must be supported.' );

$metabox_reflection = new ReflectionClass( OrderShipmentsMetabox::class );
$metabox = $metabox_reflection->newInstanceWithoutConstructor();
$price_class = $metabox_reflection->getMethod( 'shipment_price_class' );
$price_class->setAccessible( true );
rp_shipment_price_assert( 'wdc-shipment-price-ok' === $price_class->invoke( $metabox, 'ok' ), 'Metabox must expose ok price class.' );
rp_shipment_price_assert( 'wdc-shipment-price-warning' === $price_class->invoke( $metabox, 'warning' ), 'Metabox must expose warning price class.' );
rp_shipment_price_assert( 'wdc-shipment-price-neutral' === $price_class->invoke( $metabox, 'neutral' ), 'Metabox must expose neutral price class.' );

$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.js' );
rp_shipment_price_assert( is_string( $js ) && str_contains( $js, 'actual_cost_label' ), 'Shipments admin JS must render actual cost from status payload.' );
$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.css' );
rp_shipment_price_assert( is_string( $css ) && str_contains( $css, 'wdc-shipment-price-warning' ), 'Shipments admin CSS must include price warning class.' );

echo "Russian Post shipment price smoke OK\n";
