<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCredentials;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSoapClientInterface;
use WallsShop\WDC\Carriers\Dpd\DpdSoapRequest;
use WallsShop\WDC\Carriers\Dpd\DpdSoapResponse;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentPayloadBuilder;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentAdapter;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function dpd_create_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-19 12:00:00'; }
function get_current_user_id(): int { return 77; }
function wp_salt( string $scheme = '' ): string { return 'dpd-create-smoke-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_create_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_create_options'][ $key ] = $value; return true; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }
function wp_unslash( mixed $value ): mixed { return $value; }

final class DpdCreateFakeSoap implements DpdSoapClientInterface {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();
	public array|Throwable $next;

	public function __construct( array|Throwable $next ) {
		$this->next = $next;
	}

	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse {
		$this->calls[] = compact( 'service', 'method', 'payload', 'options' );
		if ( $this->next instanceof Throwable ) {
			throw $this->next;
		}

		return new DpdSoapResponse( true, $this->next, array( 'service' => $service, 'method' => $method, 'wrapper' => $options['wrapper'] ?? '' ) );
	}

	public function is_available(): bool { return true; }
}

final class DpdCreateFakeOrder {
	/** @var array<string,mixed> */
	public array $meta = array();
	/** @var array<int,string> */
	public array $notes = array();
	public int $save_count = 0;

	public function __construct( private int $id ) {}
	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void { $this->save_count++; }
	public function add_order_note( string $message ): void { $this->notes[] = $message; }
}

function dpd_create_settings(): DpdSettings {
	return new DpdSettings( new SettingsRepository(), new EncryptionService() );
}

function dpd_create_request( string $delivery_type = DeliveryType::PICKUP, array $meta = array(), array $places = array() ): ShipmentCreateRequest {
	$base_meta = array(
		'service_key' => DpdSettings::SERVICE_KEY,
		'service_title' => DpdSettings::TITLE,
		'service_code' => 'ECN',
		'tariff_title' => 'DPD Эконом',
		'pickup_city_id' => '49455627',
		'delivery_city_id' => '195300000',
		'pickup_terminal_code' => 'NSK-SENDER',
		'delivery_terminal_code' => DeliveryType::PICKUP === $delivery_type ? 'MSK-RECEIVER' : '',
		'date_pickup' => '2026-06-22',
		'declared_value_rub' => 3000,
		'order_num' => 'WC-660',
		'default_sender_terminal_configured' => true,
		'sender_terminal' => array( 'terminal_code' => 'NSK-SENDER', 'city_name' => 'Новосибирск', 'address' => 'Новосибирск, Складская, 1' ),
		'delivery_terminal' => array( 'terminal_code' => 'MSK-RECEIVER', 'city_name' => 'Москва', 'address' => 'Москва, Тестовая, 1' ),
		'normalization_valid' => DeliveryType::COURIER === $delivery_type,
		'sender_contact_fio' => 'Курьер Иванов',
		'normalized_address' => array(
			'fields' => array(
				'postal_code' => '101000',
				'region' => 'Москва',
				'city' => 'Москва',
				'street' => 'Тестовая',
				'street_type' => 'ул',
				'house' => '9',
				'flat' => '12',
			),
		),
	);
	$address = DeliveryType::PICKUP === $delivery_type
		? new Address( country_code: 'RU', city: 'Москва', raw_address: 'Москва, Тестовая, 1' )
		: new Address( country_code: 'RU', region_name: 'Москва', city: 'Москва', postcode: '101000', raw_address: 'Тестовая, 9' );
	$places = array() !== $places ? $places : array( new ShipmentPlace( 1, 2500, 40, 30, 20, Money::from_kopecks( 0 ), array() ) );

	return new ShipmentCreateRequest(
		660,
		DpdSettings::CARRIER_KEY,
		$delivery_type,
		DpdSettings::CARRIER_KEY . ':' . $delivery_type,
		$address,
		null,
		$places,
		Money::from_rubles( 3000 ),
		false,
		array(),
		array( 'name' => 'Петров Иван', 'phone' => '+79990000000', 'email' => 'buyer@example.test' ),
		array_merge( $base_meta, $meta )
	);
}

