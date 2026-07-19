<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/shipments/admin-js-bundle-source.php';

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
define( 'WDC_SECRET_KEY', 'yandex-framework-smoke-key' );

function yd_framework_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['yd_framework_options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value, bool $autoload = true ): bool { $GLOBALS['yd_framework_options'][ $name ] = $value; return true; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( mixed $value ): string { return trim( (string) $value ); }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function current_time( string $type ): string { return gmdate( 'Y-m-d H:i:s' ); }
function get_current_user_id(): int { return 7; }
function wc_get_order_statuses(): array { return array( 'wc-processing' => 'Processing', 'wc-shipped' => 'Shipped', 'wc-completed' => 'Completed' ); }
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
	}
}
$GLOBALS['wpdb'] = new wpdb();

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryEarliestOfferSelector;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentClient;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentPayloadBuilder;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentRegistrationService as CoreYandexRegistrationService;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentMetaboxButtonPolicy;
use WallsShop\WDC\Shipments\Application\ShipmentModalRequestMapper;
use WallsShop\WDC\Shipments\Application\ShipmentOrderStatusMappingService;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentAdapter;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentButtonPolicy;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentDocumentProvider;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentDocumentService;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentLabelPolicy;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentRegistrationService;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentRepository;
use WallsShop\WDC\Shipments\YandexDelivery\YandexStatusMapping;

final class YdFrameworkFakeHttp implements YandexDeliveryHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	/** @param array<int,YandexDeliveryApiResponse> $queue */
	public function __construct( private array $queue ) {}
	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		$response = array_shift( $this->queue );
		if ( ! $response instanceof YandexDeliveryApiResponse ) {
			throw new RuntimeException( 'Unexpected empty fake queue.' );
		}
		return $response;
	}
}

final class YdFrameworkOrder {
	/** @var array<string,mixed> */
	public array $meta = array();
	/** @var array<int,string> */
	public array $notes = array();
	public string $status = 'processing';
	public function __construct( private int $id, private string $order_number = '' ) {}
	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
	public function save(): void {}
	public function add_order_note( string $note ): void { $this->notes[] = $note; }
	public function get_status(): string { return $this->status; }
	public function update_status( string $status ): void { $this->status = $status; }
	public function get_order_number(): string { return '' !== $this->order_number ? $this->order_number : 'ORDER-' . (string) $this->id; }
	public function get_items(): array { return array( new YdFrameworkOrderItem( 101, 'Item A', 'SKU-A', 3 ) ); }
	public function get_shipping_first_name(): string { return 'Михаил'; }
	public function get_shipping_last_name(): string { return 'Михайлов'; }
	public function get_billing_phone(): string { return '9131234567'; }
	public function get_billing_email(): string { return 'buyer@example.test'; }
	public function get_shipping_country(): string { return 'RU'; }
	public function get_shipping_state(): string { return 'Москва'; }
	public function get_shipping_city(): string { return 'Москва'; }
	public function get_shipping_postcode(): string { return '125252'; }
	public function get_shipping_address_1(): string { return 'Ходынский бульвар, 9'; }
	public function get_shipping_address_2(): string { return '15'; }
}

final class YdFrameworkOrderItem {
	public function __construct( private int $id, private string $name, private string $sku, private int $quantity ) {}
	public function get_id(): int { return $this->id; }
	public function get_product(): object { return new YdFrameworkProduct( $this->sku ); }
	public function get_quantity(): int { return $this->quantity; }
	public function get_total(): float { return 300.0; }
	public function get_name(): string { return $this->name; }
}

final class YdFrameworkProduct {
	public function __construct( private string $sku ) {}
	public function get_sku(): string { return $this->sku; }
	public function get_weight(): string { return '0.5'; }
	public function get_length(): string { return '10'; }
	public function get_width(): string { return '10'; }
	public function get_height(): string { return '5'; }
}

function yd_framework_settings(): YandexDeliverySettings {
	$GLOBALS['yd_framework_options'] = array();
	$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
	$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'secret-test-token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'SRC-1' ) );

	return $settings;
}
function yd_framework_document_provider( YandexShipmentLabelPolicy $policy, ?OrderShipmentRepository $repository = null ): YandexShipmentDocumentProvider {
	$repository ??= new OrderShipmentRepository();
	$service = new YandexShipmentDocumentService(
		new YandexShipmentRepository( $repository ),
		new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() ), new YdFrameworkFakeHttp( array() ) ) ),
		$policy
	);

	return new YandexShipmentDocumentProvider( $service, $policy );
}
function yd_framework_response( array $body ): YandexDeliveryApiResponse {
	return new YandexDeliveryApiResponse( 200, json_encode( $body, JSON_UNESCAPED_UNICODE ) ?: '{}' );
}
function yd_framework_error_response( int $code, array $body ): YandexDeliveryApiResponse {
	return new YandexDeliveryApiResponse( $code, json_encode( $body, JSON_UNESCAPED_UNICODE ) ?: '{}' );
}
function yd_framework_offer( string $offer_id ): array {
	return array(
		'offer_id' => $offer_id,
		'expires_at' => '2026-07-11T16:23:01.000000Z',
		'offer_details' => array(
			'delivery_interval' => array( 'min' => '2026-07-21T07:00:00.000000Z', 'max' => '2026-07-21T20:00:00.000000Z', 'policy' => 'self_pickup' ),
			'pickup_interval' => array( 'min' => '2026-07-11T16:08:01.000000Z', 'max' => '2026-07-13T05:00:00.000000Z' ),
			'pricing' => '298.8 RUB',
			'pricing_total' => '298.8 RUB',
			'features' => array(),
		),
	);
}
function yd_framework_info( string $request_id, string $status, string $real_barcode, ?string $operator_request_id = 'ORDER-777' ): array {
	$info = array();
	if ( null !== $operator_request_id ) {
		$info['operator_request_id'] = $operator_request_id;
	}

	return array(
		'request_id' => $request_id,
		'request' => array(
			'info' => $info,
			'destination' => array( 'type' => 'platform_station', 'platform_station' => array( 'platform_id' => 'PVZ-1' ) ),
			'recipient_info' => array( 'first_name' => 'Михайлов Михаил', 'last_name' => '', 'patronymic' => '', 'phone' => '+79131234567', 'email' => 'buyer@example.test' ),
			'items' => array( array( 'count' => 1, 'name' => 'Item A', 'article' => 'SKU-A', 'barcode' => 'ITEM-YD-1', 'billing_details' => array( 'nds' => -1, 'unit_price' => 10000, 'assessed_unit_price' => 10000 ), 'place_barcode' => $real_barcode, 'refused_count' => 0 ) ),
			'places' => array( array( 'barcode' => $real_barcode, 'physical_dims' => array( 'weight_gross' => 1000, 'dx' => 20, 'dy' => 20, 'dz' => 10 ) ) ),
			'available_actions' => array( 'update_recipient' => true, 'update_places' => true ),
			'delivery_policy' => array( 'min' => 1784617200, 'max' => 1784664000, 'policy' => 'interval_strict' ),
		),
		'state' => array( 'status' => $status, 'description' => 'state ' . $status, 'timestamp' => '2026-07-11T16:10:40.000000Z' ),
		'full_items_price' => 10000,
		'sharing_url' => 'https://dostavka.yandex.ru/route/example',
		'self_pickup_node_code' => array( 'type' => 'pickup', 'code' => '00000' ),
		'courier_order_id' => '880191690',
	);
}
function yd_framework_incomplete_info( string $request_id, ?string $operator_request_id = 'ORDER-777' ): array {
	$info = array();
	if ( null !== $operator_request_id ) {
		$info['operator_request_id'] = $operator_request_id;
	}

	return array(
		'request_id' => $request_id,
		'request' => array(
			'info' => $info,
			'destination' => array( 'type' => 'platform_station', 'platform_station' => array( 'platform_id' => 'PVZ-1' ) ),
			'recipient_info' => array( 'first_name' => 'Михайлов Михаил', 'phone' => '+79131234567' ),
			'items' => array(),
			'places' => array(),
		),
	);
}
function yd_framework_request( int $order_id = 777, string $order_num = 'ORDER-777' ): ShipmentCreateRequest {
	$item = new PackageItem( 'SKU-A', 'Item A', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 500, 10, 10, 5 );
	return new ShipmentCreateRequest(
		$order_id,
		YandexDeliverySettings::CARRIER_KEY,
		DeliveryType::PICKUP,
		YandexDeliverySettings::CARRIER_KEY . ':pickup',
		new Address( country_code: 'RU', city: 'Москва', raw_address: 'ПВЗ Яндекс' ),
		null,
		array( new ShipmentPlace( 1, 1000, 20, 20, 10, Money::from_kopecks( 0 ), array( $item ) ) ),
		Money::from_rubles( 100 ),
		false,
		array(),
		array( 'name' => 'Михайлов Михаил', 'phone' => '9131234567', 'email' => 'buyer@example.test' ),
		array(
			'service_key' => YandexDeliverySettings::SERVICE_KEY,
			'service_title' => YandexDeliverySettings::TITLE,
			'order_num' => $order_num,
			'yandex_operator_request_id' => $order_num,
			'yandex_source_platform_station_id' => 'SRC-1',
			'yandex_ready_from' => '2026-07-12 12:00:00+07:00',
			'yandex_ready_to' => '2026-07-12 12:00:00+07:00',
			'yandex_pickup_platform_station_id' => 'PVZ-1',
			'shipment_item_rows' => array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'name' => 'Item A', 'sku' => 'SKU-A', 'amount' => 1, 'unit_price_kopecks' => 10000, 'assessed_unit_price_kopecks' => 10000, 'weight' => 500 ) ),
		)
	);
}
function yd_framework_request_without_item_rows( int $order_id = 1777, string $order_num = 'ORDER-1777' ): ShipmentCreateRequest {
	$request = yd_framework_request( $order_id, $order_num );
	$meta = $request->meta;
	unset( $meta['shipment_item_rows'] );

	return new ShipmentCreateRequest(
		$request->order_id,
		$request->carrier_key,
		$request->delivery_type,
		$request->rate_id,
		$request->recipient_address,
		$request->pickup_point,
		$request->places,
		$request->declared_value,
		$request->insurance_enabled,
		$request->services,
		$request->recipient,
		$meta
	);
}

/** @return array{0:OrderShipmentRepository,1:YandexShipmentAdapter,2:ShipmentCreationService,3:YandexShipmentRegistrationService,4:YdFrameworkFakeHttp} */
function yd_framework_stack( array $responses ): array {
	$fake = new YdFrameworkFakeHttp( $responses );
	$client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_framework_settings(), $fake ) );
	$payload_builder = new YandexDeliveryShipmentPayloadBuilder();
	$core_registration = new CoreYandexRegistrationService( $payload_builder, $client, new YandexDeliveryEarliestOfferSelector() );
	$base_repository = new OrderShipmentRepository();
	$yandex_repository = new YandexShipmentRepository( $base_repository );
	$status_mapping = new YandexStatusMapping( new SettingsRepository() );
	$order_status_mapping = new ShipmentOrderStatusMappingService( new SettingsRepository() );
	$mapper = new YandexShipmentPersistenceMapper( $yandex_repository, $status_mapping, $order_status_mapping );
	$button_policy = new YandexShipmentButtonPolicy( $status_mapping );
	$framework_registration = new YandexShipmentRegistrationService( $core_registration, $payload_builder, $client, $yandex_repository, $mapper, $button_policy, $status_mapping, $order_status_mapping );
	$adapter = new YandexShipmentAdapter( $framework_registration, $button_policy, $status_mapping );
	$registry = new CarrierShipmentAdapterRegistry( array( $adapter ) );
	$creation = new ShipmentCreationService( $base_repository, array( $adapter ), null, $registry, array( $mapper ) );

	return array( $base_repository, $adapter, $creation, $framework_registration, $fake );
}

$fake = new YdFrameworkFakeHttp( array(
	yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-1' ) ) ) ),
	yd_framework_response( array( 'request_id' => 'REQ-777' ) ),
	yd_framework_response( yd_framework_info( 'REQ-777', 'CREATED', 'YD-REAL-1' ) ),
	yd_framework_response( yd_framework_info( 'REQ-777', 'CREATED', 'YD-REAL-1' ) ),
	yd_framework_response( array( 'status' => 'CREATED', 'description' => 'Заказ отменяется', 'reason' => 'cancellation_started' ) ),
	yd_framework_response( yd_framework_info( 'REQ-777', 'CANCELLED', 'YD-REAL-1' ) ),
	yd_framework_response( array( 'state_history' => array( array( 'status' => 'CREATED' ), array( 'status' => 'CANCELLED', 'reason' => 'SHOP_CANCELLED' ) ) ) ),
) );
$settings = yd_framework_settings();
$api = new YandexDeliveryApiClient( $settings, $fake );
$client = new YandexDeliveryShipmentClient( $api );
$payload_builder = new YandexDeliveryShipmentPayloadBuilder();
$core_registration = new CoreYandexRegistrationService( $payload_builder, $client, new YandexDeliveryEarliestOfferSelector() );
$base_repository = new OrderShipmentRepository();
$yandex_repository = new YandexShipmentRepository( $base_repository );
$status_mapping = new YandexStatusMapping( new SettingsRepository() );
$order_status_mapping = new ShipmentOrderStatusMappingService( new SettingsRepository() );
$mapper = new YandexShipmentPersistenceMapper( $yandex_repository, $status_mapping, $order_status_mapping );
$button_policy = new YandexShipmentButtonPolicy( $status_mapping );
$framework_registration = new YandexShipmentRegistrationService( $core_registration, $payload_builder, $client, $yandex_repository, $mapper, $button_policy, $status_mapping, $order_status_mapping );
$adapter = new YandexShipmentAdapter( $framework_registration, $button_policy, $status_mapping );
$registry = new CarrierShipmentAdapterRegistry( array( $adapter ) );
$creation = new ShipmentCreationService( $base_repository, array( $adapter ), null, $registry, array( $mapper ) );
$order = new YdFrameworkOrder( 777 );
$request = yd_framework_request();

yd_framework_assert( $registry->get( YandexDeliverySettings::CARRIER_KEY ) instanceof YandexShipmentAdapter, 'Yandex adapter must be registered through CarrierShipmentAdapterRegistry.' );
yd_framework_assert( array() === ( new YandexShipmentButtonPolicy( $status_mapping ) )->resolve( array( 'yandex_request_id' => 'REQ-777', 'yandex_status' => 'CREATED' ) ) ? false : true, 'Yandex button policy must resolve created shipments.' );

$expected_yandex_statuses = array(
	'DRAFT', 'VALIDATING', 'VALIDATING_ERROR', 'CREATED', 'DELIVERY_PROCESSING_STARTED', 'DELIVERY_TRACK_RECIEVED', 'SORTING_CENTER_PROCESSING_STARTED',
	'SORTING_CENTER_TRACK_RECEIVED', 'SORTING_CENTER_TRACK_LOADED', 'DELIVERY_LOADED', 'SORTING_CENTER_LOADED', 'SORTING_CENTER_AT_START', 'SORTING_CENTER_PREPARED',
	'SORTING_CENTER_TRANSMITTED', 'DELIVERY_AT_START', 'DELIVERY_AT_START_SORT', 'DELIVERY_TRANSPORTATION_RECIPIENT', 'DELIVERY_TRANSPORTATION',
	'DELIVERY_ARRIVED_PICKUP_POINT', 'CONFIRMATION_CODE_RECEIVED', 'DELIVERY_TRANSMITTED_TO_RECIPIENT', 'DELIVERY_ATTEMPT_FAILED',
	'DELIVERY_STORAGE_PERIOD_EXPIRED', 'PARTICULARLY_DELIVERED', 'DELIVERY_DELIVERED', 'CANCELLED', 'SORTING_CENTER_RETURN_PREPARING',
	'SORTING_CENTER_RETURN_PREPARING_SENDER', 'SORTING_CENTER_RETURN_ARRIVED', 'SORTING_CENTER_RETURN_RETURNED', 'RETURN_PREPARING',
	'RETURN_TRANSPORTATION_STARTED', 'RETURN_ARRIVED_DELIVERY', 'RETURN_TRANSMITTED_FULFILMENT', 'RETURN_READY_FOR_PICKUP', 'RETURN_RETURNED',
	'DELIVERY_TIME_INTERVALS_UPDATED',
);
$catalog = YandexStatusMapping::statuses();
yd_framework_assert( array() === array_diff( $expected_yandex_statuses, array_keys( $catalog ) ) && count( $expected_yandex_statuses ) === count( $catalog ) && count( $catalog ) === count( array_unique( array_keys( $catalog ) ) ), 'Yandex status catalog must contain the full documented union without duplicates.' );
foreach ( $catalog as $code => $row ) {
	yd_framework_assert( $code === $row['code'] && '' !== $row['description'] && in_array( $row['mode'], array( 'courier', 'pickup', 'both' ), true ) && DeliveryStatus::is_valid( $row['default'] ), 'Every Yandex status catalog row must have code, description, mode and valid default universal status.' );
}
yd_framework_assert( isset( $catalog['DELIVERY_TRACK_RECIEVED'] ) && ! isset( $catalog['DELIVERY_TRACK_RECEIVED'] ), 'Yandex catalog must keep official DELIVERY_TRACK_RECIEVED typo.' );
foreach ( array( 'SHOP_CANCELLED', 'USER_CHANGED_MIND', 'DELIVERY_PROBLEMS', 'BROKEN_ITEM', 'ORDER_WAS_LOST', 'DIMENSIONS_EXCEEDED', 'ORDER_IS_DAMAGED', 'EXTRA_RESCHEDULING', 'PICKUP_EXPIRED', 'LAST_MILE_CHANGED_BY_USER', 'CLIENT_REQUEST', 'DELIVERY_DATE_UPDATED_BY_DELIVERY', 'DELIVERY_DATE_UPDATED_BY_SHOP' ) as $reason_code ) {
	yd_framework_assert( ! isset( $catalog[ $reason_code ] ), 'Yandex reason codes must not be carrier statuses: ' . $reason_code );
}
yd_framework_assert( DeliveryStatus::PENDING_CREATION_IN_CARRIER === $status_mapping->universal_status_for( 'DRAFT' ), 'DRAFT must default to pending_creation_in_carrier.' );
yd_framework_assert( DeliveryStatus::CREATED_IN_CARRIER === $status_mapping->universal_status_for( 'CREATED' ), 'CREATED must default to created_in_carrier.' );
yd_framework_assert( DeliveryStatus::IN_TRANSIT === $status_mapping->universal_status_for( 'SORTING_CENTER_AT_START' ), 'SORTING_CENTER_AT_START must default to in_transit.' );
yd_framework_assert( DeliveryStatus::HANDED_TO_COURIER === $status_mapping->universal_status_for( 'DELIVERY_TRANSPORTATION_RECIPIENT' ), 'Courier last-mile status must default to handed_to_courier.' );
yd_framework_assert( DeliveryStatus::READY_FOR_PICKUP === $status_mapping->universal_status_for( 'DELIVERY_ARRIVED_PICKUP_POINT' ), 'Pickup arrival must default to ready_for_pickup.' );
yd_framework_assert( DeliveryStatus::DELIVERED === $status_mapping->universal_status_for( 'DELIVERY_DELIVERED' ), 'DELIVERY_DELIVERED must default to delivered.' );
yd_framework_assert( DeliveryStatus::IN_TRANSIT === $status_mapping->universal_status_for( 'PARTICULARLY_DELIVERED' ), 'PARTICULARLY_DELIVERED must default to in_transit because partial delivery is not full delivery.' );
yd_framework_assert( DeliveryStatus::RETURNING_TO_SENDER === $status_mapping->universal_status_for( 'DELIVERY_STORAGE_PERIOD_EXPIRED' ), 'Expired pickup storage must default to returning_to_sender.' );
yd_framework_assert( DeliveryStatus::RETURNED_TO_SENDER === $status_mapping->universal_status_for( 'SORTING_CENTER_RETURN_RETURNED' ), 'Return completed at sorting center must default to returned_to_sender.' );
yd_framework_assert( DeliveryStatus::CANCELLED === $status_mapping->universal_status_for( 'CANCELLED' ), 'CANCELLED must default to cancelled.' );
yd_framework_assert( DeliveryStatus::REJECTED === $status_mapping->universal_status_for( 'VALIDATING_ERROR' ), 'VALIDATING_ERROR must default to rejected.' );
yd_framework_assert( DeliveryStatus::UNKNOWN === $status_mapping->universal_status_for( 'YANDEX_NEW_UNKNOWN_STATUS' ), 'Unknown Yandex raw status must resolve to unknown.' );
$override_mapping = new YandexStatusMapping( new SettingsRepository() );
$override = YandexStatusMapping::default_mapping();
$override['CREATED'] = DeliveryStatus::IN_TRANSIT;
$override_mapping->save_mapping( $override );
yd_framework_assert( DeliveryStatus::IN_TRANSIT === $override_mapping->universal_status_for( 'CREATED' ), 'Admin override CREATED -> in_transit must be respected by mapper.' );
$override['PARTICULARLY_DELIVERED'] = DeliveryStatus::DELIVERED;
$override_mapping->save_mapping( $override );
yd_framework_assert( DeliveryStatus::DELIVERED === $override_mapping->universal_status_for( 'PARTICULARLY_DELIVERED' ), 'Admin override PARTICULARLY_DELIVERED -> delivered must remain possible.' );
yd_framework_assert( DeliveryStatus::CREATED_IN_CARRIER === $override_mapping->sanitize_mapping( array( 'CREATED' => 'not_a_real_status' ) )['CREATED'], 'Invalid universal status override must fall back to default.' );
yd_framework_assert( ! isset( $override_mapping->sanitize_mapping( array( 'UNKNOWN_YANDEX_CODE' => DeliveryStatus::DELIVERED ) )['UNKNOWN_YANDEX_CODE'] ), 'Unknown Yandex carrier status code must not be saved.' );
$override_mapping->save_mapping( YandexStatusMapping::default_mapping() );
yd_framework_assert( DeliveryStatus::IN_TRANSIT === $override_mapping->universal_status_for( 'PARTICULARLY_DELIVERED' ), 'Reset to Yandex defaults must restore PARTICULARLY_DELIVERED -> in_transit.' );
$admin_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ) ?: '';
yd_framework_assert( str_contains( $admin_source, 'save_yandex_delivery_statuses' ) && str_contains( $admin_source, 'render_yandex_delivery_statuses_tab' ) && str_contains( $admin_source, 'YandexStatusMapping::MAPPING_KEY' ) && str_contains( $admin_source, 'Сбросить к дефолтным значениям' ), 'Delivery services admin must expose Yandex status mapping tab, save action and reset control.' );
$policy_by_universal = new YandexShipmentButtonPolicy( $status_mapping );
foreach ( array( DeliveryStatus::PENDING_CREATION_IN_CARRIER, DeliveryStatus::CREATED_IN_CARRIER ) as $universal_status ) {
	$resolved = $policy_by_universal->resolve( array( 'yandex_request_id' => 'REQ-POLICY', 'status' => 'created', 'universal_status_code' => $universal_status ) );
	yd_framework_assert( ! empty( $resolved['cancel'] ) && empty( $resolved['remove'] ), 'Yandex policy must allow cancel and hide remove for early universal status ' . $universal_status );
}
foreach ( array( DeliveryStatus::IN_TRANSIT, DeliveryStatus::READY_FOR_PICKUP, DeliveryStatus::HANDED_TO_COURIER, DeliveryStatus::DELIVERED, DeliveryStatus::RETURNING_TO_SENDER, DeliveryStatus::RETURNED_TO_SENDER, DeliveryStatus::CANCELLED, DeliveryStatus::REJECTED, DeliveryStatus::UNKNOWN ) as $universal_status ) {
	$resolved = $policy_by_universal->resolve( array( 'yandex_request_id' => 'REQ-POLICY', 'status' => 'created', 'universal_status_code' => $universal_status ) );
	yd_framework_assert( empty( $resolved['cancel'] ) && ! empty( $resolved['remove'] ), 'Yandex policy must hide cancel and allow remove for non-early universal status ' . $universal_status );
}
$override_policy = new YandexShipmentButtonPolicy( $override_mapping );
$override_mapping->save_mapping( array_merge( YandexStatusMapping::default_mapping(), array( 'CREATED' => DeliveryStatus::IN_TRANSIT ) ) );
$overridden_created_policy = $override_policy->resolve( array( 'yandex_request_id' => 'REQ-OVERRIDE', 'status' => 'created', 'yandex_status' => 'CREATED' ) );
yd_framework_assert( empty( $overridden_created_policy['cancel'] ) && ! empty( $overridden_created_policy['remove'] ), 'Yandex button policy must respect admin override and not raw CREATED.' );
$override_mapping->save_mapping( YandexStatusMapping::default_mapping() );

