<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryCheckoutPickupPointFormatter;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Checkout\Validation\CheckoutAddressValidation;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Pickup\Rest\CheckoutPickupPointRestController;
use WallsShop\WDC\Pickup\Rest\PickupPointsRestController;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function yandex_pickup_selection_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function __( string $text, string $domain = '' ): string { return $text; }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function rest_ensure_response( mixed $data ): mixed { return $data; }
function wp_verify_nonce( string $nonce, string $action ): bool { return true; }
function current_time( string $type ): string { return '2026-06-30 12:00:00'; }

function WC(): object {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new class {
			public object $session;
			public function __construct() {
				$this->session = new class {
					public array $data = array();
					public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
					public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
					public function set_customer_session_cookie( bool $set ): void {}
					public function save_data(): void {}
				};
			}
		};
	}

	return $wc;
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $yandex_delivery_pickup_points_v2 = array();
		public array $yandex_location_mapping_v2 = array();
		public function prepare( string $query, mixed ...$args ): string { return $query; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

final class YandexPickupSelectionRequest {
	public function __construct( private array $params ) {}
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? ''; }
	public function get_header( string $key ): string { return ''; }
}
final class YandexPickupSelectionErrors {
	public array $errors = array();
	public function add( string $code, string $message ): void { $this->errors[ $code ] = $message; }
	public function has( string $code ): bool { return isset( $this->errors[ $code ] ); }
}
final class YandexPickupSelectionOrder {
	public array $meta = array();
	public string $shipping_address_1 = '';
	public string $shipping_city = '';
	public string $shipping_state = '';
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function set_shipping_address_1( string $value ): void { $this->shipping_address_1 = $value; }
	public function set_shipping_address_2( string $value ): void {}
	public function set_shipping_city( string $value ): void { $this->shipping_city = $value; }
	public function set_shipping_state( string $value ): void { $this->shipping_state = $value; }
	public function set_shipping_postcode( string $value ): void {}
	public function set_shipping_country( string $value ): void {}
}

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->yandex_location_mapping_v2 = array(
	array( 'location_id' => 10, 'yandex_geo_id' => 65, 'status' => 'mapped', 'is_primary' => 1 ),
	array( 'location_id' => 10, 'yandex_geo_id' => 66, 'status' => 'manual', 'is_primary' => 0 ),
	array( 'location_id' => 10, 'yandex_geo_id' => 67, 'status' => 'needs_review', 'is_primary' => 0 ),
);
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'platform_station_id' => 'DST-A', 'operator_station_id' => 'OA', 'operator_id' => 'market_l4g', 'type' => 'pickup_point', 'name' => 'Яндекс Ленина', 'yandex_geo_id' => 65, 'region' => 'Новосибирская обл.', 'locality' => 'Новосибирск', 'postal_code' => '630001', 'full_address' => 'Новосибирск, ул Ленина, 1', 'latitude' => 55.030199, 'longitude' => 82.92043, 'instruction' => 'Вход с улицы', 'schedule_text' => 'Пн-Вс 10-22', 'available_for_dropoff' => 0, 'active' => 1 ),
	array( 'platform_station_id' => 'DST-B', 'operator_station_id' => 'OB', 'operator_id' => '5post', 'type' => 'terminal', 'name' => '5Post Красный', 'yandex_geo_id' => 66, 'region' => 'Новосибирская обл.', 'locality' => 'Новосибирск', 'full_address' => 'Красный проспект, 2', 'latitude' => null, 'longitude' => null, 'available_for_dropoff' => 0, 'active' => 1 ),
	array( 'platform_station_id' => '', 'operator_id' => 'market_l4g', 'type' => 'pickup_point', 'name' => 'Без station', 'yandex_geo_id' => 65, 'active' => 1 ),
	array( 'platform_station_id' => 'DST-INACTIVE', 'operator_id' => 'market_l4g', 'type' => 'pickup_point', 'name' => 'Закрытый', 'yandex_geo_id' => 65, 'active' => 0 ),
	array( 'platform_station_id' => 'DST-DROPOFF', 'operator_id' => 'market_l4g', 'type' => 'pickup_point', 'name' => 'Без dropoff', 'yandex_geo_id' => 66, 'active' => 1, 'available_for_dropoff' => 0 ),
);
for ( $i = 1; $i <= 1005; ++$i ) {
	$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2[] = array(
		'platform_station_id' => 'DST-BULK-' . str_pad( (string) $i, 4, '0', STR_PAD_LEFT ),
		'operator_id' => 0 === $i % 2 ? 'market_l4g' : '5post',
		'type' => 0 === $i % 3 ? 'terminal' : 'pickup_point',
		'name' => 'Массовый ПВЗ ' . $i,
		'yandex_geo_id' => 0 === $i % 2 ? 65 : 66,
		'locality' => 'Новосибирск',
		'full_address' => 'Новосибирск, тестовая ' . $i,
		'available_for_dropoff' => 0,
		'active' => 1,
	);
}