$settings = dpd_create_settings();
$settings->save_from_admin( array( DpdSettings::ENVIRONMENT_KEY => DpdSettings::ENV_TEST, DpdSettings::TEST_CLIENT_NUMBER_KEY => '123456', 'dpd_test_client_key' => 'secret', DpdSettings::ORDER_CREATE_TIMEOUT_KEY => 90 ) );
$settings->save_tariff_settings_from_admin( array( DpdSettings::TARIFF_CARGO_CATEGORY_KEY => 'Посуда', DpdSettings::TARIFF_SENDER_NAME_KEY => 'Walls Shop', DpdSettings::TARIFF_SENDER_PHONE_KEY => '+73830000000' ) );
$builder = new DpdShipmentPayloadBuilder( $settings );
dpd_create_assert( method_exists( DpdApiClient::class, 'createOrder2' ), 'DpdApiClient must expose createOrder2().' );
$no_client_result = ( new DpdShipmentAdapter( $builder ) )->create( dpd_create_request() );
dpd_create_assert( ! $no_client_result->success && 'dpd_create_disabled' !== $no_client_result->error_code, 'DPD adapter create() must no longer return dpd_create_disabled.' );

$preview = ( new DpdShipmentAdapter( $builder ) )->build_safe_payload_preview( dpd_create_request() );
dpd_create_assert( 'order2/createOrder2' === (string) $preview['path'] && ! array_key_exists( 'live_api_call', $preview ), 'DPD dry-run preview must not expose live_api_call in preview payload.' );

$success_body = array(
	'orderNumberInternal' => 'WC-660',
	'orderNum' => 'DPD-ORDER-1',
	'requestNumber' => 'REQ-1',
	'status' => 'OK',
	'pickupDate' => '2026-06-22',
	'parcel' => array( array( 'number' => 'PARCEL-1' ), array( 'number' => 'PARCEL-2' ) ),
);
$soap = new DpdCreateFakeSoap( $success_body );
$client = new DpdApiClient( $settings, $soap );
$adapter = new DpdShipmentAdapter( $builder, $client );
$request = dpd_create_request();
$result = $adapter->create( $request );
dpd_create_assert( $result->success && 'DPD-ORDER-1' === $result->tracking_number, 'Successful mocked DPD response must create a successful result.' );
dpd_create_assert( 1 === count( $soap->calls ) && 'order2' === $soap->calls[0]['service'] && 'createOrder2' === $soap->calls[0]['method'] && DpdSoapRequest::WRAPPER_ORDERS === $soap->calls[0]['options']['wrapper'], 'Live create must call order2/createOrder2 with orders wrapper.' );
dpd_create_assert( DpdSettings::DEFAULT_ORDER_CREATE_TIMEOUT === (int) $soap->calls[0]['options']['timeout'], 'createOrder2 must use increased order create timeout.' );
dpd_create_assert( $soap->calls[0]['payload'] === $builder->build( $request ), 'Live create must use the same DpdShipmentPayloadBuilder payload as preview.' );

