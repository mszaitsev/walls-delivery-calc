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
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryOfferCollection;
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
		if ( $next instanceof YandexDeliveryApiException ) { throw $next; }
		if ( ! $next instanceof YandexDeliveryApiResponse ) { throw new RuntimeException( 'Unexpected empty fake HTTP queue.' ); }
		return $next;
	}
}

function yd_shipment_http_settings(): YandexDeliverySettings {
	$GLOBALS['yd_shipment_http_options'] = array();
	$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
	$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'secret-test-token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'SRC-1' ) );
	return $settings;
}
function yd_shipment_http_response( array $body, int $code = 200, array $headers = array() ): YandexDeliveryApiResponse {
	return new YandexDeliveryApiResponse( $code, json_encode( $body, JSON_UNESCAPED_UNICODE ) ?: '{}', $headers );
}
function yd_offer( string $id, string $policy, string $min, string $max, string $pickup_max, string $price, array $extra = array() ): array {
	return array_merge(
		array(
			'offer_id' => $id,
			'expires_at' => '2026-07-11T16:23:01.000000Z',
			'station_id' => 'station-' . $id,
			'offer_details' => array(
				'delivery_interval' => array( 'min' => $min, 'max' => $max, 'policy' => $policy ),
				'pickup_interval' => array( 'min' => '2026-07-11T16:08:01.000000Z', 'max' => $pickup_max ),
				'pricing' => $price,
				'pricing_total' => $price,
				'features' => array(),
			),
		),
		$extra
	);
}
function yd_info_response( string $request_id = 'REQ-1', array $places = array( array( 'barcode' => 'YD6A526A61E86E2204C' ) ) ): array {
	return array(
		'route_id' => null,
		'request_id' => $request_id,
		'request' => array(
			'info' => array( 'operator_request_id' => 'ORDER-123', 'referral_source' => 'logistic-platform-offers' ),
			'source' => array( 'platform_station' => array( 'platform_id' => 'SRC-1' ) ),
			'destination' => array(
				'type' => 'custom_location',
				'custom_location' => array( 'details' => array( 'country' => 'Россия', 'locality' => 'Москва', 'street' => 'Ходынский бульвар', 'house' => '9', 'full_address' => 'Москва, Ходынский бульвар, 9', 'geo_id' => 117030 ) ),
				'interval_utc' => array( 'from' => '2026-07-21T07:00:00+0000', 'to' => '2026-07-21T20:00:00+0000' ),
			),
			'items' => array( array( 'count' => 1, 'name' => 'Item A', 'article' => 'A', 'barcode' => 'e52b919d1905097519e2615d15a3faa6', 'billing_details' => array( 'nds' => -1, 'unit_price' => 10000, 'assessed_unit_price' => 10000 ), 'physical_dims' => array( 'dx' => 0, 'dy' => 0, 'dz' => 0 ), 'place_barcode' => $places[0]['barcode'] ?? 'YD6A526A61E86E2204C', 'refused_count' => 0 ) ),
			'places' => $places,
			'billing_info' => array( 'payment_method' => 'already_paid', 'delivery_cost' => 0 ),
			'recipient_info' => array( 'first_name' => 'Михайлов Михаил', 'last_name' => '', 'patronymic' => '', 'phone' => '+79131234567', 'email' => 'buyer@example.test' ),
			'available_actions' => array( 'update_recipient' => true, 'update_places' => true ),
			'delivery_policy' => array( 'min' => 1784617200, 'max' => 1784664000, 'policy' => 'interval_strict' ),
		),
		'state' => array( 'status' => 'CREATED', 'description' => 'Заказ создан в операторе', 'timestamp_unix' => 1783786240, 'timestamp' => '2026-07-11T16:10:40.000000Z' ),
		'full_items_price' => 10000,
		'sharing_url' => 'https://dostavka.yandex.ru/route/example',
		'self_pickup_node_code' => array( 'type' => 'pickup', 'code' => '00000' ),
		'courier_order_id' => '880191690',
	);
}
function yd_history_response(): array {
	return array( 'state_history' => array( array( 'status' => 'CREATED', 'description' => 'Заказ создан в операторе', 'timestamp' => 1783783543, 'timestamp_utc' => '2026-07-11T15:25:43.000000Z' ), array( 'status' => 'CANCELLED', 'description' => 'Заказ отменен', 'reason' => 'SHOP_CANCELLED', 'timestamp' => 1783784580, 'timestamp_utc' => '2026-07-11T15:43:00.000000Z' ) ) );
}
function yd_shipment_allocation(): ShipmentAllocation {
	return new ShipmentAllocation( array( new ShipmentAllocationPlace( 1, 1000, 20, 15, 10, array( new ShipmentAllocationItem( 'item-a', array( 'order_item_id' => 'item-a' ), 'Item A', 'A', 1, 10000, 10000, 300 ) ) ) ) );
}
function yd_shipment_context(): array {
	return array( 'operator_request_id' => 'ORDER-123', 'source_platform_station_id' => 'SRC-1', 'ready_from' => new DateTimeImmutable( '2026-07-12 12:00:00+07:00' ), 'ready_to' => new DateTimeImmutable( '2026-07-12 12:00:00+07:00' ), 'recipient' => array( 'first_name' => 'Михаил', 'last_name' => 'Михайлов', 'phone' => '9131234567', 'email' => 'buyer@example.test' ), 'destination' => array( 'mode' => 'pickup', 'platform_station_id' => 'PVZ-1' ) );
}

