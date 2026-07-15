<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function shipment_persistence_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function current_time( string $type ): string { return '2026-07-15 10:11:12'; }
function get_current_user_id(): int { return 42; }

final class ShipmentPersistenceOrder {
	/** @var array<string,mixed> */
	public array $meta = array();
	/** @var array<int,string> */
	public array $notes = array();
	public function __construct( private int $id ) {}
	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
	public function add_order_note( string $message ): void { $this->notes[] = $message; }
}

final class ShipmentPersistenceAdapter implements CarrierShipmentAdapterInterface {
	public function __construct( private string $carrier_key, private ShipmentCreateResult $result, private array $preview ) {}
	public function carrier_key(): string { return $this->carrier_key; }
	public function supports( ShipmentCreateRequest $request ): bool { return $request->carrier_key === $this->carrier_key; }
	public function presentation(): array { return array(); }
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array { unset( $request ); return $this->preview; }
	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult { unset( $request ); return $this->result; }
	public function status_payload( object $order, array $shipment ): array { unset( $order ); return $shipment; }
	public function update_status( object $order, string $shipment_key = '' ): array { unset( $order, $shipment_key ); return array(); }
	public function attach_manual( object $order, array $payload ): array { unset( $order, $payload ); return array(); }
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array { unset( $order, $shipment_key ); return array(); }
	public function remove_from_order( object $order, string $shipment_key = '' ): array { unset( $order, $shipment_key ); return array(); }
	public function label_actions( object $order, array $shipment ): array { unset( $order, $shipment ); return array(); }
	public function supports_status_auto_sync(): bool { return false; }
	public function tracking_identifier( array $shipment ): string { return (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ); }
	public function auto_sync_throttle_microseconds(): int { return 0; }
}

function shipment_persistence_request( string $carrier_key, array $meta = array() ): ShipmentCreateRequest {
	return new ShipmentCreateRequest(
		1001,
		$carrier_key,
		DeliveryType::PICKUP,
		$carrier_key . ':service',
		new Address( country_code: 'RU', city: 'Москва', raw_address: 'Москва' ),
		null,
		array( new ShipmentPlace( 1, 1000, 20, 15, 10, Money::from_kopecks( 0 ) ) ),
		Money::from_rubles( 100 ),
		false,
		array(),
		array( 'name' => 'Buyer' ),
		array_merge( array( 'service_key' => $carrier_key . ':service', 'service_title' => strtoupper( $carrier_key ), 'order_num' => 'WC-1001' ), $meta )
	);
}
function shipment_persistence_saved( ShipmentPersistenceOrder $order, string $carrier_key ): array {
	$shipments = is_array( $order->meta[ OrderShipmentRepository::META_KEY ] ?? null ) ? $order->meta[ OrderShipmentRepository::META_KEY ] : array();
	return is_array( $shipments[ $carrier_key ] ?? null ) ? $shipments[ $carrier_key ] : array();
}