$repository = new YandexDeliveryPickupPointV2Repository( $GLOBALS['wpdb'] );
$mapping = new YandexLocationMappingV2Repository( $GLOBALS['wpdb'] );
$formatter = new YandexDeliveryCheckoutPickupPointFormatter();
$geo_ids = $mapping->geo_ids_for_location( 10 );
$rows = $repository->destination_pickup_points_by_geo_ids( $geo_ids, 10 );
yandex_pickup_selection_assert( array( 65, 66 ) === $geo_ids, 'Yandex mapping must return all mapped/manual geo ids for location_id.' );
yandex_pickup_selection_assert( 1008 === count( $rows ), 'Yandex destination repository must return all active pickup/terminal rows from all geo ids without applying the legacy limit.' );
yandex_pickup_selection_assert( in_array( (string) ( $rows[0]['platform_station_id'] ?? '' ), array( 'DST-A', 'DST-DROPOFF' ), true ) && 'pickup_point' === (string) ( $rows[0]['type'] ?? '' ), 'Yandex destination repository must sort pickup_point before terminal and keep market_l4g priority.' );
yandex_pickup_selection_assert( null !== $repository->destination_pickup_point_by_platform_station_id( 'DST-DROPOFF' ), 'Yandex destination lookup must not require available_for_dropoff.' );
yandex_pickup_selection_assert( null === $repository->destination_pickup_point_by_platform_station_id( 'DST-INACTIVE' ), 'Yandex destination lookup must reject inactive rows.' );
$formatted = $formatter->format( $rows[0] );
yandex_pickup_selection_assert( YandexDeliverySettings::CARRIER_KEY === (string) $formatted['carrier_key'] && (string) ( $rows[0]['platform_station_id'] ?? '' ) === (string) $formatted['platform_station_id'] && (string) $formatted['platform_station_id'] === (string) $formatted['point_code'], 'Yandex formatter must emit common checkout pickup point identity.' );
yandex_pickup_selection_assert( ! array_key_exists( 'raw_json', $formatted ) && ! array_key_exists( 'raw_json', $formatted['snapshot'] ?? array() ), 'Yandex formatter must not expose raw_json.' );

$points_controller = new PickupPointsRestController( new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ), null, null, null, null, $repository, $mapping, $formatter );
$points = $points_controller->points( array( 'carrier' => YandexDeliverySettings::CARRIER_KEY, 'location_id' => '10', 'limit' => '10' ) );
yandex_pickup_selection_assert( 1008 === count( $points ) && 'yandex_delivery:pickup' === (string) ( $points[0]['pickup_family'] ?? '' ), 'Pickup REST endpoint must ignore REST limit and return all Yandex points in common map/list shape.' );
$searched = $points_controller->search( array( 'carrier' => YandexDeliverySettings::CARRIER_KEY, 'location_id' => '10', 'q' => 'Красный' ) );
yandex_pickup_selection_assert( 1 === count( $searched ) && 'DST-B' === (string) ( $searched[0]['platform_station_id'] ?? '' ), 'Pickup REST endpoint must search Yandex points by address/name.' );

$session = new CheckoutSessionManager();
$pickup_rate_id = 'yandex_pickup';
$courier_rate_id = 'yandex_courier';
$session->save_rates( array(
	$pickup_rate_id => array( 'carrier_key' => YandexDeliverySettings::CARRIER_KEY, 'service_key' => YandexDeliverySettings::SERVICE_KEY, 'rate_id' => $pickup_rate_id, 'delivery_type' => DeliveryType::PICKUP, 'requires_pickup_point' => true, 'rate_meta' => array( 'pickup_source' => 'representative' ) ),
	$courier_rate_id => array( 'carrier_key' => YandexDeliverySettings::CARRIER_KEY, 'service_key' => YandexDeliverySettings::SERVICE_KEY, 'rate_id' => $courier_rate_id, 'delivery_type' => DeliveryType::COURIER, 'requires_pickup_point' => false, 'requires_courier_address' => true ),
	'dpd:pickup:max' => array( 'carrier_key' => 'dpd', 'service_key' => 'dpd', 'rate_id' => 'dpd:pickup:max', 'delivery_type' => DeliveryType::PICKUP, 'requires_pickup_point' => true ),
) );
$session->save_city_context( array( 'location_id' => 10, 'city_name' => 'Новосибирск', 'region_name' => 'Новосибирская обл.', 'country_code' => 'RU' ) );
yandex_pickup_selection_assert( 'yandex_delivery:pickup' === $session->shipping_method_family( 'yandex_pickup' ), 'CheckoutSessionManager must map yandex_pickup to yandex_delivery:pickup.' );

