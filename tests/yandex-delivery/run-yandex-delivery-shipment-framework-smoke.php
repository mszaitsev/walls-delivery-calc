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

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryEarliestOfferSelector;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentClient;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentPayloadBuilder;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentRegistrationService as CoreYandexRegistrationService;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentAdapter;
use WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentButtonPolicy;
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
			'yandex_item_rows' => array( array( 'item_key' => '101', 'ordered_quantity' => 1, 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'SKU-A', 'amount' => 1, 'cost' => 100, 'weight' => 500 ) ),
		)
	);
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
$creation = new ShipmentCreationService( $base_repository, array( $adapter ), null, null, $registry );
$order = new YdFrameworkOrder( 777 );
$request = yd_framework_request();

yd_framework_assert( $registry->get( YandexDeliverySettings::CARRIER_KEY ) instanceof YandexShipmentAdapter, 'Yandex adapter must be registered through CarrierShipmentAdapterRegistry.' );
yd_framework_assert( array() === ( new YandexShipmentButtonPolicy() )->resolve( array( 'yandex_request_id' => 'REQ-777', 'yandex_status' => 'CREATED' ) ) ? false : true, 'Yandex button policy must resolve created shipments.' );

$result = $creation->create( $order, $request );
yd_framework_assert( $result->success && 'REQ-777' === $result->external_id && '880191690' === $result->tracking_number, 'ShipmentCreationService must create Yandex shipment through adapter and registration service.' );
$shipment = $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'REQ-777' === (string) ( $shipment['yandex_request_id'] ?? '' ) && 'OFFER-1' === (string) ( $shipment['yandex_selected_offer_id'] ?? '' ) && 'CREATED' === (string) ( $shipment['yandex_status'] ?? '' ), 'Repository must persist request_id, selected_offer_id and Yandex status.' );
yd_framework_assert( 'YD-REAL-1' === (string) ( $shipment['yandex_place_barcode_map']['ORDER-777-1'] ?? '' ) && 'YD-REAL-1' === (string) ( $shipment['yandex_places'][0]['barcode'] ?? '' ) && 'ITEM-YD-1' === (string) ( $shipment['yandex_items'][0]['barcode'] ?? '' ), 'Repository must persist request/info items, places and temporary-to-real barcode map.' );
yd_framework_assert( array() === (array) ( $shipment['request_snapshot']['body'] ?? array( 'not-empty' ) ) && is_array( $shipment['response_snapshot'] ?? null ), 'Yandex persistence must not store offers/create payload body and must store canonical request/info snapshot.' );
yd_framework_assert( $base_repository->has_created_for_carrier( $order, YandexDeliverySettings::CARRIER_KEY ), 'Existing repository duplicate guard must see created Yandex shipment.' );

$duplicate = $creation->create( $order, $request );
yd_framework_assert( ! $duplicate->success && 'shipment_already_created' === $duplicate->error_code && 3 === count( $fake->requests ), 'Repeat Yandex registration must be blocked by ShipmentCreationService without HTTP.' );

$status = $adapter->update_status( $order );
yd_framework_assert( ! empty( $status['success'] ) && 4 === count( $fake->requests ), 'Yandex status update must call request/info through adapter.' );
$payload = $adapter->status_payload( $order, $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY ) );
yd_framework_assert( ! empty( $payload['can_update_status'] ) && ! empty( $payload['can_cancel'] ) && empty( $payload['can_attach_manual'] ), 'Yandex button policy must expose status/cancel and hide manual attach.' );

$cancel = $adapter->cancel_in_carrier( $order );
yd_framework_assert( ! empty( $cancel['success'] ) && 6 === count( $fake->requests ), 'Yandex cancel must call request/cancel and canonical request/info.' );
$cancelled = $base_repository->find_by_carrier( $order, YandexDeliverySettings::CARRIER_KEY );
yd_framework_assert( 'CANCELLED' === (string) ( $cancelled['yandex_status'] ?? '' ) && 'cancellation_started' === (string) ( $cancelled['yandex_cancel_state']['reason'] ?? '' ), 'Cancel must persist request/info state and async cancel response.' );
$cancel_payload = $adapter->status_payload( $order, $cancelled );
yd_framework_assert( empty( $cancel_payload['can_cancel'] ) && ! empty( $cancel_payload['can_remove_from_order'] ), 'Cancelled Yandex shipment must hide cancel and allow remove.' );

$history = $framework_registration->history( $order );
yd_framework_assert( ! empty( $history['success'] ) && 'SHOP_CANCELLED' === (string) ( $history['events'][1]['reason'] ?? '' ), 'Yandex history must use request/history through the framework service.' );

$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/OrderShipmentDraftFactory.php' );
yd_framework_assert( str_contains( $source, 'create_yandex_request_from_order' ) && str_contains( $source, 'yandex_item_rows' ), 'OrderShipmentDraftFactory must provide Yandex ShipmentCreateRequest data.' );
$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
yd_framework_assert( str_contains( $metabox_source, 'can_attach_manual' ) && str_contains( $metabox_source, 'data-wdc-open-shipment-modal' ), 'Existing shipment metabox/modal must be reused and respect carrier button policy.' );

echo "Yandex delivery shipment framework smoke test passed.\n";
