<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointRepository;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Checkout\Validation\CheckoutAddressValidation;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Pickup\Rest\CheckoutPickupPointRestController;
use WallsShop\WDC\Pickup\Rest\PickupPointsRestController;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;

function dpd_checkout_pickup_assert( bool $condition, string $message ): void {
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
function __return_true(): bool { return true; }
function wp_verify_nonce( string $nonce, string $action ): bool { return true; }
function current_time( string $type ): string { return '2026-06-17 12:00:00'; }

function WC(): object {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new class {
			public object $session;
			public function __construct() {
				$this->session = new class {
					/** @var array<string,mixed> */
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
		/** @var array<int,array<string,mixed>> */
		public array $dpd_pickup_points = array();
		/** @var array<int,array<string,mixed>> */
		public array $delivery_codes = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $replacement, $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

final class DpdCheckoutPickupRequest {
	/** @param array<string,mixed> $params */
	public function __construct( private array $params ) {}
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? ''; }
	public function get_header( string $key ): string { return ''; }
}

final class DpdCheckoutPickupErrors {
	/** @var array<string,string> */
	public array $errors = array();
	public function add( string $code, string $message ): void { $this->errors[ $code ] = $message; }
	public function has( string $code ): bool { return isset( $this->errors[ $code ] ); }
}

final class DpdCheckoutPickupOrder {
	/** @var array<string,mixed> */
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
$GLOBALS['wpdb']->delivery_codes = array(
	array( 'location_id' => 77, 'dpd_city_id' => '49455627', 'updated_at' => '2026-06-17 12:00:00' ),
);
$GLOBALS['wpdb']->dpd_pickup_points = array(
	array( 'id' => 1, 'terminal_code' => 'NSK-PS-1', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49455627, 'city_name' => 'Новосибирск', 'region_name' => 'Новосибирская обл.', 'address' => 'ул Ленина, 1', 'name' => 'DPD Ленина', 'latitude' => 55.030199, 'longitude' => 82.92043, 'schedule' => 'пн-пт 09:00-18:00', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 2, 'terminal_code' => 'NSK-TERM-1', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 49455627, 'city_name' => 'Новосибирск', 'region_name' => 'Новосибирская обл.', 'address' => 'Складская, 2', 'name' => 'DPD Терминал', 'latitude' => 55.100000, 'longitude' => 82.950000, 'schedule' => 'ежедневно', 'source' => 'getTerminalsSelfDelivery2', 'is_active' => 1 ),
	array( 'id' => 3, 'terminal_code' => 'INACTIVE', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49455627, 'city_name' => 'Новосибирск', 'address' => 'Закрытый', 'source' => 'getParcelShops', 'is_active' => 0 ),
);

$repository = new DpdPickupPointRepository( $GLOBALS['wpdb'] );
$delivery_codes = new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] );
$service = new DpdPickupPointService( $repository, $delivery_codes );

dpd_checkout_pickup_assert( 2 === count( $service->get_points_for_location_id( 77 ) ), 'DpdPickupPointService must return active points by location_id through dpd_city_id.' );
dpd_checkout_pickup_assert( array() === $service->get_points_for_location_id( 78 ), 'DpdPickupPointService must return empty list when location_id has no dpd_city_id.' );

$points_controller = new PickupPointsRestController( new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ), null, null, null, $service );
$points = $points_controller->points( array( 'carrier' => 'dpd', 'location_id' => '77', 'limit' => '20' ) );
dpd_checkout_pickup_assert( 2 === count( $points ) && 'NSK-PS-1' === (string) ( $points[0]['terminal_code'] ?? '' ), 'Endpoint must return DPD pickup points for location_id.' );
dpd_checkout_pickup_assert( 'dpd:pickup' === (string) ( $points[0]['pickup_family'] ?? '' ) && 'Пункт выдачи DPD' === (string) ( $points[0]['point_title'] ?? '' ), 'Endpoint DPD response shape must match checkout pickup UI shape.' );
dpd_checkout_pickup_assert( array() === $points_controller->points( array( 'carrier' => 'dpd', 'location_id' => '78' ) ), 'Endpoint must return empty list without dpd_city_id.' );
$searched = $points_controller->search( array( 'carrier' => 'dpd', 'location_id' => '77', 'q' => 'Складская' ) );
dpd_checkout_pickup_assert( 1 === count( $searched ) && 'NSK-TERM-1' === (string) ( $searched[0]['point_code'] ?? '' ), 'Endpoint must filter DPD points by query.' );

$session = new CheckoutSessionManager();
$pickup_rate_id = 'dpd:pickup:max';
$courier_rate_id = 'dpd:courier:max';
$session->save_rates(
	array(
		$pickup_rate_id => array( 'carrier_key' => 'dpd', 'service_key' => 'dpd', 'rate_id' => $pickup_rate_id, 'delivery_type' => DeliveryType::PICKUP, 'requires_pickup_point' => true ),
		$courier_rate_id => array( 'carrier_key' => 'dpd', 'service_key' => 'dpd', 'rate_id' => $courier_rate_id, 'delivery_type' => DeliveryType::COURIER, 'requires_pickup_point' => false, 'requires_courier_address' => true ),
		'cdek:pickup:136' => array( 'carrier_key' => 'cdek', 'service_key' => 'cdek', 'rate_id' => 'cdek:pickup:136', 'delivery_type' => DeliveryType::PICKUP, 'requires_pickup_point' => true ),
	)
);
$session->save_city_context( array( 'location_id' => 77, 'city_name' => 'Новосибирск', 'region_name' => 'Новосибирская обл.', 'country_code' => 'RU' ) );

$checkout_rest = new CheckoutPickupPointRestController( new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ), $session, null, null, $service );
$save_response = $checkout_rest->save( new DpdCheckoutPickupRequest( array( 'carrier' => 'dpd', 'shipping_method_id' => $pickup_rate_id, 'point_code' => 'NSK-PS-1' ) ) );
dpd_checkout_pickup_assert( 'NSK-PS-1' === (string) ( $save_response['pickup_point']['point_code'] ?? '' ), 'Checkout save endpoint must persist selected DPD terminal_code.' );
dpd_checkout_pickup_assert( 'dpd:pickup' === (string) ( $save_response['active_pickup_family'] ?? '' ), 'Checkout save endpoint must save DPD selection in dpd:pickup bucket.' );
$inactive_response = $checkout_rest->save( new DpdCheckoutPickupRequest( array( 'carrier' => 'dpd', 'shipping_method_id' => $pickup_rate_id, 'point_code' => 'INACTIVE' ) ) );
dpd_checkout_pickup_assert( is_array( $inactive_response ) && 'not_found' === (string) ( $inactive_response['code'] ?? '' ), 'Checkout save endpoint must reject inactive DPD terminal_code.' );

$validation = new CheckoutValidation( $session, new CheckoutAddressValidation( $session ), null, $service );
$errors = new DpdCheckoutPickupErrors();
$validation->validate( array( 'shipping_method' => array( $pickup_rate_id ), 'shipping_city' => 'Новосибирск' ), $errors );
dpd_checkout_pickup_assert( false === $errors->has( 'wdc_pickup_required' ), 'Validation must pass when active DPD terminal_code is saved.' );

$session->clear_pickup_selection( 'test' );
$missing_errors = new DpdCheckoutPickupErrors();
$validation->validate( array( 'shipping_method' => array( $pickup_rate_id ), 'shipping_city' => 'Новосибирск' ), $missing_errors );
dpd_checkout_pickup_assert( 'Выберите пункт выдачи DPD.' === (string) ( $missing_errors->errors['wdc_pickup_required'] ?? '' ), 'DPD pickup validation must fail without terminal_code.' );

$inactive_errors = new DpdCheckoutPickupErrors();
$validation->validate( array( 'shipping_method' => array( $pickup_rate_id ), 'shipping_city' => 'Новосибирск', 'wdc_pickup_point_code' => 'INACTIVE', 'wdc_pickup_carrier_key' => 'dpd', 'wdc_pickup_family' => 'dpd:pickup' ), $inactive_errors );
dpd_checkout_pickup_assert( $inactive_errors->has( 'wdc_pickup_required' ), 'DPD pickup validation must fail when terminal_code does not exist or is inactive.' );

$courier_errors = new DpdCheckoutPickupErrors();
$validation->validate( array( 'shipping_method' => array( $courier_rate_id ), 'shipping_city' => 'Новосибирск', 'shipping_address_1' => 'ул Ленина, 1' ), $courier_errors );
dpd_checkout_pickup_assert( false === $courier_errors->has( 'wdc_pickup_required' ), 'DPD courier rate must not require terminal_code.' );

$non_dpd_errors = new DpdCheckoutPickupErrors();
$validation->validate( array( 'shipping_method' => array( 'cdek:pickup:136' ), 'shipping_city' => 'Новосибирск' ), $non_dpd_errors );
dpd_checkout_pickup_assert( 'Выберите пункт выдачи.' === (string) ( $non_dpd_errors->errors['wdc_pickup_required'] ?? '' ), 'Non-DPD pickup validation must keep generic pickup requirement.' );

$checkout_rest->save( new DpdCheckoutPickupRequest( array( 'carrier' => 'dpd', 'shipping_method_id' => $pickup_rate_id, 'point_code' => 'NSK-PS-1' ) ) );
WC()->session->set( 'chosen_shipping_methods', array( $pickup_rate_id ) );
$order = new DpdCheckoutPickupOrder();
( new OrderShippingMetaPersister( $session ) )->persist( $order, array() );
dpd_checkout_pickup_assert( 'NSK-PS-1' === (string) ( $order->meta['_wdc_pickup_point_code'] ?? '' ), 'Order meta must save canonical pickup point code.' );
dpd_checkout_pickup_assert( 'NSK-PS-1' === (string) ( $order->meta['_wdc_dpd_pickup_terminal_code'] ?? '' ), 'Order meta must save DPD terminal_code alias.' );
dpd_checkout_pickup_assert( 'DPD Ленина' === (string) ( $order->meta['_wdc_dpd_pickup_name'] ?? '' ) && 'ул Ленина, 1' === (string) ( $order->meta['_wdc_dpd_pickup_address'] ?? '' ), 'Order meta must save selected DPD name and address.' );
dpd_checkout_pickup_assert( 'Новосибирск' === (string) ( $order->meta['_wdc_dpd_pickup_city_name'] ?? '' ) && 'getParcelShops' === (string) ( $order->meta['_wdc_dpd_pickup_source'] ?? '' ), 'Order meta must save selected DPD city and source.' );

$delivery_selector_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/CheckoutDeliveryTypeSelector.php' );
$pickup_map_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/PickupMapCheckout.php' );
$points_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Rest/PickupPointsRestController.php' );
$tariff_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Dpd/Tariff/DpdTariffCalculationService.php' );
$runtime_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Runtime/DpdQuoteCarrier.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$shipments_metabox = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
dpd_checkout_pickup_assert( str_contains( $delivery_selector_source, 'render_pickup_map_selector' ) && str_contains( $pickup_map_source, "'dpd:pickup'" ), 'Checkout UI registration/hook must exist for DPD pickup.' );
dpd_checkout_pickup_assert( str_contains( $points_source, 'DpdPickupPointService' ) && str_contains( $points_source, 'dpd_points' ), 'Pickup points endpoint must be extended for DPD.' );
dpd_checkout_pickup_assert( str_contains( $runtime_source, 'getServiceCostByParcels2' ) || ! str_contains( $runtime_source, 'getServiceCostByParcels3' ), 'DPD runtime must not switch to getServiceCostByParcels3.' );
dpd_checkout_pickup_assert( ! str_contains( $tariff_source, 'terminalCode' ) && ! str_contains( $runtime_source, 'terminalCode' ), 'DPD terminalCode must not be sent to tariff calculation yet.' );
dpd_checkout_pickup_assert( ! str_contains( $plugin_source, 'DpdShipmentAdapter' ) && ! str_contains( $shipments_metabox, 'DpdShipmentAdapter' ), 'DPD shipment adapter/metabox must not be added.' );

echo "DPD checkout pickup selection smoke test passed.\n";
