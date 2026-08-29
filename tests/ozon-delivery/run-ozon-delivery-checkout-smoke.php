<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . '/' );
defined( 'WDC_SECRET_KEY' ) || define( 'WDC_SECRET_KEY', 'test-secret' );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'test-app-encryption-key' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once $root . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', $root . '/src' ) )->register();

use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryAccessTokenService;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiResponse;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryMessageSanitizer;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryTokenCache;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliveryCredentials;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupPointProvider;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupRepository;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteParser;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteRequestBuilder;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteService;
use WallsShop\WDC\Carriers\Runtime\OzonDeliveryCarrier;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\WooCommercePackageMapper;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Packaging\PackagingBuilder;
use WallsShop\WDC\Packaging\PackagingBuilderConfig;

if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( string $type = 'mysql', bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( mixed $value ): mixed { return $value; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['oz_checkout_options'][ $key ] ?? $default; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( string $key, mixed $value, bool $autoload = true ): bool { $GLOBALS['oz_checkout_options'][ $key ] = $value; return true; } }
if ( ! function_exists( 'wc_get_logger' ) ) { function wc_get_logger(): object { return new class { public function log( string $level, string $message, array $context = array() ): void {} }; } }
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */ public array $locations = array();
		public int $location_single_lookup_calls = 0;
		public function prepare( string $query, mixed ...$values ): string { foreach ( $values as $value ) { $query = preg_replace( '/%[sdf]/', is_float( $value ) ? sprintf( '%.8F', $value ) : ( is_numeric( $value ) ? (string) (int) $value : "'" . (string) $value . "'" ), $query, 1 ) ?? $query; } return $query; }
		public function get_row( string $query, mixed $output = null ): ?array { foreach ( $this->locations as $row ) { if ( preg_match( '/WHERE l\\.id = (\\d+)/', $query, $matches ) && (int) ( $row['id'] ?? 0 ) === (int) $matches[1] ) { return $row; } } return null; }
	}
}
final class OzonCheckoutSmokeSession { private array $data = array(); public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; } public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; } }
final class OzonCheckoutSmokeWooCommerce { public OzonCheckoutSmokeSession $session; public function __construct() { $this->session = new OzonCheckoutSmokeSession(); } }
if ( ! function_exists( 'WC' ) ) { function WC(): OzonCheckoutSmokeWooCommerce { static $wc = null; if ( null === $wc ) { $wc = new OzonCheckoutSmokeWooCommerce(); } return $wc; } }
final class OzonCheckoutSmokeProduct { public function get_sku(): string { return 'ozon-smoke'; } public function get_name(): string { return 'Тестовый товар'; } public function get_weight(): float { return 1.0; } public function get_length(): int { return 10; } public function get_width(): int { return 10; } public function get_height(): int { return 10; } }
final class OzonCheckoutSmokeHttp implements OzonDeliveryHttpClientInterface {
	public array $calls = array();
	public function request( string $method, string $url, array $args = array() ): OzonDeliveryApiResponse {
		$body = json_decode( (string) ( $args['body'] ?? '{}' ), true );
		$this->calls[] = array( 'method' => $method, 'url' => $url, 'body' => is_array( $body ) ? $body : array() );
		if ( str_contains( $url, '/oauth/token' ) ) { return new OzonDeliveryApiResponse( 200, '{"access_token":"token","expires_in":9999999999,"token_type":"bearer","scope":["delivery-api.all"]}', array() ); }
		return new OzonDeliveryApiResponse( 200, wp_json_encode( array( 'results' => array( array( 'request_id' => 101, 'posting' => array( 'estimated_delivery_cost' => array( 'amount' => '99.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 5 ) ) ) ) ) ?: '{}', array() );
	}
}
final class OzonCheckoutSmokePickupDb {
	public string $prefix = 'wp_';
	public function prepare( string $query, mixed ...$values ): string { foreach ( $values as $value ) { $query = preg_replace( '/%[df]/', is_float( $value ) ? sprintf( '%.8F', $value ) : (string) (int) $value, $query, 1 ) ?? $query; } return $query; }
	public function get_row( string $query, mixed $output = null ): ?array { return str_contains( $query, "WHERE state='active'" ) ? array( 'id' => 1, 'state' => 'active' ) : null; }
	public function get_results( string $query, mixed $output = null ): array { return array( array( 'generation_id' => 1, 'point_id' => 92783, 'name' => 'ПВЗ Ozon', 'type' => 'pvz', 'full_address' => 'Новосибирск, Красный проспект', 'latitude' => 55.0301, 'longitude' => 82.9201, 'schedule' => 'Ежедневно 09:00-21:00', 'is_active' => 1, 'is_bulky' => 0, 'min_weight_g' => 10, 'max_weight_g' => 25000, 'max_width_mm' => 600, 'max_length_mm' => 1200, 'max_height_mm' => 800 ) ); }
}
function oz_checkout_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$plugin = file_get_contents( $root . '/src/Core/Plugin.php' ) ?: '';
$carrier = file_get_contents( $root . '/src/Carriers/Runtime/OzonDeliveryCarrier.php' ) ?: '';
$service = file_get_contents( $root . '/src/Carriers/OzonDelivery/Quote/OzonDeliveryQuoteService.php' ) ?: '';
$api = file_get_contents( $root . '/src/Carriers/OzonDelivery/Api/OzonDeliveryApiClient.php' ) ?: '';
$orchestrator = file_get_contents( $root . '/src/Checkout/Runtime/CheckoutOrchestrator.php' ) ?: '';
$pickup_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-map.js' ) ?: '';
$pickup_rest = file_get_contents( $root . '/src/Pickup/Rest/CheckoutPickupPointRestController.php' ) ?: '';
oz_checkout_assert( str_contains( $plugin, 'OzonDeliveryCarrier::class' ) && str_contains( $plugin, 'OzonDeliveryQuoteService::class' ) && str_contains( $plugin, 'OzonDeliveryPickupPointProvider::class' ), 'Ozon runtime carrier, quote service and pickup provider must be wired.' );
oz_checkout_assert( str_contains( $carrier, 'pricing_live_confirmed()' ) && str_contains( $carrier, 'supports_courier_delivery: false' ) && str_contains( $carrier, "public const RATE_ID = 'ozon_delivery:pickup'" ), 'Ozon checkout carrier must be pickup-only and live-gated.' );
oz_checkout_assert( str_contains( $carrier, "public const TARIFF_KEY = 'pickup'") && str_contains( $carrier, "public const TARIFF_NAME = 'Ozon до ПВЗ'" ) && str_contains( $carrier, "'pickup_family' => OzonDeliverySettings::PICKUP_FAMILY" ), 'Ozon checkout rate must expose pickup service family and buyer title.' );
oz_checkout_assert( str_contains( $service, 'representative_point' ) && str_contains( $service, 'resolve_selection' ) && str_contains( $service, 'ozon_selected_point_stale' ) && str_contains( $service, 'pickup_provider_query' ), 'Ozon checkout must support representative quote, selected-point repricing and stale selection fail-closed.' );
oz_checkout_assert( str_contains( $api, 'order_checkout' ) && str_contains( $api, "'/v1/order/checkout'" ) && ! str_contains( $service, 'pickup_list' ) && ! str_contains( $service, 'pickup_info' ), 'Checkout pricing must call only order checkout and not catalog APIs.' );
oz_checkout_assert( ! str_contains( $orchestrator, 'Ozon' ) && ! str_contains( $pickup_js, 'ozon_delivery' ) && ! str_contains( $pickup_rest, 'ozon_delivery' ), 'Checkout orchestrator, generic pickup JS and generic pickup REST must remain carrier-neutral.' );
oz_checkout_assert( ! str_contains( $plugin, 'OzonDeliveryShipment' ) && ! str_contains( $plugin, 'OzonDeliveryShipmentAdapter' ) && ! str_contains( $plugin, 'OzonDeliveryDocument' ), 'Shipment Framework must not gain Ozon shipment mutations in this stage.' );

$GLOBALS['oz_checkout_options'] = array();
$_POST['billing_phone'] = '+79991234567';
$location_db = new wpdb();
$location_db->locations = array( array( 'id' => 650000, 'country_code' => 'RU', 'region_name' => 'Новосибирская область', 'city_name' => 'Новосибирск', 'place_name' => 'Новосибирск', 'display_name' => 'Новосибирская область, г Новосибирск', 'latitude' => 55.030199, 'longitude' => 82.92043, 'active' => 1 ) );
$location_repository = new LocationRepository( $location_db );
$session = new CheckoutSessionManager();
$session->save_city_context( array( 'location_id' => 650000, 'city_name' => 'Новосибирск', 'country_code' => 'RU' ) );
$mapped_request = ( new WooCommercePackageMapper( null, $session, null, $location_repository ) )->map( array( 'destination' => array( 'country' => 'RU', 'city' => 'Новосибирск' ), 'contents_cost' => 1000, 'contents_weight' => 1, 'contents' => array( array( 'data' => new OzonCheckoutSmokeProduct(), 'quantity' => 1, 'line_total' => 1000 ) ) ) );
oz_checkout_assert( 650000 === (int) ( $mapped_request->customer_context['selected_location_id'] ?? 0 ) && 55.030199 === (float) ( $mapped_request->customer_context['destination_latitude'] ?? 0 ) && 82.92043 === (float) ( $mapped_request->customer_context['destination_longitude'] ?? 0 ), 'WooCommerce mapper must resolve trusted destination coordinates by canonical selected_location_id when session coordinates are absent.' );
oz_checkout_assert( 0 === $location_db->location_single_lookup_calls, 'WooCommerce mapper must not use fuzzy city-name lookup for Ozon preliminary coordinates.' );
$settings_repo = new SettingsRepository();
$settings = new OzonDeliverySettings( $settings_repo );
$settings->save_pricing_settings( array( OzonDeliverySettings::SHIPMENT_METHOD_ID_KEY => '42' ) );
$settings->save_last_quote_diagnostic( array( 'success' => true, 'endpoint' => 'POST /v1/order/checkout', 'shipment_method_id' => 42 ) );
$credentials = new OzonDeliveryCredentials( $settings_repo, new EncryptionService(), new OzonDeliveryTokenCache( new EncryptionService() ) );
$credentials->save_from_admin( array( OzonDeliverySettings::CLIENT_ID_KEY => 'client', 'ozon_delivery_client_secret' => 'secret' ) );
$http = new OzonCheckoutSmokeHttp();
$sanitizer = new OzonDeliveryMessageSanitizer();
$api = new OzonDeliveryApiClient( $http, new OzonDeliveryAccessTokenService( $credentials, $http, $sanitizer, new OzonDeliveryTokenCache( new EncryptionService() ) ) );
$quote_service = new OzonDeliveryQuoteService( $api, new OzonDeliveryQuoteRequestBuilder( $settings ), new OzonDeliveryQuoteParser( $sanitizer ), new PackagingBuilder( PackagingBuilderConfig::defaults() ), new OzonDeliveryPickupPointProvider( new OzonDeliveryPickupRepository( new OzonCheckoutSmokePickupDb() ) ), $sanitizer );
$carrier_quote = ( new OzonDeliveryCarrier( $settings, $credentials, $quote_service, new Logger() ) )->quote( $mapped_request );
oz_checkout_assert( 1 === count( $carrier_quote->rates ) && 'Ozon до ПВЗ' === $carrier_quote->rates[0]->title, 'Ozon preliminary checkout quote must produce the pickup rate after canonical coordinate fallback.' );
oz_checkout_assert( isset( $http->calls[1] ) && str_ends_with( $http->calls[1]['url'], '/v1/order/checkout' ) && 92783 === (int) ( $http->calls[1]['body']['delivery']['delivery_point']['delivery_point_id'] ?? 0 ), 'Ozon preliminary quote must call order_checkout with the representative pickup point found from canonical coordinates.' );
echo "Ozon Delivery checkout smoke passed.\n";
