<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
define( 'WDC_SECRET_KEY', 'yandex-shipment-http-smoke-key' );

function yd_shipment_http_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['yd_shipment_http_options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value, bool $autoload = true ): bool { $GLOBALS['yd_shipment_http_options'][ $name ] = $value; return true; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryEarliestOfferSelector;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentClient;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentPayloadBuilder;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentRegistrationService;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliveryEndpoints;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocation;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationItem;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationPlace;

final class YdShipmentHttpFake implements YandexDeliveryHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	/** @param array<int,YandexDeliveryApiResponse|YandexDeliveryApiException> $queue */
	public function __construct( private array $queue ) {}
	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		$next = array_shift( $this->queue );
		if ( $next instanceof YandexDeliveryApiException ) {
			throw $next;
		}
		if ( ! $next instanceof YandexDeliveryApiResponse ) {
			throw new RuntimeException( 'Unexpected empty fake HTTP queue.' );
		}

		return $next;
	}
}

function yd_shipment_http_settings(): YandexDeliverySettings {
	$GLOBALS['yd_shipment_http_options'] = array();
	$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
	$settings->save_from_admin( array(
		YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST,
		'yandex_delivery_test_bearer_token' => 'secret-test-token',
		YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'SRC-1',
	) );

	return $settings;
}
function yd_shipment_http_response( array $body, int $code = 200 ): YandexDeliveryApiResponse {
	return new YandexDeliveryApiResponse( $code, json_encode( $body, JSON_UNESCAPED_UNICODE ) ?: '{}' );
}
function yd_shipment_allocation(): ShipmentAllocation {
	return new ShipmentAllocation( array( new ShipmentAllocationPlace( 1, 1000, 20, 15, 10, array( new ShipmentAllocationItem( 'item-a', array( 'order_item_id' => 'item-a' ), 'Item A', 'A', 1, 10000, 10000, 300 ) ) ) ) );
}
function yd_shipment_context(): array {
	return array(
		'operator_request_id' => 'ORDER-123',
		'source_platform_station_id' => 'SRC-1',
		'ready_from' => new DateTimeImmutable( '2026-07-12 12:00:00+07:00' ),
		'ready_to' => new DateTimeImmutable( '2026-07-12 12:00:00+07:00' ),
		'recipient' => array( 'first_name' => 'Михаил', 'last_name' => 'Михайлов', 'phone' => '9131234567', 'email' => 'buyer@example.test' ),
		'destination' => array( 'mode' => 'pickup', 'platform_station_id' => 'PVZ-1' ),
	);
}

