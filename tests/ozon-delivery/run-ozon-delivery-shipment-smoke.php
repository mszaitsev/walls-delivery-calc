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
use WallsShop\WDC\Carriers\OzonDelivery\Admin\OzonDeliveryAdminPage;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryMessageSanitizer;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryTokenCache;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliveryCredentials;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupPointProvider;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupRepository;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteParser;
use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnInfoParser;
use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnLifecycleResolver;
use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnSearchParser;
use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnService;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentAdapter;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentActionPolicy;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentAllocationValueResolver;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentCreateRequestBuilder;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentCreateResponseParser;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentDescriptionBuilder;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentDocumentProvider;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentExternalIdResolver;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentModalExtension;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentPersistenceMapper;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentPreflightQuoteService;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentService;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentCreationStatusPolicy;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentStatusMapping;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentStatusMapper;
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
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'selected' ) ) { function selected( mixed $selected, mixed $current = true, bool $display = true ): string { $result = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ( $display ) { echo $result; } return $result; } }
if ( ! function_exists( 'wp_nonce_field' ) ) { function wp_nonce_field( string|int $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true ): string { $field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">'; if ( $display ) { echo $field; } return $field; } }
if ( ! function_exists( 'submit_button' ) ) { function submit_button( string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true, array $other_attributes = array() ): void { echo '<button type="submit">' . esc_html( $text ) . '</button>'; } }

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
	public bool $fail_cancel = false;
	/** @var array<int,string> */
	public array $fail_cancel_numbers = array();
	/** @var array<int,array<string,mixed>> */
	public array $return_pages = array();
	/** @var array<string,array<string,mixed>> */
	public array $return_info = array();
	public bool $fail_return_info = false;
	public bool $approve_updates_status = true;
	public string $approve_status = 'READY_FOR_SHIPPING';
	public bool $cancel_updates_status = true;

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
				$this->statuses[ $number ] = $this->approve_status;
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
			$posting_number = (string) ( $body['posting_number'] ?? '' );
			if ( $this->fail_cancel || in_array( $posting_number, $this->fail_cancel_numbers, true ) ) {
				return new OzonDeliveryApiResponse( 500, '{"error":{"code":"cancel_failed","message":"temporary"}}', array( 'content-type' => 'application/json' ) );
			}
			if ( $this->cancel_updates_status ) {
				$this->statuses[ $posting_number ] = 'CANCELED';
			}
			return new OzonDeliveryApiResponse( 200, '{}', array( 'content-type' => 'application/json' ) );
		}
		if ( str_contains( $url, '/v1/return/search' ) ) {
			$cursor = (string) ( $body['pagination']['cursor'] ?? '' );
			$page_index = '' !== $cursor ? (int) $cursor : 0;
			$page = $this->return_pages[ $page_index ] ?? array( 'returns' => array(), 'next_cursor' => '' );
			return new OzonDeliveryApiResponse( 200, wp_json_encode( $page ) ?: '{}', array( 'content-type' => 'application/json' ) );
		}
		if ( str_contains( $url, '/v1/return/info' ) ) {
			if ( $this->fail_return_info ) {
				return new OzonDeliveryApiResponse( 500, '{"error":{"code":"return_info_failed","message":"temporary"}}', array( 'content-type' => 'application/json' ) );
			}
			$returns = array();
			foreach ( is_array( $body['return_numbers'] ?? null ) ? $body['return_numbers'] : array() as $number ) {
				$returns[] = $this->return_info[ (string) $number ] ?? array( 'return_number' => (string) $number, 'return_external_id' => '1030', 'status' => 'MOVING' );
			}
			return new OzonDeliveryApiResponse( 200, wp_json_encode( array( 'returns' => $returns ) ) ?: '{}', array( 'content-type' => 'application/json' ) );
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

/** @return array{http:OzonShipmentSmokeHttp,service:ShipmentCreationService,adapter:OzonDeliveryShipmentAdapter,docs:OzonDeliveryShipmentDocumentProvider,modal:OzonDeliveryShipmentModalExtension,settings:OzonDeliverySettings,mapper:OzonDeliveryShipmentStatusMapper} */
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
	$external_ids = new OzonDeliveryShipmentExternalIdResolver();
	$builder = new OzonDeliveryShipmentCreateRequestBuilder( $settings, new RussianPhoneNormalizer(), new OzonDeliveryShipmentDescriptionBuilder(), new OzonDeliveryShipmentAllocationValueResolver(), $external_ids );
	$pickup_provider = new OzonDeliveryPickupPointProvider( new OzonDeliveryPickupRepository( $db ) );
	$mapper = new OzonDeliveryShipmentStatusMapper( $settings );
	$return_service = new OzonDeliveryReturnService( $api, $external_ids, new OzonDeliveryReturnSearchParser(), new OzonDeliveryReturnInfoParser(), new OzonDeliveryReturnLifecycleResolver(), $mapper );
	$service = new OzonDeliveryShipmentService( $api, $builder, new OzonDeliveryShipmentCreateResponseParser(), new OzonDeliveryShipmentPreflightQuoteService( $api, new OzonDeliveryQuoteParser( new OzonDeliveryMessageSanitizer() ) ), $pickup_provider, $repository, $attempts, $mapper, $return_service, new Logger() );
	$actual_cost = new ShipmentActualCostResolver( new ShipmentActualCostComparisonService(), new ShipmentBaseApiCostResolver() );
	$adapter = new OzonDeliveryShipmentAdapter( $service, $builder, $repository, $actual_cost );
	$creation = new ShipmentCreationService( $repository, array( $adapter ), new ShipmentActualCostService( $repository ), new Logger(), new CarrierShipmentAdapterRegistry( array( $adapter ) ), array( new OzonDeliveryShipmentPersistenceMapper() ), $attempts );

	return array(
		'http' => $http,
		'service' => $creation,
		'adapter' => $adapter,
		'docs' => new OzonDeliveryShipmentDocumentProvider( $api ),
		'modal' => new OzonDeliveryShipmentModalExtension( new OzonDeliveryPickupRepository( $db ) ),
		'settings' => $settings,
		'mapper' => $mapper,
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
oz_ship_assert( DeliveryStatus::CREATED_IN_CARRIER === (string) ( $stored['universal_status_code'] ?? '' ) && DeliveryStatus::label( DeliveryStatus::CREATED_IN_CARRIER ) === (string) ( $stored['status_title'] ?? '' ) && 'READY_FOR_SHIPPING' === (string) ( $stored['ozon_statuses'][0]['status'] ?? '' ), 'Create+approve must persist post-approve Ozon status immediately.' );
oz_ship_assert( 23200 === (int) ( $stored['actual_cost_kopecks'] ?? 0 ) && 'carrier_api' === (string) ( $stored['actual_cost_source'] ?? '' ) && OzonDeliveryShipmentPreflightQuoteService::SOURCE_DETAIL === (string) ( $stored['actual_cost_source_detail'] ?? '' ), 'Ozon pre-create checkout must persist full delivery plus insurance as canonical actual cost.' );
$status_payload = $stack['adapter']->status_payload( $order, $stored );
oz_ship_assert( ! empty( $status_payload['has_actual_cost'] ) && 'carrier_api' === (string) ( $status_payload['actual_cost_source'] ?? '' ), 'Ozon status payload must expose actual cost immediately after create.' );
oz_ship_assert( DeliveryStatus::CREATED_IN_CARRIER === (string) ( $status_payload['universal_status_code'] ?? '' ) && DeliveryStatus::label( DeliveryStatus::CREATED_IN_CARRIER ) === (string) ( $status_payload['shipment_status_label'] ?? '' ) && 'READY_FOR_SHIPPING, READY_FOR_SHIPPING' === (string) ( $status_payload['carrier_status_title'] ?? '' ), 'Ozon create UI payload must immediately show created_in_carrier and raw Ozon statuses without a manual refresh.' );
oz_ship_assert( 'Номера Ozon' === (string) ( $status_payload['tracking_presentation']['label'] ?? '' ) && 2 === count( $status_payload['tracking_presentation']['items'] ?? array() ) && 'OZON-1' === (string) ( $status_payload['tracking_presentation']['items'][0]['copy_value'] ?? '' ) && 'OZON-2' === (string) ( $status_payload['tracking_presentation']['items'][1]['copy_value'] ?? '' ), 'Ozon multi-box status payload must expose every posting number sorted by place for individual copying.' );

$actions = $stack['docs']->actions( $order, $stored );
oz_ship_assert( 2 === count( $actions ) && 'Скачать этикетку 1 из 2' === $actions[0]->label && 'Скачать этикетку 2 из 2' === $actions[1]->label, 'Ozon multi-box document actions must use concise label button names.' );
$document = $stack['docs']->download( $order, $stored, 'ozon_label_2' );
oz_ship_assert( $document instanceof ShipmentBinaryDocument && 'ozon-85372-2.pdf' === $document->filename, 'Ozon label provider must name multi-box PDFs with Woo order number and place number.' );
$single_document_order = new OzonShipmentSmokeOrder( 1030, '1030', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$single_document_shipment = array( 'ozon_postings' => array( array( 'posting_number' => 'OZON-1030', 'place_number' => 1, 'approved' => true ) ) );
$single_actions = $stack['docs']->actions( $single_document_order, $single_document_shipment );
oz_ship_assert( 1 === count( $single_actions ) && 'Скачать этикетку' === $single_actions[0]->label, 'Ozon single-box document action must use the concise label button name.' );
$single_document = $stack['docs']->download( $single_document_order, $single_document_shipment, 'ozon_label_1' );
oz_ship_assert( 'ozon-1030.pdf' === $single_document->filename, 'Ozon label provider must omit box suffix for a single posting.' );

$forming_stack = oz_ship_stack( $db );
$forming_stack['http']->approve_status = 'FORMING';
$forming_order = new OzonShipmentSmokeOrder( 85387, '85387', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$forming_create = $forming_stack['service']->create( $forming_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85387, '85387' ) );
$forming_stored = ( new OrderShipmentRepository() )->find_by_carrier( $forming_order, OzonDeliverySettings::CARRIER_KEY );
$forming_payload = $forming_stack['adapter']->status_payload( $forming_order, $forming_stored );
oz_ship_assert( $forming_create->success && ! empty( $forming_create->raw_reference['auto_poll'] ) && OzonDeliveryShipmentCreationStatusPolicy::STATUS_STARTED === (string) ( $forming_stored['status'] ?? '' ) && DeliveryStatus::CREATED_IN_CARRIER === (string) ( $forming_stored['universal_status_code'] ?? '' ) && 'FORMING' === (string) ( $forming_stored['ozon_statuses'][0]['status'] ?? '' ) && ! empty( $forming_payload['lifecycle']['poll_required'] ) && OzonDeliveryShipmentCreationStatusPolicy::PURPOSE === (string) ( $forming_payload['lifecycle']['purpose'] ?? '' ), 'Ozon create+approve with immediate FORMING must persist creation confirmation polling while keeping universal created_in_carrier.' );
$mutation_counts = array(
	'checkout' => count( $forming_stack['http']->calls_for( '/v1/order/checkout' ) ),
	'create' => count( $forming_stack['http']->calls_for( '/v1/order/create' ) ),
	'approve' => count( $forming_stack['http']->calls_for( '/v1/posting/approve' ) ),
);
$forming_poll = $forming_stack['adapter']->update_status( $forming_order );
oz_ship_assert( ! empty( $forming_poll['pending'] ) && OzonDeliveryShipmentCreationStatusPolicy::STATUS_STARTED === (string) ( $forming_poll['shipment']['status'] ?? '' ) && DeliveryStatus::CREATED_IN_CARRIER === (string) ( $forming_poll['shipment']['universal_status_code'] ?? '' ), 'Ozon creation confirmation polling must continue while posting remains FORMING.' );
$forming_stack['http']->statuses['OZON-1'] = 'READY_FOR_SHIPPING';
$forming_ready = $forming_stack['adapter']->update_status( $forming_order );
oz_ship_assert( empty( $forming_ready['pending'] ) && 'created' === (string) ( $forming_ready['shipment']['status'] ?? '' ) && DeliveryStatus::CREATED_IN_CARRIER === (string) ( $forming_ready['shipment']['universal_status_code'] ?? '' ) && 'READY_FOR_SHIPPING' === (string) ( $forming_ready['shipment']['ozon_statuses'][0]['status'] ?? '' ), 'Ozon creation confirmation polling must stop when all postings are READY_FOR_SHIPPING.' );
oz_ship_assert( $mutation_counts['checkout'] === count( $forming_stack['http']->calls_for( '/v1/order/checkout' ) ) && $mutation_counts['create'] === count( $forming_stack['http']->calls_for( '/v1/order/create' ) ) && $mutation_counts['approve'] === count( $forming_stack['http']->calls_for( '/v1/posting/approve' ) ), 'Ozon creation confirmation polling must call only posting/info and must not repeat checkout, create, or approve mutations.' );

$multi_confirm_stack = oz_ship_stack( $db );
$multi_confirm_stack['http']->approve_updates_status = false;
$multi_confirm_stack['http']->statuses['OZON-1'] = 'READY_FOR_SHIPPING';
$multi_confirm_stack['http']->statuses['OZON-2'] = 'FORMING';
$multi_confirm_order = new OzonShipmentSmokeOrder( 85390, '85390', array( new OzonShipmentSmokeOrderItem( 101, 2, '2000.00' ) ) );
$multi_confirm_create = $multi_confirm_stack['service']->create( $multi_confirm_order, oz_ship_request( array(
	new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ),
	new ShipmentPlace( 2, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ),
), array(
	array( 'item_key' => '101', 'ordered_quantity' => 2, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ),
	array( 'item_key' => '101:split:2', 'ordered_quantity' => 2, 'place_number' => 2, 'amount' => 1, 'cost' => 1000 ),
), '777', 85390, '85390' ) );
$multi_confirm_poll = $multi_confirm_stack['adapter']->update_status( $multi_confirm_order );
$multi_confirm_stack['http']->statuses['OZON-2'] = 'READY_FOR_SHIPPING';
$multi_confirm_ready = $multi_confirm_stack['adapter']->update_status( $multi_confirm_order );
oz_ship_assert( $multi_confirm_create->success && ! empty( $multi_confirm_poll['pending'] ) && empty( $multi_confirm_ready['pending'] ) && 'created' === (string) ( $multi_confirm_ready['shipment']['status'] ?? '' ), 'Ozon multi-posting creation confirmation must wait until every posting is READY_FOR_SHIPPING.' );

$created_confirm_stack = oz_ship_stack( $db );
$created_confirm_stack['http']->approve_updates_status = false;
$created_confirm_order = new OzonShipmentSmokeOrder( 85391, '85391', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$created_confirm = $created_confirm_stack['service']->create( $created_confirm_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85391, '85391' ) );
$created_confirm_stored = ( new OrderShipmentRepository() )->find_by_carrier( $created_confirm_order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( $created_confirm->success && OzonDeliveryShipmentCreationStatusPolicy::STATUS_STARTED === (string) ( $created_confirm_stored['status'] ?? '' ) && DeliveryStatus::CREATED_IN_CARRIER === (string) ( $created_confirm_stored['universal_status_code'] ?? '' ) && 'CREATED' === (string) ( $created_confirm_stored['ozon_statuses'][0]['status'] ?? '' ), 'Ozon create+approve with immediate CREATED must start creation confirmation polling and keep universal created_in_carrier.' );

$failed_confirm_stack = oz_ship_stack( $db );
$failed_confirm_stack['http']->approve_status = 'FORMING';
$failed_confirm_order = new OzonShipmentSmokeOrder( 85392, '85392', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$failed_confirm_stack['service']->create( $failed_confirm_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85392, '85392' ) );
$failed_counts = array( 'create' => count( $failed_confirm_stack['http']->calls_for( '/v1/order/create' ) ), 'approve' => count( $failed_confirm_stack['http']->calls_for( '/v1/posting/approve' ) ) );
$failed_confirm_stack['http']->statuses['OZON-1'] = 'FORMING_FAILED';
$failed_confirm = $failed_confirm_stack['adapter']->update_status( $failed_confirm_order );
oz_ship_assert( empty( $failed_confirm['pending'] ) && DeliveryStatus::REJECTED === (string) ( $failed_confirm['shipment']['universal_status_code'] ?? '' ) && 'Ozon не смог сформировать отправление.' === (string) ( $failed_confirm['message'] ?? '' ) && $failed_counts['create'] === count( $failed_confirm_stack['http']->calls_for( '/v1/order/create' ) ) && $failed_counts['approve'] === count( $failed_confirm_stack['http']->calls_for( '/v1/posting/approve' ) ), 'Ozon FORMING_FAILED during creation confirmation must stop polling, persist rejected status, and avoid create/approve retry.' );

$timeout_confirm_stack = oz_ship_stack( $db );
$timeout_confirm_stack['http']->approve_status = 'FORMING';
$timeout_confirm_order = new OzonShipmentSmokeOrder( 85393, '85393', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$timeout_confirm_stack['service']->create( $timeout_confirm_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85393, '85393' ) );
$timeout_confirm = $timeout_confirm_stack['adapter']->mark_polling_exhausted( $timeout_confirm_order, 14, OzonDeliveryShipmentCreationStatusPolicy::PURPOSE );
$timeout_confirm_stored = ( new OrderShipmentRepository() )->find_by_carrier( $timeout_confirm_order, OzonDeliverySettings::CARRIER_KEY );
$timeout_confirm_payload = $timeout_confirm_stack['adapter']->status_payload( $timeout_confirm_order, $timeout_confirm_stored );
oz_ship_assert( ! empty( $timeout_confirm['success'] ) && OzonDeliveryShipmentCreationStatusPolicy::STATUS_EXHAUSTED === (string) ( $timeout_confirm_stored['status'] ?? '' ) && DeliveryStatus::CREATED_IN_CARRIER === (string) ( $timeout_confirm_stored['universal_status_code'] ?? '' ) && empty( $timeout_confirm_payload['polling_continue'] ) && ! empty( $timeout_confirm_payload['can_update_status'] ) && ( new OrderShipmentRepository() )->has_created_for_carrier( $timeout_confirm_order, OzonDeliverySettings::CARRIER_KEY ), 'Ozon creation confirmation timeout must keep shipment, stop active polling, keep universal created_in_carrier, and prevent duplicate create.' );
$timeout_confirm_stack['http']->statuses['OZON-1'] = 'READY_FOR_SHIPPING';
$late_ready = $timeout_confirm_stack['adapter']->update_status( $timeout_confirm_order );
oz_ship_assert( ! empty( $late_ready['success'] ) && DeliveryStatus::CREATED_IN_CARRIER === (string) ( $late_ready['shipment']['universal_status_code'] ?? '' ) && 'READY_FOR_SHIPPING' === (string) ( $late_ready['shipment']['ozon_statuses'][0]['status'] ?? '' ), 'Manual status update after creation confirmation timeout must pick up late READY_FOR_SHIPPING.' );

$status = $stack['adapter']->update_status( $order );
oz_ship_assert( ! empty( $status['success'] ) && DeliveryStatus::CREATED_IN_CARRIER === (string) ( $status['shipment']['universal_status_code'] ?? '' ), 'Ozon status provider must map ready_for_shipping postings to created_in_carrier.' );
$stack['http']->statuses['OZON-1'] = 'FORMING';
$stack['http']->statuses['OZON-2'] = 'FORMING';
$forming_status = $stack['adapter']->update_status( $order );
oz_ship_assert( ! empty( $forming_status['success'] ) && DeliveryStatus::CREATED_IN_CARRIER === (string) ( $forming_status['shipment']['universal_status_code'] ?? '' ), 'Ozon automatic status poll with FORMING must not downgrade a created shipment under default mapping.' );
$stack['http']->statuses['OZON-1'] = 'CREATED';
$stack['http']->statuses['OZON-2'] = 'CREATED';
$created_status = $stack['adapter']->update_status( $order );
oz_ship_assert( ! empty( $created_status['success'] ) && DeliveryStatus::CREATED_IN_CARRIER === (string) ( $created_status['shipment']['universal_status_code'] ?? '' ), 'Ozon automatic status poll with CREATED must not downgrade a created shipment under default mapping.' );
$cancel = $stack['adapter']->cancel_in_carrier( $order );
oz_ship_assert( ! empty( $cancel['success'] ) && empty( $cancel['partial'] ) && ! empty( $cancel['auto_poll'] ) && 5000 === (int) ( $cancel['poll_interval_ms'] ?? 0 ) && 14 === (int) ( $cancel['poll_max_attempts'] ?? 0 ) && 'cancellation' === (string) ( $cancel['purpose'] ?? '' ), 'Ozon cancellation must start the shared 5s x 14 polling lifecycle: ' . json_encode( $cancel, JSON_UNESCAPED_UNICODE ) );
$cancelled = ( new OrderShipmentRepository() )->find_by_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( 'cancellation_started' === (string) ( $cancelled['status'] ?? '' ) && DeliveryStatus::UNKNOWN === (string) ( $cancelled['universal_status_code'] ?? '' ), 'Cancel request must not mark the shipment fully cancelled before status sync confirms it.' );
$cancel_payload = $stack['adapter']->status_payload( $order, $cancelled );
oz_ship_assert( ! empty( $cancel_payload['polling_continue'] ) && ! empty( $cancel_payload['can_remove_from_order'] ) && empty( $cancel_payload['can_cancel'] ) && ! empty( $cancel_payload['can_update_status'] ) && 5000 === (int) ( $cancel_payload['registration_poll_interval_ms'] ?? 0 ) && 14 === (int) ( $cancel_payload['registration_poll_max_attempts'] ?? 0 ), 'Ozon cancellation_started payload must keep polling active and allow local remove only as an explicit non-carrier cleanup.' );
$active_remove = $stack['adapter']->remove_from_order( $order );
oz_ship_assert( ! empty( $active_remove['success'] ) && array() === ( new OrderShipmentRepository() )->find_by_carrier( $order, OzonDeliverySettings::CARRIER_KEY ), 'Ozon cancellation_started direct local remove must only delete local data and must not call Ozon API.' );
$multi_cancel_policy = OzonDeliveryShipmentActionPolicy::for_shipment( array(
	'status' => 'cancellation_started',
	'ozon_postings' => array( array( 'posting_number' => 'A' ), array( 'posting_number' => 'B' ) ),
	'ozon_statuses' => array(
		array( 'status' => 'CANCELED' ),
		array( 'status' => 'READY_FOR_SHIPPING' ),
	),
) );
oz_ship_assert( empty( $multi_cancel_policy['can_cancel'] ) && ! empty( $multi_cancel_policy['can_remove'] ) && ! empty( $multi_cancel_policy['can_update'] ), 'Mixed multi-posting active cancellation must allow explicit local cleanup while carrier cancel remains unavailable.' );

$stack = oz_ship_stack( $db );
$delayed_order = new OzonShipmentSmokeOrder( 85387, '85387', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$delayed_create = $stack['service']->create( $delayed_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85387, '85387' ) );
oz_ship_assert( $delayed_create->success, 'Delayed cancellation fixture must create Ozon shipment first.' );
$stack['http']->cancel_updates_status = false;
$delayed_cancel = $stack['adapter']->cancel_in_carrier( $delayed_order );
oz_ship_assert( ! empty( $delayed_cancel['auto_poll'] ), 'Cancel accepted with delayed Ozon status must start polling.' );
$delayed_poll = $stack['adapter']->update_status( $delayed_order );
$delayed_stored = ( new OrderShipmentRepository() )->find_by_carrier( $delayed_order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( ! empty( $delayed_poll['pending'] ) && empty( $delayed_poll['cancelled_and_removed'] ) && array() !== $delayed_stored, 'Delayed cancel status must keep polling and keep local shipment until all postings are CANCELED.' );
$delayed_payload = $stack['adapter']->status_payload( $delayed_order, $delayed_stored );
oz_ship_assert( ! empty( $delayed_payload['polling_continue'] ) && ! empty( $delayed_payload['can_remove_from_order'] ), 'Partially confirmed cancellation must keep polling while allowing explicit local cleanup.' );
$timeout = $stack['adapter']->mark_polling_exhausted( $delayed_order, 14, 'cancellation' );
$timeout_stored = ( new OrderShipmentRepository() )->find_by_carrier( $delayed_order, OzonDeliverySettings::CARRIER_KEY );
$timeout_payload = $stack['adapter']->status_payload( $delayed_order, $timeout_stored );
oz_ship_assert( ! empty( $timeout['success'] ) && 'cancellation_exhausted' === (string) ( $timeout_stored['status'] ?? '' ) && empty( $timeout_payload['polling_continue'] ) && empty( $timeout_payload['can_cancel'] ) && ! empty( $timeout_payload['can_remove_from_order'] ) && ! empty( $timeout_payload['can_update_status'] ), 'Exhausted Ozon cancellation polling must keep the shipment but exit the active local-removal lock.' );
$stack['http']->statuses['OZON-1'] = 'CANCELED';
$delayed_done = $stack['adapter']->update_status( $delayed_order );
oz_ship_assert( ! empty( $delayed_done['cancelled_and_removed'] ) && array() === ( new OrderShipmentRepository() )->find_by_carrier( $delayed_order, OzonDeliverySettings::CARRIER_KEY ), 'Manual status update after timeout with all-CANCELED status must clear the Ozon shipment block.' );

$stack = oz_ship_stack( $db );
$cancel_fail_order = new OzonShipmentSmokeOrder( 85388, '85388', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$cancel_fail_create = $stack['service']->create( $cancel_fail_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85388, '85388' ) );
oz_ship_assert( $cancel_fail_create->success, 'Cancel failure fixture must create Ozon shipment first.' );
$stack['http']->fail_cancel = true;
$cancel_fail = $stack['adapter']->cancel_in_carrier( $cancel_fail_order );
$cancel_fail_stored = ( new OrderShipmentRepository() )->find_by_carrier( $cancel_fail_order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( empty( $cancel_fail['success'] ) && empty( $cancel_fail['auto_poll'] ) && 'created' === (string) ( $cancel_fail_stored['status'] ?? '' ), 'Hard Ozon cancel API rejection must not start optimistic polling or change local shipment status.' );

$stack = oz_ship_stack( $db );
$partial_cancel_order = new OzonShipmentSmokeOrder( 85389, '85389', array( new OzonShipmentSmokeOrderItem( 101, 2, '2000.00' ) ) );
$partial_cancel_create = $stack['service']->create( $partial_cancel_order, oz_ship_request( array(
	new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ),
	new ShipmentPlace( 2, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ),
), array(
	array( 'item_key' => '101', 'ordered_quantity' => 2, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ),
	array( 'item_key' => '101:split:1', 'ordered_quantity' => 2, 'place_number' => 2, 'amount' => 1, 'cost' => 1000 ),
), '777', 85389, '85389' ) );
oz_ship_assert( $partial_cancel_create->success, 'Partial cancel fixture must create a multi-posting Ozon shipment.' );
$stack['http']->cancel_updates_status = false;
$stack['http']->fail_cancel_numbers = array( 'OZON-2' );
$partial_cancel = $stack['adapter']->cancel_in_carrier( $partial_cancel_order );
$partial_stored = ( new OrderShipmentRepository() )->find_by_carrier( $partial_cancel_order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( ! empty( $partial_cancel['success'] ) && ! empty( $partial_cancel['partial'] ) && ! empty( $partial_cancel['auto_poll'] ) && 'cancellation_started' === (string) ( $partial_stored['status'] ?? '' ) && 1 === (int) ( $partial_stored['cancel_attempt']['accepted_count'] ?? 0 ) && 1 === (int) ( $partial_stored['cancel_attempt']['failed_count'] ?? 0 ) && str_contains( (string) ( $partial_cancel['message'] ?? '' ), 'Ozon принял отмену части грузомест' ), 'Partial Ozon cancel acceptance must persist cancellation_started and start reconciliation polling.' );
$stack['http']->statuses['OZON-1'] = 'CANCELED';
$stack['http']->statuses['OZON-2'] = 'READY_FOR_SHIPPING';
$partial_pending = $stack['adapter']->update_status( $partial_cancel_order );
oz_ship_assert( ! empty( $partial_pending['pending'] ) && empty( $partial_pending['cancelled_and_removed'] ) && 2 === count( $stack['http']->calls_for( '/v1/posting/cancel' ) ) && array() !== ( new OrderShipmentRepository() )->find_by_carrier( $partial_cancel_order, OzonDeliverySettings::CARRIER_KEY ), 'Partial cancel polling must keep local shipment while any posting is not CANCELED and must not repeat cancel mutation.' );
$stack['http']->statuses['OZON-2'] = 'CANCELED';
$partial_done = $stack['adapter']->update_status( $partial_cancel_order );
oz_ship_assert( ! empty( $partial_done['cancelled_and_removed'] ) && array() === ( new OrderShipmentRepository() )->find_by_carrier( $partial_cancel_order, OzonDeliverySettings::CARRIER_KEY ), 'Partial cancel reconciliation must delete local shipment after all postings become CANCELED.' );

$stack = oz_ship_stack( $db );
$partial_timeout_order = new OzonShipmentSmokeOrder( 85390, '85390', array( new OzonShipmentSmokeOrderItem( 101, 2, '2000.00' ) ) );
$partial_timeout_create = $stack['service']->create( $partial_timeout_order, oz_ship_request( array(
	new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ),
	new ShipmentPlace( 2, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ),
), array(
	array( 'item_key' => '101', 'ordered_quantity' => 2, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ),
	array( 'item_key' => '101:split:2', 'ordered_quantity' => 2, 'place_number' => 2, 'amount' => 1, 'cost' => 1000 ),
), '777', 85390, '85390' ) );
oz_ship_assert( $partial_timeout_create->success, 'Partial cancel timeout fixture must create a multi-posting Ozon shipment.' );
$stack['http']->cancel_updates_status = false;
$stack['http']->fail_cancel_numbers = array( 'OZON-2' );
$partial_timeout_cancel = $stack['adapter']->cancel_in_carrier( $partial_timeout_order );
oz_ship_assert( ! empty( $partial_timeout_cancel['partial'] ), 'Partial cancel timeout fixture must start from partial accepted mutation.' );
$stack['http']->statuses['OZON-1'] = 'CANCELED';
$stack['http']->statuses['OZON-2'] = 'READY_FOR_SHIPPING';
$partial_timeout_pending = $stack['adapter']->update_status( $partial_timeout_order );
oz_ship_assert( ! empty( $partial_timeout_pending['pending'] ), 'Partial cancel timeout fixture must keep polling while statuses are mixed.' );
$partial_timeout = $stack['adapter']->mark_polling_exhausted( $partial_timeout_order, 14, 'cancellation' );
$partial_timeout_stored = ( new OrderShipmentRepository() )->find_by_carrier( $partial_timeout_order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( ! empty( $partial_timeout['success'] ) && 'cancellation_exhausted' === (string) ( $partial_timeout_stored['status'] ?? '' ) && 2 === count( $stack['http']->calls_for( '/v1/posting/cancel' ) ) && array() !== $partial_timeout_stored, 'Partial cancel timeout must keep local shipment, avoid automatic cancel retry, and move it to cancellation_exhausted.' );

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
oz_ship_assert( DeliveryStatus::CREATED_IN_CARRIER === OzonDeliveryShipmentStatusMapping::universal( 'CREATED' ) && DeliveryStatus::CREATED_IN_CARRIER === OzonDeliveryShipmentStatusMapping::universal( 'FORMING' ) && DeliveryStatus::REJECTED === OzonDeliveryShipmentStatusMapping::universal( 'FORMING_FAILED' ) && DeliveryStatus::CREATED_IN_CARRIER === OzonDeliveryShipmentStatusMapping::universal( 'READY_FOR_SHIPPING' ) && DeliveryStatus::IN_TRANSIT === OzonDeliveryShipmentStatusMapping::universal( 'IN_CONTAINER' ) && DeliveryStatus::IN_TRANSIT === OzonDeliveryShipmentStatusMapping::universal( 'ACCEPTANCE_IN_PROGRESS' ) && DeliveryStatus::IN_TRANSIT === OzonDeliveryShipmentStatusMapping::universal( 'ON_WAY' ) && DeliveryStatus::REJECTED === OzonDeliveryShipmentStatusMapping::universal( 'NOT_ACCEPTED_TO_DELIVERY' ) && DeliveryStatus::READY_FOR_PICKUP === OzonDeliveryShipmentStatusMapping::universal( 'IN_DELIVERY_POINT' ) && DeliveryStatus::HANDED_TO_COURIER === OzonDeliveryShipmentStatusMapping::universal( 'IN_COURIER_SERVICE' ) && DeliveryStatus::DELIVERED === OzonDeliveryShipmentStatusMapping::universal( 'DELIVERED' ) && DeliveryStatus::CANCELLED === OzonDeliveryShipmentStatusMapping::universal( 'CANCELED' ) && DeliveryStatus::RETURNING_TO_SENDER === OzonDeliveryShipmentStatusMapping::universal( 'MOVING' ) && DeliveryStatus::RETURNING_TO_SENDER === OzonDeliveryShipmentStatusMapping::universal( 'AT_THE_PICK_UP_POINT' ) && DeliveryStatus::RETURNED_TO_SENDER === OzonDeliveryShipmentStatusMapping::universal( 'RECEIVED' ) && DeliveryStatus::RETURNING_TO_SENDER === OzonDeliveryShipmentStatusMapping::universal( 'UTILIZATION' ) && DeliveryStatus::RETURNED_TO_SENDER === OzonDeliveryShipmentStatusMapping::universal( 'UTILIZED' ) && DeliveryStatus::RETURNED_TO_SENDER === OzonDeliveryShipmentStatusMapping::universal( 'WRITTEN_OFF' ) && DeliveryStatus::RETURNING_TO_SENDER === OzonDeliveryShipmentStatusMapping::universal( 'LOOKING_FOR' ) && DeliveryStatus::UNKNOWN === OzonDeliveryShipmentStatusMapping::universal( 'BRAND_NEW_STATUS' ), 'Ozon status mapping must cover documented posting and return statuses with official casing, map carrier-created states to created_in_carrier by default, and keep unknown safe.' );
oz_ship_assert( DeliveryStatus::DELIVERED === OzonDeliveryShipmentStatusMapping::aggregate( array( 'DELIVERED', 'DELIVERED' ) ) && DeliveryStatus::IN_TRANSIT === OzonDeliveryShipmentStatusMapping::aggregate( array( 'READY_FOR_SHIPPING', 'ON_WAY' ) ) && DeliveryStatus::DELIVERED !== OzonDeliveryShipmentStatusMapping::aggregate( array( 'DELIVERED', 'ON_WAY' ) ) && DeliveryStatus::RETURNING_TO_SENDER === OzonDeliveryShipmentStatusMapping::aggregate( array( 'MOVING', 'RECEIVED' ) ) && DeliveryStatus::RETURNED_TO_SENDER === OzonDeliveryShipmentStatusMapping::aggregate( array( 'RECEIVED', 'RECEIVED' ) ), 'Ozon multi-posting aggregate status must include return states and not report delivered until all postings are delivered.' );
$documented = OzonDeliveryShipmentStatusMapping::documented_statuses();
oz_ship_assert( in_array( 'READY_FOR_SHIPPING', $documented, true ) && in_array( 'FORMING_FAILED', $documented, true ) && in_array( 'CANCELED', $documented, true ) && in_array( 'MOVING', $documented, true ) && in_array( 'AT_THE_PICK_UP_POINT', $documented, true ) && in_array( 'RECEIVED', $documented, true ) && in_array( 'UTILIZATION', $documented, true ) && in_array( 'UTILIZED', $documented, true ) && in_array( 'WRITTEN_OFF', $documented, true ) && in_array( 'LOOKING_FOR', $documented, true ), 'Ozon documented status list must expose official return statuses for the admin tab.' );
$roundtrip_stack = oz_ship_stack( $db );
$roundtrip_stack['mapper']->save_from_admin( array( OzonDeliverySettings::SHIPMENT_STATUS_MAPPING_KEY => array( 'FORMING' => DeliveryStatus::IN_TRANSIT ) ) );
$roundtrip_settings = new OzonDeliverySettings( new SettingsRepository() );
$roundtrip_mapper = new OzonDeliveryShipmentStatusMapper( $roundtrip_settings );
oz_ship_assert( DeliveryStatus::IN_TRANSIT === (string) ( $roundtrip_mapper->mapping()['forming'] ?? '' ) && DeliveryStatus::IN_TRANSIT === $roundtrip_mapper->universal( 'FORMING' ) && DeliveryStatus::CREATED_IN_CARRIER === OzonDeliveryShipmentStatusMapping::universal( 'FORMING' ), 'Saved Ozon status mapping override must survive a new request mapper without mutating code defaults.' );
$admin_reflection = new ReflectionClass( OzonDeliveryAdminPage::class );
$admin_page = $admin_reflection->newInstanceWithoutConstructor();
$admin_mapper_property = $admin_reflection->getProperty( 'shipment_status_mapper' );
$admin_mapper_property->setAccessible( true );
$admin_mapper_property->setValue( $admin_page, $roundtrip_mapper );
ob_start();
$admin_page->render_statuses();
$admin_html = (string) ob_get_clean();
oz_ship_assert( 1 === preg_match( '/<code>FORMING<\/code>.*value="in_transit"[^>]*selected/s', $admin_html ), 'Ozon status admin render must select a saved override after a new request.' );
$override_stack = oz_ship_stack( $db );
$override_stack['mapper']->save_from_admin( array( OzonDeliverySettings::SHIPMENT_STATUS_MAPPING_KEY => array( 'FORMING' => DeliveryStatus::IN_TRANSIT ) ) );
$override_order = new OzonShipmentSmokeOrder( 85389, '85389', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$override_create = $override_stack['service']->create( $override_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85389, '85389' ) );
$override_stack['http']->statuses['OZON-1'] = 'FORMING';
$override_status = $override_stack['adapter']->update_status( $override_order );
oz_ship_assert( $override_create->success && ! empty( $override_status['success'] ) && DeliveryStatus::IN_TRANSIT === (string) ( $override_status['shipment']['universal_status_code'] ?? '' ), 'Saved Ozon status mapping override must affect ordinary runtime status sync.' );
foreach ( array( 'CREATED', 'FORMING', 'READY_FOR_SHIPPING' ) as $status_code ) {
	$policy = OzonDeliveryShipmentActionPolicy::for_statuses( array( $status_code ) );
	oz_ship_assert( ! empty( $policy['can_cancel'] ) && empty( $policy['can_remove'] ), 'Ozon action policy must allow cancel for early status ' . $status_code );
}
foreach ( array( 'FORMING_FAILED', 'ON_WAY', 'IN_CONTAINER', 'ACCEPTANCE_IN_PROGRESS', 'NOT_ACCEPTED_TO_DELIVERY', 'IN_DELIVERY_POINT', 'IN_COURIER_SERVICE', 'DELIVERED', 'CANCELED', 'MOVING', 'AT_THE_PICK_UP_POINT', 'RECEIVED', 'UTILIZATION', 'UTILIZED', 'WRITTEN_OFF', 'LOOKING_FOR', 'UNKNOWN', 'BRAND_NEW_STATUS' ) as $status_code ) {
	$policy = OzonDeliveryShipmentActionPolicy::for_statuses( array( $status_code ) );
	oz_ship_assert( empty( $policy['can_cancel'] ) && ! empty( $policy['can_remove'] ) && ! empty( $policy['can_update'] ), 'Ozon action policy must fail safe to remove/update for status ' . $status_code );
}
$multi_cancel = OzonDeliveryShipmentActionPolicy::for_statuses( array( 'READY_FOR_SHIPPING', 'CREATED' ) );
$multi_remove = OzonDeliveryShipmentActionPolicy::for_statuses( array( 'READY_FOR_SHIPPING', 'ON_WAY' ) );
$multi_failed = OzonDeliveryShipmentActionPolicy::for_statuses( array( 'FORMING_FAILED', 'READY_FOR_SHIPPING' ) );
oz_ship_assert( ! empty( $multi_cancel['can_cancel'] ) && empty( $multi_remove['can_cancel'] ) && ! empty( $multi_remove['can_remove'] ) && empty( $multi_failed['can_cancel'] ) && ! empty( $multi_failed['can_remove'] ), 'Ozon multi-posting action policy must allow cancel only when every posting is still cancellable.' );

$ids = new OzonDeliveryShipmentExternalIdResolver();
oz_ship_assert( '1030' === $ids->order_external_id( '1030' ) && '1030' === $ids->posting_external_id( '1030', 1, 1 ) && '1030' === $ids->expected_return_external_id( '1030', 1, 1 ) && '1030-1' === $ids->posting_external_id( '1030', 1, 2 ) && '1030-2' === $ids->expected_return_external_id( '1030', 2, 2 ), 'Ozon external ID resolver must keep single order number and multi order-number-place hyphen format.' );

$stack = oz_ship_stack( $db );
$local_cancel_order = new OzonShipmentSmokeOrder( 85400, '85400', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$local_cancel_create = $stack['service']->create( $local_cancel_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85400, '85400' ) );
oz_ship_assert( $local_cancel_create->success, 'Local cancel return regression fixture must create a shipment.' );
$local_cancel = $stack['adapter']->cancel_in_carrier( $local_cancel_order );
$local_cancel_status = $stack['adapter']->update_status( $local_cancel_order );
oz_ship_assert( ! empty( $local_cancel['success'] ) && ! empty( $local_cancel_status['cancelled_and_removed'] ) && 0 === count( $stack['http']->calls_for( '/v1/return/search' ) ) && 0 === count( $stack['http']->calls_for( '/v1/return/info' ) ), 'Local early Ozon cancellation must not call return/search or return/info.' );

$stack = oz_ship_stack( $db );
$external_before_order = new OzonShipmentSmokeOrder( 85401, '85401', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$external_before_create = $stack['service']->create( $external_before_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85401, '85401' ) );
$stack['http']->statuses['OZON-1'] = 'CANCELED';
$stack['http']->return_pages = array( array( 'returns' => array(), 'next_cursor' => '' ) );
$external_before_status = $stack['adapter']->update_status( $external_before_order );
$external_before_stored = ( new OrderShipmentRepository() )->find_by_carrier( $external_before_order, OzonDeliverySettings::CARRIER_KEY );
oz_ship_assert( $external_before_create->success && DeliveryStatus::CANCELLED === (string) ( $external_before_status['shipment']['universal_status_code'] ?? '' ) && array() !== $external_before_stored && ! empty( $external_before_stored['ozon_postings'][0]['handover_seen'] ) === false && ! empty( $stack['adapter']->status_payload( $external_before_order, $external_before_stored )['can_remove_from_order'] ), 'External CANCELED before handover must resolve to local cancelled_no_return, keep shipment, and allow local remove.' );

$stack = oz_ship_stack( $db );
$external_after_order = new OzonShipmentSmokeOrder( 85402, '85402', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$external_after_create = $stack['service']->create( $external_after_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85402, '85402' ) );
$stack['http']->statuses['OZON-1'] = 'ON_WAY';
$stack['adapter']->update_status( $external_after_order );
$stack['http']->statuses['OZON-1'] = 'CANCELED';
$stack['http']->return_pages = array( array( 'returns' => array( array( 'return_number' => 'R1', 'return_external_id' => '85402', 'status' => 'MOVING', 'status_changed_at' => '2026-08-31T10:00:00Z' ) ), 'next_cursor' => '' ) );
$stack['http']->return_info = array( 'R1' => array( 'return_number' => 'R1', 'return_external_id' => '85402', 'status' => 'MOVING', 'status_changed_at' => '2026-08-31T10:05:00Z' ) );
$external_after_status = $stack['adapter']->update_status( $external_after_order );
$external_after_payload = $stack['adapter']->status_payload( $external_after_order, $external_after_status['shipment'] );
oz_ship_assert( $external_after_create->success && DeliveryStatus::RETURNING_TO_SENDER === (string) ( $external_after_status['shipment']['universal_status_code'] ?? '' ) && 'R1' === (string) ( $external_after_status['shipment']['ozon_returns'][0]['return_number'] ?? '' ) && ! empty( $external_after_payload['can_remove_from_order'] ) && empty( $external_after_payload['can_cancel'] ) && str_contains( (string) ( $external_after_payload['return_tracking_presentation']['display_text'] ?? '' ), 'R1' ), 'External CANCELED after handover must find return by exact external ID, call return/info, persist active return, and expose return tracking.' );
oz_ship_assert( 1 === count( $stack['http']->calls_for( '/v1/return/search' ) ) && 1 === count( $stack['http']->calls_for( '/v1/return/info' ) ), 'First found Ozon return must use search once and then return/info.' );
$stack['http']->return_info['R1']['status'] = 'RECEIVED';
$received_status = $stack['adapter']->update_status( $external_after_order );
oz_ship_assert( DeliveryStatus::RETURNED_TO_SENDER === (string) ( $received_status['shipment']['universal_status_code'] ?? '' ) && 1 === count( $stack['http']->calls_for( '/v1/return/search' ) ) && 2 === count( $stack['http']->calls_for( '/v1/return/info' ) ), 'Stored Ozon return_number must use return/info on later sync without another full return/search and RECEIVED must map to returned_to_sender.' );

$stack = oz_ship_stack( $db );
$return_not_found_order = new OzonShipmentSmokeOrder( 85403, '85403', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$return_not_found_create = $stack['service']->create( $return_not_found_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85403, '85403' ) );
$stack['http']->statuses['OZON-1'] = 'ON_WAY';
$stack['adapter']->update_status( $return_not_found_order );
$stack['http']->statuses['OZON-1'] = 'CANCELED';
$stack['http']->return_pages = array( array( 'returns' => array( array( 'return_number' => 'RX', 'return_external_id' => '85403-extra', 'status' => 'MOVING' ) ), 'next_cursor' => '' ) );
$return_not_found_status = $stack['adapter']->update_status( $return_not_found_order );
$return_not_found_payload = $stack['adapter']->status_payload( $return_not_found_order, $return_not_found_status['shipment'] );
oz_ship_assert( $return_not_found_create->success && DeliveryStatus::UNKNOWN === (string) ( $return_not_found_status['shipment']['universal_status_code'] ?? '' ) && 'not_found' === (string) ( $return_not_found_status['shipment']['ozon_return_search']['search_state'] ?? '' ) && str_starts_with( (string) ( $return_not_found_payload['return_tracking_presentation']['display_text'] ?? '' ), 'не найден' ) && 'исходное отправление отменено' === (string) ( $return_not_found_payload['carrier_status_title'] ?? '' ), 'Expected Ozon return not found after handover must be UNKNOWN with safe diagnostics and no substring match: ' . json_encode( array( 'status' => $return_not_found_status['shipment']['universal_status_code'] ?? '', 'search' => $return_not_found_status['shipment']['ozon_return_search'] ?? array(), 'return_tracking' => $return_not_found_payload['return_tracking_presentation'] ?? array(), 'carrier' => $return_not_found_payload['carrier_status_title'] ?? '' ), JSON_UNESCAPED_UNICODE ) );

$stack = oz_ship_stack( $db );
$multi_return_order = new OzonShipmentSmokeOrder( 85404, '85404', array( new OzonShipmentSmokeOrderItem( 101, 2, '2000.00' ) ) );
$multi_return_create = $stack['service']->create( $multi_return_order, oz_ship_request( array(
	new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ),
	new ShipmentPlace( 2, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ),
), array(
	array( 'item_key' => '101', 'ordered_quantity' => 2, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ),
	array( 'item_key' => '101:split', 'ordered_quantity' => 2, 'place_number' => 2, 'amount' => 1, 'cost' => 1000 ),
), '777', 85404, '85404' ) );
$stack['http']->statuses['OZON-1'] = 'ON_WAY';
$stack['http']->statuses['OZON-2'] = 'ON_WAY';
$stack['adapter']->update_status( $multi_return_order );
$stack['http']->statuses['OZON-1'] = 'CANCELED';
$stack['http']->statuses['OZON-2'] = 'CANCELED';
$stack['http']->return_pages = array(
	array( 'returns' => array( array( 'return_number' => 'R1', 'return_external_id' => '85404-1', 'status' => 'RECEIVED' ) ), 'next_cursor' => '1' ),
	array( 'returns' => array( array( 'return_number' => 'R2', 'return_external_id' => '85404-2', 'status' => 'MOVING' ), array( 'return_number' => 'R2', 'return_external_id' => '85404-2', 'status' => 'MOVING' ) ), 'next_cursor' => '' ),
);
$stack['http']->return_info = array(
	'R1' => array( 'return_number' => 'R1', 'return_external_id' => '85404-1', 'status' => 'RECEIVED' ),
	'R2' => array( 'return_number' => 'R2', 'return_external_id' => '85404-2', 'status' => 'MOVING' ),
);
$multi_return_status = $stack['adapter']->update_status( $multi_return_order );
oz_ship_assert( $multi_return_create->success && DeliveryStatus::RETURNING_TO_SENDER === (string) ( $multi_return_status['shipment']['universal_status_code'] ?? '' ) && 2 === count( $multi_return_status['shipment']['ozon_returns'] ?? array() ) && 2 === (int) ( $multi_return_status['shipment']['ozon_return_search']['pages_scanned'] ?? 0 ), 'Ozon multi-box return search must continue to the second page, deduplicate returns, and stay returning while one return is active.' );
$stack['http']->return_info['R2']['status'] = 'RECEIVED';
$multi_return_received = $stack['adapter']->update_status( $multi_return_order );
oz_ship_assert( DeliveryStatus::RETURNED_TO_SENDER === (string) ( $multi_return_received['shipment']['universal_status_code'] ?? '' ), 'Ozon multi-box returns must become returned_to_sender only after all expected returns are RECEIVED.' );

$stack = oz_ship_stack( $db );
$info_error_order = new OzonShipmentSmokeOrder( 85405, '85405', array( new OzonShipmentSmokeOrderItem( 101, 1, '1000.00' ) ) );
$info_error_create = $stack['service']->create( $info_error_order, oz_ship_request( array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ) ) ), array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'amount' => 1, 'cost' => 1000 ) ), '777', 85405, '85405' ) );
$stack['http']->statuses['OZON-1'] = 'ON_WAY';
$stack['adapter']->update_status( $info_error_order );
$stack['http']->statuses['OZON-1'] = 'CANCELED';
$stack['http']->return_pages = array( array( 'returns' => array( array( 'return_number' => 'R5', 'return_external_id' => '85405', 'status' => 'MOVING' ) ), 'next_cursor' => '' ) );
$stack['http']->return_info = array( 'R5' => array( 'return_number' => 'R5', 'return_external_id' => '85405', 'status' => 'MOVING' ) );
$stack['adapter']->update_status( $info_error_order );
$stack['http']->fail_return_info = true;
$info_error_status = $stack['adapter']->update_status( $info_error_order );
oz_ship_assert( $info_error_create->success && 'R5' === (string) ( $info_error_status['shipment']['ozon_returns'][0]['return_number'] ?? '' ) && 'MOVING' === (string) ( $info_error_status['shipment']['ozon_returns'][0]['status'] ?? '' ) && DeliveryStatus::RETURNING_TO_SENDER === (string) ( $info_error_status['shipment']['universal_status_code'] ?? '' ), 'Ozon return/info failure must preserve existing return number and last known status without falling to cancelled.' );

echo "Ozon Delivery shipment smoke passed.\n";
