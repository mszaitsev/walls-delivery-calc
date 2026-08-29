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
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteException;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteParser;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteRequestBuilder;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteService;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
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

final class OzonQuoteSmokeWpdb {
	public string $prefix = 'wp_';
	public function prepare( string $query, mixed ...$values ): string { foreach ( $values as $value ) { $query = preg_replace( '/%[df]/', is_float( $value ) ? sprintf( '%.8F', $value ) : (string) (int) $value, $query, 1 ) ?? $query; } return $query; }
	public function get_row( string $query, mixed $output = null ): ?array {
		if ( str_contains( $query, "WHERE state='active'" ) ) { return array( 'id' => 1, 'state' => 'active' ); }
		if ( str_contains( $query, 'point_id=777' ) ) { return $this->point( 777, 55.0301, 82.9201 ); }
		return null;
	}
	public function get_results( string $query, mixed $output = null ): array { return array( $this->point( 777, 55.0301, 82.9201 ), $this->point( 778, 55.5000, 83.0000 ) ); }
	private function point( int $id, float $lat, float $lng ): array { return array( 'generation_id' => 1, 'point_id' => $id, 'name' => 'ПВЗ Ozon', 'type' => 'pvz', 'full_address' => 'Новосибирск', 'latitude' => $lat, 'longitude' => $lng, 'schedule' => 'Ежедневно 09:00-21:00', 'is_active' => 1, 'is_bulky' => 0, 'min_weight_g' => 10, 'max_weight_g' => 25000, 'max_width_mm' => 600, 'max_length_mm' => 1200, 'max_height_mm' => 800 ); }
}

$settings_repo = new SettingsRepository();
$settings = new OzonDeliverySettings( $settings_repo );
$settings->save_pricing_settings( array( OzonDeliverySettings::SHIPMENT_METHOD_ID_KEY => '42' ) );
$credentials = new OzonDeliveryCredentials( $settings_repo, new EncryptionService(), new OzonDeliveryTokenCache( new EncryptionService() ) );
$credentials->save_from_admin( array( OzonDeliverySettings::CLIENT_ID_KEY => 'client', 'ozon_delivery_client_secret' => 'secret' ) );
$http = new OzonQuoteSmokeHttp();
$sanitizer = new OzonDeliveryMessageSanitizer();
$api = new OzonDeliveryApiClient( $http, new OzonDeliveryAccessTokenService( $credentials, $http, $sanitizer, new OzonDeliveryTokenCache( new EncryptionService() ) ) );
$service = new OzonDeliveryQuoteService( $api, new OzonDeliveryQuoteRequestBuilder( $settings ), new OzonDeliveryQuoteParser( $sanitizer ), new PackagingBuilder( PackagingBuilderConfig::defaults() ), new OzonDeliveryPickupPointProvider( new OzonDeliveryPickupRepository( new OzonQuoteSmokeWpdb() ) ), $sanitizer );
$money = Money::from_rubles( 1000 );
$request = new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Новосибирск' ), new Package( array(), $money, $money, 1000, 0, 1000, 10, 10, 10, 1000, 'manual' ), '', $money, '2026-08-29', array( 'recipient_phone' => '+79991234567', 'destination_latitude' => 55.0300, 'destination_longitude' => 82.9200 ) );
$result = $service->quote_pickup( $request );
oz_quote_assert( 12345 === $result->price->get_kopecks() && 'RUB' === $result->price->get_currency(), 'Parser must normalize RUB amount to Money.' );
oz_quote_assert( 2 === $result->delivery_days->max_days && '777' === $result->destination_point_id && 1 === $result->package_count, 'Quote must use nearest representative Ozon point and normalize days.' );
$checkout_call = $http->calls[1] ?? array();
oz_quote_assert( 'POST' === ( $checkout_call['method'] ?? '' ) && str_ends_with( (string) ( $checkout_call['url'] ?? '' ), '/v1/order/checkout' ), 'Quote must call exact official order checkout endpoint.' );
$body = $checkout_call['body'];
oz_quote_assert( '+79991234567' === $body['recipient']['phone_number'] && 42 === $body['postings'][0]['shipment_method_id'] && 777 === $body['delivery']['delivery_point']['delivery_point_id'], 'Request body must contain recipient, shipment_method_id and destination delivery_point_id.' );
oz_quote_assert( 1000 === $body['postings'][0]['dimensions']['weight_g'] && 100 === $body['postings'][0]['dimensions']['length_mm'] && '1000.00' === $body['postings'][0]['declared_value']['amount'], 'Request body must use grams, millimetres and decimal RUB declared value.' );
try {
	( new OzonDeliveryQuoteParser( $sanitizer ) )->parse( array( 'results' => array( array( 'request_id' => 101, 'error' => array( 'code' => 'DeliveryPointRestrictionsError', 'message' => 'bad point' ) ) ) ), array( 101 ), '777', 42 );
	oz_quote_assert( false, 'Carrier error envelope must fail closed.' );
} catch ( OzonDeliveryQuoteException $exception ) {
	oz_quote_assert( 'DeliveryPointRestrictionsError' === $exception->safe_code, 'Official error code must be preserved safely.' );
}
$source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Quote/OzonDeliveryQuoteService.php' ) ?: '';
oz_quote_assert( ! str_contains( $source, 'wp_remote_' ) && ! str_contains( $source, 'access_token' ) && str_contains( $source, 'PackagingBuilder' ), 'Quote layer must reuse ApiClient/PackagingBuilder and avoid transport/secrets.' );
echo "Ozon Delivery quote smoke passed.\n";
