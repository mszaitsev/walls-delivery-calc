<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Cdek\CdekOrderStatusService;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentAdapter;
use WallsShop\WDC\Shipments\Presentation\ShipmentActualCostComparisonService;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentAdapter;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentButtonPolicy;

function shipment_actual_cost_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\\-]/', '', $key ) ?? '' );
	}
}

final class ShipmentActualCostOrder {
	public function __construct( private mixed $meta ) {}
	public function get_meta( string $key, bool $single = true ): mixed {
		unset( $single );
		return OrderShippingMetaPersister::CALCULATION_META_KEY === $key ? $this->meta : '';
	}
}
function shipment_actual_cost_reflection_instance( string $class ): object {
	return ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
}
function shipment_actual_cost_set_property( object $object, string $property, mixed $value ): void {
	$reflection = new ReflectionProperty( $object, $property );
	$reflection->setAccessible( true );
	$reflection->setValue( $object, $value );
}

$service = new ShipmentActualCostComparisonService();

$hidden = $service->compare( null, null )->to_array();
shipment_actual_cost_assert( null === $hidden['actual_cost_kopecks'] && '' === $hidden['actual_cost_label'] && '' === $hidden['actual_cost_compare_status'] && '' === $hidden['actual_cost_compare_message'], 'Missing actual cost must hide the price presentation.' );

$neutral = $service->compare( 99500, null )->to_array();
shipment_actual_cost_assert( '995.00 руб.' === $neutral['actual_cost_label'] && 'neutral' === $neutral['actual_cost_compare_status'] && 'нет базовой стоимости для сравнения' === $neutral['actual_cost_compare_message'], 'Actual cost without base must render neutral presentation.' );

$zero_base = $service->compare( 99500, 0 )->to_array();
shipment_actual_cost_assert( '995.00 руб.' === $zero_base['actual_cost_label'] && 'neutral' === $zero_base['actual_cost_compare_status'], 'Base cost zero must be treated as neutral comparison context.' );
$zero_actual = $service->compare( 0, null )->to_array();
shipment_actual_cost_assert( 0 === $zero_actual['actual_cost_kopecks'] && '0.00 руб.' === $zero_actual['actual_cost_label'] && 'neutral' === $zero_actual['actual_cost_compare_status'], 'Low-level comparator must still support explicit zero actual cost.' );

shipment_actual_cost_assert( 'ok' === $service->compare( 102999, 100000 )->actual_cost_compare_status, 'Actual cost below +3% must be ok.' );
shipment_actual_cost_assert( 'ok' === $service->compare( 103000, 100000 )->actual_cost_compare_status, 'Actual cost exactly at +3% must be ok.' );
shipment_actual_cost_assert( 'warning' === $service->compare( 103001, 100000 )->actual_cost_compare_status, 'Actual cost one kopeck above +3% must be warning.' );
shipment_actual_cost_assert( 'ok' === $service->compare( 102998, 99999 )->actual_cost_compare_status && 'warning' === $service->compare( 102999, 99999 )->actual_cost_compare_status, 'Non-round base must use exact integer cross multiplication.' );

$labels = array(
	0 => '0.00 руб.',
	1 => '0.01 руб.',
	100 => '1.00 руб.',
	99500 => '995.00 руб.',
	123456 => '1234.56 руб.',
);
foreach ( $labels as $kopecks => $label ) {
	shipment_actual_cost_assert( $label === $service->compare( $kopecks, null )->actual_cost_label, 'Actual cost label mismatch for ' . $kopecks . ' kopecks.' );
}

foreach ( array( -1, -100 ) as $invalid ) {
	try {
		$service->compare( $invalid, null );
		shipment_actual_cost_assert( false, 'Negative actual cost must be rejected.' );
	} catch ( InvalidArgumentException ) {
	}
}
try {
	$service->compare( 100, -1 );
	shipment_actual_cost_assert( false, 'Negative base cost must be rejected.' );
} catch ( InvalidArgumentException ) {
}