$checkout_rest = new CheckoutPickupPointRestController( new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ), $session, null, null, null, $repository, $formatter );
$save_response = $checkout_rest->save( new YandexPickupSelectionRequest( array( 'carrier' => YandexDeliverySettings::CARRIER_KEY, 'shipping_method_id' => $pickup_rate_id, 'point_code' => 'DST-A' ) ) );
yandex_pickup_selection_assert( 'DST-A' === (string) ( $save_response['pickup_point']['platform_station_id'] ?? '' ), 'Checkout save endpoint must persist selected Yandex platform_station_id.' );
yandex_pickup_selection_assert( 'yandex_delivery:pickup' === (string) ( $save_response['active_pickup_family'] ?? '' ), 'Checkout save endpoint must store Yandex selection in yandex_delivery:pickup bucket.' );
$resolve = $checkout_rest->resolve_location( new YandexPickupSelectionRequest( array( 'point' => array( 'carrier_key' => YandexDeliverySettings::CARRIER_KEY, 'point_code' => 'DST-A' ) ) ) );
yandex_pickup_selection_assert( false === (bool) ( $resolve['requires_location_change'] ?? true ) && null === ( $resolve['location'] ?? null ), 'Yandex resolve_location must skip Russian Post location resolver path.' );
$shipping_method_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/NewShippingMethod.php' );
yandex_pickup_selection_assert( str_contains( $shipping_method_source, "'pickup_selections' => \$this->session_manager->pickup_selections()" ), 'NewShippingMethod must pass family-specific pickup_selections into QuoteRequest customer context.' );

$validation = new CheckoutValidation( $session, new CheckoutAddressValidation( $session ), null, null, $repository, $formatter );
$errors = new YandexPickupSelectionErrors();
$validation->validate( array( 'shipping_method' => array( $pickup_rate_id ), 'shipping_city' => 'Новосибирск' ), $errors );
yandex_pickup_selection_assert( false === $errors->has( 'wdc_pickup_required' ), 'Yandex pickup validation must pass with selected Yandex PVZ.' );
$session->clear_pickup_selection( 'test' );
$missing_errors = new YandexPickupSelectionErrors();
$validation->validate( array( 'shipping_method' => array( $pickup_rate_id ), 'shipping_city' => 'Новосибирск' ), $missing_errors );
yandex_pickup_selection_assert( 'Выберите пункт выдачи Яндекс.Доставки.' === (string) ( $missing_errors->errors['wdc_pickup_required'] ?? '' ), 'Yandex pickup validation must fail without selected Yandex PVZ.' );
$wrong_errors = new YandexPickupSelectionErrors();
$validation->validate( array( 'shipping_method' => array( $pickup_rate_id ), 'shipping_city' => 'Новосибирск', 'wdc_pickup_point_code' => 'NSK-PS-1', 'wdc_pickup_carrier_key' => 'dpd', 'wdc_pickup_family' => 'dpd:pickup' ), $wrong_errors );
yandex_pickup_selection_assert( 'Выберите пункт выдачи Яндекс.Доставки.' === (string) ( $wrong_errors->errors['wdc_pickup_required'] ?? '' ), 'Yandex pickup validation must reject pickup selected for another carrier.' );
$courier_errors = new YandexPickupSelectionErrors();
$validation->validate( array( 'shipping_method' => array( $courier_rate_id ), 'shipping_city' => 'Новосибирск', 'shipping_address_1' => 'ул Ленина, 1' ), $courier_errors );
yandex_pickup_selection_assert( false === $courier_errors->has( 'wdc_pickup_required' ), 'Yandex courier must not require pickup point.' );

$checkout_rest->save( new YandexPickupSelectionRequest( array( 'carrier' => YandexDeliverySettings::CARRIER_KEY, 'shipping_method_id' => $pickup_rate_id, 'point_code' => 'DST-A' ) ) );
WC()->session->set( 'chosen_shipping_methods', array( $pickup_rate_id ) );
$order = new YandexPickupSelectionOrder();
( new OrderShippingMetaPersister( $session ) )->persist( $order, array() );
yandex_pickup_selection_assert( 'DST-A' === (string) ( $order->meta['_wdc_pickup_point_code'] ?? '' ) && 'DST-A' === (string) ( $order->meta['_wdc_pickup_platform_station_id'] ?? '' ), 'Order meta must save canonical selected Yandex point code/platform_station_id.' );
yandex_pickup_selection_assert( 'DST-A' === (string) ( $order->meta['_wdc_yandex_delivery_pickup_platform_station_id'] ?? '' ), 'Order meta must save Yandex platform_station_id alias.' );
yandex_pickup_selection_assert( 'Яндекс Ленина' === (string) ( $order->meta['_wdc_yandex_delivery_pickup_name'] ?? '' ) && 'Новосибирск, ул Ленина, 1' === (string) ( $order->meta['_wdc_yandex_delivery_pickup_address'] ?? '' ), 'Order meta must save selected Yandex name/address.' );
yandex_pickup_selection_assert( ! str_contains( (string) ( $order->meta['_wdc_pickup_point_snapshot'] ?? '' ), 'raw_json' ), 'Order meta must not save raw Yandex JSON.' );
$calculation = is_array( $order->meta[OrderShippingMetaPersister::CALCULATION_META_KEY] ?? null ) ? $order->meta[OrderShippingMetaPersister::CALCULATION_META_KEY] : array();
yandex_pickup_selection_assert( 'DST-A' === (string) ( $calculation['pickup']['platform_station_id'] ?? '' ), 'Calculation meta pickup block must include selected Yandex platform_station_id.' );

echo "Yandex Delivery checkout pickup selection smoke test passed.\n";
