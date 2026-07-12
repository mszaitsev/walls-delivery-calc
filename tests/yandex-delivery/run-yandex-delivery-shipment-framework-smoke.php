<?php
declare(strict_types=1);

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
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentMetaboxButtonPolicy;
use WallsShop\WDC\Shipments\Application\ShipmentModalRequestMapper;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentAdapter;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentButtonPolicy;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentRegistrationService;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentRepository;

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
	public function __construct( private int $id ) {}
	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
	public function save(): void {}
	public function add_order_note( string $note ): void { $this->notes[] = $note; }
	public function get_order_number(): string { return 'ORDER-' . (string) $this->id; }
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
function yd_framework_request(): ShipmentCreateRequest {
	$item = new PackageItem( 'SKU-A', 'Item A', 1, Money::from_rubles( 100 ), Money::from_rubles( 100 ), 500, 10, 10, 5 );
	return new ShipmentCreateRequest(
		777,
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
			'order_num' => 'ORDER-777',
			'yandex_operator_request_id' => 'ORDER-777',
			'yandex_source_platform_station_id' => 'SRC-1',
			'yandex_ready_from' => '2026-07-12 12:00:00+07:00',
			'yandex_ready_to' => '2026-07-12 12:00:00+07:00',
			'yandex_pickup_platform_station_id' => 'PVZ-1',
			'shipment_item_rows' => array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'SKU-A', 'amount' => 1, 'cost' => 100, 'weight' => 500 ) ),
		)
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
	$mapper = new YandexShipmentPersistenceMapper( $yandex_repository );
	$button_policy = new YandexShipmentButtonPolicy();
	$framework_registration = new YandexShipmentRegistrationService( $core_registration, $payload_builder, $client, $yandex_repository, $mapper, $button_policy );
	$adapter = new YandexShipmentAdapter( $framework_registration, $button_policy );
	$registry = new CarrierShipmentAdapterRegistry( array( $adapter ) );
	$creation = new ShipmentCreationService( $base_repository, array( $adapter ), null, null, $registry, array( $mapper ) );

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
$mapper = new YandexShipmentPersistenceMapper( $yandex_repository );
$button_policy = new YandexShipmentButtonPolicy();
$framework_registration = new YandexShipmentRegistrationService( $core_registration, $payload_builder, $client, $yandex_repository, $mapper, $button_policy );
$adapter = new YandexShipmentAdapter( $framework_registration, $button_policy );
$registry = new CarrierShipmentAdapterRegistry( array( $adapter ) );
$creation = new ShipmentCreationService( $base_repository, array( $adapter ), null, null, $registry, array( $mapper ) );
$order = new YdFrameworkOrder( 777 );
$request = yd_framework_request();

yd_framework_assert( $registry->get( YandexDeliverySettings::CARRIER_KEY ) instanceof YandexShipmentAdapter, 'Yandex adapter must be registered through CarrierShipmentAdapterRegistry.' );
yd_framework_assert( array() === ( new YandexShipmentButtonPolicy() )->resolve( array( 'yandex_request_id' => 'REQ-777', 'yandex_status' => 'CREATED' ) ) ? false : true, 'Yandex button policy must resolve created shipments.' );