$cdek_preview = array( 'method' => 'POST', 'path' => '/v2/orders', 'body' => array( 'preview' => 'cdek' ), 'errors' => array() );
$cdek_raw = array(
	'http_code' => 202,
	'request' => array( 'number' => 'WC-1001', 'package_count' => 1 ),
	'response' => array( 'entity_uuid' => 'entity-uuid', 'cdek_number' => '100500' ),
	'entity_uuid' => 'entity-uuid',
	'request_uuid' => 'request-uuid',
	'cdek_number' => '100500',
	'registration_state' => 'SUCCESSFUL',
	'order_status' => 'CREATED',
	'order_status_name' => 'Создан',
	'planned_delivery_date' => '2026-07-20',
	'actual_cost_kopecks' => 12345,
);
$cdek_request = shipment_persistence_request( CdekSettings::CARRIER_KEY );
$cdek_order = new ShipmentPersistenceOrder( 1001 );
$cdek_result = new ShipmentCreateResult( true, external_id: 'entity-uuid', tracking_number: '100500', backlog_order_id: 'request-uuid', raw_reference: $cdek_raw );
( new ShipmentCreationService( new OrderShipmentRepository(), array( new ShipmentPersistenceAdapter( CdekSettings::CARRIER_KEY, $cdek_result, $cdek_preview ) ), null, null, null, array( new CdekShipmentPersistenceMapper() ) ) )->create( $cdek_order, $cdek_request );
$cdek_expected = array(
	'carrier_key' => CdekSettings::CARRIER_KEY,
	'service_key' => CdekSettings::CARRIER_KEY . ':service',
	'order_id' => 1001,
	'service_title' => 'CDEK',
	'delivery_type' => DeliveryType::PICKUP,
	'places' => array_map( static fn ( ShipmentPlace $place ): array => $place->to_array(), $cdek_request->places ),
	'request_snapshot' => array( 'method' => 'POST', 'path' => '/v2/orders', 'body' => $cdek_raw['request'], 'errors' => array() ),
	'response_snapshot' => $cdek_raw,
	'barcode' => '100500',
	'tracking_number' => '100500',
	'external_id' => 'entity-uuid',
	'created_by' => 42,
	'created_by_context' => 'admin_manual',
	'order_num' => 'WC-1001',
	'status' => 'registration_pending',
	'status_title' => 'Заявка на регистрацию принята',
	'created_at' => '2026-07-15 10:11:12',
	'updated_at' => '2026-07-15 10:11:12',
	'cdek_number' => '100500',
	'cdek_request_uuid' => 'request-uuid',
	'cdek_request_state' => 'SUCCESSFUL',
	'cdek_order_status_code' => 'CREATED',
	'cdek_order_status_name' => 'Создан',
	'cdek_planned_delivery_date' => '2026-07-20',
	'cdek_actual_cost_kopecks' => 12345,
	'backlog_order_id' => 'request-uuid',
);
shipment_persistence_assert( $cdek_expected === shipment_persistence_saved( $cdek_order, CdekSettings::CARRIER_KEY ) && array() === $cdek_order->notes, 'CDEK mapper persistence must equal legacy shipment fields and notes.' );

$dpd_preview = array( 'method' => 'SOAP', 'path' => 'order2/createOrder2', 'body' => array( 'preview' => 'dpd' ), 'errors' => array(), 'warnings' => array() );
$dpd_raw = array(
	'request' => array( 'method' => 'SOAP', 'path' => 'order2/createOrder2', 'body' => array( 'request' => 'dpd' ) ),
	'response' => array( 'orderNum' => 'DPD-1' ),
	'dpd_order_number' => 'DPD-1',
	'dpd_request_number' => 'REQ-1',
	'dpd_parcel_numbers' => array( 'P-1', 'P-2' ),
	'dpd_status' => 'OK',
	'dpd_pickup_date' => '2026-07-16',
	'dpd_date_flag' => 'NEW',
);
$dpd_request = shipment_persistence_request( DpdSettings::CARRIER_KEY, array( 'service_code' => 'PCL', 'pickup_terminal_code' => 'SRC', 'delivery_terminal_code' => 'DST', 'date_pickup' => '2026-07-16', 'declared_value_rub' => 1000 ) );
$dpd_order = new ShipmentPersistenceOrder( 1001 );
$dpd_result = new ShipmentCreateResult( true, external_id: 'DPD-1', tracking_number: 'DPD-1', backlog_order_id: 'REQ-1', raw_reference: $dpd_raw );
( new ShipmentCreationService( new OrderShipmentRepository(), array( new ShipmentPersistenceAdapter( DpdSettings::CARRIER_KEY, $dpd_result, $dpd_preview ) ), null, null, null, array( new DpdShipmentPersistenceMapper() ) ) )->create( $dpd_order, $dpd_request );
$dpd_expected = array(
	'carrier_key' => DpdSettings::CARRIER_KEY,
	'service_key' => DpdSettings::CARRIER_KEY . ':service',
	'order_id' => 1001,
	'service_title' => 'DPD',
	'delivery_type' => DeliveryType::PICKUP,
	'places' => array_map( static fn ( ShipmentPlace $place ): array => $place->to_array(), $dpd_request->places ),
	'request_snapshot' => $dpd_raw['request'],
	'response_snapshot' => $dpd_raw,
	'barcode' => 'DPD-1',
	'tracking_number' => 'DPD-1',
	'external_id' => 'DPD-1',
	'created_by' => 42,
	'created_by_context' => 'admin_manual',
	'order_num' => 'WC-1001',
	'status' => 'pending_creation_in_carrier',
	'status_title' => 'Заявка DPD создана',
	'created_at' => '2026-07-15 10:11:12',
	'updated_at' => '2026-07-15 10:11:12',
	'dpd_order_number' => 'DPD-1',
	'dpd_request_number' => 'REQ-1',
	'dpd_parcel_numbers' => array( 'P-1', 'P-2' ),
	'dpd_status' => 'OK',
	'dpd_pickup_date' => '2026-07-16',
	'dpd_date_flag' => 'NEW',
	'dpd_service_code' => 'PCL',
	'dpd_sender_terminal_code' => 'SRC',
	'dpd_receiver_terminal_code' => 'DST',
	'dpd_date_pickup' => '2026-07-16',
	'dpd_cargo_value' => 1000.0,
	'universal_status_code' => 'pending_creation_in_carrier',
);
shipment_persistence_assert( $dpd_expected === shipment_persistence_saved( $dpd_order, DpdSettings::CARRIER_KEY ) && array( 'DPD отправление создано вручную. Номер: DPD-1. Мест: 1' ) === $dpd_order->notes, 'DPD mapper persistence must equal legacy shipment fields and notes.' );

