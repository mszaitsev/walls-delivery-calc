<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCredentials;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSoapClientInterface;
use WallsShop\WDC\Carriers\Dpd\DpdSoapResponse;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentPayloadBuilder;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\ShipmentOrderStatusMappingService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Dpd\DpdEventNormalizer;
use WallsShop\WDC\Shipments\Dpd\DpdEventSyncService;
use WallsShop\WDC\Shipments\Dpd\DpdOrderRegistrationService;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentAdapter;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentButtonPolicy;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentRepository;
use WallsShop\WDC\Shipments\Dpd\DpdStatusMapping;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function dpd_woo_audit_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_woo_audit_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string|null $autoload = null ): bool { $GLOBALS['wdc_dpd_woo_audit_options'][ $key ] = $value; return true; }
function add_option( string $key, mixed $value, string $deprecated = '', string $autoload = 'yes' ): bool { if ( isset( $GLOBALS['wdc_dpd_woo_audit_options'][ $key ] ) ) { return false; } $GLOBALS['wdc_dpd_woo_audit_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_dpd_woo_audit_options'][ $key ] ); return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_dpd_woo_audit_transients'][ $key ]['value'] ?? false; }
function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['wdc_dpd_woo_audit_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_dpd_woo_audit_transients'][ $key ] ); return true; }
function wc_get_order_statuses(): array { return array( 'wc-pending' => 'Pending payment', 'wc-processing' => 'Processing', 'wc-on-hold' => 'On hold', 'wc-completed' => 'Completed', 'wc-ready-pickup' => 'Ready pickup', 'wc-cancelled' => 'Cancelled' ); }
function wc_get_order( int $id ): ?object { return $GLOBALS['wdc_dpd_woo_audit_orders'][ $id ] ?? null; }
function wc_get_orders( array $args ): array {
	$orders = array_values( $GLOBALS['wdc_dpd_woo_audit_orders'] ?? array() );
	if ( isset( $args['meta_key'], $args['meta_value'] ) ) {
		return array_values( array_filter( $orders, static fn( object $order ): bool => method_exists( $order, 'get_meta' ) && (string) $order->get_meta( (string) $args['meta_key'], true ) === (string) $args['meta_value'] ) );
	}
	if ( isset( $args['status'] ) ) {
		$statuses = array_map( static fn( mixed $status ): string => str_starts_with( (string) $status, 'wc-' ) ? substr( (string) $status, 3 ) : (string) $status, (array) $args['status'] );
		return array_values( array_filter( $orders, static fn( object $order ): bool => method_exists( $order, 'get_status' ) && in_array( (string) $order->get_status(), $statuses, true ) ) );
	}
	return $orders;
}
function wp_salt( string $scheme = '' ): string { return 'dpd-woocommerce-status-audit'; }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ?? '' ); }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function current_time( string $type ): string { return $GLOBALS['wdc_dpd_woo_audit_now'] ?? '2026-06-22 11:00:00'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Asia/Novosibirsk' ); }

final class DpdWooAuditOrder {
	public array $notes = array();
	public int $update_calls = 0;
	public bool $throw_on_update = false;
	public function __construct( private int $id, private string $status = 'processing', private array $meta = array() ) {}
	public function get_id(): int { return $this->id; }
	public function get_status(): string { return $this->status; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
	public function save(): void {}
	public function update_status( string $status ): void { if ( $this->throw_on_update ) { throw new RuntimeException( 'status rejected' ); } $this->status = $status; $this->update_calls++; }
	public function add_order_note( string $note, bool $is_customer_note = false, bool $added_by_user = false ): void { $this->notes[] = $note; }
}

final class DpdWooAuditFakeSoap implements DpdSoapClientInterface {
	public array $calls = array();
	public array $responses = array();
	public function queue( string $method, DpdSoapResponse $response ): void { $this->responses[ $method ][] = $response; }
	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse {
		$this->calls[] = compact( 'service', 'method', 'payload', 'options' );
		if ( ! empty( $this->responses[ $method ] ) ) { return array_shift( $this->responses[ $method ] ); }
		return 'confirm' === $method ? new DpdSoapResponse( true, array( 'return' => 'OK' ), array() ) : new DpdSoapResponse( true, array( 'docId' => 'doc-empty', 'resultComplete' => true, 'event' => array() ), array() );
	}
	public function is_available(): bool { return true; }
	public function count_method( string $method ): int { return count( array_filter( $this->calls, static fn( array $call ): bool => $method === $call['method'] ) ); }
}

function dpd_woo_audit_event( string $dpd_order, int $client_order, string $event_number, string $date = '2026-06-22T10:00:00+07:00' ): array {
	$names = array( '1401' => array( 'OrderCreate', 'Заказ создан' ), '2201' => array( 'OrderReady', 'Заказ готов к выдаче на пункте' ), '3304' => array( 'OrderDelivered', 'Заказ доставлен до двери' ), '2901' => array( 'OrderCancelled', 'Заказ отменен' ) );
	$name = $names[ $event_number ] ?? array( 'OrderEvent', 'DPD event' );
	return array( 'dpdOrderNr' => $dpd_order, 'clientOrderNr' => (string) $client_order, 'dpdParcelNr' => $dpd_order . '-1', 'eventNumber' => $event_number, 'eventCode' => $name[0], 'eventName' => $name[1], 'eventDate' => $date );
}

function dpd_woo_audit_context( array $orders, array $mapping, bool $confirm = true, bool $dpd_autosync_enabled = true ): array {
	$GLOBALS['wdc_dpd_woo_audit_orders'] = array();
	foreach ( $orders as $order ) { $GLOBALS['wdc_dpd_woo_audit_orders'][ $order->get_id() ] = $order; }
	$GLOBALS['wdc_dpd_woo_audit_options'] = array();
	$GLOBALS['wdc_dpd_woo_audit_transients'] = array();
	$settings_repository = new SettingsRepository();
	$settings_repository->set( ShipmentOrderStatusMappingService::ENABLED_KEY, true );
	$settings_repository->set( ShipmentOrderStatusMappingService::MAPPING_KEY, $mapping );
	$dpd_settings = new DpdSettings( $settings_repository, new EncryptionService() );
	$dpd_settings->save_from_admin( array( DpdSettings::ENVIRONMENT_KEY => DpdSettings::ENV_TEST, DpdSettings::TEST_CLIENT_NUMBER_KEY => '123', 'dpd_test_client_key' => 'secret' ) );
	$dpd_settings->save_event_settings_from_admin( array( DpdSettings::AUTOSYNC_ENABLED_KEY => $dpd_autosync_enabled ? '1' : '', DpdSettings::EVENTS_CONFIRM_ENABLED_KEY => $confirm ? '1' : '' ) );
	$soap = new DpdWooAuditFakeSoap();
	$client = new DpdApiClient( $dpd_settings, $soap );
	$order_repository = new OrderShipmentRepository();
	$dpd_repository = new DpdShipmentRepository( $order_repository );
	$order_mapping = new ShipmentOrderStatusMappingService( $settings_repository );
	$events = new DpdEventSyncService( $client, $dpd_settings, $dpd_repository, new DpdEventNormalizer(), new DpdStatusMapping( $settings_repository ), $order_mapping );
	$adapter = new DpdShipmentAdapter( new DpdShipmentPayloadBuilder( $dpd_settings ), $client, null, new DpdShipmentButtonPolicy() );
	$autosync = new ShipmentStatusAutoSyncService( $settings_repository, $order_repository, ( new ReflectionClass( ShipmentStatusUpdateService::class ) )->newInstanceWithoutConstructor(), $order_mapping, null, null, null, new CarrierShipmentAdapterRegistry( array( $adapter ) ), $events, $dpd_settings );
	$registration = new DpdOrderRegistrationService( new DpdShipmentPayloadBuilder( $dpd_settings ), $client, $dpd_repository, $events );
	return array( $soap, $events, $dpd_repository, $autosync, $registration );
}

function dpd_woo_audit_seed( DpdShipmentRepository $repository, DpdWooAuditOrder $order, string $dpd_order, array $extra = array() ): void {
	$repository->save( $order, array_merge( array( 'carrier_key' => DpdSettings::CARRIER_KEY, 'status' => 'registered', 'dpd_order_number' => $dpd_order, 'tracking_number' => $dpd_order, 'barcode' => $dpd_order, 'universal_status_code' => DeliveryStatus::UNKNOWN, 'universal_status_label' => DeliveryStatus::label( DeliveryStatus::UNKNOWN ) ), $extra ) );
}

$order = new DpdWooAuditOrder( 1401 );
list( $soap, $events, $repository ) = dpd_woo_audit_context( array( $order ), array( DeliveryStatus::CREATED_IN_CARRIER => 'wc-completed' ) );
dpd_woo_audit_seed( $repository, $order, 'DPD1401' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-created', 'resultComplete' => true, 'event' => array( dpd_woo_audit_event( 'DPD1401', 1401, '1401' ) ) ), array() ) );
$result = $events->sync( true )->to_array();
dpd_woo_audit_assert( DeliveryStatus::CREATED_IN_CARRIER === (string) $repository->find( $order )['universal_status_code'] && 'completed' === $order->get_status() && 1 === $order->update_calls && 1 === (int) $result['order_statuses_changed'], 'DPD 1401 must map to created_in_carrier and change Woo status when mapping exists.' );

$order = new DpdWooAuditOrder( 3304 );
list( $soap, $events, $repository ) = dpd_woo_audit_context( array( $order ), array( DeliveryStatus::DELIVERED => 'wc-completed' ) );
dpd_woo_audit_seed( $repository, $order, 'DPD3304' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-delivered', 'resultComplete' => true, 'event' => array( dpd_woo_audit_event( 'DPD3304', 3304, '3304' ) ) ), array() ) );
$result = $events->sync( true )->to_array();
dpd_woo_audit_assert( DeliveryStatus::DELIVERED === (string) $repository->find( $order )['universal_status_code'] && 'completed' === $order->get_status() && 1 === (int) $result['order_statuses_changed'], 'DPD 3304 must map to delivered and change Woo status when mapping exists.' );

$order = new DpdWooAuditOrder( 2201 );
list( $soap, $events, $repository ) = dpd_woo_audit_context( array( $order ), array( DeliveryStatus::READY_FOR_PICKUP => 'wc-ready-pickup' ) );
dpd_woo_audit_seed( $repository, $order, 'DPD2201' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-ready', 'resultComplete' => true, 'event' => array( dpd_woo_audit_event( 'DPD2201', 2201, '2201' ) ) ), array() ) );
$result = $events->sync( true )->to_array();
dpd_woo_audit_assert( DeliveryStatus::READY_FOR_PICKUP === (string) $repository->find( $order )['universal_status_code'] && 'ready-pickup' === $order->get_status() && 1 === (int) $result['order_statuses_changed'], 'DPD 2201 must map to ready_for_pickup and change Woo status when mapping exists.' );

$order = new DpdWooAuditOrder( 4001 );
list( $soap, $events, $repository ) = dpd_woo_audit_context( array( $order ), array( DeliveryStatus::DELIVERED => 'wc-completed' ) );
dpd_woo_audit_seed( $repository, $order, 'DPD4001' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-no-mapping', 'resultComplete' => true, 'event' => array( dpd_woo_audit_event( 'DPD4001', 4001, '1401' ) ) ), array() ) );
$result = $events->sync( true )->to_array();
dpd_woo_audit_assert( DeliveryStatus::CREATED_IN_CARRIER === (string) $repository->find( $order )['universal_status_code'] && 'processing' === $order->get_status() && 0 === $order->update_calls && 1 === (int) $result['order_statuses_skipped'], 'DPD event with universal status but no Woo mapping must update shipment and skip Woo status.' );

$order = new DpdWooAuditOrder( 5001 );
list( $soap, $events, $repository ) = dpd_woo_audit_context( array( $order ), array( DeliveryStatus::DELIVERED => 'wc-completed' ) );
dpd_woo_audit_seed( $repository, $order, 'DPD5001' );
$event = dpd_woo_audit_event( 'DPD5001', 5001, '3304' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-first', 'resultComplete' => true, 'event' => array( $event ) ), array() ) );
$events->sync( true );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-duplicate', 'resultComplete' => true, 'event' => array( $event ) ), array() ) );
$duplicate = $events->sync( true )->to_array();
dpd_woo_audit_assert( 1 === $order->update_calls && 1 === count( $order->notes ) && 1 === (int) $duplicate['unchanged'] && 1 === (int) $duplicate['order_statuses_skipped'], 'Duplicate DPD event must not duplicate Woo status changes or notes and must be counted unchanged/skipped.' );

$order = new DpdWooAuditOrder( 6001 );
list( $soap, $events, $repository ) = dpd_woo_audit_context( array( $order ), array( DeliveryStatus::CREATED_IN_CARRIER => 'wc-on-hold', DeliveryStatus::DELIVERED => 'wc-completed' ) );
dpd_woo_audit_seed( $repository, $order, 'DPD6001', array( 'universal_status_code' => DeliveryStatus::DELIVERED, 'dpd_event_code' => '3304', 'dpd_event_marker' => 'OrderDelivered', 'dpd_event_timestamp' => strtotime( '2026-06-22T12:00:00+07:00' ) ) );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-old', 'resultComplete' => true, 'event' => array( dpd_woo_audit_event( 'DPD6001', 6001, '1401', '2026-06-22T10:00:00+07:00' ) ) ), array() ) );
$old = $events->sync( true )->to_array();
dpd_woo_audit_assert( DeliveryStatus::DELIVERED === (string) $repository->find( $order )['universal_status_code'] && 'processing' === $order->get_status() && 0 === $order->update_calls && 1 === (int) $old['unchanged'], 'Older DPD event after newer one must not roll shipment or Woo status back.' );

$order = new DpdWooAuditOrder( 7001 );
list( $soap, $events, $repository ) = dpd_woo_audit_context( array( $order ), array( DeliveryStatus::CANCELLED => 'wc-cancelled', DeliveryStatus::UNKNOWN => 'wc-cancelled' ) );
dpd_woo_audit_seed( $repository, $order, 'RUNEW' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-old-number', 'resultComplete' => true, 'event' => array( dpd_woo_audit_event( 'RUOLD', 7001, '2901' ) ) ), array() ) );
$old_number = $events->sync( true )->to_array();
dpd_woo_audit_assert( 'RUNEW' === (string) $repository->find( $order )['dpd_order_number'] && 'processing' === $order->get_status() && 0 === $order->update_calls && 1 === (int) $old_number['unmatched'] && 1 === $soap->count_method( 'confirm' ), 'Event for old dpdOrderNr with same clientOrderNr must be unmatched, not change Woo status, and still allow confirm.' );

$order = new DpdWooAuditOrder( 8001 );
list( $soap, $events, $repository, $autosync, $registration ) = dpd_woo_audit_context( array( $order ), array( DeliveryStatus::DELIVERED => 'wc-completed' ) );
dpd_woo_audit_seed( $repository, $order, 'DPD8001' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-manual', 'resultComplete' => true, 'event' => array( dpd_woo_audit_event( 'DPD8001', 8001, '3304' ) ) ), array() ) );
$manual = $registration->update_status( $order );
dpd_woo_audit_assert( ! empty( $manual['success'] ) && 'completed' === $order->get_status() && 1 === (int) $manual['event_sync']['order_statuses_changed'] && '' !== (string) $repository->find( $order )['tracking_checked_at'], 'Manual DPD status update must apply Woo mapping and update tracking_checked_at diagnostics.' );

$first = new DpdWooAuditOrder( 9001 );
$second = new DpdWooAuditOrder( 9002 );
list( $soap, $events, $repository, $autosync ) = dpd_woo_audit_context( array( $first, $second ), array( DeliveryStatus::CREATED_IN_CARRIER => 'wc-completed' ) );
dpd_woo_audit_seed( $repository, $first, 'DPD9001' );
dpd_woo_audit_seed( $repository, $second, 'DPD9002' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-auto-two', 'resultComplete' => true, 'event' => array( dpd_woo_audit_event( 'DPD9001', 9001, '1401' ), dpd_woo_audit_event( 'DPD9002', 9002, '1401' ) ) ), array() ) );
$stats = $autosync->run( 'cron' );
dpd_woo_audit_assert( 1 === $soap->count_method( 'getEvents' ) && 'completed' === $first->get_status() && 'completed' === $second->get_status() && 2 === (int) $stats['order_statuses_changed'] && 2 === (int) $stats['shipments_updated'], 'Autosync must apply Woo mapping for multiple DPD orders from one getEvents call.' );

$order = new DpdWooAuditOrder( 10001 );
list( $soap, $events, $repository ) = dpd_woo_audit_context( array( $order ), array( DeliveryStatus::CREATED_IN_CARRIER => 'wc-completed' ), false );
dpd_woo_audit_seed( $repository, $order, 'DPD10001' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-disabled-confirm', 'resultComplete' => true, 'event' => array( dpd_woo_audit_event( 'UNKNOWN10001', 10001, '1401' ) ) ), array() ) );
$disabled_confirm = $events->sync( false )->to_array();
dpd_woo_audit_assert( 1 === (int) $disabled_confirm['unmatched'] && 0 === $soap->count_method( 'confirm' ) && 'processing' === $order->get_status(), 'Unmatched event with confirm disabled must not affect Woo status and must not call confirm.' );

$order = new DpdWooAuditOrder( 12001 );
$order->throw_on_update = true;
list( $soap, $events, $repository, $autosync ) = dpd_woo_audit_context( array( $order ), array( DeliveryStatus::DELIVERED => 'wc-completed' ) );
dpd_woo_audit_seed( $repository, $order, 'DPD12001' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-error', 'resultComplete' => true, 'event' => array( dpd_woo_audit_event( 'DPD12001', 12001, '3304' ) ) ), array() ) );
$stats = $autosync->run( 'cron' );
dpd_woo_audit_assert( 1 === (int) $stats['order_status_change_errors'] && ! empty( $stats['error_samples'] ) && str_contains( (string) $stats['error_samples'][0]['message'], 'status rejected' ), 'Autosync diagnostics must include DPD order status mapping errors.' );

$mapping = new DpdStatusMapping( new SettingsRepository() );
dpd_woo_audit_assert( DeliveryStatus::PENDING_CREATION_IN_CARRIER === $mapping->resolve( '1001' ) && DeliveryStatus::CREATED_IN_CARRIER === $mapping->resolve( '1401' ) && DeliveryStatus::READY_FOR_PICKUP === $mapping->resolve( '2209' ) && DeliveryStatus::IN_TRANSIT === $mapping->resolve( '2301' ) && DeliveryStatus::DELIVERED === $mapping->resolve( '3304' ) && DeliveryStatus::UNKNOWN === $mapping->resolve( '2901' ) && DeliveryStatus::RETURNING_TO_SENDER === $mapping->resolve( '2404' ) && DeliveryStatus::RETURNED_TO_SENDER === $mapping->resolve( '3306' ), 'Key DPD EventCode defaults must resolve through universal statuses before Woo mapping.' );

$statuses_page_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/ShipmentStatusesAdminPage.php' );
dpd_woo_audit_assert( str_contains( $statuses_page_source, 'DeliveryStatus::all()' ) && str_contains( $statuses_page_source, 'ShipmentOrderStatusMappingService::MAPPING_KEY' ), 'General status mapping UI must render universal statuses from DeliveryStatus::all().' );

echo "DPD WooCommerce status audit smoke passed\n";