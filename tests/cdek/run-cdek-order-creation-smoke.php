<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiResponse;
use WallsShop\WDC\Carriers\Cdek\Api\CdekHttpClientInterface;
use WallsShop\WDC\Carriers\Cdek\Api\CdekOAuthTokenService;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffRepository;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticApiClient;
use WallsShop\WDC\Carriers\RussianPost\Tracking\RussianPostTrackingApiClient;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Pickup\PickupPointSelection;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Admin\OrderShipmentsMetabox;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Cdek\CdekCreateRequestBuilder;
use WallsShop\WDC\Shipments\Cdek\CdekOrderStatusService;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentAdapter;
use WallsShop\WDC\Shipments\RussianPost\RussianPostTrackingStatusMapper;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function cdek_order_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-13 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'cdek-order-smoke-' . $scheme; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_cdek_order_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_cdek_order_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_cdek_order_options'][ $key ] ); return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_cdek_order_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['wdc_cdek_order_transients'][ $key ] = $value; return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_cdek_order_transients'][ $key ] ); return true; }
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( mixed $value ): string { return filter_var( trim( (string) $value ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $value ) : ''; }
function wp_unslash( mixed $value ): mixed { return $value; }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function esc_attr__( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
	$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}
function disabled( mixed $disabled, mixed $current = true, bool $display = true ): string {
	$result = (bool) $disabled === (bool) $current ? ' disabled="disabled"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}
function current_user_can( string $capability ): bool { return true; }
function check_ajax_referer( string $action, string|bool $query_arg = false, bool $stop = true ): bool { return true; }
function wc_get_order( int $order_id ): ?object { return $GLOBALS['wdc_cdek_order_ajax_order'] ?? null; }
function wp_send_json_success( mixed $data = null, int $status_code = 200, int $flags = 0 ): never { throw new CdekOrderAjaxResponse( true, $data, $status_code ); }
function wp_send_json_error( mixed $data = null, int $status_code = 400, int $flags = 0 ): never { throw new CdekOrderAjaxResponse( false, $data, $status_code ); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $cdek_tariffs = array();
	}
}

final class CdekOrderAjaxResponse extends RuntimeException {
	public function __construct(
		public bool $success,
		public mixed $data,
		public int $status_code
	) {
		parent::__construct( 'ajax response' );
	}
}

final class CdekOrderFakeHttp implements CdekHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	/** @var array<int,array<string,mixed>> */
	public array $post_responses = array();
	/** @var array<int,array<string,mixed>> */
	public array $order_responses = array();
	/** @var array<int,array<string,mixed>> */
	public array $delete_responses = array();

	public function request( string $method, string $url, array $args = array() ): CdekApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		if ( str_contains( $url, '/v2/oauth/token' ) ) {
			return new CdekApiResponse( 200, json_encode( array( 'access_token' => 'token', 'expires_in' => 3600 ) ) ?: '{}' );
		}
		if ( 'POST' === $method && str_contains( $url, '/v2/orders' ) ) {
			$response = array_shift( $this->post_responses ) ?: array(
				'entity' => array( 'uuid' => 'order-uuid-1', 'recipient' => array( 'name' => 'Иван Иванов', 'email' => 'buyer@example.com' ) ),
				'requests' => array( array( 'request_uuid' => 'request-uuid-1', 'state' => 'ACCEPTED' ) ),
			);
			return new CdekApiResponse( 202, json_encode( $response ) ?: '{}' );
		}
		if ( 'DELETE' === $method && str_contains( $url, '/v2/orders/' ) ) {
			$response = array_shift( $this->delete_responses ) ?: array(
				'entity' => array( 'uuid' => 'deleted-uuid' ),
				'requests' => array( array( 'request_uuid' => 'delete-request-uuid', 'state' => 'ACCEPTED' ) ),
			);
			return new CdekApiResponse( 202, json_encode( $response ) ?: '{}' );
		}
		$response = array_shift( $this->order_responses ) ?: array( 'entity' => array( 'uuid' => 'order-uuid-1', 'cdek_number' => '100500', 'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
		return new CdekApiResponse( 200, json_encode( $response ) ?: '{}' );
	}
}

final class CdekOrderFakeOrder {
	public array $meta = array();
	public array $notes = array();
	public function __construct( private int $id = 101 ) {}
	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
	public function add_order_note( string $message ): void { $this->notes[] = $message; }
	public function get_order_number(): string { return 'WC-' . $this->id; }
	public function get_shipping_first_name(): string { return 'Иван'; }
	public function get_shipping_last_name(): string { return 'Иванов'; }
	public function get_billing_first_name(): string { return 'Иван'; }
	public function get_billing_last_name(): string { return 'Иванов'; }
	public function get_billing_phone(): string { return '9131234567'; }
	public function get_billing_email(): string { return 'buyer@example.com'; }
	public function get_shipping_postcode(): string { return '650000'; }
	public function get_shipping_state(): string { return 'Кемеровская область'; }
	public function get_shipping_city(): string { return 'Кемерово'; }
	public function get_shipping_address_1(): string { return 'Советский 10'; }
	public function get_shipping_address_2(): string { return ''; }
	public function get_items(): array { return array(); }
}

function cdek_order_request( string $delivery_type, int $mode, array $overrides = array() ): ShipmentCreateRequest {
	$item = new PackageItem( 'SKU-1', 'Товар', 5, Money::from_rubles( $overrides['unit_cost'] ?? 1000 ), Money::from_rubles( ( $overrides['unit_cost'] ?? 1000 ) * 5 ), 100, 10, 8, 3 );
	$place = new ShipmentPlace( 1, (int) ( $overrides['place_weight'] ?? 1000 ), 20, 15, 10, Money::from_kopecks( 0 ), array( $item ) );
	$pickup = DeliveryType::PICKUP === $delivery_type ? new PickupPointSelection( CdekSettings::CARRIER_KEY, CdekSettings::SERVICE_KEY, 'KEM7', 'Kemerovo', '2026-06-13 12:00:00' ) : null;
	return new ShipmentCreateRequest(
		101,
		CdekSettings::CARRIER_KEY,
		$delivery_type,
		'cdek:' . $delivery_type . ':136',
		new Address( country_code: 'RU', region_name: 'Кемеровская область', city: 'Кемерово', postcode: '650000', raw_address: DeliveryType::COURIER === $delivery_type ? '650000, Кемерово, Советский 10' : 'KEM7' ),
		$pickup,
		array( $place ),
		Money::from_kopecks( 0 ),
		false,
		array(),
		array( 'name' => $overrides['name'] ?? 'Иван Иванов', 'phone' => $overrides['phone'] ?? '9131234567', 'email' => 'buyer@example.com' ),
		array(
			'service_key' => CdekSettings::SERVICE_KEY,
			'order_num' => 'WC-101',
			'tariff_code' => $overrides['tariff_code'] ?? '136',
			'tariff_title' => $overrides['tariff_title'] ?? 'Посылка склад-склад',
			'delivery_mode' => $mode,
			'delivery_point' => $overrides['delivery_point'] ?? ( DeliveryType::PICKUP === $delivery_type ? 'KEM7' : '' ),
			'cdek_to_city_code' => 44,
			'cdek_item_rows' => $overrides['cdek_item_rows'] ?? array(
				array( 'item_key' => '1', 'ordered_quantity' => 5, 'place_number' => 1, 'name' => 'Товар', 'ware_key' => 'SKU-1', 'amount' => 5, 'cost' => $overrides['unit_cost'] ?? 1000, 'weight' => 100 ),
			),
		)
	);
}

$GLOBALS['wdc_cdek_order_options'] = array();
$GLOBALS['wdc_cdek_order_transients'] = array();
$settings = new CdekSettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin(
	array(
		CdekSettings::ENVIRONMENT_KEY => CdekSettings::ENV_TEST,
		CdekSettings::TEST_ACCOUNT_KEY => 'account',
		'cdek_test_secure_password' => 'secret',
		CdekSettings::SENDER_CITY_CODE_KEY => 270,
		CdekSettings::SENDER_POSTAL_CODE_KEY => '630000',
		CdekSettings::SENDER_CITY_NAME_KEY => 'Новосибирск',
		CdekSettings::SENDER_ADDRESS_KEY => 'Фабричная 1',
		CdekSettings::SHIPMENT_POINT_KEY => 'NSK69',
	)
);
$builder = new CdekCreateRequestBuilder( $settings );

$pickup_payload = $builder->build( cdek_order_request( DeliveryType::PICKUP, 4 ) );
cdek_order_assert( 1 === $pickup_payload['type'] && 'WC-101' === $pickup_payload['number'] && 136 === $pickup_payload['tariff_code'], 'CDEK pickup payload must include type, number and tariff_code.' );
cdek_order_assert( 'NSK69' === $pickup_payload['shipment_point'] && 'KEM7' === $pickup_payload['delivery_point'], 'CDEK pickup payload must use shipment_point and delivery_point.' );
cdek_order_assert( ! isset( $pickup_payload['from_location'], $pickup_payload['to_location'], $pickup_payload['services'], $pickup_payload['additional_order_types'], $pickup_payload['delivery_recipient_cost'], $pickup_payload['delivery_recipient_cost_adv'] ), 'CDEK pickup payload must omit forbidden fields.' );
cdek_order_assert( 'BARCODE' === $pickup_payload['print'], 'CDEK order payload must request BARCODE print.' );
cdek_order_assert( 0 === $pickup_payload['packages'][0]['items'][0]['payment']['value'] && 1000.0 === $pickup_payload['packages'][0]['items'][0]['cost'], 'CDEK item payment/cost mismatch.' );

$courier_payload = $builder->build( cdek_order_request( DeliveryType::COURIER, 3 ) );
cdek_order_assert( isset( $courier_payload['shipment_point'], $courier_payload['to_location'] ) && ! isset( $courier_payload['delivery_point'], $courier_payload['from_location'] ), 'Warehouse-door courier must use shipment_point and to_location only.' );
$door_payload = $builder->build( cdek_order_request( DeliveryType::COURIER, 1 ) );
cdek_order_assert( isset( $door_payload['from_location'], $door_payload['to_location'] ) && ! isset( $door_payload['shipment_point'], $door_payload['delivery_point'] ), 'Door-door courier must use from_location and to_location only.' );

cdek_order_assert( array() !== $builder->validate( cdek_order_request( DeliveryType::PICKUP, 4, array( 'phone' => '' ) ) ), 'Missing phone must fail validation.' );
cdek_order_assert( array() !== $builder->validate( cdek_order_request( DeliveryType::PICKUP, 4, array( 'tariff_code' => '' ) ) ), 'Missing tariff_code must fail validation.' );
cdek_order_assert( array() !== $builder->validate( cdek_order_request( DeliveryType::PICKUP, 4, array( 'delivery_point' => '' ) ) ), 'Missing delivery_point for pickup must fail validation.' );
cdek_order_assert( array() !== $builder->validate( cdek_order_request( DeliveryType::COURIER, 1, array( 'place_weight' => 100 ) ) ), 'Package weight below item weight must fail validation.' );
$too_many = array_fill( 0, 127, array( 'item_key' => 'x', 'ordered_quantity' => 1, 'place_number' => 1, 'name' => 'T', 'ware_key' => 'W', 'amount' => 1, 'cost' => 1, 'weight' => 1 ) );
cdek_order_assert( array() !== $builder->validate( cdek_order_request( DeliveryType::PICKUP, 4, array( 'cdek_item_rows' => $too_many ) ) ), 'More than 126 item rows must fail validation.' );

$split = CdekCreateRequestBuilder::split_item_rows( array( array( 'item_key' => 'A', 'ordered_quantity' => 5, 'amount' => 5 ) ), 'A', 1 );
cdek_order_assert( 4 === (int) $split[0]['amount'] && 1 === (int) $split[1]['amount'], 'Split must create original 4 + duplicate 1.' );
$split[1]['amount'] = 2;
$split = CdekCreateRequestBuilder::rebalance_split_rows( $split );
cdek_order_assert( 3 === (int) $split[0]['amount'] && 2 === (int) $split[1]['amount'], 'Changing duplicate to 2 must make original 3.' );

$http = new CdekOrderFakeHttp();
$client = new CdekApiClient( new CdekOAuthTokenService( $settings, $http ), $settings, $http );
$repository = new OrderShipmentRepository();
$creation = new ShipmentCreationService( $repository, array( new CdekShipmentAdapter( $client, $builder ) ) );
$order = new CdekOrderFakeOrder();
$result = $creation->create( $order, cdek_order_request( DeliveryType::PICKUP, 4 ) );
cdek_order_assert( $result->success, 'CDEK POST /v2/orders must be accepted.' );
$stored = $repository->find_by_carrier( $order, CdekSettings::CARRIER_KEY );
cdek_order_assert( 'registration_pending' === (string) $stored['status'] && 'order-uuid-1' === (string) $stored['external_id'], 'Accepted CDEK order must be stored as registration_pending with UUID.' );
$request_snapshot_json = json_encode( $stored['request_snapshot'], JSON_UNESCAPED_UNICODE ) ?: '';
$response_snapshot_json = json_encode( $stored['response_snapshot'], JSON_UNESCAPED_UNICODE ) ?: '';
cdek_order_assert( ! str_contains( $request_snapshot_json, 'Иван Иванов' ) && ! str_contains( $request_snapshot_json, '+79131234567' ) && ! str_contains( $request_snapshot_json, 'buyer@example.com' ), 'CDEK request snapshot must redact recipient PII.' );
cdek_order_assert( ! str_contains( $response_snapshot_json, 'Иван Иванов' ) && ! str_contains( $response_snapshot_json, 'buyer@example.com' ), 'CDEK response snapshot must not keep recipient PII.' );
$blocked = $creation->create( $order, cdek_order_request( DeliveryType::PICKUP, 4 ) );
cdek_order_assert( ! $blocked->success && 'shipment_already_created' === $blocked->error_code, 'Repeated CDEK creation must be blocked while pending.' );

$http_post_invalid = new CdekOrderFakeHttp();
$http_post_invalid->post_responses[] = array( 'entity' => array( 'uuid' => 'invalid-uuid' ), 'requests' => array( array( 'request_uuid' => 'invalid-request-uuid', 'state' => 'INVALID', 'errors' => array( array( 'code' => 'v2_bad', 'message' => 'bad request' ) ) ) ) );
$invalid_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $http_post_invalid ), $settings, $http_post_invalid );
$invalid_repository = new OrderShipmentRepository();
$invalid_creation = new ShipmentCreationService( $invalid_repository, array( new CdekShipmentAdapter( $invalid_client, $builder ) ) );
$invalid_post_order = new CdekOrderFakeOrder();
$invalid_post_result = $invalid_creation->create( $invalid_post_order, cdek_order_request( DeliveryType::PICKUP, 4 ) );
cdek_order_assert( ! $invalid_post_result->success && 'cdek_registration_invalid' === $invalid_post_result->error_code, 'POST /v2/orders INVALID must fail ShipmentCreateResult.' );
cdek_order_assert( array() === $invalid_repository->find_by_carrier( $invalid_post_order, CdekSettings::CARRIER_KEY ), 'POST /v2/orders INVALID must not be stored as registration_pending.' );

