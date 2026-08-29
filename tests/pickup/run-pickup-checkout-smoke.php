<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutDeliveryTypeSelector;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutRateRenderer;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Checkout\WooCommerce\PickupPointOrderDisplay;
use WallsShop\WDC\Checkout\WooCommerce\PickupPointRenderer;
use WallsShop\WDC\Checkout\WooCommerce\PickupMapCheckout;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceSessionBootstrapper;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Admin\SettingsAdminPage;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Pickup\Rest\CheckoutPickupPointRestController;
use WallsShop\WDC\Pickup\Rest\PickupPointsRestController;
use WallsShop\WDC\Pickup\Presentation\PickupPointCardRenderer;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\Services\PickupPointLocationResolver;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;

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
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function wp_verify_nonce( string $nonce, string $action ): bool { return 'nonce' === $nonce && 'wp_rest' === $action; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_pickup_checkout_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string|null $autoload = null ): bool { $GLOBALS['wdc_pickup_checkout_options'][ $key ] = $value; return true; }
function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
function is_checkout(): bool { return true; }
function rest_url( string $path = '' ): string { return '/wp-json/' . ltrim( $path, '/' ); }
function wp_create_nonce( string $action ): string { return 'nonce'; }
function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), string|bool|null $ver = false ): void { $GLOBALS['wdc_pickup_checkout_enqueued_styles'][ $handle ] = compact( 'src', 'deps', 'ver' ); }
function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), string|bool|null $ver = false, bool $in_footer = false ): void { $GLOBALS['wdc_pickup_checkout_enqueued_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' ); }
function wp_localize_script( string $handle, string $object_name, array $l10n ): void { $GLOBALS['wdc_pickup_checkout_localized'][ $handle ][ $object_name ] = $l10n; }
function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['wdc_pickup_checkout_actions'][ $hook ][] = compact( 'callback', 'priority', 'accepted_args' ); }
function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
	$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}
function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
	$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}
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
		public array $locations = array();
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
				if ( preg_match( "/WHERE point_code = '([^']+)'/", $query, $m ) && (string) ( $row['point_code'] ?? '' ) === $m[1] ) {
					return $row;
				}
				if ( preg_match( "/l.postal_code = '([^']+)'/", $query, $m ) && (string) ( $row['postal_code'] ?? '' ) === $m[1] ) {
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
			if ( preg_match( "/l.country_code = '([^']+)'/", $query, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['country_code'] ?? '' ) === $m[1] ) );
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
	public function mailer(): object {
		return new class {
			public function get_emails(): array {
				return array(
					'WC_Email_New_Order' => (object) array( 'id' => 'new_order', 'title' => 'New order' ),
					'WC_Email_Customer_Processing_Order' => (object) array( 'id' => 'customer_processing_order', 'title' => 'Processing order' ),
					'WC_Email_OSM_Custom' => (object) array( 'id' => 'wc_order_status_manager_custom_status', 'title' => 'Custom status' ),
				);
			}
		};
	}
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
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
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
$GLOBALS['wpdb']->tables[ $repo->main_table() ][0]['fias_location_guid'] = '11111111-1111-1111-1111-111111111111';
$GLOBALS['wpdb']->tables[ $repo->main_table() ][] = array( 'id' => 11, 'point_code' => '660000-b', 'point_type' => 'PVZ', 'postcode' => '660000', 'address' => 'Krasnoyarsk, 2', 'city_name' => 'Krasnoyarsk', 'region_name' => 'Krasnoyarsk krai', 'fias_location_guid' => '22222222-2222-2222-2222-222222222222', 'latitude' => 56.01, 'longitude' => 92.85, 'work_time' => '10-19', 'description' => 'PVZ', 'active' => 1 );
$location_repo = new LocationRepository( $GLOBALS['wpdb'] );
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 100, 'fias_id' => '11111111-1111-1111-1111-111111111111', 'gar_id' => '100', 'gar_object_id' => 100, 'country_code' => 'RU', 'region_name' => 'Новосибирская', 'region_type' => 'область', 'region_code' => '54', 'city_name' => 'Новосибирск', 'city_type' => 'г', 'place_name' => 'Новосибирск', 'place_type' => 'г', 'display_name' => 'Новосибирская область, г Новосибирск', 'postal_code' => '630001', 'latitude' => 55.01, 'longitude' => 82.91, 'active' => 1 ),
	array( 'id' => 101, 'fias_id' => '22222222222222222222222222222222', 'gar_id' => '101', 'gar_object_id' => 101, 'country_code' => 'RU', 'region_name' => 'Krasnoyarsk krai', 'region_code' => '24', 'city_name' => 'Krasnoyarsk', 'settlement_name' => 'Krasnoyarsk', 'display_name' => 'Krasnoyarsk', 'postal_code' => '660000', 'latitude' => 56.01, 'longitude' => 92.85, 'active' => 1 ),
);

$points_controller = new PickupPointsRestController( $repo );
$bbox = $points_controller->points( array( 'carrier' => 'russian_post', 'bbox' => '82.9,55,83,56' ) );
pickup_checkout_assert( 1 === count( $bbox ), 'bbox endpoint must return active points.' );

$session = new CheckoutSessionManager();
$saved_cdek_bucket = $session->save_pickup_selection_for_family(
	'cdek:pickup',
	array(
		'carrier_key' => 'cdek',
		'service_key' => 'cdek',
		'point_code' => 'KEM7',
		'point_address' => 'CDEK address',
	)
);
pickup_checkout_assert( 'KEM7' === (string) ( $saved_cdek_bucket['point_code'] ?? '' ) && 'KEM7' === (string) ( $session->pickup_selections()['cdek:pickup']['point_code'] ?? '' ), 'save_pickup_selection_for_family must immediately return and expose the CDEK canonical bucket.' );
pickup_checkout_assert( 'KEM7' === (string) ( $GLOBALS['wdc_pickup_checkout_session']->data['wdc_platform_pickup_selections']['cdek:pickup']['point_code'] ?? '' ), 'Raw WC session key must contain the CDEK pickup bucket immediately after save.' );
$session->save_pickup_selection_for_family(
	RussianPostDomesticSettings::CARRIER_KEY . ':pickup',
	array(
		'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
		'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
		'point_code' => '630001-a',
		'point_address' => 'Ленина, 1',
	)
);
pickup_checkout_assert( 'KEM7' === (string) ( $session->pickup_selections()['cdek:pickup']['point_code'] ?? '' ) && '630001-a' === (string) ( $session->pickup_selections()[ RussianPostDomesticSettings::CARRIER_KEY . ':pickup' ]['point_code'] ?? '' ), 'Sequential family saves must keep CDEK and Russian Post buckets together.' );
$session->clear_pickup_selection( 'canonical_write_smoke_reset' );
$existing_wc_session = WC()->session;
$state_controller = new CheckoutPickupPointRestController( $repo, $session, new PickupPointLocationResolver( $location_repo ), null, null, null, null, null, null, new WooCommerceSessionBootstrapper() );
$state_controller->register();
pickup_checkout_assert( 3 === count( $GLOBALS['wdc_pickup_checkout_routes'] ?? array() ), 'checkout REST routes must register.' );
$state_route = array_values( array_filter( $GLOBALS['wdc_pickup_checkout_routes'], static fn( array $route ): bool => '/checkout/state' === $route['route'] ) )[0] ?? array();
pickup_checkout_assert( is_array( $state_route['args'] ?? null ) && is_callable( $state_route['args']['permission_callback'] ?? null ), 'checkout state GET must register a nonce permission callback.' );
$resolve_route = array_values( array_filter( $GLOBALS['wdc_pickup_checkout_routes'], static fn( array $route ): bool => '/checkout/pickup-point/resolve-location' === $route['route'] ) )[0] ?? array();
pickup_checkout_assert( false === $resolve_route['args']['permission_callback']( new WdcPickupCheckoutRequest( array() ) ), 'resolve-location endpoint without nonce must be forbidden.' );
pickup_checkout_assert( true === $resolve_route['args']['permission_callback']( new WdcPickupCheckoutRequest( array(), array( 'X-WP-Nonce' => 'nonce' ) ) ), 'resolve-location endpoint with nonce must be authorized.' );
pickup_checkout_assert( false === $state_controller->check_nonce( new WdcPickupCheckoutRequest( array() ) ), 'checkout state GET without nonce must be forbidden.' );
pickup_checkout_assert( true === $state_controller->check_nonce( new WdcPickupCheckoutRequest( array(), array( 'X-WP-Nonce' => 'nonce' ) ) ), 'checkout state GET with nonce must be authorized.' );

$pickup_group_id = RussianPostDomesticSettings::checkout_group_id( \WallsShop\WDC\Domain\Quote\DeliveryType::PICKUP );
$courier_group_id = RussianPostDomesticSettings::checkout_group_id( \WallsShop\WDC\Domain\Quote\DeliveryType::COURIER );