$result = $creation->create( $order, $request );
yd_framework_assert( $result->success && 'REQ-777' === $result->external_id && '880191690' === $result->tracking_number, 'ShipmentCreationService must create Yandex shipment through adapter and registration service.' );
$shipment = $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'REQ-777' === (string) ( $shipment['yandex_request_id'] ?? '' ) && 'OFFER-1' === (string) ( $shipment['yandex_selected_offer_id'] ?? '' ) && 'CREATED' === (string) ( $shipment['yandex_status'] ?? '' ), 'Repository must persist request_id, selected_offer_id and Yandex status.' );
yd_framework_assert( '2026-07-11T16:23:01.000000Z' === (string) ( $shipment['yandex_offer_expires_at'] ?? '' ), 'Repository must persist selected offer expires_at.' );
yd_framework_assert( '298.8 RUB' === (string) ( $shipment['yandex_offer_pricing'] ?? '' ) && '298.8 RUB' === (string) ( $shipment['yandex_offer_pricing_total'] ?? '' ) && 29880 === (int) ( $shipment['yandex_offer_pricing_total_kopecks'] ?? 0 ), 'Repository must persist selected offer pricing audit fields.' );
yd_framework_assert( '2026-07-21T07:00:00.000000Z' === (string) ( $shipment['yandex_offer_delivery_interval']['min'] ?? '' ) && '2026-07-13T05:00:00.000000Z' === (string) ( $shipment['yandex_offer_pickup_interval']['max'] ?? '' ) && 'OFFER-1' === (string) ( $shipment['yandex_selected_offer_snapshot']['offer_id'] ?? '' ), 'Repository must persist selected offer interval snapshot audit fields.' );
yd_framework_assert( 'REQ-777' === (string) $order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Successful create must persist Yandex request_id lookup meta.' );
yd_framework_assert( 'YD-REAL-1' === (string) ( $shipment['yandex_place_barcode_map']['ORDER-777-1'] ?? '' ) && 'YD-REAL-1' === (string) ( $shipment['yandex_places'][0]['barcode'] ?? '' ) && 'ITEM-YD-1' === (string) ( $shipment['yandex_items'][0]['barcode'] ?? '' ), 'Repository must persist request/info items, places and temporary-to-real barcode map.' );
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
$active_remove = $adapter->remove_from_order( $order );
yd_framework_assert( empty( $active_remove['success'] ) && 'Текущее отправление Яндекс нельзя удалить из заказа.' === (string) ( $active_remove['message'] ?? '' ) && array() !== $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY ) && 'REQ-777' === (string) $order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Server-side Yandex remove guard must reject active CREATED shipment and keep persistence.' );

$cancel = $adapter->cancel_in_carrier( $order );
yd_framework_assert( ! empty( $cancel['success'] ) && 6 === count( $fake->requests ), 'Yandex cancel must call request/cancel and canonical request/info.' );
$cancel_body = json_decode( (string) ( $fake->requests[4]['args']['body'] ?? '{}' ), true );
yd_framework_assert( 'REQ-777' === (string) ( $cancel_body['request_id'] ?? '' ), 'Yandex cancel must use request_id, not courier_order_id/tracking_number.' );
$cancelled = $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'CANCELLED' === (string) ( $cancelled['yandex_status'] ?? '' ) && 'cancellation_started' === (string) ( $cancelled['yandex_cancel_state']['reason'] ?? '' ), 'Cancel must persist request/info state and async cancel response.' );
yd_framework_assert( 'REQ-777' === (string) $order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Cancel update must keep Yandex request_id lookup meta.' );
$cancel_payload = $adapter->status_payload( $order, $cancelled );
yd_framework_assert( empty( $cancel_payload['can_cancel'] ) && ! empty( $cancel_payload['can_remove_from_order'] ), 'Cancelled Yandex shipment must hide cancel and allow remove.' );

$history = $framework_registration->history( $order );
yd_framework_assert( ! empty( $history['success'] ) && 'SHOP_CANCELLED' === (string) ( $history['events'][1]['reason'] ?? '' ), 'Yandex history must use request/history through the framework service.' );

$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/OrderShipmentDraftFactory.php' );
yd_framework_assert( str_contains( $source, 'create_yandex_request_from_order' ) && str_contains( $source, 'shipment_item_rows' ) && ! str_contains( $source, 'shipment_item_rows_from_rows' ), 'OrderShipmentDraftFactory must provide canonical shipment item rows without a second Yandex parser.' );
$removed_manual_place_capability = 'requires_manual' . '_place_dimensions';
$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
yd_framework_assert( str_contains( $metabox_source, 'button_policy()->resolve' ) && str_contains( $metabox_source, 'data-wdc-open-shipment-modal' ), 'Existing shipment metabox/modal must be reused through shared capability-driven button policy.' );
yd_framework_assert( str_contains( $metabox_source, 'data-wdc-yandex-source-station' ) && str_contains( $metabox_source, 'name="yandex_source_platform_station_id"' ) && str_contains( $metabox_source, 'data-wdc-yandex-pickup-destination' ) && str_contains( $metabox_source, 'name="yandex_pickup_platform_station_id"' ), 'Shared modal must render Yandex source station and pickup destination fields.' );
yd_framework_assert( str_contains( $metabox_source, 'data-wdc-yandex-ready-interval' ) && str_contains( $metabox_source, 'name="yandex_ready_from"' ) && str_contains( $metabox_source, 'name="yandex_ready_to"' ), 'Shared modal must render and submit Yandex ready interval values.' );
yd_framework_assert( str_contains( $metabox_source, '$requires_tariff' ) && str_contains( $metabox_source, 'data-wdc-yandex-offer-note' ) && str_contains( $metabox_source, '$requires_postoffice' ), 'Shared modal must hide tariff/postoffice controls for carriers that do not require them.' );
yd_framework_assert( str_contains( $metabox_source, 'foreach ( $place_rows as $place_index => $place_row )' ) && str_contains( $metabox_source, 'data-wdc-decimal-input="2"' ) && str_contains( $metabox_source, 'places[<?php echo esc_attr( (string) $place_index ); ?>][weight_g]' ), 'Shared modal must render initial places from draft and allow decimal dimensions while keeping weight integer.' );
yd_framework_assert( str_contains( $metabox_source, 'shipment_preview_validation_failed' ) && str_contains( $metabox_source, 'shipment_preview_unexpected_error' ) && str_contains( $metabox_source, 'discard_preview_buffer' ), 'Shipment preview AJAX must return controlled JSON errors instead of leaking HTML output.' );
yd_framework_assert( str_contains( $metabox_source, 'data-wdc-requires-successful-preview' ) && str_contains( $metabox_source, 'shipment_create_validation_failed' ) && str_contains( $metabox_source, 'shipment_create_unexpected_error' ) && str_contains( $metabox_source, 'public_shipment_error_message' ), 'Shipment create AJAX must be JSON-safe and expose the preview-required capability to runtime.' );
yd_framework_assert( str_contains( $metabox_source, 'editable_place_rows' ) && str_contains( $metabox_source, "\$row['weight_g'] = ''" ) && ! str_contains( $metabox_source, $removed_manual_place_capability ), 'Shared modal must clear editable place dimensions for every carrier without carrier-specific capability.' );
yd_framework_assert( str_contains( $metabox_source, 'shipment_attach_validation_failed' ) && str_contains( $metabox_source, 'shipment_attach_unexpected_error' ) && str_contains( $metabox_source, "'request_id' => \$barcode" ), 'Generic manual attach AJAX must stay JSON-safe and pass request_id alias to carrier adapters.' );
yd_framework_assert( str_contains( $metabox_source, 'wdc_mark_shipment_poll_exhausted' ) && str_contains( $metabox_source, 'ajax_mark_poll_exhausted' ) && str_contains( $metabox_source, 'mark_polling_exhausted' ), 'Shared metabox must expose a carrier-neutral AJAX hook to persist exhausted registration polling.' );
yd_framework_assert( str_contains( $metabox_source, "__( '⚖️%d'" ) && ! str_contains( $metabox_source, 'Расчётный вес товаров: %d г' ), 'Shared modal must preserve compact calculated weight hint format.' );
$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.js' );
yd_framework_assert( str_contains( $js_source, 'can_create' ) && str_contains( $js_source, 'can_attach_manual' ) && str_contains( $js_source, 'setVisible(openButton, canCreate)' ) && str_contains( $js_source, 'setVisible(manualButton, canAttachManual)' ), 'Runtime shipment buttons must consume adapter create/manual capabilities.' );
yd_framework_assert( str_contains( $js_source, 'function parseShipmentJsonResponse' ) && str_contains( $js_source, 'Сервер вернул некорректный ответ при подготовке отправления' ) && str_contains( $js_source, '.then(parseShipmentJsonResponse)' ), 'Shipment preview JS must parse malformed responses through controlled JSON fallback.' );
yd_framework_assert( str_contains( $js_source, "form.dataset.wdcRequiresTariff !== '0'" ) && str_contains( $js_source, 'parseDecimalValue(length' ), 'Shipment admin JS must support no-tariff carriers and decimal place dimensions.' );
yd_framework_assert( str_contains( $js_source, "form.dataset.wdcRequiresSuccessfulPreview === '1'" ) && str_contains( $js_source, 'const latestPreviewReady = !requiresSuccessfulPreview' ), 'Shipment admin JS must block create by carrier-neutral preview capability instead of checking a Yandex/DPD branch.' );
yd_framework_assert( str_contains( $js_source, "data.append('barcode', input ? input.value || '' : '')" ) && str_contains( $js_source, 'canAttachManual: Object.prototype.hasOwnProperty.call(statusPayload' ) && str_contains( $js_source, 'manualAttachFieldLabel' ), 'Runtime manual attach must send the generic barcode field and consume adapter manual-attach capability after success.' );
yd_framework_assert( str_contains( $js_source, 'function startShipmentRegistrationPolling' ) && str_contains( $js_source, 'function markShipmentPollingExhausted' ) && str_contains( $js_source, 'markPollExhaustedAction' ) && str_contains( $js_source, 'shipmentPollingTokens' ) && str_contains( $js_source, 'removeConfirmationMessage' ), 'Runtime registration polling must be carrier-neutral, bounded, persist exhaustion and protect stale responses.' );
yd_framework_assert( str_contains( $js_source, 'settings.auto && !isPending' ) && str_contains( $js_source, 'if (settings.pollingToken)' ) && str_contains( $js_source, 'throw error;' ) && str_contains( $js_source, 'stopShipmentRegistrationPolling(box)' ), 'Runtime polling must suppress pending toast spam, propagate transport errors during bounded polling and stop polling before local remove.' );
yd_framework_assert( ! str_contains( $js_source, 'response.json()' ) && ! str_contains( $js_source, 'Unexpected token' ) && ! str_contains( $js_source, 'Server returned' ) && ! str_contains( $js_source, 'DPD registration failed' ), 'Shipment admin runtime must not expose raw JSON parser or English fallback messages.' );

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
yd_framework_assert( ! empty( $cancel_started_yandex_buttons['has_shipment'] ) && empty( $cancel_started_yandex_buttons['show_create'] ) && ! empty( $cancel_started_yandex_buttons['show_update'] ) && empty( $cancel_started_yandex_buttons['show_cancel'] ) && empty( $cancel_started_yandex_buttons['show_remove'] ), 'Metabox capabilities must treat Yandex cancellation_started as existing shipment with status update only.' );

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
yd_framework_assert( array() !== array_filter( $reconciliation_order->notes, static fn ( string $note ): bool => str_contains( $note, 'Отправление Яндекс создано. Request ID: REQ-PENDING. Ожидается получение статуса.' ) ), 'Accepted pending create must write a non-failure order note.' );
$pending_policy = ( new YandexShipmentButtonPolicy() )->resolve( $pending );
yd_framework_assert( empty( $pending_policy['create'] ) && ! empty( $pending_policy['update'] ) && empty( $pending_policy['cancel'] ) && ! empty( $pending_policy['remove'] ) && empty( $pending_policy['manual_attach'] ), 'Reconciliation button policy must allow status update and local remove while blocking create/cancel/manual attach.' );
$pending_payload = $reconciliation_adapter->status_payload( $reconciliation_order, $pending );
yd_framework_assert( ! empty( $pending_payload['polling_continue'] ) && ! empty( $pending_payload['can_remove_from_order'] ) && 5000 === (int) ( $pending_payload['registration_poll_interval_ms'] ?? 0 ) && 14 === (int) ( $pending_payload['registration_poll_max_attempts'] ?? 0 ) && 'Ожидается получение статуса' === (string) ( $pending_payload['carrier_status_title'] ?? '' ), 'Pending Yandex status payload must request bounded 5s x 14 polling, allow local remove and render pending without duplicate label.' );
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
yd_framework_assert( ! empty( $cancel_pending['success'] ) && 'CREATED' === (string) ( $cancel_pending['status'] ?? '' ), 'Async cancel must be accepted even when immediate request/info is still CREATED.' );
$cancel_pending_shipment = $cancel_pending_repository->find_by_carrier( $cancel_pending_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'cancellation_started' === (string) ( $cancel_pending_shipment['status'] ?? '' ) && ! empty( $cancel_pending_shipment['yandex_cancel_requested'] ) && 'cancellation_started' === (string) ( $cancel_pending_shipment['yandex_cancel_reason'] ?? '' ), 'Non-terminal info after cancel must keep local cancellation_started state.' );
$cancel_pending_policy = ( new YandexShipmentButtonPolicy() )->resolve( $cancel_pending_shipment );
yd_framework_assert( empty( $cancel_pending_policy['cancel'] ) && ! empty( $cancel_pending_policy['update'] ) && empty( $cancel_pending_policy['remove'] ), 'Cancel pending button policy must block repeat cancel and local remove.' );
$cancel_pending_remove = $cancel_pending_adapter->remove_from_order( $cancel_pending_order );
yd_framework_assert( empty( $cancel_pending_remove['success'] ) && 'Текущее отправление Яндекс нельзя удалить из заказа.' === (string) ( $cancel_pending_remove['message'] ?? '' ) && array() !== $cancel_pending_repository->find_by_carrier( $cancel_pending_order, YandexDeliverySettings::CARRIER_KEY ), 'Server-side Yandex remove guard must reject cancellation_started shipment.' );
yd_framework_assert( ! str_contains( implode( "\n", $cancel_pending_order->notes ), 'отменено' ), 'Cancel pending note must not claim final cancellation.' );
$cancel_completed = $cancel_pending_adapter->update_status( $cancel_pending_order );
yd_framework_assert( ! empty( $cancel_completed['success'] ) && 'CANCELLED' === (string) ( $cancel_completed['status'] ?? '' ), 'Subsequent update_status must complete async cancellation when request/info returns CANCELLED.' );
$cancel_completed_shipment = $cancel_pending_repository->find_by_carrier( $cancel_pending_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'CANCELLED' === (string) ( $cancel_completed_shipment['yandex_status'] ?? '' ) && empty( $cancel_completed_shipment['yandex_cancel_requested'] ) && ! empty( $cancel_completed_shipment['yandex_cancel_completed'] ), 'Confirmed cancellation must clear active cancel flag and keep Yandex CANCELLED status.' );
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
$preview_mapper = new YandexShipmentPersistenceMapper( $preview_repository );
$preview_adapter = new YandexShipmentAdapter(
	new YandexShipmentRegistrationService( new CoreYandexRegistrationService( new YandexDeliveryShipmentPayloadBuilder(), $preview_client, new YandexDeliveryEarliestOfferSelector() ), new YandexDeliveryShipmentPayloadBuilder(), $preview_client, $preview_repository, $preview_mapper, new YandexShipmentButtonPolicy() ),
	new YandexShipmentButtonPolicy()
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
yd_framework_assert( 'order-item-a' === (string) $admin_rows[0]['item_key'] && 'order-item-b' === (string) $admin_rows[2]['item_key'] && $admin_rows[0]['ware_key'] === $admin_rows[2]['ware_key'], 'Yandex admin data identity must keep same SKU order items distinct by item_key.' );
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

foreach ( array( 'DELIVERED', 'RETURNED', 'RETURNED_TO_SENDER', 'REJECTED' ) as $terminal_status ) {
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
	yd_framework_assert( ! empty( $terminal_cancel['success'] ) && $terminal_status === (string) ( $terminal_cancel['status'] ?? '' ), 'Cancel follow-up info must surface non-CANCELLED terminal status.' );
	yd_framework_assert( 'created' === (string) ( $terminal_shipment['status'] ?? '' ) && $terminal_status === (string) ( $terminal_shipment['yandex_status'] ?? '' ) && empty( $terminal_shipment['yandex_cancel_requested'] ), 'Non-CANCELLED terminal status must close cancellation_started lifecycle.' );
	yd_framework_assert( empty( $terminal_policy['cancel'] ) && ! empty( $terminal_policy['remove'] ), 'Terminal Yandex status must use existing button policy to hide cancel and allow remove.' );
	yd_framework_assert( ! str_contains( implode( "\n", $terminal_order->notes ), 'Отправление Яндекс отменено.' ) && str_contains( implode( "\n", $terminal_order->notes ), 'Получен терминальный статус Яндекс: ' . $terminal_status ), 'Non-CANCELLED terminal cancel resolution must not write successful-cancel note.' );
}

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
yd_framework_assert( 'Статус отправления пока не получен. Повторите обновление статуса позднее.' === (string) ( $attach_presentation['polling_timeout_message'] ?? '' ) && str_contains( (string) ( $attach_presentation['remove_confirmation_message'] ?? '' ), 'не отменит отправление в Яндекс.Доставке' ), 'Yandex presentation must expose Russian polling exhaustion and local-remove warning messages.' );
$attach_result = $attach_adapter->attach_manual( $attach_order, array( 'barcode' => 'REQ-MANUAL' ) );
$attached = $attach_repository->find_by_carrier( $attach_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( ! empty( $attach_result['success'] ) && 1 === count( $attach_http->requests ) && str_contains( $attach_http->requests[0]['url'], '/request/info' ) && str_contains( $attach_http->requests[0]['url'], 'request_id=REQ-MANUAL' ), 'Manual attach must verify Yandex request_id with a single request/info call.' );
yd_framework_assert( ! str_contains( implode( "\n", array_column( $attach_http->requests, 'url' ) ), '/offers/create' ) && ! str_contains( implode( "\n", array_column( $attach_http->requests, 'url' ) ), '/offers/confirm' ), 'Manual attach must not call offers/create or offers/confirm.' );
yd_framework_assert( 'REQ-MANUAL' === (string) ( $attached['yandex_request_id'] ?? '' ) && 'ORDER-777' === (string) ( $attached['yandex_operator_request_id'] ?? '' ) && 'CREATED' === (string) ( $attached['yandex_status'] ?? '' ), 'Manual attach must persist canonical Yandex request/info fields.' );
yd_framework_assert( 'REQ-MANUAL' === (string) $attach_order->get_meta( '_wdc_yandex_delivery_request_id', true ) && 'admin_manual_attach' === (string) ( $attached['created_by_context'] ?? '' ), 'Manual attach must persist lookup meta and admin_manual_attach context.' );
$attached_payload = $attach_adapter->status_payload( $attach_order, $attached );
yd_framework_assert( empty( $attached_payload['can_create'] ) && empty( $attached_payload['can_attach_manual'] ) && ! empty( $attached_payload['can_update_status'] ) && ! empty( $attached_payload['can_cancel'] ), 'Attached CREATED Yandex shipment must hide create/manual attach and expose update/cancel.' );
$duplicate_attach = $attach_adapter->attach_manual( $attach_order, array( 'request_id' => 'REQ-DUPLICATE' ) );
yd_framework_assert( empty( $duplicate_attach['success'] ) && 'По заказу уже сохранено отправление Яндекс.' === (string) ( $duplicate_attach['message'] ?? '' ) && 1 === count( $attach_http->requests ), 'Duplicate manual attach must be blocked before request/info.' );

$wrong_order = new YdFrameworkOrder( 777 );
list( $wrong_repository, $wrong_adapter, $wrong_creation, $wrong_registration, $wrong_http ) = yd_framework_stack( array( yd_framework_response( yd_framework_info( 'REQ-WRONG', 'CREATED', 'YD-WRONG', 'ORDER-999' ) ) ) );
$wrong_attach = $wrong_adapter->attach_manual( $wrong_order, array( 'barcode' => 'REQ-WRONG' ) );
yd_framework_assert( empty( $wrong_attach['success'] ) && 'Отправление Яндекс создано для другого заказа.' === (string) ( $wrong_attach['message'] ?? '' ) && array() === $wrong_repository->find_by_carrier( $wrong_order, YandexDeliverySettings::CARRIER_KEY ) && '' === (string) $wrong_order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Manual attach must reject request/info with mismatched operator_request_id.' );

$missing_operator_order = new YdFrameworkOrder( 777 );
list( $missing_repository, $missing_adapter ) = yd_framework_stack( array( yd_framework_response( yd_framework_info( 'REQ-NO-OP', 'CREATED', 'YD-NO-OP', null ) ) ) );
$missing_attach = $missing_adapter->attach_manual( $missing_operator_order, array( 'barcode' => 'REQ-NO-OP' ) );
yd_framework_assert( empty( $missing_attach['success'] ) && 'Яндекс не вернул номер заказа для проверки принадлежности отправления.' === (string) ( $missing_attach['message'] ?? '' ) && array() === $missing_repository->find_by_carrier( $missing_operator_order, YandexDeliverySettings::CARRIER_KEY ), 'Manual attach must reject request/info without operator_request_id.' );

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

$remove = $adapter->remove_from_order( $order );
yd_framework_assert( ! empty( $remove['success'] ) && '' === (string) $order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Yandex remove_local must delete lookup meta.' );

echo "Yandex delivery shipment framework smoke test passed.\n";
