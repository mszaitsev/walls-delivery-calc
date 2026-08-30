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
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentAdapter;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentAllocationValueResolver;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentCreateRequestBuilder;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentCreateResponseParser;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentDescriptionBuilder;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentDocumentProvider;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentModalExtension;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentPersistenceMapper;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentPreflightQuoteService;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentService;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentStatusMapping;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Pickup\PickupPointSelection;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Domain\Phone\RussianPhoneNormalizer;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationAttemptService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;
use WallsShop\WDC\Shipments\Presentation\ShipmentActualCostComparisonService;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); } }
if ( ! function_exists( 'current_time' ) ) { function current_time( string $type = 'mysql', bool $gmt = false ): string { return gmdate( 'Y-m-d H:i:s' ); } }
if ( ! function_exists( 'sanitize_text_field' ) ) { function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); } }
if ( ! function_exists( 'wp_unslash' ) ) { function wp_unslash( mixed $value ): mixed { return $value; } }
if ( ! function_exists( 'get_option' ) ) { function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['oz_ship_options'][ $key ] ?? $default; } }
if ( ! function_exists( 'update_option' ) ) { function update_option( string $key, mixed $value, bool $autoload = true ): bool { $GLOBALS['oz_ship_options'][ $key ] = $value; return true; } }
if ( ! function_exists( 'add_option' ) ) { function add_option( string $key, mixed $value, string $deprecated = '', string $autoload = 'yes' ): bool { if ( array_key_exists( $key, $GLOBALS['oz_ship_options'] ) ) { return false; } $GLOBALS['oz_ship_options'][ $key ] = $value; return true; } }
if ( ! function_exists( 'delete_option' ) ) { function delete_option( string $key ): bool { unset( $GLOBALS['oz_ship_options'][ $key ] ); return true; } }
if ( ! function_exists( 'wc_get_logger' ) ) { function wc_get_logger(): object { return new class { public function log( string $level, string $message, array $context = array() ): void { $GLOBALS['oz_ship_logs'][] = array( 'level' => $level, 'message' => $message, 'context' => $context ); } }; } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( string $text, string $domain = 'default' ): string { return $text; } }
if ( ! function_exists( '__' ) ) { function __( string $text, string $domain = 'default' ): string { return $text; } }
if ( ! function_exists( 'esc_html' ) ) { function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); } }