$settings = yd_shipment_http_settings();
$fake = new YdShipmentHttpFake( array(
	yd_shipment_http_response( array( 'offers' => array( yd_offer( 'offer-late', 'self_pickup', '2026-07-21T07:00:00.000000Z', '2026-07-21T20:00:00.000000Z', '2026-07-13T05:00:00.000000Z', '298.8 RUB' ), yd_offer( 'offer-early-expensive', 'self_pickup', '2026-07-20T07:00:00.000000Z', '2026-07-20T20:00:00.000000Z', '2026-07-13T05:00:00.000000Z', '542.8 RUB' ), yd_offer( 'offer-courier', 'time_interval', '2026-07-20T07:00:00.000000Z', '2026-07-20T20:00:00.000000Z', '2026-07-13T05:00:00.000000Z', '300,5 RUB' ), array( 'offer_id' => 'malformed', 'offer_details' => array( 'pricing_total' => '1 RUB' ) ) ) ) ),
	yd_shipment_http_response( array( 'request_id' => 'REQ-1' ) ),
	yd_shipment_http_response( yd_info_response() ),
	yd_shipment_http_response( yd_history_response() ),
	yd_shipment_http_response( array( 'status' => 'CREATED', 'description' => 'Заказ отменяется', 'reason' => 'cancellation_started' ) ),
) );
$client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( $settings, $fake ) );
$payload = ( new YandexDeliveryShipmentPayloadBuilder() )->build( yd_shipment_allocation(), yd_shipment_context() );
$offers = $client->create_offers( $payload );
yd_shipment_http_assert( 3 === count( $offers->offers ), 'Malformed production offer must be skipped.' );
yd_shipment_http_assert( 'self_pickup' === $offers->offers[0]->last_mile_policy && 'time_interval' === $offers->offers[2]->last_mile_policy, 'Offer policy must be parsed from offer_details.delivery_interval.policy.' );
yd_shipment_http_assert( 29880 === $offers->offers[0]->pricing_total_kopecks && 54280 === $offers->offers[1]->pricing_total_kopecks && 30050 === $offers->offers[2]->pricing_total_kopecks, 'RUB strings must be parsed to kopecks.' );
$selected = ( new YandexDeliveryEarliestOfferSelector() )->select( $offers, 'self_pickup' );
yd_shipment_http_assert( null !== $selected && 'offer-early-expensive' === $selected->offer_id && '2026-07-11T16:23:01.000000Z' === $selected->expires_at, 'Selector must choose earliest production offer and preserve expires_at.' );
$confirmed = $client->confirm_offer( $selected );
$info = $client->request_info( $confirmed->request_id, $payload['places'] );
yd_shipment_http_assert( 'REQ-1' === $info->request_id && '880191690' === $info->courier_order_id && 'https://dostavka.yandex.ru/route/example' === $info->sharing_url && 'CREATED' === $info->status, 'RequestInfo must parse top-level ids, sharing URL and state.status.' );
yd_shipment_http_assert( 'custom_location' === $info->destination['type'] && 'Михайлов Михаил' === $info->recipient['first_name'] && 'YD6A526A61E86E2204C' === $info->places[0]['barcode'] && 'YD6A526A61E86E2204C' === $info->items[0]['place_barcode'], 'RequestInfo must parse nested request destination, recipient, items and places with real barcodes.' );
yd_shipment_http_assert( 'ORDER-123-1' === array_key_first( $info->place_barcode_map ) && 'YD6A526A61E86E2204C' === $info->place_barcode_map['ORDER-123-1'] && 'e52b919d1905097519e2615d15a3faa6' === $info->items[0]['barcode'], 'Temporary-to-real barcode map and item barcode must be preserved.' );
yd_shipment_http_assert( '00000' === $info->self_pickup_node_code && 'pickup' === $info->self_pickup_node_type, 'RequestInfo must preserve self_pickup_node_code.code as a string with leading zeros.' );
$history = $client->request_history( 'REQ-1' );
yd_shipment_http_assert( 2 === count( $history->events ) && 'CANCELLED' === $history->events[1]['status'] && 'SHOP_CANCELLED' === $history->events[1]['reason'] && '2026-07-11T15:43:00.000000Z' === $history->events[1]['timestamp_utc'], 'state_history must be parsed with reason and UTC timestamp.' );
$cancel = $client->cancel_request( 'REQ-1' );
yd_shipment_http_assert( 'REQ-1' === $cancel->request_id && 'CREATED' === $cancel->status && 'Заказ отменяется' === $cancel->description && 'cancellation_started' === $cancel->reason, 'Async cancel response must be preserved as operation state.' );