$status = new CdekOrderStatusService( $repository, $client );
$created = $status->update( $order );
cdek_order_assert( $created['success'] && 'registered' === (string) $repository->find_by_carrier( $order, CdekSettings::CARRIER_KEY )['status'], 'GET /v2/orders CREATED must register shipment.' );

$successful_without_status_order = new CdekOrderFakeOrder( 115 );
$repository->save_for_carrier( $successful_without_status_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'successful-empty-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-115' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'successful-empty-uuid', 'statuses' => array() ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$successful_without_status = $status->update( $successful_without_status_order );
cdek_order_assert( 'registration_pending' === (string) $repository->find_by_carrier( $successful_without_status_order, CdekSettings::CARRIER_KEY )['status'], 'CDEK request_state SUCCESSFUL without order statuses must remain registration_pending.' );
cdek_order_assert( 'SUCCESSFUL' === (string) ( $successful_without_status['status']['carrier_status_title'] ?? '' ), 'CDEK request state may be displayed only when no order status exists.' );
cdek_order_assert( empty( $successful_without_status['terminal'] ), 'CDEK SUCCESSFUL without order statuses must keep polling active.' );

$successful_accepted_order = new CdekOrderFakeOrder( 121 );
$repository->save_for_carrier( $successful_accepted_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'successful-accepted-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-121' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'successful-accepted-uuid', 'statuses' => array( array( 'code' => 'ACCEPTED', 'name' => 'Принят', 'date_time' => '2026-06-13T05:48:42+0000' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$successful_accepted = $status->update( $successful_accepted_order );
$successful_accepted_shipment = $repository->find_by_carrier( $successful_accepted_order, CdekSettings::CARRIER_KEY );
cdek_order_assert( 'registration_pending' === (string) ( $successful_accepted_shipment['status'] ?? '' ) && 'регистрация' === (string) ( $successful_accepted['status']['shipment_status_label'] ?? '' ), 'CDEK order status ACCEPTED must remain registration_pending internally.' );
cdek_order_assert( empty( $successful_accepted['terminal'] ) && empty( $successful_accepted['status']['can_remove_from_order'] ), 'CDEK order status ACCEPTED must keep polling active and forbid local remove.' );

$successful_created_order = new CdekOrderFakeOrder( 116 );
$repository->save_for_carrier( $successful_created_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'successful-created-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-116' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'successful-created-uuid', 'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$successful_created = $status->update( $successful_created_order );
cdek_order_assert( 'registered' === (string) $repository->find_by_carrier( $successful_created_order, CdekSettings::CARRIER_KEY )['status'] && 'CREATED' === (string) ( $successful_created['status']['order_status_code'] ?? '' ), 'CDEK request_state SUCCESSFUL with CREATED order status must become registered.' );
cdek_order_assert( ! empty( $successful_created['terminal'] ) && ! empty( $successful_created['status']['can_cancel'] ) && empty( $successful_created['status']['can_remove_from_order'] ), 'CDEK order status CREATED must be terminal, cancellable and protected from local remove.' );

$successful_invalid_order = new CdekOrderFakeOrder( 122 );
$repository->save_for_carrier( $successful_invalid_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'successful-invalid-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-122' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'successful-invalid-uuid', 'statuses' => array( array( 'code' => 'INVALID', 'name' => 'Некорректный заказ', 'date_time' => '2026-06-13T05:48:45+0000' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$successful_invalid = $status->update( $successful_invalid_order );
cdek_order_assert( 'failed' === (string) $repository->find_by_carrier( $successful_invalid_order, CdekSettings::CARRIER_KEY )['status'] && ! empty( $successful_invalid['terminal'] ), 'CDEK order status INVALID must become failed and terminal.' );
cdek_order_assert( ! empty( $successful_invalid['status']['can_remove_from_order'] ) && 'Заказ СДЭК некорректен.' === (string) ( $successful_invalid['message'] ?? '' ), 'CDEK order status INVALID must allow local remove and use invalid-order message.' );

$successful_removed_order = new CdekOrderFakeOrder( 123 );
$repository->save_for_carrier( $successful_removed_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'successful-removed-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-123' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'successful-removed-uuid', 'statuses' => array( array( 'code' => 'REMOVED', 'name' => 'Удален', 'date_time' => '2026-06-13T05:48:46+0000' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$successful_removed = $status->update( $successful_removed_order );
cdek_order_assert( 'removed' === (string) $repository->find_by_carrier( $successful_removed_order, CdekSettings::CARRIER_KEY )['status'] && ! empty( $successful_removed['terminal'] ), 'CDEK order status REMOVED must become removed and terminal.' );
cdek_order_assert( ! empty( $successful_removed['status']['can_remove_from_order'] ) && 'удалено' === (string) ( $successful_removed['status']['shipment_status_label'] ?? '' ), 'CDEK order status REMOVED must allow local remove and render removed internal label.' );

$successful_ready_order = new CdekOrderFakeOrder( 117 );
$repository->save_for_carrier( $successful_ready_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'successful-ready-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-117' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'successful-ready-uuid', 'statuses' => array( array( 'code' => 'READY_FOR_SHIPMENT_IN_SENDER_CITY', 'name' => 'Готов к отправке', 'date_time' => '2026-06-13T10:04:41+0000' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$successful_ready = $status->update( $successful_ready_order );
cdek_order_assert( 'registered' === (string) $repository->find_by_carrier( $successful_ready_order, CdekSettings::CARRIER_KEY )['status'] && 'READY_FOR_SHIPMENT_IN_SENDER_CITY' === (string) ( $successful_ready['status']['order_status_code'] ?? '' ), 'CDEK later real order status must keep shipment registered and display order status.' );
cdek_order_assert( ! empty( $successful_ready['terminal'] ) && ! empty( $successful_ready['status']['can_remove_from_order'] ) && empty( $successful_ready['status']['can_cancel'] ), 'CDEK operational statuses must be terminal registered shipments, removable locally and not cancellable in CDEK.' );

$accepted_without_status_order = new CdekOrderFakeOrder( 118 );
$repository->save_for_carrier( $accepted_without_status_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'accepted-empty-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-118' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'accepted-empty-uuid', 'statuses' => array() ), 'requests' => array( array( 'state' => 'ACCEPTED' ) ) );
$accepted_without_status = $status->update( $accepted_without_status_order );
cdek_order_assert( 'registration_pending' === (string) $repository->find_by_carrier( $accepted_without_status_order, CdekSettings::CARRIER_KEY )['status'] && 'ACCEPTED' === (string) ( $accepted_without_status['status']['carrier_status_title'] ?? '' ), 'CDEK request_state ACCEPTED without order status must remain registration_pending.' );

$successful_created_latest_order = new CdekOrderFakeOrder( 119 );
$repository->save_for_carrier( $successful_created_latest_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'created-latest-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-119' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'created-latest-uuid',
		'statuses' => array(
			array( 'code' => 'ACCEPTED', 'name' => 'Принят', 'date_time' => '2026-06-13T05:48:42+0000' ),
			array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ),
		),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$successful_created_latest = $status->update( $successful_created_latest_order );
cdek_order_assert( 'CREATED' === (string) ( $successful_created_latest['status']['order_status_code'] ?? '' ) && 'Создан' === (string) ( $successful_created_latest['status']['carrier_status_title'] ?? '' ), 'CDEK actual status CREATED must come from entity.statuses even when request_state is SUCCESSFUL.' );

$latest_order = new CdekOrderFakeOrder( 110 );
$latest_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] = array( 'api' => array( 'api_base_price_rub' => 450.0 ) );
$repository->save_for_carrier( $latest_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'latest-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-110' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'latest-uuid',
		'cdek_number' => '100510',
		'planned_delivery_date' => '2026-06-15',
		'delivery_detail' => array( 'total_sum' => 450.18 ),
		'statuses' => array(
			array( 'code' => 'READY_FOR_SHIPMENT_IN_SENDER_CITY', 'name' => 'Готов к отправке', 'date_time' => '2026-06-13T10:04:41+0000' ),
			array( 'code' => 'RECEIVED_AT_SHIPMENT_WAREHOUSE', 'name' => 'Принят на складе', 'date_time' => '2026-06-13T10:04:33+0000' ),
			array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ),
			array( 'code' => 'ACCEPTED', 'name' => 'Принят', 'date_time' => '2026-06-13T05:48:42+0000' ),
		),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$latest = $status->update( $latest_order );
$latest_shipment = $repository->find_by_carrier( $latest_order, CdekSettings::CARRIER_KEY );
cdek_order_assert( 'READY_FOR_SHIPMENT_IN_SENDER_CITY' === (string) ( $latest_shipment['cdek_order_status_code'] ?? '' ), 'CDEK latest status must be selected by max date_time, not array tail.' );
cdek_order_assert( 'READY_FOR_SHIPMENT_IN_SENDER_CITY' === (string) ( $latest['status']['order_status_code'] ?? '' ), 'CDEK status payload must use latest order status, not request state.' );
cdek_order_assert( 'Готов к отправке' === (string) ( $latest['status']['carrier_status_title'] ?? '' ), 'CDEK displayed status must use entity.statuses name instead of request_state.' );
cdek_order_assert( '2026-06-15' === (string) ( $latest['status']['cdek_planned_delivery_date'] ?? '' ), 'CDEK planned_delivery_date must be saved in status payload.' );
cdek_order_assert( 45018 === (int) ( $latest_shipment['cdek_actual_cost_kopecks'] ?? 0 ), 'CDEK delivery_detail.total_sum must be saved as actual cost.' );
cdek_order_assert( '450.18 руб.' === (string) ( $latest['status']['actual_cost_label'] ?? '' ) && 'ok' === (string) ( $latest['status']['actual_cost_compare_status'] ?? '' ), 'CDEK actual cost within 3 percent of base API cost must compare as ok.' );

$deleted_status_order = new CdekOrderFakeOrder( 111 );
$repository->save_for_carrier( $deleted_status_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'deleted-status-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-111' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'deleted-status-uuid',
		'statuses' => array(
			array( 'code' => 'DELIVERED', 'name' => 'Вручен', 'date_time' => '2026-06-14T10:04:41+0000', 'deleted' => true ),
			array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ),
		),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$deleted_status = $status->update( $deleted_status_order );
cdek_order_assert( 'CREATED' === (string) ( $deleted_status['status']['order_status_code'] ?? '' ), 'CDEK deleted statuses must be ignored when selecting current status.' );

$empty_status_order = new CdekOrderFakeOrder( 112 );
$repository->save_for_carrier( $empty_status_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'empty-status-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-112' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'empty-status-uuid',
		'statuses' => array(
			array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000', 'deleted' => true ),
		),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$empty_status = $status->update( $empty_status_order );
cdek_order_assert( $empty_status['success'] && 'registration_pending' === (string) $repository->find_by_carrier( $empty_status_order, CdekSettings::CARRIER_KEY )['status'] && '' === (string) ( $empty_status['status']['order_status_code'] ?? '' ), 'CDEK empty/all-deleted statuses must remain registration_pending and not break status payload.' );

$warning_cost_order = new CdekOrderFakeOrder( 113 );
$warning_cost_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] = array( 'api' => array( 'api_base_price_rub' => 450.0 ) );
$repository->save_for_carrier( $warning_cost_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'warning-cost-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-113' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'warning-cost-uuid',
		'delivery_detail' => array( 'total_sum' => 470 ),
		'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ) ),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$warning_cost = $status->update( $warning_cost_order );
cdek_order_assert( 'warning' === (string) ( $warning_cost['status']['actual_cost_compare_status'] ?? '' ), 'CDEK actual cost above 3 percent of base API cost must compare as warning.' );

$missing_cost_order = new CdekOrderFakeOrder( 114 );
$repository->save_for_carrier( $missing_cost_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'missing-cost-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-114' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'missing-cost-uuid',
		'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ) ),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$missing_cost = $status->update( $missing_cost_order );
cdek_order_assert( '' === (string) ( $missing_cost['status']['actual_cost_label'] ?? '' ) && '' === (string) ( $missing_cost['status']['actual_cost_compare_status'] ?? '' ), 'Missing CDEK delivery_detail.total_sum must not render actual cost comparison.' );

$order_invalid = new CdekOrderFakeOrder( 102 );
$repository->save_for_carrier( $order_invalid, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'bad-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-102' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'bad-uuid' ), 'requests' => array( array( 'state' => 'INVALID', 'errors' => array( array( 'message' => 'bad request' ) ) ) ) );
$invalid = $status->update( $order_invalid );
cdek_order_assert( $invalid['success'] && 'failed' === (string) $repository->find_by_carrier( $order_invalid, CdekSettings::CARRIER_KEY )['status'], 'GET /v2/orders INVALID must fail shipment.' );

cdek_order_assert( isset( $created['status'], $invalid['status'] ), 'Status AJAX payload data must contain status for toast/UI.' );
cdek_order_assert( CdekSettings::CARRIER_KEY === (string) $created['status']['carrier_key'], 'CDEK status payload must be carrier-aware.' );

$attach_http = new CdekOrderFakeHttp();
$attach_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $attach_http ), $settings, $attach_http );
$attach_repository = new OrderShipmentRepository();
$attach_status = new CdekOrderStatusService( $attach_repository, $attach_client );
$attach_order = new CdekOrderFakeOrder( 103 );
$attach_http->order_responses[] = array( 'entity' => array( 'uuid' => 'manual-uuid', 'cdek_number' => '100501', 'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$attached = $attach_status->attach_by_cdek_number( $attach_order, '100501' );
cdek_order_assert( $attached['success'] && CdekSettings::CARRIER_KEY === (string) ( $attached['status']['carrier_key'] ?? '' ), 'Manual attach CDEK must return status payload.' );
$attached_shipment = $attach_repository->find_by_carrier( $attach_order, CdekSettings::CARRIER_KEY );
cdek_order_assert( '100501' === (string) ( $attached_shipment['cdek_number'] ?? '' ) && 'manual-uuid' === (string) ( $attached_shipment['external_id'] ?? '' ), 'Manual attach CDEK must save cdek_number and uuid.' );
$attach_http->order_responses[] = array( 'entity' => array( 'uuid' => 'manual-uuid', 'cdek_number' => '100501', 'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$manual_update = $attach_status->update( $attach_order );
cdek_order_assert( $manual_update['success'] && ! empty( $manual_update['status']['can_update_status'] ), 'Manual attached CDEK shipment must support update status.' );

$attach_pending_http = new CdekOrderFakeHttp();
$attach_pending_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $attach_pending_http ), $settings, $attach_pending_http );
$attach_pending_repository = new OrderShipmentRepository();
$attach_pending_status = new CdekOrderStatusService( $attach_pending_repository, $attach_pending_client );
$attach_pending_order = new CdekOrderFakeOrder( 120 );
$attach_pending_http->order_responses[] = array( 'entity' => array( 'uuid' => 'manual-pending-uuid', 'cdek_number' => '100520', 'statuses' => array() ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$attach_pending = $attach_pending_status->attach_by_cdek_number( $attach_pending_order, '100520' );
$attach_pending_shipment = $attach_pending_repository->find_by_carrier( $attach_pending_order, CdekSettings::CARRIER_KEY );
cdek_order_assert( $attach_pending['success'] && 'registration_pending' === (string) ( $attach_pending_shipment['status'] ?? '' ), 'Manual attach shipment_from_body must keep SUCCESSFUL without order statuses as registration_pending.' );

$not_found_http = new CdekOrderFakeHttp();
$not_found_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $not_found_http ), $settings, $not_found_http );
$not_found_repository = new OrderShipmentRepository();
$not_found_status = new CdekOrderStatusService( $not_found_repository, $not_found_client );
$not_found_order = new CdekOrderFakeOrder( 104 );
$not_found_http->order_responses[] = array( 'entity' => array(), 'requests' => array( array( 'state' => 'INVALID', 'errors' => array( array( 'message' => 'not found' ) ) ) ) );
$not_found = $not_found_status->attach_by_cdek_number( $not_found_order, 'missing' );
cdek_order_assert( ! $not_found['success'] && array() === $not_found_repository->find_by_carrier( $not_found_order, CdekSettings::CARRIER_KEY ), 'Manual attach not found must not save shipment.' );

$cancel_http = new CdekOrderFakeHttp();
$cancel_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $cancel_http ), $settings, $cancel_http );
$cancel_repository = new OrderShipmentRepository();
$cancel_status = new CdekOrderStatusService( $cancel_repository, $cancel_client );
$cancel_order = new CdekOrderFakeOrder( 105 );
$cancel_repository->save_for_carrier( $cancel_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'created-uuid', 'cdek_number' => '100502', 'status' => 'registered', 'cdek_order_status_code' => 'CREATED', 'cdek_order_status_label' => 'Создан' ) );
$cancel_payload = $cancel_status->status_payload( $cancel_repository->find_by_carrier( $cancel_order, CdekSettings::CARRIER_KEY ) );
cdek_order_assert( ! empty( $cancel_payload['can_cancel'] ), 'CREATED CDEK shipment must allow cancel/delete in CDEK.' );
$cancel_http->delete_responses[] = array( 'entity' => array( 'uuid' => 'created-uuid' ), 'requests' => array( array( 'request_uuid' => 'delete-1', 'state' => 'ACCEPTED' ) ) );
$cancelled = $cancel_status->cancel_created_order( $cancel_order );
$delete_count = count( array_filter( $cancel_http->requests, static fn ( array $request ): bool => 'DELETE' === $request['method'] && str_contains( $request['url'], '/v2/orders/created-uuid' ) ) );
cdek_order_assert( $cancelled['success'] && 1 === $delete_count && array() === $cancel_repository->find_by_carrier( $cancel_order, CdekSettings::CARRIER_KEY ), 'CDEK cancel/delete must call API and remove local shipment on success.' );

$forbidden_cancel_http = new CdekOrderFakeHttp();
$forbidden_cancel_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $forbidden_cancel_http ), $settings, $forbidden_cancel_http );
$forbidden_cancel_repository = new OrderShipmentRepository();
$forbidden_cancel_status = new CdekOrderStatusService( $forbidden_cancel_repository, $forbidden_cancel_client );
$forbidden_cancel_order = new CdekOrderFakeOrder( 106 );
$forbidden_cancel_repository->save_for_carrier( $forbidden_cancel_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'accepted-uuid', 'cdek_number' => '100503', 'status' => 'registered', 'cdek_order_status_code' => 'ACCEPTED', 'cdek_order_status_label' => 'Принят' ) );
$forbidden_cancel_payload = $forbidden_cancel_status->status_payload( $forbidden_cancel_repository->find_by_carrier( $forbidden_cancel_order, CdekSettings::CARRIER_KEY ) );
$forbidden_cancel = $forbidden_cancel_status->cancel_created_order( $forbidden_cancel_order );
$forbidden_delete_count = count( array_filter( $forbidden_cancel_http->requests, static fn ( array $request ): bool => 'DELETE' === $request['method'] ) );
cdek_order_assert( empty( $forbidden_cancel_payload['can_cancel'] ) && ! $forbidden_cancel['success'] && 0 === $forbidden_delete_count, 'CDEK cancel/delete must be forbidden outside CREATED and must not call API.' );

$remove_http = new CdekOrderFakeHttp();
$remove_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $remove_http ), $settings, $remove_http );
$remove_repository = new OrderShipmentRepository();
$remove_status = new CdekOrderStatusService( $remove_repository, $remove_client );
$remove_order = new CdekOrderFakeOrder( 107 );
$remove_repository->save_for_carrier( $remove_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'delivered-uuid', 'cdek_number' => '100504', 'status' => 'registered', 'cdek_order_status_code' => 'DELIVERED', 'cdek_order_status_label' => 'Вручен' ) );
$remove_payload = $remove_status->status_payload( $remove_repository->find_by_carrier( $remove_order, CdekSettings::CARRIER_KEY ) );
$removed = $remove_status->remove_local_if_allowed( $remove_order );
$remove_delete_count = count( array_filter( $remove_http->requests, static fn ( array $request ): bool => 'DELETE' === $request['method'] ) );
cdek_order_assert( ! empty( $remove_payload['can_remove_from_order'] ) && $removed['success'] && 0 === $remove_delete_count && array() === $remove_repository->find_by_carrier( $remove_order, CdekSettings::CARRIER_KEY ), 'Allowed CDEK local remove must not call API and must remove local shipment.' );

foreach ( array( 'ACCEPTED', 'CREATED' ) as $protected_status ) {
	$protected_repository = new OrderShipmentRepository();
	$protected_http = new CdekOrderFakeHttp();
	$protected_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $protected_http ), $settings, $protected_http );
	$protected_service = new CdekOrderStatusService( $protected_repository, $protected_client );
	$protected_order = new CdekOrderFakeOrder( 'ACCEPTED' === $protected_status ? 108 : 109 );
	$protected_repository->save_for_carrier( $protected_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => strtolower( $protected_status ) . '-uuid', 'cdek_number' => '100505', 'status' => 'registered', 'cdek_order_status_code' => $protected_status ) );
	$protected_payload = $protected_service->status_payload( $protected_repository->find_by_carrier( $protected_order, CdekSettings::CARRIER_KEY ) );
	$protected_remove = $protected_service->remove_local_if_allowed( $protected_order );
	cdek_order_assert( empty( $protected_payload['can_remove_from_order'] ) && ! $protected_remove['success'], 'CDEK local remove must be forbidden for ' . $protected_status . '.' );
}

$tariff_db = new wpdb();
$tariff_db->cdek_tariffs = array(
	array( 'tariff_code' => '136', 'tariff_name_from_cdek' => 'Посылка склад-склад', 'custom_title' => 'Кастомный ПВЗ', 'delivery_type' => DeliveryType::PICKUP, 'is_active' => 1, 'created_at' => '2026-06-13 12:00:00', 'updated_at' => '2026-06-13 12:00:00' ),
	array( 'tariff_code' => '138', 'tariff_name_from_cdek' => 'Эконом склад-склад', 'custom_title' => '', 'delivery_type' => DeliveryType::PICKUP, 'is_active' => 1, 'created_at' => '2026-06-13 12:00:00', 'updated_at' => '2026-06-13 12:00:00' ),
	array( 'tariff_code' => '137', 'tariff_name_from_cdek' => 'Посылка склад-дверь', 'custom_title' => 'Курьер кастом', 'delivery_type' => DeliveryType::COURIER, 'is_active' => 1, 'created_at' => '2026-06-13 12:00:00', 'updated_at' => '2026-06-13 12:00:00' ),
	array( 'tariff_code' => '139', 'tariff_name_from_cdek' => 'Неактивный ПВЗ', 'custom_title' => '', 'delivery_type' => DeliveryType::PICKUP, 'is_active' => 0, 'created_at' => '2026-06-13 12:00:00', 'updated_at' => '2026-06-13 12:00:00' ),
);
$tariff_repository = new CdekTariffRepository( $tariff_db );
$services = new DeliveryServiceRepository( new wpdb() );
$drafts = new OrderShipmentDraftFactory( $services, new ShipmentServiceSettings(), null, null, null, $settings, $tariff_repository );
$draft_order = new CdekOrderFakeOrder( 130 );
$draft_order->meta['_wdc_platform_carrier_key'] = CdekSettings::CARRIER_KEY;
$draft_order->meta['_wdc_platform_delivery_type'] = DeliveryType::PICKUP;
$draft_order->meta['_wdc_delivery_calculation_data'] = array(
	'carrier_key' => CdekSettings::CARRIER_KEY,
	'selected_tariff_object' => '136',
	'selected_tariff_title' => 'Посылка склад-склад',
	'pickup' => array( 'cdek_code' => 'ISK1', 'point_code' => 'ISK1', 'point_address' => 'Искитим, ПВЗ', 'point_postcode' => '633209', 'city_name' => 'Искитим', 'region_name' => 'Новосибирская область' ),
	'api' => array( 'response_tariff_sanitized' => array( 'delivery_mode' => 4 ), 'cdek_to_city_code' => 270 ),
	'package' => array( 'products_weight_g' => 500, 'dimensions_cm' => array( 'length' => 20, 'width' => 15, 'height' => 10 ) ),
);
$draft = $drafts->draft_array( $draft_order );
$draft_request = $draft['request'];
$pickup_service = array_values( array_filter( $draft['services'], static fn ( array $service ): bool => DeliveryType::PICKUP === (string) ( $service['delivery_type'] ?? '' ) ) )[0] ?? array();
$pickup_tariffs = is_array( $pickup_service['tariffs'] ?? null ) ? $pickup_service['tariffs'] : array();
$pickup_codes = array_map( static fn ( array $row ): string => (string) ( $row['object_code'] ?? '' ), $pickup_tariffs );
cdek_order_assert( array( '136', '138' ) === $pickup_codes && '136' === (string) ( $draft_request['meta']['tariff_code'] ?? '' ), 'CDEK shipment modal pickup tariff select must include active pickup tariffs only and keep selected order tariff.' );
cdek_order_assert( str_contains( (string) ( $pickup_tariffs[0]['title'] ?? '' ), 'Кастомный ПВЗ' ) && str_contains( (string) ( $pickup_tariffs[1]['title'] ?? '' ), 'Эконом склад-склад' ), 'CDEK tariff titles must use custom_title first and CDEK name as fallback.' );
cdek_order_assert( ! in_array( '137', $pickup_codes, true ) && ! in_array( '139', $pickup_codes, true ), 'CDEK pickup modal tariffs must exclude courier and inactive tariffs.' );
$location_context = is_array( $draft_request['meta']['pickup_location_context'] ?? null ) ? $draft_request['meta']['pickup_location_context'] : array();
cdek_order_assert( CdekSettings::CARRIER_KEY === (string) ( $location_context['carrier_key'] ?? '' ) && 'cdek:pickup' === (string) ( $location_context['pickup_family'] ?? '' ), 'CDEK admin map context must use CDEK carrier and pickup family.' );
cdek_order_assert( 'Кемерово' === (string) ( $location_context['city_name'] ?? '' ) && 'Новосибирск' !== (string) ( $location_context['city_name'] ?? '' ), 'CDEK admin map location context must come from recipient city, not sender city.' );
$courier_order = new CdekOrderFakeOrder( 131 );
$courier_order->meta['_wdc_platform_carrier_key'] = CdekSettings::CARRIER_KEY;
$courier_order->meta['_wdc_platform_delivery_type'] = DeliveryType::COURIER;
$courier_order->meta['_wdc_delivery_calculation_data'] = array(
	'carrier_key' => CdekSettings::CARRIER_KEY,
	'selected_tariff_object' => '137',
	'selected_tariff_title' => 'Посылка склад-дверь',
	'api' => array( 'response_tariff_sanitized' => array( 'delivery_mode' => 3 ), 'cdek_to_city_code' => 44 ),
	'package' => array( 'products_weight_g' => 500, 'dimensions_cm' => array( 'length' => 20, 'width' => 15, 'height' => 10 ) ),
);
$courier_draft = $drafts->draft_array( $courier_order );
$courier_service = array_values( array_filter( $courier_draft['services'], static fn ( array $service ): bool => DeliveryType::COURIER === (string) ( $service['delivery_type'] ?? '' ) ) )[0] ?? array();
$courier_codes = array_map( static fn ( array $row ): string => (string) ( $row['object_code'] ?? '' ), is_array( $courier_service['tariffs'] ?? null ) ? $courier_service['tariffs'] : array() );
cdek_order_assert( array( '137' ) === $courier_codes, 'CDEK shipment modal courier tariff select must include active courier tariffs only.' );
$missing_tariff_order = new CdekOrderFakeOrder( 132 );
$missing_tariff_order->meta = $draft_order->meta;
$missing_tariff_order->meta['_wdc_delivery_calculation_data']['selected_tariff_object'] = '999';
$missing_draft = $drafts->draft_array( $missing_tariff_order );
$missing_pickup_service = array_values( array_filter( $missing_draft['services'], static fn ( array $service ): bool => DeliveryType::PICKUP === (string) ( $service['delivery_type'] ?? '' ) ) )[0] ?? array();
$missing_options = is_array( $missing_pickup_service['tariffs'] ?? null ) ? $missing_pickup_service['tariffs'] : array();
$missing_option = array_values( array_filter( $missing_options, static fn ( array $row ): bool => '999' === (string) ( $row['object_code'] ?? '' ) ) )[0] ?? array();
cdek_order_assert( ! empty( $missing_option['selected_missing'] ) && '999' === (string) ( $missing_draft['request']['meta']['tariff_code'] ?? '' ), 'CDEK modal must keep selected tariff value when it is absent from active managed tariffs.' );
$admin_request = $drafts->create_request_from_admin_data(
	$draft_order,
	array(
		'delivery_type' => DeliveryType::PICKUP,
		'tariff_object' => '136',
		'delivery_point' => 'NEW1',
		'pickup_point_code' => 'NEW1',
		'pickup_point_address' => 'Новый ПВЗ',
		'pickup_point_city' => 'Кемерово',
		'pickup_point_region' => 'Кемеровская область',
	)
);
cdek_order_assert( 'NEW1' === (string) ( $admin_request->meta['delivery_point'] ?? '' ) && 'NEW1' === (string) ( $admin_request->meta['pickup_point_code'] ?? '' ) && $admin_request->pickup_point instanceof PickupPointSelection && 'NEW1' === $admin_request->pickup_point->point_code, 'Choosing another CDEK pickup point in modal must update delivery_point and point_code.' );

$ajax_http = new CdekOrderFakeHttp();
$ajax_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $ajax_http ), $settings, $ajax_http );
$ajax_repository = new OrderShipmentRepository();
$ajax_creation = new ShipmentCreationService( $ajax_repository, array( new CdekShipmentAdapter( $ajax_client, $builder ) ) );
$rp_tracking = ( new ReflectionClass( RussianPostTrackingApiClient::class ) )->newInstanceWithoutConstructor();
$status_updates = new ShipmentStatusUpdateService( $ajax_repository, $rp_tracking, new RussianPostTrackingStatusMapper() );
$ajax_status = new CdekOrderStatusService( $ajax_repository, $ajax_client );
$metabox = new OrderShipmentsMetabox( $ajax_repository, $drafts, $ajax_creation, $services, $status_updates, $ajax_status );
$ajax_order = new CdekOrderFakeOrder();
$ajax_order->meta['_wdc_platform_carrier_key'] = CdekSettings::CARRIER_KEY;
$ajax_order->meta['_wdc_platform_delivery_type'] = DeliveryType::PICKUP;
$ajax_order->meta['_wdc_delivery_calculation_data'] = array(
	'carrier_key' => CdekSettings::CARRIER_KEY,
	'selected_tariff_object' => '136',
	'selected_tariff_title' => 'Посылка склад-склад',
	'pickup' => array( 'cdek_code' => 'KEM7', 'point_code' => 'KEM7', 'point_address' => 'Кемерово, ПВЗ', 'point_postcode' => '650000' ),
	'api' => array( 'response_tariff_sanitized' => array( 'delivery_mode' => 4 ), 'cdek_to_city_code' => 44 ),
	'package' => array( 'products_weight_g' => 500, 'dimensions_cm' => array( 'length' => 20, 'width' => 15, 'height' => 10 ) ),
);
$GLOBALS['wdc_cdek_order_ajax_order'] = $ajax_order;
$_POST = array(
	'order_id' => 101,
	'nonce' => 'ok',
	'delivery_type' => DeliveryType::PICKUP,
	'recipient_name' => 'Иван Иванов',
	'recipient_phone' => '9131234567',
	'recipient_email' => 'buyer@example.com',
	'tariff_object' => '136',
	'places' => array( array( 'weight_g' => 1000, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10 ) ),
	'cdek_items' => array( array( 'item_key' => '1', 'ordered_quantity' => 1, 'place_number' => 1, 'name' => 'Товар', 'ware_key' => 'SKU-1', 'amount' => 1, 'cost' => 1000, 'weight' => 100 ) ),
);
try {
	$metabox->ajax_create();
	throw new RuntimeException( 'ajax_create did not send JSON.' );
} catch ( CdekOrderAjaxResponse $response ) {
	cdek_order_assert( $response->success, 'ajax_create for CDEK must succeed.' );
	cdek_order_assert( CdekSettings::CARRIER_KEY === (string) ( $response->data['status']['carrier_key'] ?? '' ), 'ajax_create for CDEK must return CDEK status payload.' );
}