foreach ( array( '100', 100.5, true ) as $invalid ) {
	try {
		$service->compare( $invalid, null );
		shipment_actual_cost_assert( false, 'Strict typed service must reject non-integer actual cost.' );
	} catch ( TypeError ) {
	}
}

$resolver = new ShipmentBaseApiCostResolver();
shipment_actual_cost_assert( 39998 === $resolver->resolve_from_order( new ShipmentActualCostOrder( array( 'api' => array( 'api_base_price_rub' => 399.98 ) ) ) ), 'Resolver must keep legacy ruble-to-kopecks conversion.' );
shipment_actual_cost_assert( 12345 === $resolver->resolve_from_order( new ShipmentActualCostOrder( array( 'api' => array( 'api_base_price_kopecks' => 12345 ) ) ) ), 'Resolver must prefer API kopecks from nested api context.' );
shipment_actual_cost_assert( null === $resolver->resolve_from_order( new ShipmentActualCostOrder( array( 'api' => array( 'api_base_price_kopecks' => 0 ) ) ) ), 'Resolver must treat integer zero base kopecks as missing.' );
shipment_actual_cost_assert( null === $resolver->resolve_from_order( new ShipmentActualCostOrder( array( 'api' => array( 'api_base_price_kopecks' => '0' ) ) ) ), 'Resolver must treat string zero base kopecks as missing.' );
shipment_actual_cost_assert( null === $resolver->resolve_from_order( new ShipmentActualCostOrder( array( 'api' => array( 'api_base_price_rub' => 0 ) ) ) ), 'Resolver must treat integer zero base rubles as missing.' );
shipment_actual_cost_assert( null === $resolver->resolve_from_order( new ShipmentActualCostOrder( array( 'api' => array( 'api_base_price_rub' => '0.00' ) ) ) ), 'Resolver must treat string zero base rubles as missing.' );
shipment_actual_cost_assert( 777 === $resolver->resolve_from_order( new ShipmentActualCostOrder( json_encode( array( 'api' => array( 'base_api_cost_kopecks' => 777 ) ), JSON_THROW_ON_ERROR ) ) ), 'Resolver must keep JSON calculation meta support.' );

$yandex = shipment_actual_cost_reflection_instance( YandexShipmentAdapter::class );
shipment_actual_cost_set_property( $yandex, 'buttons', new YandexShipmentButtonPolicy() );
shipment_actual_cost_set_property( $yandex, 'status_mapping', null );
shipment_actual_cost_set_property( $yandex, 'label_policy', null );
$yandex_zero = $yandex->status_payload( new ShipmentActualCostOrder( array( 'api' => array( 'api_base_price_kopecks' => 10000 ) ) ), array( 'actual_cost_kopecks' => 0 ) );
shipment_actual_cost_assert( 0 === $yandex_zero['actual_cost_kopecks'] && '0.00 руб.' === $yandex_zero['actual_cost_label'] && 'ok' === $yandex_zero['actual_cost_compare_status'] && 10000 === $yandex_zero['base_api_cost_kopecks'], 'Yandex actual_cost_kopecks=0 must render as known zero actual cost.' );
$yandex_string_zero = $yandex->status_payload( new ShipmentActualCostOrder( array( 'api' => array( 'api_base_price_kopecks' => 10000 ) ) ), array( 'yandex_offer_pricing_total_kopecks' => '0' ) );
shipment_actual_cost_assert( null === $yandex_string_zero['actual_cost_kopecks'] && '' === $yandex_string_zero['actual_cost_label'], 'Yandex yandex_offer_pricing_total_kopecks=\"0\" must hide actual-cost presentation.' );
$yandex_base_zero = $yandex->status_payload( new ShipmentActualCostOrder( array( 'api' => array( 'api_base_price_kopecks' => 0 ) ) ), array( 'actual_cost_kopecks' => 99500 ) );
shipment_actual_cost_assert( 99500 === $yandex_base_zero['actual_cost_kopecks'] && '995.00 руб.' === $yandex_base_zero['actual_cost_label'] && 'neutral' === $yandex_base_zero['actual_cost_compare_status'] && null === $yandex_base_zero['base_api_cost_kopecks'], 'Yandex positive actual with zero base must render neutral with null base.' );

