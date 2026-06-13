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
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Pickup\PickupPointSelection;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Cdek\CdekCreateRequestBuilder;
use WallsShop\WDC\Shipments\Cdek\CdekOrderStatusService;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentAdapter;
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

final class CdekOrderFakeHttp implements CdekHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	/** @var array<int,array<string,mixed>> */
	public array $order_responses = array();

	public function request( string $method, string $url, array $args = array() ): CdekApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		if ( str_contains( $url, '/v2/oauth/token' ) ) {
			return new CdekApiResponse( 200, json_encode( array( 'access_token' => 'token', 'expires_in' => 3600 ) ) ?: '{}' );
		}
		if ( 'POST' === $method && str_contains( $url, '/v2/orders' ) ) {
			return new CdekApiResponse( 202, json_encode( array( 'entity' => array( 'uuid' => 'order-uuid-1' ), 'requests' => array( array( 'request_uuid' => 'request-uuid-1', 'state' => 'ACCEPTED' ) ) ) ) ?: '{}' );
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
$blocked = $creation->create( $order, cdek_order_request( DeliveryType::PICKUP, 4 ) );
cdek_order_assert( ! $blocked->success && 'shipment_already_created' === $blocked->error_code, 'Repeated CDEK creation must be blocked while pending.' );

$status = new CdekOrderStatusService( $repository, $client );
$created = $status->update( $order );
cdek_order_assert( $created['success'] && 'registered' === (string) $repository->find_by_carrier( $order, CdekSettings::CARRIER_KEY )['status'], 'GET /v2/orders CREATED must register shipment.' );

$order_invalid = new CdekOrderFakeOrder( 102 );
$repository->save_for_carrier( $order_invalid, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'bad-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-102' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'bad-uuid' ), 'requests' => array( array( 'state' => 'INVALID', 'errors' => array( array( 'message' => 'bad request' ) ) ) ) );
$invalid = $status->update( $order_invalid );
cdek_order_assert( $invalid['success'] && 'failed' === (string) $repository->find_by_carrier( $order_invalid, CdekSettings::CARRIER_KEY )['status'], 'GET /v2/orders INVALID must fail shipment.' );

cdek_order_assert( isset( $created['status'], $invalid['status'] ), 'Status AJAX payload data must contain status for toast/UI.' );

echo "CDEK order creation smoke test passed.\n";