$pending_cancel_order = new YdFrameworkOrder( 971, '971' );
list( $pending_cancel_repository, $pending_cancel_adapter, $pending_cancel_creation, $pending_cancel_registration, $pending_cancel_http ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'status' => 'CREATED', 'description' => 'Заказ отменяется', 'reason' => 'cancellation_started' ) ),
		yd_framework_response( yd_framework_info( 'REQ-PENDING-CANCEL', 'CREATED', 'YD-PENDING-CANCEL', '971' ) ),
	)
);
$pending_cancel_repository->save_for_carrier(
	$pending_cancel_order,
	YandexDeliverySettings::CARRIER_KEY,
	array(
		'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
		'status' => 'created',
		'yandex_request_id' => 'REQ-PENDING-CANCEL',
		'request_id' => 'REQ-PENDING-CANCEL',
		'universal_status_code' => DeliveryStatus::PENDING_CREATION_IN_CARRIER,
		'universal_status_label' => DeliveryStatus::label( DeliveryStatus::PENDING_CREATION_IN_CARRIER ),
	)
);
$pending_cancel_result = $pending_cancel_adapter->cancel_in_carrier( $pending_cancel_order );
yd_framework_assert( ! empty( $pending_cancel_result['success'] ) && 2 === count( $pending_cancel_http->requests ) && str_contains( $pending_cancel_http->requests[0]['url'], '/request/cancel' ), 'Server-side cancel guard must allow pending_creation_in_carrier and call Yandex cancel once.' );

foreach ( array( DeliveryStatus::IN_TRANSIT, DeliveryStatus::READY_FOR_PICKUP, DeliveryStatus::HANDED_TO_COURIER, DeliveryStatus::DELIVERED, DeliveryStatus::RETURNED_TO_SENDER, DeliveryStatus::REJECTED, DeliveryStatus::UNKNOWN ) as $blocked_cancel_status ) {
	$blocked_cancel_order = new YdFrameworkOrder( 972, '972' );
	list( $blocked_cancel_repository, $blocked_cancel_adapter, $blocked_cancel_creation, $blocked_cancel_registration, $blocked_cancel_http ) = yd_framework_stack( array() );
	$blocked_cancel_shipment = array(
		'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
		'status' => 'created',
		'yandex_request_id' => 'REQ-BLOCKED-CANCEL-' . $blocked_cancel_status,
		'request_id' => 'REQ-BLOCKED-CANCEL-' . $blocked_cancel_status,
		'universal_status_code' => $blocked_cancel_status,
		'universal_status_label' => DeliveryStatus::label( $blocked_cancel_status ),
	);
	$blocked_cancel_repository->save_for_carrier( $blocked_cancel_order, YandexDeliverySettings::CARRIER_KEY, $blocked_cancel_shipment );
	$blocked_cancel_result = $blocked_cancel_adapter->cancel_in_carrier( $blocked_cancel_order );
	$blocked_cancel_after = $blocked_cancel_repository->find_by_carrier( $blocked_cancel_order, YandexDeliverySettings::CARRIER_KEY );
	yd_framework_assert( empty( $blocked_cancel_result['success'] ) && 'Текущее отправление Яндекс нельзя отменить.' === (string) ( $blocked_cancel_result['message'] ?? '' ) && array() === $blocked_cancel_http->requests && $blocked_cancel_after === $blocked_cancel_shipment, 'Server-side cancel guard must reject forged cancel without API for universal status ' . $blocked_cancel_status );
}

$settings_repository = new SettingsRepository();
$settings_repository->set( ShipmentOrderStatusMappingService::ENABLED_KEY, true );
$settings_repository->set( ShipmentOrderStatusMappingService::MAPPING_KEY, array( DeliveryStatus::CREATED_IN_CARRIER => 'wc-shipped' ) );

$result = $creation->create( $order, $request );
yd_framework_assert( $result->success && 'REQ-777' === $result->external_id && '880191690' === $result->tracking_number, 'ShipmentCreationService must create Yandex shipment through adapter and registration service.' );
yd_framework_assert( 'shipped' === $order->get_status(), 'Yandex synchronous create must apply universal-to-Woo status mapping through the shared service.' );
$shipment = $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'REQ-777' === (string) ( $shipment['yandex_request_id'] ?? '' ) && 'OFFER-1' === (string) ( $shipment['yandex_selected_offer_id'] ?? '' ) && 'CREATED' === (string) ( $shipment['yandex_status'] ?? '' ), 'Repository must persist request_id, selected_offer_id and Yandex status.' );
yd_framework_assert( DeliveryStatus::CREATED_IN_CARRIER === (string) ( $shipment['universal_status_code'] ?? '' ) && DeliveryStatus::label( DeliveryStatus::CREATED_IN_CARRIER ) === (string) ( $shipment['universal_status_label'] ?? '' ) && 'state CREATED' === (string) ( $shipment['yandex_status_description'] ?? '' ), 'Repository must persist mapped universal Yandex status fields and raw API status description.' );
yd_framework_assert( '2026-07-11T16:23:01.000000Z' === (string) ( $shipment['yandex_offer_expires_at'] ?? '' ), 'Repository must persist selected offer expires_at.' );
yd_framework_assert( '298.8 RUB' === (string) ( $shipment['yandex_offer_pricing'] ?? '' ) && '298.8 RUB' === (string) ( $shipment['yandex_offer_pricing_total'] ?? '' ) && 29880 === (int) ( $shipment['yandex_offer_pricing_total_kopecks'] ?? 0 ), 'Repository must persist selected offer pricing audit fields.' );
yd_framework_assert( 29880 === (int) ( $shipment['actual_cost_kopecks'] ?? 0 ) && 'yandex_selected_offer' === (string) ( $shipment['actual_cost_source'] ?? '' ), 'Normal Yandex create must persist selected offer pricing_total as common actual-cost kopecks without scale drift.' );
yd_framework_assert( '2026-07-21T07:00:00.000000Z' === (string) ( $shipment['yandex_offer_delivery_interval']['min'] ?? '' ) && '2026-07-13T05:00:00.000000Z' === (string) ( $shipment['yandex_offer_pickup_interval']['max'] ?? '' ) && 'OFFER-1' === (string) ( $shipment['yandex_selected_offer_snapshot']['offer_id'] ?? '' ), 'Repository must persist selected offer interval snapshot audit fields.' );
yd_framework_assert( 'REQ-777' === (string) $order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Successful create must persist Yandex request_id lookup meta.' );
yd_framework_assert( 'YD-REAL-1' === (string) ( $shipment['yandex_place_barcode_map']['ORDER-777-1'] ?? '' ) && 'YD-REAL-1' === (string) ( $shipment['yandex_places'][0]['barcode'] ?? '' ) && 'ITEM-YD-1' === (string) ( $shipment['yandex_items'][0]['barcode'] ?? '' ), 'Repository must persist request/info items, places and temporary-to-real barcode map.' );
yd_framework_assert( '00000' === (string) ( $shipment['yandex_self_pickup_node_code'] ?? '' ) && 'pickup' === (string) ( $shipment['yandex_self_pickup_node_type'] ?? '' ), 'Repository must persist Yandex self_pickup_node_code.code as a string with leading zeros.' );
yd_framework_assert( array() === (array) ( $shipment['request_snapshot']['body'] ?? array( 'not-empty' ) ) && is_array( $shipment['response_snapshot'] ?? null ), 'Yandex persistence must not store offers/create payload body and must store canonical request/info snapshot.' );
yd_framework_assert( $base_repository->has_created_for_carrier( $order, YandexDeliverySettings::CARRIER_KEY ), 'Existing repository duplicate guard must see created Yandex shipment.' );

$duplicate = $creation->create( $order, $request );
yd_framework_assert( ! $duplicate->success && 'shipment_already_created' === $duplicate->error_code && 3 === count( $fake->requests ), 'Repeat Yandex registration must be blocked by ShipmentCreationService without HTTP.' );

$status = $adapter->update_status( $order );
yd_framework_assert( ! empty( $status['success'] ) && 4 === count( $fake->requests ), 'Yandex status update must call request/info through adapter.' );
$updated_after_status = $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'REQ-777' === (string) $order->get_meta( '_wdc_yandex_delivery_request_id', true ) && 'OFFER-1' === (string) ( $updated_after_status['yandex_selected_offer_id'] ?? '' ) && '2026-07-11T16:23:01.000000Z' === (string) ( $updated_after_status['yandex_offer_expires_at'] ?? '' ), 'Status update must keep lookup meta and selected offer audit fields.' );
$payload = $adapter->status_payload( $order, $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY ) );
yd_framework_assert( ! empty( $payload['can_update_status'] ) && ! empty( $payload['can_cancel'] ) && empty( $payload['can_attach_manual'] ), 'Yandex button policy must expose status/cancel and hide manual attach.' );
yd_framework_assert( 'CREATED' === (string) ( $payload['carrier_status_title'] ?? '' ), 'Yandex status payload must expose status value without duplicating carrier label.' );
yd_framework_assert( DeliveryStatus::CREATED_IN_CARRIER === (string) ( $payload['universal_status_code'] ?? '' ) && DeliveryStatus::label( DeliveryStatus::CREATED_IN_CARRIER ) === (string) ( $payload['shipment_status_label'] ?? '' ), 'Yandex status payload must expose universal status as primary shipment status.' );
yd_framework_assert( 29880 === (int) ( $payload['actual_cost_kopecks'] ?? 0 ) && '298.80 руб.' === (string) ( $payload['actual_cost_label'] ?? '' ) && 'neutral' === (string) ( $payload['actual_cost_compare_status'] ?? '' ), 'Yandex status payload must expose actual offer cost through the shared neutral cost contract when Base API cost is absent.' );
yd_framework_assert( '00000' === (string) ( $payload['yandex_self_pickup_node_code'] ?? '' ) && 'pickup' === (string) ( $payload['yandex_self_pickup_node_type'] ?? '' ), 'Yandex status payload must expose pickup code for static/runtime metabox rendering.' );
$order->update_meta_data( OrderShippingMetaPersister::CALCULATION_META_KEY, array( 'api' => array( 'api_base_price_kopecks' => 29010 ) ) );
$green_payload = $adapter->status_payload( $order, $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY ) );
yd_framework_assert( 'ok' === (string) ( $green_payload['actual_cost_compare_status'] ?? '' ), 'Yandex actual cost at or under the shared +3% threshold must be green/ok.' );
$order->update_meta_data( OrderShippingMetaPersister::CALCULATION_META_KEY, array( 'api' => array( 'api_base_price_kopecks' => 29009 ) ) );
$red_payload = $adapter->status_payload( $order, $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY ) );
yd_framework_assert( 'warning' === (string) ( $red_payload['actual_cost_compare_status'] ?? '' ), 'Yandex actual cost over the shared +3% threshold must be red/warning.' );
$order->delete_meta_data( OrderShippingMetaPersister::CALCULATION_META_KEY );
$document_provider = yd_framework_document_provider( new YandexShipmentLabelPolicy( new YandexStatusMapping( new SettingsRepository() ) ), $base_repository );
$document_actions = $document_provider->actions( $order, $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY ) );
yd_framework_assert( 1 === count( $document_actions ) && 'download_yandex_label' === $document_actions[0]->key && 'Скачать ярлык' === $document_actions[0]->label, 'Yandex provider must expose a shared document_actions download button for allowed universal statuses with request_id.' );
foreach ( array( DeliveryStatus::CREATED_IN_CARRIER, DeliveryStatus::IN_TRANSIT, DeliveryStatus::READY_FOR_PICKUP, DeliveryStatus::HANDED_TO_COURIER, DeliveryStatus::DELIVERED, DeliveryStatus::RETURNING_TO_SENDER, DeliveryStatus::RETURNED_TO_SENDER ) as $label_status ) {
	$actions = $document_provider->actions( $order, array( 'yandex_request_id' => 'REQ-LABEL-' . $label_status, 'universal_status_code' => $label_status ) );
	yd_framework_assert( 1 === count( $actions ), 'Yandex label action must be visible for universal status ' . $label_status );
}
foreach ( array( DeliveryStatus::PENDING_CREATION_IN_CARRIER, DeliveryStatus::CANCELLED, DeliveryStatus::REJECTED, DeliveryStatus::UNKNOWN ) as $label_status ) {
	$actions = $document_provider->actions( $order, array( 'yandex_request_id' => 'REQ-LABEL-' . $label_status, 'universal_status_code' => $label_status ) );
	yd_framework_assert( array() === $actions, 'Yandex label action must be hidden for universal status ' . $label_status );
}
yd_framework_assert( array() === $document_provider->actions( $order, array( 'yandex_request_id' => 'REQ-RECONCILE', 'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER, 'yandex_reconciliation_required' => true ) ) && array() === $document_provider->actions( $order, array( 'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER ) ), 'Yandex label action must be hidden during reconciliation and without server-side request_id.' );
$tracking_presentation = (array) ( $payload['tracking_presentation'] ?? array() );
yd_framework_assert( 'Отслеживание посылки' === (string) ( $tracking_presentation['label'] ?? '' ) && 'ссылка' === (string) ( $tracking_presentation['display_text'] ?? '' ) && 'https://dostavka.yandex.ru/route/example' === (string) ( $tracking_presentation['url'] ?? '' ) && 'https://dostavka.yandex.ru/route/example' === (string) ( $tracking_presentation['copy_value'] ?? '' ) && 'REQ-777' !== (string) ( $tracking_presentation['display_text'] ?? '' ), 'Yandex status payload must present sharing_url as tracking link text and clipboard value instead of request_id when available.' );
$invalid_tracking_payload = $adapter->status_payload( $order, array( 'yandex_request_id' => 'REQ-INVALID-URL', 'sharing_url' => 'javascript:alert(1)' ) );
$invalid_tracking = (array) ( $invalid_tracking_payload['tracking_presentation'] ?? array() );
yd_framework_assert( 'Request ID Яндекс' === (string) ( $invalid_tracking['label'] ?? '' ) && 'REQ-INVALID-URL' === (string) ( $invalid_tracking['display_text'] ?? '' ) && '' === (string) ( $invalid_tracking['url'] ?? '' ) && 'REQ-INVALID-URL' === (string) ( $invalid_tracking['copy_value'] ?? '' ) && ! str_contains( implode( "\n", $invalid_tracking ), 'javascript:' ), 'Invalid Yandex sharing_url must not be rendered as a link and must fall back to request_id.' );
$active_remove = $adapter->remove_from_order( $order );
yd_framework_assert( empty( $active_remove['success'] ) && 'Текущее отправление Яндекс нельзя удалить из заказа.' === (string) ( $active_remove['message'] ?? '' ) && array() !== $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY ) && 'REQ-777' === (string) $order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Server-side Yandex remove guard must reject active CREATED shipment and keep persistence.' );

$cancel = $adapter->cancel_in_carrier( $order );
yd_framework_assert( ! empty( $cancel['success'] ) && 6 === count( $fake->requests ), 'Yandex cancel must call request/cancel and canonical request/info.' );
$cancel_body = json_decode( (string) ( $fake->requests[4]['args']['body'] ?? '{}' ), true );
yd_framework_assert( 'REQ-777' === (string) ( $cancel_body['request_id'] ?? '' ), 'Yandex cancel must use request_id, not courier_order_id/tracking_number.' );
yd_framework_assert( ! empty( $cancel['cancelled_and_removed'] ) && empty( $cancel['auto_poll'] ) && array() === $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY ) && '' === (string) $order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Canonical CANCELLED after cancel must automatically remove local Yandex shipment, skip polling and clear lookup meta.' );

