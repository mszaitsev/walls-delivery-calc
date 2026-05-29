<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Checkout\WooCommerce\PickupMapCheckout;
use WallsShop\WDC\Admin\SettingsAdminPage;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Pickup\Rest\CheckoutPickupPointRestController;
use WallsShop\WDC\Pickup\Rest\PickupPointsRestController;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

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
$state_route = array_values( array_filter( $GLOBALS['wdc_pickup_checkout_routes'], static fn( array $route ): bool => '/checkout/state' === $route['route'] ) )[0] ?? array();
pickup_checkout_assert( is_array( $state_route['args'] ?? null ) && is_callable( $state_route['args']['permission_callback'] ?? null ), 'checkout state GET must register a nonce permission callback.' );
pickup_checkout_assert( false === $state_controller->check_nonce( new WdcPickupCheckoutRequest( array() ) ), 'checkout state GET without nonce must be forbidden.' );
pickup_checkout_assert( true === $state_controller->check_nonce( new WdcPickupCheckoutRequest( array(), array( 'X-WP-Nonce' => 'nonce' ) ) ), 'checkout state GET with nonce must be authorized.' );

$saved = $state_controller->save( new WdcPickupCheckoutRequest( array( 'point_id' => 10, 'shipping_method_id' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_checkout_assert( '630001-a' === $saved['pickup_point']['point_code'], 'checkout state save must return selected point.' );
pickup_checkout_assert( '630001-a' === $session->checkout_pickup_point()['point_code'], 'selection must be stored in WC session wdc_pickup_point.' );
pickup_checkout_assert( '630001-a' === $state_controller->state()['pickup_point']['point_code'], 'checkout state GET must return current pickup selection.' );
$session->save_city_context( array( 'lat' => 56.0106, 'lng' => 92.8526, 'postcode' => '660000', 'display_name' => 'Красноярск', 'region_name' => 'Красноярский край', 'country_code' => 'RU' ) );
$state = $state_controller->state();
pickup_checkout_assert( 56.0106 === (float) $state['city_context']['lat'] && 92.8526 === (float) $state['city_context']['lng'], 'checkout state GET must expose enriched city_context lat/lng.' );
pickup_checkout_assert( 'Красноярск' === (string) $state['city_context']['display_name'], 'checkout state GET must expose enriched city display_name.' );

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

$root = dirname( __DIR__, 2 );
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
$ops_type_keys = array_keys( $localized_leaflet['pickupPointTypes']['OPS'] ?? array() );
sort( $ops_type_keys );
pickup_checkout_assert( 'Отделение Почты России' === (string) ( $localized_leaflet['pickupPointTypes']['OPS']['label'] ?? '' ) && array( 'enabled', 'label' ) === $ops_type_keys && true === (bool) ( $localized_leaflet['pickupPointTypes']['PVZ']['enabled'] ?? false ) && true === (bool) ( $localized_leaflet['pickupPointTypes']['APS']['enabled'] ?? false ), 'JS localization must include only pickupPointTypes label/enabled flags.' );
pickup_checkout_assert( '630001-a' === (string) ( $localized_leaflet['initialContext']['selectedPoint']['point_code'] ?? '' ) && 55.01 === (float) ( $localized_leaflet['initialContext']['selectedPoint']['lat'] ?? 0 ), 'Initial context must expose the previously saved pickup point to the map.' );

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
WC()->session->set( 'chosen_shipping_methods', array( 'russian_post_domestic_courier' ) );
pickup_checkout_assert( '630001-a' === $session->pickup_selection()['point_code'], 'selected pickup must survive a shipping method switch in session.' );
WC()->session->set( 'chosen_shipping_methods', array( RussianPostDomesticSettings::PICKUP_SERVICE_KEY ) );
$errors = new WdcPickupCheckoutErrors();
( new CheckoutValidation( $session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
pickup_checkout_assert( array() === $errors->errors, 'validation must pass when pickup selection exists.' );

$settings_source = file_get_contents( $root . '/src/Infrastructure/Settings/SettingsRepository.php' ) ?: '';
pickup_checkout_assert( str_contains( $settings_source, "'pickup_map_provider' => 'leaflet'" ), 'Default pickup map provider must be leaflet.' );
pickup_checkout_assert( str_contains( $settings_source, "'russian_post_domestic_pickup_type_ops_enabled' => true" ) && str_contains( $settings_source, "'russian_post_domestic_pickup_type_pvz_enabled' => true" ) && str_contains( $settings_source, "'russian_post_domestic_pickup_type_aps_enabled' => true" ), 'Default pickup point type settings must enable OPS/PVZ/APS.' );
$point_type_source = file_get_contents( $root . '/src/Pickup/RussianPost/RussianPostPickupPointTypeSettings.php' ) ?: '';
$old_short_key = implode( '_', array( 'marker', 'label' ) );
$old_long_key = implode( '_', array( 'card', 'label' ) );
pickup_checkout_assert( str_contains( $point_type_source, "'label' => 'Отделение Почты России'" ) && str_contains( $point_type_source, "russian_post_domestic_pickup_type_{\$key}_label" ) && str_contains( $point_type_source, "\$result['OPS']['enabled'] = true" ) && ! str_contains( $point_type_source, $old_short_key ) && ! str_contains( $point_type_source, $old_long_key ), 'Pickup type settings must provide only label and auto-enable OPS.' );
$type_settings_values = ( new RussianPostPickupPointTypeSettings( new SettingsRepository() ) )->sanitize_admin_values( array( 'russian_post_domestic_pickup_type_ops_enabled' => '1', 'russian_post_domestic_pickup_type_ops_label' => 'Новое название' ) );
pickup_checkout_assert( 'Новое название' === (string) $type_settings_values['russian_post_domestic_pickup_type_ops_label']['value'] && ! array_key_exists( "russian_post_domestic_pickup_type_ops_{$old_short_key}", $type_settings_values ) && ! array_key_exists( "russian_post_domestic_pickup_type_ops_{$old_long_key}", $type_settings_values ), 'Admin save must keep only enabled and label keys for each type.' );
$settings_admin = new SettingsAdminPage( new SettingsRepository() );
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
$map_checkout_source = file_get_contents( $root . '/src/Checkout/WooCommerce/PickupMapCheckout.php' ) ?: '';
pickup_checkout_assert( str_contains( $map_checkout_source, 'map_provider()' ) && str_contains( $map_checkout_source, 'assets/vendor/leaflet/leaflet.css' ) && str_contains( $map_checkout_source, 'assets/vendor/leaflet/leaflet.js' ), 'Leaflet provider must enqueue Leaflet assets from assets/vendor/leaflet.' );
pickup_checkout_assert( str_contains( $map_checkout_source, 'providers/wdc-map-provider-leaflet.js' ), 'Leaflet provider script must be enqueued.' );
pickup_checkout_assert( str_contains( $map_checkout_source, "if ( 'leaflet' === \$provider )" ) && str_contains( $map_checkout_source, 'providers/wdc-map-provider-yandex.js' ), 'Yandex provider must enqueue from the non-Leaflet branch.' );
pickup_checkout_assert( str_contains( $map_checkout_source, "'yandex' === \$provider && \$this->has_yandex_api_key()" ) && str_contains( $map_checkout_source, "'yandexApiKeyPresent'" ), 'Yandex key must be localized only when Yandex is selected and the key exists.' );
pickup_checkout_assert( str_contains( $map_checkout_source, "'pickupPointTypes'" ) && str_contains( $map_checkout_source, 'pickup_point_types()' ) && str_contains( $map_checkout_source, "'selectedPoint' => \$this->selected_point_context()" ), 'Pickup map checkout must localize pickupPointTypes and selectedPoint.' );
pickup_checkout_assert( str_contains( $map_checkout_source, 'Для Яндекс.Карт не задан API key' ), 'Frontend config must include a clear missing Yandex API key error.' );
pickup_checkout_assert( file_exists( $root . '/assets/vendor/leaflet/leaflet.css' ) && file_exists( $root . '/assets/vendor/leaflet/leaflet.js' ), 'Leaflet assets must exist under assets/vendor/leaflet.' );
$checkout_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-checkout.js' ) ?: '';
$city_selector_js = file_get_contents( $root . '/assets/frontend/checkout-city-selector.js' ) ?: '';
pickup_checkout_assert( ! str_contains( $checkout_js, "input[name^=\"shipping_method\"]')) {\n\t\t\tresetSelection();" ), 'JS must not reset pickup on shipping method change.' );
pickup_checkout_assert( str_contains( $checkout_js, 'billing_postcode' ) && str_contains( $checkout_js, 'shipping_postcode' ), 'JS must reset pickup on city/country/postcode changes.' );
pickup_checkout_assert( str_contains( $checkout_js, 'contextFromFields' ) && str_contains( $checkout_js, 'shipping_city' ) && str_contains( $checkout_js, 'shipping_postcode' ), 'JS must form initial map query from checkout city/postcode.' );
pickup_checkout_assert( str_contains( $checkout_js, 'WDCPickupMap.create' ) && str_contains( $checkout_js, 'initialContext()' ), 'Next modal open must recompute initial context instead of reusing a cached value.' );
pickup_checkout_assert( str_contains( $checkout_js, "confirmButton.addEventListener('wdc:point-selected'" ) && str_contains( $checkout_js, 'savePoint(event.detail || map.selected())' ) && str_contains( $checkout_js, 'function savePoint(point)' ), 'Popup/list selection event must save the pickup point immediately without a second footer click.' );
pickup_checkout_assert( str_contains( $checkout_js, 'window.wdcPickupCheckout.selectedPickupPoint = selectedPoint' ) && str_contains( $checkout_js, 'selectedPoint: config.selectedPoint' ) && str_contains( $checkout_js, 'function normalizeSelectedPoint(point)' ), 'Checkout JS must keep the saved pickup point available for the next map open.' );
pickup_checkout_assert( str_contains( $checkout_js, 'lat: fieldContext.lat || runtimeContext.lat || localizedContext.lat' ) && str_contains( $checkout_js, 'lng: fieldContext.lng || runtimeContext.lng || localizedContext.lng' ), 'Initial context must prefer DOM hidden coordinates, then fresh runtime context, then localized config.' );
pickup_checkout_assert( str_contains( $checkout_js, 'wdc_platform_location_lat' ) && str_contains( $checkout_js, 'wdc_platform_location_lng' ), 'Initial context must read city picker hidden lat/lng fields.' );
pickup_checkout_assert( str_contains( $checkout_js, 'wdc_platform_location_postcode' ) && str_contains( $checkout_js, 'wdc_platform_location_display_name' ), 'Initial context query must use hidden postcode/display_name fields.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var runtimeContext = sameDestination(fieldContext, currentContext)' ) && str_contains( $checkout_js, 'var localizedContext = sameDestination(fieldContext, configContext)' ), 'Initial context must merge coordinates from same-destination current/localized context.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function sameDestination(a, b)' ) && str_contains( $checkout_js, 'aPostcode && bPostcode && aPostcode === bPostcode' ), 'sameDestination must treat matching non-empty postcode as the strongest match.' );
pickup_checkout_assert( str_contains( $checkout_js, 'containsDestinationName(aName, bName)' ) && str_contains( $checkout_js, 'containsDestinationName(bName, aName)' ), 'sameDestination must allow short city display names to match region-qualified display names.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var latSource = validCoordinate(fieldContext.lat, fieldContext.lng)' ) && str_contains( $checkout_js, 'chosen lat/lng source' ), 'Initial context must debug the chosen coordinate source.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var visibleDestinationChanged = !!(visibleCity && hiddenDisplay && normalizeText(visibleCity) !== normalizeText(hiddenDisplay))' ), 'contextFromFields must detect visible city changes against stale hidden display.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var city = visibleDestinationChanged ? visibleCity : (hiddenDisplay || visibleCity)' ), 'contextFromFields must prefer visible city when hidden display belongs to the old destination.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var postcode = visibleDestinationChanged ? (visiblePostcode || hiddenPostcode) : (hiddenPostcode || visiblePostcode)' ), 'contextFromFields must prefer visible postcode when visible city changed.' );
pickup_checkout_assert( str_contains( $checkout_js, 'if (!visibleDestinationChanged && validCoordinate(hiddenLat, hiddenLng))' ), 'contextFromFields must not use old hidden lat/lng when visible city changed.' );
pickup_checkout_assert( str_contains( $checkout_js, 'query: fieldContext.query || runtimeContext.query || localizedContext.query' ), 'Initial context must use hidden/visible query first and localized config only as fallback.' );
pickup_checkout_assert( str_contains( $checkout_js, 'debug(' ) && str_contains( $checkout_js, 'prefetch cache key' ), 'Pickup checkout JS must expose debug context logs behind config.debug.' );
pickup_checkout_assert( str_contains( $city_selector_js, "CustomEvent( 'wdc:location-selected'" ) && str_contains( $city_selector_js, 'display_name: location.display_name || label ||' ), 'City selector must dispatch wdc:location-selected with display_name even when coordinates are empty.' );
pickup_checkout_assert( str_contains( $checkout_js, "document.body.addEventListener('wdc:location-selected'" ) && str_contains( $checkout_js, 'contextFromLocationDetail' ), 'Pickup JS must listen for city selector location events.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var query = [postcode, displayName].filter(Boolean).join' ), 'Location-selected context must create a non-empty city query from postcode/display_name.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function clearPickupSelectionUi()' ) && str_contains( $checkout_js, 'function resetPickupSelectionOnServer()' ), 'Pickup reset must split UI clear and server reset.' );
pickup_checkout_assert( str_contains( $checkout_js, 'updateCurrentContext(context)' ) && str_contains( $checkout_js, 'applyContextToHidden(context)' ) && str_contains( $checkout_js, 'resetPickupSelectionOnServer();' ) && str_contains( $checkout_js, 'clearPickupSelectionUi();' ), 'Location-selected event must keep currentContext while resetting pickup selection.' );
pickup_checkout_assert( ! str_contains( $checkout_js, "debug('wdc:location-selected detail', event.detail || {});\n\t\tinvalidatePrefetch();\n\t\tupdateCurrentContext(context);\n\t\tapplyContextToHidden(context);\n\t\tresetSelection();" ), 'Location-selected event must not call broad resetSelection after setting context.' );
pickup_checkout_assert( str_contains( $checkout_js, 'stateContextMatchesCurrentDestination' ) && str_contains( $checkout_js, 'contextMatches(currentContext, context)' ), 'State refresh must ignore empty or stale old-city context.' );
pickup_checkout_assert( str_contains( $checkout_js, 'initialContext selected source' ), 'Initial context debug must report the selected source.' );
pickup_checkout_assert( str_contains( $checkout_js, 'refreshCheckoutContextOnce(700)' ) && str_contains( $checkout_js, 'Promise.race' ), 'Open modal must briefly wait for checkout state coordinates when context has query but no coordinates.' );
pickup_checkout_assert( str_contains( $checkout_js, 'debug(\'refreshCheckoutContextOnce result\'' ) && str_contains( $checkout_js, 'debug(\'openModal context\'' ), 'Pickup debug logs must include state refresh and open context.' );
pickup_checkout_assert( str_contains( $checkout_js, 'countryBlocked' ) && str_contains( $checkout_js, "country.toUpperCase() !== 'RU'" ), 'Initial context must ignore non-RU checkout destinations.' );
pickup_checkout_assert( str_contains( $checkout_js, 'refreshCheckoutContext' ) && str_contains( $checkout_js, "window.jQuery(document.body).on('updated_checkout'" ), 'updated_checkout must refresh server city_context and then prefetch pickup points.' );
pickup_checkout_assert( str_contains( $checkout_js, 'applyContextToHidden' ) && str_contains( $checkout_js, 'window.WDCPickupApi.state()' ), 'Frontend must update hidden fields from checkout state city_context after DaData enrichment.' );
pickup_checkout_assert( str_contains( $checkout_js, 'prefetchInitialPoints' ) && str_contains( $checkout_js, 'bboxAround' ) && str_contains( $checkout_js, 'searchInitial(context.query' ), 'Frontend must prefetch search to bbox when initial coordinates are unavailable.' );
pickup_checkout_assert( str_contains( $checkout_js, 'isPickupMethodActive' ) && str_contains( $checkout_js, 'russian_post_domestic_pickup' ), 'Prefetch must run only for the active Russian Post pickup method.' );
pickup_checkout_assert( str_contains( $checkout_js, 'hasPickupBlock' ) && str_contains( $checkout_js, '[data-wdc-pickup-checkout]' ), 'Prefetch must require a pickup checkout block.' );
pickup_checkout_assert( str_contains( $checkout_js, 'prefetchController.abort()' ) && str_contains( $checkout_js, 'setTimeout(prefetchInitialPoints, 400)' ), 'Prefetch must debounce and abort stale requests.' );
pickup_checkout_assert( str_contains( $checkout_js, 'prefetchCache = null' ) && str_contains( $checkout_js, 'invalidatePrefetch' ), 'Prefetch cache must invalidate on destination changes.' );
pickup_checkout_assert( str_contains( $checkout_js, 'var context = withPrefetch(resolvedContext)' ) && str_contains( $checkout_js, 'preloadedPoints: prefetchCache.points' ), 'Open modal must pass cached preloaded points to the map.' );
$map_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-map.js' ) ?: '';
$modal_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-modal.js' ) ?: '';
$map_css = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-map.css' ) ?: '';
$api_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-api.js' ) ?: '';
pickup_checkout_assert( ! str_contains( $modal_js, 'autofocus' ) && ! str_contains( $checkout_js, 'search.focus' ) && str_contains( $modal_js, "button[data-wdc-close]" ), 'Opening the pickup modal must not autofocus the address search input.' );
pickup_checkout_assert( str_contains( $modal_js, 'wdc-pickup-search__icon' ) && str_contains( $modal_js, 'aria-hidden="true">🔍</span>' ), 'Pickup modal search template must render a decorative magnifier icon.' );
pickup_checkout_assert( str_contains( $modal_js, 'data-wdc-search-submit' ) && str_contains( $modal_js, 'Искать адрес' ), 'Pickup modal search template must render an explicit address search button.' );
pickup_checkout_assert( str_contains( $checkout_js, 'function runAddressSearch()' ) && str_contains( $checkout_js, "searchSubmit.addEventListener('click', runAddressSearch)" ), 'Search submit button must call the shared map.search flow.' );
pickup_checkout_assert( str_contains( $checkout_js, "search.addEventListener('keydown'" ) && str_contains( $checkout_js, "event.key === 'Enter'" ) && str_contains( $checkout_js, 'runAddressSearch();' ), 'Enter in the search input must call the shared map.search flow.' );
$input_handler_pos = strpos( $checkout_js, "search.addEventListener('input'" );
$confirm_handler_pos = strpos( $checkout_js, "confirmButton.addEventListener('wdc:point-selected'" );
pickup_checkout_assert( false !== $input_handler_pos && false !== $confirm_handler_pos && ! str_contains( substr( $checkout_js, $input_handler_pos, $confirm_handler_pos - $input_handler_pos ), 'map.search' ), 'Input event must only filter postcode-only text and must not launch search.' );
pickup_checkout_assert( str_contains( $map_js, 'WDCPickupMapProviders' ) && str_contains( $map_js, 'providerFactory.create' ), 'wdc-pickup-map.js must use provider abstraction.' );
pickup_checkout_assert( ! str_contains( $map_js, 'window.L' ) && ! str_contains( $map_js, 'L.map' ), 'wdc-pickup-map.js must not use Leaflet directly.' );
pickup_checkout_assert( str_contains( $map_js, 'data-wdc-point-id' ) && str_contains( $map_js, 'wdc-pickup-list__item' ) && str_contains( $map_js, 'LIST_LIMIT = 100' ), 'Map modal must render a capped pickup point list after bbox load.' );
pickup_checkout_assert( str_contains( $map_js, 'distanceMeters(distanceOrigin.lat, distanceOrigin.lng' ) && str_contains( $map_js, 'formatDistance' ) && str_contains( $map_js, "' м'" ) && str_contains( $map_js, "' км'" ), 'Map list must sort by active distance origin and format meters/kilometers.' );
pickup_checkout_assert( str_contains( $api_js, "points/address-search?" ) && str_contains( $map_js, 'window.WDCPickupApi.addressSearch(query, context' ), 'Pickup address search must use the dedicated REST endpoint.' );
pickup_checkout_assert( str_contains( $map_js, 'searchMarker: searchAddress' ) && str_contains( $map_js, 'wdc-pickup-list__found' ) && str_contains( $map_js, 'Найден адрес:' ) && str_contains( $map_js, 'Ближайший ПВЗ:' ), 'Successful address search must render a search marker and found-address list block.' );
pickup_checkout_assert( str_contains( $map_js, 'function applySearchResult(result)' ) && str_contains( $map_js, 'provider.renderMarkers(visiblePoints' ), 'Successful addressSearch must apply the search marker without replacing visiblePoints from result.points.' );
pickup_checkout_assert( str_contains( $map_js, 'loadBounds(bboxAround(searchAddress.lat, searchAddress.lng), {' ) && str_contains( $map_js, 'force: true' ) && str_contains( $map_js, 'previewNearest: true' ), 'Frontend must force a bbox load around the found address after addressSearch.' );
pickup_checkout_assert( str_contains( $map_js, 'if (!options.force && suppressNextMoveLoad)' ), 'Forced bbox loads must bypass suppressNextMoveLoad while automatic move loads can still be suppressed.' );
pickup_checkout_assert( ! str_contains( $map_js, 'renderMarkers(Array.isArray(result.points)' ), 'Successful addressSearch must not render result.points as the final visiblePoints state.' );
pickup_checkout_assert( str_contains( $map_js, 'renderMarkers(points, labels.empty || \'\')' ) && str_contains( $map_js, 'if (options.previewNearest && visiblePoints[0])' ), 'Bbox response after search must replace visiblePoints and preview the nearest point.' );
pickup_checkout_assert( ! str_contains( $map_js, 'visiblePoints.push(searchAddress)' ) && ! str_contains( $map_js, 'points.push(searchAddress)' ), 'Search marker must stay separate from pickup point list data.' );
pickup_checkout_assert( str_contains( $map_js, 'setPostcodeOnlyMode' ) && str_contains( $map_js, 'Сейчас работает поиск только по почтовому индексу' ) && str_contains( $map_js, "input.value.replace(/\\D+/g, '').slice(0, 6)" ), 'Frontend must switch the search input to postcode-only mode when DaData limits are exhausted.' );
pickup_checkout_assert( str_contains( $map_css, '.wdc-pickup-search' ) && str_contains( $map_css, '.wdc-pickup-search__icon' ) && str_contains( $map_css, '.wdc-pickup-search__button' ), 'Pickup search row must style icon, input, and submit button.' );
pickup_checkout_assert( str_contains( $map_js, 'var aKey = String(a.postal_code || a.postcode || \'\') + \'|\'' ) && str_contains( $map_js, 'return a._wdcOrder - b._wdcOrder' ), 'Map list must use stable postcode/address ordering without coordinates.' );
pickup_checkout_assert( str_contains( $map_js, "text === '0.000000'" ) && str_contains( $map_js, 'cleanDescription(point.description)' ), 'Selected card must suppress technical zero descriptions.' );
pickup_checkout_assert( str_contains( $map_js, 'provider.setActivePoint(pointId(point))' ) && str_contains( $map_js, 'provider.focusPoint(point)' ), 'Map and list preview/commit must synchronize active marker and provider focus.' );
pickup_checkout_assert( str_contains( $map_js, "' active'" ) && str_contains( $map_js, "' selected'" ), 'Preview rows must receive active/preview classes and committed rows must receive selected class.' );
pickup_checkout_assert( str_contains( $map_js, 'var initialSelectedPoint = normalizeInitialSelectedPoint(context.selectedPoint || context.selectedPickupPoint)' ) && str_contains( $map_js, 'var previewPoint = initialSelectedPoint' ) && str_contains( $map_js, 'var committedPoint = initialSelectedPoint' ) && str_contains( $map_js, 'selected: function () { return committedPoint; }' ), 'Map JS must initialize previewPoint and committedPoint from the saved checkout point while keeping them separate.' );
pickup_checkout_assert( str_contains( $map_js, 'function preview(point, options)' ) && str_contains( $map_js, 'previewPoint = point' ) && str_contains( $map_js, 'provider.setActivePoint(pointId(point))' ) && str_contains( $map_js, 'confirmButton.disabled = !committedPoint' ) && str_contains( $map_js, 'provider.openPointPopup(point, renderPointPopup(point' ), 'Marker/list preview must highlight the marker and open a popup without dispatching checkout selection.' );
pickup_checkout_assert( str_contains( $map_js, 'function commit(point, options)' ) && str_contains( $map_js, 'committedPoint = point' ) && str_contains( $map_js, "CustomEvent('wdc:point-selected'" ), 'Popup/list selection must commit the point and dispatch wdc:point-selected.' );
pickup_checkout_assert( strpos( $map_js, "CustomEvent('wdc:point-selected'" ) > strpos( $map_js, 'function commit(point, options)' ) && strpos( $map_js, "CustomEvent('wdc:point-selected'" ) < strpos( $map_js, 'function markPopupManuallyClosed(source)' ), 'wdc:point-selected must only be dispatched by commit, not preview/renderMarkers.' );
pickup_checkout_assert( str_contains( $map_js, 'provider.onPointClick(function (point) { preview(point, { focus: false, userAction: true }); });' ) && str_contains( $map_js, 'provider.onPopupSelect(function (point) { commit(point, { focus: false }); });' ) && str_contains( $map_js, 'preview(point, { focus: false, ensureVisible: true, userAction: true });' ) && ! str_contains( $map_js, 'preview(point, { focus: true });' ), 'Marker/list row clicks must open popup previews without hard map centering, while popup select is an explicit commit path.' );
pickup_checkout_assert( strpos( $map_js, 'provider.setActivePoint(pointId(point))' ) > strpos( $map_js, 'function preview(point, options)' ) && strpos( $map_js, 'provider.setActivePoint(pointId(point))' ) < strpos( $map_js, 'function commit(point, options)' ), 'Marker/list preview must set active marker without dispatching selection.' );
pickup_checkout_assert( str_contains( $map_js, 'renderPointPopup(point, selected)' ) && str_contains( $map_js, 'data-wdc-pickup-popup-select' ) && str_contains( $map_js, 'point.address' ) && str_contains( $map_js, 'point.work_time' ) && str_contains( $map_js, 'pointTypeLabel(point)' ), 'Point popup must render card labels, address, work time, and the select button.' );
pickup_checkout_assert( str_contains( $map_js, 'preview(point, { focus: false, initial: true })' ) && ! str_contains( $map_js, 'preview(points[0], false)' ), 'Initial search must render only a preview popup.' );
pickup_checkout_assert( str_contains( $map_js, "' preview'" ) && str_contains( $map_js, 'var selected = committedPoint' ) && str_contains( $map_js, 'var active = previewed' ), 'Preview row must be highlighted, while selected class must depend on committedPoint.' );
pickup_checkout_assert( str_contains( $map_js, 'if (previewPoint && !pointInList(previewPoint, visiblePoints))' ) && str_contains( $map_js, 'previewPoint = null' ), 'renderMarkers must clear previewPoint when it leaves the visible bbox.' );
pickup_checkout_assert( str_contains( $map_js, 'if (!previewPoint && committedPoint)' ) && str_contains( $map_js, 'previewPoint = matchingPoint(committedPoint, visiblePoints)' ) && str_contains( $map_js, 'activePointId: previewPoint ? pointId(previewPoint) : null' ), 'Visible committedPoint must become previewPoint, and active marker id must come only from previewPoint.' );
pickup_checkout_assert( str_contains( $map_js, 'provider.openPointPopup(point, renderPointPopup(point, true)' ) && str_contains( $map_js, 'card.textContent = selectedSummary(point)' ), 'Selected state must update popup HTML and compact status card.' );
pickup_checkout_assert( str_contains( $map_js, 'var popupManuallyClosed = false' ) && str_contains( $map_js, 'popupManuallyClosed = false' ) && str_contains( $map_js, 'if (provider.openPointPopup && !popupManuallyClosed)' ), 'Preview and initial selected restore must open popups only while manual close is not set.' );
pickup_checkout_assert( str_contains( $map_js, 'function markPopupManuallyClosed(source)' ) && str_contains( $map_js, 'popupManuallyClosed = true' ) && str_contains( $map_js, "provider.onPopupClose(function () { markPopupManuallyClosed('popup_close'); });" ), 'Popup close must mark popupManuallyClosed without closing the already closed popup again.' );
pickup_checkout_assert( ! str_contains( $map_js, 'data-wdc-pickup-list-select' ) && ! str_contains( $map_js, 'wdc-pickup-list__actions' ), 'List rows must not render per-row select buttons.' );
pickup_checkout_assert( str_contains( $map_js, 'data-wdc-pickup-list-confirm' ) && str_contains( $map_js, 'createListSelectButton(list)' ) && str_contains( $map_js, 'listSelectButton.disabled = true' ), 'Map list must render a single disabled footer select button.' );
pickup_checkout_assert( str_contains( $map_js, 'if (previewPoint && committedPoint && pointId(previewPoint) === pointId(committedPoint))' ) && str_contains( $map_js, 'listSelectButton.disabled = false' ) && str_contains( $map_js, "listSelectButton.textContent = 'Выбрать этот пункт'" ), 'Preview point must enable the shared list footer button unless it is already committed.' );
pickup_checkout_assert( str_contains( $map_js, "commit(previewPoint, { focus: false, ensureVisible: true });" ), 'Shared list footer button must commit previewPoint and dispatch wdc:point-selected through commit().' );
pickup_checkout_assert( str_contains( $modal_js, 'data-wdc-confirm' ) && str_contains( $map_css, '.wdc-pickup-modal__footer' ) && str_contains( $map_css, 'clip-path: inset(50%)' ), 'Fallback modal confirm button must remain available for checkout events but be visually hidden.' );
pickup_checkout_assert( str_contains( $modal_js, 'data-wdc-card aria-live="polite"' ) && str_contains( $map_css, '.wdc-pickup-modal__card' ) && str_contains( $map_css, 'clip: rect(0 0 0 0)' ), 'Compact status card must be aria-live only and not render as visible duplicate text under the list.' );
pickup_checkout_assert( str_contains( $map_js, 'wdc-pickup-list-footer__select' ) && str_contains( $map_js, 'data-wdc-pickup-list-confirm' ), 'The visible list area must expose the single shared footer select button.' );
pickup_checkout_assert( str_contains( $map_js, 'provider.openPointPopup(previewPoint, renderPointPopup(previewPoint' ) && str_contains( $map_js, 'if (provider.openPointPopup && !popupManuallyClosed)' ) && ! str_contains( $map_js, "\n\t\t\tcommittedPoint = null" ), 'renderMarkers must reopen the preview popup only when not manually closed and must not reset committedPoint when it leaves the bbox.' );
pickup_checkout_assert( str_contains( $map_js, "provider.onMapClick(function () { markPopupManuallyClosed('map_click'); });" ) && str_contains( $map_js, "if (source === 'map_click' && provider.closePopup)" ) && str_contains( $map_js, 'provider.closePopup()' ), 'Map click must mark manual close and explicitly close popup.' );
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
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'attributionControl: false' ), 'Leaflet provider must disable the standard attribution control.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'window.L.divIcon' ) && str_contains( $leaflet_provider_js, 'wdc-map-marker-pin' ) && str_contains( $leaflet_provider_js, 'wdc-map-marker-pin__inner"></span>' ) && str_contains( $leaflet_provider_js, 'wdc-map-marker-pin__tail' ) && str_contains( $leaflet_provider_js, 'setActivePoint' ) && str_contains( $leaflet_provider_js, 'focusPoint' ), 'Leaflet provider must support textless custom pin markers and active/focus abstraction.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'renderSearchMarker' ) && str_contains( $leaflet_provider_js, 'wdc-map-marker-pin--search' ) && str_contains( $yandex_provider_js, 'renderSearchMarker' ) && str_contains( $map_css, '.wdc-map-marker-pin--search' ), 'Map providers must render a separate red address search marker.' );
pickup_checkout_assert( ! str_contains( $leaflet_provider_js, 'ОПС' ) && ! str_contains( $leaflet_provider_js, 'ПВЗ' ) && ! str_contains( $leaflet_provider_js, 'Почтомат' ), 'Leaflet marker HTML must not render type text.' );
pickup_checkout_assert( ! str_contains( $leaflet_provider_js, "marker.bindPopup(escapeHtml(point.address || ''))" ), 'Leaflet marker creation must not bind a temporary address popup.' );
pickup_checkout_assert( ! str_contains( $leaflet_provider_js, 'committed' ) && str_contains( $leaflet_provider_js, "(active ? ' is-active' : '')" ), 'Leaflet marker provider must expose only the active marker class, without committed-specific state.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'clusterPoints(points)' ) && str_contains( $leaflet_provider_js, 'wdc-map-cluster' ) && str_contains( $leaflet_provider_js, 'wdc-map-cluster__inner' ) && str_contains( $leaflet_provider_js, 'cluster.points.length' ) && str_contains( $leaflet_provider_js, 'map.fitBounds' ), 'Leaflet provider must render grid clusters with counts and zoom/focus on click.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'openPointPopup' ) && str_contains( $leaflet_provider_js, 'bindPopup' ) && str_contains( $leaflet_provider_js, 'autoPan: true' ) && str_contains( $leaflet_provider_js, 'keepInView: true' ) && str_contains( $leaflet_provider_js, 'autoPanPadding: [24, 24]' ), 'Leaflet popup options must auto-pan softly to keep the balloon visible.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'onPopupSelect' ) && str_contains( $leaflet_provider_js, 'onPopupClose' ) && str_contains( $leaflet_provider_js, 'suppressPopupClose' ) && str_contains( $leaflet_provider_js, "map.on('popupclose', popupClosed)" ), 'Leaflet provider must report manual popup close while suppressing close events during rerender.' );
pickup_checkout_assert( str_contains( $leaflet_provider_js, 'var maxClusterZoom = 18' ) && str_contains( $leaflet_provider_js, 'var clusterCellSize = 64' ) && str_contains( $leaflet_provider_js, 'map.getZoom() >= maxClusterZoom' ) && str_contains( $leaflet_provider_js, 'return { points: [point]' ), 'Leaflet grid clustering must use wider cells and disable clusters only on zoom 18+.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'api-maps.yandex.ru/2.1/' ) && str_contains( $yandex_provider_js, 'new ymaps.Map' ) && str_contains( $yandex_provider_js, 'new ymapsApi.Placemark' ) && str_contains( $yandex_provider_js, 'pointClickCallback(point)' ), 'Yandex provider must load API, create map, render placemarks, and pass marker clicks.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'new ymaps.Clusterer' ) && str_contains( $yandex_provider_js, 'clusterIconLayout' ) && str_contains( $yandex_provider_js, 'wdc-map-cluster__inner' ) && str_contains( $yandex_provider_js, 'properties.geoObjects.length' ) && ! str_contains( $yandex_provider_js, 'islands#' ), 'Yandex provider must use custom Clusterer HTML and avoid standard islands marker presets.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'templateLayoutFactory.createClass' ) && str_contains( $yandex_provider_js, 'wdc-map-marker-pin__inner"></span>' ) && str_contains( $yandex_provider_js, 'wdc-map-marker-pin__tail' ), 'Yandex marker custom layout must render textless markers.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'openPointPopup' ) && str_contains( $yandex_provider_js, "placemark.properties.set('balloonContent', html)" ) && str_contains( $yandex_provider_js, 'balloonAutoPan: true' ) && str_contains( $yandex_provider_js, 'balloonAutoPanMargin: 24' ), 'Yandex balloons must auto-pan softly when opened.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'onPopupSelect' ) && str_contains( $yandex_provider_js, 'onPopupClose' ) && str_contains( $yandex_provider_js, 'suppressPopupClose' ) && str_contains( $yandex_provider_js, "placemark.events.add('balloonclose', popupClosed)" ), 'Yandex provider must report manual balloon close while suppressing close events during rerender.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'var maxClusterZoom = 18' ) && str_contains( $yandex_provider_js, 'gridSize: 80' ) && str_contains( $yandex_provider_js, 'var useClusterer = map.getZoom() < maxClusterZoom' ) && str_contains( $yandex_provider_js, 'map.geoObjects.add(placemark)' ), 'Yandex provider must cluster through zoom 17 and bypass Clusterer only on zoom 18+.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'setActivePoint' ) && str_contains( $yandex_provider_js, 'focusPoint' ) && str_contains( $yandex_provider_js, 'updateActivePlacemarks' ), 'Yandex provider must support active/focus abstraction.' );
pickup_checkout_assert( ! str_contains( $yandex_provider_js, 'committed' ) && str_contains( $yandex_provider_js, "activePointId === id ? 'is-active' : ''" ), 'Yandex marker provider must expose only the active marker class, without committed-specific state.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'var pendingCenter = normalizeCenter' ) && str_contains( $yandex_provider_js, 'pendingCenterChanged = true' ) && str_contains( $yandex_provider_js, 'map.setCenter([pendingCenter.lat, pendingCenter.lng]' ), 'Yandex setCenter before API readiness must be stored and applied after map creation.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'if (pendingPoints.length)' ) && str_contains( $yandex_provider_js, 'renderMarkers(pendingPoints)' ), 'Yandex renderMarkers before readiness must render queued points after map creation.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'pendingPoints = []' ) && str_contains( $yandex_provider_js, 'clearMarkers: function ()' ), 'Yandex clearMarkers before readiness must clear queued points.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'fitToViewport();' ) && str_contains( $yandex_provider_js, 'boundsChanged();' ), 'Yandex provider must fit viewport and trigger bounds loading after readiness.' );
pickup_checkout_assert( str_contains( $yandex_provider_js, 'console.warn' ) && str_contains( $yandex_provider_js, 'debugEnabled()' ), 'Yandex API load failures must stay non-fatal and warn only in debug.' );
pickup_checkout_assert( str_contains( $map_js, 'yandexApiKeyPresent' ) && str_contains( $map_js, 'yandexApiKeyMissing' ) && str_contains( $map_js, 'API key' ), 'Yandex without API key must show a clear modal error and skip provider creation.' );
pickup_checkout_assert( str_contains( $api_js, 'searchInitial' ) && str_contains( $api_js, 'limit=10' ), 'Pickup API must expose a small-limit initial search.' );
pickup_checkout_assert( str_contains( $api_js, 'Array.isArray(data)' ), 'Pickup API must normalize point responses to arrays.' );
pickup_checkout_assert( str_contains( $map_checkout_source, 'selected_city()' ) && str_contains( $map_checkout_source, 'fallback_city()' ), 'Initial map context must use selected city/session fallback query sources.' );
pickup_checkout_assert( str_contains( $map_checkout_source, 'has_usable_coordinates' ) && str_contains( $map_checkout_source, "'RU'" ), 'Initial map context must validate coordinates and only pass RU context.' );

echo "Pickup checkout smoke test passed.\n";