$dpd = shipment_actual_cost_reflection_instance( DpdShipmentAdapter::class );
shipment_actual_cost_set_property( $dpd, 'buttons', null );
$dpd_zero = $dpd->status_payload( new ShipmentActualCostOrder( array( 'api' => array( 'api_base_price_kopecks' => 10000 ) ) ), array( 'actual_cost_kopecks' => 0 ) );
shipment_actual_cost_assert( 0 === $dpd_zero['actual_cost_kopecks'] && '0.00 руб.' === $dpd_zero['actual_cost_label'] && 'ok' === $dpd_zero['actual_cost_compare_status'], 'DPD canonical actual_cost_kopecks=0 must render as known zero actual cost.' );

$russian_post = shipment_actual_cost_reflection_instance( ShipmentStatusUpdateService::class );
$rp_zero = $russian_post->status_payload( array( 'russian_post_actual_cost_kopecks' => 0 ), new ShipmentActualCostOrder( array( 'api' => array( 'api_base_price_kopecks' => 10000 ) ) ) );
shipment_actual_cost_assert( 0 === $rp_zero['actual_cost_kopecks'] && '0.00 руб.' === $rp_zero['actual_cost_label'] && 'ok' === $rp_zero['actual_cost_compare_status'], 'Russian Post legacy russian_post_actual_cost_kopecks=0 must render as known zero actual cost.' );

$cdek = shipment_actual_cost_reflection_instance( CdekOrderStatusService::class );
$cdek_zero = $cdek->status_payload( array( 'actual_cost_kopecks' => 0 ), new ShipmentActualCostOrder( array( 'api' => array( 'api_base_price_kopecks' => 10000 ) ) ) );
shipment_actual_cost_assert( 0 === $cdek_zero['actual_cost_kopecks'] && '0.00 руб.' === $cdek_zero['actual_cost_label'] && 'ok' === $cdek_zero['actual_cost_compare_status'], 'CDEK canonical actual_cost_kopecks=0 must render as known zero actual cost.' );

$service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Presentation/ShipmentActualCostComparisonService.php' );
shipment_actual_cost_assert( ! str_contains( $service_source, '1.03' ) && ! str_contains( $service_source, 'floor(' ) && ! str_contains( $service_source, 'round(' ) && ! str_contains( $service_source, '(int)' ), 'Comparison service must not use float threshold arithmetic or silent integer casts.' );
shipment_actual_cost_assert( str_contains( $service_source, '* 100 <= $base_cost_kopecks * 103' ), 'Comparison service must use integer cross multiplication for the +3% threshold.' );

foreach ( array(
	'src/Shipments/YandexDelivery/YandexShipmentAdapter.php',
	'src/Shipments/Dpd/DpdShipmentAdapter.php',
	'src/Shipments/Application/ShipmentStatusUpdateService.php',
	'src/Shipments/Cdek/CdekOrderStatusService.php',
) as $carrier_file ) {
	$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $carrier_file );
	shipment_actual_cost_assert( ! str_contains( $source, '1.03' ) && ! str_contains( $source, 'floor(' ) && ! str_contains( $source, '* 103' ), $carrier_file . ' must not keep local actual-cost threshold formula.' );
	shipment_actual_cost_assert( str_contains( $source, 'ShipmentActualCostResolver' ), $carrier_file . ' actual-cost presentation must use the shared resolver.' );
}

echo "Shipment actual cost presentation smoke passed.\n";