$label_fake = new YdShipmentHttpFake( array( new YandexDeliveryApiResponse( 200, "%PDF-1.4\n% yandex label", array( 'content-type' => 'application/pdf' ) ) ) );
$label_client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), $label_fake ) );
$label = $label_client->generate_labels( array( 'abc-udp' ) );
yd_shipment_http_assert( "%PDF-" === substr( $label->body, 0, 5 ) && 'application/pdf' === strtolower( $label->content_type ), 'Yandex generate-labels must return binary PDF response without JSON decoding.' );
$label_body = json_decode( (string) ( $label_fake->requests[0]['args']['body'] ?? '{}' ), true );
yd_shipment_http_assert( YandexDeliveryEndpoints::REQUEST_GENERATE_LABELS_PATH === ( parse_url( $label_fake->requests[0]['url'], PHP_URL_PATH ) ?: '' ) && 'POST' === $label_fake->requests[0]['method'] && array( 'abc-udp' ) === (array) ( $label_body['request_ids'] ?? array() ) && 'one' === (string) ( $label_body['generate_type'] ?? '' ) && 'ru' === (string) ( $label_body['language'] ?? '' ), 'generate-labels must call documented endpoint with request_ids array/generate_type/language body.' );

$urls = array_column( $fake->requests, 'url' );
$paths = array_map( static fn( string $url ): string => parse_url( $url, PHP_URL_PATH ) ?: '', $urls );
yd_shipment_http_assert( array( YandexDeliveryEndpoints::OFFERS_CREATE_PATH, YandexDeliveryEndpoints::OFFERS_CONFIRM_PATH, YandexDeliveryEndpoints::REQUEST_INFO_PATH, YandexDeliveryEndpoints::REQUEST_HISTORY_PATH, YandexDeliveryEndpoints::REQUEST_CANCEL_PATH ) === $paths, 'Shipment client must call documented endpoint paths.' );
yd_shipment_http_assert( array( 'POST', 'POST', 'GET', 'GET', 'POST' ) === array_column( $fake->requests, 'method' ), 'Shipment client must use documented HTTP methods.' );
parse_str( (string) parse_url( $urls[0], PHP_URL_QUERY ), $create_query );
parse_str( (string) parse_url( $urls[2], PHP_URL_QUERY ), $info_query );
parse_str( (string) parse_url( $urls[3], PHP_URL_QUERY ), $history_query );
yd_shipment_http_assert( 'false' === (string) $create_query['send_unix'] && 'REQ-1' === (string) $info_query['request_id'] && 'false' === (string) $info_query['slim'] && 'REQ-1' === (string) $history_query['request_id'], 'Shipment URLs must contain send_unix=false and GET query request_id/slim values.' );
yd_shipment_http_assert( isset( $fake->requests[0]['args']['body'] ) && ! isset( $fake->requests[2]['args']['body'] ) && ! isset( $fake->requests[3]['args']['body'] ), 'POST must keep JSON body and GET must not send JSON body.' );
yd_shipment_http_assert( isset( $fake->requests[2]['args']['headers']['Authorization'], $fake->requests[2]['args']['headers']['Accept'] ) && ! isset( $fake->requests[2]['args']['headers']['Content-Type'] ), 'GET must keep auth/accept headers without JSON content type.' );
yd_shipment_http_assert( ! str_contains( implode( ' ', $paths ), 'request/create' ), 'request/create must not be implemented or called.' );