function oz_ship_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class OzonShipmentSmokeHttp implements OzonDeliveryHttpClientInterface {
	/** @var array<int,array{method:string,url:string,body:array<string,mixed>,headers:array<string,mixed>}> */
	public array $calls = array();
	/** @var array<int,string> */
	public array $fail_approve = array();
	/** @var array<string,string> */
	public array $statuses = array();
	/** @var array<int,array{delivery:string,insurance:string,days:int}> */
	public array $checkout_quotes = array();
	public bool $fail_checkout = false;
	public bool $fail_info = false;
	public bool $approve_updates_status = true;

	public function request( string $method, string $url, array $args = array() ): OzonDeliveryApiResponse {
		$body = json_decode( (string) ( $args['body'] ?? '{}' ), true );
		$body = is_array( $body ) ? $body : array();
		$headers = is_array( $args['headers'] ?? null ) ? $args['headers'] : array();
		$this->calls[] = array( 'method' => $method, 'url' => $url, 'body' => $body, 'headers' => $headers );
		if ( str_contains( $url, '/oauth/token' ) ) {
			return new OzonDeliveryApiResponse( 200, '{"access_token":"token","expires_in":9999999999,"token_type":"bearer","scope":["delivery-api.all"]}', array( 'content-type' => 'application/json' ) );
		}
		if ( str_contains( $url, '/v1/order/checkout' ) ) {
			if ( $this->fail_checkout ) {
				return new OzonDeliveryApiResponse( 500, '{"error":{"code":"checkout_failed","message":"temporary"}}', array( 'content-type' => 'application/json' ) );
			}
			$results = array();
			foreach ( is_array( $body['postings'] ?? null ) ? $body['postings'] : array() as $posting ) {
				$id = (int) ( $posting['request_id'] ?? 0 );
				$quote = $this->checkout_quotes[ $id ] ?? array( 'delivery' => '106.00', 'insurance' => '10.00', 'days' => 3 );
				$results[] = array(
					'request_id' => $id,
					'posting' => array(
						'estimated_delivery_cost' => array( 'amount' => $quote['delivery'], 'currency_code' => 'RUB' ),
						'estimated_insurance_cost' => array( 'amount' => $quote['insurance'], 'currency_code' => 'RUB' ),
						'estimated_delivery_days' => $quote['days'],
					),
				);
			}
			return new OzonDeliveryApiResponse( 200, wp_json_encode( array( 'results' => $results ) ) ?: '{}', array( 'content-type' => 'application/json' ) );
		}
		if ( str_contains( $url, '/v1/order/create' ) ) {
			$postings = array();
			foreach ( is_array( $body['postings'] ?? null ) ? $body['postings'] : array() as $posting ) {
				$id = (int) ( $posting['request_id'] ?? 0 );
				$postings[] = array(
					'request_id' => $id,
					'posting_number' => 'OZON-' . $id,
					'posting_external_id' => (string) ( $posting['posting_external_id'] ?? '' ),
					'estimated_delivery_days' => 3,
					'cutoff_at' => '2026-08-30T12:00:00Z',
				);
			}
			return new OzonDeliveryApiResponse( 200, wp_json_encode( array( 'order_number' => 'ORDER-OZON-1', 'order_external_id' => (string) ( $body['order_external_id'] ?? '' ), 'postings' => $postings ) ) ?: '{}', array( 'content-type' => 'application/json' ) );
		}
		if ( str_contains( $url, '/v1/posting/approve' ) ) {
			$number = (string) ( $body['posting_number'] ?? '' );
			if ( in_array( $number, $this->fail_approve, true ) ) {
				return new OzonDeliveryApiResponse( 500, '{"error":{"code":"approve_failed","message":"temporary"}}', array( 'content-type' => 'application/json' ) );
			}
			if ( $this->approve_updates_status ) {
				$this->statuses[ $number ] = 'READY_FOR_SHIPPING';
			}
			return new OzonDeliveryApiResponse( 200, '{}', array( 'content-type' => 'application/json' ) );
		}
		if ( str_contains( $url, '/v1/posting/info' ) ) {
			if ( $this->fail_info ) {
				return new OzonDeliveryApiResponse( 500, '{"error":{"code":"info_failed","message":"temporary"}}', array( 'content-type' => 'application/json' ) );
			}
			$postings = array();
			foreach ( is_array( $body['posting_numbers'] ?? null ) ? $body['posting_numbers'] : array() as $number ) {
				$postings[] = array( 'posting_number' => (string) $number, 'status' => $this->statuses[ (string) $number ] ?? 'CREATED', 'status_changed_at' => '2026-08-30T12:00:00Z' );
			}
			return new OzonDeliveryApiResponse( 200, wp_json_encode( array( 'postings' => $postings ) ) ?: '{}', array( 'content-type' => 'application/json' ) );
		}
		if ( str_contains( $url, '/v1/posting/cancel' ) ) {
			$this->statuses[ (string) ( $body['posting_number'] ?? '' ) ] = 'CANCELED';
			return new OzonDeliveryApiResponse( 200, '{}', array( 'content-type' => 'application/json' ) );
		}
		if ( str_contains( $url, '/v1/posting/label' ) ) {
			return new OzonDeliveryApiResponse( 200, '%PDF-1.4 test', array( 'content-type' => 'application/pdf' ) );
		}
		return new OzonDeliveryApiResponse( 404, '{"error":{"code":"not_found","message":"not found"}}', array( 'content-type' => 'application/json' ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function calls_for( string $needle ): array {
		return array_values( array_filter( $this->calls, static fn( array $call ): bool => str_contains( $call['url'], $needle ) ) );
	}
}

final class OzonShipmentSmokeDb {
	public string $prefix = 'wp_';
	/** @var array<int,array<string,mixed>> */
	public array $points = array();

	public function prepare( string $query, mixed ...$values ): string {
		foreach ( $values as $value ) {
			$query = preg_replace( '/%[sdf]/', is_float( $value ) ? sprintf( '%.8F', $value ) : ( is_numeric( $value ) ? (string) (int) $value : "'" . (string) $value . "'" ), $query, 1 ) ?? $query;
		}
		return $query;
	}

	public function get_row( string $query, mixed $output = null ): ?array {
		if ( preg_match( '/point_id=(\d+)/', $query, $matches ) ) {
			return $this->points[ (int) $matches[1] ] ?? null;
		}
		if ( str_contains( $query, "state='active'" ) ) {
			return array( 'id' => 1, 'state' => 'active' );
		}
		return null;
	}
}

final class OzonShipmentSmokeOrderItem {
	public function __construct( private int $id, private int $quantity, private string $total ) {}
	public function get_id(): int { return $this->id; }
	public function get_quantity(): int { return $this->quantity; }
	public function get_total(): string { return $this->total; }
}

final class OzonShipmentSmokeOrder {
	/** @var array<string,mixed> */
	public array $meta = array();
	public function __construct( private int $id, private string $number, private array $items ) {}
	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return $this->number; }
	/** @return array<int,object> */
	public function get_items(): array { return $this->items; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
}

/** @return array{http:OzonShipmentSmokeHttp,service:ShipmentCreationService,adapter:OzonDeliveryShipmentAdapter,docs:OzonDeliveryShipmentDocumentProvider,modal:OzonDeliveryShipmentModalExtension} */
function oz_ship_stack( OzonShipmentSmokeDb $db ): array {
	$GLOBALS['oz_ship_options'] = array();
	$GLOBALS['oz_ship_logs'] = array();
	$encryption = new EncryptionService();
	$settings_repository = new SettingsRepository();
	$settings_repository->replace( array(
		OzonDeliverySettings::CLIENT_ID_KEY => 'client',
		OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY => $encryption->encrypt( 'secret' ),
		OzonDeliverySettings::SHIPMENT_METHOD_ID_KEY => 42,
	) );
	$settings = new OzonDeliverySettings( $settings_repository );
	$http = new OzonShipmentSmokeHttp();
	$credentials = new OzonDeliveryCredentials( $settings_repository, $encryption );
	$tokens = new OzonDeliveryAccessTokenService( $credentials, $http, new OzonDeliveryMessageSanitizer(), new OzonDeliveryTokenCache( $encryption ) );
	$api = new OzonDeliveryApiClient( $http, $tokens );
	$repository = new OrderShipmentRepository();
	$attempts = new ShipmentCreationAttemptService( $repository, static fn(): string => '11111111-1111-4111-8111-111111111111' );
	$builder = new OzonDeliveryShipmentCreateRequestBuilder( $settings, new RussianPhoneNormalizer(), new OzonDeliveryShipmentDescriptionBuilder(), new OzonDeliveryShipmentAllocationValueResolver() );
	$pickup_provider = new OzonDeliveryPickupPointProvider( new OzonDeliveryPickupRepository( $db ) );
	$service = new OzonDeliveryShipmentService( $api, $builder, new OzonDeliveryShipmentCreateResponseParser(), new OzonDeliveryShipmentPreflightQuoteService( $api, new OzonDeliveryQuoteParser( new OzonDeliveryMessageSanitizer() ) ), $pickup_provider, $repository, $attempts, new Logger() );
	$actual_cost = new ShipmentActualCostResolver( new ShipmentActualCostComparisonService(), new ShipmentBaseApiCostResolver() );
	$adapter = new OzonDeliveryShipmentAdapter( $service, $builder, $repository, $actual_cost );
	$creation = new ShipmentCreationService( $repository, array( $adapter ), new ShipmentActualCostService( $repository ), new Logger(), new CarrierShipmentAdapterRegistry( array( $adapter ) ), array( new OzonDeliveryShipmentPersistenceMapper() ), $attempts );

	return array(
		'http' => $http,
		'service' => $creation,
		'adapter' => $adapter,
		'docs' => new OzonDeliveryShipmentDocumentProvider( $api ),
		'modal' => new OzonDeliveryShipmentModalExtension( new OzonDeliveryPickupRepository( $db ) ),
	);
}

/** @param array<int,ShipmentPlace> $places @param array<int,array<string,mixed>> $rows */
function oz_ship_request( array $places, array $rows, string $point_code = '777', int $order_id = 85372, string $order_num = '85372' ): ShipmentCreateRequest {
	return new ShipmentCreateRequest(
		order_id: $order_id,
		carrier_key: OzonDeliverySettings::CARRIER_KEY,
		delivery_type: DeliveryType::PICKUP,
		rate_id: OzonDeliverySettings::PICKUP_FAMILY,
		recipient_address: new Address( country_code: 'RU', city: 'Новосибирск', raw_address: 'ПВЗ Ozon' ),
		pickup_point: new PickupPointSelection( OzonDeliverySettings::CARRIER_KEY, OzonDeliverySettings::SERVICE_KEY, $point_code, 'ПВЗ Ozon', '2026-08-30 12:00:00' ),
		places: $places,
		declared_value: Money::from_kopecks( 0 ),
		insurance_enabled: false,
		services: array(),
		recipient: array( 'name' => 'Иван Иванов', 'phone' => '+79132038250', 'email' => 'test@example.test' ),
		meta: array(
			'service_key' => OzonDeliverySettings::SERVICE_KEY,
			'order_num' => $order_num,
			'pickup_point_code' => $point_code,
			'shipment_item_rows' => $rows,
		)
	);
}

$db = new OzonShipmentSmokeDb();
$db->points[777] = array( 'generation_id' => 1, 'point_id' => 777, 'name' => 'ПВЗ Ozon', 'type' => 'pvz', 'full_address' => 'Новосибирск', 'latitude' => 55.03, 'longitude' => 82.92, 'schedule' => '09:00-21:00', 'is_active' => 1, 'min_weight_g' => 1, 'max_weight_g' => 10000, 'max_length_mm' => 500, 'max_width_mm' => 500, 'max_height_mm' => 300 );
$db->points[888] = array( 'generation_id' => 1, 'point_id' => 888, 'name' => 'Строгий ПВЗ Ozon', 'type' => 'pvz', 'full_address' => 'Новосибирск', 'latitude' => 55.03, 'longitude' => 82.92, 'schedule' => '09:00-21:00', 'is_active' => 1, 'min_weight_g' => 1, 'max_weight_g' => 9000, 'max_length_mm' => 400, 'max_width_mm' => 400, 'max_height_mm' => 250 );

$order = new OzonShipmentSmokeOrder( 85372, '85372', array( new OzonShipmentSmokeOrderItem( 101, 3, '3000.00' ) ) );
$rows = array(
	array( 'item_key' => 'shipment-ui-row-a', 'ordered_quantity' => 3, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ),
	array( 'item_key' => 'shipment-ui-row-a:split:2', 'split_parent' => 'shipment-ui-row-a', 'ordered_quantity' => 3, 'place_number' => 2, 'amount' => 2, 'cost' => 1000 ),
);
$stack = oz_ship_stack( $db );
$request = oz_ship_request( array(
	new ShipmentPlace( 1, 5000, 40, 30, 20, Money::from_kopecks( 0 ) ),
	new ShipmentPlace( 2, 9000, 50, 30, 20, Money::from_kopecks( 0 ) ),
), $rows );
$pickup_preview_request = new ShipmentCreateRequest(
	order_id: 85372,
	carrier_key: OzonDeliverySettings::CARRIER_KEY,
	delivery_type: DeliveryType::PICKUP,
	rate_id: OzonDeliverySettings::PICKUP_FAMILY,
	recipient_address: new Address(),
	pickup_point: new PickupPointSelection( OzonDeliverySettings::CARRIER_KEY, OzonDeliverySettings::SERVICE_KEY, '777', 'ПВЗ Ozon', '2026-08-30 12:00:00' ),
	places: array( new ShipmentPlace( 1, 5000, 40, 30, 20, Money::from_kopecks( 0 ) ) ),
	declared_value: Money::from_kopecks( 0 ),
	recipient: array( 'name' => 'Иван Иванов', 'phone' => '+79132038250' ),
	meta: array( 'shipment_item_rows' => array( array( 'item_key' => 'shipment-ui-row-a', 'ordered_quantity' => 3, 'place_number' => 1, 'amount' => 3, 'cost' => 1000 ) ) )
);
$pickup_preview = $stack['adapter']->build_safe_payload_preview( $pickup_preview_request );
oz_ship_assert( ! in_array( 'city or settlement is recommended', $pickup_preview['errors'] ?? array(), true ) && ! in_array( 'street and house or raw_address are required for courier delivery', $pickup_preview['errors'] ?? array(), true ), 'Ozon pickup preview must not show courier recipient-address validation errors.' );
$result = $stack['service']->create( $order, $request );
oz_ship_assert( $result->success, 'Ozon shipment create+approve must succeed for two actual modal places.' );
$checkout_calls = $stack['http']->calls_for( '/v1/order/checkout' );
oz_ship_assert( 1 === count( $checkout_calls ), 'Ozon shipment create must preflight /v1/order/checkout once before /v1/order/create.' );
$create_calls = $stack['http']->calls_for( '/v1/order/create' );
oz_ship_assert( 1 === count( $create_calls ), 'Ozon shipment create must call /v1/order/create once.' );
$body = $create_calls[0]['body'];
oz_ship_assert( '11111111-1111-4111-8111-111111111111' === (string) ( $create_calls[0]['headers']['Idempotency-Key'] ?? '' ), 'Ozon order/create must pass the stable Shipment Framework idempotency UUID.' );
oz_ship_assert( 2 === count( $body['postings'] ?? array() ), 'Ozon postings count must equal actual modal places count.' );
foreach ( $body['postings'] as $index => $posting ) {
	$checkout_posting = $checkout_calls[0]['body']['postings'][ $index ] ?? array();
	oz_ship_assert( $checkout_posting['request_id'] === $posting['request_id'] && $checkout_posting['shipment_method_id'] === $posting['shipment_method_id'] && $checkout_posting['declared_value'] === $posting['declared_value'] && $checkout_posting['dimensions'] === $posting['dimensions'], 'Ozon preflight checkout posting data must match subsequent create posting data exactly.' );
	oz_ship_assert( ! isset( $checkout_posting['description'] ) && ! isset( $checkout_posting['posting_external_id'] ), 'Ozon preflight checkout must not include create-only posting fields.' );
}
oz_ship_assert( ! isset( $checkout_calls[0]['body']['order_external_id'] ), 'Ozon preflight checkout must not include create-only order_external_id.' );
oz_ship_assert( '1000.00' === (string) $body['postings'][0]['declared_value']['amount'] && '2000.00' === (string) $body['postings'][1]['declared_value']['amount'], 'Declared value must be calculated server-side from Shipment modal quantity times price per actual place.' );
oz_ship_assert( 5000 === (int) $body['postings'][0]['dimensions']['weight_g'] && 400 === (int) $body['postings'][0]['dimensions']['length_mm'] && 300 === (int) $body['postings'][0]['dimensions']['width_mm'] && 200 === (int) $body['postings'][0]['dimensions']['height_mm'], 'Posting dimensions must use manager-defined actual place data.' );
oz_ship_assert( 'Товары по заказу 85372. Коробка 1 из 2' === (string) $body['postings'][0]['description'] && 'Товары по заказу 85372. Коробка 2 из 2' === (string) $body['postings'][1]['description'], 'Ozon posting descriptions must use the documented Russian order/box format.' );
oz_ship_assert( '85372' === (string) ( $body['order_external_id'] ?? '' ) && '85372-1' === (string) ( $body['postings'][0]['posting_external_id'] ?? '' ) && '85372-2' === (string) ( $body['postings'][1]['posting_external_id'] ?? '' ), 'Ozon external IDs must use WooCommerce order number without wdc prefix or UUID.' );
oz_ship_assert( 2 === count( $stack['http']->calls_for( '/v1/posting/approve' ) ), 'Create action must approve every returned posting.' );
$stored = ( new OrderShipmentRepository() )->find_by_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( 'created' === (string) ( $stored['status'] ?? '' ) && 2 === count( $stored['ozon_postings'] ?? array() ), 'Persistence mapper must store Ozon order and all posting references.' );
oz_ship_assert( 'OZON-1' === (string) ( $stored['ozon_postings'][0]['posting_number'] ?? '' ) && 1 === (int) ( $stored['ozon_postings'][0]['place_number'] ?? 0 ), 'Persistence must keep posting to place index mapping.' );
oz_ship_assert( DeliveryStatus::CREATED_IN_CARRIER === (string) ( $stored['universal_status_code'] ?? '' ) && 'READY_FOR_SHIPPING' === (string) ( $stored['ozon_statuses'][0]['status'] ?? '' ), 'Create+approve must persist post-approve Ozon status immediately.' );
oz_ship_assert( 23200 === (int) ( $stored['actual_cost_kopecks'] ?? 0 ) && 'carrier_api' === (string) ( $stored['actual_cost_source'] ?? '' ) && OzonDeliveryShipmentPreflightQuoteService::SOURCE_DETAIL === (string) ( $stored['actual_cost_source_detail'] ?? '' ), 'Ozon pre-create checkout must persist full delivery plus insurance as canonical actual cost.' );
$status_payload = $stack['adapter']->status_payload( $order, $stored );
oz_ship_assert( ! empty( $status_payload['has_actual_cost'] ) && 'carrier_api' === (string) ( $status_payload['actual_cost_source'] ?? '' ), 'Ozon status payload must expose actual cost immediately after create.' );

$document = $stack['docs']->download( $order, $stored, 'ozon_label_2' );
oz_ship_assert( $document instanceof ShipmentBinaryDocument && 'ozon-box-2.pdf' === $document->filename, 'Ozon label provider must expose a PDF label per posting/place.' );
$status = $stack['adapter']->update_status( $order );
oz_ship_assert( ! empty( $status['success'] ) && DeliveryStatus::CREATED_IN_CARRIER === (string) ( $status['shipment']['universal_status_code'] ?? '' ), 'Ozon status provider must map ready_for_shipping postings to created_in_carrier.' );
$cancel = $stack['adapter']->cancel_in_carrier( $order );
oz_ship_assert( ! empty( $cancel['success'] ), 'Ozon cancellation must call cancel for persisted postings: ' . json_encode( $cancel, JSON_UNESCAPED_UNICODE ) );
$cancelled = ( new OrderShipmentRepository() )->find_by_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( 'cancellation_started' === (string) ( $cancelled['status'] ?? '' ) && DeliveryStatus::UNKNOWN === (string) ( $cancelled['universal_status_code'] ?? '' ), 'Cancel request must not mark the shipment fully cancelled before status sync confirms it.' );

$stack = oz_ship_stack( $db );
$live_modal_order = new OzonShipmentSmokeOrder( 85378, '85378', array( new OzonShipmentSmokeOrderItem( 246, 2, '2000.00' ) ) );
$stack['http']->checkout_quotes = array( 1 => array( 'delivery' => '109.00', 'insurance' => '15.00', 'days' => 4 ) );
$live_modal = $stack['service']->create( $live_modal_order, oz_ship_request( array( new ShipmentPlace( 1, 5000, 40, 30, 20, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => 'real-framework-ui-key', 'ordered_quantity' => 2, 'place_number' => 1, 'amount' => 2, 'cost' => 1230 ) ), '777', 85378, '85378' ) );
oz_ship_assert( $live_modal->success, 'Ozon allocation must accept actual Shipment modal rows without WooCommerce order item matching.' );
$live_modal_body = $stack['http']->calls_for( '/v1/order/create' )[0]['body'] ?? array();
oz_ship_assert( '2460.00' === (string) ( $live_modal_body['postings'][0]['declared_value']['amount'] ?? '' ), 'Ozon live fixture must calculate 2 x 1230 RUB = 2460 RUB from modal rows.' );
oz_ship_assert( '85378' === (string) ( $live_modal_body['order_external_id'] ?? '' ) && '85378' === (string) ( $live_modal_body['postings'][0]['posting_external_id'] ?? '' ), 'Single-place Ozon external IDs must equal WooCommerce order number.' );
$live_modal_stored = ( new OrderShipmentRepository() )->find_by_carrier( $live_modal_order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( 12400 === (int) ( $live_modal_stored['actual_cost_kopecks'] ?? 0 ), 'Single-place Ozon actual cost must save delivery 109 + insurance 15 = 124 RUB.' );

$stack = oz_ship_stack( $db );
$split_modal_order = new OzonShipmentSmokeOrder( 85379, '85379', array( new OzonShipmentSmokeOrderItem( 246, 2, '2000.00' ) ) );
$split_modal = $stack['service']->create( $split_modal_order, oz_ship_request( array( new ShipmentPlace( 1, 2000, 20, 20, 10, Money::from_kopecks( 0 ) ), new ShipmentPlace( 2, 2000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array(
	array( 'item_key' => 'real-framework-ui-key', 'ordered_quantity' => 2, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ),
	array( 'item_key' => 'custom:split:7', 'split_parent' => 'custom', 'ordered_quantity' => 2, 'place_number' => 2, 'amount' => 1, 'cost' => 1000 ),
), '777', 85379, '85379' ) );
oz_ship_assert( $split_modal->success, 'Ozon allocation must calculate split rows from modal quantities and prices without identity lookup.' );
$split_body = $stack['http']->calls_for( '/v1/order/create' )[0]['body'] ?? array();
oz_ship_assert( '1000.00' === (string) ( $split_body['postings'][0]['declared_value']['amount'] ?? '' ) && '1000.00' === (string) ( $split_body['postings'][1]['declared_value']['amount'] ?? '' ), 'Ozon declared value must use split row modal price and quantity per place.' );

$stack = oz_ship_stack( $db );
$manual_item_order = new OzonShipmentSmokeOrder( 85380, '85380', array( new OzonShipmentSmokeOrderItem( 246, 2, '2000.00' ) ) );
$manual_item = $stack['service']->create( $manual_item_order, oz_ship_request( array( new ShipmentPlace( 1, 5000, 40, 30, 20, Money::from_kopecks( 0 ) ) ), array(
	array( 'item_key' => 'real-framework-ui-key', 'ordered_quantity' => 2, 'place_number' => 1, 'amount' => 2, 'cost' => 1230 ),
	array( 'item_key' => 'manual-extra', 'ordered_quantity' => 999, 'place_number' => 1, 'amount' => 1, 'cost' => 500 ),
), '777', 85380, '85380' ) );
oz_ship_assert( $manual_item->success, 'Ozon declared value calculation must accept manually added Shipment modal items.' );
$manual_body = $stack['http']->calls_for( '/v1/order/create' )[0]['body'] ?? array();
oz_ship_assert( '2960.00' === (string) ( $manual_body['postings'][0]['declared_value']['amount'] ?? '' ), 'Ozon manual item fixture must calculate 2 x 1230 + 1 x 500 = 2960 RUB.' );

$stack = oz_ship_stack( $db );
$edited_price_order = new OzonShipmentSmokeOrder( 85381, '85381', array( new OzonShipmentSmokeOrderItem( 246, 2, '2000.00' ) ) );
$edited_price = $stack['service']->create( $edited_price_order, oz_ship_request( array( new ShipmentPlace( 1, 5000, 40, 30, 20, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => 'edited-price', 'ordered_quantity' => 2, 'place_number' => 1, 'amount' => 2, 'cost' => 1500 ) ), '777', 85381, '85381' ) );
oz_ship_assert( $edited_price->success, 'Ozon declared value calculation must respect manager-edited Shipment modal price.' );
$edited_body = $stack['http']->calls_for( '/v1/order/create' )[0]['body'] ?? array();
oz_ship_assert( '3000.00' === (string) ( $edited_body['postings'][0]['declared_value']['amount'] ?? '' ), 'Ozon edited price fixture must calculate 2 x 1500 = 3000 RUB.' );

$stack = oz_ship_stack( $db );
$multi_modal_order = new OzonShipmentSmokeOrder( 85382, '85382', array( new OzonShipmentSmokeOrderItem( 246, 3, '3000.00' ) ) );
$stack['http']->checkout_quotes = array(
	1 => array( 'delivery' => '106.00', 'insurance' => '10.00', 'days' => 3 ),
	2 => array( 'delivery' => '120.00', 'insurance' => '15.00', 'days' => 3 ),
);
$multi_modal = $stack['service']->create( $multi_modal_order, oz_ship_request( array( new ShipmentPlace( 1, 2000, 20, 20, 10, Money::from_kopecks( 0 ) ), new ShipmentPlace( 2, 2000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array(
	array( 'item_key' => 'modal-a', 'ordered_quantity' => 2, 'place_number' => 1, 'amount' => 2, 'cost' => 1230 ),
	array( 'item_key' => 'modal-b', 'ordered_quantity' => 1, 'place_number' => 2, 'amount' => 1, 'cost' => 500 ),
	array( 'item_key' => 'manual-c', 'ordered_quantity' => 999, 'place_number' => 2, 'amount' => 2, 'cost' => 100 ),
), '777', 85382, '85382' ) );
oz_ship_assert( $multi_modal->success, 'Ozon declared value calculation must group actual modal row totals by place.' );
$multi_body = $stack['http']->calls_for( '/v1/order/create' )[0]['body'] ?? array();
oz_ship_assert( '2460.00' === (string) ( $multi_body['postings'][0]['declared_value']['amount'] ?? '' ) && '700.00' === (string) ( $multi_body['postings'][1]['declared_value']['amount'] ?? '' ), 'Ozon multi-place fixture must calculate declared values 2460 / 700 RUB.' );
$multi_stored = ( new OrderShipmentRepository() )->find_by_carrier( $multi_modal_order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( 25100 === (int) ( $multi_stored['actual_cost_kopecks'] ?? 0 ), 'Multi-place Ozon actual cost must sum all checkout delivery plus insurance postings.' );

$stack = oz_ship_stack( $db );
$invalid_price_order = new OzonShipmentSmokeOrder( 85383, '85383', array( new OzonShipmentSmokeOrderItem( 246, 2, '2000.00' ) ) );
$invalid_price = $stack['service']->create( $invalid_price_order, oz_ship_request( array( new ShipmentPlace( 1, 5000, 40, 30, 20, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => 'bad-price', 'ordered_quantity' => 2, 'place_number' => 1, 'amount' => 2, 'cost' => '-1' ) ), '777', 85383, '85383' ) );
oz_ship_assert( ! $invalid_price->success && str_contains( $invalid_price->error_message, 'некорректная цена' ) && 0 === count( $stack['http']->calls_for( '/v1/order/create' ) ), 'Invalid Ozon modal item price must fail before /v1/order/create.' );

$stack = oz_ship_stack( $db );
$stack['http']->fail_checkout = true;
$preflight_failed_order = new OzonShipmentSmokeOrder( 85384, '85384', array( new OzonShipmentSmokeOrderItem( 246, 2, '2000.00' ) ) );
$preflight_failed = $stack['service']->create( $preflight_failed_order, oz_ship_request( array( new ShipmentPlace( 1, 5000, 40, 30, 20, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => 'preflight', 'ordered_quantity' => 2, 'place_number' => 1, 'amount' => 2, 'cost' => 1230 ) ), '777', 85384, '85384' ) );
oz_ship_assert( ! $preflight_failed->success && 'ozon_shipment_preflight_failed' === $preflight_failed->error_code && 0 === count( $stack['http']->calls_for( '/v1/order/create' ) ) && 0 === count( $stack['http']->calls_for( '/v1/posting/approve' ) ), 'Failed Ozon shipment preflight checkout must block order/create and approve calls.' );

$stack = oz_ship_stack( $db );
$overweight_order = new OzonShipmentSmokeOrder( 85372, '85372', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$overweight = $stack['service']->create( $overweight_order, oz_ship_request( array( new ShipmentPlace( 1, 12000, 40, 30, 20, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ) ) );
oz_ship_assert( ! $overweight->success && 'ozon_shipment_validation_failed' === $overweight->error_code && 0 === count( $stack['http']->calls_for( '/v1/order/create' ) ), 'Overweight actual place must be blocked before /v1/order/create.' );
$oversize = $stack['service']->create( new OzonShipmentSmokeOrder( 85373, '85373', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) ), oz_ship_request( array( new ShipmentPlace( 1, 8000, 40, 40, 40, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85373, '85373' ) );
oz_ship_assert( ! $oversize->success && str_contains( $oversize->error_message, 'размер' ), '40x40x40 actual place must fail selected Ozon point limits after rotation-aware dimension check: ' . $oversize->error_code . ' ' . $oversize->error_message );
$rotated = $stack['service']->create( new OzonShipmentSmokeOrder( 85374, '85374', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) ), oz_ship_request( array( new ShipmentPlace( 1, 8000, 30, 50, 20, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85374, '85374' ) );
oz_ship_assert( $rotated->success, '50x30x20 actual place must pass selected point limits with rotation.' );

$stack = oz_ship_stack( $db );
$stack['http']->fail_approve = array( 'OZON-1' );
$stack['http']->statuses['OZON-1'] = 'READY_FOR_SHIPPING';
$recovered_order = new OzonShipmentSmokeOrder( 85377, '85377', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$recovered = $stack['service']->create( $recovered_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85377, '85377' ) );
oz_ship_assert( $recovered->success, 'Approve recovery must treat official READY_FOR_SHIPPING status as approved after an approve error.' );
oz_ship_assert( 1 === count( $stack['http']->calls_for( '/v1/order/create' ) ), 'Approve recovery through posting/info must not create a duplicate Ozon order.' );

$stack = oz_ship_stack( $db );
$stack['http']->fail_info = true;
$info_failed_order = new OzonShipmentSmokeOrder( 85385, '85385', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$info_failed = $stack['service']->create( $info_failed_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85385, '85385' ) );
$info_failed_stored = ( new OrderShipmentRepository() )->find_by_carrier( $info_failed_order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( $info_failed->success && DeliveryStatus::CREATED_IN_CARRIER === (string) ( $info_failed_stored['universal_status_code'] ?? '' ) && '' !== (string) ( $info_failed_stored['ozon_status_read_error'] ?? '' ), 'Post-approve posting/info failure must not fail or downgrade successful Ozon create.' );

$stack = oz_ship_stack( $db );
$stack['http']->approve_updates_status = false;
$created_after_approve_order = new OzonShipmentSmokeOrder( 85386, '85386', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$created_after_approve = $stack['service']->create( $created_after_approve_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85386, '85386' ) );
$created_after_approve_stored = ( new OrderShipmentRepository() )->find_by_carrier( $created_after_approve_order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( $created_after_approve->success && 'CREATED' === (string) ( $created_after_approve_stored['ozon_statuses'][0]['status'] ?? '' ) && DeliveryStatus::CREATED_IN_CARRIER === (string) ( $created_after_approve_stored['universal_status_code'] ?? '' ), 'Raw CREATED immediately after successful approve must be saved but must not downgrade the completed create lifecycle.' );

$stack = oz_ship_stack( $db );
$strict_context = $stack['modal']->modal_context( $order, array( 'request' => array( 'meta' => array( 'pickup_point_code' => '888' ) ) ) );
oz_ship_assert( 9000 === (int) ( $strict_context['max_weight_g'] ?? 0 ) && 400 === (int) ( $strict_context['max_length_mm'] ?? 0 ) && 250 === (int) ( $strict_context['max_height_mm'] ?? 0 ), 'Ozon modal extension must present selected point-specific limits, not global defaults.' );
$strict = $stack['service']->create( new OzonShipmentSmokeOrder( 85375, '85375', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) ), oz_ship_request( array( new ShipmentPlace( 1, 8000, 50, 30, 20, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '888', 85375, '85375' ) );
oz_ship_assert( ! $strict->success && 0 === count( $stack['http']->calls_for( '/v1/order/create' ) ), 'Server-side validation must use stricter selected point limits.' );

$stack = oz_ship_stack( $db );
$stack['http']->fail_approve = array( 'OZON-3' );
$partial_order = new OzonShipmentSmokeOrder( 85376, '85376', array( new OzonShipmentSmokeOrderItem( 101, 3, '5200.00' ) ) );
$partial_request = oz_ship_request( array(
	new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ),
	new ShipmentPlace( 2, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ),
	new ShipmentPlace( 3, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ),
), array(
	array( 'item_key' => '101', 'ordered_quantity' => 3, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ),
	array( 'item_key' => '101', 'ordered_quantity' => 3, 'place_number' => 2, 'amount' => 1, 'cost' => 1000 ),
	array( 'item_key' => '101', 'ordered_quantity' => 3, 'place_number' => 3, 'amount' => 1, 'cost' => 1000 ),
), '777', 85376, '85376' );
$partial = $stack['service']->create( $partial_order, $partial_request );
oz_ship_assert( ! $partial->success && 'ozon_posting_approve_partial' === $partial->error_code, 'Partial approve failure must not be reported as full success.' );
$pending = ( new OrderShipmentRepository() )->find_by_carrier( $partial_order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( ! empty( $pending['pending_creation_in_carrier'] ) && 3 === count( $pending['ozon_postings'] ?? array() ), 'Partial approve must persist all external references for recovery.' );
oz_ship_assert( 34800 === (int) ( $pending['actual_cost_kopecks'] ?? 0 ) && OzonDeliveryShipmentPreflightQuoteService::SOURCE_DETAIL === (string) ( $pending['actual_cost_source_detail'] ?? '' ), 'Partial approve persistence must keep the initial Ozon preflight actual cost candidate.' );
$stack['http']->fail_approve = array();
$continued = $stack['adapter']->continue_lifecycle( $partial_order, OzonDeliveryShipmentService::CONTINUATION_TOKEN );
oz_ship_assert( ! empty( $continued['success'] ), 'Lifecycle continuation must approve the remaining Ozon postings.' );
oz_ship_assert( 1 === count( $stack['http']->calls_for( '/v1/order/create' ) ), 'Approve retry must not create a second Ozon order.' );
oz_ship_assert( 1 === count( $stack['http']->calls_for( '/v1/order/checkout' ) ), 'Approve retry must not run a second Ozon checkout preflight.' );
$finished = ( new OrderShipmentRepository() )->find_by_carrier( $partial_order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( empty( $finished['pending_creation_in_carrier'] ) && 'created' === (string) ( $finished['status'] ?? '' ), 'Continuation must clear pending state after all postings are approved.' );

$architecture_source = file_get_contents( $root . '/src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentCreateRequestBuilder.php' ) ?: '';
oz_ship_assert( ! str_contains( $architecture_source, 'PackagingBuilder' ) && ! str_contains( $architecture_source, 'PackagingResult' ) && ! str_contains( $architecture_source, 'ozon_delivery_places' ), 'Ozon shipment create must not depend on checkout Packaging or quote places metadata.' );
oz_ship_assert( 'ready_for_shipping' === OzonDeliveryShipmentStatusMapping::normalize( ' READY_FOR_SHIPPING ' ), 'Ozon status normalization must canonicalize documented enum casing.' );
oz_ship_assert( DeliveryStatus::PENDING_CREATION_IN_CARRIER === OzonDeliveryShipmentStatusMapping::universal( 'CREATED' ) && DeliveryStatus::PENDING_CREATION_IN_CARRIER === OzonDeliveryShipmentStatusMapping::universal( 'FORMING' ) && DeliveryStatus::REJECTED === OzonDeliveryShipmentStatusMapping::universal( 'FORMING_FAILED' ) && DeliveryStatus::CREATED_IN_CARRIER === OzonDeliveryShipmentStatusMapping::universal( 'READY_FOR_SHIPPING' ) && DeliveryStatus::IN_TRANSIT === OzonDeliveryShipmentStatusMapping::universal( 'IN_CONTAINER' ) && DeliveryStatus::IN_TRANSIT === OzonDeliveryShipmentStatusMapping::universal( 'ACCEPTANCE_IN_PROGRESS' ) && DeliveryStatus::IN_TRANSIT === OzonDeliveryShipmentStatusMapping::universal( 'ON_WAY' ) && DeliveryStatus::REJECTED === OzonDeliveryShipmentStatusMapping::universal( 'NOT_ACCEPTED_TO_DELIVERY' ) && DeliveryStatus::READY_FOR_PICKUP === OzonDeliveryShipmentStatusMapping::universal( 'IN_DELIVERY_POINT' ) && DeliveryStatus::HANDED_TO_COURIER === OzonDeliveryShipmentStatusMapping::universal( 'IN_COURIER_SERVICE' ) && DeliveryStatus::DELIVERED === OzonDeliveryShipmentStatusMapping::universal( 'DELIVERED' ) && DeliveryStatus::CANCELLED === OzonDeliveryShipmentStatusMapping::universal( 'CANCELED' ) && DeliveryStatus::UNKNOWN === OzonDeliveryShipmentStatusMapping::universal( 'BRAND_NEW_STATUS' ), 'Ozon status mapping must cover documented posting statuses with official casing and keep unknown safe.' );
oz_ship_assert( DeliveryStatus::DELIVERED === OzonDeliveryShipmentStatusMapping::aggregate( array( 'DELIVERED', 'DELIVERED' ) ) && DeliveryStatus::IN_TRANSIT === OzonDeliveryShipmentStatusMapping::aggregate( array( 'READY_FOR_SHIPPING', 'ON_WAY' ) ) && DeliveryStatus::DELIVERED !== OzonDeliveryShipmentStatusMapping::aggregate( array( 'DELIVERED', 'ON_WAY' ) ), 'Ozon multi-posting aggregate status must work with official enum casing and not report delivered until all postings are delivered.' );

echo "Ozon Delivery shipment smoke passed.\n";