$pickup_payload = $builder->build( $request );
dpd_create_assert( '2026-06-22' === (string) $pickup_payload['header']['datePickup'] && '123456' === (string) $pickup_payload['header']['payer'], 'Pickup create payload must contain datePickup and header.payer.' );
dpd_create_assert( 'ECN' === (string) $pickup_payload['order']['serviceCode'], 'Pickup create payload must contain serviceCode.' );
dpd_create_assert( 'NSK-SENDER' === (string) $pickup_payload['header']['senderAddress']['terminalCode'] && 'Walls Shop' === (string) $pickup_payload['header']['senderAddress']['name'] && '+73830000000' === (string) $pickup_payload['header']['senderAddress']['contactPhone'] && 'Курьер Иванов' === (string) $pickup_payload['header']['senderAddress']['contactFio'], 'Pickup create payload must contain sender terminalCode/name/phone/contactFio.' );
dpd_create_assert( 'MSK-RECEIVER' === (string) $pickup_payload['order']['receiverAddress']['terminalCode'], 'Pickup create payload must contain receiver terminalCode.' );
dpd_create_assert( 3000.0 === (float) $pickup_payload['order']['cargoValue'] && 1 === (int) $pickup_payload['order']['cargoNumPack'] && 1 === count( $pickup_payload['order']['parcel'] ) && 'Посуда' === (string) $pickup_payload['order']['cargoCategory'], 'Pickup create payload must contain cargoValue, cargoCategory and parcels.' );
$first_parcel = $pickup_payload['order']['parcel'][0] ?? array();
dpd_create_assert( 2.5 === (float) ( $first_parcel['weight'] ?? 0 ) && 40 === (int) ( $first_parcel['length'] ?? 0 ) && 30 === (int) ( $first_parcel['width'] ?? 0 ) && 20 === (int) ( $first_parcel['height'] ?? 0 ), 'createOrder2 payload must contain parcel weight and dimensions.' );
$multi_request = dpd_create_request( DeliveryType::PICKUP, array(), array( new ShipmentPlace( 1, 6700, 38, 24, 24, Money::from_kopecks( 0 ), array() ), new ShipmentPlace( 2, 1200, 20, 15, 10, Money::from_kopecks( 0 ), array() ) ) );
$multi_payload = $builder->build( $multi_request );
dpd_create_assert( 2 === count( $multi_payload['order']['parcel'] ?? array() ) && 2 === (int) $multi_payload['order']['cargoNumPack'], 'Multiple DPD places must produce multiple parcel rows and cargoNumPack.' );
dpd_create_assert( 6.7 === (float) $multi_payload['order']['parcel'][0]['weight'] && 38 === (int) $multi_payload['order']['parcel'][0]['length'] && 24 === (int) $multi_payload['order']['parcel'][0]['width'] && 24 === (int) $multi_payload['order']['parcel'][0]['height'], 'First DPD place dimensions must survive payload clean_array.' );
dpd_create_assert( 1.2 === (float) $multi_payload['order']['parcel'][1]['weight'] && 20 === (int) $multi_payload['order']['parcel'][1]['length'] && 15 === (int) $multi_payload['order']['parcel'][1]['width'] && 10 === (int) $multi_payload['order']['parcel'][1]['height'], 'Second DPD place dimensions must survive payload clean_array.' );
dpd_create_assert( (float) $multi_payload['order']['cargoWeight'] > 0 && (float) $multi_payload['order']['cargoVolume'] > 0, 'DPD cargoWeight and cargoVolume must be calculated from sent places.' );

$courier_payload = $builder->build( dpd_create_request( DeliveryType::COURIER ) );
dpd_create_assert( '2026-06-22' === (string) $courier_payload['header']['datePickup'] && 'ECN' === (string) $courier_payload['order']['serviceCode'], 'Courier create payload must contain datePickup and serviceCode.' );
dpd_create_assert( 'NSK-SENDER' === (string) $courier_payload['header']['senderAddress']['terminalCode'], 'Courier create payload must contain sender terminalCode.' );
dpd_create_assert( ! isset( $courier_payload['order']['receiverAddress']['addressString'] ) && 'Тестовая' === (string) $courier_payload['order']['receiverAddress']['street'] && '9' === (string) $courier_payload['order']['receiverAddress']['house'], 'Courier create payload must contain structured address and no addressString.' );
dpd_create_assert( ! isset( $courier_payload['order']['receiverAddress']['terminalCode'] ), 'Courier create payload must not contain receiver terminalCode.' );