$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/OrderShipmentDraftFactory.php' );
yd_framework_assert( str_contains( $source, 'create_yandex_request_from_order' ) && str_contains( $source, 'shipment_item_rows' ) && ! str_contains( $source, 'shipment_item_rows_from_rows' ), 'OrderShipmentDraftFactory must provide canonical shipment item rows without a second Yandex parser.' );
yd_framework_assert( str_contains( $source, "'address_verified' => false" ) && str_contains( $source, "'dadata+yandex'" ) && ! str_contains( $source, 'street_from_address_line' ) && ! str_contains( $source, 'house_from_address_line' ), 'Yandex courier draft must keep structured address empty until DaData verification and must not use heuristic street/house parsing.' );
$yandex_registration_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/YandexDelivery/YandexShipmentRegistrationService.php' );
yd_framework_assert( ! str_contains( $yandex_registration_source, 'rows_from_places' ) && ! str_contains( $yandex_registration_source, 'PackageItem' ), 'Yandex registration service must not rebuild canonical rows from ShipmentPlace items.' );
$removed_manual_place_capability = 'requires_manual' . '_place_dimensions';
$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$preview_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentPreviewAjaxController.php' );
$create_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentCreateAjaxController.php' );
$address_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentAddressAjaxController.php' );
$manual_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentManualAttachAjaxController.php' );
$status_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentStatusAjaxController.php' );
$yandex_modal_extension_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/YandexDelivery/YandexShipmentModalExtension.php' );
yd_framework_assert( str_contains( $metabox_source, 'button_policy()->resolve' ) && str_contains( $metabox_source, 'data-wdc-open-shipment-modal' ), 'Existing shipment metabox/modal must be reused through shared capability-driven button policy.' );
yd_framework_assert( str_contains( $metabox_source, 'modal_extensions->get' ) && str_contains( $metabox_source, 'render_fields' ) && str_contains( $metabox_source, 'render_pickup_fields' ) && str_contains( $metabox_source, 'render_courier_fields' ) && str_contains( $yandex_modal_extension_source, 'data-wdc-yandex-source-station' ) && str_contains( $yandex_modal_extension_source, 'name="yandex_source_platform_station_id"' ) && str_contains( $yandex_modal_extension_source, 'data-wdc-yandex-pickup-destination' ) && str_contains( $yandex_modal_extension_source, 'name="yandex_pickup_platform_station_id"' ), 'Shared modal must call Yandex modal extension for source station and pickup destination fields.' );
yd_framework_assert( str_contains( $yandex_modal_extension_source, 'data-wdc-yandex-ready-interval' ) && str_contains( $yandex_modal_extension_source, 'name="yandex_ready_from"' ) && str_contains( $yandex_modal_extension_source, 'name="yandex_ready_to"' ), 'Yandex modal extension must render and submit ready interval values.' );
yd_framework_assert( str_contains( $metabox_source, '$requires_tariff' ) && str_contains( $yandex_modal_extension_source, 'data-wdc-yandex-offer-note' ), 'Shared modal must use extension capabilities to hide tariff controls for carriers that do not require them.' );
yd_framework_assert( str_contains( $metabox_source, 'foreach ( $place_rows as $place_index => $place_row )' ) && str_contains( $metabox_source, 'data-wdc-decimal-input="2"' ) && str_contains( $metabox_source, 'places[<?php echo esc_attr( (string) $place_index ); ?>][weight_g]' ), 'Shared modal must render initial places from draft and allow decimal dimensions while keeping weight integer.' );
yd_framework_assert( str_contains( $preview_controller_source, 'shipment_preview_validation_failed' ) && str_contains( $preview_controller_source, 'shipment_preview_unexpected_error' ) && str_contains( $preview_controller_source, 'discard_preview_buffer' ), 'Shipment preview AJAX must return controlled JSON errors instead of leaking HTML output.' );
yd_framework_assert( str_contains( $metabox_source, 'data-wdc-requires-successful-preview' ) && str_contains( $create_controller_source, 'shipment_create_validation_failed' ) && str_contains( $create_controller_source, 'shipment_create_unexpected_error' ) && str_contains( $create_controller_source, 'public_shipment_error_message' ), 'Shipment create AJAX must be JSON-safe and expose the preview-required capability to runtime.' );
yd_framework_assert( str_contains( $address_controller_source, 'AddressSuggestionService' ) && str_contains( $address_controller_source, 'normalize_yandex_courier_address' ) && str_contains( $address_controller_source, "'dadata+yandex'" ) && str_contains( $yandex_modal_extension_source, 'Проверить адрес' ) && str_contains( $yandex_modal_extension_source, 'data-wdc-yandex-address-field="street"' ) && str_contains( $yandex_modal_extension_source, 'Проверьте адрес доставки через DaData' ), 'Yandex courier modal extension must use shared DaData suggestions through the existing shipment normalize endpoint and block preview until explicit verification.' );
yd_framework_assert( str_contains( $metabox_source, 'editable_place_rows' ) && str_contains( $metabox_source, "\$row['weight_g'] = ''" ) && ! str_contains( $metabox_source, $removed_manual_place_capability ), 'Shared modal must clear editable place dimensions for every carrier without carrier-specific capability.' );
yd_framework_assert( str_contains( $manual_controller_source, 'shipment_attach_validation_failed' ) && str_contains( $manual_controller_source, 'shipment_attach_unexpected_error' ) && str_contains( $manual_controller_source, "'request_id' => \$barcode" ), 'Generic manual attach AJAX must stay JSON-safe and pass request_id alias to carrier adapters.' );
yd_framework_assert( str_contains( $metabox_source, 'wdc_mark_shipment_poll_exhausted' ) && str_contains( $status_controller_source, 'handle_mark_poll_exhausted' ) && str_contains( $status_controller_source, 'mark_polling_exhausted' ), 'Shared metabox must expose a carrier-neutral AJAX hook to persist exhausted registration polling.' );
yd_framework_assert( str_contains( $metabox_source, 'tracking_presentation' ) && str_contains( $metabox_source, 'render_tracking_value' ) && str_contains( $metabox_source, 'target="_blank"' ) && str_contains( $metabox_source, 'rel="noopener noreferrer"' ) && str_contains( $metabox_source, 'esc_url( $tracking' ), 'Shared metabox must render structured tracking URLs as escaped links that open in a new tab.' );
yd_framework_assert( str_contains( $metabox_source, 'data-wdc-yandex-self-pickup-code-row' ) && str_contains( $metabox_source, 'ShipmentDocumentDownloadService' ) && ! str_contains( $metabox_source, 'admin_post_yandex_label_pdf' ) && ! str_contains( $metabox_source, 'ACTION_YANDEX_LABEL_PDF' ), 'Shared metabox must render Yandex pickup-code row and use the common protected shipment document endpoint.' );
yd_framework_assert( str_contains( $metabox_source, "__( '⚖️%d'" ) && ! str_contains( $metabox_source, 'Расчётный вес товаров: %d г' ), 'Shared modal must preserve compact calculated weight hint format.' );
$js_source = wdc_shipment_admin_js_bundle_source();
yd_framework_assert( str_contains( $js_source, 'can_create' ) && str_contains( $js_source, 'can_attach_manual' ) && str_contains( $js_source, 'setVisible(openButton, canCreate)' ) && str_contains( $js_source, 'setVisible(manualButton, canAttachManual)' ), 'Runtime shipment buttons must consume adapter create/manual capabilities.' );
yd_framework_assert( str_contains( $js_source, 'function parseShipmentJsonResponse' ) && str_contains( $js_source, 'Сервер вернул некорректный ответ при подготовке отправления' ) && str_contains( $js_source, '.then(parseShipmentJsonResponse)' ), 'Shipment preview JS must parse malformed responses through controlled JSON fallback.' );
yd_framework_assert( str_contains( $js_source, "form.dataset.wdcRequiresTariff !== '0'" ) && str_contains( $js_source, 'parseDecimalValue(length' ), 'Shipment admin JS must support no-tariff carriers and decimal place dimensions.' );
yd_framework_assert( str_contains( $js_source, "form.dataset.wdcRequiresSuccessfulPreview === '1'" ) && str_contains( $js_source, 'const latestPreviewReady = !requiresSuccessfulPreview' ), 'Shipment admin JS must block create by carrier-neutral preview capability instead of checking a Yandex/DPD branch.' );
yd_framework_assert( str_contains( $js_source, "data.append('barcode', input ? input.value || '' : '')" ) && str_contains( $js_source, 'canAttachManual: Object.prototype.hasOwnProperty.call(statusPayload' ) && str_contains( $js_source, 'manualAttachFieldLabel' ), 'Runtime manual attach must send the generic barcode field and consume adapter manual-attach capability after success.' );
yd_framework_assert( str_contains( $js_source, 'function trackingPresentation' ) && str_contains( $js_source, 'document.createElement' ) && str_contains( $js_source, "link.target = '_blank'" ) && str_contains( $js_source, "link.rel = 'noopener noreferrer'" ) && str_contains( $js_source, 'copy.dataset.trackingNumber = copyValue' ), 'Runtime shipment status renderer must support link display with a separate clipboard value through the shared copy button.' );
$legacy_document_payload_key = 'label_' . 'actions';
yd_framework_assert( str_contains( $js_source, 'function renderYandexSelfPickupCode' ) && str_contains( $js_source, 'data-wdc-yandex-self-pickup-code' ) && str_contains( $js_source, 'documentActions' ) && str_contains( $js_source, 'document_actions' ) && ! str_contains( $js_source, $legacy_document_payload_key ) && str_contains( $js_source, 'data-wdc-shipment-document-download' ) && str_contains( $js_source, 'function requestYandexLabelDownload' ), 'Runtime shipment renderer must update Yandex pickup code and drive document visibility through normalized document actions and the canonical document_actions payload key.' );
yd_framework_assert( str_contains( $js_source, 'function startShipmentRegistrationPolling' ) && str_contains( $js_source, 'function markShipmentPollingExhausted' ) && str_contains( $js_source, 'markPollExhaustedAction' ) && str_contains( $js_source, 'shipmentPollingTokens' ) && str_contains( $js_source, 'removeConfirmationMessage' ), 'Runtime registration polling must be carrier-neutral, bounded, persist exhaustion and protect stale responses.' );
yd_framework_assert( str_contains( $js_source, 'settings.auto && !isPending' ) && str_contains( $js_source, 'if (settings.pollingToken)' ) && str_contains( $js_source, 'throw error;' ) && str_contains( $js_source, 'stopShipmentRegistrationPolling(box)' ), 'Runtime polling must suppress pending toast spam, propagate transport errors during bounded polling and stop polling before local remove.' );
yd_framework_assert( str_contains( $js_source, 'const cancellationPollingToasts = new WeakMap()' ) && str_contains( $js_source, 'function isYandexPollingContext' ) && str_contains( $js_source, 'button.dataset.shipmentKey' ) && str_contains( $js_source, "trim() === 'yandex_delivery'" ) && str_contains( $js_source, 'function initCancellationPollingToast' ) && str_contains( $js_source, 'cancellationPollingProgressMessage(0, maxAttempts)' ) && str_contains( $js_source, 'function isCancellationPollingPending' ) && str_contains( $js_source, 'function isCancellationConfirmed' ) && str_contains( $js_source, 'function finishCancellationPollingToast' ) && str_contains( $js_source, 'payloadData.cancelled_and_removed === true' ) && str_contains( $js_source, 'rawStatus === \'CANCELLED\'' ) && str_contains( $js_source, 'lifecycle.poll_required === true' ) && str_contains( $js_source, 'statusPayload.cancellation_pending || statusPayload.polling_continue' ) && str_contains( $js_source, 'Проведено: 0/' ) && str_contains( $js_source, "Проведено: ' + current + '/'" ) && str_contains( $js_source, 'Отмена не выполнена. Получен статус Яндекс:' ) && str_contains( $js_source, 'Отправление Яндекс отменено.' ) && str_contains( $js_source, 'cancellationPollingExhaustedMessage(context.attempt' ) && str_contains( $js_source, 'clearCancellationPollingToast(box)' ), 'Runtime Yandex cancellation polling must distinguish pending lifecycle/status flags from terminal failure and preserve start/progress/failure/success toast states.' );
yd_framework_assert( str_contains( $js_source, "const rawStatus = String(statusPayload.yandex_status || '').trim().toUpperCase();" ) && ! str_contains( $js_source, 'statusPayload.yandex_status || statusPayload.carrier_status_title' ), 'Yandex cancellation terminal failure must use yandex_status only, not the intermediate carrier_status_title display text.' );
yd_framework_assert( preg_match( "/if \\(rawStatus && rawStatus !== 'CANCELLED'\\)[\\s\\S]+?cancellationPollingProgressMessage\\(context\\.attempt, context\\.maxAttempts\\)[\\s\\S]+?'warning',[\\s\\S]+?true/s", $js_source ) === 1, 'Yandex cancellation polling with pending=false and empty yandex_status must keep a warning/progress toast instead of showing success.' );
yd_framework_assert( preg_match( "/function finishCancellationPollingToast[\\s\\S]+?toast\\.textContent = message;[\\s\\S]+?toast\\.hidden = false;[\\s\\S]+?window\\.setTimeout\\(function \\(\\) \\{[\\s\\S]+?toast\\.hidden = true;[\\s\\S]+?cancellationPollingToasts\\.delete\\(box\\);/s", $js_source ) === 1 && substr_count( $js_source, "finishCancellationPollingToast(context.box, 'Отправление Яндекс отменено.', 'success')" ) >= 2, 'Yandex cancellation success must replace the existing progress toast and auto-hide/delete it after completion.' );
yd_framework_assert( preg_match( "/cancelledAndRemoved:\\s*function \\(context\\) \\{\\s*if \\(!isYandexPollingContext\\(context\\) && !hasCancellationPollingToast\\(context && context\\.box\\)\\) return false;[\\s\\S]+?finishCancellationPollingToast\\(context\\.box, 'Отправление Яндекс отменено\\.', 'success'\\);/s", $js_source ) === 1, 'Yandex cancelled_and_removed must finish an existing progress toast even when resetShipmentUi has already cleared status identity.' );
yd_framework_assert( str_contains( $js_source, 'handlePollingStatus' ) && str_contains( $js_source, 'context.settings.auto && !context.pending' ) && str_contains( $js_source, 'Статус отправления Яндекс получен:' ), 'Yandex extension must own auto status toast presentation to avoid duplicate cancel-pending toasts in generic polling.' );
yd_framework_assert( str_contains( $js_source, 'function syncYandexAddressFields' ) && str_contains( $js_source, 'data-wdc-yandex-address-field="' ) && str_contains( $js_source, 'Адрес изменен, нужно обработать адрес заново.' ), 'Runtime Yandex courier address check must fill structured fields from normalized DaData response and clear verification when the full address changes.' );
yd_framework_assert( ! str_contains( $js_source, 'response.json()' ) && ! str_contains( $js_source, 'Unexpected token' ) && ! str_contains( $js_source, 'Server returned' ) && ! str_contains( $js_source, 'DPD registration failed' ), 'Shipment admin runtime must not expose raw JSON parser or English fallback messages.' );

list( , $missing_rows_adapter, , $missing_rows_registration, $missing_rows_http ) = yd_framework_stack( array(
	yd_framework_response( array( 'offers' => array( yd_framework_offer( 'UNUSED-OFFER' ) ) ) ),
) );
$missing_rows_request = yd_framework_request_without_item_rows();
$missing_rows_preview = $missing_rows_adapter->build_safe_payload_preview( $missing_rows_request );
yd_framework_assert( array() === ( $missing_rows_preview['body'] ?? array() ) && str_contains( implode( "\n", $missing_rows_preview['errors'] ?? array() ), 'Shipment allocation rows must not be empty' ) && 0 === count( $missing_rows_http->requests ), 'Yandex preview without shipment_item_rows must fail validation and must not call HTTP.' );
$missing_rows_create = $missing_rows_registration->create( $missing_rows_request );
yd_framework_assert( ! $missing_rows_create->success && 'yandex_shipment_registration_failed' === $missing_rows_create->error_code && str_contains( $missing_rows_create->error_message, 'Shipment allocation rows must not be empty' ) && 0 === count( $missing_rows_http->requests ), 'Yandex create without shipment_item_rows must fail before offers/create, confirm or request/info.' );
yd_framework_assert( 1 === count( $missing_rows_request->places ) && 1 === count( $missing_rows_request->places[0]->items ), 'Missing rows regression fixture must contain PackageItem inside ShipmentPlace.' );

$metabox_reflection = new ReflectionClass( \WallsShop\WDC\Shipments\Admin\OrderShipmentsMetabox::class );
$metabox_without_constructor = $metabox_reflection->newInstanceWithoutConstructor();
$editable_method = $metabox_reflection->getMethod( 'editable_place_rows' );
$editable_method->setAccessible( true );
$editable_rows = $editable_method->invoke(
	$metabox_without_constructor,
	array(
		array( 'place_number' => 1, 'weight_g' => 1000, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10, 'items' => array( 'a' ) ),
		array( 'place_number' => 2, 'weight_g' => 800, 'length_cm' => 30, 'width_cm' => 25, 'height_cm' => 12, 'items' => array( 'b' ) ),
	)
);
yd_framework_assert( 2 === count( $editable_rows ) && 1 === (int) $editable_rows[0]['place_number'] && 2 === (int) $editable_rows[1]['place_number'], 'Generic modal editable place helper must keep all draft places and place numbers.' );
yd_framework_assert( '' === $editable_rows[0]['weight_g'] && '' === $editable_rows[0]['length_cm'] && '' === $editable_rows[1]['weight_g'] && '' === $editable_rows[1]['height_cm'] && array( 'a' ) === $editable_rows[0]['items'], 'Generic modal editable place helper must clear only factual weight/dimensions and preserve allocation data.' );
$address_controller_reflection = new ReflectionClass( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAddressAjaxController::class );
$address_controller_without_constructor = $address_controller_reflection->newInstanceWithoutConstructor();
$locality_method = $address_controller_reflection->getMethod( 'yandex_locality_from_normalized_item' );
$locality_method->setAccessible( true );
yd_framework_assert( 'Москва' === $locality_method->invoke( $address_controller_without_constructor, array( 'data' => array( 'region_with_type' => 'г Москва', 'street_with_type' => 'Ходынский бульвар', 'house' => '9' ) ) ), 'Yandex DaData locality must resolve federal city Moscow when city fields are absent.' );
yd_framework_assert( 'Новосибирск' === $locality_method->invoke( $address_controller_without_constructor, array( 'data' => array( 'city_with_type' => 'г Новосибирск', 'region_with_type' => 'Новосибирская обл', 'street_with_type' => 'Красный проспект', 'house' => '10' ) ) ), 'Yandex DaData locality must resolve a normal city from city_with_type.' );
yd_framework_assert( 'Кольцово' === $locality_method->invoke( $address_controller_without_constructor, array( 'data' => array( 'settlement_with_type' => 'рп Кольцово', 'city' => '', 'region_with_type' => 'Новосибирская обл', 'street_with_type' => 'Технопарковая ул', 'house' => '1' ) ) ), 'Yandex DaData locality must resolve settlements from settlement fields.' );
yd_framework_assert( 'Химки' === $locality_method->invoke( $address_controller_without_constructor, array( 'locality' => 'Химки', 'data' => array( 'city' => '', 'region_with_type' => 'Московская обл', 'street_with_type' => 'Ленинградская ул', 'house' => '1' ) ) ), 'Yandex DaData locality must use canonical normalized locality even when raw data city is absent.' );
yd_framework_assert( '' === $locality_method->invoke( $address_controller_without_constructor, array( 'data' => array( 'city' => '', 'settlement' => '', 'region_with_type' => 'Московская обл', 'street_with_type' => 'Ленина ул', 'house' => '1' ) ) ), 'Yandex DaData locality must not substitute ordinary region as locality.' );

