<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
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

function pickup_checkout_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) ) ?? ''; }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function rest_ensure_response( mixed $data ): mixed { return $data; }
function __return_true(): bool { return true; }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function wp_verify_nonce( string $nonce, string $action ): bool { return 'nonce' === $nonce && 'wp_rest' === $action; }
function register_rest_route( string $namespace, string $route, array $args ): bool {
	$GLOBALS['wdc_pickup_checkout_routes'][] = compact( 'namespace', 'route', 'args' );
	return true;
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code, public string $message, public array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $tables = array();
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $value, $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
		public function get_row( string $query, mixed $output = null ): ?array {
			foreach ( $this->rows_for_query( $query ) as $row ) {
				if ( preg_match( '/WHERE id = ([0-9]+)/', $query, $m ) && (int) $row['id'] === (int) $m[1] ) {
					return $row;
				}
			}
			return null;
		}
		public function get_results( string $query, mixed $output = null ): array {
			$rows = $this->rows_for_query( $query );
			if ( str_contains( $query, 'active = 1' ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => 1 === (int) ( $row['active'] ?? 0 ) ) );
			}
			if ( preg_match( '/longitude BETWEEN ([0-9.\\-]+) AND ([0-9.\\-]+)/', $query, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (float) $row['longitude'] >= (float) $m[1] && (float) $row['longitude'] <= (float) $m[2] ) );
			}
			if ( preg_match( '/latitude BETWEEN ([0-9.\\-]+) AND ([0-9.\\-]+)/', $query, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (float) $row['latitude'] >= (float) $m[1] && (float) $row['latitude'] <= (float) $m[2] ) );
			}
			return $rows;
		}
		private function rows_for_query( string $query ): array {
			preg_match( '/FROM ([A-Za-z0-9_]+)/', $query, $m );
			return $this->tables[ $m[1] ?? '' ] ?? array();
		}
	}
}

$GLOBALS['wdc_pickup_checkout_session'] = new class {
	public array $data = array();
	public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
	public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
};
$GLOBALS['wdc_pickup_checkout_wc'] = new class {
	public mixed $session;
	public function __construct() { $this->session = $GLOBALS['wdc_pickup_checkout_session']; }
};
function WC(): object { return $GLOBALS['wdc_pickup_checkout_wc']; }

final class WdcPickupCheckoutRequest {
	public function __construct( private array $params, private array $headers = array() ) {}
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? ''; }
	public function get_header( string $key ): string { return (string) ( $this->headers[ $key ] ?? '' ); }
}

final class WdcPickupCheckoutErrors {
	public array $errors = array();
	public function add( string $code, string $message ): void { $this->errors[ $code] = $message; }
}

final class WdcPickupCheckoutOrder {
	public array $meta = array();
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function set_shipping_address_1( string $value ): void {}
	public function set_shipping_address_2( string $value ): void {}
	public function set_shipping_city( string $value ): void {}
	public function set_shipping_postcode( string $value ): void {}
	public function set_shipping_country( string $value ): void {}
}

final class WdcPickupCheckoutItem {
	public array $meta = array();
	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void { $this->meta[ $key ] = $value; }
	public function delete_meta_data( string $key ): void {}
	public function set_method_title( string $title ): void {}
}

$GLOBALS['wpdb'] = new wpdb();
$repo = new RussianPostPickupPointRepository( $GLOBALS['wpdb'] );
$GLOBALS['wpdb']->tables[ $repo->main_table() ] = array(
	array( 'id' => 10, 'point_code' => '630001-a', 'point_type' => 'OPS', 'postcode' => '630001', 'address' => 'Ленина, 1', 'city_name' => 'Новосибирск', 'region_name' => 'НСО', 'latitude' => 55.01, 'longitude' => 82.91, 'work_time' => '09-18', 'description' => 'ОПС', 'active' => 1 ),
);

$points_controller = new PickupPointsRestController( $repo );
$bbox = $points_controller->points( array( 'carrier' => 'russian_post', 'bbox' => '82.9,55,83,56' ) );
pickup_checkout_assert( 1 === count( $bbox ), 'bbox endpoint must return active points.' );

$session = new CheckoutSessionManager();
$state_controller = new CheckoutPickupPointRestController( $repo, $session );
$state_controller->register();
pickup_checkout_assert( 2 === count( $GLOBALS['wdc_pickup_checkout_routes'] ?? array() ), 'checkout REST routes must register.' );

$saved = $state_controller->save( new WdcPickupCheckoutRequest( array( 'point_id' => 10, 'shipping_method_id' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_checkout_assert( '630001-a' === $saved['pickup_point']['point_code'], 'checkout state save must return selected point.' );
pickup_checkout_assert( '630001-a' === $session->checkout_pickup_point()['point_code'], 'selection must be stored in WC session wdc_pickup_point.' );
pickup_checkout_assert( '630001-a' === $state_controller->state()['pickup_point']['point_code'], 'checkout state GET must return current pickup selection.' );

$rate = array(
	'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
	'rate_id' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY,
	'service_key' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY,
	'service_title' => 'Почта России',
	'delivery_type' => 'pickup',
	'requires_pickup_point' => true,
	'rate_meta' => array(),
	'delivery_days' => array(),
);
$session->save_rates( array( RussianPostDomesticSettings::PICKUP_SERVICE_KEY => $rate ) );
WC()->session->set( 'chosen_shipping_methods', array( RussianPostDomesticSettings::PICKUP_SERVICE_KEY ) );

$order = new WdcPickupCheckoutOrder();
$persister = new OrderShippingMetaPersister( $session );
$persister->persist( $order, array() );
pickup_checkout_assert( '10' === (string) $order->meta['_wdc_pickup_point_id'], 'order meta must save pickup point id.' );
pickup_checkout_assert( str_contains( (string) $order->meta['_wdc_pickup_point_snapshot'], '630001-a' ), 'order meta must save snapshot JSON.' );

$item = new WdcPickupCheckoutItem();
$persister->persist_shipping_item_meta( $item );
pickup_checkout_assert( 'Ленина, 1' === (string) ( $item->meta['Пункт выдачи'] ?? '' ), 'shipping item meta must save pickup address.' );
pickup_checkout_assert( '630001' === (string) ( $item->meta['Индекс ПВЗ'] ?? '' ), 'shipping item meta must save pickup postcode.' );

$state_controller->delete();
pickup_checkout_assert( array() === $session->checkout_pickup_point(), 'reset must clear checkout pickup point.' );
pickup_checkout_assert( null === $state_controller->state()['pickup_point'], 'checkout state GET must return null after reset.' );

$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
pickup_checkout_assert( isset( $errors->errors['wdc_pickup_required'] ), 'validation must block checkout without pickup.' );
pickup_checkout_assert( 'Выберите пункт выдачи Почты России.' === $errors->errors['wdc_pickup_required'], 'validation must use Russian Post pickup error.' );

$session->save_pickup_selection( array( 'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY, 'rate_id' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY, 'point_code' => '630001-a' ) );
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
pickup_checkout_assert( array() === $errors->errors, 'validation must pass when pickup selection exists.' );

echo "Pickup checkout smoke test passed.\n";
