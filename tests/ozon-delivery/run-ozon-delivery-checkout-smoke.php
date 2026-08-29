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
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Packaging\PackagingBuilder;
use WallsShop\WDC\Packaging\PackagingBuilderConfig;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CheckoutPickupPointProviderQueryResolver;

if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( string $type = 'mysql', bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( mixed $value ): mixed { return $value; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['oz_checkout_options'][ $key ] ?? $default; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( string $key, mixed $value, bool $autoload = true ): bool { $GLOBALS['oz_checkout_options'][ $key ] = $value; return true; } }
if ( ! function_exists( 'wc_get_logger' ) ) { function wc_get_logger(): object { return new class { public function log( string $level, string $message, array $context = array() ): void {} }; } }
if ( ! class_exists( 'WC_Shipping_Method' ) ) { class WC_Shipping_Method {} }
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
final class OzonCheckoutSmokeLongProduct { public function get_sku(): string { return 'ozon-long-smoke'; } public function get_name(): string { return 'Длинный тестовый товар'; } public function get_weight(): float { return 8.0; } public function get_length(): int { return 50; } public function get_width(): int { return 30; } public function get_height(): int { return 20; } }
final class OzonCheckoutSmokeHttp implements OzonDeliveryHttpClientInterface {
	public array $calls = array();
	public function request( string $method, string $url, array $args = array() ): OzonDeliveryApiResponse {
		$body = json_decode( (string) ( $args['body'] ?? '{}' ), true );
		$this->calls[] = array( 'method' => $method, 'url' => $url, 'body' => is_array( $body ) ? $body : array() );
		if ( str_contains( $url, '/oauth/token' ) ) { return new OzonDeliveryApiResponse( 200, '{"access_token":"token","expires_in":9999999999,"token_type":"bearer","scope":["delivery-api.all"]}', array() ); }
		$results = array();
		foreach ( is_array( $body['postings'] ?? null ) ? $body['postings'] : array() as $posting ) {
			$results[] = array( 'request_id' => (int) ( $posting['request_id'] ?? 0 ), 'posting' => array( 'estimated_delivery_cost' => array( 'amount' => '99.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => '10.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 5 ) );
		}
		if ( array() === $results ) {
			$results[] = array( 'request_id' => 101, 'posting' => array( 'estimated_delivery_cost' => array( 'amount' => '99.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => '10.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 5 ) );
		}
		return new OzonDeliveryApiResponse( 200, wp_json_encode( array( 'results' => $results ) ) ?: '{}', array() );
	}
}
final class OzonCheckoutSmokePickupDb {
	public string $prefix = 'wp_';
	/** @var array<int,array<string,mixed>> */
	public array $points;
	public function __construct() {
		$this->points = array(
			array( 'generation_id' => 1, 'point_id' => 92783, 'name' => 'ПВЗ Ozon', 'type' => 'pvz', 'full_address' => 'Новосибирск, Красный проспект', 'latitude' => 55.0301, 'longitude' => 82.9201, 'schedule' => 'Ежедневно 09:00-21:00', 'is_active' => 1, 'is_bulky' => 0, 'min_weight_g' => 10, 'max_weight_g' => 10000, 'max_width_mm' => 500, 'max_length_mm' => 500, 'max_height_mm' => 300 ),
			array( 'generation_id' => 1, 'point_id' => 92784, 'name' => 'Выбранный ПВЗ Ozon', 'type' => 'pvz', 'full_address' => 'Новосибирск, улица Ленина', 'latitude' => 55.0310, 'longitude' => 82.9210, 'schedule' => 'Ежедневно 10:00-20:00', 'is_active' => 1, 'is_bulky' => 0, 'min_weight_g' => 10, 'max_weight_g' => 10000, 'max_width_mm' => 500, 'max_length_mm' => 500, 'max_height_mm' => 300 ),
			array( 'generation_id' => 1, 'point_id' => 77001, 'name' => 'Московский ПВЗ Ozon', 'type' => 'pvz', 'full_address' => 'Москва, Тверская улица', 'latitude' => 55.7558, 'longitude' => 37.6173, 'schedule' => 'Ежедневно 09:00-21:00', 'is_active' => 1, 'is_bulky' => 0, 'min_weight_g' => 10, 'max_weight_g' => 10000, 'max_width_mm' => 500, 'max_length_mm' => 500, 'max_height_mm' => 300 ),
		);
	}
	public function prepare( string $query, mixed ...$values ): string { foreach ( $values as $value ) { $query = preg_replace( '/%[df]/', is_float( $value ) ? sprintf( '%.8F', $value ) : (string) (int) $value, $query, 1 ) ?? $query; } return $query; }
	public function get_row( string $query, mixed $output = null ): ?array {
		if ( str_contains( $query, "WHERE state='active'" ) ) { return array( 'id' => 1, 'state' => 'active' ); }
		if ( preg_match( '/point_id=(\d+)/', $query, $matches ) ) {
			foreach ( $this->points as $point ) { if ( (int) $point['point_id'] === (int) $matches[1] ) { return $point; } }
		}
		return null;
	}
	public function get_results( string $query, mixed $output = null ): array { return $this->points; }
}
function oz_checkout_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function oz_checkout_stored_rate( \WallsShop\WDC\Domain\Quote\DeliveryRate $rate ): array {
	$mapped = ( new WooCommerceRateMapper() )->map( $rate );
	return array_merge(
		$mapped['meta_data'],
		array(
			'rate_id' => $rate->rate_id,
			'label' => $mapped['label'],
			'cost' => $mapped['cost'],
			'fallback_used' => false,
			'service_title' => $rate->service_name,
		)
	);
}
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
$_POST = array(
	'post_data'     => http_build_query(
		array(
			'billing_phone'      => '+7 (913) 123-45-67',
			'billing_first_name' => 'Smoke',
		)
	),
	'billing_phone' => '+79990000000',
);
$location_db = new wpdb();
$location_db->locations = array( array( 'id' => 650000, 'country_code' => 'RU', 'region_name' => 'Новосибирская область', 'city_name' => 'Новосибирск', 'place_name' => 'Новосибирск', 'display_name' => 'Новосибирская область, г Новосибирск', 'latitude' => 55.030199, 'longitude' => 82.92043, 'active' => 1 ) );
$location_repository = new LocationRepository( $location_db );
$session = new CheckoutSessionManager();
$session->save_city_context( array( 'location_id' => 650000, 'city_name' => 'Новосибирск', 'country_code' => 'RU' ) );
$mapped_request = ( new WooCommercePackageMapper( null, $session, null, $location_repository ) )->map( array( 'destination' => array( 'country' => 'RU', 'city' => 'Новосибирск' ), 'contents_cost' => 1000, 'contents_weight' => 1, 'contents' => array( array( 'data' => new OzonCheckoutSmokeProduct(), 'quantity' => 1, 'line_total' => 1000 ) ) ) );
oz_checkout_assert( 650000 === (int) ( $mapped_request->customer_context['selected_location_id'] ?? 0 ) && 55.030199 === (float) ( $mapped_request->customer_context['destination_latitude'] ?? 0 ) && 82.92043 === (float) ( $mapped_request->customer_context['destination_longitude'] ?? 0 ), 'WooCommerce mapper must resolve trusted destination coordinates by canonical selected_location_id when session coordinates are absent.' );
oz_checkout_assert( 0 === $location_db->location_single_lookup_calls, 'WooCommerce mapper must not use fuzzy city-name lookup for Ozon preliminary coordinates.' );
oz_checkout_assert( '+79131234567' === (string) ( $mapped_request->customer_context['recipient_phone'] ?? '' ), 'WooCommerce mapper must read and normalize current billing_phone from checkout AJAX post_data for Ozon pricing.' );
oz_checkout_assert( ! array_key_exists( 'post_data', $mapped_request->customer_context ) && ! array_key_exists( 'billing_first_name', $mapped_request->customer_context ), 'WooCommerce mapper must not copy raw checkout post_data into quote customer context.' );
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
$runtime_carrier = new OzonDeliveryCarrier( $settings, $credentials, $quote_service, new Logger() );
$preliminary_cache_context = $runtime_carrier->quote_cache_context( $mapped_request );
$carrier_quote = ( new OzonDeliveryCarrier( $settings, $credentials, $quote_service, new Logger() ) )->quote( $mapped_request );
oz_checkout_assert( 1 === count( $carrier_quote->rates ) && 'Ozon до ПВЗ' === $carrier_quote->rates[0]->title, 'Ozon preliminary checkout quote must produce the pickup rate after canonical coordinate fallback.' );
oz_checkout_assert( 10900 === $carrier_quote->rates[0]->price->get_kopecks() && 109.0 === (float) ( $carrier_quote->rates[0]->meta['api_base_price_rub'] ?? 0 ) && 10.0 === (float) ( $carrier_quote->rates[0]->meta['insurance_total_rub'] ?? 0 ), 'Ozon buyer-facing checkout rate must include delivery and insurance from order_checkout.' );
oz_checkout_assert( 2 === (int) ( $preliminary_cache_context['ozon_delivery_pricing_contract_version'] ?? 0 ), 'Ozon quote cache context must include the insurance-aware pricing contract version.' );
oz_checkout_assert( isset( $http->calls[1] ) && str_ends_with( $http->calls[1]['url'], '/v1/order/checkout' ) && 92783 === (int) ( $http->calls[1]['body']['delivery']['delivery_point']['delivery_point_id'] ?? 0 ), 'Ozon preliminary quote must call order_checkout with the representative pickup point found from canonical coordinates.' );
oz_checkout_assert( '+79131234567' === (string) ( $http->calls[1]['body']['recipient']['phone_number'] ?? '' ), 'Ozon preliminary quote must send normalized customer phone from WooCommerce AJAX post_data to order_checkout.' );
$ozon_rate = $carrier_quote->rates[0];
$snapshot = is_array( $ozon_rate->meta['pickup_provider_query'] ?? null ) ? $ozon_rate->meta['pickup_provider_query'] : array();
oz_checkout_assert( array() !== $snapshot && 'ozon_delivery' === (string) ( $snapshot['carrier_key'] ?? '' ) && 'destination_pickup' === (string) ( $snapshot['purpose'] ?? '' ), 'Ozon rate must carry canonical pickup_provider_query snapshot.' );
oz_checkout_assert( 'country=RU|location_id=650000' === (string) ( $snapshot['destination_fingerprint'] ?? '' ) && 'country=RU|location_id=650000' === (string) ( $snapshot['provider_destination_fingerprint'] ?? '' ), 'Ozon pickup_provider_query must carry the generic destination fingerprint.' );
oz_checkout_assert( ! array_key_exists( 'generation_id', $snapshot ) && ! str_contains( (string) ( $snapshot['destination_fingerprint'] ?? '' ), '92783' ), 'Ozon destination fingerprint must not depend on pickup generation or point_id.' );
oz_checkout_assert( 60 === (int) ( $snapshot['radius_km'] ?? 0 ) && 1000 === (int) ( $snapshot['cargo']['weight_g'] ?? 0 ) && false === (bool) ( $snapshot['reload_on_viewport_change'] ?? true ) && false === (bool) ( $snapshot['prefetch_points'] ?? true ), 'Ozon pickup_provider_query must keep the trusted 60 km search radius, cargo, fixed-area map capability, and disabled point prefetch.' );
$multi_request = ( new WooCommercePackageMapper( null, $session, null, $location_repository ) )->map(
	array(
		'destination'      => array( 'country' => 'RU', 'city' => 'Новосибирск' ),
		'contents_cost'    => 2985,
		'contents_weight'  => 24,
		'contents'         => array(
			array(
				'data'       => new OzonCheckoutSmokeLongProduct(),
				'quantity'   => 3,
				'line_total' => 2985,
			),
		),
	)
);
$multi_call_count = count( $http->calls );
$multi_quote = $runtime_carrier->quote( $multi_request );
$multi_call = $http->calls[ count( $http->calls ) - 1 ] ?? array();
$multi_postings = is_array( $multi_call['body']['postings'] ?? null ) ? $multi_call['body']['postings'] : array();
oz_checkout_assert( count( $http->calls ) > $multi_call_count && 1 === count( $multi_quote->rates ) && str_ends_with( (string) ( $multi_call['url'] ?? '' ), '/v1/order/checkout' ), 'Ozon multi-box preliminary quote must reach order_checkout instead of failing representative point lookup.' );
oz_checkout_assert( 3 === count( $multi_postings ) && 32700 === $multi_quote->rates[0]->price->get_kopecks(), 'Ozon multi-box checkout quote must create three postings and sum delivery plus insurance for each posting.' );
foreach ( $multi_postings as $posting ) {
	oz_checkout_assert( '995.00' === (string) ( $posting['declared_value']['amount'] ?? '' ) && 8000 === (int) ( $posting['dimensions']['weight_g'] ?? 0 ) && 500 === (int) ( $posting['dimensions']['length_mm'] ?? 0 ) && 300 === (int) ( $posting['dimensions']['width_mm'] ?? 0 ) && 200 === (int) ( $posting['dimensions']['height_mm'] ?? 0 ), 'Ozon multi-box checkout request must use per-posting declared value and per-place dimensions.' );
}
$multi_snapshot = is_array( $multi_quote->rates[0]->meta['pickup_provider_query'] ?? null ) ? $multi_quote->rates[0]->meta['pickup_provider_query'] : array();
oz_checkout_assert( 24000 === (int) ( $multi_snapshot['cargo']['weight_g'] ?? 0 ) && 8000 === (int) ( $multi_snapshot['cargo']['max_place_weight_g'] ?? 0 ) && 3 === count( is_array( $multi_snapshot['cargo']['places'] ?? null ) ? $multi_snapshot['cargo']['places'] : array() ), 'Ozon multi-box provider query must preserve total weight separately from per-place weight and place dimensions.' );
oz_checkout_assert( 3 === (int) ( $multi_quote->rates[0]->meta['packages_count'] ?? 0 ) && is_array( $multi_quote->rates[0]->meta['ozon_delivery_places'] ?? null ) && 3 === count( $multi_quote->rates[0]->meta['ozon_delivery_places'] ), 'Ozon multi-box quote metadata must expose safe package count and per-place summary.' );
$session->save_rates( array( OzonDeliveryCarrier::RATE_ID => oz_checkout_stored_rate( $ozon_rate ) ) );
$provider = new OzonDeliveryPickupPointProvider( new OzonDeliveryPickupRepository( new OzonCheckoutSmokePickupDb() ) );
$resolver = new CheckoutPickupPointProviderQueryResolver( $session );
$resolved_query = $resolver->resolve( OzonDeliveryCarrier::RATE_ID, OzonDeliverySettings::CARRIER_KEY, OzonDeliverySettings::PICKUP_FAMILY );
$resolved_points = ( new CarrierPickupPointProviderRegistry( array( $provider ) ) )->get( OzonDeliverySettings::CARRIER_KEY )?->search( $resolved_query ) ?? array();
oz_checkout_assert( 650000 === $resolved_query->location_id && 'RU' === $resolved_query->normalized_country_code() && count( $resolved_points ) > 0 && '92783' === $resolved_points[0]->code, 'Generic resolver must accept Ozon rate context and registry-backed provider must return Ozon pickup points for the map.' );
oz_checkout_assert( true === ( $resolved_points[0]->raw_reference['requires_rate_refresh'] ?? false ), 'Ozon provider point must declare the generic selected-point repricing capability.' );
$selected_request = new \WallsShop\WDC\Domain\Quote\QuoteRequest( $mapped_request->country_code, $mapped_request->destination, $mapped_request->package, $mapped_request->payment_method, $mapped_request->order_total, $mapped_request->calculation_date, array_merge( $mapped_request->customer_context, array( 'pickup_selections' => array( OzonDeliverySettings::PICKUP_FAMILY => array( 'point_code' => '92784', 'snapshot' => array( 'point_code' => '92784' ) ) ) ) ) );
$selected_cache_context = $runtime_carrier->quote_cache_context( $selected_request );
oz_checkout_assert( '' === (string) $preliminary_cache_context['ozon_delivery_selected_point_id'] && '92784' === (string) $selected_cache_context['ozon_delivery_selected_point_id'], 'Ozon quote cache context must differ after selected pickup point changes.' );
$selected_quote = $runtime_carrier->quote( $selected_request );
$last_call = $http->calls[ count( $http->calls ) - 1 ] ?? array();
oz_checkout_assert( 1 === count( $selected_quote->rates ) && str_ends_with( (string) ( $last_call['url'] ?? '' ), '/v1/order/checkout' ) && 92784 === (int) ( $last_call['body']['delivery']['delivery_point']['delivery_point_id'] ?? 0 ), 'Selected Ozon point must trigger authoritative second order_checkout quote with selected delivery_point_id.' );
$same_quote = ( new OzonDeliveryCarrier( $settings, $credentials, $quote_service, new Logger() ) )->quote( $mapped_request );
$same_snapshot = is_array( $same_quote->rates[0]->meta['pickup_provider_query'] ?? null ) ? $same_quote->rates[0]->meta['pickup_provider_query'] : array();
oz_checkout_assert( (string) $snapshot['destination_fingerprint'] === (string) ( $same_snapshot['destination_fingerprint'] ?? '' ), 'Ozon destination fingerprint must remain stable for the same canonical destination.' );
$other_session = new CheckoutSessionManager();
$other_session->save_city_context( array( 'location_id' => 650001, 'city_name' => 'Новосибирск', 'country_code' => 'RU' ) );
$other_location_db = new wpdb();
$other_location_db->locations = array( array( 'id' => 650001, 'country_code' => 'RU', 'region_name' => 'Новосибирская область', 'city_name' => 'Новосибирск', 'place_name' => 'Новосибирск', 'display_name' => 'Новосибирская область, г Новосибирск', 'latitude' => 55.030199, 'longitude' => 82.92043, 'active' => 1 ) );
$other_request = ( new WooCommercePackageMapper( null, $other_session, null, new LocationRepository( $other_location_db ) ) )->map( array( 'destination' => array( 'country' => 'RU', 'city' => 'Новосибирск' ), 'contents_cost' => 1000, 'contents_weight' => 1, 'contents' => array( array( 'data' => new OzonCheckoutSmokeProduct(), 'quantity' => 1, 'line_total' => 1000 ) ) ) );
$other_quote = ( new OzonDeliveryCarrier( $settings, $credentials, $quote_service, new Logger() ) )->quote( $other_request );
$other_snapshot = is_array( $other_quote->rates[0]->meta['pickup_provider_query'] ?? null ) ? $other_quote->rates[0]->meta['pickup_provider_query'] : array();
oz_checkout_assert( 'country=RU|location_id=650001' === (string) ( $other_snapshot['destination_fingerprint'] ?? '' ) && (string) $snapshot['destination_fingerprint'] !== (string) $other_snapshot['destination_fingerprint'], 'Ozon destination fingerprint must change when the canonical destination changes.' );
$session->save_city_context( array( 'location_id' => 650000, 'city_name' => 'Новосибирск', 'country_code' => 'RU' ) );
$session->save_pickup_selection_for_family(
	OzonDeliverySettings::PICKUP_FAMILY,
	array(
		'carrier_key' => OzonDeliverySettings::CARRIER_KEY,
		'service_key' => OzonDeliverySettings::CARRIER_KEY,
		'pickup_family' => OzonDeliverySettings::PICKUP_FAMILY,
		'point_code' => '92784',
		'point_address' => 'Новосибирск, улица Ленина',
		'destination_fingerprint' => 'country=RU|location_id=650000',
		'snapshot' => array(
			'carrier_key' => OzonDeliverySettings::CARRIER_KEY,
			'service_key' => OzonDeliverySettings::CARRIER_KEY,
			'pickup_family' => OzonDeliverySettings::PICKUP_FAMILY,
			'point_code' => '92784',
			'address' => 'Новосибирск, улица Ленина',
			'destination_fingerprint' => 'country=RU|location_id=650000',
		),
	)
);
oz_checkout_assert( '92784' === (string) ( $session->pickup_selections_for_current_destination()[ OzonDeliverySettings::PICKUP_FAMILY ]['point_code'] ?? '' ), 'Same destination must preserve the selected Ozon pickup point.' );
$location_db->locations[] = array( 'id' => 770000, 'country_code' => 'RU', 'region_name' => 'Москва', 'city_name' => 'Москва', 'place_name' => 'Москва', 'display_name' => 'г Москва', 'latitude' => 55.755864, 'longitude' => 37.617698, 'active' => 1 );
$session->save_city_context( array( 'location_id' => 770000, 'city_name' => 'Москва', 'country_code' => 'RU' ) );
oz_checkout_assert( '92784' === (string) ( $session->raw_pickup_selections()[ OzonDeliverySettings::PICKUP_FAMILY ]['point_code'] ?? '' ), 'Raw stale Ozon selection fixture must still contain the Novosibirsk point before server-side filtering.' );
$moscow_effective_selections = $session->pickup_selections_for_current_destination( true );
oz_checkout_assert( ! isset( $moscow_effective_selections[ OzonDeliverySettings::PICKUP_FAMILY ] ) && ! isset( $session->raw_pickup_selections()[ OzonDeliverySettings::PICKUP_FAMILY ] ), 'Destination fingerprint change must remove the stale Ozon pickup selection before carrier quote.' );
$moscow_request = ( new WooCommercePackageMapper( null, $session, null, $location_repository ) )->map(
	array(
		'destination' => array( 'country' => 'RU', 'city' => 'Москва' ),
		'contents_cost' => 1000,
		'contents_weight' => 1,
		'contents' => array( array( 'data' => new OzonCheckoutSmokeProduct(), 'quantity' => 1, 'line_total' => 1000 ) ),
	),
	array( 'pickup_selections' => $moscow_effective_selections )
);
oz_checkout_assert( ! isset( $moscow_request->customer_context['pickup_selections'][ OzonDeliverySettings::PICKUP_FAMILY ] ), 'QuoteRequest for Moscow must not carry the old Novosibirsk Ozon selection.' );
$moscow_quote = $runtime_carrier->quote( $moscow_request );
$moscow_last_call = $http->calls[ count( $http->calls ) - 1 ] ?? array();
oz_checkout_assert( 1 === count( $moscow_quote->rates ) && 77001 === (int) ( $moscow_last_call['body']['delivery']['delivery_point']['delivery_point_id'] ?? 0 ), 'Novosibirsk to Moscow destination change must produce a new preliminary Ozon quote with the Moscow representative point, not ozon_selected_point_stale.' );
echo "Ozon Delivery checkout smoke passed.\n";