$settings = yd_shipment_http_settings();
$fake = new YdShipmentHttpFake( array(
	yd_shipment_http_response( array( 'offers' => array( array( 'offer_id' => 'offer-late', 'last_mile_policy' => 'self_pickup', 'delivery_interval' => array( 'min' => '2026-07-15T10:00:00Z', 'max' => '2026-07-15T12:00:00Z' ), 'pickup_interval' => array( 'max' => '2026-07-15T09:00:00Z' ), 'pricing_total' => '300 RUB' ), array( 'offer_id' => 'offer-early', 'last_mile_policy' => 'self_pickup', 'delivery_interval' => array( 'min' => '2026-07-14T10:00:00Z', 'max' => '2026-07-14T12:00:00Z' ), 'pickup_interval' => array( 'max' => '2026-07-14T09:00:00Z' ), 'pricing_total' => '400 RUB' ) ) ) ),
	yd_shipment_http_response( array( 'request_id' => 'REQ-1' ) ),
	yd_shipment_http_response( array( 'request' => array( 'request_id' => 'REQ-1', 'courier_order_id' => 'COURIER-1', 'sharing_url' => 'https://ya.test/share/1', 'status' => 'CREATED', 'destination' => array( 'type' => 'platform_station' ), 'recipient_info' => array( 'first_name' => 'Михайлов Михаил' ), 'places' => array( array( 'barcode' => 'YD-REAL-1' ) ), 'items' => array( array( 'article' => 'A', 'place_barcode' => 'YD-REAL-1' ) ) ) ) ),
	yd_shipment_http_response( array( 'history' => array( array( 'status' => 'CREATED' ), array( 'status' => 'VALIDATING' ) ) ) ),
	yd_shipment_http_response( array( 'request_id' => 'REQ-1', 'status' => 'CANCELLED' ) ),
) );
$client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( $settings, $fake ) );
$payload = ( new YandexDeliveryShipmentPayloadBuilder() )->build( yd_shipment_allocation(), yd_shipment_context() );
$offers = $client->create_offers( $payload );
yd_shipment_http_assert( 2 === count( $offers->offers ), 'offers/create must return OfferCollection DTO.' );
$selected = ( new YandexDeliveryEarliestOfferSelector() )->select( $offers, 'self_pickup' );
yd_shipment_http_assert( null !== $selected && 'offer-early' === $selected->offer_id, 'selector must choose the earliest matching offer.' );
$confirmed = $client->confirm_offer( $selected );
yd_shipment_http_assert( 'REQ-1' === $confirmed->request_id && 'offer-early' === $confirmed->offer_id, 'offers/confirm must return ConfirmedRequest DTO.' );
$info = $client->request_info( $confirmed->request_id, $payload['places'] );
yd_shipment_http_assert( 'REQ-1' === $info->request_id && 'COURIER-1' === $info->courier_order_id && 'CREATED' === $info->status && 'YD-REAL-1' === $info->place_barcode_map['ORDER-123-1'], 'request/info must return canonical RequestInfo DTO with temporary-to-real barcode map.' );
$history = $client->request_history( 'REQ-1' );
yd_shipment_http_assert( 2 === count( $history->events ), 'request/history must return history DTO.' );
$cancel = $client->cancel_request( 'REQ-1' );
yd_shipment_http_assert( 'CANCELLED' === $cancel->status, 'request/cancel must return shipment state DTO.' );
$paths = array_map( static fn( array $request ): string => parse_url( $request['url'], PHP_URL_PATH ) ?: '', $fake->requests );
yd_shipment_http_assert( array( YandexDeliveryEndpoints::OFFERS_CREATE_PATH, YandexDeliveryEndpoints::OFFERS_CONFIRM_PATH, YandexDeliveryEndpoints::REQUEST_INFO_PATH, YandexDeliveryEndpoints::REQUEST_HISTORY_PATH, YandexDeliveryEndpoints::REQUEST_CANCEL_PATH ) === $paths, 'shipment client must call the documented endpoint sequence.' );
yd_shipment_http_assert( array( 'POST', 'POST', 'GET', 'GET', 'POST' ) === array_column( $fake->requests, 'method' ), 'shipment client must use documented HTTP methods.' );
yd_shipment_http_assert( ! str_contains( implode( ' ', $paths ), 'request/create' ), 'request/create must not be implemented or called.' );

$service_fake = new YdShipmentHttpFake( array(
	yd_shipment_http_response( array( 'offers' => array( array( 'offer_id' => 'offer-service', 'last_mile_policy' => 'self_pickup', 'delivery_interval' => array( 'min' => '2026-07-14T10:00:00Z', 'max' => '2026-07-14T12:00:00Z' ), 'pickup_interval' => array( 'max' => '2026-07-14T09:00:00Z' ), 'pricing_total' => '250 RUB' ) ) ) ),
	yd_shipment_http_response( array( 'request_id' => 'REQ-SERVICE' ) ),
	yd_shipment_http_response( array( 'request' => array( 'request_id' => 'REQ-SERVICE', 'courier_order_id' => 'COURIER-SERVICE', 'sharing_url' => 'https://ya.test/share/service', 'status' => 'CREATED', 'places' => array( array( 'barcode' => 'YD-SERVICE-1' ) ), 'items' => array( array( 'article' => 'A', 'place_barcode' => 'YD-SERVICE-1' ) ) ) ) ),
) );
$service = new YandexDeliveryShipmentRegistrationService( new YandexDeliveryShipmentPayloadBuilder(), new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), $service_fake ) ) );
$result = $service->register( yd_shipment_allocation(), yd_shipment_context() );
yd_shipment_http_assert( 'offer-service' === $result->selected_offer->offer_id && 'REQ-SERVICE' === $result->confirmed_request->request_id && 'REQ-SERVICE' === $result->request_info->request_id, 'registration service must run payload -> offers -> confirm -> info.' );
yd_shipment_http_assert( 3 === count( $service_fake->requests ), 'registration service must stop after mandatory request/info and not call history/cancel.' );

