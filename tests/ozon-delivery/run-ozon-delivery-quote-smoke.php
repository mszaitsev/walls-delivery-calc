<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'WDC_SECRET_KEY', 'test-secret' );
define( 'APP_ENCRYPTION_KEY', 'test-app-encryption-key' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

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
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryCourierLocationResolver;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteException;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryPackagingBuilderFactory;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteParser;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteRequestBuilder;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteService;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Phone\RussianPhoneNormalizer;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Packaging\PackagingParcel;
use WallsShop\WDC\Packaging\PackagingResult;
use WallsShop\WDC\Packaging\PackagingBuilder;
use WallsShop\WDC\Packaging\PackagingBuilderConfig;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;

function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function current_time( string $type, bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); }
$GLOBALS['wdc_options'] = array();
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = true ): bool { $GLOBALS['wdc_options'][ $key ] = $value; return true; }

function oz_quote_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		public function prepare( string $query, mixed ...$values ): string {
			foreach ( $values as $value ) {
				$query = preg_replace( '/%[sdf]/', is_float( $value ) ? sprintf( '%.8F', $value ) : ( is_numeric( $value ) ? (string) (int) $value : "'" . (string) $value . "'" ), $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function get_row( string $query, mixed $output = null ): ?array {
			foreach ( $this->locations as $row ) {
				if ( preg_match( '/WHERE l\\.id = (\\d+)/', $query, $matches ) && (int) ( $row['id'] ?? 0 ) === (int) $matches[1] ) {
					return $row;
				}
			}
			return null;
		}
	}
}

final class OzonQuoteSmokeHttp implements OzonDeliveryHttpClientInterface {
	/** @var array<int,array{method:string,url:string,body:array<string,mixed>,headers:array<string,mixed>}> */
	public array $calls = array();
	public function request( string $method, string $url, array $args = array() ): OzonDeliveryApiResponse {
		$body = json_decode( (string) ( $args['body'] ?? '{}' ), true );
		$this->calls[] = array( 'method' => $method, 'url' => $url, 'body' => is_array( $body ) ? $body : array(), 'headers' => is_array( $args['headers'] ?? null ) ? $args['headers'] : array() );
		if ( str_contains( $url, '/oauth/token' ) ) {
			return new OzonDeliveryApiResponse( 200, '{"access_token":"test-token","expires_in":9999999999,"token_type":"bearer","scope":["delivery-api.all"]}', array( 'content-type' => 'application/json' ) );
		}
		$postings = is_array( $body['postings'] ?? null ) ? $body['postings'] : array();
		$results = array();
		foreach ( $postings as $posting ) {
			$results[] = array( 'request_id' => (int) $posting['request_id'], 'posting' => array( 'estimated_delivery_cost' => array( 'amount' => '123.45', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => '1.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 2, 'cutoff_at' => '2026-07-16T12:00:00Z' ) );
		}
		return new OzonDeliveryApiResponse( 200, wp_json_encode( array( 'results' => $results ) ) ?: '{}', array( 'content-type' => 'application/json' ) );
	}
}

class OzonQuoteSmokeWpdb extends wpdb {
	public string $prefix = 'wp_';
	/** @var array<int,array<string,mixed>> */
	public array $locations = array(
		array( 'id' => 123, 'country_code' => 'RU', 'region_name' => 'Новосибирская область', 'city_name' => 'Новосибирск', 'place_name' => 'Новосибирск', 'display_name' => 'Новосибирская область, г Новосибирск', 'latitude' => 55.0415, 'longitude' => 82.9346, 'active' => 1 ),
	);
	public int $area_lookup_calls = 0;
	public function prepare( string $query, mixed ...$values ): string { foreach ( $values as $value ) { $query = preg_replace( '/%[df]/', is_float( $value ) ? sprintf( '%.8F', $value ) : (string) (int) $value, $query, 1 ) ?? $query; } return $query; }
	public function get_row( string $query, mixed $output = null ): ?array {
		if ( str_contains( $query, "WHERE state='active'" ) ) { return array( 'id' => 1, 'state' => 'active' ); }
		if ( str_contains( $query, 'point_id=777' ) ) { return $this->point( 777, 55.0301, 82.9201 ); }
		return null;
	}
	public function get_results( string $query, mixed $output = null ): array { ++$this->area_lookup_calls; return array( $this->point( 777, 55.0301, 82.9201 ), $this->point( 779, 55.0416, 82.9347 ), $this->point( 778, 55.5000, 83.0000 ) ); }
	private function point( int $id, float $lat, float $lng ): array { return array( 'generation_id' => 1, 'point_id' => $id, 'name' => 'ПВЗ Ozon', 'type' => 'pvz', 'full_address' => 'Новосибирск', 'latitude' => $lat, 'longitude' => $lng, 'schedule' => 'Ежедневно 09:00-21:00', 'is_active' => 1, 'is_bulky' => 0, 'min_weight_g' => 10, 'max_weight_g' => 25000, 'max_width_mm' => 600, 'max_length_mm' => 1200, 'max_height_mm' => 800 ); }
}

final class OzonQuoteSmokeRejectingWpdb extends OzonQuoteSmokeWpdb {
	public function get_row( string $query, mixed $output = null ): ?array {
		if ( str_contains( $query, "WHERE state='active'" ) ) { return array( 'id' => 1, 'state' => 'active' ); }
		return null;
	}
	public function get_results( string $query, mixed $output = null ): array {
		return array( array( 'generation_id' => 1, 'point_id' => 880, 'name' => 'ПВЗ Ozon', 'type' => 'pvz', 'full_address' => 'Новосибирск', 'latitude' => 55.0301, 'longitude' => 82.9201, 'schedule' => 'Ежедневно 09:00-21:00', 'is_active' => 1, 'is_bulky' => 0, 'min_weight_g' => 10, 'max_weight_g' => 7000, 'max_width_mm' => 500, 'max_length_mm' => 500, 'max_height_mm' => 300 ) );
	}
}

$phones = new RussianPhoneNormalizer();
$settings_repo = new SettingsRepository();
$settings = new OzonDeliverySettings( $settings_repo, $phones );
$settings->save_pricing_settings( array( OzonDeliverySettings::SHIPMENT_METHOD_ID_KEY => '42', OzonDeliverySettings::COURIER_SHIPMENT_METHOD_ID_KEY => '43' ) );
$credentials = new OzonDeliveryCredentials( $settings_repo, new EncryptionService(), new OzonDeliveryTokenCache( new EncryptionService() ) );
$credentials->save_from_admin( array( OzonDeliverySettings::CLIENT_ID_KEY => 'client', 'ozon_delivery_client_secret' => 'secret' ) );
$http = new OzonQuoteSmokeHttp();
$sanitizer = new OzonDeliveryMessageSanitizer();
$api = new OzonDeliveryApiClient( $http, new OzonDeliveryAccessTokenService( $credentials, $http, $sanitizer, new OzonDeliveryTokenCache( new EncryptionService() ) ) );
$ozon_packaging = ( new OzonDeliveryPackagingBuilderFactory() )->create();
$location_db = new OzonQuoteSmokeWpdb();
$pickup_repository = new OzonDeliveryPickupRepository( $location_db );
$courier_location = new OzonDeliveryCourierLocationResolver( new LocationRepository( $location_db ), $pickup_repository );
$quote_builder = new OzonDeliveryQuoteRequestBuilder( $settings, $phones, null, $courier_location );
$service = new OzonDeliveryQuoteService( $api, $quote_builder, new OzonDeliveryQuoteParser( $sanitizer ), $ozon_packaging, new OzonDeliveryPickupPointProvider( $pickup_repository ), $sanitizer );
$money = Money::from_rubles( 1000 );
$request = new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Новосибирск' ), new Package( array(), $money, $money, 1000, 0, 1000, 10, 10, 10, 1000, 'manual' ), '', $money, '2026-08-29', array( 'recipient_phone' => '+79991234567', 'selected_location_id' => 123, 'destination_latitude' => 55.0300, 'destination_longitude' => 82.9200 ) );
$courier_request = new QuoteRequest( 'RU', $request->destination, $request->package, $request->payment_method, $request->order_total, $request->calculation_date, array( 'recipient_phone' => '+79991234567', 'selected_location_id' => 123, 'destination_latitude' => 1, 'destination_longitude' => 2, 'lat' => 3, 'lng' => 4 ) );
$result = $service->quote_pickup( $request );
oz_quote_assert( 12445 === $result->price->get_kopecks() && 'RUB' === $result->price->get_currency(), 'Parser must add Ozon delivery and insurance amounts to Money.' );
oz_quote_assert( 2 === $result->delivery_days->max_days && '777' === $result->destination_point_id && 1 === $result->package_count, 'Quote must use nearest representative Ozon point and normalize days.' );
oz_quote_assert( '123.45' === (string) ( $result->meta['delivery_total_rub'] ?? '' ) && '1.00' === (string) ( $result->meta['insurance_total_rub'] ?? '' ) && '124.45' === (string) ( $result->meta['total_rub'] ?? '' ), 'Parser diagnostics must expose normalized delivery, insurance and total RUB amounts.' );
$checkout_call = $http->calls[1] ?? array();
oz_quote_assert( 'POST' === ( $checkout_call['method'] ?? '' ) && str_ends_with( (string) ( $checkout_call['url'] ?? '' ), '/v1/order/checkout' ), 'Quote must call exact official order checkout endpoint.' );
$body = $checkout_call['body'];
oz_quote_assert( '+79991234567' === $body['recipient']['phone_number'] && 42 === $body['postings'][0]['shipment_method_id'] && 777 === $body['delivery']['delivery_point']['delivery_point_id'], 'Request body must contain recipient, shipment_method_id and destination delivery_point_id.' );
oz_quote_assert( 1000 === $body['postings'][0]['dimensions']['weight_g'] && 100 === $body['postings'][0]['dimensions']['length_mm'] && '1000.00' === $body['postings'][0]['declared_value']['amount'], 'Request body must use grams, millimetres and decimal RUB declared value.' );
$packaging = $ozon_packaging->build( $request );
$courier_payload = $quote_builder->build_courier( $courier_request, $packaging );
oz_quote_assert( 43 === (int) ( $courier_payload['body']['postings'][0]['shipment_method_id'] ?? 0 ) && isset( $courier_payload['body']['delivery']['courier']['coordinates'] ) && ! isset( $courier_payload['body']['delivery']['delivery_point'] ), 'Courier order_checkout request must use the separate courier shipment_method_id and official delivery.courier.coordinates contract.' );
oz_quote_assert( 55.0416 === (float) ( $courier_payload['body']['delivery']['courier']['coordinates']['latitude'] ?? 0 ) && 82.9347 === (float) ( $courier_payload['body']['delivery']['courier']['coordinates']['longitude'] ?? 0 ), 'Courier order_checkout request must use the nearest active Ozon pickup proxy point and ignore forged customer_context coordinates.' );
oz_quote_assert( 'ozon_pickup_proxy' === (string) ( $courier_payload['diagnostics']['courier_coordinate_source'] ?? '' ) && 123 === (int) ( $courier_payload['diagnostics']['courier_location_id'] ?? 0 ) && 779 === (int) ( $courier_payload['diagnostics']['courier_proxy_point_id'] ?? 0 ), 'Courier builder diagnostics must expose the safe proxy coordinate source.' );
$exact_request = new QuoteRequest( 'RU', $request->destination, $request->package, $request->payment_method, $request->order_total, $request->calculation_date, array( 'recipient_phone' => '+79991234567', 'selected_location_id' => 123, 'dadata_status' => 'resolved', 'dadata_street' => 'Тверская улица', 'dadata_house' => '1', 'dadata_geo_lat' => '55.75511', 'dadata_geo_lon' => '37.622396' ) );
$area_calls_before_exact = $location_db->area_lookup_calls;
$exact_payload = $quote_builder->build_courier( $exact_request, $packaging );
oz_quote_assert( 55.75511 === (float) ( $exact_payload['body']['delivery']['courier']['coordinates']['latitude'] ?? 0 ) && 37.622396 === (float) ( $exact_payload['body']['delivery']['courier']['coordinates']['longitude'] ?? 0 ) && 'dadata_address' === (string) ( $exact_payload['diagnostics']['courier_coordinate_source'] ?? '' ) && $area_calls_before_exact === $location_db->area_lookup_calls, 'Exact trusted DaData address coordinates must have priority over proxy point lookup.' );
$missing_courier_coords = new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Новосибирск' ), new Package( array(), $money, $money, 1000, 0, 1000, 10, 10, 10, 1000, 'manual' ), '', $money, '2026-08-29', array( 'recipient_phone' => '+79991234567' ) );
try {
	$quote_builder->build_courier( $missing_courier_coords, $packaging );
	oz_quote_assert( false, 'Courier checkout must fail closed when trusted coordinates are absent.' );
} catch ( OzonDeliveryQuoteException $exception ) {
	oz_quote_assert( 'ozon_courier_location_coordinates_missing' === $exception->safe_code, 'Missing courier location coordinates must use the stable fail-closed code.' );
}
$invalid_location_cases = array(
	array( 'id' => 123, 'country_code' => 'RU', 'latitude' => null, 'longitude' => null, 'active' => 1 ),
	array( 'id' => 123, 'country_code' => 'RU', 'latitude' => 0, 'longitude' => 0, 'active' => 1 ),
	array( 'id' => 123, 'country_code' => 'RU', 'latitude' => 100, 'longitude' => 82.9346, 'active' => 1 ),
	array( 'id' => 123, 'country_code' => 'RU', 'latitude' => 55.0415, 'longitude' => 200, 'active' => 1 ),
	array( 'id' => 123, 'country_code' => 'KZ', 'latitude' => 55.0415, 'longitude' => 82.9346, 'active' => 1 ),
);
foreach ( $invalid_location_cases as $invalid_location ) {
	$invalid_db = new OzonQuoteSmokeWpdb();
	$invalid_db->locations = array( $invalid_location );
	$invalid_http = new OzonQuoteSmokeHttp();
	$invalid_service = new OzonDeliveryQuoteService(
		new OzonDeliveryApiClient( $invalid_http, new OzonDeliveryAccessTokenService( $credentials, $invalid_http, $sanitizer, new OzonDeliveryTokenCache( new EncryptionService() ) ) ),
		new OzonDeliveryQuoteRequestBuilder( $settings, $phones, null, new OzonDeliveryCourierLocationResolver( new LocationRepository( $invalid_db ), new OzonDeliveryPickupRepository( $invalid_db ) ) ),
		new OzonDeliveryQuoteParser( $sanitizer ),
		$ozon_packaging,
		new OzonDeliveryPickupPointProvider( new OzonDeliveryPickupRepository( $invalid_db ) ),
		$sanitizer
	);
	try {
		$invalid_service->quote_courier( $courier_request );
		oz_quote_assert( false, 'Invalid DB courier coordinates must fail closed.' );
	} catch ( OzonDeliveryQuoteException $exception ) {
		oz_quote_assert( 'ozon_courier_location_coordinates_missing' === $exception->safe_code && array() === $invalid_http->calls, 'Invalid DB courier coordinates must not call Ozon order_checkout or fallback to browser coordinates.' );
	}
}

final class OzonQuoteSmokeNoProxyWpdb extends OzonQuoteSmokeWpdb {
	public function get_results( string $query, mixed $output = null ): array {
		++$this->area_lookup_calls;
		return array( $this->point_for_test( 901, 55.0600, 82.9600 ) );
	}
	private function point_for_test( int $id, float $lat, float $lng ): array { return array( 'generation_id' => 1, 'point_id' => $id, 'name' => 'Дальний ПВЗ Ozon', 'type' => 'pvz', 'full_address' => 'Новосибирск', 'latitude' => $lat, 'longitude' => $lng, 'schedule' => 'Ежедневно 09:00-21:00', 'is_active' => 1, 'is_bulky' => 0, 'min_weight_g' => 10, 'max_weight_g' => 25000, 'max_width_mm' => 600, 'max_length_mm' => 1200, 'max_height_mm' => 800 ); }
}
$no_proxy_db = new OzonQuoteSmokeNoProxyWpdb();
$no_proxy_db->locations = array( array( 'id' => 123, 'country_code' => 'RU', 'latitude' => 55.0415, 'longitude' => 82.9346, 'active' => 1 ) );
try {
	( new OzonDeliveryQuoteRequestBuilder( $settings, $phones, null, new OzonDeliveryCourierLocationResolver( new LocationRepository( $no_proxy_db ), new OzonDeliveryPickupRepository( $no_proxy_db ) ) ) )->build_courier( $courier_request, $packaging );
	oz_quote_assert( false, 'Courier checkout must fail closed when no active Ozon proxy point exists within 1 km.' );
} catch ( OzonDeliveryQuoteException $exception ) {
	oz_quote_assert( 'ozon_courier_proxy_point_missing' === $exception->safe_code, 'Missing nearby Ozon proxy point must use a stable fail-closed code.' );
}
$oversize_request = new QuoteRequest(
	'RU',
	new Address( country_code: 'RU', city: 'Новосибирск' ),
	Package::from_items( array( new PackageItem( 'oversize', 'Oversize', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 1000, 60, 10, 10 ) ), 0, $money, $money ),
	'',
	$money,
	'2026-08-29',
	array( 'recipient_phone' => '+79991234567', 'destination_latitude' => 55.0300, 'destination_longitude' => 82.9200 )
);
$calls_before_oversize = count( $http->calls );
try {
	$service->quote_pickup( $oversize_request );
	oz_quote_assert( false, 'An indivisible item outside the Ozon parcel limit must fail before Ozon API.' );
} catch ( OzonDeliveryQuoteException $exception ) {
	oz_quote_assert( 'ozon_package_item_oversize' === $exception->safe_code && $calls_before_oversize === count( $http->calls ), 'Ozon oversize item must fail closed without an order_checkout request.' );
}
$rejecting_http = new OzonQuoteSmokeHttp();
$rejecting_api = new OzonDeliveryApiClient( $rejecting_http, new OzonDeliveryAccessTokenService( $credentials, $rejecting_http, $sanitizer, new OzonDeliveryTokenCache( new EncryptionService() ) ) );
$rejecting_service = new OzonDeliveryQuoteService( $rejecting_api, new OzonDeliveryQuoteRequestBuilder( $settings, $phones ), new OzonDeliveryQuoteParser( $sanitizer ), ( new OzonDeliveryPackagingBuilderFactory() )->create(), new OzonDeliveryPickupPointProvider( new OzonDeliveryPickupRepository( new OzonQuoteSmokeRejectingWpdb() ) ), $sanitizer );
$multi_money = Money::from_rubles( 2985 );
$multi_request = new QuoteRequest(
	'RU',
	new Address( country_code: 'RU', city: 'Новосибирск' ),
	Package::from_items(
		array( new PackageItem( 'safe-test', 'Safe test', 3, Money::from_rubles( 995 ), $multi_money, 8000, 50, 30, 20 ) ),
		0,
		$multi_money,
		$multi_money
	),
	'',
	$multi_money,
	'2026-08-29',
	array( 'recipient_phone' => '+79991234567', 'destination_latitude' => 55.0300, 'destination_longitude' => 82.9200 )
);
try {
	$rejecting_service->quote_pickup( $multi_request );
	oz_quote_assert( false, 'Representative point missing must fail closed before Ozon API when all local candidates reject cargo.' );
} catch ( OzonDeliveryQuoteException $exception ) {
	$details = $exception->details;
	$pickup_diagnostics = is_array( $details['pickup_diagnostics'] ?? null ) ? $details['pickup_diagnostics'] : array();
	oz_quote_assert( 'ozon_representative_point_missing' === $exception->safe_code && 0 === $exception->http_status && array() === $rejecting_http->calls, 'Representative missing diagnostics must be produced before any Ozon HTTP request.' );
	oz_quote_assert( 3 === (int) ( $details['places_count'] ?? 0 ) && 24000 === (int) ( $details['total_weight_g'] ?? 0 ) && 8000 === (int) ( $details['max_place_weight_g'] ?? 0 ) && 3 === count( is_array( $details['places'] ?? null ) ? $details['places'] : array() ), 'Representative missing details must include actual expanded place count, total weight, max place weight and safe place dimensions.' );
	oz_quote_assert( 1 === (int) ( $pickup_diagnostics['rows_in_bbox'] ?? 0 ) && 1 === (int) ( $pickup_diagnostics['inside_radius'] ?? 0 ) && 1 === (int) ( $pickup_diagnostics['max_weight_rejected'] ?? 0 ) && 0 === (int) ( $pickup_diagnostics['accepted'] ?? -1 ), 'Representative missing details must include aggregate pickup rejection counters.' );
	oz_quote_assert( ! str_contains( wp_json_encode( $details ) ?: '', '+7999' ) && ! str_contains( wp_json_encode( $details ) ?: '', 'Safe test' ), 'Representative missing diagnostics must not expose phone, product name or raw customer data.' );
}
$settings->save_last_quote_diagnostic( array( 'success' => true, 'endpoint' => 'POST /v1/order/checkout', 'shipment_method_id' => 42 ) );
$settings->save_pricing_settings( array( OzonDeliverySettings::QUOTE_FALLBACK_PHONE_KEY => '+7 (916) 000-11-22' ) );
oz_quote_assert( 42 === $settings->shipment_method_id() && $settings->pricing_live_confirmed(), 'Saving only the Ozon fallback phone must not reset shipment_method_id or close the pricing live gate.' );
$phone_builder = new OzonDeliveryQuoteRequestBuilder( $settings, $phones );
$customer_phone_payload = $phone_builder->build( $request, $packaging, '777' );
oz_quote_assert( '+79991234567' === (string) ( $customer_phone_payload['body']['recipient']['phone_number'] ?? '' ), 'Ozon request builder must prefer valid customer recipient_phone over configured fallback phone.' );
$fallback_request = new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Новосибирск' ), new Package( array(), $money, $money, 1000, 0, 1000, 10, 10, 10, 1000, 'manual' ), '', $money, '2026-08-29', array( 'recipient_phone' => '', 'destination_latitude' => 55.0300, 'destination_longitude' => 82.9200 ) );
$fallback_payload = $phone_builder->build( $fallback_request, $packaging, '777' );
oz_quote_assert( '+79160001122' === (string) ( $fallback_payload['body']['recipient']['phone_number'] ?? '' ), 'Ozon request builder must use normalized fallback phone when customer phone is absent.' );
oz_quote_assert( ! str_contains( wp_json_encode( $fallback_payload['diagnostics'] ) ?: '', '79160001122' ), 'Ozon builder diagnostics must not contain phone values.' );
$empty_fallback_settings = new OzonDeliverySettings( new SettingsRepository(), $phones );
$empty_fallback_settings->save_pricing_settings( array( OzonDeliverySettings::SHIPMENT_METHOD_ID_KEY => '42', OzonDeliverySettings::COURIER_SHIPMENT_METHOD_ID_KEY => '43', OzonDeliverySettings::QUOTE_FALLBACK_PHONE_KEY => '' ) );
try {
	( new OzonDeliveryQuoteRequestBuilder( $empty_fallback_settings, $phones ) )->build( $fallback_request, $packaging, '777' );
	oz_quote_assert( false, 'Missing customer and fallback phone must fail closed.' );
} catch ( OzonDeliveryQuoteException $exception ) {
	oz_quote_assert( 'ozon_recipient_phone_missing' === $exception->safe_code, 'Missing phone must keep the stable fail-closed code.' );
}
$invalid_fallback_settings = new OzonDeliverySettings( new SettingsRepository(), $phones );
$invalid_fallback_settings->save_pricing_settings( array( OzonDeliverySettings::SHIPMENT_METHOD_ID_KEY => '42', OzonDeliverySettings::COURIER_SHIPMENT_METHOD_ID_KEY => '43', OzonDeliverySettings::QUOTE_FALLBACK_PHONE_KEY => 'call me +79131234567' ) );
try {
	( new OzonDeliveryQuoteRequestBuilder( $invalid_fallback_settings, $phones ) )->build( $fallback_request, $packaging, '777' );
	oz_quote_assert( false, 'Invalid fallback phone must not become an Ozon request phone.' );
} catch ( OzonDeliveryQuoteException $exception ) {
	oz_quote_assert( 'ozon_recipient_phone_missing' === $exception->safe_code, 'Invalid fallback phone must fail closed with the stable phone-missing code.' );
}
try {
	( new OzonDeliveryQuoteParser( $sanitizer ) )->parse( array( 'results' => array( array( 'request_id' => 101, 'error' => array( 'code' => 'DeliveryPointRestrictionsError', 'message' => 'bad point' ) ) ) ), array( 101 ), '777', 42 );
	oz_quote_assert( false, 'Carrier error envelope must fail closed.' );
} catch ( OzonDeliveryQuoteException $exception ) {
	oz_quote_assert( 'DeliveryPointRestrictionsError' === $exception->safe_code, 'Official error code must be preserved safely.' );
}
$parser = new OzonDeliveryQuoteParser( $sanitizer );
$single = $parser->parse( array( 'results' => array( array( 'request_id' => 101, 'posting' => array( 'estimated_delivery_cost' => array( 'amount' => '109.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => '10.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 7 ) ) ) ), array( 101 ), '777', 42 );
oz_quote_assert( 11900 === $single->price->get_kopecks() && 7 === $single->delivery_days->max_days && '109.00' === (string) ( $single->meta['postings'][0]['delivery_cost_rub'] ?? '' ) && '10.00' === (string) ( $single->meta['postings'][0]['insurance_cost_rub'] ?? '' ) && '119.00' === (string) ( $single->meta['postings'][0]['total_cost_rub'] ?? '' ), 'Single-box parser must price Ozon as delivery 109 + insurance 10 = 119 RUB.' );
$multi = $parser->parse(
	array(
		'results' => array(
			array( 'request_id' => 101, 'posting' => array( 'estimated_delivery_cost' => array( 'amount' => '106.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => '10.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 4 ) ),
			array( 'request_id' => 102, 'posting' => array( 'estimated_delivery_cost' => array( 'amount' => '106.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => '10.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 4 ) ),
			array( 'request_id' => 103, 'posting' => array( 'estimated_delivery_cost' => array( 'amount' => '106.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => '10.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 4 ) ),
		),
	),
	array( 101, 102, 103 ),
	'777',
	42
);
oz_quote_assert( 34800 === $multi->price->get_kopecks() && 4 === $multi->delivery_days->max_days && 3 === $multi->package_count && '318.00' === (string) ( $multi->meta['delivery_total_rub'] ?? '' ) && '30.00' === (string) ( $multi->meta['insurance_total_rub'] ?? '' ) && '348.00' === (string) ( $multi->meta['total_rub'] ?? '' ), 'Multi-box parser must sum all posting delivery and insurance totals from one order_checkout response.' );
$max_days = $parser->parse(
	array(
		'results' => array(
			array( 'request_id' => 101, 'posting' => array( 'estimated_delivery_cost' => array( 'amount' => '1.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => '0.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 3 ) ),
			array( 'request_id' => 102, 'posting' => array( 'estimated_delivery_cost' => array( 'amount' => '1.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => '0.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 4 ) ),
			array( 'request_id' => 103, 'posting' => array( 'estimated_delivery_cost' => array( 'amount' => '1.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => '0.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 6 ) ),
		),
	),
	array( 101, 102, 103 ),
	'777',
	42
);
oz_quote_assert( 6 === $max_days->delivery_days->max_days, 'Multi-box delivery days must use the maximum posting delivery days.' );
foreach ( array(
	'missing insurance' => array( 'estimated_delivery_cost' => array( 'amount' => '109.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 1 ),
	'missing insurance amount' => array( 'estimated_delivery_cost' => array( 'amount' => '109.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 1 ),
	'unsupported insurance currency' => array( 'estimated_delivery_cost' => array( 'amount' => '109.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => '10.00', 'currency_code' => 'USD' ), 'estimated_delivery_days' => 1 ),
	'malformed insurance amount' => array( 'estimated_delivery_cost' => array( 'amount' => '109.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => array(), 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 1 ),
) as $case => $posting ) {
	try {
		$parser->parse( array( 'results' => array( array( 'request_id' => 101, 'posting' => $posting ) ) ), array( 101 ), '777', 42 );
		oz_quote_assert( false, 'Parser must fail closed on ' . $case . '.' );
	} catch ( OzonDeliveryQuoteException $exception ) {
		oz_quote_assert( str_starts_with( $exception->safe_code, 'ozon_quote_insurance_' ), 'Insurance parse failures must use carrier-owned insurance error codes.' );
	}
}
try {
	$parser->parse(
		array(
			'results' => array(
				array( 'request_id' => 101, 'posting' => array( 'estimated_delivery_cost' => array( 'amount' => '106.00', 'currency_code' => 'RUB' ), 'estimated_insurance_cost' => array( 'amount' => '10.00', 'currency_code' => 'RUB' ), 'estimated_delivery_days' => 4 ) ),
				array( 'request_id' => 102, 'error' => array( 'code' => 'posting_failed', 'message' => 'bad posting' ) ),
			),
		),
		array( 101, 102 ),
		'777',
		42
	);
	oz_quote_assert( false, 'Partial multi-box carrier error must fail the whole quote.' );
} catch ( OzonDeliveryQuoteException $exception ) {
	oz_quote_assert( 'posting_failed' === $exception->safe_code, 'Partial multi-box errors must remain fail-closed with the Ozon error code.' );
}
$split_builder = new OzonDeliveryQuoteRequestBuilder( $settings, $phones );
$declared_2985 = $split_builder->build( $request, new PackagingResult( array( new PackagingParcel( 500, 20, 10, 10, 3 ) ), array( 'declared_value_rub' => '2985.00' ) ), '777' );
oz_quote_assert( 3 === count( $declared_2985['body']['postings'] ) && array( '995.00' ) === array_values( array_unique( array_map( static fn( array $posting ): string => (string) $posting['declared_value']['amount'], $declared_2985['body']['postings'] ) ) ), 'Declared value 2985 RUB over 3 postings must become 995.00 RUB each.' );
$declared_3001 = $split_builder->build( $request, new PackagingResult( array( new PackagingParcel( 500, 20, 10, 10, 2 ), new PackagingParcel( 500, 20, 10, 10, 1 ) ), array( 'declared_value_rub' => '3001.00' ) ), '777' );
oz_quote_assert( 3 === count( $declared_3001['body']['postings'] ) && array( '1001.00' ) === array_values( array_unique( array_map( static fn( array $posting ): string => (string) $posting['declared_value']['amount'], $declared_3001['body']['postings'] ) ) ) && '3001.00' === (string) $declared_3001['diagnostics']['total_declared_value_rub'] && '1001.00' === (string) $declared_3001['diagnostics']['declared_value_per_posting_rub'], 'Declared value split must ceil 3001/3 to a whole 1001 RUB per posting and expose safe diagnostics.' );
$declared_5000_single = $split_builder->build( $request, new PackagingResult( array( new PackagingParcel( 1000, 20, 10, 10, 1 ) ), array( 'declared_value_rub' => '5000.00' ) ), '777' );
oz_quote_assert( 1 === count( $declared_5000_single['body']['postings'] ) && '5000.00' === (string) $declared_5000_single['body']['postings'][0]['declared_value']['amount'], 'Single posting must keep full declared value.' );
$declared_5000_two = $split_builder->build( $request, new PackagingResult( array( new PackagingParcel( 1000, 20, 10, 10, 2 ) ), array( 'declared_value_rub' => '5000.00' ) ), '777' );
oz_quote_assert( 2 === count( $declared_5000_two['body']['postings'] ) && array( '2500.00' ) === array_values( array_unique( array_map( static fn( array $posting ): string => (string) $posting['declared_value']['amount'], $declared_5000_two['body']['postings'] ) ) ), 'Declared value 5000 RUB over 2 postings must become 2500.00 RUB each.' );
oz_quote_assert( 100100 * 3 >= 300100 && ( 100100 * 3 - 300100 ) < 3 * 100, 'Ceil-per-posting declared value must not underinsure and must over by less than posting_count RUB.' );
$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Quote/OzonDeliveryQuoteService.php' ) ?: '';
oz_quote_assert( ! str_contains( $source, 'wp_remote_' ) && ! str_contains( $source, 'access_token' ) && str_contains( $source, 'PackagingBuilder' ), 'Quote layer must reuse ApiClient/PackagingBuilder and avoid transport/secrets.' );
echo "Ozon Delivery quote smoke passed.\n";