$rp_preview = array( 'method' => 'PUT', 'path' => '/2.0/user/backlog', 'body' => array( 'preview' => 'rp' ) );
$rp_raw = array( 'orders' => array( array( 'barcode' => 'RP1' ) ), 'barcodes' => array( 'RP1' ), 'group_name' => 'GROUP-1', 'http_code' => 200 );
$rp_request = shipment_persistence_request( RussianPostDomesticSettings::CARRIER_KEY );
$rp_order = new ShipmentPersistenceOrder( 1001 );
$rp_result = new ShipmentCreateResult( true, external_id: '777', tracking_number: 'RP1', backlog_order_id: '777', raw_reference: $rp_raw );
( new ShipmentCreationService( new OrderShipmentRepository(), array( new ShipmentPersistenceAdapter( RussianPostDomesticSettings::CARRIER_KEY, $rp_result, $rp_preview ) ), null, null, null, array( new RussianPostShipmentPersistenceMapper() ) ) )->create( $rp_order, $rp_request );
$rp_expected = array(
	'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
	'service_key' => RussianPostDomesticSettings::CARRIER_KEY . ':service',
	'order_id' => 1001,
	'service_title' => 'RUSSIAN_POST_DOMESTIC',
	'delivery_type' => DeliveryType::PICKUP,
	'places' => array_map( static fn ( ShipmentPlace $place ): array => $place->to_array(), $rp_request->places ),
	'request_snapshot' => $rp_preview,
	'response_snapshot' => $rp_raw,
	'barcode' => 'RP1',
	'tracking_number' => 'RP1',
	'external_id' => '777',
	'created_by' => 42,
	'created_by_context' => 'admin_manual',
	'order_num' => 'WC-1001',
	'status' => 'created',
	'status_title' => '',
	'created_at' => '2026-07-15 10:11:12',
	'updated_at' => '2026-07-15 10:11:12',
	'group_name' => 'GROUP-1',
	'backlog_order_id' => 777,
);
shipment_persistence_assert( $rp_expected === shipment_persistence_saved( $rp_order, RussianPostDomesticSettings::CARRIER_KEY ) && array( 'Отправление Почты России создано. Barcode: RP1. Мест: 1. ММО group-name: GROUP-1' ) === $rp_order->notes, 'Russian Post mapper persistence must equal legacy shipment fields and notes.' );

echo "Shipment persistence mappers smoke passed.\n";