$empty_offer_fake = new YdShipmentHttpFake( array( yd_shipment_http_response( array( 'offers' => array() ) ) ) );
$empty_service = new YandexDeliveryShipmentRegistrationService( new YandexDeliveryShipmentPayloadBuilder(), new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), $empty_offer_fake ) ) );
try {
	$empty_service->register( yd_shipment_allocation(), yd_shipment_context() );
	throw new RuntimeException( 'Empty offers must fail.' );
} catch ( YandexDeliveryApiException $exception ) {
	yd_shipment_http_assert( 'empty_matching_offers' === (string) ( $exception->details()['error_code'] ?? '' ), 'empty offers must throw API exception with deterministic code.' );
}

$api_error_client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), new YdShipmentHttpFake( array( new YandexDeliveryApiResponse( 500, '{"code":"server_error","message":"try later"}' ) ) ) ) );
try {
	$api_error_client->create_offers( $payload );
	throw new RuntimeException( 'API error must fail.' );
} catch ( YandexDeliveryApiException $exception ) {
	yd_shipment_http_assert( 500 === $exception->http_code() && str_contains( $exception->error_body(), 'server_error' ) && 'server_error' === (string) ( $exception->decoded_response()['code'] ?? '' ), 'API exception must expose HTTP code, body and decoded response.' );
}

$confirm_error_fake = new YdShipmentHttpFake( array(
	yd_shipment_http_response( array( 'offers' => array( array( 'offer_id' => 'offer-confirm-error', 'last_mile_policy' => 'self_pickup', 'delivery_interval' => array( 'min' => '2026-07-14T10:00:00Z', 'max' => '2026-07-14T12:00:00Z' ), 'pickup_interval' => array( 'max' => '2026-07-14T09:00:00Z' ), 'pricing_total' => '250 RUB' ) ) ) ),
	new YandexDeliveryApiException( 'network after confirm', array( 'error_code' => 'transport_error' ) ),
) );
$confirm_error_service = new YandexDeliveryShipmentRegistrationService( new YandexDeliveryShipmentPayloadBuilder(), new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), $confirm_error_fake ) ) );
try {
	$confirm_error_service->register( yd_shipment_allocation(), yd_shipment_context() );
	throw new RuntimeException( 'Confirm transport error must fail.' );
} catch ( YandexDeliveryApiException $exception ) {
	yd_shipment_http_assert( 2 === count( $confirm_error_fake->requests ) && YandexDeliveryEndpoints::OFFERS_CONFIRM_PATH === ( parse_url( $confirm_error_fake->requests[1]['url'], PHP_URL_PATH ) ?: '' ), 'confirm transport error must not retry confirm or continue to request/info.' );
}

$client_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Shipment/YandexDeliveryShipmentClient.php' );
$service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Shipment/YandexDeliveryShipmentRegistrationService.php' );
yd_shipment_http_assert( ! str_contains( $client_source . $service_source, 'wp_remote_request' ) && ! str_contains( $client_source . $service_source, 'curl' ) && ! str_contains( $client_source . $service_source, 'request/create' ), 'shipment HTTP layer must use existing API client and must not implement curl/request-create.' );

echo "Yandex delivery shipment HTTP smoke test passed.\n";