$metabox_buttons = new ShipmentMetaboxButtonPolicy();
$empty_yandex_payload = $adapter->status_payload( $order, array() );
$empty_yandex_buttons = $metabox_buttons->resolve( YandexDeliverySettings::CARRIER_KEY, array(), $empty_yandex_payload );
yd_framework_assert( ! empty( $empty_yandex_buttons['show_create'] ) && ! empty( $empty_yandex_buttons['show_manual_attach'] ) && empty( $empty_yandex_buttons['show_update'] ) && empty( $empty_yandex_buttons['show_cancel'] ) && empty( $empty_yandex_buttons['show_remove'] ), 'Metabox capabilities must show Create and manual attach for empty Yandex shipment.' );

$created_yandex = array( 'status' => 'created', 'yandex_status' => 'CREATED', 'yandex_request_id' => 'REQ-META-CREATED' );
$created_yandex_buttons = $metabox_buttons->resolve( YandexDeliverySettings::CARRIER_KEY, $created_yandex, $adapter->status_payload( $order, $created_yandex ) );
yd_framework_assert( empty( $created_yandex_buttons['show_create'] ) && ! empty( $created_yandex_buttons['show_update'] ) && ! empty( $created_yandex_buttons['show_cancel'] ) && empty( $created_yandex_buttons['show_remove'] ), 'Metabox capabilities must show update/cancel for active CREATED Yandex shipment.' );

$reconciliation_yandex = array( 'status' => 'reconciliation_required', 'yandex_request_id' => 'REQ-META-PENDING' );
$reconciliation_yandex_buttons = $metabox_buttons->resolve( YandexDeliverySettings::CARRIER_KEY, $reconciliation_yandex, $adapter->status_payload( $order, $reconciliation_yandex ) );
yd_framework_assert( ! empty( $reconciliation_yandex_buttons['has_shipment'] ) && empty( $reconciliation_yandex_buttons['show_create'] ) && ! empty( $reconciliation_yandex_buttons['show_update'] ) && empty( $reconciliation_yandex_buttons['show_cancel'] ) && ! empty( $reconciliation_yandex_buttons['show_remove'] ), 'Metabox capabilities must allow local remove for Yandex reconciliation_required immediately after reload.' );
$reconciliation_exhausted_yandex = array( 'status' => 'reconciliation_required', 'yandex_request_id' => 'REQ-META-EXHAUSTED', 'yandex_reconciliation_poll_exhausted' => true );
$reconciliation_exhausted_buttons = $metabox_buttons->resolve( YandexDeliverySettings::CARRIER_KEY, $reconciliation_exhausted_yandex, $adapter->status_payload( $order, $reconciliation_exhausted_yandex ) );
yd_framework_assert( ! empty( $reconciliation_exhausted_buttons['show_update'] ) && ! empty( $reconciliation_exhausted_buttons['show_remove'] ) && empty( $reconciliation_exhausted_buttons['show_create'] ) && empty( $reconciliation_exhausted_buttons['show_cancel'] ), 'Yandex exhausted reconciliation must allow status update and local remove without create/cancel.' );

$cancel_started_yandex = array( 'status' => 'cancellation_started', 'yandex_request_id' => 'REQ-META-CANCEL' );
$cancel_started_yandex_buttons = $metabox_buttons->resolve( YandexDeliverySettings::CARRIER_KEY, $cancel_started_yandex, $adapter->status_payload( $order, $cancel_started_yandex ) );
yd_framework_assert( ! empty( $cancel_started_yandex_buttons['has_shipment'] ) && empty( $cancel_started_yandex_buttons['show_create'] ) && ! empty( $cancel_started_yandex_buttons['show_update'] ) && empty( $cancel_started_yandex_buttons['show_cancel'] ) && empty( $cancel_started_yandex_buttons['show_remove'] ), 'Metabox capabilities must hide local remove for active Yandex cancellation_started before exhaustion.' );
$cancel_started_exhausted_yandex = array( 'status' => 'cancellation_started', 'yandex_request_id' => 'REQ-META-CANCEL', 'yandex_cancel_poll_exhausted' => true );
$cancel_started_exhausted_buttons = $metabox_buttons->resolve( YandexDeliverySettings::CARRIER_KEY, $cancel_started_exhausted_yandex, $adapter->status_payload( $order, $cancel_started_exhausted_yandex ) );
yd_framework_assert( ! empty( $cancel_started_exhausted_buttons['show_update'] ) && ! empty( $cancel_started_exhausted_buttons['show_remove'] ) && empty( $cancel_started_exhausted_buttons['show_cancel'] ) && empty( $cancel_started_exhausted_buttons['show_create'] ), 'Metabox capabilities must allow local remove for Yandex cancellation_started only after poll exhaustion.' );

$cancelled_yandex = array( 'status' => 'created', 'yandex_status' => 'CANCELLED', 'yandex_request_id' => 'REQ-META-CANCELLED' );
$cancelled_yandex_buttons = $metabox_buttons->resolve( YandexDeliverySettings::CARRIER_KEY, $cancelled_yandex, $adapter->status_payload( $order, $cancelled_yandex ) );
yd_framework_assert( empty( $cancelled_yandex_buttons['show_create'] ) && ! empty( $cancelled_yandex_buttons['show_update'] ) && empty( $cancelled_yandex_buttons['show_cancel'] ) && ! empty( $cancelled_yandex_buttons['show_remove'] ), 'Metabox capabilities must hide cancel and show remove for terminal Yandex CANCELLED shipment.' );

$legacy_cdek_buttons = $metabox_buttons->resolve( CdekSettings::CARRIER_KEY, array( 'status' => 'registration_pending' ), array() );
$legacy_dpd_buttons = $metabox_buttons->resolve( DpdSettings::CARRIER_KEY, array( 'status' => 'created', 'barcode' => 'DPD-TRACK' ), array() );
$legacy_russian_post_buttons = $metabox_buttons->resolve( RussianPostDomesticSettings::CARRIER_KEY, array( 'barcode' => 'RP-TRACK' ), array(), false );
yd_framework_assert( ! empty( $legacy_cdek_buttons['has_shipment'] ) && ! empty( $legacy_cdek_buttons['show_update'] ) && empty( $legacy_cdek_buttons['show_create'] ), 'Legacy CDEK metabox fallback must remain available when capability keys are absent.' );
yd_framework_assert( ! empty( $legacy_dpd_buttons['has_shipment'] ) && ! empty( $legacy_dpd_buttons['show_update'] ) && empty( $legacy_dpd_buttons['show_create'] ), 'Legacy DPD metabox fallback must remain available when capability keys are absent.' );
yd_framework_assert( ! empty( $legacy_russian_post_buttons['has_shipment'] ) && ! empty( $legacy_russian_post_buttons['show_remove'] ) && empty( $legacy_russian_post_buttons['show_create'] ), 'Legacy Russian Post metabox fallback must remain available when capability keys are absent.' );

$reconciliation_order = new YdFrameworkOrder( 777 );
list( $reconciliation_repository, $reconciliation_adapter, $reconciliation_creation, $reconciliation_registration, $reconciliation_http ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-PENDING' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-PENDING' ) ),
		yd_framework_response( yd_framework_incomplete_info( 'REQ-PENDING' ) ),
		yd_framework_response( yd_framework_incomplete_info( 'REQ-PENDING' ) ),
		yd_framework_response( yd_framework_info( 'REQ-PENDING', 'CREATED', 'YD-PENDING-REAL' ) ),
	)
);
$pending_result = $reconciliation_creation->create( $reconciliation_order, yd_framework_request() );
yd_framework_assert( $pending_result->success && 'REQ-PENDING' === $pending_result->external_id && ! empty( $pending_result->raw_reference['yandex_accepted_reconciliation']['accepted'] ), 'Incomplete request/info after successful confirm must return accepted ShipmentCreateResult for admin feedback.' );
$pending = $reconciliation_repository->find_by_carrier( $reconciliation_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'REQ-PENDING' === (string) ( $pending['yandex_request_id'] ?? '' ) && 'reconciliation_required' === (string) ( $pending['status'] ?? '' ) && '' === (string) ( $pending['yandex_status'] ?? '' ) && ! empty( $pending['yandex_reconciliation_required'] ), 'Confirmed request_id must be persisted as reconciliation_required shipment without a fake API status.' );
yd_framework_assert( 'REQ-PENDING' === (string) $reconciliation_order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Reconciliation pending shipment must persist lookup meta.' );
yd_framework_assert( 'OFFER-PENDING' === (string) ( $pending['yandex_selected_offer_id'] ?? '' ) && '2026-07-11T16:23:01.000000Z' === (string) ( $pending['yandex_offer_expires_at'] ?? '' ) && '298.8 RUB' === (string) ( $pending['yandex_offer_pricing_total'] ?? '' ), 'Reconciliation pending shipment must keep selected offer fields.' );
yd_framework_assert( '2026-07-21T20:00:00.000000Z' === (string) ( $pending['yandex_offer_delivery_interval']['max'] ?? '' ) && '2026-07-11T16:08:01.000000Z' === (string) ( $pending['yandex_offer_pickup_interval']['min'] ?? '' ), 'Reconciliation pending shipment must keep selected offer interval fields.' );
yd_framework_assert( array() !== array_filter( $reconciliation_order->notes, static fn ( string $note ): bool => str_contains( $note, 'Отправление Яндекс создано. Номер заказа в Яндекс: ORDER-777. Request ID: REQ-PENDING. Ожидается получение статуса.' ) ), 'Accepted pending create must write a non-failure order note.' );
$pending_policy = ( new YandexShipmentButtonPolicy() )->resolve( $pending );
yd_framework_assert( empty( $pending_policy['create'] ) && ! empty( $pending_policy['update'] ) && empty( $pending_policy['cancel'] ) && ! empty( $pending_policy['remove'] ) && empty( $pending_policy['manual_attach'] ), 'Reconciliation button policy must allow status update and local remove while blocking create/cancel/manual attach.' );
$pending_payload = $reconciliation_adapter->status_payload( $reconciliation_order, $pending );
yd_framework_assert( ! empty( $pending_payload['polling_continue'] ) && ! empty( $pending_payload['can_remove_from_order'] ) && 5000 === (int) ( $pending_payload['registration_poll_interval_ms'] ?? 0 ) && 14 === (int) ( $pending_payload['registration_poll_max_attempts'] ?? 0 ) && 'Ожидается получение статуса' === (string) ( $pending_payload['carrier_status_title'] ?? '' ), 'Pending Yandex status payload must request bounded 5s x 14 polling, allow local remove and render pending without duplicate label.' );
$pending_tracking = (array) ( $pending_payload['tracking_presentation'] ?? array() );
yd_framework_assert( 'Request ID Яндекс' === (string) ( $pending_tracking['label'] ?? '' ) && 'REQ-PENDING' === (string) ( $pending_tracking['display_text'] ?? '' ) && '' === (string) ( $pending_tracking['url'] ?? '' ) && 'REQ-PENDING' === (string) ( $pending_tracking['copy_value'] ?? '' ), 'Pending Yandex reconciliation without sharing_url must keep request_id fallback tracking presentation.' );
$blocked_pending_duplicate = $reconciliation_creation->create( $reconciliation_order, yd_framework_request() );
yd_framework_assert( ! $blocked_pending_duplicate->success && 'shipment_already_created' === $blocked_pending_duplicate->error_code && 3 === count( $reconciliation_http->requests ), 'Pending reconciliation must block repeat create without repeating confirm.' );
$still_pending = $reconciliation_adapter->update_status( $reconciliation_order );
yd_framework_assert( ! empty( $still_pending['success'] ) && ! empty( $still_pending['pending'] ) && 'Яндекс ещё не подготовил статус созданного отправления.' === (string) ( $still_pending['message'] ?? '' ) && 4 === count( $reconciliation_http->requests ), 'Pending request/info without state.status must stay a controlled retryable success.' );
$exhausted = $reconciliation_adapter->mark_polling_exhausted( $reconciliation_order, 14 );
$exhausted_shipment = $reconciliation_repository->find_by_carrier( $reconciliation_order, YandexDeliverySettings::CARRIER_KEY );
$exhausted_payload = $reconciliation_adapter->status_payload( $reconciliation_order, $exhausted_shipment );
yd_framework_assert( ! empty( $exhausted['success'] ) && ! empty( $exhausted_shipment['yandex_reconciliation_poll_exhausted'] ) && 14 === (int) ( $exhausted_shipment['yandex_reconciliation_attempts'] ?? 0 ) && '' !== (string) ( $exhausted_shipment['yandex_reconciliation_poll_exhausted_at'] ?? '' ), 'Yandex polling exhaustion must persist exhausted flag, attempts and timestamp.' );
yd_framework_assert( 'Статус пока не получен. Повторите обновление позднее.' === (string) ( $exhausted_shipment['status_title'] ?? '' ) && 'REQ-PENDING' === (string) ( $exhausted_shipment['yandex_request_id'] ?? '' ) && 'OFFER-PENDING' === (string) ( $exhausted_shipment['yandex_selected_offer_id'] ?? '' ), 'Yandex exhaustion must keep request_id and selected offer audit while updating status title.' );
yd_framework_assert( ! empty( $exhausted_payload['can_update_status'] ) && ! empty( $exhausted_payload['can_remove_from_order'] ) && empty( $exhausted_payload['can_create'] ) && empty( $exhausted_payload['can_cancel'] ) && empty( $exhausted_payload['polling_continue'] ), 'Yandex exhausted payload must expose update/remove and stop polling without create/cancel.' );
$recovered = $reconciliation_adapter->update_status( $reconciliation_order );
yd_framework_assert( ! empty( $recovered['success'] ) && 5 === count( $reconciliation_http->requests ), 'Reconciliation update_status must call only request/info.' );
$recovered_shipment = $reconciliation_repository->find_by_carrier( $reconciliation_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'created' === (string) ( $recovered_shipment['status'] ?? '' ) && 'CREATED' === (string) ( $recovered_shipment['yandex_status'] ?? '' ) && empty( $recovered_shipment['yandex_reconciliation_required'] ) && empty( $recovered_shipment['yandex_reconciliation_poll_exhausted'] ) && 0 === (int) ( $recovered_shipment['yandex_reconciliation_attempts'] ?? 0 ) && '' === (string) ( $recovered_shipment['yandex_reconciliation_poll_exhausted_at'] ?? '' ), 'Successful request/info must convert reconciliation shipment to canonical created state and clear exhausted state.' );
yd_framework_assert( 'YD-PENDING-REAL' === (string) ( $recovered_shipment['yandex_place_barcode_map']['ORDER-777-1'] ?? '' ) && 'REQ-PENDING' === (string) $reconciliation_order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Reconciliation recovery must persist barcode map and keep lookup meta.' );
$recovered_payload = $reconciliation_adapter->status_payload( $reconciliation_order, $recovered_shipment );
yd_framework_assert( 'CREATED' === (string) ( $recovered_payload['carrier_status_title'] ?? '' ) && empty( $recovered_payload['polling_continue'] ) && ! empty( $recovered_payload['can_cancel'] ) && empty( $recovered_payload['can_remove_from_order'] ), 'Recovered Yandex shipment must stop polling, render CREATED once, allow cancel and hide remove.' );
$recovered_tracking = (array) ( $recovered_payload['tracking_presentation'] ?? array() );
yd_framework_assert( 'Отслеживание посылки' === (string) ( $recovered_tracking['label'] ?? '' ) && 'ссылка' === (string) ( $recovered_tracking['display_text'] ?? '' ) && 'https://dostavka.yandex.ru/route/example' === (string) ( $recovered_tracking['url'] ?? '' ) && 'https://dostavka.yandex.ru/route/example' === (string) ( $recovered_tracking['copy_value'] ?? '' ), 'Reconciliation recovery must replace request_id fallback with sharing_url tracking link.' );
yd_framework_assert( 'OFFER-PENDING' === (string) ( $recovered_shipment['yandex_selected_offer_id'] ?? '' ) && '298.8 RUB' === (string) ( $recovered_shipment['yandex_offer_pricing_total'] ?? '' ), 'Reconciliation recovery must preserve selected offer audit fields.' );

$remove_pending_order = new YdFrameworkOrder( 777 );
list( $remove_pending_repository, $remove_pending_adapter, $remove_pending_creation, $remove_pending_registration, $remove_pending_http ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-REMOVE-PENDING' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-REMOVE-PENDING' ) ),
		yd_framework_response( yd_framework_incomplete_info( 'REQ-REMOVE-PENDING' ) ),
	)
);
yd_framework_assert( $remove_pending_creation->create( $remove_pending_order, yd_framework_request() )->success, 'Pending remove scenario must start from accepted reconciliation.' );
$pending_remove = $remove_pending_adapter->remove_from_order( $remove_pending_order );
yd_framework_assert( ! empty( $pending_remove['success'] ) && array() === $remove_pending_repository->find_by_carrier( $remove_pending_order, YandexDeliverySettings::CARRIER_KEY ) && '' === (string) $remove_pending_order->get_meta( '_wdc_yandex_delivery_request_id', true ) && 3 === count( $remove_pending_http->requests ), 'Server-side Yandex remove guard must allow reconciliation_required local remove without extra API calls.' );

$exhausted_pending_order = new YdFrameworkOrder( 777 );
list( $exhausted_pending_repository, $exhausted_pending_adapter, $exhausted_pending_creation, $exhausted_pending_registration, $exhausted_pending_http ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-EXHAUSTED-PENDING' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-EXHAUSTED-PENDING' ) ),
		yd_framework_response( yd_framework_incomplete_info( 'REQ-EXHAUSTED-PENDING' ) ),
		yd_framework_response( yd_framework_incomplete_info( 'REQ-EXHAUSTED-PENDING' ) ),
	)
);
yd_framework_assert( $exhausted_pending_creation->create( $exhausted_pending_order, yd_framework_request() )->success, 'Exhausted pending scenario must start from accepted create.' );
$exhausted_pending_adapter->mark_polling_exhausted( $exhausted_pending_order, 14 );
$exhausted_pending_update = $exhausted_pending_adapter->update_status( $exhausted_pending_order );
$exhausted_pending_shipment = $exhausted_pending_repository->find_by_carrier( $exhausted_pending_order, YandexDeliverySettings::CARRIER_KEY );
$exhausted_pending_payload = $exhausted_pending_adapter->status_payload( $exhausted_pending_order, $exhausted_pending_shipment );
yd_framework_assert( ! empty( $exhausted_pending_update['success'] ) && ! empty( $exhausted_pending_update['pending'] ) && ! empty( $exhausted_pending_shipment['yandex_reconciliation_poll_exhausted'] ) && 14 === (int) ( $exhausted_pending_shipment['yandex_reconciliation_attempts'] ?? 0 ), 'Pending update after exhaustion must not clear exhausted state.' );
yd_framework_assert( ! empty( $exhausted_pending_payload['can_remove_from_order'] ) && ! empty( $exhausted_pending_payload['can_update_status'] ) && 'Статус пока не получен. Повторите обновление позднее.' === (string) ( $exhausted_pending_payload['carrier_status_title'] ?? '' ), 'Pending update after exhaustion must keep local remove and timeout status title.' );