$service_fake = new YdShipmentHttpFake( array( yd_shipment_http_response( array( 'offers' => array( yd_offer( 'offer-service', 'self_pickup', '2026-07-20T07:00:00.000000Z', '2026-07-20T20:00:00.000000Z', '2026-07-13T05:00:00.000000Z', '250 RUB' ) ) ) ), yd_shipment_http_response( array( 'request_id' => 'REQ-SERVICE' ) ), yd_shipment_http_response( yd_info_response( 'REQ-SERVICE', array( array( 'barcode' => 'YD-SERVICE-1' ) ) ) ) ) );
$service = new YandexDeliveryShipmentRegistrationService( new YandexDeliveryShipmentPayloadBuilder(), new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), $service_fake ) ) );
$result = $service->register( yd_shipment_allocation(), yd_shipment_context() );
yd_shipment_http_assert( 'offer-service' === $result->selected_offer->offer_id && 'REQ-SERVICE' === $result->confirmed_request->request_id && 'REQ-SERVICE' === $result->request_info->request_id && 'YD-SERVICE-1' === $result->request_info->place_barcode_map['ORDER-123-1'], 'Registration service must return production DTO result with real barcode map.' );

$empty_service = new YandexDeliveryShipmentRegistrationService( new YandexDeliveryShipmentPayloadBuilder(), new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), new YdShipmentHttpFake( array( yd_shipment_http_response( array( 'offers' => array( array( 'offer_id' => 'broken', 'offer_details' => array( 'pricing_total' => '1 RUB' ) ) ) ) ) ) ) ) ) );
try {
	$empty_service->register( yd_shipment_allocation(), yd_shipment_context() );
	throw new RuntimeException( 'Empty/malformed offers must fail.' );
} catch ( YandexDeliveryApiException $exception ) {
	yd_shipment_http_assert( 'empty_matching_offers' === (string) ( $exception->details()['error_code'] ?? '' ), 'Empty or malformed offers must produce deterministic exception.' );
}

$mismatch_client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), new YdShipmentHttpFake( array( yd_shipment_http_response( yd_info_response( 'OTHER-REQ' ) ) ) ) ) );
try {
	$mismatch_client->request_info( 'REQ-1', $payload['places'] );
	throw new RuntimeException( 'Request id mismatch must fail.' );
} catch ( YandexDeliveryApiException $exception ) {
	yd_shipment_http_assert( 'request_info_id_mismatch' === (string) ( $exception->details()['error_code'] ?? '' ) && 'REQ-1' === (string) $exception->details()['expected_request_id'] && 'OTHER-REQ' === (string) $exception->details()['actual_request_id'], 'request/info id mismatch must be protected.' );
}
$place_mismatch_client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), new YdShipmentHttpFake( array( yd_shipment_http_response( yd_info_response( 'REQ-1', array() ) ) ) ) ) );
try {
	$place_mismatch_client->request_info( 'REQ-1', $payload['places'] );
	throw new RuntimeException( 'Place count mismatch must fail.' );
} catch ( YandexDeliveryApiException $exception ) {
	yd_shipment_http_assert( 'request_info_places_count_mismatch' === (string) ( $exception->details()['error_code'] ?? '' ), 'Barcode map must reject place count mismatch.' );
}

$api_error_client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), new YdShipmentHttpFake( array( new YandexDeliveryApiResponse( 500, '{"code":"server_error","message":"try later"}' ) ) ) ) );
try {
	$api_error_client->request_info( 'REQ-1' );
	throw new RuntimeException( 'GET API error must fail.' );
} catch ( YandexDeliveryApiException $exception ) {
	yd_shipment_http_assert( 500 === $exception->http_code() && str_contains( $exception->error_body(), 'server_error' ) && 'server_error' === (string) ( $exception->decoded_response()['code'] ?? '' ) && 'REQ-1' === (string) ( $exception->details()['request']['request_id'] ?? '' ) && ! str_contains( serialize( $exception->details() ), 'secret-test-token' ), 'GET API exception must expose code/body/decoded response without auth token.' );
}

$empty_info_client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), new YdShipmentHttpFake( array( new YandexDeliveryApiResponse( 200, '' ) ) ) ) );
try {
	$empty_info_client->request_info( 'REQ-EMPTY-INFO' );
	throw new RuntimeException( 'GET request/info empty JSON must fail.' );
} catch ( YandexDeliveryApiException $exception ) {
	$request = is_array( $exception->details()['request'] ?? null ) ? $exception->details()['request'] : array();
	yd_shipment_http_assert( 'empty_json' === (string) ( $exception->details()['error_code'] ?? '' ) && 'REQ-EMPTY-INFO' === (string) ( $request['request_id'] ?? '' ) && 'false' === (string) ( $request['slim'] ?? '' ), 'GET request/info empty JSON diagnostics must preserve query request_id and slim.' );
}

