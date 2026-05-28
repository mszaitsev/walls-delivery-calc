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

$root = dirname( __DIR__, 2 );
$validation_source = file_get_contents( $root . '/src/Checkout/WooCommerce/CheckoutValidation.php' ) ?: '';
foreach ( array( 'Р’', 'Рµ', 'С‹', 'СЊ' ) as $mojibake ) {
	pickup_checkout_assert( ! str_contains( $validation_source, $mojibake ), 'CheckoutValidation.php must not contain mojibake marker ' . $mojibake . '.' );
}
$map_checkout_source = file_get_contents( $root . '/src/Checkout/WooCommerce/PickupMapCheckout.php' ) ?: '';
pickup_checkout_assert( str_contains( $map_checkout_source, 'assets/vendor/leaflet/leaflet.css' ) && str_contains( $map_checkout_source, 'assets/vendor/leaflet/leaflet.js' ), 'Leaflet enqueue URL must point to assets/vendor/leaflet.' );
pickup_checkout_assert( file_exists( $root . '/assets/vendor/leaflet/leaflet.css' ) && file_exists( $root . '/assets/vendor/leaflet/leaflet.js' ), 'Leaflet assets must exist under assets/vendor/leaflet.' );
$checkout_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-checkout.js' ) ?: '';
$city_selector_js = file_get_contents( $root . '/assets/frontend/checkout-city-selector.js' ) ?: '';
pickup_checkout_assert( ! str_contains( $checkout_js, "input[name^=\"shipping_method\"]')) {\n\t\t\tresetSelection();" ), 'JS must not reset pickup on shipping method change.' );
pickup_checkout_assert( str_contains( $checkout_js, 'billing_postcode' ) && str_contains( $checkout_js, 'shipping_postcode' ), 'JS must reset pickup on city/country/postcode changes.' );
pickup_checkout_assert( str_contains( $checkout_js, 'contextFromFields' ) && str_contains( $checkout_js, 'shipping_city' ) && str_contains( $checkout_js, 'shipping_postcode' ), 'JS must form initial map query from checkout city/postcode.' );
pickup_checkout_assert( str_contains( $checkout_js, 'WDCPickupMap.create' ) && str_contains( $checkout_js, 'initialContext()' ), 'Next modal open must recompute initial context instead of reusing a cached value.' );
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
pickup_checkout_assert( str_contains( $map_js, 'if (hasInitialCoordinates)' ) && str_contains( $map_js, 'loadBounds();' ), 'Map JS must load bbox immediately when initial coordinates exist.' );
pickup_checkout_assert( str_contains( $map_js, 'map.setView([55.0302, 82.9204], 11)' ) && ! str_contains( $map_js, '} else if (!hasInitialQuery) {' ), 'Map JS must always set a safe fallback center when initial coordinates are missing.' );
pickup_checkout_assert( str_contains( $map_js, 'else if (hasInitialQuery)' ) && str_contains( $map_js, 'initialSearch(String(context.query))' ), 'Map JS must run initial search before bbox loading when only an initial query exists.' );
pickup_checkout_assert( ! str_contains( $map_js, "loadBounds();\n\t\t\tif (!hasInitialCoordinates && context.query)" ), 'Map JS must not load the Novosibirsk bbox before initial query search.' );
pickup_checkout_assert( str_contains( $map_js, 'preview(points[0], false)' ), 'Initial search preview must not enable final pickup confirmation.' );
pickup_checkout_assert( str_contains( $map_js, 'hasPreloadedPoints = preloadedPoints.length > 0' ) && str_contains( $map_js, 'renderMarkers(preloadedPoints' ), 'Map JS must render preloaded points immediately.' );
pickup_checkout_assert( str_contains( $map_js, "if (hasPreloadedPoints) {\n\t\t\t\treturn;" ), 'Preloaded startup must invalidate map size without calling initial loadBounds.' );
pickup_checkout_assert( str_contains( $map_js, "map.on('moveend zoomend', debouncedLoad)" ) && str_contains( $map_js, 'loadBounds();' ), 'Manual moveend/zoomend must still call loadBounds after open.' );
pickup_checkout_assert( str_contains( $map_js, 'centerLat' ) && str_contains( $map_js, 'centerLng' ), 'Map JS must support preloaded map center coordinates.' );
$api_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-api.js' ) ?: '';
pickup_checkout_assert( str_contains( $api_js, 'searchInitial' ) && str_contains( $api_js, 'limit=10' ), 'Pickup API must expose a small-limit initial search.' );
pickup_checkout_assert( str_contains( $api_js, 'Array.isArray(data)' ), 'Pickup API must normalize point responses to arrays.' );
pickup_checkout_assert( str_contains( $map_checkout_source, 'selected_city()' ) && str_contains( $map_checkout_source, 'fallback_city()' ), 'Initial map context must use selected city/session fallback query sources.' );
pickup_checkout_assert( str_contains( $map_checkout_source, 'has_usable_coordinates' ) && str_contains( $map_checkout_source, "'RU'" ), 'Initial map context must validate coordinates and only pass RU context.' );

echo "Pickup checkout smoke test passed.\n";