$timeout_soap = new DpdCreateFakeSoap( new \WallsShop\WDC\Carriers\Dpd\DpdException( 'DPD SOAP request failed: Error Fetching http headers' ) );
$timeout_response = ( new DpdApiClient( $settings, $timeout_soap ) )->createOrder2( $builder->build( $request ) );
dpd_create_assert( empty( $timeout_response['success'] ) && 'dpd_order_create_uncertain' === (string) $timeout_response['error_code'] && str_contains( (string) $timeout_response['error_message'], 'DPD не вернул ответ вовремя' ), 'SOAP Error Fetching http headers must return uncertain-result manager message.' );
$settings->add_courier_contact_fio_history( '' );
$settings->add_courier_contact_fio_history( 'Курьер Иванов' );
$settings->add_courier_contact_fio_history( 'Курьер Иванов' );
$settings->add_courier_contact_fio_history( 'Курьер Петров' );
dpd_create_assert( array( 'Курьер Петров', 'Курьер Иванов' ) === $settings->courier_contact_fio_history(), 'contactFio history must ignore empty values and duplicates.' );
$settings->remove_courier_contact_fio_history( 'Курьер Иванов' );
dpd_create_assert( array( 'Курьер Петров' ) === $settings->courier_contact_fio_history(), 'contactFio history must remove one selected value.' );
$invalid_soap = new DpdCreateFakeSoap( $success_body );
$invalid = ( new DpdShipmentAdapter( $builder, new DpdApiClient( $settings, $invalid_soap ) ) )->create( dpd_create_request( DeliveryType::PICKUP, array( 'date_pickup' => '' ) ) );
dpd_create_assert( ! $invalid->success && 'dpd_validation_failed' === $invalid->error_code && 0 === count( $invalid_soap->calls ), 'Missing required fields must block create before SOAP call.' );

$repository = new OrderShipmentRepository();
$order = new DpdCreateFakeOrder( 660 );
$creation = new ShipmentCreationService( $repository, array( $adapter ) );
$created = $creation->create( $order, $request );
$stored = $repository->find_by_carrier( $order, DpdSettings::CARRIER_KEY );
dpd_create_assert( $created->success && array() !== $stored, 'Successful mocked DPD response must create shipment record.' );
dpd_create_assert( 'DPD-ORDER-1' === (string) $stored['dpd_order_number'] && 'REQ-1' === (string) $stored['dpd_request_number'], 'DPD order/request number must be saved.' );
dpd_create_assert( array( 'PARCEL-1', 'PARCEL-2' ) === $stored['dpd_parcel_numbers'], 'DPD parcel numbers must be saved if present.' );
dpd_create_assert( 'pending_creation_in_carrier' === (string) $stored['status'] && 'admin_manual' === (string) $stored['created_by_context'] && 77 === (int) $stored['created_by'], 'DPD shipment must be saved with technical status and admin marker.' );
$duplicate = $creation->create( $order, $request );
dpd_create_assert( ! $duplicate->success && 'shipment_already_created' === $duplicate->error_code && 'DPD отправление уже создано для этого заказа.' === $duplicate->error_message, 'Duplicate active DPD shipment must block second create.' );

$error_soap = new DpdCreateFakeSoap( array( 'orderNumberInternal' => 'WC-661', 'status' => 'Error', 'errorMessage' => 'Не заполнен параметр Улица' ) );
$error_creation = new ShipmentCreationService( new OrderShipmentRepository(), array( new DpdShipmentAdapter( $builder, new DpdApiClient( $settings, $error_soap ) ) ) );
$error_order = new DpdCreateFakeOrder( 660 );
$error_result = $error_creation->create( $error_order, dpd_create_request() );
dpd_create_assert( ! $error_result->success && 'dpd_business_error' === $error_result->error_code, 'DPD API business error must be shown as create failure.' );
dpd_create_assert( array() === ( new OrderShipmentRepository() )->find_by_carrier( $error_order, DpdSettings::CARRIER_KEY ), 'DPD API error must not create shipment record.' );

$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$cdek_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Cdek/CdekShipmentAdapter.php' );
$russian_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/RussianPost/RussianPostShipmentAdapter.php' );
dpd_create_assert( str_contains( $cdek_source, 'registerOrder' ) && str_contains( $plugin_source, 'CdekShipmentAdapter::class' ), 'CDEK create flow must remain wired.' );
dpd_create_assert( str_contains( $russian_source, 'create( ShipmentCreateRequest $request )' ) && str_contains( $plugin_source, 'RussianPostShipmentAdapter::class' ), 'Russian Post flow must remain wired.' );
dpd_create_assert( ! str_contains( $plugin_source, 'wdc_dpd_auto_create' ) && ! str_contains( $plugin_source, 'dpd_auto_create' ), 'No DPD auto-create hooks may exist.' );

echo "DPD create order smoke passed\n";
