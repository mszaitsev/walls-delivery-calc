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
function yd_framework_info( string $request_id, string $status, string $real_barcode ): array {
	return array(
		'request_id' => $request_id,
		'request' => array(
			'info' => array( 'operator_request_id' => 'ORDER-777' ),
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
	$framework_registration = new YandexShipmentRegistrationService( $core_registration, $payload_builder, $client, $yandex_repository );
	$adapter = new YandexShipmentAdapter( $framework_registration, new YandexShipmentButtonPolicy() );
	$registry = new CarrierShipmentAdapterRegistry( array( $adapter ) );
	$creation = new ShipmentCreationService( $base_repository, array( $adapter ), null, null, $registry, array( new YandexShipmentPersistenceMapper( $yandex_repository ) ) );

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
$framework_registration = new YandexShipmentRegistrationService( $core_registration, $payload_builder, $client, $yandex_repository );
$adapter = new YandexShipmentAdapter( $framework_registration, new YandexShipmentButtonPolicy() );
$registry = new CarrierShipmentAdapterRegistry( array( $adapter ) );
$creation = new ShipmentCreationService( $base_repository, array( $adapter ), null, null, $registry, array( new YandexShipmentPersistenceMapper( $yandex_repository ) ) );
$order = new YdFrameworkOrder( 777 );
$request = yd_framework_request();

yd_framework_assert( $registry->get( YandexDeliverySettings::CARRIER_KEY ) instanceof YandexShipmentAdapter, 'Yandex adapter must be registered through CarrierShipmentAdapterRegistry.' );
yd_framework_assert( array() === ( new YandexShipmentButtonPolicy() )->resolve( array( 'yandex_request_id' => 'REQ-777', 'yandex_status' => 'CREATED' ) ) ? false : true, 'Yandex button policy must resolve created shipments.' );

$result = $creation->create( $order, $request );
yd_framework_assert( $result->success && 'REQ-777' === $result->external_id && '880191690' === $result->tracking_number, 'ShipmentCreationService must create Yandex shipment through adapter and registration service.' );
$shipment = $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'REQ-777' === (string) ( $shipment['yandex_request_id'] ?? '' ) && 'OFFER-1' === (string) ( $shipment['yandex_selected_offer_id'] ?? '' ) && 'CREATED' === (string) ( $shipment['yandex_status'] ?? '' ), 'Repository must persist request_id, selected_offer_id and Yandex status.' );
yd_framework_assert( '2026-07-11T16:23:01.000000Z' === (string) ( $shipment['yandex_offer_expires_at'] ?? '' ), 'Repository must persist selected offer expires_at.' );
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
$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
yd_framework_assert( str_contains( $metabox_source, 'button_policy()->resolve' ) && str_contains( $metabox_source, 'data-wdc-open-shipment-modal' ), 'Existing shipment metabox/modal must be reused through shared capability-driven button policy.' );
yd_framework_assert( str_contains( $metabox_source, 'data-wdc-yandex-source-station' ) && str_contains( $metabox_source, 'name="yandex_source_platform_station_id"' ) && str_contains( $metabox_source, 'data-wdc-yandex-pickup-destination' ) && str_contains( $metabox_source, 'name="yandex_pickup_platform_station_id"' ), 'Shared modal must render Yandex source station and pickup destination fields.' );
yd_framework_assert( str_contains( $metabox_source, 'data-wdc-yandex-ready-interval' ) && str_contains( $metabox_source, 'name="yandex_ready_from"' ) && str_contains( $metabox_source, 'name="yandex_ready_to"' ), 'Shared modal must render and submit Yandex ready interval values.' );
yd_framework_assert( str_contains( $metabox_source, '$requires_tariff' ) && str_contains( $metabox_source, 'data-wdc-yandex-offer-note' ) && str_contains( $metabox_source, '$requires_postoffice' ), 'Shared modal must hide tariff/postoffice controls for carriers that do not require them.' );
yd_framework_assert( str_contains( $metabox_source, 'foreach ( $place_rows as $place_index => $place_row )' ) && str_contains( $metabox_source, 'data-wdc-decimal-input="2"' ) && str_contains( $metabox_source, 'places[<?php echo esc_attr( (string) $place_index ); ?>][weight_g]' ), 'Shared modal must render initial places from draft and allow decimal dimensions while keeping weight integer.' );
yd_framework_assert( str_contains( $metabox_source, 'shipment_preview_validation_failed' ) && str_contains( $metabox_source, 'shipment_preview_unexpected_error' ) && str_contains( $metabox_source, 'discard_preview_buffer' ), 'Shipment preview AJAX must return controlled JSON errors instead of leaking HTML output.' );
$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.js' );
yd_framework_assert( str_contains( $js_source, 'can_create' ) && str_contains( $js_source, 'can_attach_manual' ) && str_contains( $js_source, 'setVisible(openButton, canCreate)' ) && str_contains( $js_source, 'setVisible(manualButton, canAttachManual)' ), 'Runtime shipment buttons must consume adapter create/manual capabilities.' );
yd_framework_assert( str_contains( $js_source, 'function parseShipmentJsonResponse' ) && str_contains( $js_source, 'Сервер вернул некорректный ответ при подготовке отправления' ) && str_contains( $js_source, '.then(parseShipmentJsonResponse)' ), 'Shipment preview JS must parse malformed responses through controlled JSON fallback.' );
yd_framework_assert( str_contains( $js_source, "form.dataset.wdcRequiresTariff !== '0'" ) && str_contains( $js_source, 'parseDecimalValue(length' ), 'Shipment admin JS must support no-tariff carriers and decimal place dimensions.' );

$metabox_buttons = new ShipmentMetaboxButtonPolicy();
$empty_yandex_payload = $adapter->status_payload( $order, array() );
$empty_yandex_buttons = $metabox_buttons->resolve( YandexDeliverySettings::CARRIER_KEY, array(), $empty_yandex_payload );
yd_framework_assert( ! empty( $empty_yandex_buttons['show_create'] ) && empty( $empty_yandex_buttons['show_manual_attach'] ) && empty( $empty_yandex_buttons['show_update'] ) && empty( $empty_yandex_buttons['show_cancel'] ) && empty( $empty_yandex_buttons['show_remove'] ), 'Metabox capabilities must show only Create for empty Yandex shipment.' );

$created_yandex = array( 'status' => 'created', 'yandex_status' => 'CREATED', 'yandex_request_id' => 'REQ-META-CREATED' );
$created_yandex_buttons = $metabox_buttons->resolve( YandexDeliverySettings::CARRIER_KEY, $created_yandex, $adapter->status_payload( $order, $created_yandex ) );
yd_framework_assert( empty( $created_yandex_buttons['show_create'] ) && ! empty( $created_yandex_buttons['show_update'] ) && ! empty( $created_yandex_buttons['show_cancel'] ) && empty( $created_yandex_buttons['show_remove'] ), 'Metabox capabilities must show update/cancel for active CREATED Yandex shipment.' );

$reconciliation_yandex = array( 'status' => 'reconciliation_required', 'yandex_request_id' => 'REQ-META-PENDING' );
$reconciliation_yandex_buttons = $metabox_buttons->resolve( YandexDeliverySettings::CARRIER_KEY, $reconciliation_yandex, $adapter->status_payload( $order, $reconciliation_yandex ) );
yd_framework_assert( ! empty( $reconciliation_yandex_buttons['has_shipment'] ) && empty( $reconciliation_yandex_buttons['show_create'] ) && ! empty( $reconciliation_yandex_buttons['show_update'] ) && empty( $reconciliation_yandex_buttons['show_cancel'] ) && empty( $reconciliation_yandex_buttons['show_remove'] ), 'Metabox capabilities must treat Yandex reconciliation_required as existing shipment with status update only.' );

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
		yd_framework_error_response( 500, array( 'message' => 'temporary info failure' ) ),
		yd_framework_response( yd_framework_info( 'REQ-PENDING', 'CREATED', 'YD-PENDING-REAL' ) ),
	)
);
$pending_result = $reconciliation_creation->create( $reconciliation_order, yd_framework_request() );
yd_framework_assert( ! $pending_result->success && 'request_info_after_confirm_failed' === $pending_result->error_code, 'Info failure after successful confirm must return failed ShipmentCreateResult for admin feedback.' );
$pending = $reconciliation_repository->find_by_carrier( $reconciliation_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'REQ-PENDING' === (string) ( $pending['yandex_request_id'] ?? '' ) && 'reconciliation_required' === (string) ( $pending['status'] ?? '' ) && ! empty( $pending['yandex_reconciliation_required'] ), 'Confirmed request_id must be persisted as reconciliation_required shipment.' );
yd_framework_assert( 'REQ-PENDING' === (string) $reconciliation_order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Reconciliation pending shipment must persist lookup meta.' );
yd_framework_assert( 'OFFER-PENDING' === (string) ( $pending['yandex_selected_offer_id'] ?? '' ) && '2026-07-11T16:23:01.000000Z' === (string) ( $pending['yandex_offer_expires_at'] ?? '' ), 'Reconciliation pending shipment must keep selected offer fields.' );
$pending_policy = ( new YandexShipmentButtonPolicy() )->resolve( $pending );
yd_framework_assert( empty( $pending_policy['create'] ) && ! empty( $pending_policy['update'] ) && empty( $pending_policy['cancel'] ) && empty( $pending_policy['remove'] ) && empty( $pending_policy['manual_attach'] ), 'Reconciliation button policy must allow only status update.' );
$blocked_pending_duplicate = $reconciliation_creation->create( $reconciliation_order, yd_framework_request() );
yd_framework_assert( ! $blocked_pending_duplicate->success && 'shipment_already_created' === $blocked_pending_duplicate->error_code && 3 === count( $reconciliation_http->requests ), 'Pending reconciliation must block repeat create without repeating confirm.' );
$recovered = $reconciliation_adapter->update_status( $reconciliation_order );
yd_framework_assert( ! empty( $recovered['success'] ) && 4 === count( $reconciliation_http->requests ), 'Reconciliation update_status must call only request/info.' );
$recovered_shipment = $reconciliation_repository->find_by_carrier( $reconciliation_order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'created' === (string) ( $recovered_shipment['status'] ?? '' ) && 'CREATED' === (string) ( $recovered_shipment['yandex_status'] ?? '' ) && empty( $recovered_shipment['yandex_reconciliation_required'] ), 'Successful request/info must convert reconciliation shipment to canonical created state.' );
yd_framework_assert( 'YD-PENDING-REAL' === (string) ( $recovered_shipment['yandex_place_barcode_map']['ORDER-777-1'] ?? '' ) && 'REQ-PENDING' === (string) $reconciliation_order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Reconciliation recovery must persist barcode map and keep lookup meta.' );

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
yd_framework_assert( 1000 <= (int) ( $draft_array['request']['places'][0]['weight_g'] ?? 0 ) && 20 === (int) ( $draft_array['request']['places'][0]['length_cm'] ?? 0 ), 'Yandex modal draft must include populated initial place values.' );
$draft_courier_order = new YdFrameworkOrder( 783 );
$draft_courier_order->update_meta_data( '_wdc_platform_carrier_key', YandexDeliverySettings::CARRIER_KEY );
$draft_courier_order->update_meta_data( '_wdc_platform_rate_id', YandexDeliverySettings::CARRIER_KEY . ':courier' );
$draft_courier_array = $draft_factory->draft_array( $draft_courier_order );
yd_framework_assert( 'Яндекс до двери' === (string) ( $draft_courier_array['services'][0]['title'] ?? '' ) && DeliveryType::COURIER === (string) ( $draft_courier_array['services'][0]['delivery_type'] ?? '' ), 'Yandex courier modal draft must expose one actual courier service variant.' );

$preview_http = new YdFrameworkFakeHttp( array() );
$preview_client = new YandexDeliveryShipmentClient( new YandexDeliveryApiClient( yd_framework_settings(), $preview_http ) );
$preview_adapter = new YandexShipmentAdapter(
	new YandexShipmentRegistrationService( new CoreYandexRegistrationService( new YandexDeliveryShipmentPayloadBuilder(), $preview_client, new YandexDeliveryEarliestOfferSelector() ), new YandexDeliveryShipmentPayloadBuilder(), $preview_client, new YandexShipmentRepository( new OrderShipmentRepository() ) ),
	new YandexShipmentButtonPolicy()
);
$preview_payload = $preview_adapter->build_safe_payload_preview( yd_framework_request() );
yd_framework_assert( array() === $preview_http->requests && empty( $preview_payload['live_api_call'] ), 'Yandex modal payload preview must not call offers/create, confirm or request/info HTTP.' );

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

$remove = $adapter->remove_from_order( $order );
yd_framework_assert( ! empty( $remove['success'] ) && '' === (string) $order->get_meta( '_wdc_yandex_delivery_request_id', true ), 'Yandex remove_local must delete lookup meta.' );

echo "Yandex delivery shipment framework smoke test passed.\n";