$empty_history_client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), new YdShipmentHttpFake( array( new YandexDeliveryApiResponse( 200, '' ) ) ) ) );
try {
	$empty_history_client->request_history( 'REQ-EMPTY-HISTORY' );
	throw new RuntimeException( 'GET request/history empty JSON must fail.' );
} catch ( YandexDeliveryApiException $exception ) {
	$request = is_array( $exception->details()['request'] ?? null ) ? $exception->details()['request'] : array();
	yd_shipment_http_assert( 'empty_json' === (string) ( $exception->details()['error_code'] ?? '' ) && 'REQ-EMPTY-HISTORY' === (string) ( $request['request_id'] ?? '' ), 'GET request/history empty JSON diagnostics must preserve query request_id.' );
}

$empty_offers_client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), new YdShipmentHttpFake( array( new YandexDeliveryApiResponse( 200, '' ) ) ) ) );
try {
	$empty_offers_client->create_offers( $payload );
	throw new RuntimeException( 'POST offers/create empty JSON must fail.' );
} catch ( YandexDeliveryApiException $exception ) {
	$request = is_array( $exception->details()['request'] ?? null ) ? $exception->details()['request'] : array();
	yd_shipment_http_assert( 'empty_json' === (string) ( $exception->details()['error_code'] ?? '' ) && 'ORDER-123' === (string) ( $request['info']['operator_request_id'] ?? '' ) && ! isset( $request['send_unix'] ), 'POST offers/create empty JSON diagnostics must keep JSON payload, not query.' );
}

$confirm_error_fake = new YdShipmentHttpFake( array( yd_shipment_http_response( array( 'offers' => array( yd_offer( 'offer-confirm-error', 'self_pickup', '2026-07-20T07:00:00.000000Z', '2026-07-20T20:00:00.000000Z', '2026-07-13T05:00:00.000000Z', '250 RUB' ) ) ) ), new YandexDeliveryApiException( 'network after confirm', array( 'error_code' => 'transport_error' ) ) ) );
$confirm_error_service = new YandexDeliveryShipmentRegistrationService( new YandexDeliveryShipmentPayloadBuilder(), new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), $confirm_error_fake ) ) );
try {
	$confirm_error_service->register( yd_shipment_allocation(), yd_shipment_context() );
	throw new RuntimeException( 'Confirm transport error must fail.' );
} catch ( YandexDeliveryApiException ) {
	yd_shipment_http_assert( 2 === count( $confirm_error_fake->requests ) && YandexDeliveryEndpoints::OFFERS_CONFIRM_PATH === ( parse_url( $confirm_error_fake->requests[1]['url'], PHP_URL_PATH ) ?: '' ), 'Confirm transport error must not retry confirm or continue to request/info.' );
}

$info_error_fake = new YdShipmentHttpFake( array( yd_shipment_http_response( array( 'offers' => array( yd_offer( 'offer-info-error', 'self_pickup', '2026-07-20T07:00:00.000000Z', '2026-07-20T20:00:00.000000Z', '2026-07-13T05:00:00.000000Z', '250 RUB' ) ) ) ), yd_shipment_http_response( array( 'request_id' => 'REQ-AFTER-CONFIRM' ) ), new YandexDeliveryApiException( 'info failed', array( 'error_code' => 'info_failed' ) ) ) );
$info_error_service = new YandexDeliveryShipmentRegistrationService( new YandexDeliveryShipmentPayloadBuilder(), new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_shipment_http_settings(), $info_error_fake ) ) );
try {
	$info_error_service->register( yd_shipment_allocation(), yd_shipment_context() );
	throw new RuntimeException( 'Info after confirm error must fail.' );
} catch ( YandexDeliveryApiException $exception ) {
	yd_shipment_http_assert( 3 === count( $info_error_fake->requests ) && 'request_info_after_confirm_failed' === (string) ( $exception->details()['error_code'] ?? '' ) && 'REQ-AFTER-CONFIRM' === (string) ( $exception->details()['confirmed_request_id'] ?? '' ), 'request/info failure after confirm must not retry confirm and must preserve confirmed_request_id.' );
}

$client_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Shipment/YandexDeliveryShipmentClient.php' );
$service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Shipment/YandexDeliveryShipmentRegistrationService.php' );
yd_shipment_http_assert( ! str_contains( $client_source . $service_source, 'wp_remote_request' ) && ! str_contains( $client_source . $service_source, 'curl' ) && ! str_contains( $client_source . $service_source, 'request/create' ), 'Shipment HTTP layer must use existing API client and must not implement curl/request-create.' );

echo "Yandex delivery shipment HTTP smoke test passed.\n";