$cancel_pending_order = new YdFrameworkOrder( 777 );
list( $cancel_pending_repository, $cancel_pending_adapter, $cancel_pending_creation, $cancel_pending_registration, $cancel_pending_http ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-CANCEL-PENDING' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-CANCEL-PENDING' ) ),
		yd_framework_response( yd_framework_info( 'REQ-CANCEL-PENDING', 'CREATED', 'YD-CANCEL-PENDING' ) ),
		yd_framework_response( array( 'status' => 'CREATED', 'description' => 'Заказ отменяется', 'reason' => 'cancellation_started' ) ),
		yd_framework_response( yd_framework_info( 'REQ-CANCEL-PENDING', 'CREATED', 'YD-CANCEL-PENDING' ) ),
		yd_framework_response( yd_framework_info( 'REQ-CANCEL-PENDING', 'CANCELLED', 'YD-CANCEL-PENDING' ) ),
		yd_framework_response( yd_framework_info( 'REQ-CANCEL-PENDING', 'CANCELLED', 'YD-CANCEL-PENDING' ) ),
	)
);
yd_framework_assert( $cancel_pending_creation->create( $cancel_pending_order, yd_framework_request() )->success, 'Cancel pending scenario must start from successful create.' );
$cancel_pending = $cancel_pending_adapter->cancel_in_carrier( $cancel_pending_order );
yd_framework_assert( ! empty( $cancel_pending['success'] ) && ! empty( $cancel_pending['accepted'] ) && ! empty( $cancel_pending['cancellation_started'] ) && ! empty( $cancel_pending['auto_poll'] ) && 5000 === (int) ( $cancel_pending['poll_interval_ms'] ?? 0 ) && 14 === (int) ( $cancel_pending['poll_max_attempts'] ?? 0 ) && 'CREATED' === (string) ( $cancel_pending['status'] ?? '' ), 'Async cancel must start polling when immediate request/info is still non-terminal CREATED.' );
$cancel_pending_shipment = $cancel_pending_repository->find_by_carrier( $cancel_pending_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'cancellation_started' === (string) ( $cancel_pending_shipment['status'] ?? '' ) && ! empty( $cancel_pending_shipment['yandex_cancel_requested'] ) && 'cancellation_started' === (string) ( $cancel_pending_shipment['yandex_cancel_reason'] ?? '' ), 'Non-terminal info after cancel must keep local cancellation_started state.' );
$cancel_pending_policy = ( new YandexShipmentButtonPolicy() )->resolve( $cancel_pending_shipment );
yd_framework_assert( empty( $cancel_pending_policy['cancel'] ) && ! empty( $cancel_pending_policy['update'] ) && empty( $cancel_pending_policy['remove'] ), 'Cancel pending button policy must block repeat cancel and local remove before exhaustion.' );
$cancel_pending_remove = $cancel_pending_adapter->remove_from_order( $cancel_pending_order );
yd_framework_assert( empty( $cancel_pending_remove['success'] ) && array() !== $cancel_pending_repository->find_by_carrier( $cancel_pending_order, YandexDeliverySettings::CARRIER_KEY ), 'Server-side remove must reject cancellation_started before polling exhaustion.' );
yd_framework_assert( ! str_contains( implode( "\n", $cancel_pending_order->notes ), 'отменено' ), 'Cancel pending note must not claim final cancellation.' );
$cancel_completed = $cancel_pending_adapter->update_status( $cancel_pending_order );
yd_framework_assert( ! empty( $cancel_completed['success'] ) && ! empty( $cancel_completed['cancelled_and_removed'] ) && 'CANCELLED' === (string) ( $cancel_completed['status'] ?? '' ), 'Subsequent update_status must complete async cancellation when request/info returns CANCELLED.' );
$cancel_completed_shipment = $cancel_pending_repository->find_by_carrier( $cancel_pending_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( array() === $cancel_completed_shipment && '' === (string) $cancel_pending_order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Confirmed cancellation must automatically remove local Yandex shipment and lookup meta.' );
$notes_before_repeat = count( array_filter( $cancel_pending_order->notes, static fn ( string $note ): bool => str_contains( $note, 'Отправление Яндекс отменено.' ) ) );
$cancel_pending_adapter->update_status( $cancel_pending_order );
$notes_after_repeat = count( array_filter( $cancel_pending_order->notes, static fn ( string $note ): bool => str_contains( $note, 'Отправление Яндекс отменено.' ) ) );
yd_framework_assert( 1 === $notes_before_repeat && 1 === $notes_after_repeat, 'Confirmed cancellation note must be written only once.' );

$cancel_info_fail_order = new YdFrameworkOrder( 777 );
list( $cancel_fail_repository, $cancel_fail_adapter, $cancel_fail_creation, $cancel_fail_registration, $cancel_fail_http ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-CANCEL-FAIL' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-CANCEL-FAIL' ) ),
		yd_framework_response( yd_framework_info( 'REQ-CANCEL-FAIL', 'CREATED', 'YD-CANCEL-FAIL' ) ),
		yd_framework_response( array( 'status' => 'CREATED', 'description' => 'Заказ отменяется', 'reason' => 'cancellation_started' ) ),
		yd_framework_error_response( 503, array( 'message' => 'info unavailable after cancel' ) ),
	)
);
yd_framework_assert( $cancel_fail_creation->create( $cancel_info_fail_order, yd_framework_request() )->success, 'Cancel info-failure scenario must start from successful create.' );
$cancel_fail = $cancel_fail_adapter->cancel_in_carrier( $cancel_info_fail_order );
yd_framework_assert( ! empty( $cancel_fail['success'] ) && ! empty( $cancel_fail['accepted'] ) && 5 === count( $cancel_fail_http->requests ), 'Cancel must stay accepted when request/info after cancel fails and must not repeat request/cancel.' );
$cancel_fail_shipment = $cancel_fail_repository->find_by_carrier( $cancel_info_fail_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'cancellation_started' === (string) ( $cancel_fail_shipment['status'] ?? '' ) && ! empty( $cancel_fail_shipment['yandex_cancel_requested'] ), 'Info failure after cancel must persist cancellation_started state.' );
$cancel_exhausted = $cancel_fail_adapter->mark_polling_exhausted( $cancel_info_fail_order, 14, 'cancellation' );
$cancel_exhausted_shipment = $cancel_fail_repository->find_by_carrier( $cancel_info_fail_order, YandexDeliverySettings::CARRIER_KEY );
$cancel_exhausted_payload = $cancel_fail_adapter->status_payload( $cancel_info_fail_order, $cancel_exhausted_shipment );
yd_framework_assert( ! empty( $cancel_exhausted['success'] ) && ! empty( $cancel_exhausted_shipment['yandex_cancel_poll_exhausted'] ) && 14 === (int) ( $cancel_exhausted_shipment['yandex_cancel_poll_attempts'] ?? 0 ) && 'Статус отмены пока не получен. Повторите обновление позднее.' === (string) ( $cancel_exhausted_shipment['status_title'] ?? '' ), 'Cancel polling exhaustion must persist cancellation-specific exhausted state.' );
yd_framework_assert( ! empty( $cancel_exhausted_payload['can_update_status'] ) && ! empty( $cancel_exhausted_payload['can_remove_from_order'] ) && empty( $cancel_exhausted_payload['can_cancel'] ) && empty( $cancel_exhausted_payload['can_create'] ) && empty( $cancel_exhausted_payload['polling_continue'] ), 'Cancel exhaustion payload must expose update/remove and stop polling without repeating cancel.' );
$cancel_exhausted_remove = $cancel_fail_adapter->remove_from_order( $cancel_info_fail_order );
yd_framework_assert( ! empty( $cancel_exhausted_remove['success'] ) && array() === $cancel_fail_repository->find_by_carrier( $cancel_info_fail_order, YandexDeliverySettings::CARRIER_KEY ), 'Server-side remove must allow cancellation_started after polling exhaustion.' );

$cancel_exhausted_update_order = new YdFrameworkOrder( 778, '778' );
list( $cancel_exhausted_update_repository, $cancel_exhausted_update_adapter, $cancel_exhausted_update_creation, $cancel_exhausted_update_registration, $cancel_exhausted_update_http ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-CANCEL-EXHAUSTED-UPDATE' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-CANCEL-EXHAUSTED-UPDATE' ) ),
		yd_framework_response( yd_framework_info( 'REQ-CANCEL-EXHAUSTED-UPDATE', 'CREATED', 'YD-CANCEL-EXHAUSTED-UPDATE', '778' ) ),
		yd_framework_response( array( 'status' => 'CREATED', 'description' => 'Заказ отменяется', 'reason' => 'cancellation_started' ) ),
		yd_framework_error_response( 503, array( 'message' => 'info unavailable after cancel' ) ),
		yd_framework_response( yd_framework_info( 'REQ-CANCEL-EXHAUSTED-UPDATE', 'CANCELLED', 'YD-CANCEL-EXHAUSTED-UPDATE', '778' ) ),
	)
);
yd_framework_assert( $cancel_exhausted_update_creation->create( $cancel_exhausted_update_order, yd_framework_request( 778, '778' ) )->success, 'Cancel exhausted update scenario must start from successful create.' );
yd_framework_assert( ! empty( $cancel_exhausted_update_adapter->cancel_in_carrier( $cancel_exhausted_update_order )['accepted'] ), 'Cancel exhausted update scenario must persist accepted cancellation.' );
$cancel_exhausted_update_adapter->mark_polling_exhausted( $cancel_exhausted_update_order, 14, 'cancellation' );
$cancel_exhausted_update_result = $cancel_exhausted_update_adapter->update_status( $cancel_exhausted_update_order );
yd_framework_assert( ! empty( $cancel_exhausted_update_result['cancelled_and_removed'] ) && array() === $cancel_exhausted_update_repository->find_by_carrier( $cancel_exhausted_update_order, YandexDeliverySettings::CARRIER_KEY ) && '' === (string) $cancel_exhausted_update_order->get_meta( '_wdc_yandex_delivery_request_id', true ) && 0 === (int) ( $cancel_exhausted_update_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true )['last_index'] ?? -1 ), 'Manual update after cancel exhaustion must auto-remove local shipment on CANCELLED and preserve sequence meta.' );

$draft_factory = new OrderShipmentDraftFactory(
	new DeliveryServiceRepository(),
	new ShipmentServiceSettings( new DeliveryServiceSettingsRepository() ),
	yandex_settings: yd_framework_settings()
);
$draft_order = new YdFrameworkOrder( 781 );
$draft_order->update_meta_data( '_wdc_platform_carrier_key', YandexDeliverySettings::CARRIER_KEY );
$draft_order->update_meta_data( '_wdc_platform_rate_id', YandexDeliverySettings::CARRIER_KEY . ':pickup' );
$draft_order->update_meta_data( '_wdc_yandex_delivery_pickup_platform_station_id', 'PVZ-1' );
$draft_request = $draft_factory->create_request_from_order( $draft_order );
yd_framework_assert( 1 === count( $draft_request->places ) && 1 === (int) ( $draft_request->meta['shipment_item_rows'][0]['place_number'] ?? 0 ), 'Initial Yandex draft must use canonical shipment_item_rows and one place before modal admin data is submitted.' );
$draft_array = $draft_factory->draft_array( $draft_order );
yd_framework_assert( 'Яндекс до ПВЗ' === (string) ( $draft_array['services'][0]['title'] ?? '' ) && DeliveryType::PICKUP === (string) ( $draft_array['services'][0]['delivery_type'] ?? '' ), 'Yandex pickup modal draft must expose one actual service variant.' );
yd_framework_assert( array() === (array) ( $draft_array['postoffice_codes'] ?? array( 'unexpected' ) ) && false === (bool) ( $draft_array['modal_capabilities']['requires_tariff'] ?? true ) && false === (bool) ( $draft_array['modal_capabilities']['requires_postoffice'] ?? true ), 'Yandex modal draft must not require tariff or Russian Post postoffice fields.' );
yd_framework_assert( true === (bool) ( $draft_array['modal_capabilities']['requires_successful_preview'] ?? false ), 'Yandex modal draft must require successful preview before create.' );
yd_framework_assert( ! array_key_exists( $removed_manual_place_capability, $draft_array['modal_capabilities'] ?? array() ) && ! str_contains( $source, $removed_manual_place_capability ), 'Yandex modal draft must not expose carrier-specific manual place dimensions capability.' );
$draft_courier_order = new YdFrameworkOrder( 783 );
$draft_courier_order->update_meta_data( '_wdc_platform_carrier_key', YandexDeliverySettings::CARRIER_KEY );
$draft_courier_order->update_meta_data( '_wdc_platform_rate_id', YandexDeliverySettings::CARRIER_KEY . ':courier' );
$draft_courier_array = $draft_factory->draft_array( $draft_courier_order );
yd_framework_assert( 'Яндекс до двери' === (string) ( $draft_courier_array['services'][0]['title'] ?? '' ) && DeliveryType::COURIER === (string) ( $draft_courier_array['services'][0]['delivery_type'] ?? '' ), 'Yandex courier modal draft must expose one actual courier service variant.' );
$draft_dpd_order = new YdFrameworkOrder( 784 );
$draft_dpd_order->update_meta_data( '_wdc_platform_carrier_key', DpdSettings::CARRIER_KEY );
$draft_dpd_array = $draft_factory->draft_array( $draft_dpd_order );
yd_framework_assert( true === (bool) ( $draft_dpd_array['modal_capabilities']['requires_successful_preview'] ?? false ), 'DPD modal draft must keep successful-preview requirement through the same carrier-neutral capability.' );

$preview_http = new YdFrameworkFakeHttp( array() );
$preview_client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_framework_settings(), $preview_http ) );
$preview_repository = new YandexShipmentRepository( new OrderShipmentRepository() );
$preview_status_mapping = new YandexStatusMapping( new SettingsRepository() );
$preview_mapper = new YandexShipmentPersistenceMapper( $preview_repository, $preview_status_mapping );
$preview_adapter = new YandexShipmentAdapter(
	new YandexShipmentRegistrationService( new CoreYandexRegistrationService( new YandexDeliveryShipmentPayloadBuilder(), $preview_client, new YandexDeliveryEarliestOfferSelector() ), new YandexDeliveryShipmentPayloadBuilder(), $preview_client, $preview_repository, $preview_mapper, new YandexShipmentButtonPolicy( $preview_status_mapping ), $preview_status_mapping ),
	new YandexShipmentButtonPolicy( $preview_status_mapping ),
	$preview_status_mapping
);
$preview_payload = $preview_adapter->build_safe_payload_preview( yd_framework_request() );
yd_framework_assert( array() === $preview_http->requests && empty( $preview_payload['live_api_call'] ), 'Yandex modal payload preview must not call offers/create, confirm or request/info HTTP.' );

$empty_dimensions_blocked = false;
try {
	$draft_factory->create_request_from_admin_data(
		$draft_order,
		array(
			'delivery_type' => DeliveryType::PICKUP,
			'places' => array( array( 'weight_g' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => '' ) ),
			'shipment_items' => array( array( 'item_key' => '101', 'ordered_quantity' => 3, 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'SKU-A', 'amount' => 3, 'cost' => 100, 'weight' => 500 ) ),
			'yandex_pickup_platform_station_id' => 'PVZ-1',
		)
	);
} catch ( InvalidArgumentException $e ) {
	$empty_dimensions_blocked = str_contains( $e->getMessage(), 'weight_g must be greater than 0' )
		&& str_contains( $e->getMessage(), 'length_cm must be greater than 0' )
		&& str_contains( $e->getMessage(), 'width_cm must be greater than 0' )
		&& str_contains( $e->getMessage(), 'height_cm must be greater than 0' );
}
yd_framework_assert( $empty_dimensions_blocked && array() === $preview_http->requests, 'Empty Yandex modal place fields must block preview/create without falling back to calculated dimensions or HTTP.' );

$modal_item_rows = array(
	array( 'item_key' => 'order-item-a', 'ordered_quantity' => 3, 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'SAME-SKU', 'amount' => 2, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'order-item-a', 'ordered_quantity' => 3, 'place_number' => 2, 'name' => 'Item A', 'ware_key' => 'SAME-SKU', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'order-item-b', 'ordered_quantity' => 1, 'place_number' => 2, 'name' => 'Item B', 'ware_key' => 'SAME-SKU', 'amount' => 1, 'cost' => 200, 'weight' => 400 ),
);
$admin_request = $draft_factory->create_request_from_admin_data(
	$draft_order,
	array(
		'delivery_type' => DeliveryType::PICKUP,
		'places' => array(
			array( 'weight_g' => 1000, 'length_cm' => '19.9', 'width_cm' => '19,1', 'height_cm' => 19 ),
			array( 'weight_g' => 800, 'length_cm' => 15, 'width_cm' => 15, 'height_cm' => 10 ),
		),
		'shipment_items' => $modal_item_rows,
		'yandex_pickup_platform_station_id' => 'PVZ-1',
	)
);
yd_framework_assert( 2 === count( $admin_request->places ) && 3 === count( $admin_request->meta['shipment_item_rows'] ?? array() ) && 2 === (int) $admin_request->meta['shipment_item_rows'][1]['place_number'], 'Yandex admin data must preserve multi-place item rows from the shared shipment modal.' );
yd_framework_assert( 1000 === $admin_request->places[0]->weight_g && 800 === $admin_request->places[1]->weight_g, 'Yandex admin data must keep modal place dimensions and weight.' );
yd_framework_assert( 20 === $admin_request->places[0]->length_cm && 20 === $admin_request->places[0]->width_cm && 19 === $admin_request->places[0]->height_cm, 'Shipment modal mapper must round decimal dimensions up without changing integer dimensions.' );
$admin_rows = $admin_request->meta['shipment_item_rows'];
yd_framework_assert( 2 === (int) $admin_rows[0]['amount'] && 1 === (int) $admin_rows[1]['amount'] && 2 === (int) $admin_rows[1]['place_number'], 'Yandex admin data must preserve split quantity across places.' );
yd_framework_assert( 'order-item-a' === (string) $admin_rows[0]['item_key'] && 'order-item-b' === (string) $admin_rows[2]['item_key'] && $admin_rows[0]['sku'] === $admin_rows[2]['sku'], 'Yandex admin data identity must keep same SKU order items distinct by item_key.' );
$fallback_order = new YdFrameworkOrder( 782 );
$fallback_order->update_meta_data( '_wdc_platform_carrier_key', YandexDeliverySettings::CARRIER_KEY );
$fallback_order->update_meta_data( '_wdc_platform_rate_id', YandexDeliverySettings::CARRIER_KEY . ':pickup' );
$fallback_order->update_meta_data( '_wdc_yandex_delivery_pickup_platform_station_id', 'PVZ-1' );
$fallback_request = $draft_factory->create_request_from_order( $fallback_order );
yd_framework_assert( 1 === count( $fallback_request->places ) && 1 === (int) ( $fallback_request->meta['shipment_item_rows'][0]['place_number'] ?? 0 ), 'Yandex draft fallback must use one place only when existing allocation is absent.' );

$invalid_thrown = false;
try {
	$draft_factory->create_request_from_admin_data(
		$draft_order,
		array(
			'delivery_type' => DeliveryType::PICKUP,
			'places' => array( array( 'weight_g' => 1000, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 10 ) ),
			'shipment_items' => array( array( 'item_key' => 'broken-item', 'ordered_quantity' => 1, 'place_number' => 1, 'name' => 'Broken', 'ware_key' => 'BROKEN', 'amount' => 0, 'cost' => 100, 'weight' => 300 ) ),
			'yandex_pickup_platform_station_id' => 'PVZ-1',
		)
	);
} catch ( InvalidArgumentException $e ) {
	$invalid_thrown = str_contains( $e->getMessage(), 'amount must be greater than 0' );
}
yd_framework_assert( $invalid_thrown, 'Broken Yandex admin allocation must be rejected through existing ShipmentAllocation validation without silent repair.' );

$mapper = new ShipmentModalRequestMapper();
$invalid_dimension_thrown = false;
try {
	$mapper->parse(
		array(
			'places' => array( array( 'weight_g' => 1000, 'length_cm' => 'bad', 'width_cm' => 20, 'height_cm' => 10 ) ),
			'shipment_items' => array( array( 'item_key' => 'bad-dim-item', 'ordered_quantity' => 1, 'place_number' => 1, 'name' => 'Bad dimensions', 'ware_key' => 'BAD-DIM', 'amount' => 1, 'cost' => 100, 'weight' => 300 ) ),
		)
	);
} catch ( InvalidArgumentException $e ) {
	$invalid_dimension_thrown = str_contains( $e->getMessage(), 'length_cm must be greater than 0' );
}
yd_framework_assert( $invalid_dimension_thrown, 'Invalid modal dimensions must become validation errors instead of being silently repaired.' );

$invalid_weight_thrown = false;
try {
	$mapper->parse(
		array(
			'places' => array( array( 'weight_g' => '1000.9', 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 10 ) ),
			'shipment_items' => array( array( 'item_key' => 'bad-weight-item', 'ordered_quantity' => 1, 'place_number' => 1, 'name' => 'Bad weight', 'ware_key' => 'BAD-WEIGHT', 'amount' => 1, 'cost' => 100, 'weight' => 300 ) ),
		)
	);
} catch ( InvalidArgumentException $e ) {
	$invalid_weight_thrown = str_contains( $e->getMessage(), 'weight_g must be greater than 0' );
}
yd_framework_assert( $invalid_weight_thrown, 'Modal weight must remain an integer contract and must not be rounded up like dimensions.' );