$session->save_pickup_selection_for_family( 'yandex_delivery:pickup', array( 'carrier_key' => 'yandex_delivery', 'service_key' => 'yandex_delivery', 'pickup_family' => 'yandex_delivery:pickup', 'point_code' => 'ya-good', 'point_address' => 'Yandex point', 'address' => 'Yandex point' ) );
$session->save_rates(
	array(
		'pek:pickup' => array(
			'carrier_key' => 'pek',
			'rate_id' => 'pek:pickup',
			'service_key' => 'pek',
			'delivery_type' => 'pickup',
			'pickup_family' => 'pek:pickup',
			'requires_pickup_point' => true,
			'rate_meta' => array( 'pickup_family' => 'pek:pickup' ),
		),
		'yandex_pickup' => array(
			'carrier_key' => 'yandex_delivery',
			'rate_id' => 'yandex_pickup',
			'service_key' => 'yandex_delivery',
			'delivery_type' => 'pickup',
			'pickup_family' => 'yandex_delivery:pickup',
			'requires_pickup_point' => true,
			'rate_meta' => array( 'pickup_family' => 'yandex_delivery:pickup' ),
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:pek:pickup' ) );
$recovery_state = $state_controller->state();
pickup_checkout_assert( null === $recovery_state['pickup_point'] && null === $recovery_state['selected_pickup_point'], 'checkout state GET must not restore a cleared PEK selected point after recovery.' );
pickup_checkout_assert( 'pek:pickup' === (string) ( $recovery_state['active_pickup_family'] ?? '' ) && ! isset( $recovery_state['pickup_selections']['pek:pickup'] ), 'checkout state GET must keep PEK active without stale PEK selection after recovery.' );
pickup_checkout_assert( 'ya-good' === (string) ( $recovery_state['pickup_selections']['yandex_delivery:pickup']['point_code'] ?? '' ), 'checkout state GET must preserve other carrier pickup selections after PEK recovery.' );
$session->clear_pickup_selection( 'pickup_recovery_state_smoke_reset' );
WC()->session->set( 'chosen_shipping_methods', array( $pickup_group_id ) );

$saved = $state_controller->save( new WdcPickupCheckoutRequest( array( 'point_id' => 10, 'shipping_method_id' => $pickup_group_id ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_checkout_assert( '630001-a' === $saved['pickup_point']['point_code'], 'checkout state save must return selected point.' );
pickup_checkout_assert( $existing_wc_session === WC()->session, 'Shared WooCommerce session bootstrapper must not replace an existing checkout session instance.' );
pickup_checkout_assert( RussianPostDomesticSettings::CARRIER_KEY === (string) ( $saved['pickup_point']['carrier_key'] ?? '' ) && $pickup_group_id === (string) ( $saved['pickup_point']['pickup_family'] ?? '' ), 'Russian Post checkout save must return normalized carrier_key and pickup_family.' );
pickup_checkout_assert( 'Отделение Почты России' === (string) ( $saved['pickup_point']['point_title'] ?? '' ) && 'Пункт выдачи' === (string) ( $saved['pickup_point']['point_type_label'] ?? '' ) && 'pickup' === (string) ( $saved['pickup_point']['marker_type'] ?? '' ), 'Russian Post checkout save must return full pickup presentation payload.' );
pickup_checkout_assert( is_array( $saved['pickup_point']['snapshot'] ?? null ) && RussianPostDomesticSettings::CARRIER_KEY === (string) ( $saved['pickup_point']['snapshot']['carrier_key'] ?? '' ), 'Russian Post checkout save response must include normalized snapshot.' );
pickup_checkout_assert( $pickup_group_id === (string) ( $saved['active_pickup_family'] ?? '' ) && '630001-a' === (string) ( $saved['pickup_selections'][ $pickup_group_id ]['point_code'] ?? '' ) && '630001-a' === (string) ( $saved['pickupSelections'][ $pickup_group_id ]['point_code'] ?? '' ), 'Checkout save response must return active pickup family and pickup selections dictionary.' );
pickup_checkout_assert( array( $pickup_group_id ) === array_keys( $session->raw_pickup_selections() ) && '630001-a' === (string) ( $session->pickup_selections()[ $pickup_group_id ]['point_code'] ?? '' ), 'Russian Post REST save must write the canonical pickup family bucket into WC session.' );
pickup_checkout_assert( '630001-a' === $session->checkout_pickup_point()['point_code'], 'selection must be stored in WC session wdc_pickup_point.' );
$initial_state = $state_controller->state();
pickup_checkout_assert( '630001-a' === $initial_state['pickup_point']['point_code'], 'checkout state GET must return current pickup selection.' );
pickup_checkout_assert( '630001-a' === (string) ( $initial_state['pickup_selections'][ $pickup_group_id ]['point_code'] ?? '' ) && '630001-a' === (string) ( $initial_state['pickupSelections'][ $pickup_group_id ]['point_code'] ?? '' ), 'checkout state GET must return pickup selections dictionary aliases.' );
$session->save_city_context( array( 'lat' => 56.0106, 'lng' => 92.8526, 'postcode' => '660000', 'display_name' => 'Красноярск', 'region_name' => 'Красноярский край', 'country_code' => 'RU' ) );
$state = $state_controller->state();
pickup_checkout_assert( 56.0106 === (float) $state['city_context']['lat'] && 92.8526 === (float) $state['city_context']['lng'], 'checkout state GET must expose enriched city_context lat/lng.' );
pickup_checkout_assert( 'Красноярск' === (string) $state['city_context']['display_name'], 'checkout state GET must expose enriched city display_name.' );

$session->save_city_context( array( 'location_id' => '101', 'fias_id' => '22222222-2222-2222-2222-222222222222', 'lat' => 56.0106, 'lng' => 92.8526, 'postcode' => '660000', 'display_name' => 'Krasnoyarsk', 'region_name' => 'Krasnoyarsk krai', 'country_code' => 'RU' ) );
$state = $state_controller->state();
pickup_checkout_assert( '101' === (string) $state['city_context']['location_id'] && '22222222-2222-2222-2222-222222222222' === (string) $state['city_context']['fias_id'], 'checkout state GET must expose local location identity for pickup cross-location checks.' );
$same_resolve = $state_controller->resolve_location( new WdcPickupCheckoutRequest( array( 'point' => array( 'postal_code' => '660000', 'city' => 'Krasnoyarsk', 'region' => 'Krasnoyarsk krai', 'fias_location_guid' => '22222222-2222-2222-2222-222222222222' ), 'checkout_context' => $state['city_context'] ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_checkout_assert( false === $same_resolve['requires_location_change'] && '101' === (string) $same_resolve['location']['id'], 'resolve-location endpoint must return no change for the same FIAS location.' );
$fias_priority_resolve = $state_controller->resolve_location( new WdcPickupCheckoutRequest( array( 'point' => array( 'postal_code' => '660000', 'city' => 'Krasnoyarsk', 'region' => 'Krasnoyarsk krai', 'fias_location_guid' => '11111111-1111-1111-1111-111111111111' ), 'checkout_context' => $state['city_context'] ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_checkout_assert( '100' === (string) $fias_priority_resolve['location']['id'], 'resolve-location endpoint must prefer pickup fias_location_guid over a conflicting postal_code match.' );
$point_id_resolve = $state_controller->resolve_location( new WdcPickupCheckoutRequest( array( 'point_id' => 10, 'checkout_context' => $state['city_context'] ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_checkout_assert( true === $point_id_resolve['requires_location_change'] && '100' === (string) $point_id_resolve['location']['id'], 'resolve-location endpoint must use fias_location_guid from point_id row payload.' );
$different_resolve = $state_controller->resolve_location( new WdcPickupCheckoutRequest( array( 'point' => array( 'postal_code' => '630001', 'city' => 'Novosibirsk', 'region' => 'NSO', 'fias_location_guid' => '11111111-1111-1111-1111-111111111111' ), 'checkout_context' => $state['city_context'] ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_checkout_assert( true === $different_resolve['requires_location_change'] && '100' === (string) $different_resolve['location']['id'], 'resolve-location endpoint must detect another FIAS location and find it by pickup fias_location_guid.' );
pickup_checkout_assert( '11111111-1111-1111-1111-111111111111' === (string) ( $different_resolve['location']['fias_id'] ?? '' ) && '100' === (string) ( $different_resolve['location']['gar_object_id'] ?? '' ) && '54' === (string) ( $different_resolve['location']['region_code'] ?? '' ) && 'область' === (string) ( $different_resolve['location']['region_type'] ?? '' ) && 'г' === (string) ( $different_resolve['location']['city_type'] ?? '' ) && 'г' === (string) ( $different_resolve['location']['place_type'] ?? '' ), 'resolve-location payload must include full location identity and type fields for checkout formatting.' );
pickup_checkout_assert( 'Новосибирская область' === (string) ( $different_resolve['location']['state_value'] ?? '' ) && 'г Новосибирск' === (string) ( $different_resolve['location']['city_value'] ?? '' ), 'resolve-location payload must include city selector compatible state_value and city_value.' );
$fallback_resolve = $state_controller->resolve_location( new WdcPickupCheckoutRequest( array( 'point' => array( 'postal_code' => '630001', 'city' => 'Novosibirsk', 'region' => 'NSO' ), 'checkout_context' => $state['city_context'] ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_checkout_assert( true === $fallback_resolve['requires_location_change'] && '100' === (string) $fallback_resolve['location']['id'], 'resolve-location endpoint must still fall back to postal_code/city when pickup FIAS is missing.' );
$missing_resolve = $state_controller->resolve_location( new WdcPickupCheckoutRequest( array( 'point' => array( 'postal_code' => '999999', 'city' => 'Missing' ), 'checkout_context' => $state['city_context'] ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_checkout_assert( false === $missing_resolve['requires_location_change'] && null === $missing_resolve['location'], 'resolve-location endpoint must safely return location=null when local location is not found.' );

$rate = array(
	'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
	'rate_id' => $pickup_group_id,
	'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
	'service_title' => 'Почта России',
	'delivery_type' => 'pickup',
	'requires_pickup_point' => true,
	'rate_meta' => array(),
	'delivery_days' => array(),
);
$session->save_rates( array( $pickup_group_id => $rate ) );
WC()->session->set( 'chosen_shipping_methods', array( $pickup_group_id ) );

$root = dirname( __DIR__, 2 );
$checkout_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-checkout.js' ) ?: '';
pickup_checkout_assert( ! str_contains( $checkout_js, 'pickupDebugEnabled' ) && ! str_contains( $checkout_js, 'function debugGroup' ) && ! str_contains( $checkout_js, 'console.groupCollapsed' ), 'Temporary frontend pickup diagnostics must be removed from checkout JS.' );
$environment = new PluginEnvironment( $root . '/walls-delivery-calc.php', $root, 'https://example.test/wp-content/plugins/walls-delivery-calc/', '0.24.0' );
$map_settings = new SettingsRepository();
$map_settings->replace( array_merge( $map_settings->defaults(), array( 'pickup_map_provider' => 'leaflet' ) ) );
$point_type_settings = new RussianPostPickupPointTypeSettings( $map_settings );
$GLOBALS['wdc_pickup_checkout_enqueued_styles'] = array();
$GLOBALS['wdc_pickup_checkout_enqueued_scripts'] = array();
$GLOBALS['wdc_pickup_checkout_localized'] = array();
( new PickupMapCheckout( $session, $environment, $map_settings, $point_type_settings ) )->enqueue_assets();
pickup_checkout_assert( isset( $GLOBALS['wdc_pickup_checkout_enqueued_scripts']['wdc-leaflet'], $GLOBALS['wdc_pickup_checkout_enqueued_styles']['wdc-leaflet'], $GLOBALS['wdc_pickup_checkout_enqueued_scripts']['wdc-map-provider-leaflet'] ), 'Leaflet provider must enqueue Leaflet assets and Leaflet provider adapter.' );
pickup_checkout_assert( ! isset( $GLOBALS['wdc_pickup_checkout_enqueued_scripts']['wdc-map-provider-yandex'] ), 'Leaflet provider must not enqueue the Yandex adapter.' );
$localized_leaflet = $GLOBALS['wdc_pickup_checkout_localized']['wdc-pickup-checkout']['wdcPickupCheckout'] ?? array();
pickup_checkout_assert( ! array_key_exists( 'debug', $localized_leaflet ) && ! array_key_exists( 'pickupDebug', $localized_leaflet ), 'Pickup checkout localization must not expose temporary frontend diagnostics flags.' );
$ops_type_keys = array_keys( $localized_leaflet['pickupPointTypes']['OPS'] ?? array() );
sort( $ops_type_keys );
pickup_checkout_assert( 'Отделение Почты России' === (string) ( $localized_leaflet['pickupPointTypes']['OPS']['label'] ?? '' ) && array( 'enabled', 'label' ) === $ops_type_keys && true === (bool) ( $localized_leaflet['pickupPointTypes']['PVZ']['enabled'] ?? false ) && true === (bool) ( $localized_leaflet['pickupPointTypes']['APS']['enabled'] ?? false ), 'JS localization must include only pickupPointTypes label/enabled flags.' );
pickup_checkout_assert( '630001-a' === (string) ( $localized_leaflet['initialContext']['selectedPoint']['point_code'] ?? '' ) && 55.01 === (float) ( $localized_leaflet['initialContext']['selectedPoint']['lat'] ?? 0 ), 'Initial context must expose the previously saved pickup point to the map.' );
pickup_checkout_assert( $pickup_group_id === (string) ( $localized_leaflet['activePickupFamily'] ?? '' ) && '630001-a' === (string) ( $localized_leaflet['selectedPickupPoint']['point_code'] ?? '' ) && '630001-a' === (string) ( $localized_leaflet['pickupSelections'][ $pickup_group_id ]['point_code'] ?? '' ), 'Localized checkout config must expose activePickupFamily, selectedPickupPoint, and pickupSelections for reload restore.' );
$session->save_checkout_pickup_point( array( 'carrier_key' => 'cdek', 'pickup_family' => 'cdek:pickup', 'point_code' => 'KEM41' ) );
$GLOBALS['wdc_pickup_checkout_localized'] = array();
( new PickupMapCheckout( $session, $environment, $map_settings, $point_type_settings ) )->enqueue_assets();
$localized_minimal = $GLOBALS['wdc_pickup_checkout_localized']['wdc-pickup-checkout']['wdcPickupCheckout'] ?? array();
pickup_checkout_assert( '630001-a' === (string) ( $localized_minimal['initialContext']['selectedPoint']['point_code'] ?? '' ), 'A minimal CDEK pickup point must not replace the active Russian Post selected point in initial context.' );
pickup_checkout_assert( ! isset( $localized_minimal['selectedPickupPoints']['cdek:pickup'] ) && '630001-a' === (string) ( $localized_minimal['selectedPickupPoints'][ $pickup_group_id ]['point_code'] ?? '' ), 'Localized selectedPickupPoints must expose only complete per-family selections.' );
pickup_checkout_assert( 'KEM41' === (string) ( $localized_minimal['pickupSelections']['cdek:pickup']['point_code'] ?? '' ) && '630001-a' === (string) ( $localized_minimal['pickupSelections'][ $pickup_group_id ]['point_code'] ?? '' ), 'Localized pickupSelections must expose raw per-family buckets even when a bucket is not renderable as a card.' );
pickup_checkout_assert( 'KEM41' === (string) ( $localized_minimal['pickupSelectionsRaw']['cdek:pickup']['point_code'] ?? '' ), 'Localized pickupSelectionsRaw must expose raw selected pickup buckets for reload restore.' );
$bucketed_selections = $session->pickup_selections();
pickup_checkout_assert( 'KEM41' === (string) ( $bucketed_selections['cdek:pickup']['point_code'] ?? '' ) && '630001-a' === (string) ( $bucketed_selections[ $pickup_group_id ]['point_code'] ?? '' ), 'Checkout session must keep Russian Post and CDEK pickup selections in separate pickup_family buckets.' );
$session->save_pickup_selection(
	array(
		'carrier_key' => 'cdek',
		'service_key' => 'cdek',
		'pickup_family' => 'cdek:pickup',
		'rate_id' => 'cdek:pickup:136',
		'point_code' => 'KEM7',
		'point_address' => 'CDEK full address',
		'address' => 'CDEK full address',
		'city_name' => 'Kemerovo',
		'region_name' => 'Kemerovo region',
		'snapshot' => array(
			'carrier_key' => 'cdek',
			'service_key' => 'cdek',
			'pickup_family' => 'cdek:pickup',
			'point_code' => 'KEM7',
			'address' => 'CDEK full address',
		),
	)
);
$GLOBALS['wdc_pickup_checkout_localized'] = array();
( new PickupMapCheckout( $session, $environment, $map_settings, $point_type_settings ) )->enqueue_assets();
$localized_buckets = $GLOBALS['wdc_pickup_checkout_localized']['wdc-pickup-checkout']['wdcPickupCheckout'] ?? array();
pickup_checkout_assert( '630001-a' === (string) ( $localized_buckets['pickupSelections'][ $pickup_group_id ]['point_code'] ?? '' ) && 'KEM7' === (string) ( $localized_buckets['pickupSelections']['cdek:pickup']['point_code'] ?? '' ), 'Localized checkout config must expose all complete pickup family buckets for reload restore.' );
pickup_checkout_assert( $pickup_group_id === (string) ( $localized_buckets['activePickupFamily'] ?? '' ) && $pickup_group_id === (string) ( $localized_buckets['activeShippingMethod'] ?? '' ) && '630001-a' === (string) ( $localized_buckets['selectedPickupPoint']['point_code'] ?? '' ), 'Reload config must derive selectedPickupPoint from the active Russian Post bucket.' );
$session->save_checkout_pickup_point( $saved['pickup_point'] );

$map_settings->replace( array_merge( $map_settings->defaults(), array( 'pickup_map_provider' => 'yandex', 'pickup_map_yandex_api_key' => 'test-yandex-key' ) ) );
$GLOBALS['wdc_pickup_checkout_enqueued_styles'] = array();
$GLOBALS['wdc_pickup_checkout_enqueued_scripts'] = array();
$GLOBALS['wdc_pickup_checkout_localized'] = array();
( new PickupMapCheckout( $session, $environment, $map_settings, $point_type_settings ) )->enqueue_assets();
$localized_map = $GLOBALS['wdc_pickup_checkout_localized']['wdc-pickup-checkout']['wdcPickupCheckout'] ?? array();
pickup_checkout_assert( isset( $GLOBALS['wdc_pickup_checkout_enqueued_scripts']['wdc-map-provider-yandex'] ) && ! isset( $GLOBALS['wdc_pickup_checkout_enqueued_scripts']['wdc-leaflet'], $GLOBALS['wdc_pickup_checkout_enqueued_styles']['wdc-leaflet'] ), 'Yandex provider must enqueue the Yandex adapter without Leaflet assets.' );
pickup_checkout_assert( 'yandex' === (string) ( $localized_map['mapProvider'] ?? '' ) && true === (bool) ( $localized_map['yandexApiKeyPresent'] ?? false ) && 'test-yandex-key' === (string) ( $localized_map['yandexApiKey'] ?? '' ), 'Yandex provider config must localize provider, key-present flag, and key when selected.' );

$map_settings->replace( array_merge( $map_settings->defaults(), array( 'pickup_map_provider' => 'yandex', 'pickup_map_yandex_api_key' => '' ) ) );
$GLOBALS['wdc_pickup_checkout_enqueued_scripts'] = array();
$GLOBALS['wdc_pickup_checkout_localized'] = array();
( new PickupMapCheckout( $session, $environment, $map_settings, $point_type_settings ) )->enqueue_assets();
$missing_key_map = $GLOBALS['wdc_pickup_checkout_localized']['wdc-pickup-checkout']['wdcPickupCheckout'] ?? array();
pickup_checkout_assert( 'yandex' === (string) ( $missing_key_map['mapProvider'] ?? '' ) && false === (bool) ( $missing_key_map['yandexApiKeyPresent'] ?? true ) && '' === (string) ( $missing_key_map['yandexApiKey'] ?? 'not-empty' ) && str_contains( (string) ( $missing_key_map['errors']['yandexApiKeyMissing'] ?? '' ), 'API key' ), 'Yandex without API key must localize a false flag, no key, and a readable error.' );

$pickup_method = (object) array( 'meta_data' => $rate );
$checkout_selector = new CheckoutDeliveryTypeSelector( $session, new WallsShop\WDC\Pickup\Storage\PickupPointRepository( $GLOBALS['wpdb'] ), new PickupPointRenderer(), new PickupPointCardRenderer() );
ob_start();
$checkout_selector->render( $pickup_method );
$selected_checkout_html = ob_get_clean() ?: '';
pickup_checkout_assert( str_contains( $selected_checkout_html, 'wdc-rp-pickup-checkout__button wdc-is-hidden' ) && str_contains( $selected_checkout_html, 'data-wdc-pickup-empty-open aria-hidden="true" hidden style="display:none;"' ) && str_contains( $selected_checkout_html, 'data-wdc-pickup-card aria-hidden="false"' ) && ! str_contains( $selected_checkout_html, 'wdc-pickup-point-card wdc-pickup-point-card--checkout wdc-is-hidden' ) && str_contains( $selected_checkout_html, 'Изменить пункт выдачи' ), 'Selected pickup checkout UI must robustly hide the primary choose button and show the card change button.' );
ob_start();
( new CheckoutRateRenderer( $session ) )->render( $pickup_method );
$rate_html = ob_get_clean() ?: '';
pickup_checkout_assert( ! str_contains( $rate_html, 'wdc-platform-pickup-selected' ) && str_contains( $rate_html, 'data-wdc-pickup-checkout' ) && str_contains( $rate_html, 'data-wdc-pickup-card aria-hidden="false"' ), 'Checkout rate renderer must output the shared pickup UI instead of the legacy selected pickup summary after rate comments.' );
$recovery_method = (object) array(
	'meta_data' => array_merge(
		$rate,
		array(
			'rate_meta' => array(
				'pickup_selection_rejected' => true,
				'pickup_selection_rejected_family' => 'pek:pickup',
				'pickup_selection_rejected_code' => 'pek_selected_terminal_quote_failed',
				'pickup_selection_rejected_message' => 'Не удалось рассчитать доставку в выбранный пункт ПЭК. Выберите другой пункт.',
			),
		)
	),
);
$session->clear_pickup_selection_for_family( 'russian_post_domestic:pickup', 'test_recovery_render' );
ob_start();
( new CheckoutRateRenderer( $session ) )->render( $recovery_method );
$recovery_rate_html = ob_get_clean() ?: '';
pickup_checkout_assert( str_contains( $recovery_rate_html, 'data-wdc-pickup-empty-open aria-hidden="false"' ) && str_contains( $recovery_rate_html, 'data-wdc-pickup-inline-notice role="status" aria-live="polite">Не удалось рассчитать доставку в выбранный пункт ПЭК. Выберите другой пункт.</div>' ), 'Rejected pickup recovery message must render inline inside the shipping method after the empty pickup selector.' );
ob_start();
( new CheckoutRateRenderer( $session ) )->render( $pickup_method );
$ordinary_rate_html = ob_get_clean() ?: '';
pickup_checkout_assert( str_contains( $ordinary_rate_html, 'data-wdc-pickup-inline-notice role="status" aria-live="polite" hidden></div>' ) && ! str_contains( $ordinary_rate_html, 'Не удалось рассчитать доставку в выбранный пункт ПЭК. Выберите другой пункт.' ), 'Pickup inline recovery notice must disappear on a normal rate render without transient rejection metadata.' );
$session->save_checkout_pickup_point( $saved['pickup_point'] );

$order = new WdcPickupCheckoutOrder();
$persister = new OrderShippingMetaPersister( $session, new \WallsShop\WDC\Calendar\Services\DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) );
$persister->persist( $order, array() );
pickup_checkout_assert( '10' === (string) $order->meta['_wdc_pickup_point_id'], 'order meta must save pickup point id.' );
pickup_checkout_assert( str_contains( (string) $order->meta['_wdc_pickup_point_snapshot'], '630001-a' ), 'order meta must save snapshot JSON.' );
$card_renderer = new PickupPointCardRenderer();
$card_html = $card_renderer->render(
	array(
		'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
		'postcode' => '650068',
		'city' => 'Кемерово',
		'address' => '650068 обл. Кемеровская Кемерово г. Ленина ул., д. 15',
		'point_work_time' => 'Пн-Пт 09:00–20:00',
	),
	true
);
pickup_checkout_assert( str_contains( $card_html, 'Отделение Почты России' ) && str_contains( $card_html, '650068 обл. Кемеровская Кемерово г. Ленина ул., д. 15' ) && ! str_contains( $card_html, '650068, г Кемерово' ) && 1 === substr_count( $card_html, '650068' ) && ! str_contains( $card_html, 'data-wdc-pickup-city' ) && str_contains( $card_html, 'Пн-Пт 09:00–20:00' ) && str_contains( $card_html, 'Изменить пункт выдачи' ), 'Pickup point card renderer must render one full address line without a separate postcode/city line.' );
pickup_checkout_assert( str_contains( $card_html, '#16a34a' ) && ! str_contains( $card_html, '#e02424' ) && str_contains( $card_html, 'width:100%' ) && str_contains( $card_html, 'max-width:none' ), 'Pickup point card renderer must use a green accent and full-width responsive inline styles.' );
$fallback_card = $card_renderer->render( array( 'postcode' => '650068', 'city' => 'Кемерово', 'address' => '' ) );
pickup_checkout_assert( str_contains( $fallback_card, '650068, г Кемерово' ), 'Pickup point card renderer must fall back to postcode plus city when full address is empty.' );
$card_without_time = $card_renderer->render( array( 'postcode' => '650068', 'address' => 'ул. Ленина, 15' ) );
pickup_checkout_assert( ! str_contains( $card_without_time, 'data-wdc-pickup-work-time-block' ) && ! str_contains( $card_without_time, 'Изменить пункт выдачи' ), 'Pickup point card renderer must omit empty work time and keep the change button checkout-only.' );
$point_object_card = $card_renderer->render( new PickupPoint( 'demo', 'demo-1', 'ул. Ленина, 15', 'Кемерово', '', '650068', null, null, 'unknown', 'Пн-Пт 09:00–20:00' ) );
pickup_checkout_assert( str_contains( $point_object_card, 'Пункт выдачи' ) && ! str_contains( $point_object_card, '650068, г Кемерово' ) && str_contains( $point_object_card, 'ул. Ленина, 15' ), 'Pickup point card renderer must accept PickupPoint objects without TypeError and avoid address duplication.' );
$order_display = new PickupPointOrderDisplay( $card_renderer, $map_settings );
unset( $GLOBALS['wdc_pickup_checkout_actions']['woocommerce_thankyou'], $GLOBALS['wdc_pickup_checkout_actions']['woocommerce_order_details_after_order_table'], $GLOBALS['wdc_pickup_checkout_actions']['woocommerce_email_after_order_table'] );
$order_display->register();
pickup_checkout_assert( empty( $GLOBALS['wdc_pickup_checkout_actions']['woocommerce_thankyou'] ?? array() ) && 1 === count( $GLOBALS['wdc_pickup_checkout_actions']['woocommerce_order_details_after_order_table'] ?? array() ), 'Order pickup card must register a single customer order-details hook to avoid thank-you duplicates.' );
ob_start();
foreach ( array( 'woocommerce_thankyou', 'woocommerce_order_details_after_order_table' ) as $hook ) {
	foreach ( $GLOBALS['wdc_pickup_checkout_actions'][ $hook ] ?? array() as $action ) {
		call_user_func( $action['callback'], $order );
	}
}
$thank_you_hook_html = ob_get_clean() ?: '';
pickup_checkout_assert( 1 === substr_count( $thank_you_hook_html, 'data-wdc-pickup-card' ), 'Thank You page hook flow must output the pickup card exactly once.' );
ob_start();
$order_display->render( $order );
$thank_you_html = ob_get_clean() ?: '';
pickup_checkout_assert( str_contains( $thank_you_html, 'wdc-pickup-point-card' ) && str_contains( $thank_you_html, 'data-wdc-pickup-address' ) && ! str_contains( $thank_you_html, 'data-wdc-pickup-city' ), 'Thank You page pickup block must use the shared single-address card renderer.' );
$map_settings->replace( array_merge( $map_settings->defaults(), array( 'pickup_email_card_enabled_emails' => array( 'customer_processing_order' ) ) ) );
ob_start();
$order_display->render_email( $order, false, false, (object) array( 'id' => 'customer_processing_order' ) );
$selected_email_html = ob_get_clean() ?: '';
ob_start();
$order_display->render_email( $order, false, false, (object) array( 'id' => 'new_order' ) );
$unselected_email_html = ob_get_clean() ?: '';
pickup_checkout_assert( str_contains( $selected_email_html, 'wdc-pickup-point-card' ) && '' === $unselected_email_html, 'Email pickup card must render only for selected WooCommerce email IDs.' );

$item = new WdcPickupCheckoutItem();
$persister->persist_shipping_item_meta( $item );
pickup_checkout_assert( ! array_key_exists( 'Пункт выдачи', $item->meta ) && ! array_key_exists( 'Индекс ПВЗ', $item->meta ) && ! array_key_exists( 'Тип ПВЗ', $item->meta ), 'shipping item meta must not expose pickup address, postcode, or type.' );

$state_controller->delete();
pickup_checkout_assert( array() === $session->checkout_pickup_point(), 'reset must clear checkout pickup point.' );
pickup_checkout_assert( null === $state_controller->state()['pickup_point'], 'checkout state GET must return null after reset.' );
ob_start();
$checkout_selector->render( $pickup_method );
$empty_checkout_html = ob_get_clean() ?: '';
pickup_checkout_assert( str_contains( $empty_checkout_html, 'wdc-rp-pickup-checkout__button" data-wdc-pickup-open data-wdc-pickup-empty-open aria-hidden="false"' ) && ! str_contains( $empty_checkout_html, 'wdc-rp-pickup-checkout__button wdc-is-hidden' ) && ! str_contains( $empty_checkout_html, 'data-wdc-pickup-empty-open aria-hidden="true" hidden style="display:none;"' ) && str_contains( $empty_checkout_html, 'wdc-pickup-point-card wdc-pickup-point-card--checkout wdc-is-hidden' ) && str_contains( $empty_checkout_html, 'data-wdc-pickup-card aria-hidden="true" hidden style="display:none;' ), 'Empty pickup checkout UI must show the primary choose button and robustly hide the card.' );

$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
pickup_checkout_assert( isset( $errors->errors['wdc_pickup_required'] ), 'validation must block checkout without pickup.' );
pickup_checkout_assert( 'Выберите пункт выдачи Почты России.' === $errors->errors['wdc_pickup_required'], 'validation must use Russian Post pickup error.' );

$session->save_pickup_selection( array( 'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY, 'rate_id' => $pickup_group_id, 'point_code' => '630001-a' ) );
WC()->session->set( 'chosen_shipping_methods', array( $courier_group_id ) );
pickup_checkout_assert( '630001-a' === $session->pickup_selection()['point_code'], 'selected pickup must survive a shipping method switch in session.' );
$session->save_rates(
	array(
		$pickup_group_id . ':tariff_a' => array(
			'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
			'rate_id' => $pickup_group_id . ':tariff_a',
			'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
			'delivery_type' => 'pickup',
			'requires_pickup_point' => true,
		),
	)
);
$session->save_pickup_selection( array( 'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY, 'rate_id' => $pickup_group_id . ':air', 'point_code' => '630001-a' ) );
WC()->session->set( 'chosen_shipping_methods', array( $pickup_group_id . ':ground' ) );
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
pickup_checkout_assert( array() === $errors->errors, 'validation must pass when pickup selection exists and the selected Russian Post pickup rate suffix changed.' );
pickup_checkout_assert( $pickup_group_id . ':ground' === (string) ( $session->pickup_selection()['rate_id'] ?? '' ), 'validation must refresh the saved pickup selection rate_id to the currently selected Russian Post pickup rate suffix.' );

$session->save_pickup_selection( array( 'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY, 'rate_id' => '', 'point_id' => 10 ) );
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform:' . $pickup_group_id . ':rail' ) );
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
pickup_checkout_assert( array() === $errors->errors, 'validation must pass when Russian Post pickup session selection has point_id and an empty rate_id.' );
pickup_checkout_assert( $pickup_group_id . ':rail' === (string) ( $session->pickup_selection()['rate_id'] ?? '' ), 'validation must normalize platform-prefixed selected rates before refreshing pickup selection rate_id.' );

$session->clear_pickup_selection();
$session->save_rates( array() );
$GLOBALS['wdc_pickup_checkout_actions'] = array();
$registered_validation = new CheckoutValidation( $session, null, $repo );
$registered_validation->register();
pickup_checkout_assert( isset( $GLOBALS['wdc_pickup_checkout_actions']['woocommerce_checkout_process'], $GLOBALS['wdc_pickup_checkout_actions']['woocommerce_after_checkout_validation'] ), 'CheckoutValidation::register must attach both the early checkout_process preloader and after_checkout_validation validator.' );
pickup_checkout_assert( 5 === (int) $GLOBALS['wdc_pickup_checkout_actions']['woocommerce_checkout_process'][0]['priority'], 'Checkout pickup preloader must run early in woocommerce_checkout_process.' );
pickup_checkout_assert( 20 === (int) $GLOBALS['wdc_pickup_checkout_actions']['woocommerce_after_checkout_validation'][0]['priority'], 'CheckoutValidation::validate must remain attached to woocommerce_after_checkout_validation priority 20.' );
$_POST = array(
	'shipping_method' => array( $pickup_group_id ),
	'wdc_pickup_point_id' => '19971',
	'wdc_pickup_point_code' => '650068-c46a3008bd',
);
$registered_validation->preload_from_post();
pickup_checkout_assert( '650068-c46a3008bd' === (string) ( $session->pickup_selection()['point_code'] ?? '' ), 'woocommerce_checkout_process preloader must restore pickup selection from checkout POST before after_checkout_validation.' );
pickup_checkout_assert( '650068-c46a3008bd' === (string) ( $session->checkout_pickup_point()['point_code'] ?? '' ), 'preloader must save checkout pickup point state from checkout POST.' );
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'Новосибирск', 'shipping_method' => array( $pickup_group_id ), 'wdc_pickup_point_id' => '770', 'wdc_pickup_point_code' => '987846-c3287ee67a' ), $errors );
pickup_checkout_assert( array() === $errors->errors, 'validation must pass after the preloader restored POST pickup selection with empty saved rates.' );

$session->clear_pickup_selection();
$_POST = array(
	'shipping_method' => array( $courier_group_id ),
	'wdc_pickup_point_id' => '19971',
	'wdc_pickup_point_code' => '650068-c46a3008bd',
);
$registered_validation->preload_from_post();
pickup_checkout_assert( array() === $session->pickup_selection(), 'preloader must skip non-pickup selected shipping methods even when hidden pickup fields are posted.' );

$session->clear_pickup_selection();
$_POST = array();
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'Новосибирск', 'shipping_method' => array( $pickup_group_id ), 'wdc_pickup_point_id' => '770', 'wdc_pickup_point_code' => '987846-c3287ee67a' ), $errors );
pickup_checkout_assert( array() === $errors->errors, 'validation must pass with bare Russian Post pickup POST method, hidden point fields, empty session selection, and empty saved rates.' );
pickup_checkout_assert( '987846-c3287ee67a' === (string) ( $session->pickup_selection()['point_code'] ?? '' ) && $pickup_group_id === (string) ( $session->pickup_selection()['rate_id'] ?? '' ), 'validation must create a synthetic pickup rate and save the posted hidden point as the pickup selection.' );
pickup_checkout_assert( '987846-c3287ee67a' === (string) ( $session->checkout_pickup_point()['point_code'] ?? '' ), 'synthetic-rate restore must save checkout pickup point state from hidden fields.' );

$session->clear_pickup_selection();
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'Новосибирск', 'shipping_method' => array( $pickup_group_id ), 'wdc_pickup_point_id' => '10', 'wdc_pickup_point_code' => '630001-a' ), $errors );
pickup_checkout_assert( array() === $errors->errors && 'Ленина, 1' === (string) ( $session->pickup_selection()['point_address'] ?? '' ), 'validation must restore posted pickup selection by id even when selected_rate resolves only to a synthetic pickup rate.' );

$session->clear_pickup_selection();
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'Новосибирск', 'shipping_method' => array( $pickup_group_id ), 'wdc_pickup_point_id' => '999999', 'wdc_pickup_point_code' => '630001-a' ), $errors );
pickup_checkout_assert( array() === $errors->errors && 10 === (int) ( $session->pickup_selection()['point_id'] ?? 0 ), 'validation must fall back to restoring the posted pickup selection by point_code when posted point_id is invalid.' );

$session->clear_pickup_selection();
$session->save_rates(
	array(
		'cdek:pickup:136' => array(
			'carrier_key' => 'cdek',
			'service_key' => 'cdek',
			'rate_id' => 'cdek:pickup:136',
			'pickup_family' => 'cdek:pickup',
			'delivery_type' => 'pickup',
			'requires_pickup_point' => true,
		),
	)
);
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate(
	array(
		'shipping_city' => 'Новосибирск',
		'shipping_method' => array( 'wdc_platform_delivery:cdek:pickup:136' ),
		'wdc_pickup_point_id' => '10',
		'wdc_pickup_point_code' => '630001-a',
		'wdc_pickup_carrier_key' => 'cdek',
		'wdc_pickup_service_key' => 'cdek',
		'wdc_pickup_family' => 'cdek:pickup',
		'wdc_pickup_point_type' => 'PVZ',
		'wdc_pickup_point_title' => 'Пункт выдачи СДЭК',
		'wdc_pickup_point_address' => 'CDEK collision address',
		'wdc_pickup_point_postcode' => '650004',
		'wdc_pickup_city_name' => 'Кемерово',
		'wdc_pickup_region_name' => 'Кемеровская область',
		'wdc_pickup_description' => 'CDEK hidden payload wins',
		'wdc_pickup_cdek_code' => '630001-a',
	),
	$errors
);
pickup_checkout_assert( array() === $errors->errors, 'CDEK restore from posted hidden fields must pass checkout validation.' );
pickup_checkout_assert( 'cdek' === (string) ( $session->pickup_selection()['carrier_key'] ?? '' ) && 'cdek:pickup' === (string) ( $session->pickup_selection()['pickup_family'] ?? '' ), 'CDEK restore must keep CDEK pickup family.' );
pickup_checkout_assert( 'CDEK collision address' === (string) ( $session->pickup_selection()['point_address'] ?? '' ) && 'Ленина, 1' !== (string) ( $session->pickup_selection()['point_address'] ?? '' ), 'CDEK point_code collision must not restore a Russian Post pickup row.' );

$session->clear_pickup_selection();
$session->save_pickup_selection(
	array(
		'carrier_key' => 'cdek',
		'service_key' => 'cdek',
		'pickup_family' => 'cdek:pickup',
		'rate_id' => 'cdek:pickup:old_tariff',
		'point_code' => 'OMS6',
		'snapshot' => array(
			'carrier_key' => 'cdek',
			'service_key' => 'cdek',
			'pickup_family' => 'cdek:pickup',
			'point_code' => 'OMS6',
			'raw' => array( 'address' => 'Omsk raw address' ),
		),
	)
);
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'РћРјСЃРє', 'shipping_method' => array( 'wdc_platform_delivery:cdek:pickup:136' ) ), $errors );
pickup_checkout_assert( array() === $errors->errors, 'CDEK validation must pass from active family bucket without hidden fields and without tariff/rate match.' );
WC()->session->set( 'chosen_shipping_methods', array( 'cdek:pickup:136' ) );
$GLOBALS['wdc_pickup_checkout_localized'] = array();
( new PickupMapCheckout( $session, $environment, $map_settings, $point_type_settings ) )->enqueue_assets();
$localized_cdek = $GLOBALS['wdc_pickup_checkout_localized']['wdc-pickup-checkout']['wdcPickupCheckout'] ?? array();
pickup_checkout_assert( 'OMS6' === (string) ( $localized_cdek['pickupSelections']['cdek:pickup']['point_code'] ?? '' ) && 'OMS6' === (string) ( $localized_cdek['selectedPickupPoint']['point_code'] ?? '' ) && 'Omsk raw address' === (string) ( $localized_cdek['selectedPickupPoint']['address'] ?? '' ), 'CDEK reload localized config must restore selectedPickupPoint from active bucket and snapshot raw address aliases.' );

$session->clear_pickup_selection();
$session->save_rates(
	array(
		'custom_carrier:pickup:base' => array(
			'carrier_key' => 'custom_carrier',
			'service_key' => 'custom_carrier',
			'rate_id' => 'custom_carrier:pickup:base',
			'pickup_family' => 'custom_carrier:pickup',
			'delivery_type' => 'pickup',
			'requires_pickup_point' => true,
		),
	)
);
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate(
	array(
		'shipping_city' => 'Новосибирск',
		'shipping_method' => array( 'wdc_platform_delivery:custom_carrier:pickup:base' ),
		'wdc_pickup_point_id' => '10',
		'wdc_pickup_point_code' => '630001-a',
		'wdc_pickup_carrier_key' => 'custom_carrier',
		'wdc_pickup_service_key' => 'custom_carrier',
		'wdc_pickup_family' => 'custom_carrier:pickup',
		'wdc_pickup_point_type' => 'LOCKER',
		'wdc_pickup_point_title' => 'Пункт выдачи',
		'wdc_pickup_point_address' => 'Custom collision address',
		'wdc_pickup_point_postcode' => '123456',
		'wdc_pickup_city_name' => 'Новосибирск',
		'wdc_pickup_region_name' => 'НСО',
		'wdc_pickup_description' => 'Custom hidden payload wins',
	),
	$errors
);
pickup_checkout_assert( array() === $errors->errors, 'Custom pickup restore from posted hidden fields must pass checkout validation.' );
pickup_checkout_assert( 'custom_carrier:pickup' === (string) ( $session->pickup_selection()['pickup_family'] ?? '' ), 'Custom pickup restore must keep custom pickup family.' );
pickup_checkout_assert( 'Custom collision address' === (string) ( $session->pickup_selection()['point_address'] ?? '' ) && 'Ленина, 1' !== (string) ( $session->pickup_selection()['point_address'] ?? '' ), 'Custom pickup point_code collision must not restore a Russian Post pickup row.' );

$session->clear_pickup_selection();
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'Новосибирск', 'shipping_method' => array( $pickup_group_id ), 'wdc_pickup_point_id' => '999999', 'wdc_pickup_point_code' => '987846-c3287ee67a' ), $errors );
pickup_checkout_assert( array() === $errors->errors && '987846-c3287ee67a' === (string) ( $session->pickup_selection()['point_code'] ?? '' ), 'validation must accept a minimal posted point selection when saved rates are empty and repository lookup misses.' );

$session->clear_pickup_selection();
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'Новосибирск', 'shipping_method' => array( $pickup_group_id ) ), $errors );
pickup_checkout_assert( 'Выберите пункт выдачи Почты России.' === (string) ( $errors->errors['wdc_pickup_required'] ?? '' ), 'validation must fail for a synthetic Russian Post pickup rate when neither session nor posted point exists.' );

$session->clear_pickup_selection();
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'Новосибирск', 'shipping_method' => array( $courier_group_id ) ), $errors );
pickup_checkout_assert( ! isset( $errors->errors['wdc_pickup_required'] ), 'validation must not require a pickup point when POST selected method is not Russian Post pickup.' );

$session->save_rates(
	array(
		$pickup_group_id . ':tariff_a' => array(
			'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
			'rate_id' => $pickup_group_id . ':tariff_a',
			'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
			'delivery_type' => 'pickup',
			'requires_pickup_point' => true,
		),
	)
);
$session->clear_pickup_selection();
WC()->session->set( 'chosen_shipping_methods', array( $courier_group_id ) );
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'Новосибирск', 'shipping_method' => array( $pickup_group_id . ':air' ), 'wdc_pickup_point_id' => '10', 'wdc_pickup_point_code' => '630001-a' ), $errors );
pickup_checkout_assert( array() === $errors->errors, 'validation must restore Russian Post pickup selection from submitted hidden point fields and posted shipping_method[0] when the session is empty.' );
pickup_checkout_assert( '630001-a' === (string) ( $session->pickup_selection()['point_code'] ?? '' ) && $pickup_group_id === substr( (string) ( $session->pickup_selection()['rate_id'] ?? '' ), 0, strlen( $pickup_group_id ) ), 'restored hidden pickup selection must be saved back to the checkout session.' );
pickup_checkout_assert( '630001-a' === (string) ( $session->checkout_pickup_point()['point_code'] ?? '' ), 'restored hidden pickup selection must save checkout pickup point state.' );

$session->clear_pickup_selection();
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'Новосибирск', 'shipping_method' => array( $pickup_group_id ), 'wdc_pickup_point_code' => '630001-a' ), $errors );
pickup_checkout_assert( array() === $errors->errors && 10 === (int) ( $session->pickup_selection()['point_id'] ?? 0 ), 'validation must restore Russian Post pickup selection by posted point_code when point_id is missing.' );

$session->clear_pickup_selection();
$session->save_pickup_selection(
	array(
		'carrier_key' => 'russian_post',
		'service_key' => 'russian_post',
		'pickup_family' => 'russian_post:pickup',
		'rate_id' => $pickup_group_id,
		'point_id' => 10,
		'point_code' => '630001-a',
		'point_address' => 'Р›РµРЅРёРЅР°, 1',
		'city_name' => 'РќРѕРІРѕСЃРёР±РёСЂСЃРє',
		'region_name' => 'РќРЎРћ',
	)
);
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'РќРѕРІРѕСЃРёР±РёСЂСЃРє', 'shipping_method' => array( $pickup_group_id ) ), $errors );
pickup_checkout_assert( array() === $errors->errors, 'Russian Post validation must pass from the active family bucket without posted hidden fields.' );
pickup_checkout_assert( RussianPostDomesticSettings::CARRIER_KEY === (string) ( $session->pickup_selection_for_family( $pickup_group_id )['carrier_key'] ?? '' ) && $pickup_group_id === (string) ( $session->pickup_selection_for_family( $pickup_group_id )['pickup_family'] ?? '' ), 'Russian Post aliases must normalize to russian_post_domestic in the canonical bucket.' );

$session->clear_pickup_selection();
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'Новосибирск', 'shipping_method' => array( $pickup_group_id ), 'wdc_pickup_point_id' => '999999', 'wdc_pickup_point_code' => '987846-c3287ee67a' ), $errors );
pickup_checkout_assert( array() === $errors->errors && '987846-c3287ee67a' === (string) ( $session->pickup_selection()['point_code'] ?? '' ), 'validation must accept a minimal posted point selection when repository lookup misses but point_code was posted.' );
pickup_checkout_assert( $session->pickup_selection_matches( RussianPostDomesticSettings::CARRIER_KEY, $pickup_group_id ), 'minimal restored pickup selection must match the selected Russian Post pickup family.' );
pickup_checkout_assert( '987846-c3287ee67a' === (string) ( $session->checkout_pickup_point()['point_code'] ?? '' ), 'minimal restored pickup selection must save checkout pickup point state.' );

$session->clear_pickup_selection();
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session, null, $repo ) )->validate( array( 'shipping_city' => 'Новосибирск', 'shipping_method' => array( $pickup_group_id ) ), $errors );
pickup_checkout_assert( 'Выберите пункт выдачи Почты России.' === (string) ( $errors->errors['wdc_pickup_required'] ?? '' ), 'validation must still fail for pickup family when neither session nor posted point exists.' );

$session->save_pickup_selection( array( 'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY, 'rate_id' => $pickup_group_id, 'point_code' => '630001-a' ) );
$session->save_pickup_selection( array( 'carrier_key' => 'cdek', 'service_key' => 'cdek', 'pickup_family' => 'cdek:pickup', 'rate_id' => 'cdek:pickup:136', 'point_code' => 'KEM7', 'point_address' => 'CDEK address' ) );
$reset_buckets = $session->pickup_selections();
pickup_checkout_assert( '630001-a' === (string) ( $reset_buckets[ $pickup_group_id ]['point_code'] ?? '' ) && 'KEM7' === (string) ( $reset_buckets['cdek:pickup']['point_code'] ?? '' ), 'Session must store Russian Post and CDEK pickup buckets before reset checks.' );
pickup_checkout_assert( false === $session->clear_pickup_selection_if_allowed( 'automatic_recalculation', $pickup_group_id . ':air' ), 'automatic clear must be blocked while an active Russian Post pickup family method has a saved point.' );
pickup_checkout_assert( '630001-a' === (string) ( $session->pickup_selections()[ $pickup_group_id ]['point_code'] ?? '' ) && 'KEM7' === (string) ( $session->pickup_selections()['cdek:pickup']['point_code'] ?? '' ), 'blocked automatic clear must keep all pickup family buckets.' );
pickup_checkout_assert( false === $session->clear_pickup_selection_if_allowed( 'method_family_changed', $courier_group_id ), 'method-family switching must not perform a global pickup bucket reset.' );
pickup_checkout_assert( '630001-a' === (string) ( $session->pickup_selections()[ $pickup_group_id ]['point_code'] ?? '' ) && 'KEM7' === (string) ( $session->pickup_selections()['cdek:pickup']['point_code'] ?? '' ), 'switching away from pickup must preserve other service buckets for restore.' );
$session->clear_pickup_selection_for_family( 'cdek:pickup', 'family_reset_smoke' );
pickup_checkout_assert( ! isset( $session->pickup_selections()['cdek:pickup'] ) && '630001-a' === (string) ( $session->pickup_selections()[ $pickup_group_id ]['point_code'] ?? '' ), 'CDEK family reset must not remove Russian Post bucket.' );
$session->save_pickup_selection( array( 'carrier_key' => 'cdek', 'service_key' => 'cdek', 'pickup_family' => 'cdek:pickup', 'rate_id' => 'cdek:pickup:136', 'point_code' => 'KEM7', 'point_address' => 'CDEK address' ) );
$session->clear_pickup_selection_for_family( $pickup_group_id, 'family_reset_smoke' );
pickup_checkout_assert( ! isset( $session->pickup_selections()[ $pickup_group_id ] ) && 'KEM7' === (string) ( $session->pickup_selections()['cdek:pickup']['point_code'] ?? '' ), 'Russian Post family reset must not remove CDEK bucket.' );
$session->clear_pickup_selection( 'global_reset_smoke' );
pickup_checkout_assert( array() === $session->pickup_selections(), 'Global pickup reset must remove all pickup family buckets.' );
$session->save_pickup_selection( array( 'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY, 'rate_id' => $pickup_group_id, 'point_code' => '630001-a' ) );
WC()->session->set( 'chosen_shipping_methods', array( $pickup_group_id ) );

$settings_source = file_get_contents( $root . '/src/Infrastructure/Settings/SettingsRepository.php' ) ?: '';
pickup_checkout_assert( str_contains( $settings_source, "'pickup_map_provider' => 'leaflet'" ), 'Default pickup map provider must be leaflet.' );
pickup_checkout_assert( str_contains( $settings_source, "'pickup_email_card_enabled_emails' => array()" ), 'Settings repository must define pickup_email_card_enabled_emails as an array default.' );
pickup_checkout_assert( str_contains( $settings_source, "'russian_post_domestic_point_type_ops_enabled' => true" ) && str_contains( $settings_source, "'russian_post_domestic_point_type_pvz_enabled' => true" ) && str_contains( $settings_source, "'russian_post_domestic_point_type_aps_enabled' => true" ), 'Default pickup point type settings must enable OPS/PVZ/APS.' );
$point_type_source = file_get_contents( $root . '/src/Pickup/RussianPost/RussianPostPickupPointTypeSettings.php' ) ?: '';
$old_short_key = implode( '_', array( 'marker', 'label' ) );
$old_long_key = implode( '_', array( 'card', 'label' ) );
pickup_checkout_assert( str_contains( $point_type_source, "'label' => 'Отделение Почты России'" ) && str_contains( $point_type_source, "russian_post_domestic_point_type_{\$key}_label" ) && str_contains( $point_type_source, "\$result['OPS']['enabled'] = true" ) && ! str_contains( $point_type_source, $old_short_key ) && ! str_contains( $point_type_source, $old_long_key ), 'Pickup type settings must provide only label and auto-enable OPS.' );
$type_settings_values = ( new RussianPostPickupPointTypeSettings( new SettingsRepository() ) )->sanitize_admin_values( array( 'russian_post_domestic_point_type_ops_enabled' => '1', 'russian_post_domestic_point_type_ops_label' => 'Новое название' ) );
pickup_checkout_assert( 'Новое название' === (string) $type_settings_values['russian_post_domestic_point_type_ops_label']['value'] && ! array_key_exists( "russian_post_domestic_point_type_ops_{$old_short_key}", $type_settings_values ) && ! array_key_exists( "russian_post_domestic_point_type_ops_{$old_long_key}", $type_settings_values ), 'Admin save must keep only enabled and label keys for each type.' );
$settings_admin = new SettingsAdminPage( new SettingsRepository() );
$email_options = $settings_admin->available_email_options();
pickup_checkout_assert( isset( $email_options['new_order'], $email_options['customer_processing_order'], $email_options['wc_order_status_manager_custom_status'] ), 'Email settings must be built dynamically from WC()->mailer()->get_emails(), including custom Order Status Manager IDs.' );
$email_settings = $settings_admin->sanitize_settings( array( 'pickup_email_card_enabled_emails' => array( 'new_order', 'wc_order_status_manager_custom_status', 'missing_email' ) ) );
pickup_checkout_assert( array( 'new_order', 'wc_order_status_manager_custom_status' ) === $email_settings['pickup_email_card_enabled_emails'], 'Pickup email setting must keep only existing WooCommerce email IDs.' );
$empty_key_settings = $settings_admin->sanitize_settings( array( 'pickup_map_provider' => 'yandex', 'pickup_map_yandex_api_key' => '' ) );
pickup_checkout_assert( 'yandex' === $empty_key_settings['pickup_map_provider'] && ! array_key_exists( 'pickup_map_yandex_api_key', $empty_key_settings ), 'Empty Yandex key input must not overwrite a saved key.' );
$new_key_settings = $settings_admin->sanitize_settings( array( 'pickup_map_provider' => 'yandex', 'pickup_map_yandex_api_key' => 'new-key' ) );
pickup_checkout_assert( 'new-key' === (string) $new_key_settings['pickup_map_yandex_api_key'], 'Non-empty Yandex key input must be saved.' );
$clear_key_settings = $settings_admin->sanitize_settings( array( 'pickup_map_provider' => 'yandex', 'pickup_map_yandex_api_key' => '', 'clear_pickup_map_yandex_api_key' => '1' ) );
pickup_checkout_assert( '' === (string) $clear_key_settings['pickup_map_yandex_api_key'], 'Clear checkbox must clear the saved Yandex key.' );
$validation_source = file_get_contents( $root . '/src/Checkout/WooCommerce/CheckoutValidation.php' ) ?: '';
foreach ( array( 'Р’', 'Рµ', 'С‹', 'СЊ' ) as $mojibake ) {
	pickup_checkout_assert( ! str_contains( $validation_source, $mojibake ), 'CheckoutValidation.php must not contain mojibake marker ' . $mojibake . '.' );
}
pickup_checkout_assert( str_contains( $validation_source, 'update_pickup_selection_rate_id( $selected_rate_id )' ) && str_contains( $validation_source, "['_selected_rate_id']" ) && str_contains( $validation_source, '$data[\'shipping_method\']' ), 'Checkout validation must pass same-family pickup selections, read posted shipping_method, and refresh the stored rate_id to the selected rate suffix.' );
pickup_checkout_assert( str_contains( $validation_source, 'add_action( \'woocommerce_checkout_process\'' ) && str_contains( $validation_source, 'function preload_from_post()' ) && str_contains( $validation_source, 'posted_checkout_data()' ), 'Checkout validation must register an early checkout_process preloader that reads checkout POST.' );
pickup_checkout_assert( str_contains( $validation_source, 'find_row_by_point_code' ) && str_contains( $validation_source, 'checkout_pickup_point_from_selection' ) && str_contains( $validation_source, '$is_russian_post_family' ) && str_contains( $validation_source, 'array() === $selection && $is_russian_post_family' ) && ! str_contains( $validation_source, 'synthetic_russian_post_pickup_rate' ) && ! str_contains( $validation_source, 'synthetic_cdek_pickup_rate' ), 'Checkout validation must restore Russian Post posted pickup points by id/code, gate repository lookup by Russian Post family, and avoid dead synthetic carrier helpers.' );
$pickup_error_sources = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) ) as $source_file ) {
	if ( ! $source_file->isFile() || 'php' !== $source_file->getExtension() ) {
		continue;
	}
	$source = file_get_contents( $source_file->getPathname() ) ?: '';
	if ( str_contains( $source, 'Выберите пункт выдачи Почты России.' ) ) {
		$pickup_error_sources[] = str_replace( '\\', '/', substr( $source_file->getPathname(), strlen( $root ) + 1 ) );
	}
}
pickup_checkout_assert( array( 'src/Checkout/WooCommerce/CheckoutValidation.php' ) === $pickup_error_sources, 'Russian Post pickup required checkout error must only be emitted by CheckoutValidation.' );
$session_source = file_get_contents( $root . '/src/Checkout/WooCommerce/CheckoutSessionManager.php' ) ?: '';
pickup_checkout_assert( str_contains( $session_source, 'function shipping_method_family' ) && str_contains( $session_source, 'function is_same_pickup_family' ) && str_contains( $session_source, 'wdc_platform:' ), 'Checkout session manager must normalize platform-prefixed rate ids and compare Russian Post pickup method family.' );
pickup_checkout_assert( str_contains( $session_source, "PICKUP_SELECTIONS_KEY = 'wdc_platform_pickup_selections'" ) && str_contains( $session_source, 'function pickup_selections()' ) && str_contains( $session_source, 'function pickup_selection_for_family' ) && str_contains( $session_source, 'function checkout_pickup_point_for_family' ), 'Checkout session manager must store selected pickup points in pickup_family buckets.' );
pickup_checkout_assert( str_contains( $session_source, 'str_ends_with( $rate_family, \':pickup\' ) && \'\' !== $selection_family' ) && str_contains( $session_source, 'return $selection_family === $rate_family;' ), 'Checkout session pickup validation must pass active family buckets before carrier-key fallback comparisons.' );
pickup_checkout_assert( str_contains( $session_source, 'GLOBAL reset: clears all pickup families' ) && str_contains( $session_source, 'function clear_pickup_selection( string $reason' ) && str_contains( $session_source, 'function clear_pickup_selection_if_allowed' ) && str_contains( $session_source, 'function is_global_pickup_reset_reason' ), 'Checkout session manager must mark global pickup reset explicitly and gate broad clears by reset reason.' );
$address_runtime_source = file_get_contents( $root . '/src/Checkout/Address/CheckoutAddressRuntime.php' ) ?: '';
pickup_checkout_assert( str_contains( $address_runtime_source, 'should_preserve_pickup_selection_for_rate_switch' ) && str_contains( $address_runtime_source, 'update_pickup_selection_rate_id' ) && str_contains( $address_runtime_source, 'clear_pickup_selection_if_allowed' ), 'Address runtime must preserve pickup selection during same-family rate recalculation and only clear it for real destination/carrier changes.' );
pickup_checkout_assert( str_contains( $address_runtime_source, "WC()->session->get( 'chosen_shipping_methods'" ) && str_contains( $address_runtime_source, 'posted_destination_conflicts_with_pickup' ), 'Address runtime must fall back to WooCommerce chosen_shipping_methods and only treat an explicit conflicting destination as a real pickup reset.' );
$delivery_type_selector_source = file_get_contents( $root . '/src/Checkout/WooCommerce/CheckoutDeliveryTypeSelector.php' ) ?: '';
pickup_checkout_assert( str_contains( $delivery_type_selector_source, 'name="wdc_pickup_point_id"' ) && str_contains( $delivery_type_selector_source, 'name="wdc_pickup_point_code"' ), 'Checkout pickup block hidden inputs must submit selected point id and code names.' );
pickup_checkout_assert( str_contains( $delivery_type_selector_source, 'name="wdc_pickup_location_id"' ) && str_contains( $delivery_type_selector_source, 'name="wdc_pickup_fias_id"' ) && str_contains( $delivery_type_selector_source, 'name="wdc_pickup_destination_fingerprint"' ) && str_contains( $delivery_type_selector_source, 'checkout_pickup_point_for_family( $family )' ), 'Checkout pickup block must submit location identity and render the selected point from the active pickup_family bucket.' );
pickup_checkout_assert( str_contains( $delivery_type_selector_source, 'PickupPointCardRenderer' ) && str_contains( $delivery_type_selector_source, '$this->card_renderer->render' ), 'Checkout pickup selected-point block must use the shared pickup card renderer.' );
$rate_renderer_source = file_get_contents( $root . '/src/Checkout/WooCommerce/CheckoutRateRenderer.php' ) ?: '';
pickup_checkout_assert( ! str_contains( $rate_renderer_source, 'wdc-platform-pickup-selected' ) && ! str_contains( $rate_renderer_source, 'render_selected_pickup' ) && str_contains( $rate_renderer_source, 'render_pickup_selector' ) && str_contains( $rate_renderer_source, 'data-wdc-pickup-checkout' ), 'Checkout rate renderer must use the shared pickup selector instead of the legacy selected pickup summary/address block.' );
pickup_checkout_assert( str_contains( $rate_renderer_source, 'woocommerce_cart_shipping_method_full_label' ) && str_contains( $rate_renderer_source, 'wdc-platform-crossed-price--inline' ) && ! str_contains( $rate_renderer_source, "echo '<span class=\"wdc-platform-crossed-price\">" ), 'Checkout rate renderer must append crossed prices to the WooCommerce rate label instead of rendering them as a separate block.' );
pickup_checkout_assert( str_contains( $rate_renderer_source, '$line = $title;' ) && str_contains( $rate_renderer_source, "\$line .= ' - ' . \$delivery_days_label;" ) && str_contains( $rate_renderer_source, "\$line .= ': ';" ) && str_contains( $rate_renderer_source, 'wdc-domestic-tariff-selector__line-text' ) && ! str_contains( $rate_renderer_source, 'wdc-domestic-tariff-selector__separator' ), 'Domestic tariff selector must build one PHP label line with stable dash and colon spacing, without separator spans.' );
$rate_renderer = new CheckoutRateRenderer();
$tariff_selector = new ReflectionMethod( CheckoutRateRenderer::class, 'render_tariff_selector' );
$tariff_selector->setAccessible( true );
ob_start();
$tariff_selector->invoke( $rate_renderer, array(
	'service_key' => 'domestic',
	'selected_tariff_object' => 'online',
	'tariff_variants' => array(
		array(
			'object_code' => 'online',
			'title' => 'Посылка онлайн',
			'delivery_days_label' => '4 дня',
			'price_rub' => 150,
			'crossed_price' => array( 'amount_kopecks' => 51801 ),
		),
		array(
			'object_code' => 'empty_days',
			'title' => 'Без срока',
			'price_rub' => 100,
		),
		array(
			'object_code' => 'empty_price',
			'title' => 'Без цены',
			'delivery_days_label' => '2 дня',
		),
	),
) );
$tariff_html = (string) ob_get_clean();
$tariff_text = html_entity_decode( trim( preg_replace( '/\s+/', ' ', strip_tags( $tariff_html ) ) ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
pickup_checkout_assert( str_contains( $tariff_html, 'wdc-domestic-tariff-selector__line-text' ) && str_contains( $tariff_html, 'wdc-domestic-tariff-selector__price' ) && str_contains( $tariff_text, 'Посылка онлайн - 4 дня: 150 руб.' ) && str_contains( $tariff_text, '518.01 руб.' ), 'Domestic tariff selector HTML/text must render title, dash, days, colon, price, and old price with exact spacing.' );
pickup_checkout_assert( ! str_contains( $tariff_text, 'Посылка онлайн-4' ) && ! str_contains( $tariff_text, 'Посылка онлайн- 4' ) && ! str_contains( $tariff_text, '4 дня:150' ), 'Domestic tariff selector text must not collapse spaces around the dash or colon.' );
pickup_checkout_assert( str_contains( $tariff_html, '<span class="wdc-domestic-tariff-selector__line-text">Без срока: </span><span class="wdc-domestic-tariff-selector__price">100 руб.</span>' ) && ! str_contains( $tariff_html, 'Без срока - ' ), 'Domestic tariff selector must not render the dash separator when delivery days are empty.' );
pickup_checkout_assert( str_contains( $tariff_html, '<span class="wdc-domestic-tariff-selector__line-text">Без цены - 2 дня</span></span></label>' ) && ! str_contains( $tariff_html, 'Без цены - 2 дня: ' ), 'Domestic tariff selector must not render the colon separator when price is empty.' );
$order_display_source = file_get_contents( $root . '/src/Checkout/WooCommerce/PickupPointOrderDisplay.php' ) ?: '';
pickup_checkout_assert( str_contains( $order_display_source, 'PickupPointCardRenderer' ) && str_contains( $order_display_source, '$this->card_renderer->render' ) && str_contains( $order_display_source, 'pickup_email_card_enabled_emails' ), 'Thank You page and email pickup blocks must use the shared pickup card renderer and email setting.' );
$card_renderer_source = file_get_contents( $root . '/src/Pickup/Presentation/PickupPointCardRenderer.php' ) ?: '';
$presentation_resolver_source = file_get_contents( $root . '/src/Pickup/Presentation/PickupPointPresentationResolver.php' ) ?: '';
pickup_checkout_assert( str_contains( $card_renderer_source, 'final class PickupPointCardRenderer' ) && str_contains( $card_renderer_source, 'point_work_time' ) && str_contains( $card_renderer_source, 'snapshot' ) && str_contains( $card_renderer_source, 'PickupPointPresentationResolver' ) && str_contains( $presentation_resolver_source, 'Отделение Почты России' ), 'Shared pickup point card renderer must normalize checkout/order snapshots and delegate built-in titles to the presentation resolver.' );
$map_checkout_source = file_get_contents( $root . '/src/Checkout/WooCommerce/PickupMapCheckout.php' ) ?: '';
pickup_checkout_assert( str_contains( $map_checkout_source, 'map_provider()' ) && str_contains( $map_checkout_source, 'assets/vendor/leaflet/leaflet.css' ) && str_contains( $map_checkout_source, 'assets/vendor/leaflet/leaflet.js' ), 'Leaflet provider must enqueue Leaflet assets from assets/vendor/leaflet.' );
pickup_checkout_assert( str_contains( $map_checkout_source, 'providers/wdc-map-provider-leaflet.js' ), 'Leaflet provider script must be enqueued.' );
pickup_checkout_assert( str_contains( $map_checkout_source, "if ( 'leaflet' === \$provider )" ) && str_contains( $map_checkout_source, 'providers/wdc-map-provider-yandex.js' ), 'Yandex provider must enqueue from the non-Leaflet branch.' );
pickup_checkout_assert( str_contains( $map_checkout_source, "'yandex' === \$provider && \$this->has_yandex_api_key()" ) && str_contains( $map_checkout_source, "'yandexApiKeyPresent'" ), 'Yandex key must be localized only when Yandex is selected and the key exists.' );
pickup_checkout_assert( str_contains( $map_checkout_source, "'pickupPointTypes'" ) && str_contains( $map_checkout_source, 'pickup_point_types()' ) && str_contains( $map_checkout_source, '$pickup_selections = $this->selected_points_context( false )' ) && str_contains( $map_checkout_source, "'pickupSelections' => \$pickup_selections" ) && str_contains( $map_checkout_source, "'pickupSelectionsRaw' => \$pickup_selections" ) && str_contains( $map_checkout_source, '$selected_pickup_points = $this->selected_points_context( true )' ) && str_contains( $map_checkout_source, "'selectedPickupPoints' => \$selected_pickup_points" ) && str_contains( $map_checkout_source, '$selected_pickup_point = $this->selected_point_context( $active_pickup_family )' ) && str_contains( $map_checkout_source, "'selectedPickupPoint' => \$selected_pickup_point" ) && str_contains( $map_checkout_source, "'shippingMethodId' => \$active_shipping_method" ), 'Pickup map checkout must localize pickupPointTypes, active selectedPoint, raw pickupSelections, selectedPickupPoints buckets, and current shipping method context.' );
pickup_checkout_assert( str_contains( $map_checkout_source, 'Для Яндекс.Карт не задан API key' ), 'Frontend config must include a clear missing Yandex API key error.' );
pickup_checkout_assert( file_exists( $root . '/assets/vendor/leaflet/leaflet.css' ) && file_exists( $root . '/assets/vendor/leaflet/leaflet.js' ), 'Leaflet assets must exist under assets/vendor/leaflet.' );
$checkout_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-checkout.js' ) ?: '';
$city_selector_js = file_get_contents( $root . '/assets/frontend/checkout-city-selector.js' ) ?: '';
$checkout_rates_css = file_get_contents( $root . '/assets/frontend/checkout-rates.css' ) ?: '';
$domestic_tariff_css = file_get_contents( $root . '/assets/frontend/domestic-tariff-selector.css' ) ?: '';
$pickup_map_css = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-map.css' ) ?: '';
$invoice_plugin_path = $root . '/walls-invoice-payment.php';
$invoice_plugin = file_exists( $invoice_plugin_path ) ? ( file_get_contents( $invoice_plugin_path ) ?: '' ) : '';
pickup_checkout_assert( ! str_contains( $checkout_js, "input[name^=\"shipping_method\"]')) {\n\t\t\tresetSelection();" ), 'JS must not reset pickup on shipping method change.' );
pickup_checkout_assert( str_contains( $checkout_rates_css, '.wdc-platform-crossed-price--inline' ) && str_contains( $checkout_rates_css, 'white-space: nowrap' ) && str_contains( $checkout_rates_css, ':has(.wdc-platform-rate-meta)' ), 'WDC checkout CSS must keep crossed prices inline and reserve horizontal space for WDC rate labels.' );
pickup_checkout_assert( str_contains( $domestic_tariff_css, 'position: static !important' ) && str_contains( $domestic_tariff_css, 'opacity: 1 !important' ) && str_contains( $domestic_tariff_css, 'wdc-domestic-tariff-selector__line' ) && str_contains( $domestic_tariff_css, 'wdc-domestic-tariff-selector__line-text' ) && ! str_contains( $domestic_tariff_css, 'wdc-domestic-tariff-selector__separator' ) && ! str_contains( $domestic_tariff_css, 'wdc-domestic-tariff-selector__days::before' ) && ! str_contains( $domestic_tariff_css, 'wdc-domestic-tariff-selector__price::before' ), 'Nested WDC domestic rate radios must have visible controls and must not depend on separator spans or CSS pseudo separators.' );
pickup_checkout_assert( str_contains( $domestic_tariff_css, '.wdc-domestic-tariff-selector.is-inactive' ) && str_contains( $domestic_tariff_css, 'cursor: not-allowed' ) && str_contains( $domestic_tariff_css, 'background: #f3f4f6' ), 'Inactive grouped tariff selectors must have a disabled visual state.' );
pickup_checkout_assert( ! str_contains( $domestic_tariff_css, 'content: " - "' ) && ! str_contains( $domestic_tariff_css, 'content: "- "' ) && ! str_contains( $domestic_tariff_css, 'content: ": "' ) && ! str_contains( $domestic_tariff_css, 'content: " : "' ), 'Nested WDC rate CSS must not generate dash or colon separators through pseudo content.' );
pickup_checkout_assert( ! str_contains( $domestic_tariff_css, 'justify-content: space-between' ) && ! str_contains( $domestic_tariff_css, 'margin-left: auto' ) && ! str_contains( $domestic_tariff_css, 'font-size: 0' ) && ! str_contains( $domestic_tariff_css, 'gap: 4px 0' ), 'Nested WDC rate CSS must keep the label line in natural text flow without right alignment or space-collapsing tricks.' );
pickup_checkout_assert( str_contains( $pickup_map_css, '.wdc-rp-pickup-checkout__button.wdc-is-hidden' ) && str_contains( $pickup_map_css, 'display: none !important' ) && str_contains( $pickup_map_css, '.wdc-pickup-modal__close:hover' ) && str_contains( $pickup_map_css, 'padding-left: 34px' ), 'Pickup map CSS must protect hidden button/card states and style modal close/search controls.' );
if ( file_exists( $invoice_plugin_path ) ) {
	pickup_checkout_assert( str_contains( $invoice_plugin, 'Version: 1.1.53' ) && str_contains( $invoice_plugin, 'WALLS_INVOICE_PAYMENT_VERSION\', \'1.1.53' ) && str_contains( $invoice_plugin, 'ul#shipping_method > li > input[type="radio"]' ) && str_contains( $invoice_plugin, 'button:not([hidden])' ) && ! str_contains( $invoice_plugin, 'russian_post_domestic_pickup' ) && ! str_contains( $invoice_plugin, 'wdc-platform' ) && ! str_contains( $invoice_plugin, 'wdc-rp' ) && ! str_contains( $invoice_plugin, 'wdc-pickup' ), 'Invoice plugin must keep third-party checkout styling while avoiding WDC-specific selectors and nested WDC controls.' );
}
pickup_checkout_assert( str_contains( $checkout_js, 'var isPlacingOrder = false' ) && str_contains( $checkout_js, 'checkout_place_order' ) && str_contains( $checkout_js, 'checkout_error' ), 'JS must guard pickup reset during place_order lifecycle and clear the guard after checkout errors.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function resetSelection(reason)' ) && str_contains( $checkout_js, 'function resetPickupSelectionOnServer(reason, family, options)' ) && str_contains( $checkout_js, 'function clearPickupSelectionUi(reason)' ) && substr_count( $checkout_js, 'if (isPickupResetGuarded())' ) >= 3, 'JS reset functions must all no-op while place_order or guard grace is active and support family-scoped reset.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function shippingMethodFamily(value)' ) && str_contains( $checkout_js, 'function isSamePickupMethodFamily(oldMethod, newMethod)' ) && str_contains( $checkout_js, 'syncSelectedPickupRate(nextMethod)' ), 'JS must preserve Russian Post pickup selection when switching rate suffixes inside the same pickup method family.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var previousMethod = activeMethod;' ) && str_contains( $checkout_js, 'var nextMethod = currentShippingMethod()' ) && str_contains( $checkout_js, 'restoreSelectedPickupUi();' ) && ! str_contains( $checkout_js, "resetSelection('method_family_changed')" ), 'JS must restore the active family bucket on shipping method changes without clearing another carrier selection.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var selected = selectedPickupPointForFamily(shippingMethodFamily(method));' ) && str_contains( $checkout_js, "Object.assign({}, selected, { selection_intent: 'technical' })" ) && str_contains( $checkout_js, 'window.WDCPickupApi.save(pointId, method, payload).catch(function () {});' ) && str_contains( $checkout_js, 'pickupMatchesFamily(selected, method)' ) && ! str_contains( $checkout_js, "catch(function () { clearPickupSelectionUi" ), 'Same-family rate sync must save only the selected point from the matching pickup_family bucket and must not clear selected pickup UI if REST save fails.' );
pickup_checkout_assert( 3 === substr_count( $checkout_js, 'WDCPickupApi.save(' ) && substr_count( $checkout_js, "selection_intent = 'explicit'" ) >= 2 && str_contains( $checkout_js, "selection_intent: 'technical'" ), 'Every production pickup save call must carry an explicit or technical selection intent.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function restoreSelectedPickupUi()' ) && str_contains( $checkout_js, 'restoreSelectedPickupUi();' ) && str_contains( $checkout_js, 'applySelection(container, selected)' ), 'updated_checkout must restore selected pickup UI and hidden fields from window state.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function containerMethod(container)' ) && str_contains( $checkout_js, 'function pickupMatchesFamily(point, method)' ) && str_contains( $checkout_js, 'pickupFamily(point)' ) && str_contains( $checkout_js, 'pickupFamilies = Array.isArray' ), 'Checkout pickup UI must keep active state by normalized pickup families from config/payload.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function isValidSelectedPointForCard(point, family)' ) && str_contains( $checkout_js, 'selectedPointAddressValue(point)' ) && str_contains( $checkout_js, 'showEmptySelection(container)' ), 'Checkout UI must render selected cards only for full selected pickup payloads with code, family and address.' );
pickup_checkout_assert( str_contains( $checkout_js, 'clearContainerSelection(container)' ) && str_contains( $checkout_js, 'isContainerSelectionComplete(container, family)' ) && str_contains( $checkout_js, "button.disabled = !visible" ), 'Switching shipping method must hide inactive pickup cards and re-enable the active empty chooser only when the active selection is complete.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function syncPickupPostFields(container, active)' ) && str_contains( $checkout_js, "input[name], select[name], textarea[name]" ) && str_contains( $checkout_js, 'field.disabled = !active' ) && str_contains( $checkout_js, 'syncPickupPostFields(container, visible)' ), 'Only named fields in the active pickup container must remain enabled for checkout POST.' );
pickup_checkout_assert( ! str_contains( $checkout_js, "if (!visible) {\r\n\t\t\tclearContainerSelection(container);" ), 'Hiding an inactive pickup container must preserve its family-specific selected values.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function rememberPreferredShippingMethod(method, point)' ) && str_contains( $checkout_js, 'requiresRateRefreshAfterPickupSave(point)' ) && str_contains( $checkout_js, 'pickupFamily(point) !== shippingMethodFamily(method)' ) && str_contains( $checkout_js, "family === 'dpd:pickup' || family === 'yandex_delivery:pickup'" ) && str_contains( $checkout_js, 'function restorePreferredShippingMethod()' ) && str_contains( $checkout_js, 'preferredShippingMethodRecoveryUpdateSent' ) && str_contains( $checkout_js, 'if (!input || input.disabled)' ) && str_contains( $checkout_js, 'input.checked = true' ), 'Pickup repricing must be data-driven by rate-refresh metadata while preserving DPD/Yandex fallback and restoring the selected shipping method only while its enabled rate exists.' );
$checkout_js_lf = str_replace( "\r\n", "\n", $checkout_js );
pickup_checkout_assert( str_contains( $checkout_js_lf, 'var runRecoveryUpdate = restorePreferredShippingMethod();' ) && str_contains( $checkout_js_lf, "if (runRecoveryUpdate) {\n\t\t\t\ttriggerCheckoutUpdate();" ), 'updated_checkout must perform at most one controlled recovery update after restoring yandex_pickup.' );
pickup_checkout_assert( str_contains( $checkout_js, "carrier === 'russian_post_domestic'" ) && str_contains( $checkout_js, "carrier = 'russian_post'" ), 'Russian Post pickup map requests must keep using the Russian Post REST carrier after switching from another pickup family.' );
pickup_checkout_assert( ! str_contains( $checkout_js, 'pickup save payload' ) && ! str_contains( $checkout_js, 'pickup save response' ) && ! str_contains( $checkout_js, 'function pickupDebugSummary(point)' ), 'Temporary checkout pickup save diagnostics must be removed.' );
pickup_checkout_assert( str_contains( $checkout_js, 'snapshot: snapshot' ), 'Checkout pickup save payload must preserve the normalized point snapshot.' );
pickup_checkout_assert( str_contains( $checkout_js, "toggleBlock(container, '[data-wdc-pickup-code-block]', false)" ) && str_contains( $checkout_js, "toggleBlock(container, '[data-wdc-pickup-postcode-block]', false)" ), 'Checkout selected pickup card must hide CDEK point code and postcode rows under rates.' );
pickup_checkout_assert( str_contains( $checkout_js, 'description: point.description' ) && str_contains( $checkout_js, 'storage_notice: point.storage_notice' ) && str_contains( $checkout_js, 'cdek_code: point.cdek_code' ), 'Reload restore payload must preserve full CDEK pickup details in frontend state.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function sameLocationContext(oldContext, newContext)' ) && str_contains( $checkout_js, 'oldLocationId && newLocationId' ) && str_contains( $checkout_js, 'oldFias && newFias' ) && str_contains( $checkout_js, 'sameLocation || newFingerprint === lastDestinationFingerprint' ) && str_contains( $checkout_js, 'var previousHasIdentity = !!destinationFingerprint(previousContext)' ) && str_contains( $checkout_js, '!selectedPickupPointId() && sameLocationContext(fieldContext, context)' ), 'wdc:location-selected for the same old location id or FIAS must not reset pickup selection, while new DOM fields alone must not mask manual city changes when a pickup point is selected.' );
pickup_checkout_assert( str_contains( $checkout_js, 'placeOrderResetGuardUntil' ) && str_contains( $checkout_js, 'function releasePlaceOrderGuardSoon()' ) && str_contains( $checkout_js, 'Date.now() < placeOrderResetGuardUntil' ), 'Place order guard must keep reset calls blocked during a short grace period after checkout_error or updated_checkout.' );
pickup_checkout_assert( str_contains( $checkout_js, 'if (isPlacingOrder)' ) && str_contains( $checkout_js, 'releasePlaceOrderGuardSoon();' ) && str_contains( $checkout_js, "window.jQuery(document.body).on('checkout_error', releasePlaceOrderGuardSoon)" ), 'updated_checkout must not refresh checkout context while place_order is guarded; checkout_error releases the guard after grace period.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var lastDestinationFingerprint' ) && str_contains( $checkout_js, 'newFingerprint === lastDestinationFingerprint' ) && str_contains( $checkout_js, 'rememberDestinationFingerprint();' ) && str_contains( $checkout_js, 'checkoutConfig.currentContext || checkoutConfig.initialContext || {}' ), 'JS must only reset pickup after a real destination fingerprint change and initialize current context from fresh checkout config.' );
pickup_checkout_assert( str_contains( $checkout_js, 'billing_postcode' ) && str_contains( $checkout_js, 'shipping_postcode' ), 'JS must reset pickup on city/country/postcode changes.' );
pickup_checkout_assert( str_contains( $checkout_js, 'contextFromFields' ) && str_contains( $checkout_js, 'shipping_city' ) && str_contains( $checkout_js, 'shipping_postcode' ), 'JS must form initial map query from checkout city/postcode.' );
pickup_checkout_assert( str_contains( $checkout_js, 'WDCPickupMap.create' ) && str_contains( $checkout_js, 'initialContext()' ), 'Next modal open must recompute initial context instead of reusing a cached value.' );
pickup_checkout_assert( str_contains( $checkout_js, "confirmButton.addEventListener('wdc:point-selected'" ) && str_contains( $checkout_js, 'savePoint(event.detail || map.selected())' ) && str_contains( $checkout_js, 'function savePoint(point)' ), 'Popup/list selection event must save the pickup point immediately without a second footer click.' );
pickup_checkout_assert( str_contains( $checkout_js, 'selectedPointAddress(point)' ) && str_contains( $checkout_js, '[data-wdc-pickup-title-text]' ) && ! str_contains( $checkout_js, 'selectedPointCityLine(point)' ) && ! str_contains( $checkout_js, '[data-wdc-pickup-city]' ) && str_contains( $checkout_js, '[data-wdc-pickup-card]' ) && str_contains( $checkout_js, '[data-wdc-pickup-empty-open]' ) && str_contains( $checkout_js, 'cityWithType' ), 'Checkout JS must update the shared selected-point card address after pickup selection without relying on a separate city line.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function setHidden(element, hidden)' ) && str_contains( $checkout_js, "element.classList.toggle('wdc-is-hidden', hidden)" ) && str_contains( $checkout_js, "element.setAttribute('aria-hidden', hidden ? 'true' : 'false')" ) && str_contains( $checkout_js, "element.style.display = 'none'" ) && str_contains( $checkout_js, "element.style.removeProperty('display')" ) && str_contains( $checkout_js, 'setHidden(card, false)' ) && str_contains( $checkout_js, 'showEmptySelection(container)' ) && str_contains( $checkout_js, 'clearContainerSelection(container)' ), 'Checkout JS applySelection must show selected cards only for complete points and use explicit empty-state clearing for incomplete selections.' );
pickup_checkout_assert( str_contains( $checkout_js, 'setSelectedPickupPoint(selectedPoint)' ) && str_contains( $checkout_js, 'window.wdcPickupCheckout.selectedPickupPoints = selectedPickupPoints' ) && str_contains( $checkout_js, 'selectedPoint: activeSelected || localizedContext.selectedPoint || runtimeContext.selectedPoint || null' ) && str_contains( $checkout_js, 'function normalizeSelectedPoint(point)' ), 'Checkout JS must keep the active-family selected pickup point available for the next map open regardless of localized sameDestination.' );
pickup_checkout_assert( str_contains( $checkout_js, 'lat: fieldContext.lat || runtimeContext.lat || localizedContext.lat' ) && str_contains( $checkout_js, 'lng: fieldContext.lng || runtimeContext.lng || localizedContext.lng' ), 'Initial context must prefer DOM hidden coordinates, then fresh runtime context, then localized config.' );
pickup_checkout_assert( str_contains( $checkout_js, 'wdc_platform_location_lat' ) && str_contains( $checkout_js, 'wdc_platform_location_lng' ), 'Initial context must read city picker hidden lat/lng fields.' );
pickup_checkout_assert( str_contains( $checkout_js, 'wdc_platform_location_postcode' ) && str_contains( $checkout_js, 'wdc_platform_location_display_name' ), 'Initial context query must use hidden postcode/display_name fields.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var activeSelected = selectedPickupPointForFamily(activeFamily)' ) && str_contains( $checkout_js, 'var runtimeContext = sameDestination(fieldContext, currentContext)' ) && str_contains( $checkout_js, 'var localizedContext = sameDestination(fieldContext, configContext)' ), 'Initial context must merge coordinates from same-destination current/localized context while keeping active selected point separately.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function sameDestination(a, b)' ) && str_contains( $checkout_js, 'aPostcode && bPostcode && aPostcode === bPostcode' ), 'sameDestination must treat matching non-empty postcode as the strongest match.' );
pickup_checkout_assert( str_contains( $checkout_js, 'containsDestinationName(aName, bName)' ) && str_contains( $checkout_js, 'containsDestinationName(bName, aName)' ), 'sameDestination must allow short city display names to match region-qualified display names.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var visibleDestinationChanged = !!(visibleCity && hiddenDisplay && !destinationTextMatches(visibleCity, hiddenDisplay) && !destinationTextMatches(visibleCity, hiddenCity))' ), 'contextFromFields must detect stale hidden destinations without rejecting city-selector formatted visible city values.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var city = visibleDestinationChanged ? visibleCity : (hiddenDisplay || visibleCity)' ), 'contextFromFields must prefer visible city when hidden display belongs to the old destination.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var postcode = visibleDestinationChanged ? (visiblePostcode || hiddenPostcode) : (hiddenPostcode || visiblePostcode)' ), 'contextFromFields must prefer visible postcode when visible city changed.' );
pickup_checkout_assert( str_contains( $checkout_js, 'if (!visibleDestinationChanged && validCoordinate(hiddenLat, hiddenLng))' ), 'contextFromFields must not use old hidden lat/lng when visible city changed.' );
pickup_checkout_assert( str_contains( $checkout_js, 'query: fieldContext.query || runtimeContext.query || localizedContext.query' ), 'Initial context must use hidden/visible query first and localized config only as fallback.' );
pickup_checkout_assert( ! str_contains( $checkout_js, 'function debugDeep()' ) && ! str_contains( $checkout_js, 'window.wdcPickupCheckout.deepDebug' ) && ! str_contains( $checkout_js, 'debugDeep(' ), 'Temporary frontend deep debug diagnostics must be removed.' );
pickup_checkout_assert( str_contains( $city_selector_js, "CustomEvent( 'wdc:location-selected'" ) && str_contains( $city_selector_js, 'display_name: location.display_name || label ||' ), 'City selector must dispatch wdc:location-selected with display_name even when coordinates are empty.' );
pickup_checkout_assert( str_contains( $city_selector_js, 'fias_id: location.fias_id ||' ) && str_contains( $city_selector_js, 'gar_object_id: location.gar_object_id || location.gar_id ||' ) && str_contains( $city_selector_js, 'kladr_id: location.kladr_id ||' ), 'City selector location-selected detail must include local identity fields used by pickup quick checks.' );
pickup_checkout_assert( str_contains( $city_selector_js, 'region_type: location.region_type ||' ) && str_contains( $city_selector_js, 'city_type: location.city_type ||' ) && str_contains( $city_selector_js, 'place_type: location.place_type ||' ) && str_contains( $city_selector_js, 'city_value: city' ), 'City selector location-selected detail must include full formatted location context.' );
pickup_checkout_assert( str_contains( $city_selector_js, 'window.WDCCheckoutCitySelector.applyLocation' ) && str_contains( $city_selector_js, 'applySelectedLocation( location || {}, options || {} )' ), 'City selector must expose a minimal applyLocation API for pickup-confirmed locality changes.' );
pickup_checkout_assert( str_contains( $checkout_js, "document.body.addEventListener('wdc:location-selected'" ) && str_contains( $checkout_js, 'contextFromLocationDetail' ), 'Pickup JS must listen for city selector location events.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var query = [postcode, displayName].filter(Boolean).join' ), 'Location-selected context must create a non-empty city query from postcode/display_name.' );
pickup_checkout_assert( str_contains( $checkout_js, 'fias_id: detail.fias_id ||' ) && str_contains( $checkout_js, 'city_value: detail.city_value || detail.settlement_name || detail.city_name || displayName' ), 'Pickup location-selected context must preserve FIAS and formatted city values.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function clearPickupSelectionUi(reason)' ) && str_contains( $checkout_js, 'function resetPickupSelectionOnServer(reason, family, options)' ), 'Pickup reset must split UI clear and family-aware server reset.' );
pickup_checkout_assert( str_contains( $checkout_js, 'resolveLocation(pointPayload(point), checkoutContext)' ) && str_contains( $checkout_js, 'showLocationChangeConfirm' ) && str_contains( $checkout_js, 'applyConfirmedPickupLocationChange' ), 'Pickup checkout JS must resolve and confirm cross-location pickup selection.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function setLoading(message)' ) && str_contains( $checkout_js, 'wdc-pickup-modal-loading' ) && str_contains( $checkout_js, 'setModalSelectButtonsDisabled' ), 'Pickup save must show modal loading and disable select buttons immediately.' );
pickup_checkout_assert( str_contains( $checkout_js, 'clearLoading();' ) && str_contains( $checkout_js, 'Не удалось сохранить пункт выдачи' ), 'Pickup save errors must clear loading and keep the modal open with a notice.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function runCrossLocationSelection(point, location)' ) && str_contains( $checkout_js, 'enableDestinationResetSuppression(60000)' ) && str_contains( $checkout_js, 'var updatedCheckout = waitForUpdatedCheckout(60000)' ) && str_contains( $checkout_js, 'updatedCheckout.then(function ()' ) && str_contains( $checkout_js, 'window.WDCPickupApi.save(point.id, currentMethod, payload)' ), 'Cross-location flow must close the map, wait for updated_checkout, then save the pending point.' );
$cross_flow_source = substr( $checkout_js, strpos( $checkout_js, 'function runCrossLocationSelection(point, location)' ), 1400 );
pickup_checkout_assert( false !== strpos( $cross_flow_source, 'applyConfirmedPickupLocationChange(location);' ) && strpos( $cross_flow_source, 'applyConfirmedPickupLocationChange(location);' ) < strpos( $cross_flow_source, 'close();' ) && strpos( $cross_flow_source, 'close();' ) < strpos( $cross_flow_source, 'var updatedCheckout = waitForUpdatedCheckout(60000)' ) && strpos( $cross_flow_source, 'var updatedCheckout = waitForUpdatedCheckout(60000)' ) < strpos( $cross_flow_source, 'triggerCheckoutUpdate();' ) && strpos( $cross_flow_source, 'triggerCheckoutUpdate();' ) < strpos( $cross_flow_source, 'updatedCheckout.then(function ()' ) && strpos( $cross_flow_source, 'updatedCheckout.then(function ()' ) < strpos( $cross_flow_source, 'window.WDCPickupApi.save(point.id, currentMethod, payload)' ), 'Cross-location flow must update destination, close the modal, listen for updated_checkout, update checkout, then save the selected pickup point.' );
pickup_checkout_assert( str_contains( $cross_flow_source, 'boot();' ) && str_contains( $cross_flow_source, 'var currentMethod = currentShippingMethod();' ) && str_contains( $cross_flow_source, 'document.querySelector(\'[data-wdc-pickup-checkout]\')' ) && str_contains( $cross_flow_source, 'applySelection(actualContainer' ), 'Cross-location save must use the current shipping method and fresh checkout container after WooCommerce rerender.' );
pickup_checkout_assert( str_contains( $cross_flow_source, "payload.selection_intent = 'explicit'" ) && str_contains( $cross_flow_source, 'var savedPoint = response.pickup_point || {}' ) && str_contains( $cross_flow_source, 'syncPickupContextAfterLocationChange(location, savedPoint)' ), 'Cross-location save must synchronize map context after the pending point is saved.' );
pickup_checkout_assert( ! str_contains( $checkout_js, 'selectCheapestPickupRate' ) && ! str_contains( $checkout_js, 'rateCost(' ) && ! str_contains( $checkout_js, 'rateLabelText' ) && ! str_contains( $checkout_js, 'parsePrice' ) && ! str_contains( $checkout_js, 'Пересчитываем тариф' ), 'Cross-location frontend must no longer choose the cheapest pickup rate or wait for a second shipping-rate recalculation.' );
pickup_checkout_assert( 1 === substr_count( $cross_flow_source, 'waitForUpdatedCheckout(' ), 'Cross-location flow must wait for only one updated_checkout event.' );
pickup_checkout_assert( str_contains( $cross_flow_source, 'triggerCheckoutUpdate();' ), 'Cross-location flow must still trigger checkout recalculation before saving the pending point.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function showCheckoutNotice(message)' ) && str_contains( $checkout_js, 'Не удалось дождаться пересчета доставки. Выберите пункт выдачи еще раз.' ) && str_contains( $checkout_js, 'После пересчета выбранный способ доставки стал недоступен' ) && str_contains( $checkout_js, "resetPickupSelectionOnServer('cross_location_method_unavailable', null, { all: true })" ), 'Cross-location flow must show checkout notices and use explicit global reset only after a location-context recalculation makes the pickup method unavailable.' );
pickup_checkout_assert( str_contains( $checkout_js, 'enableDestinationResetSuppression(60000)' ) && str_contains( $checkout_js, 'disableDestinationResetSuppression' ) && str_contains( $checkout_js, 'suppressDestinationResetTimer' ) && ! str_contains( $checkout_js, 'setTimeout(function () { suppressNextDestinationReset = false; }, 0)' ), 'Confirmed cross-location pickup flow must suppress destination reset until checkout recalculation, save, timeout, or error.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var suppressPickupResetOnNextLocationSelected = false' ) && str_contains( $checkout_js, 'function beginControlledLocationChange()' ) && str_contains( $checkout_js, 'function consumeControlledLocationChange()' ) && str_contains( $checkout_js, 'window.setTimeout(consumeControlledLocationChange, 5000)' ), 'Controlled pickup locality changes must use a one-shot suppress flag with timeout safety.' );
pickup_checkout_assert( str_contains( $checkout_js, 'beginControlledLocationChange();' ) && strpos( $checkout_js, 'beginControlledLocationChange();' ) < strpos( $checkout_js, 'window.WDCCheckoutCitySelector.applyLocation(location' ) && str_contains( $checkout_js, 'window.WDCCheckoutCitySelector.applyLocation(location, { updateCheckout: false, explicit: true, source: \'pickup\', updateFields: true })' ) && str_contains( $checkout_js, 'context.city_value || context.settlement_name || context.city_name || context.display_name' ) && str_contains( $checkout_js, 'function setCheckoutStateField(name, context)' ), 'Confirmed cross-location pickup flow must mark controlled city-selector applyLocation before applying the resolved location.' );
pickup_checkout_assert( str_contains( $checkout_js, "setHiddenValue('wdc_platform_location_region_type'" ) && str_contains( $checkout_js, "setHiddenValue('wdc_platform_location_city_type'" ) && str_contains( $checkout_js, "setHiddenValue('wdc_platform_location_place_type'" ) && str_contains( $checkout_js, "setHiddenValue('wdc_platform_location_gar_object_id'" ), 'Confirmed cross-location pickup fallback must write the same hidden location fields as the city selector.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function syncPickupContextAfterLocationChange(location, savedPoint)' ) && str_contains( $checkout_js, 'var fieldContext = contextFromFields();' ) && str_contains( $checkout_js, 'var selectedPoint = normalizeSelectedPoint(savedPoint || {})' ) && str_contains( $checkout_js, 'context.selectedPoint = selectedPoint' ), 'Cross-location context sync must rebuild context from resolved location and actual checkout fields while preserving selected point.' );
pickup_checkout_assert( str_contains( $checkout_js, 'window.wdcPickupCheckout.currentContext = context' ) && str_contains( $checkout_js, 'window.wdcPickupCheckout.initialContext = Object.assign({}, window.wdcPickupCheckout.initialContext || {}, context)' ) && str_contains( $checkout_js, 'window.wdcPickupCheckout.selectedPickupPoint = selectedPoint' ), 'Cross-location context sync must update currentContext, initialContext, and selectedPickupPoint globals for the next map open.' );
pickup_checkout_assert( str_contains( $checkout_js, 'invalidatePrefetch();' ) && str_contains( $checkout_js, 'schedulePrefetch();' ) && str_contains( $checkout_js, 'normalizeGuid(context.fias_id || \'\')' ), 'Cross-location context sync must clear old prefetch data and rebuild cache keys with the new FIAS/location context.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var checkoutFias = normalizeGuid(checkoutContext && checkoutContext.fias_id)' ) && str_contains( $checkout_js, 'var pointFias = normalizeGuid(point && (point.fias_location_guid || point.fias_id))' ) && str_contains( $checkout_js, 'return checkoutFias === pointFias;' ), 'Pickup quick check must compare normalized checkout FIAS and pickup fias_location_guid before fallback matching.' );
$commit_source = substr( $checkout_js, strpos( $checkout_js, 'function commitPoint(point, shippingMethodId, options)' ), 2200 );
$save_point_source = substr( $checkout_js, strpos( $checkout_js, 'function savePoint(point)' ), strpos( $checkout_js, 'function runCrossLocationSelection(point, location)' ) - strpos( $checkout_js, 'function savePoint(point)' ) );
pickup_checkout_assert( str_contains( $commit_source, 'var payload = pointPayload(point);' ) && str_contains( $commit_source, "payload.selection_intent = 'explicit'" ) && str_contains( $commit_source, 'window.WDCPickupApi.save(point.id, shippingMethodId || method, payload)' ) && str_contains( $commit_source, 'applySelection(container, response.pickup_point || {})' ) && str_contains( $commit_source, 'close();' ), 'Same-city save must still call REST save with the full payload, apply the selected point UI, and close the modal.' );
pickup_checkout_assert( str_contains( $commit_source, 'if (true === options.updateCheckoutAfterSave)' ) && ! str_contains( $commit_source, 'options.updateCheckoutAfterSave !== false' ), 'commitPoint must only trigger checkout update when updateCheckoutAfterSave is explicitly true.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function requiresRateRefreshAfterPickupSave(point)' ) && str_contains( $checkout_js, "family === 'dpd:pickup'" ) && str_contains( $checkout_js, "family === 'yandex_delivery:pickup'" ), 'DPD and Yandex pickup selections should request checkout recalculation after save.' );
pickup_checkout_assert( str_contains( $save_point_source, 'if (pointMatchesDestinationQuick(point, checkoutContext) || !window.WDCPickupApi.resolveLocation)' ) && str_contains( $save_point_source, 'commitPoint(point, method, { updateCheckoutAfterSave: requiresRateRefreshAfterPickupSave(point) });' ), 'Same-FIAS pickup points must save immediately without resolve-location confirm flow, with family-aware recalculation after save.' );
pickup_checkout_assert( 3 === substr_count( $save_point_source, 'commitPoint(point, method, { updateCheckoutAfterSave: requiresRateRefreshAfterPickupSave(point) });' ), 'Same-city quick match, resolve-location no-change, and resolve-location fallback must all use the family-aware update_checkout guard.' );
pickup_checkout_assert( ! str_contains( $save_point_source, 'triggerCheckoutUpdate();' ) && ! str_contains( $save_point_source, 'commitPoint(point);' ), 'Same-city savePoint flow must not trigger WooCommerce update_checkout directly or through default commitPoint options.' );
pickup_checkout_assert( str_contains( $checkout_js, 'updateCurrentContext(context)' ) && str_contains( $checkout_js, 'applyContextToHidden(context)' ) && str_contains( $checkout_js, "resetSelection('location_changed')" ), 'Manual location-selected changes must keep currentContext while resetting all pickup family buckets on server and UI.' );
pickup_checkout_assert( str_contains( $checkout_js, 'sameLocationContext(previousContext, context)' ) && str_contains( $checkout_js, 'var fieldContext = contextFromFields();' ) && str_contains( $checkout_js, 'var previousContext = Object.assign({}, currentContext || {})' ), 'Manual location-selected comparison must use the previous checkout location before considering field context fallback.' );
pickup_checkout_assert( str_contains( $checkout_js, 'if (suppressPickupResetOnNextLocationSelected)' ) && str_contains( $checkout_js, 'consumeControlledLocationChange();' ) && str_contains( $checkout_js, 'rememberDestinationFingerprint(context);' ) && ! str_contains( $checkout_js, "resetPickupSelectionOnServer('location_selected')" ), 'Controlled cross-location location-selected events must consume the flag and skip pickup reset.' );
pickup_checkout_assert( ! str_contains( $checkout_js, "debug('wdc:location-selected detail', event.detail || {});\n\t\tinvalidatePrefetch();\n\t\tupdateCurrentContext(context);\n\t\tapplyContextToHidden(context);\n\t\tresetSelection();" ), 'Location-selected event must not call broad resetSelection after setting context.' );
pickup_checkout_assert( str_contains( $checkout_js, 'stateContextMatchesCurrentDestination' ) && str_contains( $checkout_js, 'contextMatches(currentContext, context)' ), 'State refresh must ignore empty or stale old-city context.' );
pickup_checkout_assert( str_contains( $checkout_js, 'refreshCheckoutContextOnce(700, { returnContext: true })' ) && str_contains( $checkout_js, 'Promise.race' ), 'Open modal must briefly wait for checkout state coordinates when context has query but no coordinates.' );
pickup_checkout_assert( str_contains( $checkout_js, 'countryBlocked' ) && str_contains( $checkout_js, 'var normalizedCountry = String(country || \'RU\').toUpperCase()' ) && str_contains( $checkout_js, 'normalizedCountry !== activePickupCountryCode' ) && str_contains( $checkout_js, "normalizedCountry !== 'RU'" ), 'Initial context must ignore non-RU checkout destinations.' );
pickup_checkout_assert( str_contains( $checkout_js, 'refreshCheckoutContext' ) && str_contains( $checkout_js, "window.jQuery(document.body).on('updated_checkout'" ), 'updated_checkout must refresh server city_context and then prefetch pickup points.' );
$pickup_css = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-map.css' ) ?: '';
$pickup_docs = file_get_contents( $root . '/docs/subsystems/locations.md' ) ?: '';
pickup_checkout_assert( str_contains( $pickup_docs, 'Pickup Styling Ownership' ) && str_contains( $pickup_docs, 'Оплата по счету от ИП/ООО' ) && str_contains( $pickup_docs, 'WDC owns' ), 'Canonical pickup docs must describe the resolved external plugin styling split.' );
foreach ( array( '.button:after', '.button::after', '.wc-forward:after', '.wc-forward::after', '.checkout-button:after', '.checkout-button::after', '.blockUI.blockOverlay:before', '.blockUI.blockOverlay::before', '.processing:before', '.processing::before' ) as $forbidden_selector ) {
	pickup_checkout_assert( ! str_contains( $pickup_css, $forbidden_selector ), 'Pickup CSS must not override global WooCommerce pseudo-element selector: ' . $forbidden_selector );
}
pickup_checkout_assert( str_contains( $checkout_js, 'applyContextToHidden' ) && str_contains( $checkout_js, 'window.WDCPickupApi.state()' ), 'Frontend must update hidden fields from checkout state city_context after DaData enrichment.' );
pickup_checkout_assert( str_contains( $checkout_js, 'refreshCheckoutContextOnce().then(function (state)' ) && str_contains( $checkout_js, 'reconcilePickupInlineNoticesWithState(state);' ) && str_contains( $checkout_js, 'restoreSelectedPickupUi();' ), 'Frontend must restore the active pickup family card after authoritative state refresh reconciles pickupSelections.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var activePickupFamily = String(checkoutConfig.activePickupFamily' ) && str_contains( $checkout_js, 'checkoutConfig.selectedPickupPoint' ) && str_contains( $checkout_js, 'return normalizeShippingMethod(activeMethod || checkoutConfig.activeShippingMethod || checkoutConfig.active_shipping_method || activePickupFamily || checkoutConfig.shippingMethodId' ) && str_contains( $checkout_js, 'function boot()' ) && str_contains( $checkout_js, "document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(init);" ) && str_contains( $checkout_js, 'restoreSelectedPickupUi();' ), 'Frontend boot must restore selected pickup cards from localized pickupSelections/activePickupFamily on checkout reload and fill hidden fields through applySelection.' );
pickup_checkout_assert( str_contains( $checkout_js, 'prefetchInitialPoints' ) && str_contains( $checkout_js, 'bboxAround' ) && str_contains( $checkout_js, 'searchInitial(context.query' ), 'Frontend must prefetch search to bbox when initial coordinates are unavailable.' );
pickup_checkout_assert( str_contains( $checkout_js, 'isPickupMethodActive' ) && str_contains( $checkout_js, 'isPickupRateValue' ) && str_contains( $checkout_js, 'shippingMethodFamily' ), 'Prefetch must run only for the active pickup method family.' );
pickup_checkout_assert( str_contains( $checkout_js, 'hasPickupBlock' ) && str_contains( $checkout_js, '[data-wdc-pickup-checkout]' ), 'Prefetch must require a pickup checkout block.' );
pickup_checkout_assert( str_contains( $checkout_js, 'prefetchController.abort()' ) && str_contains( $checkout_js, 'setTimeout(prefetchInitialPoints, 400)' ), 'Prefetch must debounce and abort stale requests.' );
pickup_checkout_assert( str_contains( $checkout_js, 'prefetchCache = null' ) && str_contains( $checkout_js, 'invalidatePrefetch' ), 'Prefetch cache must invalidate on destination changes.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var context = withPrefetch(withCarrierContext(resolvedContext, method))' ) && str_contains( $checkout_js, 'preloadedPoints: prefetchCache.points' ), 'Open modal must pass cached preloaded points to the map.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var selectedPickupPoints = extractPickupSelections(checkoutConfig);' ) && str_contains( $checkout_js, 'function extractPickupSelections(payload)' ) && str_contains( $checkout_js, 'payload.pickup_selections' ) && str_contains( $checkout_js, 'payload.selected_pickup_point' ) && str_contains( $checkout_js, 'function selectedPickupPointForFamily(family)' ) && str_contains( $checkout_js, 'selectedPickupPoints[family]' ), 'Checkout JS must keep selected pickup points by pickup_family and understand camelCase/snake_case state payloads.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function containerMatchesActivePickup(container, activeMethodValue, activeFamilyValue)' ) && str_contains( $checkout_js, "document.querySelectorAll('[data-wdc-pickup-checkout]').length === 1" ) && str_contains( $checkout_js, "container.setAttribute('data-shipping-method-id', method)" ) && str_contains( $checkout_js, 'var containerMatches = containerMatchesActivePickup(container, method, family)' ), 'Checkout JS must restore active pickup selections into generic or stale pickup containers.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function selectionLocationMatchesContext(point, context)' ) && str_contains( $checkout_js, 'destination_location_id' ) && str_contains( $checkout_js, 'matchedBy = \'location_id\'' ) && str_contains( $checkout_js, 'function fingerprintValue(fingerprint, key)' ), 'Checkout JS must restore selected pickup points by stable destination identifiers before full fingerprint fallback.' );
pickup_checkout_assert( str_contains( $checkout_js, 'if (context.city_code || context.cdek_city_code)' ) && str_contains( $checkout_js, "window.WDCPickupApi.points('', prefetchController.signal, context)" ), 'CDEK pickup prefetch must request deliverypoints by city_code before the modal is opened.' );
$map_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-map.js' ) ?: '';
$modal_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-modal.js' ) ?: '';
$map_css = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-map.css' ) ?: '';
$api_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-api.js' ) ?: '';
$domestic_tariff_js = file_get_contents( $root . '/assets/frontend/domestic-tariff-selector.js' ) ?: '';
pickup_checkout_assert( str_contains( $api_js, 'payload.selection_intent = point.selection_intent' ), 'Pickup API client must forward selection_intent in the checkout pickup-point POST body.' );
pickup_checkout_assert( str_contains( $api_js, 'pickup_family' ) && str_contains( $api_js, 'shipping_method_id' ) && str_contains( $api_js, "request(path, { method: 'DELETE' })" ), 'Pickup reset API client must support family-scoped reset params.' );
pickup_checkout_assert( str_contains( $map_js, "carrier === 'russian_post_domestic' || carrier === 'russian_post'" ) && str_contains( $map_js, 'point.postcode || point.postal_code' ) && str_contains( $map_js, "carrier === 'cdek'" ) && str_contains( $map_js, 'point.cdek_code || point.point_code' ) && str_contains( $map_js, 'function pointDisplayTitle(point)' ) && str_contains( $map_js, 'escapeHtml(pointDisplayTitle(point))' ), 'Map popup/list titles must show Russian Post postcode and CDEK code with the same display-title builder.' );
pickup_checkout_assert( str_contains( $map_js, "carrier === 'yandex_delivery'" ) && str_contains( $map_js, "return '';" ), 'Map display-code fallback must not expose Yandex platform_station_id.' );
pickup_checkout_assert( str_contains( $map_js, 'function presentationComment(point)' ) && str_contains( $map_js, 'wdc-pickup-popup__title-comment' ) && str_contains( $map_js, 'wdc-pickup-list__title-comment' ), 'Map popup and side list must render presentation_comment directly under the title.' );
pickup_checkout_assert( str_contains( $map_js, 'var yandexCityListMode = isYandexDeliveryContext(context)' ) && str_contains( $map_js, 'function pointInsideBounds(point, bbox)' ) && str_contains( $map_js, 'return pointInsideBounds(point, lastBbox);' ) && str_contains( $map_js, 'renderList(listPointsForCurrentBounds(), visiblePoints.length)' ), 'Yandex map list must filter the already-loaded city dataset by current viewport bounds.' );
pickup_checkout_assert( str_contains( $map_js, "return 'Показано ' + shown + ' из ' + total" ) && str_contains( $map_js, 'listMeta(totalCount, points.length)' ), 'Map list meta must show viewport count against the full loaded city count.' );
pickup_checkout_assert( str_contains( $map_js, 'committedPoint = matchingPoint(committedPoint, visiblePoints) || committedPoint' ) && ! str_contains( $map_js, "\n\t\t\tcommittedPoint = null" ), 'Viewport/list refresh must not clear a committed selected point that left the visible map bounds.' );
pickup_checkout_assert( str_contains( $map_css, '.wdc-pickup-popup__title-comment' ) && str_contains( $map_css, '.wdc-pickup-list__title-comment' ), 'Pickup CSS must style presentation_comment in popup and side list.' );
pickup_checkout_assert( str_contains( $domestic_tariff_js, 'function syncDisabledState()' ) && str_contains( $domestic_tariff_js, 'var active = !!activeFamily && !!groupFamily && activeFamily === groupFamily;' ) && str_contains( $domestic_tariff_js, "wrapper.classList.toggle('is-inactive', !active)" ) && str_contains( $domestic_tariff_js, 'input.disabled = !active' ) && str_contains( $domestic_tariff_js, "input[name^=\"shipping_method\"]" ), 'Grouped tariff JS must disable inactive method rates, keep all nested rates disabled when no method is active, and re-enable only the active family.' );
pickup_checkout_assert( str_contains( $map_css, '.wdc-rp-pickup-checkout [hidden]' ) && str_contains( $map_css, '.wdc-rp-pickup-checkout .wdc-is-hidden' ) && str_contains( $map_css, 'display: none !important' ) && ! preg_match( '/(^|\\n)\\s*\\[hidden\\]\\s*\\{/m', $map_css ), 'Pickup hidden CSS must be scoped to the WDC checkout block, not global.' );
pickup_checkout_assert( str_contains( $map_css, '.wdc-pickup-point-card' ) && str_contains( $map_css, 'width: 100%' ) && str_contains( $map_css, 'max-width: none' ) && str_contains( $map_css, '.wdc-pickup-point-card__accent' ) && str_contains( $map_css, '#16a34a' ) && str_contains( $map_css, 'overflow-wrap: anywhere' ) && str_contains( $map_css, 'word-break: normal' ) && ! str_contains( $map_css, '.wdc-pickup-point-card__title::before' ), 'Selected pickup card CSS must be full-width, wrap long addresses, and use the green accent instead of a red pseudo-icon.' );
pickup_checkout_assert( ! str_contains( $modal_js, 'autofocus' ) && ! str_contains( $checkout_js, 'search.focus' ) && str_contains( $modal_js, "button[data-wdc-close]" ), 'Opening the pickup modal must not autofocus the address search input.' );
pickup_checkout_assert( str_contains( $modal_js, 'wdc-pickup-search__icon' ) && str_contains( $modal_js, 'aria-hidden="true">🔍</span>' ), 'Pickup modal search template must render a decorative magnifier icon.' );
pickup_checkout_assert( str_contains( $modal_js, 'data-wdc-search-submit' ) && str_contains( $modal_js, 'Искать адрес' ), 'Pickup modal search template must render an explicit address search button.' );
$search_row_pos = strpos( $modal_js, '<div class="wdc-pickup-search">' );
$search_submit_pos = strpos( $modal_js, 'data-wdc-search-submit' );
$search_row_end_pos = false !== $search_row_pos ? strpos( $modal_js, '</div>', $search_row_pos ) : false;
pickup_checkout_assert( false !== $search_row_pos && false !== $search_submit_pos && false !== $search_row_end_pos && $search_row_pos < $search_submit_pos && $search_submit_pos < $search_row_end_pos, 'Pickup modal search submit must live inside the search row wrapper.' );
pickup_checkout_assert( str_contains( $modal_js, 'wdc-pickup-modal__map-pane' ) && str_contains( $modal_js, 'wdc-pickup-map__locate' ) && str_contains( $modal_js, 'data-wdc-geolocation' ), 'Pickup modal must render geolocation as a map overlay control.' );
pickup_checkout_assert( str_contains( $modal_js, 'title="Определить моё местоположение"' ) && str_contains( $modal_js, 'aria-label="Определить моё местоположение"' ) && str_contains( $modal_js, 'wdc-pickup-map__locate-icon' ) && str_contains( $modal_js, '<svg viewBox="0 0 24 24"' ) && str_contains( $modal_js, 'M4 11.4 20.2 3.8 12.6 20l-2.1-7.1L4 11.4Z' ), 'Pickup geolocation overlay control must expose title, aria-label, and the navigation-arrow SVG icon.' );
pickup_checkout_assert( ! str_contains( $modal_js . $checkout_js, '⌖' ), 'Pickup geolocation button must not use the old target glyph.' );
pickup_checkout_assert( ! str_contains( $modal_js, 'Моё местоположение' ) && ! str_contains( $modal_js, 'wdc-pickup-search__geo' ), 'Pickup modal search row must no longer render the old text geolocation button.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function runAddressSearch()' ) && str_contains( $checkout_js, "searchSubmit.addEventListener('click', runAddressSearch)" ), 'Search submit button must call the shared map.search flow.' );
pickup_checkout_assert( str_contains( $checkout_js, "search.addEventListener('keydown'" ) && str_contains( $checkout_js, "event.key === 'Enter'" ) && str_contains( $checkout_js, 'runAddressSearch();' ), 'Enter in the search input must call the shared map.search flow.' );
$geolocation_setup_source = substr( $checkout_js, strpos( $checkout_js, 'function setupGeolocationButton()' ), 2400 );
pickup_checkout_assert( str_contains( $geolocation_setup_source, '!window.navigator || !window.navigator.geolocation' ) && str_contains( $geolocation_setup_source, 'geolocationButton.hidden = true' ) && str_contains( $geolocation_setup_source, 'geolocationButton.disabled = true' ) && str_contains( $geolocation_setup_source, 'Геолокация не поддерживается браузером.' ), 'Geolocation button must hide/disable itself when browser geolocation is unavailable.' );
pickup_checkout_assert( str_contains( $geolocation_setup_source, 'geolocationButton.disabled = true' ) && str_contains( $geolocation_setup_source, "geolocationButton.classList.add('is-loading')" ) && str_contains( $geolocation_setup_source, "geolocationButton.title = 'Определяем местоположение...'" ) && str_contains( $geolocation_setup_source, "map.setStatus('Определяем местоположение...')" ) && str_contains( $geolocation_setup_source, "window.navigator.geolocation.getCurrentPosition(function (position)" ), 'Geolocation click must disable the overlay control, enter loading state, update title/status, and call getCurrentPosition.' );
pickup_checkout_assert( str_contains( $geolocation_setup_source, 'enableHighAccuracy: true' ) && str_contains( $geolocation_setup_source, 'timeout: 10000' ) && str_contains( $geolocation_setup_source, 'maximumAge: 300000' ), 'Geolocation options must request high accuracy with the required timeout and cache age.' );
pickup_checkout_assert( str_contains( $geolocation_setup_source, 'map.useUserLocation(position.coords.latitude, position.coords.longitude)' ) && ! str_contains( $geolocation_setup_source, 'update_checkout' ) && ! str_contains( $geolocation_setup_source, 'applyLocation' ), 'Geolocation success must pass coordinates to the map without changing checkout destination or recalculating checkout.' );
pickup_checkout_assert( str_contains( $geolocation_setup_source, 'function resetGeolocationButton()' ) && str_contains( $geolocation_setup_source, "geolocationButton.classList.remove('is-loading')" ) && str_contains( $geolocation_setup_source, "geolocationButton.title = 'Определить моё местоположение'" ) && str_contains( $geolocation_setup_source, 'wdc-pickup-map__locate-icon' ) && str_contains( $geolocation_setup_source, '<svg viewBox="0 0 24 24"' ), 'Geolocation success/error must restore the overlay control default SVG icon and title.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function geolocationErrorMessage(error)' ) && str_contains( $checkout_js, 'Браузер не дал доступ к местоположению' ) && str_contains( $checkout_js, 'Не удалось определить местоположение за отведенное время' ), 'Geolocation errors must map permission denied, unavailable, timeout, and unknown states to readable messages.' );
$input_handler_pos = strpos( $checkout_js, "search.addEventListener('input'" );
$confirm_handler_pos = strpos( $checkout_js, "confirmButton.addEventListener('wdc:point-selected'" );
pickup_checkout_assert( false !== $input_handler_pos && false !== $confirm_handler_pos && ! str_contains( substr( $checkout_js, $input_handler_pos, $confirm_handler_pos - $input_handler_pos ), 'map.search' ), 'Input event must only filter postcode-only text and must not launch search.' );
pickup_checkout_assert( str_contains( $map_js, 'WDCPickupMapProviders' ) && str_contains( $map_js, 'providerFactory.create' ), 'wdc-pickup-map.js must use provider abstraction.' );
pickup_checkout_assert( ! str_contains( $map_js, 'window.L' ) && ! str_contains( $map_js, 'L.map' ), 'wdc-pickup-map.js must not use Leaflet directly.' );
pickup_checkout_assert( str_contains( $map_js, 'data-wdc-point-id' ) && str_contains( $map_js, 'wdc-pickup-list__item' ) && ! str_contains( $map_js, 'LIST_LIMIT' ) && ! str_contains( $map_js, 'points.slice(0' ) && str_contains( $map_js, 'points.map(renderListItem).join' ), 'Map modal must render the full pickup point list without a frontend cap.' );
pickup_checkout_assert( str_contains( $map_js, 'listMeta(totalCount, points.length)' ) && str_contains( $map_js, "return 'Показано ' + shown + ' из ' + total" ), 'Map list meta must report viewport count against the real loaded point count without a frontend cap.' );
pickup_checkout_assert( str_contains( $map_js, 'distanceMeters(distanceOrigin.lat, distanceOrigin.lng' ) && str_contains( $map_js, 'formatDistance' ) && str_contains( $map_js, "' м'" ) && str_contains( $map_js, "' км'" ), 'Map list must sort by active distance origin and format meters/kilometers.' );
pickup_checkout_assert( str_contains( $api_js, "points/address-search?" ) && str_contains( $map_js, 'window.WDCPickupApi.addressSearch(query, context' ), 'Pickup address search must use the dedicated REST endpoint.' );
pickup_checkout_assert( ! str_contains( $map_js, "context.carrier === 'cdek'" ) && ! str_contains( $map_js, "context.carrier_key === 'cdek'" ), 'Checkout pickup map must not bypass shared address search for CDEK.' );
pickup_checkout_assert( ! str_contains( $map_js, 'через DaData' ) && ! str_contains( $map_js, 'Ошибка DaData' ) && str_contains( $map_js, "'Ищем адрес...'" ) && str_contains( $map_js, "'Адрес найден.'" ) && str_contains( $map_js, "'Адрес не найден.'" ), 'Checkout pickup map address-search UI must not mention DaData.' );
pickup_checkout_assert( str_contains( $map_js, 'searchMarker: activeOriginMarker()' ) && str_contains( $map_js, 'wdc-pickup-list__found' ) && str_contains( $map_js, 'Найден адрес:' ) && str_contains( $map_js, 'Ближайший ПВЗ:' ), 'Successful address search must render the active origin marker and found-address list block.' );
pickup_checkout_assert( str_contains( $map_js, 'function applySearchResult(result)' ) && str_contains( $map_js, 'refreshDistancesFromOrigin();' ) && str_contains( $map_js, "card.textContent = labels.addressFound || 'Адрес найден.'" ), 'Successful addressSearch must refresh visible point distances without replacing visiblePoints from result.points.' );
pickup_checkout_assert( str_contains( $map_js, 'function refreshDistancesFromOrigin()' ) && str_contains( $map_js, 'visiblePoints = sortPoints(enrichPoints(visiblePoints));' ) && str_contains( $map_js, 'provider.renderMarkers(visiblePoints' ) && str_contains( $map_js, 'renderCurrentList();' ), 'Changing pickup distance origin must recalculate, sort, rerender markers, and rerender the current side list before nearest text is shown.' );
pickup_checkout_assert( str_contains( $map_js, 'programmaticBoundsSuppressed' ) && str_contains( $map_js, 'scheduleProgrammaticBoundsRelease();' ) && str_contains( $map_js, 'return;' ) && str_contains( $map_js, 'loadBounds(bboxAround(point.lat, point.lng), { force: true })' ) && str_contains( $map_js, 'loadBounds(\'city-code\', { force: true })' ), 'Programmatic bounds changes must suppress automatic move loads while explicit forced bbox loads remain available.' );
pickup_checkout_assert( ! str_contains( $map_js, 'renderMarkers(Array.isArray(result.points)' ), 'Successful addressSearch must not render result.points as the final visiblePoints state.' );
pickup_checkout_assert( str_contains( $map_js, "updateLoadingMessage(requestId, labels.searchingAddress || 'Ищем адрес...')" ) && str_contains( $map_js, 'refreshDistancesFromOrigin();' ) && str_contains( $map_js, 'loadBounds(bboxAround(searchAddress.lat, searchAddress.lng), { force: true })' ), 'Address search must keep the searched address as orientation marker, refresh distances, and explicitly reload pickup points around the searched address.' );
$apply_start = strpos( $map_js, 'function applySearchResult(result)' );
$startup_start = strpos( $map_js, 'setTimeout(function ()' );
pickup_checkout_assert( false !== $apply_start && false !== $startup_start && ! str_contains( substr( $map_js, $apply_start, $startup_start - $apply_start ), 'previewNearest' ), 'Address search must not opt into nearest-point preview.' );
pickup_checkout_assert( ! str_contains( $map_js, 'visiblePoints.push(searchAddress)' ) && ! str_contains( $map_js, 'points.push(searchAddress)' ), 'Search marker must stay separate from pickup point list data.' );
pickup_checkout_assert( str_contains( $map_js, 'setPostcodeOnlyMode' ) && str_contains( $map_js, 'Сейчас работает поиск только по почтовому индексу' ) && str_contains( $map_js, "input.value.replace(/\\D+/g, '').slice(0, 6)" ), 'Frontend must switch the search input to postcode-only mode when DaData limits are exhausted.' );
pickup_checkout_assert( str_contains( $map_css, '.wdc-pickup-search' ) && str_contains( $map_css, 'min-height: 50px' ) && str_contains( $map_css, 'border-radius: 12px' ) && str_contains( $map_css, '.wdc-pickup-search__icon' ) && str_contains( $map_css, '.wdc-pickup-search__button' ) && str_contains( $map_css, 'min-height: 40px' ) && str_contains( $map_css, '.wdc-pickup-modal__close' ) && str_contains( $map_css, 'appearance: none' ) && ! str_contains( $map_css, '.wdc-pickup-search__geo' ), 'Pickup search row must be a rounded control with icon, input, inside submit button, and styled close button.' );
pickup_checkout_assert( str_contains( $map_css, '.wdc-pickup-modal__map-pane' ) && str_contains( $map_css, 'position: relative' ) && str_contains( $map_css, '.wdc-pickup-map__locate' ) && str_contains( $map_css, 'right: 16px' ) && str_contains( $map_css, 'bottom: 36px' ) && ! str_contains( $map_css, "bottom: 16px;\n\tz-index: 650" ) && str_contains( $map_css, '.wdc-pickup-map__locate.is-loading' ), 'Pickup CSS must keep the geolocation overlay at the requested 36px bottom offset and keep loading state.' );
pickup_checkout_assert( str_contains( $map_css, '.wdc-pickup-map__locate-icon svg' ) && str_contains( $map_css, 'fill: #374151' ) && str_contains( $map_css, 'width: 23px' ), 'Pickup CSS must center a dark navigation-arrow SVG in the locate button.' );
pickup_checkout_assert( str_contains( $map_css, '.wdc-pickup-modal-loading' ) && str_contains( $map_css, '.wdc-pickup-modal-loading__spinner' ) && str_contains( $map_css, '@keyframes wdc-pickup-spin' ) && str_contains( $map_css, '.wdc-pickup-modal-notice' ), 'Pickup modal must style loading overlay, spinner, and error notice.' );
pickup_checkout_assert( str_contains( $map_js, 'function createLoadingOverlay' ) && str_contains( $map_js, 'activeLoadingRequestId' ) && str_contains( $map_js, 'aria-busy' ) && str_contains( $map_js, 'wdc-pickup-list__loading' ) && str_contains( $map_css, '.wdc-pickup-map__loading' ) && str_contains( $map_css, '.wdc-pickup-list__loading' ), 'Pickup map must expose a generic accessible loading overlay for point fetch/render lifecycle.' );
pickup_checkout_assert( str_contains( $map_js, 'function useUserLocation(lat, lng)' ) && str_contains( $map_js, "userLocation = normalizeUserLocationMarker({ lat: lat, lng: lng })" ) && str_contains( $map_js, 'distanceOrigin = { lat: lat, lng: lng }' ) && str_contains( $map_js, "originStatus = 'Показаны ближайшие пункты к вашему местоположению'" ), 'Geolocation success must set user location as the distance origin and list status.' );
$geolocation_map_source = substr( $map_js, strpos( $map_js, 'function useUserLocation(lat, lng)' ), strpos( $map_js, 'function setStatus(message, type)' ) - strpos( $map_js, 'function useUserLocation(lat, lng)' ) );
pickup_checkout_assert( str_contains( $geolocation_map_source, 'searchAddress = null' ) && str_contains( $geolocation_map_source, 'provider.setCenter(lat, lng, 15)' ) && str_contains( $geolocation_map_source, 'refreshDistancesFromOrigin();' ) && str_contains( $geolocation_map_source, 'loadBounds(bboxAround(lat, lng), { force: true })' ), 'Geolocation success must replace any address origin marker, refresh distances, center the map, and force bbox load around user coordinates.' );
pickup_checkout_assert( ! str_contains( $geolocation_map_source, 'preview(' ) && ! str_contains( $geolocation_map_source, "CustomEvent('wdc:point-selected'" ) && ! str_contains( $geolocation_map_source, 'previewNearest' ), 'Geolocation success must not auto-preview, dispatch selection, or opt into nearest preview.' );
pickup_checkout_assert( str_contains( $map_js, 'function activeOriginMarker()' ) && str_contains( $map_js, 'return userLocation || searchAddress' ) && str_contains( $map_js, 'userLocation = null' ) && str_contains( $map_js, 'searchAddress = null' ), 'Address search and geolocation must replace each other as the single active origin marker.' );
pickup_checkout_assert( str_contains( $map_css, '.wdc-pickup-list__status' ) && str_contains( $map_css, '.wdc-pickup-list__status.is-error' ) && ! str_contains( $map_css, 'wdc-map-user-marker' ) && ! str_contains( $map_css, 'wdc-map-user-icon' ), 'Pickup CSS must keep geolocation list statuses and remove old blue user marker styles.' );
pickup_checkout_assert( str_contains( $map_js, 'var aKey = String(a.postal_code || a.postcode || \'\') + \'|\'' ) && str_contains( $map_js, 'return a._wdcOrder - b._wdcOrder' ), 'Map list must use stable postcode/address ordering without coordinates.' );
pickup_checkout_assert( str_contains( $map_js, "text === '0.000000'" ) && str_contains( $map_js, 'cleanDescription(point.description)' ), 'Selected card must suppress technical zero descriptions.' );
pickup_checkout_assert( str_contains( $map_js, 'provider.setActivePoint(pointId(point))' ) && str_contains( $map_js, 'provider.focusPoint(point)' ), 'Map and list preview/commit must synchronize active marker and provider focus.' );
pickup_checkout_assert( str_contains( $map_js, "' active'" ) && str_contains( $map_js, "' selected'" ), 'Preview rows must receive active/preview classes and committed rows must receive selected class.' );
pickup_checkout_assert( str_contains( $map_js, 'var initialSelectedPoint = normalizeInitialSelectedPoint' ) && str_contains( $map_js, 'var selectedPointHasCoordinates = !!(initialSelectedPoint && validPointCoordinates(initialSelectedPoint))' ) && str_contains( $map_js, 'var selectedPointCoordinates = selectedPointHasCoordinates ? coordinatePair(initialSelectedPoint.lat, initialSelectedPoint.lng) : null' ) && str_contains( $map_js, 'selectedPointCoordinates,' ), 'Opening the checkout pickup map must center initial load on the already selected pickup point when coordinates are available.' );
pickup_checkout_assert( str_contains( $map_js, 'function cdekDisplayTitle(point)' ) && str_contains( $map_js, "'ПВЗ СДЭК'" ) && str_contains( $map_js, "'Постамат СДЭК'" ), 'Checkout map must render CDEK pickup/postamat titles in list and balloon.' );
pickup_checkout_assert( str_contains( $map_js, 'var initialSelectedPoint = normalizeInitialSelectedPoint(context.selectedPoint || context.selectedPickupPoint)' ) && str_contains( $map_js, 'var previewPoint = initialSelectedPoint' ) && str_contains( $map_js, 'var committedPoint = initialSelectedPoint' ) && str_contains( $map_js, 'selected: function () { return committedPoint; }' ), 'Map JS must initialize previewPoint and committedPoint from the saved checkout point while keeping them separate.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function pointSnapshot(point)' ) && str_contains( $checkout_js, 'JSON.parse(snapshot)' ) && str_contains( $checkout_js, 'var snapshot = pointSnapshot(point)' ) && str_contains( $checkout_js, 'point.display_code || point.postcode || point.postal_code || point.point_postcode' ), 'Checkout selected point state must read object and JSON-string snapshots, including Russian Post display_code/postcode aliases.' );
pickup_checkout_assert( str_contains( $map_js, 'function pointSnapshot(point)' ) && str_contains( $map_js, 'JSON.parse(snapshot)' ) && str_contains( $map_js, 'function pointMatchKeys(point)' ) && str_contains( $map_js, 'var snapshot = pointSnapshot(point)' ) && str_contains( $map_js, 'point && point.display_code' ) && str_contains( $map_js, 'snapshot.display_code' ) && str_contains( $map_js, 'snapshot.postcode' ) && str_contains( $map_js, 'snapshot.postal_code' ) && str_contains( $map_js, 'snapshot.point_postcode' ), 'Checkout map selected Russian Post matching must include display_code/postcode fields from top-level, object snapshot, and JSON-string snapshot payloads.' );
pickup_checkout_assert( str_contains( $map_js, 'point && point.cdek_code' ) && str_contains( $map_js, 'point && point.delivery_point' ) && str_contains( $map_js, 'snapshot.cdek_code' ) && str_contains( $map_js, 'snapshot.delivery_point' ) && str_contains( $map_js, 'function hasSharedPointKey(left, right)' ), 'Checkout selected CDEK matching must keep CDEK code/delivery_point support.' );
pickup_checkout_assert( str_contains( $map_js, 'normalized.display_code = normalized.display_code || snapshot.display_code' ) && str_contains( $map_js, 'normalized.postcode = normalized.postcode || normalized.postal_code || normalized.point_postcode || normalized.display_code || snapshot.postcode || snapshot.postal_code || snapshot.point_postcode || snapshot.display_code' ), 'normalizeInitialSelectedPoint must promote Russian Post snapshot postcode/display_code aliases for preview matching.' );
pickup_checkout_assert( str_contains( $map_js, 'var matchingPreviewPoint = previewPoint ? matchingPoint(previewPoint, visiblePoints) : null' ) && str_contains( $map_js, 'previewPoint = matchingPreviewPoint' ) && str_contains( $map_js, 'committedPoint = matchingPoint(committedPoint, visiblePoints) || committedPoint' ) && str_contains( $map_js, '} else if (previewPoint) {' ) && str_contains( $map_js, 'previewPoint = null' ) && str_contains( $map_js, "list.querySelectorAll('[data-wdc-point-id]')" ) && str_contains( $map_js, 'scrollListRowIntoView(previewPoint)' ), 'Checkout preview restore must use the matching REST row while preserving committed selection when it leaves the viewport.' );
pickup_checkout_assert( str_contains( $map_js, 'function preview(point, options)' ) && str_contains( $map_js, 'previewPoint = point' ) && str_contains( $map_js, 'provider.setActivePoint(pointId(point))' ) && str_contains( $map_js, 'confirmButton.disabled = !committedPoint' ) && str_contains( $map_js, 'provider.openPointPopup(point, renderPointPopup(point' ), 'Marker/list preview must highlight the marker and open a popup without dispatching checkout selection.' );
pickup_checkout_assert( str_contains( $map_js, 'function openPointPreviewFromMarker(point)' ) && str_contains( $map_js, 'suppressNextMapClick = true' ) && str_contains( $map_js, 'openPointPreviewFromMarker(point);' ), 'Marker click must use a direct force-reopen path instead of the generic preview branch.' );
$marker_reopen_source = substr( $map_js, strpos( $map_js, 'function openPointPreviewFromMarker(point)' ) ?: 0, 900 );
pickup_checkout_assert( str_contains( $marker_reopen_source, 'popupManuallyClosed = false;' ) && strpos( $marker_reopen_source, 'popupManuallyClosed = false;' ) < strpos( $marker_reopen_source, 'provider.openPointPopup(point, renderPointPopup(point, selected), { ensureVisible: false, forceReopen: true });' ), 'Marker click force-reopen must clear popupManuallyClosed before opening the popup.' );
pickup_checkout_assert( str_contains( $map_js, 'function commit(point, options)' ) && str_contains( $map_js, 'committedPoint = point' ) && str_contains( $map_js, "CustomEvent('wdc:point-selected'" ), 'Popup/list selection must commit the point and dispatch wdc:point-selected.' );
pickup_checkout_assert( strpos( $map_js, "CustomEvent('wdc:point-selected'" ) > strpos( $map_js, 'function commit(point, options)' ) && strpos( $map_js, "CustomEvent('wdc:point-selected'" ) < strpos( $map_js, 'function markPopupManuallyClosed(source)' ), 'wdc:point-selected must only be dispatched by commit, not preview/renderMarkers.' );
pickup_checkout_assert( str_contains( $map_js, 'provider.openPointPopup(point, renderPointPopup(point, selected), { ensureVisible: false, forceReopen: true });' ) && str_contains( $map_js, 'provider.onPopupSelect(function (point) { commit(point, { focus: false }); });' ) && ! str_contains( $map_js, 'preview(point, { focus: false, ensureVisible: true, userAction: true });' ) && ! str_contains( $map_js, 'preview(point, { focus: true });' ), 'Marker and side-list clicks must imperatively reopen popups while popup select remains the explicit commit path.' );
$list_click_source = substr( $map_js, strpos( $map_js, "list.addEventListener('click'" ) ?: 0, 420 );
pickup_checkout_assert( str_contains( $list_click_source, 'openPointPreviewFromMarker(point);' ) && ! str_contains( $list_click_source, "CustomEvent('wdc:point-selected'" ) && ! str_contains( $list_click_source, 'commit(point' ), 'Side card click after popup close must ignore popupManuallyClosed and reopen the preview without committing selection.' );
$generic_preview_source = substr( $map_js, strpos( $map_js, 'function preview(point, options)' ) ?: 0, strpos( $map_js, 'function commit(point, options)' ) - strpos( $map_js, 'function preview(point, options)' ) );
pickup_checkout_assert( str_contains( $generic_preview_source, 'provider.setActivePoint(pointId(point))' ) && ! str_contains( $generic_preview_source, "CustomEvent('wdc:point-selected'" ), 'List preview must set active marker without dispatching selection.' );
pickup_checkout_assert( str_contains( $map_js, 'renderPointPopup(point, selected)' ) && str_contains( $map_js, 'data-wdc-pickup-popup-select' ) && str_contains( $map_js, 'point.address' ) && str_contains( $map_js, 'point.work_time' ) && str_contains( $map_js, 'pointTypeLabel(point)' ), 'Point popup must render card labels, address, work time, and the select button.' );
pickup_checkout_assert( str_contains( $map_js, 'preview(point, { focus: false, initial: true })' ) && ! str_contains( $map_js, 'preview(points[0], false)' ), 'Initial search must render only a preview popup.' );
pickup_checkout_assert( str_contains( $map_js, "' preview'" ) && str_contains( $map_js, 'var selected = committedPoint' ) && str_contains( $map_js, 'var active = previewed' ), 'Preview row must be highlighted, while selected class must depend on committedPoint.' );
pickup_checkout_assert( str_contains( $map_js, 'var previewLeftVisiblePoints = previewPoint && !matchingPreviewPoint' ) && str_contains( $map_js, 'previewPoint = null' ), 'renderMarkers must clear previewPoint only when no code/postcode match exists in the visible list.' );
pickup_checkout_assert( str_contains( $map_js, 'if (!previewPoint && committedPoint)' ) && str_contains( $map_js, 'previewPoint = matchingPoint(committedPoint, visiblePoints)' ) && str_contains( $map_js, 'activePointId: previewPoint ? pointId(previewPoint) : null' ), 'Visible committedPoint must become previewPoint, and active marker id must come only from previewPoint.' );
pickup_checkout_assert( str_contains( $map_js, 'provider.openPointPopup(point, renderPointPopup(point, true)' ) && str_contains( $map_js, 'card.textContent = selectedSummary(point)' ), 'Selected state must update popup HTML and compact status card.' );
pickup_checkout_assert( str_contains( $map_js, 'var popupManuallyClosed = false' ) && str_contains( $map_js, 'popupManuallyClosed = false' ) && str_contains( $map_js, 'if (provider.openPointPopup && !popupManuallyClosed)' ), 'Preview and initial selected restore must open popups only while manual close is not set.' );
pickup_checkout_assert( str_contains( $map_js, 'function markPopupManuallyClosed(source)' ) && str_contains( $map_js, 'popupManuallyClosed = true' ) && str_contains( $map_js, "provider.onPopupClose(function () { markPopupManuallyClosed('popup_close'); });" ), 'Popup close must mark popupManuallyClosed only for auto-open restoration.' );
pickup_checkout_assert( ! str_contains( $map_js, 'data-wdc-pickup-list-select' ) && ! str_contains( $map_js, 'wdc-pickup-list__actions' ), 'List rows must not render per-row select buttons.' );
pickup_checkout_assert( str_contains( $map_js, 'data-wdc-pickup-list-confirm' ) && str_contains( $map_js, 'createListSelectButton(list)' ) && str_contains( $map_js, 'listSelectButton.disabled = true' ), 'Map list must render a single disabled footer select button.' );
pickup_checkout_assert( str_contains( $map_js, 'if (previewPoint && committedPoint && pointId(previewPoint) === pointId(committedPoint))' ) && str_contains( $map_js, 'listSelectButton.disabled = false' ) && str_contains( $map_js, "listSelectButton.textContent = 'Выбрать этот пункт'" ), 'Preview point must enable the shared list footer button unless it is already committed.' );
pickup_checkout_assert( str_contains( $map_js, "commit(previewPoint, { focus: false, ensureVisible: true });" ), 'Shared list footer button must commit previewPoint and dispatch wdc:point-selected through commit().' );
pickup_checkout_assert( str_contains( $modal_js, 'data-wdc-confirm' ) && str_contains( $map_css, '.wdc-pickup-modal__footer' ) && str_contains( $map_css, 'clip-path: inset(50%)' ), 'Fallback modal confirm button must remain available for checkout events but be visually hidden.' );
pickup_checkout_assert( str_contains( $modal_js, 'data-wdc-card aria-live="polite"' ) && str_contains( $map_css, '.wdc-pickup-modal__card' ) && str_contains( $map_css, 'clip: rect(0 0 0 0)' ), 'Compact status card must be aria-live only and not render as visible duplicate text under the list.' );
pickup_checkout_assert( str_contains( $map_js, 'wdc-pickup-list-footer__select' ) && str_contains( $map_js, 'data-wdc-pickup-list-confirm' ), 'The visible list area must expose the single shared footer select button.' );
pickup_checkout_assert( str_contains( $map_css, '.wdc-pickup-popup__select' ) && str_contains( $map_css, '.wdc-pickup-list-footer__select' ) && str_contains( $map_css, 'border-radius: 10px' ) && str_contains( $map_css, 'min-height: 40px' ) && str_contains( $map_css, 'min-height: 42px' ), 'Popup and list select buttons must use scoped rounded styling.' );
pickup_checkout_assert( str_contains( $map_js, 'provider.openPointPopup(previewPoint, renderPointPopup(previewPoint' ) && str_contains( $map_js, 'if (provider.openPointPopup && !popupManuallyClosed)' ) && ! str_contains( $map_js, "\n\t\t\tcommittedPoint = null" ), 'renderMarkers must reopen the preview popup only when not manually closed and must not reset committedPoint when it leaves the bbox.' );
pickup_checkout_assert( str_contains( $map_js, 'if (suppressNextMapClick)' ) && str_contains( $map_js, "markPopupManuallyClosed('map_click')" ) && str_contains( $map_js, "if (source === 'map_click' && provider.closePopup)" ) && str_contains( $map_js, 'provider.closePopup()' ), 'Map click must mark manual close and explicitly close popup, while marker clicks must not be closed by the same click event.' );
pickup_checkout_assert( str_contains( $map_js, 'scrollListRowIntoView(point)' ) && str_contains( $map_js, 'function scrollContainerForList(start)' ) && str_contains( $map_js, 'row.getBoundingClientRect()' ) && str_contains( $map_js, 'container.getBoundingClientRect()' ), 'Marker/list preview must scroll the active row inside the list container using rect-relative coordinates.' );
pickup_checkout_assert( str_contains( $map_js, 'rowRect.top - containerRect.top + container.scrollTop' ) && str_contains( $map_js, 'rowRect.bottom - containerRect.top + container.scrollTop' ) && str_contains( $map_js, 'container.scrollTo({ top: nextTop, behavior: \'smooth\' })' ) && str_contains( $map_js, 'container.scrollTop = nextTop' ) && str_contains( $map_js, 'nextTop === null' ), 'List scroll must update only the scroll container and leave fully visible rows alone.' );
pickup_checkout_assert( ! str_contains( $map_js, 'scrollIntoView' ), 'List row scrolling must not call scrollIntoView because it can scroll the checkout page.' );
pickup_checkout_assert( str_contains( $map_css, '--wdc-map-primary: #1e9af0' ) && str_contains( $map_css, '--wdc-map-active: #e02424' ) && str_contains( $map_css, '.wdc-map-marker-pin.is-active' ) && str_contains( $map_css, 'background: var(--wdc-map-active, #e02424)' ), 'Normal markers must stay blue while active/preview markers become red.' );
pickup_checkout_assert( str_contains( $map_css, '.wdc-map-cluster' ) && str_contains( $map_css, 'border: 6px solid var(--wdc-map-primary, #1e9af0)' ) && ! str_contains( $map_css, 'committed' ), 'Clusters must remain blue and committed must not introduce a separate marker color.' );
pickup_checkout_assert( str_contains( $map_js, 'copy._wdcTypeLabel = pointTypeLabel(copy)' ) && str_contains( $map_js, 'config.label || defaultPointTypeConfig(type).label' ), 'Frontend must derive list/balloon label from label only.' );
pickup_checkout_assert( str_contains( $map_js, 'if (hasInitialCoordinates)' ) && str_contains( $map_js, 'loadBounds(bboxAround(initialLat, initialLng))' ), 'Map JS must load bbox immediately when initial coordinates exist.' );
pickup_checkout_assert( str_contains( $map_js, '55.0302' ) && str_contains( $map_js, '82.9204' ) && ! str_contains( $map_js, '} else if (!hasInitialQuery) {' ), 'Map JS must always set a safe fallback center when initial coordinates are missing.' );
pickup_checkout_assert( str_contains( $map_js, 'else if (hasInitialQuery)' ) && str_contains( $map_js, 'initialSearch(String(context.query))' ), 'Map JS must run initial search before bbox loading when only an initial query exists.' );
pickup_checkout_assert( ! str_contains( $map_js, "loadBounds();\n\t\t\tif (!hasInitialCoordinates && context.query)" ), 'Map JS must not load the Novosibirsk bbox before initial query search.' );
pickup_checkout_assert( str_contains( $map_js, 'hasPreloadedPoints = preloadedPoints.length > 0' ) && str_contains( $map_js, 'renderMarkers(preloadedPoints' ), 'Map JS must render preloaded points immediately.' );
pickup_checkout_assert( str_contains( $map_js, 'if (hasPreloadedPoints)' ) && str_contains( $map_js, 'return;' ), 'Preloaded startup must invalidate map size without calling initial loadBounds.' );
pickup_checkout_assert( str_contains( $map_js, 'onBoundsChange' ) && str_contains( $map_js, 'loadBounds(bbox)' ), 'Manual provider bounds changes must still call loadBounds after open.' );
pickup_checkout_assert( str_contains( $map_js, 'centerLat' ) && str_contains( $map_js, 'centerLng' ), 'Map JS must support preloaded map center coordinates.' );
$leaflet_provider_js = file_get_contents( $root . '/assets/frontend/pickup-map/providers/wdc-map-provider-leaflet.js' ) ?: '';
$yandex_provider_js = file_get_contents( $root . '/assets/frontend/pickup-map/providers/wdc-map-provider-yandex.js' ) ?: '';
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'window.L.map' ) && str_contains( $leaflet_provider_js, "map.on('moveend zoomend', boundsChanged)" ) && str_contains( $leaflet_provider_js, 'pointClickCallback(point)' ), 'Leaflet provider must keep map, bbox loading, and marker click flow.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'window.L.DomEvent.preventDefault(event.originalEvent)' ) && str_contains( $leaflet_provider_js, 'window.L.DomEvent.stopPropagation(event.originalEvent)' ) && str_contains( $leaflet_provider_js, 'window.L.DomEvent.stop(event.originalEvent)' ), 'Leaflet active marker clicks must not bubble into the map close path before reopening the popup.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'openPointPopup: function (point, html, options)' ) && str_contains( $leaflet_provider_js, 'popupState = { pointId: id, html: html, options: options || {} }' ) && str_contains( $leaflet_provider_js, 'if (popupState.options && popupState.options.forceReopen)' ) && str_contains( $leaflet_provider_js, "debugLog('leaflet popup force reopen')" ), 'Leaflet openPointPopup must retain forceReopen in popup state and log the gated reopen path.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'marker.closePopup();' ) && str_contains( $leaflet_provider_js, 'marker.unbindPopup();' ) && str_contains( $leaflet_provider_js, 'marker.bindPopup(popupState.html' ) && str_contains( $leaflet_provider_js, 'marker.openPopup();' ), 'Leaflet force reopen must close, unbind, rebind, and reopen the marker popup.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'window.setTimeout(function () { suppressPopupClose = false; }, 0);' ) && str_contains( $leaflet_provider_js, "debugLog('leaflet popup close')" ) && str_contains( $leaflet_provider_js, 'refreshActiveMarkerAfterPopupClose();' ), 'Leaflet popup reopen must suppress close events through the reopen tick and refresh active state after X-close.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'function refreshActiveMarkerAfterPopupClose()' ) && str_contains( $leaflet_provider_js, 'markerById[id] !== marker' ) && str_contains( $leaflet_provider_js, 'updateActiveMarkers();' ), 'Leaflet popupclose must not permanently block active marker clicks and must keep the marker active/red.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, "debugLog('leaflet marker click')" ) && str_contains( $leaflet_provider_js, 'pointClickCallback(point);' ), 'Leaflet marker click after popupclose must still call the WDC point click flow.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'attributionControl: false' ), 'Leaflet provider must disable the standard attribution control.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'window.L.divIcon' ) && str_contains( $leaflet_provider_js, 'wdc-map-marker-pin' ) && str_contains( $leaflet_provider_js, 'wdc-map-marker-pin__inner"></span>' ) && str_contains( $leaflet_provider_js, 'wdc-map-marker-pin__tail' ) && str_contains( $leaflet_provider_js, 'setActivePoint' ) && str_contains( $leaflet_provider_js, 'focusPoint' ), 'Leaflet provider must support textless custom pin markers and active/focus abstraction.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'renderSearchMarker' ) && str_contains( $leaflet_provider_js, 'wdc-map-search-pin--push' ) && str_contains( $leaflet_provider_js, 'wdc-map-search-pin__head' ) && str_contains( $leaflet_provider_js, 'wdc-map-search-pin__needle' ) && ! str_contains( $leaflet_provider_js, 'wdc-map-marker-pin--search' ), 'Leaflet search marker must use the separate push-pin layout, not the pickup marker class.' );
pickup_checkout_assert( ! str_contains( $leaflet_provider_js, 'renderUserLocationMarker' ) && ! str_contains( $leaflet_provider_js, 'wdc-map-user-marker' ) && ! str_contains( $leaflet_provider_js, 'Вы здесь' ) && str_contains( $leaflet_provider_js, 'interactive: false' ), 'Leaflet provider must render geolocation through the non-interactive search push-pin instead of the old blue user marker.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, "map.createPane('wdcSearchMarkerPane')" ) && str_contains( $leaflet_provider_js, "map.getPane('wdcSearchMarkerPane').style.zIndex = 550" ), 'Leaflet provider must create a dedicated lower pane for the search marker.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, "pane: 'wdcSearchMarkerPane'" ) && str_contains( $leaflet_provider_js, 'interactive: false' ) && str_contains( $leaflet_provider_js, 'zIndexOffset: -100' ), 'Leaflet search marker must use the lower pane, stay non-clickable, and keep a negative z-index offset.' );
pickup_checkout_assert( 1 === substr_count( $leaflet_provider_js, "pane: 'wdcSearchMarkerPane'" ), 'Leaflet pickup markers must stay in the default marker pane, not the search marker pane.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'searchMarkerShift' ) && str_contains( $leaflet_provider_js, 'is-overlapping is-shift-' ) && str_contains( $leaflet_provider_js, 'latLngToContainerPoint' ), 'Leaflet search marker must compute overlap and apply a visual shift class.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'searchMarkerLayout' ) && str_contains( $yandex_provider_js, 'wdc-map-search-pin--push' ) && str_contains( $yandex_provider_js, 'wdc-map-search-pin__head' ) && str_contains( $yandex_provider_js, 'wdc-map-search-pin__needle' ) && str_contains( $yandex_provider_js, 'wdcShift' ), 'Yandex search marker must use the separate push-pin layout/class and not become a pickup marker.' );
pickup_checkout_assert( ! str_contains( $yandex_provider_js, 'userMarkerLayout' ) && ! str_contains( $yandex_provider_js, 'renderUserLocationMarker' ) && ! str_contains( $yandex_provider_js, 'wdc-map-user-marker' ) && ! str_contains( $yandex_provider_js, 'Вы здесь' ), 'Yandex provider must render geolocation through the search push-pin instead of the old blue user marker.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'searchMarkerShift' ) && str_contains( $yandex_provider_js, 'is-overlapping is-shift-right' ) && str_contains( $yandex_provider_js, 'zIndex: 10' ), 'Yandex search marker must shift on close overlap and stay below pickup markers.' );
pickup_checkout_assert( str_contains( $map_css, '.wdc-map-search-pin--push .wdc-map-search-pin__head' ) && str_contains( $map_css, '.wdc-map-search-pin--push .wdc-map-search-pin__needle' ) && str_contains( $map_css, '#e53935' ) && str_contains( $map_css, '.wdc-map-search-icon' ) && str_contains( $map_css, 'z-index: 0 !important' ) && str_contains( $map_css, 'translateX(38px)' ) && str_contains( $map_css, 'translateX(-38px)' ), 'CSS must render a distinct red push pin with lower z-index and full marker-width shift classes.' );
pickup_checkout_assert( ! str_contains( $map_css, 'wdc-map-search-pin__dot' ) && ! str_contains( $map_css, 'rotate(-45deg)' ), 'Old teardrop search marker layout must be removed.' );
pickup_checkout_assert( ! str_contains( $leaflet_provider_js, 'ОПС' ) && ! str_contains( $leaflet_provider_js, 'ПВЗ' ) && ! str_contains( $leaflet_provider_js, 'Почтомат' ), 'Leaflet marker HTML must not render type text.' );
pickup_checkout_assert( ! str_contains( $leaflet_provider_js, "marker.bindPopup(escapeHtml(point.address || ''))" ), 'Leaflet marker creation must not bind a temporary address popup.' );
pickup_checkout_assert( ! str_contains( $leaflet_provider_js, 'committed' ) && str_contains( $leaflet_provider_js, "(active ? ' is-active' : '')" ), 'Leaflet marker provider must expose only the active marker class, without committed-specific state.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'clusterPoints(points)' ) && str_contains( $leaflet_provider_js, 'wdc-map-cluster' ) && str_contains( $leaflet_provider_js, 'wdc-map-cluster__inner' ) && str_contains( $leaflet_provider_js, 'cluster.points.length' ) && str_contains( $leaflet_provider_js, 'map.fitBounds' ), 'Leaflet provider must render grid clusters with counts and zoom/focus on click.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'openPointPopup' ) && str_contains( $leaflet_provider_js, 'bindPopup' ) && str_contains( $leaflet_provider_js, 'autoPan: true' ) && str_contains( $leaflet_provider_js, 'keepInView: true' ) && str_contains( $leaflet_provider_js, 'autoPanPadding: [24, 24]' ), 'Leaflet popup options must auto-pan softly to keep the balloon visible.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'activePointId = popupState.pointId' ) && str_contains( $leaflet_provider_js, 'updateActiveMarkers();' ) && str_contains( $leaflet_provider_js, 'popupAnchor: [0, -58]' ) && str_contains( $leaflet_provider_js, 'offset: window.L.point(0, -5)' ), 'Leaflet provider must keep the active marker visible/red and anchor popup above it.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'onPopupSelect' ) && str_contains( $leaflet_provider_js, 'onPopupClose' ) && str_contains( $leaflet_provider_js, 'suppressPopupClose' ) && str_contains( $leaflet_provider_js, "map.on('popupclose', popupClosed)" ), 'Leaflet provider must report manual popup close while suppressing close events during rerender.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'var maxClusterZoom = 18' ) && str_contains( $leaflet_provider_js, 'var clusterCellSize = 128' ) && str_contains( $leaflet_provider_js, 'map.getZoom() >= maxClusterZoom' ) && str_contains( $leaflet_provider_js, 'return { points: [point]' ), 'Leaflet grid clustering must use wider cells and disable clusters only on zoom 18+.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'var lastPoints = []' ) && str_contains( $leaflet_provider_js, 'lastPoints = Array.isArray(points) ? points.slice() : []' ) && str_contains( $leaflet_provider_js, "map.on('zoomend', rebuildClusters)" ), 'Leaflet provider must retain the full marker dataset and rebuild its grid clusters on every zoomend.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'function rebuildClusters()' ) && str_contains( $leaflet_provider_js, 'clearRenderedMarkers();' ) && str_contains( $leaflet_provider_js, 'renderClustered(lastPoints);' ) && str_contains( $leaflet_provider_js, 'clusterPoints(points)' ) && str_contains( $leaflet_provider_js, 'map.latLngToLayerPoint' ), 'Leaflet zoom rebuild must clear old layers and rerun clusterPoints against the current zoom without replacing the retained point dataset.' );
$leaflet_rebuild_source = substr( $leaflet_provider_js, strpos( $leaflet_provider_js, 'function rebuildClusters()' ), strpos( $leaflet_provider_js, 'function renderClustered(points)' ) - strpos( $leaflet_provider_js, 'function rebuildClusters()' ) );
pickup_checkout_assert( str_contains( $leaflet_rebuild_source, 'updateActiveMarkers();' ) && ! str_contains( $leaflet_rebuild_source, 'activePointId =' ), 'Leaflet zoom rebuild must preserve activePointId and reapply the active marker style.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'var lastSearchMarker = null' ) && str_contains( $leaflet_provider_js, 'lastSearchMarker = options.searchMarker || null' ) && str_contains( $leaflet_rebuild_source, 'renderSearchMarker(lastSearchMarker);' ), 'Leaflet zoom rebuild must preserve and rerender the search marker.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'var popupState = null' ) && str_contains( $leaflet_provider_js, 'popupState = { pointId: id, html: html, options: options || {} }' ) && str_contains( $leaflet_rebuild_source, 'openStoredPopup();' ), 'Leaflet zoom rebuild must retain popup state and reopen it when the active point is rendered as a single marker.' );
pickup_checkout_assert( ! str_contains( $leaflet_rebuild_source, 'onBoundsChange' ) && ! str_contains( $leaflet_rebuild_source, 'fetch(' ) && ! str_contains( $leaflet_rebuild_source, 'WDCPickupApi' ), 'Leaflet cluster rebuild must use local state and must not initiate a REST request.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'api-maps.yandex.ru/2.1/' ) && str_contains( $yandex_provider_js, 'new ymaps.Map' ) && str_contains( $yandex_provider_js, 'new ymapsApi.Placemark' ) && str_contains( $yandex_provider_js, 'pointClickCallback(point)' ), 'Yandex provider must load API, create map, render placemarks, and pass marker clicks.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, "placemark.events.add('click', function (event)" ) && str_contains( $yandex_provider_js, 'event.stopPropagation();' ) && str_contains( $yandex_provider_js, 'pointClickCallback(point)' ), 'Yandex active marker clicks must not bubble into the map close path before reopening the balloon.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'placemark.balloon.open();' ) && str_contains( $yandex_provider_js, 'window.setTimeout(function () { suppressPopupClose = false; }, 0);' ), 'Yandex balloon reopen must suppress close events through the reopen tick so the X-close state cannot block marker click.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'function refreshActivePlacemarkAfterBalloonClose()' ) && str_contains( $yandex_provider_js, 'refreshActivePlacemarkAfterBalloonClose();' ) && str_contains( $yandex_provider_js, "debugLog('yandex balloon close')" ), 'Yandex provider must handle balloonclose by restoring active placemark clickability.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'activePointId = id;' ) && str_contains( $yandex_provider_js, 'updateActivePlacemarks();' ) && str_contains( $yandex_provider_js, "debugLog('yandex active placemark refreshed after balloon close')" ), 'Yandex balloonclose refresh must keep active marker state.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'collection.remove(oldPlacemark);' ) && str_contains( $yandex_provider_js, 'map.geoObjects.remove(oldPlacemark);' ) && str_contains( $yandex_provider_js, 'var replacement = createPickupPlacemark(point, id);' ) && str_contains( $yandex_provider_js, 'addPickupPlacemark(replacement, map.getZoom() < maxClusterZoom);' ), 'Yandex active placemark must be recreated after X-close to escape a stale events pane.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'placemarkById[id] = replacement;' ) && str_contains( $yandex_provider_js, 'pointById[id] = point;' ) && str_contains( $yandex_provider_js, "debugLog('yandex active placemark recreated after balloon close')" ), 'Yandex recreated placemark must keep placemarkById and pointById consistent.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, "debugLog('yandex placemark click')" ) && str_contains( $yandex_provider_js, 'pointClickCallback(point);' ), 'Yandex marker click after balloonclose must still call the WDC point click flow.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'new ymaps.Clusterer' ) && str_contains( $yandex_provider_js, 'clusterIconLayout' ) && str_contains( $yandex_provider_js, 'wdc-map-cluster__inner' ) && str_contains( $yandex_provider_js, 'properties.geoObjects.length' ) && ! str_contains( $yandex_provider_js, 'islands#' ), 'Yandex provider must use custom Clusterer HTML and avoid standard islands marker presets.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'templateLayoutFactory.createClass' ) && str_contains( $yandex_provider_js, 'wdc-map-marker-pin__inner"></span>' ) && str_contains( $yandex_provider_js, 'wdc-map-marker-pin__tail' ), 'Yandex marker custom layout must render textless markers.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'openPointPopup' ) && str_contains( $yandex_provider_js, "placemark.properties.set('balloonContent', html)" ) && str_contains( $yandex_provider_js, 'balloonAutoPan: true' ) && str_contains( $yandex_provider_js, 'balloonAutoPanMargin: 24' ), 'Yandex balloons must auto-pan softly when opened.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'activePointId = id' ) && str_contains( $yandex_provider_js, 'updateActivePlacemarks();' ) && str_contains( $yandex_provider_js, 'balloonOffset: [0, -58]' ) && str_contains( $yandex_provider_js, 'hideIconOnBalloonOpen: false' ), 'Yandex provider must keep active placemark visible/red and open the balloon above it.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'onPopupSelect' ) && str_contains( $yandex_provider_js, 'onPopupClose' ) && str_contains( $yandex_provider_js, 'suppressPopupClose' ) && str_contains( $yandex_provider_js, "placemark.events.add('balloonclose', popupClosed)" ), 'Yandex provider must report manual balloon close while suppressing close events during rerender.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'var maxClusterZoom = 18' ) && str_contains( $yandex_provider_js, 'gridSize: 128' ) && str_contains( $yandex_provider_js, 'var useClusterer = map.getZoom() < maxClusterZoom' ) && str_contains( $yandex_provider_js, 'map.geoObjects.add(placemark)' ), 'Yandex provider must cluster through zoom 17 and bypass Clusterer only on zoom 18+.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'setActivePoint' ) && str_contains( $yandex_provider_js, 'focusPoint' ) && str_contains( $yandex_provider_js, 'updateActivePlacemarks' ), 'Yandex provider must support active/focus abstraction.' );
pickup_checkout_assert( ! str_contains( $yandex_provider_js, 'committed' ) && str_contains( $yandex_provider_js, "activePointId === id ? 'is-active' : ''" ), 'Yandex marker provider must expose only the active marker class, without committed-specific state.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'var pendingCenter = normalizeCenter' ) && str_contains( $yandex_provider_js, 'pendingCenterChanged = true' ) && str_contains( $yandex_provider_js, 'map.setCenter([pendingCenter.lat, pendingCenter.lng]' ), 'Yandex setCenter before API readiness must be stored and applied after map creation.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'if (pendingPoints.length || pendingSearchMarker)' ) && str_contains( $yandex_provider_js, 'renderMarkers(pendingPoints)' ), 'Yandex renderMarkers before readiness must render queued points or origin marker after map creation.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'pendingPoints = []' ) && str_contains( $yandex_provider_js, 'pendingSearchMarker = null' ) && str_contains( $yandex_provider_js, 'clearMarkers: function ()' ), 'Yandex clearMarkers before readiness must clear queued points and origin marker.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'fitToViewport();' ) && str_contains( $yandex_provider_js, 'boundsChanged();' ), 'Yandex provider must fit viewport and trigger bounds loading after readiness.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'console.warn' ) && str_contains( $yandex_provider_js, 'debugEnabled()' ), 'Yandex API load failures must stay non-fatal and warn only in debug.' );
pickup_checkout_assert( str_contains( $map_js, 'yandexApiKeyPresent' ) && str_contains( $map_js, 'yandexApiKeyMissing' ) && str_contains( $map_js, 'API key' ), 'Yandex without API key must show a clear modal error and skip provider creation.' );
pickup_checkout_assert( str_contains( $api_js, 'searchInitial' ) && str_contains( $api_js, "params.set('limit', '10')" ), 'Pickup API must expose a small-limit initial search.' );
pickup_checkout_assert( str_contains( $api_js, 'Array.isArray(data)' ), 'Pickup API must normalize point responses to arrays.' );
pickup_checkout_assert( str_contains( $map_checkout_source, 'selected_city()' ) && str_contains( $map_checkout_source, 'fallback_city()' ), 'Initial map context must use selected city/session fallback query sources.' );
pickup_checkout_assert( str_contains( $map_checkout_source, 'has_usable_coordinates' ) && str_contains( $map_checkout_source, "'RU'" ), 'Initial map context must validate coordinates and only pass RU context.' );

echo "Pickup checkout smoke test passed.\n";