ob_start();
$metabox->render( $draft_order );
$modal_html = ob_get_clean() ?: '';
cdek_order_assert( ! str_contains( $modal_html, 'tariff_code:' ) && ! str_contains( $modal_html, 'delivery_mode:' ), 'CDEK shipment modal must not render technical tariff_code/delivery_mode labels.' );
cdek_order_assert( str_contains( $modal_html, 'В заказе тариф' ) && str_contains( $modal_html, 'Кастомный ПВЗ' ), 'CDEK shipment modal must render human selected tariff title.' );
cdek_order_assert( str_contains( $modal_html, 'ПВЗ отправителя' ) && str_contains( $modal_html, 'NSK69' ), 'CDEK shipment modal must render sender shipment_point label.' );
cdek_order_assert( str_contains( $modal_html, 'Код ПВЗ' ) && str_contains( $modal_html, 'ISK1' ), 'CDEK shipment modal must show recipient CDEK point code, not index label.' );
cdek_order_assert( str_contains( $modal_html, 'name="pickup_carrier_key" value="cdek"' ) && str_contains( $modal_html, 'name="pickup_family" value="cdek:pickup"' ), 'CDEK shipment modal must render CDEK carrier context for admin pickup map.' );
cdek_order_assert( str_contains( $modal_html, 'name="recipient_location_city" value="Кемерово"' ) && ! str_contains( $modal_html, 'name="recipient_location_city" value="Новосибирск"' ), 'CDEK shipment modal map context must use recipient locality, not sender locality.' );
$shipments_js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.js' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'window.WDCPickupApi.addressSearch' ) && str_contains( $shipments_js, 'addressMarkerFromResult' ) && str_contains( $shipments_js, 'provider.setCenter(searchMarker.lat, searchMarker.lng, 15);' ), 'CDEK shipment modal pickup map must use shared DaData address search and focus the temporary marker.' );
cdek_order_assert( is_string( $shipments_js ) && ! str_contains( $shipments_js, 'через DaData' ) && str_contains( $shipments_js, "status.textContent = 'Ищем адрес...'" ) && str_contains( $shipments_js, "'Адрес найден.'" ) && str_contains( $shipments_js, "'Адрес не найден.'" ), 'CDEK shipment modal pickup map must use neutral address-search UI messages.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'data-wdc-pickup-picker-confirm' ) && ! str_contains( $shipments_js, 'data-wdc-pickup-picker-choose' ) && ! str_contains( $shipments_js, 'data-wdc-pickup-popup-select' ) && ! str_contains( $shipments_js, 'wdc-admin-pickup-picker__selected-grid' ), 'CDEK shipment modal pickup map must use one bottom select button and no duplicate per-card controls.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, "'ПВЗ СДЭК'" ) && str_contains( $shipments_js, "'Постамат СДЭК'" ), 'CDEK shipment modal pickup map must render CDEK pickup/postamat titles.' );

$render = new ReflectionMethod( OrderShipmentsMetabox::class, 'render_status_block' );
$render->setAccessible( true );
ob_start();
$render->invoke( $metabox, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'carrier_status_title' => 'Регистрация', 'cdek_planned_delivery_date' => '2026-06-15' ) );
$status_html = ob_get_clean() ?: '';
cdek_order_assert( str_contains( $status_html, 'Статус СДЭК' ) && ! str_contains( $status_html, 'Статус Почты России' ), 'CDEK status block must use CDEK label.' );
cdek_order_assert( str_contains( $status_html, 'Плановая дата доставки' ) && str_contains( $status_html, '2026-06-15' ), 'CDEK status block must render planned_delivery_date when present.' );

echo "CDEK order creation smoke test passed.\n";