$request_id_safety_order = new YdFrameworkOrder( 777 );
list( $request_id_repository, $request_id_adapter, $request_id_creation, $request_id_registration, $request_id_http ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-SAFE-ID' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-SAFE' ) ),
		yd_framework_response( yd_framework_info( 'REQ-SAFE', 'CREATED', 'YD-SAFE' ) ),
		yd_framework_response( yd_framework_info( 'REQ-SAFE', 'CREATED', 'YD-SAFE' ) ),
		yd_framework_response( array( 'status' => 'CREATED', 'description' => 'Заказ отменяется', 'reason' => 'cancellation_started' ) ),
		yd_framework_response( yd_framework_info( 'REQ-SAFE', 'CREATED', 'YD-SAFE' ) ),
	)
);
yd_framework_assert( $request_id_creation->create( $request_id_safety_order, yd_framework_request() )->success, 'Request id safety scenario must create shipment.' );
$safe_shipment = $request_id_repository->find_by_carrier( $request_id_safety_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( '880191690' === (string) ( $safe_shipment['tracking_number'] ?? '' ) && 'REQ-SAFE' === (string) ( $safe_shipment['yandex_request_id'] ?? '' ), 'Smoke fixture must distinguish courier_order_id tracking from request_id.' );
$request_id_adapter->update_status( $request_id_safety_order );
$request_id_adapter->cancel_in_carrier( $request_id_safety_order );
yd_framework_assert( str_contains( $request_id_http->requests[3]['url'], 'request_id=REQ-SAFE' ) && ! str_contains( $request_id_http->requests[3]['url'], '880191690' ), 'Status update must use yandex_request_id and not courier_order_id tracking.' );
$safe_cancel_body = json_decode( (string) ( $request_id_http->requests[4]['args']['body'] ?? '{}' ), true );
yd_framework_assert( 'REQ-SAFE' === (string) ( $safe_cancel_body['request_id'] ?? '' ), 'Cancel must use yandex_request_id and not courier_order_id tracking.' );

foreach ( array(
	'DELIVERY_DELIVERED' => DeliveryStatus::DELIVERED,
	'RETURN_RETURNED' => DeliveryStatus::RETURNED_TO_SENDER,
	'SORTING_CENTER_RETURN_RETURNED' => DeliveryStatus::RETURNED_TO_SENDER,
	'VALIDATING_ERROR' => DeliveryStatus::REJECTED,
) as $terminal_status => $expected_universal_status ) {
	$terminal_order = new YdFrameworkOrder( 777 );
	list( $terminal_repository, $terminal_adapter, $terminal_creation, $terminal_registration, $terminal_http ) = yd_framework_stack(
		array(
			yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-' . $terminal_status ) ) ) ),
			yd_framework_response( array( 'request_id' => 'REQ-' . $terminal_status ) ),
			yd_framework_response( yd_framework_info( 'REQ-' . $terminal_status, 'CREATED', 'YD-' . $terminal_status ) ),
			yd_framework_response( array( 'status' => 'CREATED', 'description' => 'Заказ отменяется', 'reason' => 'cancellation_started' ) ),
			yd_framework_response( yd_framework_info( 'REQ-' . $terminal_status, $terminal_status, 'YD-' . $terminal_status ) ),
		)
	);
	yd_framework_assert( $terminal_creation->create( $terminal_order, yd_framework_request() )->success, 'Terminal cancel scenario must start from successful create.' );
	$terminal_cancel = $terminal_adapter->cancel_in_carrier( $terminal_order );
	$terminal_shipment = $terminal_repository->find_by_carrier( $terminal_order, YandexDeliverySettings::CARRIER_KEY );
	$terminal_policy = ( new YandexShipmentButtonPolicy() )->resolve( $terminal_shipment );
	yd_framework_assert( ! empty( $terminal_cancel['success'] ) && empty( $terminal_cancel['accepted'] ) && empty( $terminal_cancel['cancellation_started'] ) && empty( $terminal_cancel['auto_poll'] ) && $terminal_status === (string) ( $terminal_cancel['status'] ?? '' ), 'Immediate non-CANCELLED terminal status after cancel must surface status and must not start polling.' );
	yd_framework_assert( 'created' === (string) ( $terminal_shipment['status'] ?? '' ) && $terminal_status === (string) ( $terminal_shipment['yandex_status'] ?? '' ) && $expected_universal_status === (string) ( $terminal_shipment['universal_status_code'] ?? '' ) && empty( $terminal_shipment['yandex_cancel_requested'] ), 'Non-CANCELLED terminal universal status must close cancellation_started lifecycle.' );
	yd_framework_assert( empty( $terminal_policy['cancel'] ) && ! empty( $terminal_policy['remove'] ) && 5 === count( $terminal_http->requests ), 'Terminal universal status must hide cancel, allow remove and avoid repeated request/cancel.' );
	yd_framework_assert( ! str_contains( implode( "\n", $terminal_order->notes ), 'Отправление Яндекс отменено.' ) && str_contains( implode( "\n", $terminal_order->notes ), 'Получен терминальный статус Яндекс: ' . $terminal_status ), 'Non-CANCELLED terminal cancel resolution must not write successful-cancel note.' );
}

$cancelled_mapping_order = new YdFrameworkOrder( 973, '973' );
list( $cancelled_mapping_repository, $cancelled_mapping_adapter, $cancelled_mapping_creation, $cancelled_mapping_registration, $cancelled_mapping_http ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-CANCELLED-MAPPING' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-CANCELLED-MAPPING' ) ),
		yd_framework_response( yd_framework_info( 'REQ-CANCELLED-MAPPING', 'CREATED', 'YD-CANCELLED-MAPPING', '973' ) ),
		yd_framework_response( array( 'status' => 'CREATED', 'description' => 'Заказ отменяется', 'reason' => 'cancellation_started' ) ),
		yd_framework_response( yd_framework_info( 'REQ-CANCELLED-MAPPING', 'CANCELLED', 'YD-CANCELLED-MAPPING', '973' ) ),
	)
);
$cancelled_mapping_settings = new SettingsRepository();
$cancelled_mapping_settings->set( ShipmentOrderStatusMappingService::ENABLED_KEY, true );
$cancelled_mapping_settings->set( ShipmentOrderStatusMappingService::MAPPING_KEY, array( DeliveryStatus::CANCELLED => 'wc-completed' ) );
yd_framework_assert( $cancelled_mapping_creation->create( $cancelled_mapping_order, yd_framework_request( 973, '973' ) )->success, 'CANCELLED mapping scenario must start from successful create.' );
$cancelled_mapping_result = $cancelled_mapping_adapter->cancel_in_carrier( $cancelled_mapping_order );
yd_framework_assert( ! empty( $cancelled_mapping_result['cancelled_and_removed'] ) && empty( $cancelled_mapping_result['auto_poll'] ) && 'completed' === $cancelled_mapping_order->get_status() && array() === $cancelled_mapping_repository->find_by_carrier( $cancelled_mapping_order, YandexDeliverySettings::CARRIER_KEY ) && '' === (string) $cancelled_mapping_order->get_meta( '_wdc_yandex_delivery_request_id', true ) && 0 === (int) ( $cancelled_mapping_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true )['last_index'] ?? -1 ) && 5 === count( $cancelled_mapping_http->requests ), 'Raw CANCELLED with default mapping must apply universal-to-Woo mapping before local auto-delete, skip polling and preserve sequence meta.' );

$cancelled_override_immediate_order = new YdFrameworkOrder( 979, '979' );
list( $cancelled_override_immediate_repository, $cancelled_override_immediate_adapter, $cancelled_override_immediate_creation, $cancelled_override_immediate_registration, $cancelled_override_immediate_http ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-CANCELLED-OVERRIDE-IMMEDIATE' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-CANCELLED-OVERRIDE-IMMEDIATE' ) ),
		yd_framework_response( yd_framework_info( 'REQ-CANCELLED-OVERRIDE-IMMEDIATE', 'CREATED', 'YD-CANCELLED-OVERRIDE-IMMEDIATE', '979' ) ),
		yd_framework_response( array( 'status' => 'CREATED', 'description' => 'Заказ отменяется', 'reason' => 'cancellation_started' ) ),
		yd_framework_response( yd_framework_info( 'REQ-CANCELLED-OVERRIDE-IMMEDIATE', 'CANCELLED', 'YD-CANCELLED-OVERRIDE-IMMEDIATE', '979' ) ),
	)
);
$cancelled_override_immediate_mapping = new YandexStatusMapping( new SettingsRepository() );
$cancelled_override_immediate_mapping->save_mapping( array_merge( YandexStatusMapping::default_mapping(), array( 'CANCELLED' => DeliveryStatus::IN_TRANSIT ) ) );
$cancelled_override_immediate_settings = new SettingsRepository();
$cancelled_override_immediate_settings->set( ShipmentOrderStatusMappingService::ENABLED_KEY, true );
$cancelled_override_immediate_settings->set( ShipmentOrderStatusMappingService::MAPPING_KEY, array( DeliveryStatus::IN_TRANSIT => 'wc-shipped' ) );
yd_framework_assert( $cancelled_override_immediate_creation->create( $cancelled_override_immediate_order, yd_framework_request( 979, '979' ) )->success, 'CANCELLED override immediate scenario must start from successful create.' );
$cancelled_override_immediate_result = $cancelled_override_immediate_adapter->cancel_in_carrier( $cancelled_override_immediate_order );
yd_framework_assert( ! empty( $cancelled_override_immediate_result['cancelled_and_removed'] ) && empty( $cancelled_override_immediate_result['auto_poll'] ) && 'shipped' === $cancelled_override_immediate_order->get_status() && array() === $cancelled_override_immediate_repository->find_by_carrier( $cancelled_override_immediate_order, YandexDeliverySettings::CARRIER_KEY ) && '' === (string) $cancelled_override_immediate_order->get_meta( '_wdc_yandex_delivery_request_id', true ) && 0 === (int) ( $cancelled_override_immediate_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true )['last_index'] ?? -1 ) && 5 === count( $cancelled_override_immediate_http->requests ), 'Raw CANCELLED must auto-delete even when admin maps CANCELLED -> in_transit, and Woo mapping must receive in_transit before delete.' );

$cancelled_override_update_order = new YdFrameworkOrder( 980, '980' );
list( $cancelled_override_update_repository, $cancelled_override_update_adapter, $cancelled_override_update_creation, $cancelled_override_update_registration, $cancelled_override_update_http ) = yd_framework_stack(
	array(
		yd_framework_response( yd_framework_info( 'REQ-CANCELLED-OVERRIDE-UPDATE', 'CANCELLED', 'YD-CANCELLED-OVERRIDE-UPDATE', '980' ) ),
	)
);
$cancelled_override_update_mapping = new YandexStatusMapping( new SettingsRepository() );
$cancelled_override_update_mapping->save_mapping( array_merge( YandexStatusMapping::default_mapping(), array( 'CANCELLED' => DeliveryStatus::IN_TRANSIT ) ) );
$cancelled_override_update_settings = new SettingsRepository();
$cancelled_override_update_settings->set( ShipmentOrderStatusMappingService::ENABLED_KEY, true );
$cancelled_override_update_settings->set( ShipmentOrderStatusMappingService::MAPPING_KEY, array( DeliveryStatus::IN_TRANSIT => 'wc-shipped' ) );
$cancelled_override_update_repository->save_for_carrier(
	$cancelled_override_update_order,
	YandexDeliverySettings::CARRIER_KEY,
	array(
		'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
		'status' => 'cancellation_started',
		'yandex_request_id' => 'REQ-CANCELLED-OVERRIDE-UPDATE',
		'request_id' => 'REQ-CANCELLED-OVERRIDE-UPDATE',
		'yandex_cancel_requested' => true,
		'yandex_status' => 'CREATED',
		'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER,
	)
);
( new YandexShipmentRepository( $cancelled_override_update_repository ) )->sync_sequence_from_operator_request_id( $cancelled_override_update_order, '980', '980', '2026-07-14 12:00:00' );
$cancelled_override_update_result = $cancelled_override_update_adapter->update_status( $cancelled_override_update_order );
yd_framework_assert( ! empty( $cancelled_override_update_result['cancelled_and_removed'] ) && 'shipped' === $cancelled_override_update_order->get_status() && array() === $cancelled_override_update_repository->find_by_carrier( $cancelled_override_update_order, YandexDeliverySettings::CARRIER_KEY ) && '' === (string) $cancelled_override_update_order->get_meta( '_wdc_yandex_delivery_request_id', true ) && 0 === (int) ( $cancelled_override_update_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true )['last_index'] ?? -1 ) && 1 === count( $cancelled_override_update_http->requests ), 'Polling/update path raw CANCELLED must auto-delete even when admin maps CANCELLED -> in_transit.' );

$override_nonterminal_order = new YdFrameworkOrder( 974, '974' );
list( $override_nonterminal_repository, $override_nonterminal_adapter, $override_nonterminal_creation, $override_nonterminal_registration, $override_nonterminal_http ) = yd_framework_stack(
	array(
		yd_framework_response( yd_framework_info( 'REQ-OVERRIDE-NONTERMINAL', 'DELIVERY_DELIVERED', 'YD-OVERRIDE-NONTERMINAL', '974' ) ),
	)
);
$override_nonterminal_repository->save_for_carrier(
	$override_nonterminal_order,
	YandexDeliverySettings::CARRIER_KEY,
	array(
		'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
		'status' => 'cancellation_started',
		'yandex_request_id' => 'REQ-OVERRIDE-NONTERMINAL',
		'request_id' => 'REQ-OVERRIDE-NONTERMINAL',
		'yandex_cancel_requested' => true,
		'yandex_status' => 'CREATED',
		'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER,
	)
);
$override_nonterminal_yandex_mapping = new YandexStatusMapping( new SettingsRepository() );
$override_nonterminal_yandex_mapping->save_mapping( array_merge( YandexStatusMapping::default_mapping(), array( 'DELIVERY_DELIVERED' => DeliveryStatus::IN_TRANSIT ) ) );
$override_nonterminal_result = $override_nonterminal_adapter->update_status( $override_nonterminal_order );
$override_nonterminal_shipment = $override_nonterminal_repository->find_by_carrier( $override_nonterminal_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( ! empty( $override_nonterminal_result['success'] ) && 'cancellation_started' === (string) ( $override_nonterminal_shipment['status'] ?? '' ) && DeliveryStatus::IN_TRANSIT === (string) ( $override_nonterminal_shipment['universal_status_code'] ?? '' ) && ! empty( $override_nonterminal_shipment['yandex_cancel_requested'] ) && 1 === count( $override_nonterminal_http->requests ), 'Admin override DELIVERY_DELIVERED -> in_transit must keep cancellation polling pending and call only request/info.' );

$override_terminal_order = new YdFrameworkOrder( 975, '975' );
list( $override_terminal_repository, $override_terminal_adapter, $override_terminal_creation, $override_terminal_registration, $override_terminal_http ) = yd_framework_stack(
	array(
		yd_framework_response( yd_framework_info( 'REQ-OVERRIDE-TERMINAL', 'CREATED', 'YD-OVERRIDE-TERMINAL', '975' ) ),
	)
);
$override_terminal_repository->save_for_carrier(
	$override_terminal_order,
	YandexDeliverySettings::CARRIER_KEY,
	array(
		'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
		'status' => 'cancellation_started',
		'yandex_request_id' => 'REQ-OVERRIDE-TERMINAL',
		'request_id' => 'REQ-OVERRIDE-TERMINAL',
		'yandex_cancel_requested' => true,
		'yandex_status' => 'CREATED',
		'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER,
	)
);
$override_terminal_yandex_mapping = new YandexStatusMapping( new SettingsRepository() );
$override_terminal_yandex_mapping->save_mapping( array_merge( YandexStatusMapping::default_mapping(), array( 'CREATED' => DeliveryStatus::DELIVERED ) ) );
$override_terminal_result = $override_terminal_adapter->update_status( $override_terminal_order );
$override_terminal_shipment = $override_terminal_repository->find_by_carrier( $override_terminal_order, YandexDeliverySettings::CARRIER_KEY );
$override_terminal_payload = $override_terminal_adapter->status_payload( $override_terminal_order, $override_terminal_shipment );
yd_framework_assert( ! empty( $override_terminal_result['success'] ) && 'created' === (string) ( $override_terminal_shipment['status'] ?? '' ) && DeliveryStatus::DELIVERED === (string) ( $override_terminal_shipment['universal_status_code'] ?? '' ) && empty( $override_terminal_shipment['yandex_cancel_requested'] ) && empty( $override_terminal_payload['can_cancel'] ) && ! empty( $override_terminal_payload['can_remove_from_order'] ) && 1 === count( $override_terminal_http->requests ), 'Admin override CREATED -> delivered must make cancel polling terminal without auto-delete because raw status is not CANCELLED.' );

$override_terminal_immediate_order = new YdFrameworkOrder( 981, '981' );
list( $override_terminal_immediate_repository, $override_terminal_immediate_adapter, $override_terminal_immediate_creation, $override_terminal_immediate_registration, $override_terminal_immediate_http ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-OVERRIDE-TERMINAL-IMMEDIATE' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-OVERRIDE-TERMINAL-IMMEDIATE' ) ),
		yd_framework_response( yd_framework_info( 'REQ-OVERRIDE-TERMINAL-IMMEDIATE', 'CREATED', 'YD-OVERRIDE-TERMINAL-IMMEDIATE', '981' ) ),
		yd_framework_response( array( 'status' => 'CREATED', 'description' => 'Заказ отменяется', 'reason' => 'cancellation_started' ) ),
		yd_framework_response( yd_framework_info( 'REQ-OVERRIDE-TERMINAL-IMMEDIATE', 'CREATED', 'YD-OVERRIDE-TERMINAL-IMMEDIATE', '981' ) ),
	)
);
yd_framework_assert( $override_terminal_immediate_creation->create( $override_terminal_immediate_order, yd_framework_request( 981, '981' ) )->success, 'Immediate CREATED override terminal scenario must start from successful create.' );
$override_terminal_immediate_mapping = new YandexStatusMapping( new SettingsRepository() );
$override_terminal_immediate_mapping->save_mapping( array_merge( YandexStatusMapping::default_mapping(), array( 'CREATED' => DeliveryStatus::DELIVERED ) ) );
$override_terminal_immediate_result = $override_terminal_immediate_adapter->cancel_in_carrier( $override_terminal_immediate_order );
$override_terminal_immediate_shipment = $override_terminal_immediate_repository->find_by_carrier( $override_terminal_immediate_order, YandexDeliverySettings::CARRIER_KEY );
$override_terminal_immediate_payload = $override_terminal_immediate_adapter->status_payload( $override_terminal_immediate_order, $override_terminal_immediate_shipment );
yd_framework_assert( ! empty( $override_terminal_immediate_result['success'] ) && empty( $override_terminal_immediate_result['accepted'] ) && empty( $override_terminal_immediate_result['auto_poll'] ) && array() !== $override_terminal_immediate_shipment && 'created' === (string) ( $override_terminal_immediate_shipment['status'] ?? '' ) && DeliveryStatus::DELIVERED === (string) ( $override_terminal_immediate_shipment['universal_status_code'] ?? '' ) && empty( $override_terminal_immediate_shipment['yandex_cancel_requested'] ) && empty( $override_terminal_immediate_payload['can_cancel'] ) && ! empty( $override_terminal_immediate_payload['can_remove_from_order'] ) && 5 === count( $override_terminal_immediate_http->requests ), 'Immediate CREATED -> delivered override must stop cancel polling, keep shipment and expose remove without auto-delete.' );

$partial_default_order = new YdFrameworkOrder( 976, '976' );
list( $partial_default_repository, $partial_default_adapter, $partial_default_creation, $partial_default_registration, $partial_default_http ) = yd_framework_stack( array( yd_framework_response( yd_framework_info( 'REQ-PARTIAL-DEFAULT', 'PARTICULARLY_DELIVERED', 'YD-PARTIAL-DEFAULT', '976' ) ) ) );
$partial_default_settings = new SettingsRepository();
$partial_default_settings->set( ShipmentOrderStatusMappingService::ENABLED_KEY, true );
$partial_default_settings->set( ShipmentOrderStatusMappingService::MAPPING_KEY, array( DeliveryStatus::IN_TRANSIT => 'wc-shipped' ) );
$partial_default_attach = $partial_default_adapter->attach_manual( $partial_default_order, array( 'barcode' => 'REQ-PARTIAL-DEFAULT' ) );
$partial_default_shipment = $partial_default_repository->find_by_carrier( $partial_default_order, YandexDeliverySettings::CARRIER_KEY );
$partial_default_payload = $partial_default_adapter->status_payload( $partial_default_order, $partial_default_shipment );
yd_framework_assert( ! empty( $partial_default_attach['success'] ) && DeliveryStatus::IN_TRANSIT === (string) ( $partial_default_shipment['universal_status_code'] ?? '' ) && DeliveryStatus::label( DeliveryStatus::IN_TRANSIT ) === (string) ( $partial_default_payload['shipment_status_label'] ?? '' ) && empty( $partial_default_payload['can_cancel'] ) && ! empty( $partial_default_payload['can_remove_from_order'] ) && 'shipped' === $partial_default_order->get_status(), 'PARTICULARLY_DELIVERED default must persist in_transit, render universal status and feed Woo mapping.' );

$partial_override_order = new YdFrameworkOrder( 977, '977' );
list( $partial_override_repository, $partial_override_adapter, $partial_override_creation, $partial_override_registration, $partial_override_http ) = yd_framework_stack( array( yd_framework_response( yd_framework_info( 'REQ-PARTIAL-OVERRIDE', 'PARTICULARLY_DELIVERED', 'YD-PARTIAL-OVERRIDE', '977' ) ) ) );
$partial_override_yandex_mapping = new YandexStatusMapping( new SettingsRepository() );
$partial_override_yandex_mapping->save_mapping( array_merge( YandexStatusMapping::default_mapping(), array( 'PARTICULARLY_DELIVERED' => DeliveryStatus::DELIVERED ) ) );
$partial_override_settings = new SettingsRepository();
$partial_override_settings->set( ShipmentOrderStatusMappingService::ENABLED_KEY, true );
$partial_override_settings->set( ShipmentOrderStatusMappingService::MAPPING_KEY, array( DeliveryStatus::DELIVERED => 'wc-completed' ) );
$partial_override_attach = $partial_override_adapter->attach_manual( $partial_override_order, array( 'barcode' => 'REQ-PARTIAL-OVERRIDE' ) );
$partial_override_shipment = $partial_override_repository->find_by_carrier( $partial_override_order, YandexDeliverySettings::CARRIER_KEY );
$partial_override_payload = $partial_override_adapter->status_payload( $partial_override_order, $partial_override_shipment );
yd_framework_assert( ! empty( $partial_override_attach['success'] ) && DeliveryStatus::DELIVERED === (string) ( $partial_override_shipment['universal_status_code'] ?? '' ) && empty( $partial_override_payload['can_cancel'] ) && ! empty( $partial_override_payload['can_remove_from_order'] ) && 'completed' === $partial_override_order->get_status(), 'Admin override PARTICULARLY_DELIVERED -> delivered must affect persistence, button policy and Woo mapping.' );

$attach_order = new YdFrameworkOrder( 777 );
list( $attach_repository, $attach_adapter, $attach_creation, $attach_registration, $attach_http ) = yd_framework_stack(
	array(
		yd_framework_response( yd_framework_info( 'REQ-MANUAL', 'CREATED', 'YD-MANUAL' ) ),
	)
);
$empty_attach_payload = $attach_adapter->status_payload( $attach_order, array() );
yd_framework_assert( ! empty( $empty_attach_payload['can_create'] ) && ! empty( $empty_attach_payload['can_attach_manual'] ), 'Yandex status payload must expose manual attach when local shipment is absent.' );
$attach_presentation = $attach_adapter->presentation();
yd_framework_assert( 'Ввести номер Яндекс вручную' === (string) ( $attach_presentation['manual_attach_button_label'] ?? '' ) && 'Request ID Яндекс' === (string) ( $attach_presentation['manual_attach_field_label'] ?? '' ) && '***-udp' === (string) ( $attach_presentation['manual_attach_placeholder'] ?? '' ) && str_contains( (string) ( $attach_presentation['manual_attach_help'] ?? '' ), 'request_id' ), 'Yandex manual attach presentation must use the new Russian button, Request ID label and ***-udp placeholder.' );
yd_framework_assert( 'Статус отправления пока не получен. Повторите обновление статуса позднее.' === (string) ( $attach_presentation['polling_timeout_message'] ?? '' ) && str_contains( (string) ( $attach_presentation['remove_confirmation_message'] ?? '' ), 'Статус отправления в Яндекс.Доставке останется без изменений' ), 'Yandex presentation must expose Russian polling exhaustion and local-remove warning messages.' );
$attach_result = $attach_adapter->attach_manual( $attach_order, array( 'barcode' => 'REQ-MANUAL' ) );
$attached = $attach_repository->find_by_carrier( $attach_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( ! empty( $attach_result['success'] ) && 1 === count( $attach_http->requests ) && str_contains( $attach_http->requests[0]['url'], '/request/info' ) && str_contains( $attach_http->requests[0]['url'], 'request_id=REQ-MANUAL' ), 'Manual attach must verify Yandex request_id with a single request/info call.' );
yd_framework_assert( ! str_contains( implode( "\n", array_column( $attach_http->requests, 'url' ) ), '/offers/create' ) && ! str_contains( implode( "\n", array_column( $attach_http->requests, 'url' ) ), '/offers/confirm' ), 'Manual attach must not call offers/create or offers/confirm.' );
yd_framework_assert( 'REQ-MANUAL' === (string) ( $attached['yandex_request_id'] ?? '' ) && 'ORDER-777' === (string) ( $attached['yandex_operator_request_id'] ?? '' ) && 'CREATED' === (string) ( $attached['yandex_status'] ?? '' ), 'Manual attach must persist canonical Yandex request/info fields.' );
yd_framework_assert( ! isset( $attached['actual_cost_kopecks'] ) && ! isset( $attached['yandex_offer_pricing_total_kopecks'] ), 'Manual attach must not invent actual delivery cost from request/info, checkout or base price.' );
yd_framework_assert( 'REQ-MANUAL' === (string) $attach_order->get_meta( '_wdc_yandex_delivery_request_id', true ) && 'admin_manual_attach' === (string) ( $attached['created_by_context'] ?? '' ), 'Manual attach must persist lookup meta and admin_manual_attach context.' );
$attached_payload = $attach_adapter->status_payload( $attach_order, $attached );
yd_framework_assert( empty( $attached_payload['can_create'] ) && empty( $attached_payload['can_attach_manual'] ) && ! empty( $attached_payload['can_update_status'] ) && ! empty( $attached_payload['can_cancel'] ), 'Attached CREATED Yandex shipment must hide create/manual attach and expose update/cancel.' );
yd_framework_assert( '' === (string) ( $attached_payload['actual_cost_label'] ?? '' ) && '00000' === (string) ( $attached_payload['yandex_self_pickup_node_code'] ?? '' ), 'Manual attach must keep price row hidden and expose pickup code from request/info.' );
$attached_document_actions = yd_framework_document_provider( new YandexShipmentLabelPolicy( new YandexStatusMapping( new SettingsRepository() ) ), $attach_repository )->actions( $attach_order, $attached );
yd_framework_assert( 1 === count( $attached_document_actions ) && 'download_yandex_label' === $attached_document_actions[0]->key, 'Manual attach without price must still expose Yandex label download when universal status allows it.' );
$label_success_http = new YdFrameworkFakeHttp( array( new YandexDeliveryApiResponse( 200, "%PDF-1.4\nmanual attach label", array( 'content-type' => 'application/pdf' ) ) ) );
$label_service = new YandexShipmentDocumentService( new YandexShipmentRepository( $attach_repository ), new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_framework_settings(), $label_success_http ) ), new YandexShipmentLabelPolicy( new YandexStatusMapping( new SettingsRepository() ) ) );
$label_result = $label_service->label_pdf_for_order( $attach_order );
$label_request_body = json_decode( (string) ( $label_success_http->requests[0]['args']['body'] ?? '{}' ), true );
yd_framework_assert( ! empty( $label_result['success'] ) && "%PDF-" === substr( (string) ( $label_result['body'] ?? '' ), 0, 5 ) && array( 'REQ-MANUAL' ) === (array) ( $label_request_body['request_ids'] ?? array() ) && 'yandex-label-ORDER-777.pdf' === (string) ( $label_result['filename'] ?? '' ), 'Yandex label document service must stream PDF for persisted request_id and sanitize filename.' );
$label_empty_http = new YdFrameworkFakeHttp( array( new YandexDeliveryApiResponse( 200, '', array( 'content-type' => 'application/pdf' ) ) ) );
$label_empty = ( new YandexShipmentDocumentService( new YandexShipmentRepository( $attach_repository ), new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_framework_settings(), $label_empty_http ) ), new YandexShipmentLabelPolicy( new YandexStatusMapping( new SettingsRepository() ) ) ) )->label_pdf_for_order( $attach_order );
yd_framework_assert( empty( $label_empty['success'] ) && 'Яндекс.Доставка вернула пустой файл ярлыка.' === (string) ( $label_empty['message'] ?? '' ), 'Yandex label document service must reject empty PDF body.' );
$label_non_pdf_http = new YdFrameworkFakeHttp( array( new YandexDeliveryApiResponse( 200, '{"message":"not pdf"}', array( 'content-type' => 'application/json' ) ) ) );
$label_non_pdf = ( new YandexShipmentDocumentService( new YandexShipmentRepository( $attach_repository ), new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_framework_settings(), $label_non_pdf_http ) ), new YandexShipmentLabelPolicy( new YandexStatusMapping( new SettingsRepository() ) ) ) )->label_pdf_for_order( $attach_order );
yd_framework_assert( empty( $label_non_pdf['success'] ) && 'Яндекс.Доставка вернула ответ, который не является PDF-файлом.' === (string) ( $label_non_pdf['message'] ?? '' ), 'Yandex label document service must reject JSON/non-PDF response bodies.' );

$label_guard_repository = new OrderShipmentRepository();
$label_guard_yandex_repository = new YandexShipmentRepository( $label_guard_repository );
$label_guard_policy = new YandexShipmentLabelPolicy( new YandexStatusMapping( new SettingsRepository() ) );
$label_guard_index = 0;
foreach ( array(
	'reconciliation_required' => array( 'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER, 'yandex_reconciliation_required' => true ),
	DeliveryStatus::PENDING_CREATION_IN_CARRIER => array( 'universal_status_code' => DeliveryStatus::PENDING_CREATION_IN_CARRIER ),
	DeliveryStatus::CANCELLED => array( 'universal_status_code' => DeliveryStatus::CANCELLED ),
	DeliveryStatus::REJECTED => array( 'universal_status_code' => DeliveryStatus::REJECTED ),
	DeliveryStatus::UNKNOWN => array( 'universal_status_code' => DeliveryStatus::UNKNOWN ),
) as $guard_case => $guard_fields ) {
	++$label_guard_index;
	$guard_order = new YdFrameworkOrder( 1100 + $label_guard_index, 'LABEL-' . (string) $guard_case );
	$guard_shipment = array_merge( array( 'carrier_key' => YandexDeliverySettings::CARRIER_KEY, 'yandex_request_id' => 'REQ-LABEL-GUARD-' . (string) $guard_case ), $guard_fields );
	$label_guard_repository->save_for_carrier( $guard_order, YandexDeliverySettings::CARRIER_KEY, $guard_shipment );
	$guard_http = new YdFrameworkFakeHttp( array() );
	$guard_result = ( new YandexShipmentDocumentService( $label_guard_yandex_repository, new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( $settings, $guard_http ) ), $label_guard_policy ) )->label_pdf_for_order( $guard_order );
	yd_framework_assert( empty( $guard_result['success'] ) && 'Для текущего статуса отправления ярлык Яндекс недоступен.' === (string) ( $guard_result['message'] ?? '' ) && array() === $guard_http->requests, 'Server-side Yandex label guard must block API for ' . (string) $guard_case );
}

$override_label_mapping = new YandexStatusMapping( new SettingsRepository() );
foreach ( array( DeliveryStatus::UNKNOWN, DeliveryStatus::CANCELLED ) as $override_universal ) {
	$override_label_mapping->save_mapping( array_merge( YandexStatusMapping::default_mapping(), array( 'CREATED' => $override_universal ) ) );
	$override_order = new YdFrameworkOrder( 1190 + ( DeliveryStatus::CANCELLED === $override_universal ? 1 : 0 ), 'LABEL-OVERRIDE-' . $override_universal );
	$label_guard_repository->save_for_carrier( $override_order, YandexDeliverySettings::CARRIER_KEY, array( 'carrier_key' => YandexDeliverySettings::CARRIER_KEY, 'yandex_request_id' => 'REQ-LABEL-OVERRIDE-' . $override_universal, 'yandex_status' => 'CREATED' ) );
	$override_http = new YdFrameworkFakeHttp( array() );
	$override_result = ( new YandexShipmentDocumentService( $label_guard_yandex_repository, new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( $settings, $override_http ) ), new YandexShipmentLabelPolicy( $override_label_mapping ) ) )->label_pdf_for_order( $override_order );
	$override_actions = yd_framework_document_provider( new YandexShipmentLabelPolicy( $override_label_mapping ), $label_guard_repository )->actions( $override_order, $label_guard_yandex_repository->find( $override_order ) );
	yd_framework_assert( empty( $override_result['success'] ) && array() === $override_http->requests && array() === $override_actions, 'Server-side and UI Yandex label policy must both follow admin override CREATED -> ' . $override_universal );
}
$override_label_mapping->save_mapping( YandexStatusMapping::default_mapping() );
$attached_tracking = (array) ( $attached_payload['tracking_presentation'] ?? array() );
yd_framework_assert( 'https://dostavka.yandex.ru/route/example' === (string) ( $attached['sharing_url'] ?? '' ) && 'Отслеживание посылки' === (string) ( $attached_tracking['label'] ?? '' ) && 'ссылка' === (string) ( $attached_tracking['display_text'] ?? '' ) && 'https://dostavka.yandex.ru/route/example' === (string) ( $attached_tracking['copy_value'] ?? '' ), 'Manual attach must persist sharing_url and immediately expose the Yandex tracking link/copy presentation.' );
$duplicate_attach = $attach_adapter->attach_manual( $attach_order, array( 'request_id' => 'REQ-DUPLICATE' ) );
yd_framework_assert( empty( $duplicate_attach['success'] ) && 'По заказу уже сохранено отправление Яндекс.' === (string) ( $duplicate_attach['message'] ?? '' ) && 1 === count( $attach_http->requests ), 'Duplicate manual attach must be blocked before request/info.' );

$wrong_order = new YdFrameworkOrder( 777 );
list( $wrong_repository, $wrong_adapter, $wrong_creation, $wrong_registration, $wrong_http ) = yd_framework_stack( array( yd_framework_response( yd_framework_info( 'REQ-WRONG', 'CREATED', 'YD-WRONG', 'ORDER-999' ) ) ) );
$wrong_attach = $wrong_adapter->attach_manual( $wrong_order, array( 'barcode' => 'REQ-WRONG' ) );
yd_framework_assert( ! empty( $wrong_attach['success'] ) && 'REQ-WRONG' === (string) ( $wrong_repository->find_by_carrier( $wrong_order, YandexDeliverySettings::CARRIER_KEY )['yandex_request_id'] ?? '' ) && 'ORDER-999' === (string) ( $wrong_repository->find_by_carrier( $wrong_order, YandexDeliverySettings::CARRIER_KEY )['yandex_operator_request_id'] ?? '' ) && -1 === (int) ( $wrong_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true )['last_index'] ?? -1 ), 'Manual attach must allow foreign operator_request_id and must not change current order sequence.' );

$missing_operator_order = new YdFrameworkOrder( 777 );
list( $missing_repository, $missing_adapter ) = yd_framework_stack( array( yd_framework_response( yd_framework_info( 'REQ-NO-OP', 'CREATED', 'YD-NO-OP', null ) ) ) );
$missing_attach = $missing_adapter->attach_manual( $missing_operator_order, array( 'barcode' => 'REQ-NO-OP' ) );
yd_framework_assert( ! empty( $missing_attach['success'] ) && 'REQ-NO-OP' === (string) ( $missing_repository->find_by_carrier( $missing_operator_order, YandexDeliverySettings::CARRIER_KEY )['yandex_request_id'] ?? '' ), 'Manual attach must not require operator_request_id ownership validation.' );

$api_error_order = new YdFrameworkOrder( 777 );
list( $api_error_repository, $api_error_adapter, $api_error_creation, $api_error_registration, $api_error_http ) = yd_framework_stack( array( yd_framework_error_response( 404, array( 'message' => 'not found' ) ) ) );
$api_error_attach = $api_error_adapter->attach_manual( $api_error_order, array( 'barcode' => 'REQ-404' ) );
yd_framework_assert( empty( $api_error_attach['success'] ) && 'Отправление Яндекс с указанным Request ID не найдено.' === (string) ( $api_error_attach['message'] ?? '' ) && array() === $api_error_repository->find_by_carrier( $api_error_order, YandexDeliverySettings::CARRIER_KEY ) && 1 === count( $api_error_http->requests ), 'Manual attach must return Russian not-found error and avoid persistence.' );

$terminal_attach_order = new YdFrameworkOrder( 777 );
list( $terminal_attach_repository, $terminal_attach_adapter ) = yd_framework_stack( array( yd_framework_response( yd_framework_info( 'REQ-ATTACH-CANCELLED', 'CANCELLED', 'YD-ATTACH-CANCELLED' ) ) ) );
$terminal_attach = $terminal_attach_adapter->attach_manual( $terminal_attach_order, array( 'barcode' => 'REQ-ATTACH-CANCELLED' ) );
$terminal_attached = $terminal_attach_repository->find_by_carrier( $terminal_attach_order, YandexDeliverySettings::CARRIER_KEY );
$terminal_attach_payload = $terminal_attach_adapter->status_payload( $terminal_attach_order, $terminal_attached );
yd_framework_assert( ! empty( $terminal_attach['success'] ) && 'CANCELLED' === (string) ( $terminal_attached['yandex_status'] ?? '' ) && empty( $terminal_attach_payload['can_cancel'] ) && ! empty( $terminal_attach_payload['can_remove_from_order'] ), 'Manual attach of terminal Yandex shipment must persist terminal status and expose remove without cancel.' );

$sequence_order = new YdFrameworkOrder( 1010, '1010' );
list( $sequence_repository_1, $sequence_adapter_1, $sequence_creation_1, $sequence_registration_1, $sequence_http_1 ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-SEQ-0' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-SEQ-0' ) ),
		yd_framework_response( yd_framework_info( 'REQ-SEQ-0', 'CREATED', 'YD-SEQ-0', '1010' ) ),
	)
);
$first_sequence_result = $sequence_creation_1->create( $sequence_order, yd_framework_request( 1010, '1010' ) );
$first_sequence_shipment = $sequence_repository_1->find_by_carrier( $sequence_order, YandexDeliverySettings::CARRIER_KEY );
$first_sequence_body = json_decode( (string) ( $sequence_http_1->requests[0]['args']['body'] ?? '{}' ), true );
yd_framework_assert( $first_sequence_result->success && '1010' === (string) ( $first_sequence_body['info']['operator_request_id'] ?? '' ) && '1010-1' === (string) ( $first_sequence_body['places'][0]['barcode'] ?? '' ), 'First Yandex registration attempt must use base order number and safe temporary place barcode.' );
yd_framework_assert( '1010' === (string) ( $first_sequence_shipment['yandex_operator_request_id'] ?? '' ) && 0 === (int) ( $first_sequence_shipment['yandex_registration_sequence_index'] ?? -1 ), 'First Yandex shipment persistence must store operator_request_id and sequence index 0.' );
$sequence_meta_1 = $sequence_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true );
yd_framework_assert( 0 === (int) ( $sequence_meta_1['last_index'] ?? -1 ) && '1010' === (string) ( $sequence_meta_1['last_operator_request_id'] ?? '' ) && ! array_key_exists( 'allocated_ids', $sequence_meta_1 ) && '1010' === (string) ( $sequence_meta_1['current_attempt']['operator_request_id'] ?? '' ), 'First reservation must persist compact sequence meta for base operator id.' );
$first_sequence_shipment['yandex_status'] = 'CANCELLED';
$first_sequence_shipment['universal_status_code'] = DeliveryStatus::CANCELLED;
$first_sequence_shipment['universal_status_label'] = DeliveryStatus::label( DeliveryStatus::CANCELLED );
$first_sequence_shipment['status_title'] = 'CANCELLED';
$sequence_repository_1->save_for_carrier( $sequence_order, YandexDeliverySettings::CARRIER_KEY, $first_sequence_shipment );
yd_framework_assert( ! empty( $sequence_adapter_1->remove_from_order( $sequence_order )['success'] ), 'Terminal sequence shipment must be locally removable before a new attempt.' );
$sequence_after_remove = $sequence_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true );
yd_framework_assert( 0 === (int) ( $sequence_after_remove['last_index'] ?? -1 ) && ! array_key_exists( 'allocated_ids', $sequence_after_remove ), 'Local remove must not clear Yandex registration sequence or restore allocated history.' );

list( $sequence_repository_2, $sequence_adapter_2, $sequence_creation_2, $sequence_registration_2, $sequence_http_2 ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-SEQ-1' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-SEQ-1' ) ),
		yd_framework_response( yd_framework_info( 'REQ-SEQ-1', 'CREATED', 'YD-SEQ-1', '1010/1' ) ),
	)
);
$second_sequence_result = $sequence_creation_2->create( $sequence_order, yd_framework_request( 1010, '1010' ) );
$second_sequence_shipment = $sequence_repository_2->find_by_carrier( $sequence_order, YandexDeliverySettings::CARRIER_KEY );
$second_sequence_body = json_decode( (string) ( $sequence_http_2->requests[0]['args']['body'] ?? '{}' ), true );
yd_framework_assert( $second_sequence_result->success && '1010/1' === (string) ( $second_sequence_body['info']['operator_request_id'] ?? '' ) && '1010-1-1' === (string) ( $second_sequence_body['places'][0]['barcode'] ?? '' ) && '1010-1-1' === (string) ( $second_sequence_body['items'][0]['place_barcode'] ?? '' ), 'Second Yandex attempt must use 1010/1 and a slash-free stable temporary barcode.' );
yd_framework_assert( '1010/1' === (string) ( $second_sequence_shipment['yandex_operator_request_id'] ?? '' ) && 1 === (int) ( $second_sequence_shipment['yandex_registration_sequence_index'] ?? -1 ) && 'YD-SEQ-1' === (string) ( $second_sequence_shipment['yandex_place_barcode_map']['1010-1-1'] ?? '' ), 'Second attempt persistence must store sequence index and map temporary barcode to real barcode.' );
$second_sequence_shipment['yandex_status'] = 'CANCELLED';
$second_sequence_shipment['universal_status_code'] = DeliveryStatus::CANCELLED;
$second_sequence_shipment['universal_status_label'] = DeliveryStatus::label( DeliveryStatus::CANCELLED );
$sequence_repository_2->save_for_carrier( $sequence_order, YandexDeliverySettings::CARRIER_KEY, $second_sequence_shipment );
yd_framework_assert( ! empty( $sequence_adapter_2->remove_from_order( $sequence_order )['success'] ), 'Second terminal sequence shipment must be removable.' );

list( $sequence_repository_3, $sequence_adapter_3, $sequence_creation_3, $sequence_registration_3, $sequence_http_3 ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-SEQ-2' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-SEQ-2' ) ),
		yd_framework_response( yd_framework_info( 'REQ-SEQ-2', 'CREATED', 'YD-SEQ-2', '1010/2' ) ),
	)
);
$third_sequence_result = $sequence_creation_3->create( $sequence_order, yd_framework_request( 1010, '1010' ) );
$third_sequence_body = json_decode( (string) ( $sequence_http_3->requests[0]['args']['body'] ?? '{}' ), true );
yd_framework_assert( $third_sequence_result->success && '1010/2' === (string) ( $third_sequence_body['info']['operator_request_id'] ?? '' ), 'Third Yandex attempt must use 1010/2.' );
$third_sequence_meta = $sequence_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true );
yd_framework_assert( 2 === (int) ( $third_sequence_meta['last_index'] ?? -1 ) && '1010/2' === (string) ( $third_sequence_meta['last_operator_request_id'] ?? '' ) && ! array_key_exists( 'allocated_ids', $third_sequence_meta ), 'Third reservation must keep compact sequence state without allocated history.' );

$sequence_repo_probe = new YandexShipmentRepository( new OrderShipmentRepository() );
$preview_sequence_before = $sequence_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true );
$peek_1 = $sequence_repo_probe->peek_next_operator_request_id( $sequence_order, '1010' );
$peek_2 = $sequence_repo_probe->peek_next_operator_request_id( $sequence_order, '1010' );
yd_framework_assert( '1010/3' === $peek_1['operator_request_id'] && $peek_1 === $peek_2 && $preview_sequence_before === $sequence_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true ), 'Preview/peek must not consume Yandex sequence index.' );

$stress_order = new YdFrameworkOrder( 2020, '2020' );
$stress_repository = new YandexShipmentRepository( new OrderShipmentRepository() );
for ( $i = 0; $i < 100; $i++ ) {
	$attempt = $stress_repository->reserve_operator_request_id( $stress_order, '2020', '2026-07-13 12:' . str_pad( (string) ( $i % 60 ), 2, '0', STR_PAD_LEFT ) . ':00' );
	yd_framework_assert( $i === (int) $attempt['index'], 'Stress sequence reservation must allocate monotonically increasing index.' );
	$stress_repository->release_registration_lock( $stress_order, $attempt['lock_token'] );
}
$stress_meta = $stress_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true );
yd_framework_assert( 99 === (int) ( $stress_meta['last_index'] ?? -1 ) && '2020/99' === (string) ( $stress_meta['last_operator_request_id'] ?? '' ) && ! array_key_exists( 'allocated_ids', $stress_meta ) && strlen( serialize( $stress_meta ) ) < 700 && '2020/100' === $stress_repository->peek_next_operator_request_id( $stress_order, '2020' )['operator_request_id'], '100 Yandex attempts must keep compact sequence state and compute the next id.' );

$lock_order = new YdFrameworkOrder( 1012, '1012' );
$lock_repository = new YandexShipmentRepository( new OrderShipmentRepository() );
$lock_now = current_time( 'mysql' );
$lock_first = $lock_repository->reserve_operator_request_id( $lock_order, '1012', $lock_now );
$lock_blocked = false;
try {
	$lock_repository->reserve_operator_request_id( $lock_order, '1012', $lock_now );
} catch ( RuntimeException $exception ) {
	$lock_blocked = str_contains( $exception->getMessage(), 'уже выполняется' );
}
$lock_repository->release_registration_lock( $lock_order, $lock_first['lock_token'] );
yd_framework_assert( '1012' === $lock_first['operator_request_id'] && $lock_blocked, 'Concurrent Yandex reservation must not allocate the same operator_request_id while lock is active.' );

$transport_order = new YdFrameworkOrder( 1011, '1011' );
list( $transport_repository_1, $transport_adapter_1, $transport_creation_1, $transport_registration_1, $transport_http_1 ) = yd_framework_stack( array( yd_framework_error_response( 503, array( 'message' => 'temporary transport failure' ) ) ) );
$transport_failed = $transport_creation_1->create( $transport_order, yd_framework_request( 1011, '1011' ) );
yd_framework_assert( empty( $transport_failed->success ) && 1 === count( $transport_http_1->requests ) && 0 === (int) ( $transport_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true )['last_index'] ?? -1 ), 'HTTP offers/create attempt must reserve and keep sequence index even when transport/API call fails.' );
list( $transport_repository_2, $transport_adapter_2, $transport_creation_2, $transport_registration_2, $transport_http_2 ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-AFTER-FAIL' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-AFTER-FAIL' ) ),
		yd_framework_response( yd_framework_info( 'REQ-AFTER-FAIL', 'CREATED', 'YD-AFTER-FAIL', '1011/1' ) ),
	)
);
yd_framework_assert( $transport_creation_2->create( $transport_order, yd_framework_request( 1011, '1011' ) )->success && '1011/1' === (string) ( json_decode( (string) ( $transport_http_2->requests[0]['args']['body'] ?? '{}' ), true )['info']['operator_request_id'] ?? '' ), 'Next independent attempt after failed HTTP must use the next sequence id.' );

$duplicate_order = new YdFrameworkOrder( 1013, '1013' );
list( $duplicate_repository, $duplicate_adapter, $duplicate_creation, $duplicate_registration, $duplicate_http ) = yd_framework_stack(
	array(
		yd_framework_error_response( 409, array( 'message' => 'There already was request with such code within this employer, request_id: OLD-udp' ) ),
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-DUP-SKIP' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-DUP-SKIP' ) ),
		yd_framework_response( yd_framework_info( 'REQ-DUP-SKIP', 'CREATED', 'YD-DUP-SKIP', '1013/1' ) ),
	)
);
$duplicate_result = $duplicate_creation->create( $duplicate_order, yd_framework_request( 1013, '1013' ) );
$duplicate_first_body = json_decode( (string) ( $duplicate_http->requests[0]['args']['body'] ?? '{}' ), true );
$duplicate_second_body = json_decode( (string) ( $duplicate_http->requests[1]['args']['body'] ?? '{}' ), true );
$duplicate_shipment = $duplicate_repository->find_by_carrier( $duplicate_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( ! empty( $duplicate_result->success ) && 4 === count( $duplicate_http->requests ) && str_contains( $duplicate_http->requests[0]['url'], '/offers/create' ) && str_contains( $duplicate_http->requests[1]['url'], '/offers/create' ) && str_contains( $duplicate_http->requests[2]['url'], '/offers/confirm' ), 'Duplicate operator_request_id must retry only offers/create and confirm exactly once after a successful offer.' );
yd_framework_assert( '1013' === (string) ( $duplicate_first_body['info']['operator_request_id'] ?? '' ) && '1013/1' === (string) ( $duplicate_second_body['info']['operator_request_id'] ?? '' ) && '1013-1-1' === (string) ( $duplicate_second_body['places'][0]['barcode'] ?? '' ), 'Duplicate auto-skip must rebuild payload and temporary barcode prefix with the next operator_request_id.' );
yd_framework_assert( '1013/1' === (string) ( $duplicate_shipment['yandex_operator_request_id'] ?? '' ) && 1 === (int) ( $duplicate_shipment['yandex_registration_sequence_index'] ?? -1 ) && 1 === (int) ( $duplicate_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true )['last_index'] ?? -1 ), 'Final shipment and sequence state must persist the auto-skipped operator_request_id.' );

$duplicate_exact_order = new YdFrameworkOrder( 1015, '1015' );
list( $duplicate_exact_repository, $duplicate_exact_adapter, $duplicate_exact_creation, $duplicate_exact_registration, $duplicate_exact_http ) = yd_framework_stack(
	array(
		yd_framework_error_response( 409, array( 'message' => 'There already was request with such code within this employer' ) ),
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-DUP-EXACT' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-DUP-EXACT' ) ),
		yd_framework_response( yd_framework_info( 'REQ-DUP-EXACT', 'CREATED', 'YD-DUP-EXACT', '1015/1' ) ),
	)
);
yd_framework_assert( $duplicate_exact_creation->create( $duplicate_exact_order, yd_framework_request( 1015, '1015' ) )->success && 4 === count( $duplicate_exact_http->requests ), 'Exact Yandex duplicate-code phrase without request_id must trigger bounded offers/create retry.' );

$duplicate_limit_order = new YdFrameworkOrder( 1014, '1014' );
$duplicate_limit_responses = array();
for ( $i = 0; $i < 10; ++$i ) {
	$duplicate_limit_responses[] = yd_framework_error_response( 409, array( 'message' => 'There already was request with such code within this employer, request_id: OLD-' . $i . '-udp' ) );
}
list( $duplicate_limit_repository, $duplicate_limit_adapter, $duplicate_limit_creation, $duplicate_limit_registration, $duplicate_limit_http ) = yd_framework_stack( $duplicate_limit_responses );
$duplicate_limit_result = $duplicate_limit_creation->create( $duplicate_limit_order, yd_framework_request( 1014, '1014' ) );
yd_framework_assert( empty( $duplicate_limit_result->success ) && 'yandex_operator_request_id_duplicate_limit' === $duplicate_limit_result->error_code && str_contains( $duplicate_limit_result->error_message, 'после 10 попыток' ) && 10 === count( $duplicate_limit_http->requests ) && 9 === (int) ( $duplicate_limit_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true )['last_index'] ?? -1 ), 'Duplicate auto-skip must be bounded to 10 occupied ids without confirm or infinite retry.' );

foreach ( array(
	'generic-duplicate' => array( 409, array( 'message' => 'duplicate validation error' ) ),
	'duplicate-barcode' => array( 409, array( 'message' => 'duplicate item barcode' ) ),
	'http-500' => array( 500, array( 'message' => 'internal server error' ) ),
) as $case => $fixture ) {
	$no_retry_order = new YdFrameworkOrder( 1200, '1200' );
	list( $no_retry_repository, $no_retry_adapter, $no_retry_creation, $no_retry_registration, $no_retry_http ) = yd_framework_stack( array( yd_framework_error_response( $fixture[0], $fixture[1] ) ) );
	$no_retry_result = $no_retry_creation->create( $no_retry_order, yd_framework_request( 1200, '1200' ) );
	yd_framework_assert( empty( $no_retry_result->success ) && 1 === count( $no_retry_http->requests ) && 0 === (int) ( $no_retry_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true )['last_index'] ?? -1 ), 'Non-exact duplicate retry must not run for case ' . $case . '.' );
}
$network_order = new YdFrameworkOrder( 1201, '1201' );
list( $network_repository, $network_adapter, $network_creation, $network_registration, $network_http ) = yd_framework_stack( array() );
$network_result = $network_creation->create( $network_order, yd_framework_request( 1201, '1201' ) );
yd_framework_assert( empty( $network_result->success ) && 1 === count( $network_http->requests ) && 0 === (int) ( $network_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true )['last_index'] ?? -1 ), 'Transport/runtime failure must not trigger automatic duplicate retry.' );

$manual_family_repository = new YandexShipmentRepository( new OrderShipmentRepository() );
foreach ( array( '1010', '1010/1', '1010/12' ) as $valid_operator_id ) {
	yd_framework_assert( null !== $manual_family_repository->parse_operator_request_id( $valid_operator_id, '1010' ), 'Manual attach ownership must accept base or positive suffixed Yandex operator id.' );
}
foreach ( array( '101', '10101', '1010/', '1010/0', '1010/-1', '1010/x', '1010/1/2', '9999/1' ) as $invalid_operator_id ) {
	yd_framework_assert( null === $manual_family_repository->parse_operator_request_id( $invalid_operator_id, '1010' ), 'Manual attach ownership must reject non-family Yandex operator id: ' . $invalid_operator_id );
}

$manual_sync_order = new YdFrameworkOrder( 1010, '1010' );
$manual_sync_repository_seed = new YandexShipmentRepository( new OrderShipmentRepository() );
$manual_sync_repository_seed->sync_sequence_from_operator_request_id( $manual_sync_order, '1010/1', '1010', '2026-07-13 12:00:00' );
list( $manual_sync_repository, $manual_sync_adapter, $manual_sync_creation, $manual_sync_registration, $manual_sync_http ) = yd_framework_stack( array( yd_framework_response( yd_framework_info( 'REQ-MANUAL-4', 'CANCELLED', 'YD-MANUAL-4', '1010/4' ) ) ) );
$manual_sync_attach = $manual_sync_adapter->attach_manual( $manual_sync_order, array( 'barcode' => 'REQ-MANUAL-4' ) );
yd_framework_assert( ! empty( $manual_sync_attach['success'] ) && 4 === (int) ( $manual_sync_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true )['last_index'] ?? -1 ) && '1010/5' === $manual_sync_repository_seed->peek_next_operator_request_id( $manual_sync_order, '1010' )['operator_request_id'], 'Manual attach of 1010/4 must sync sequence upward and next preview must use 1010/5.' );
$manual_sync_before_lower = $manual_sync_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true );
$manual_sync_repository_seed->sync_sequence_from_operator_request_id( $manual_sync_order, '1010/3', '1010', '2026-07-13 12:10:00' );
yd_framework_assert( $manual_sync_before_lower === $manual_sync_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true ), 'Manual attach sync with a lower suffix must not decrease or rewrite compact sequence state.' );

$legacy_sequence_order = new YdFrameworkOrder( 3030, '3030' );
$legacy_sequence_order->update_meta_data(
	YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY,
	array(
		'last_index' => 2,
		'last_operator_request_id' => '3030/2',
		'allocated_ids' => array( '3030', '3030/1', '3030/2' ),
		'current_attempt' => array(
			'operator_request_id' => '3030/2',
			'sequence_index' => 2,
			'started_at' => '2026-07-13 12:00:00',
			'order_id' => 3030,
			'registration_phase' => 'offers_create',
			'lock_token' => 'legacy-token',
		),
		'updated_at' => '2026-07-13 12:00:00',
	)
);
$legacy_repository = new YandexShipmentRepository( new OrderShipmentRepository() );
$legacy_sequence = $legacy_repository->registration_sequence( $legacy_sequence_order, '3030' );
$legacy_saved_sequence = $legacy_sequence_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true );
yd_framework_assert( 2 === (int) ( $legacy_sequence['last_index'] ?? -1 ) && '3030/2' === (string) ( $legacy_sequence['last_operator_request_id'] ?? '' ) && ! array_key_exists( 'allocated_ids', $legacy_sequence ) && ! array_key_exists( 'allocated_ids', $legacy_saved_sequence ), 'Old 0.108.12 Yandex sequence state must be normalized without allocated_ids on read/save.' );

$legacy_allocated_only_order = new YdFrameworkOrder( 4040, '4040' );
$legacy_allocated_only_order->update_meta_data( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, array( 'allocated_ids' => array( '4040', '4040/4', 'other' ), 'updated_at' => '2026-07-13 12:00:00' ) );
$legacy_allocated_only_repository = new YandexShipmentRepository( new OrderShipmentRepository() );
$legacy_allocated_only_sequence = $legacy_allocated_only_repository->registration_sequence( $legacy_allocated_only_order, '4040' );
yd_framework_assert( 4 === (int) ( $legacy_allocated_only_sequence['last_index'] ?? -1 ) && '4040/4' === (string) ( $legacy_allocated_only_sequence['last_operator_request_id'] ?? '' ) && ! array_key_exists( 'allocated_ids', $legacy_allocated_only_order->get_meta( YandexShipmentRepository::REGISTRATION_SEQUENCE_META_KEY, true ) ), 'Legacy allocated-only Yandex state may be compacted to max valid suffix once.' );

$terminal_remove_order = new YdFrameworkOrder( 9090, '9090' );
list( $terminal_remove_repository, $terminal_remove_adapter, $terminal_remove_creation, $terminal_remove_registration, $terminal_remove_http ) = yd_framework_stack(
	array(
		yd_framework_response( array( 'offers' => array( yd_framework_offer( 'OFFER-TERMINAL-REMOVE' ) ) ) ),
		yd_framework_response( array( 'request_id' => 'REQ-TERMINAL-REMOVE' ) ),
		yd_framework_response( yd_framework_info( 'REQ-TERMINAL-REMOVE', 'CANCELLED', 'YD-TERMINAL-REMOVE', '9090' ) ),
	)
);
yd_framework_assert( $terminal_remove_creation->create( $terminal_remove_order, yd_framework_request( 9090, '9090' ) )->success, 'Terminal remove scenario must create a terminal Yandex shipment.' );
$remove = $terminal_remove_adapter->remove_from_order( $terminal_remove_order );
yd_framework_assert( ! empty( $remove['success'] ) && '' === (string) $terminal_remove_order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Yandex remove_local must delete lookup meta.' );

echo "Yandex delivery shipment framework smoke test passed.\n";
