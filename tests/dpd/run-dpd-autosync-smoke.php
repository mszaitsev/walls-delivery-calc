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
use WallsShop\WDC\Shipments\Dpd\DpdShipmentAdapter;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentButtonPolicy;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentEnrichmentService;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentRepository;
use WallsShop\WDC\Shipments\Dpd\DpdStatusMapping;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function dpd_autosync_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_autosync_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string|null $autoload = null ): bool { $GLOBALS['wdc_dpd_autosync_options'][ $key ] = $value; return true; }
function add_option( string $key, mixed $value, string $deprecated = '', string $autoload = 'yes' ): bool { if ( isset( $GLOBALS['wdc_dpd_autosync_options'][ $key ] ) ) { return false; } $GLOBALS['wdc_dpd_autosync_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_dpd_autosync_options'][ $key ] ); return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_dpd_autosync_transients'][ $key ]['value'] ?? false; }
function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['wdc_dpd_autosync_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_dpd_autosync_transients'][ $key ] ); return true; }
function wc_get_orders( array $args ): array {
	$orders = array_values( $GLOBALS['wdc_dpd_autosync_orders'] ?? array() );
	if ( isset( $args['meta_key'], $args['meta_value'] ) ) {
		return array_values( array_filter( $orders, static fn( object $order ): bool => method_exists( $order, 'get_meta' ) && (string) $order->get_meta( (string) $args['meta_key'], true ) === (string) $args['meta_value'] ) );
	}
	return $orders;
}
function wc_get_order( int $id ): ?object { return $GLOBALS['wdc_dpd_autosync_orders'][ $id ] ?? null; }
function wp_salt( string $scheme = '' ): string { return 'dpd-autosync-smoke'; }
function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ?? '' ); }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function current_time( string $type ): string { return $GLOBALS['wdc_dpd_autosync_now'] ?? '2026-06-21 14:55:00'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Asia/Novosibirsk' ); }

final class DpdAutosyncSmokeOrder {
	public array $notes = array();
	public function __construct( private int $id, private string $status = 'processing', private array $meta = array() ) {}
	public function get_id(): int { return $this->id; }
	public function get_status(): string { return $this->status; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
	public function save(): void {}
	public function add_order_note( string $note, bool $is_customer_note = false, bool $added_by_user = false ): void { $this->notes[] = $note; }
}

final class DpdAutosyncFakeSoap implements DpdSoapClientInterface {
	public array $calls = array();
	public array $responses = array();
	public function queue( string $method, DpdSoapResponse $response ): void { $this->responses[ $method ][] = $response; }
	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse {
		$this->calls[] = compact( 'service', 'method', 'payload', 'options' );
		if ( ! empty( $this->responses[ $method ] ) ) { return array_shift( $this->responses[ $method ] ); }
		return match ( $method ) {
			'getEvents' => new DpdSoapResponse( true, array( 'docId' => 'doc-empty', 'resultComplete' => true, 'event' => array() ), array() ),
			'confirm' => new DpdSoapResponse( true, array( 'return' => 'OK' ), array() ),
			'getStatesByDPDOrder' => new DpdSoapResponse( true, array( 'return' => array( 'states' => array( 'orderCost' => 123.45, 'planDeliveryDate' => '2026-06-25' ) ) ), array() ),
			default => new DpdSoapResponse( true, array(), array() ),
		};
	}
	public function is_available(): bool { return true; }
	public function count_method( string $method ): int { return count( array_filter( $this->calls, static fn( array $call ): bool => $method === $call['method'] ) ); }
}

function dpd_autosync_event( string $dpd_order, string $client_order, string $number = '1401', string $date = '2026-06-21T14:00:00+07:00' ): array {
	return array( 'dpdOrderNr' => $dpd_order, 'clientOrderNr' => $client_order, 'dpdParcelNr' => $dpd_order . '-1', 'eventNumber' => $number, 'eventCode' => 'OrderCreate', 'eventName' => 'Заказ создан', 'eventDate' => $date );
}

function dpd_autosync_context( array $orders, DpdAutosyncFakeSoap $soap, bool $enabled = true ): array {
	$GLOBALS['wdc_dpd_autosync_orders'] = array();
	foreach ( $orders as $order ) { $GLOBALS['wdc_dpd_autosync_orders'][ $order->get_id() ] = $order; }
	$GLOBALS['wdc_dpd_autosync_options'] = array();
	$GLOBALS['wdc_dpd_autosync_transients'] = array();
	$settings_repository = new SettingsRepository();
	$dpd_settings = new DpdSettings( $settings_repository, new EncryptionService() );
	$dpd_settings->save_from_admin( array( DpdSettings::ENVIRONMENT_KEY => DpdSettings::ENV_TEST, DpdSettings::TEST_CLIENT_NUMBER_KEY => '123', 'dpd_test_client_key' => 'secret' ) );
	$dpd_settings->save_event_settings_from_admin( array( DpdSettings::AUTOSYNC_ENABLED_KEY => $enabled ? '1' : '', DpdSettings::EVENTS_CONFIRM_ENABLED_KEY => '1' ) );
	$order_repository = new OrderShipmentRepository();
	$dpd_repository = new DpdShipmentRepository( $order_repository );
	$client = new DpdApiClient( $dpd_settings, $soap );
	$order_mapping = new ShipmentOrderStatusMappingService( $settings_repository );
	$enrichment = new DpdShipmentEnrichmentService( $client, $dpd_repository );
	$events = new DpdEventSyncService( $client, $dpd_settings, $dpd_repository, new DpdEventNormalizer(), new DpdStatusMapping( $settings_repository ), $order_mapping, null, $enrichment );
	$adapter = new DpdShipmentAdapter( new DpdShipmentPayloadBuilder( $dpd_settings ), $client, null, new DpdShipmentButtonPolicy(), $enrichment );
	$service = new ShipmentStatusAutoSyncService( $settings_repository, $order_repository, ( new ReflectionClass( ShipmentStatusUpdateService::class ) )->newInstanceWithoutConstructor(), $order_mapping, null, null, null, new CarrierShipmentAdapterRegistry( array( $adapter ) ), $events, $dpd_settings );
	return array( $service, $dpd_settings, $dpd_repository );
}

function dpd_autosync_seed( DpdShipmentRepository $repository, DpdAutosyncSmokeOrder $order, string $dpd_order, array $extra = array() ): void {
	$repository->save( $order, array_merge( array( 'carrier_key' => DpdSettings::CARRIER_KEY, 'status' => 'registered', 'dpd_order_number' => $dpd_order, 'tracking_number' => $dpd_order, 'barcode' => $dpd_order, 'universal_status_code' => DeliveryStatus::UNKNOWN ), $extra ) );
}

$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/ShipmentStatusAutoSyncService.php' );
dpd_autosync_assert( str_contains( $source, 'run_global_carrier_syncs' ) && str_contains( $source, 'DpdEventSyncService' ) && str_contains( $source, 'carrier_global_sync' ), 'DPD autosync must be integrated as a global carrier sync, not per-order polling.' );
dpd_autosync_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdShipmentAdapter.php' ), 'supports_status_auto_sync(): bool' ) && str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdShipmentAdapter.php' ), 'return true;' ), 'DPD adapter must opt into autosync support.' );

$soap = new DpdAutosyncFakeSoap();
$order = new DpdAutosyncSmokeOrder( 501 );
list( $service, $settings, $repository ) = dpd_autosync_context( array( $order ), $soap, true );
dpd_autosync_seed( $repository, $order, 'DPD501' );
$service->run( 'cron' );
dpd_autosync_assert( 1 === $soap->count_method( 'getEvents' ), 'Autosync enabled must call DPD sync.' );
dpd_autosync_assert( 'success' === $settings->autosync_last_result(), 'Successful DPD autosync must save last_result=success.' );

$soap = new DpdAutosyncFakeSoap();
$order = new DpdAutosyncSmokeOrder( 502 );
list( $service, $settings, $repository ) = dpd_autosync_context( array( $order ), $soap, false );
dpd_autosync_seed( $repository, $order, 'DPD502' );
$disabled = $service->run( 'cron' );
dpd_autosync_assert( 0 === $soap->count_method( 'getEvents' ) && 'disabled' === $settings->autosync_last_result(), 'Autosync disabled must not call getEvents and must save disabled result.' );
dpd_autosync_assert( 1 === (int) $disabled['skip_reasons']['carrier_global_sync'], 'Disabled DPD autosync must not fall back to per-order DPD polling.' );

$soap = new DpdAutosyncFakeSoap();
$first = new DpdAutosyncSmokeOrder( 601 );
$second = new DpdAutosyncSmokeOrder( 602 );
list( $service, $settings, $repository ) = dpd_autosync_context( array( $first, $second ), $soap, true );
dpd_autosync_seed( $repository, $first, 'DPD601' );
dpd_autosync_seed( $repository, $second, 'DPD602' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-two', 'resultComplete' => true, 'event' => array( dpd_autosync_event( 'DPD601', '601' ), dpd_autosync_event( 'DPD602', '602' ) ) ), array() ) );
$stats = $service->run( 'cron' );
dpd_autosync_assert( 1 === $soap->count_method( 'getEvents' ), 'One cron run with several DPD orders must call getEvents exactly once.' );
dpd_autosync_assert( 2 === (int) $stats['dpd_autosync']['updated'], 'Several DPD shipments must update through one DPD sync.' );
dpd_autosync_assert( DeliveryStatus::CREATED_IN_CARRIER === (string) $repository->find( $first )['universal_status_code'] && DeliveryStatus::CREATED_IN_CARRIER === (string) $repository->find( $second )['universal_status_code'], 'DPD sync must update every matching order from the global event packet.' );

$soap = new DpdAutosyncFakeSoap();
$order = new DpdAutosyncSmokeOrder( 701 );
list( $service, $settings, $repository ) = dpd_autosync_context( array( $order ), $soap, true );
dpd_autosync_seed( $repository, $order, 'DPD701' );
$GLOBALS['wdc_dpd_autosync_options']['wdc_dpd_events_lock'] = array( 'token' => 'busy', 'expires' => time() + 300 );
$locked = $service->run( 'cron' );
dpd_autosync_assert( 0 === $soap->count_method( 'getEvents' ) && ! empty( $locked['dpd_autosync']['lock_busy'] ) && 'success' === $settings->autosync_last_result(), 'Busy DPD lock must skip autosync without error.' );

$soap = new DpdAutosyncFakeSoap();
$order = new DpdAutosyncSmokeOrder( 801 );
list( $service, $settings, $repository ) = dpd_autosync_context( array( $order ), $soap, true );
dpd_autosync_seed( $repository, $order, 'DPD801' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-new', 'resultComplete' => true, 'event' => array( dpd_autosync_event( 'DPD801', '801' ) ) ), array() ) );
$service->run( 'cron' );
dpd_autosync_assert( 1 === $soap->count_method( 'getStatesByDPDOrder' ) && 12345 === (int) $repository->find( $order )['dpd_actual_cost_kopecks'] && '2026-06-25' === (string) $repository->find( $order )['planned_delivery_date'], 'New DPD event with missing price/date must trigger enrichment.' );
$soap->calls = array();
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-same', 'resultComplete' => true, 'event' => array( dpd_autosync_event( 'DPD801', '801' ) ) ), array() ) );
$service->run( 'cron' );
dpd_autosync_assert( 0 === $soap->count_method( 'getStatesByDPDOrder' ), 'Unchanged DPD event must not trigger autosync enrichment.' );

$soap = new DpdAutosyncFakeSoap();
$order = new DpdAutosyncSmokeOrder( 901 );
list( $service, $settings, $repository ) = dpd_autosync_context( array( $order ), $soap, true );
dpd_autosync_seed( $repository, $order, 'DPD901', array( 'universal_status_code' => DeliveryStatus::IN_TRANSIT ) );
$soap->queue( 'getEvents', new DpdSoapResponse( false, array( 'errorMessage' => 'SOAP unavailable' ), array() ) );
$service->run( 'cron' );
dpd_autosync_assert( DeliveryStatus::IN_TRANSIT === (string) $repository->find( $order )['universal_status_code'] && 'error' === $settings->autosync_last_result(), 'getEvents SOAP error must not change shipment status and must save error.' );

$soap = new DpdAutosyncFakeSoap();
$order = new DpdAutosyncSmokeOrder( 902 );
list( $service, $settings, $repository ) = dpd_autosync_context( array( $order ), $soap, true );
dpd_autosync_seed( $repository, $order, 'DPD902' );
$soap->queue( 'getEvents', new DpdSoapResponse( true, array( 'docId' => 'doc-confirm-error', 'resultComplete' => false, 'event' => array( dpd_autosync_event( 'DPD902', '902' ) ) ), array() ) );
$soap->queue( 'confirm', new DpdSoapResponse( false, array( 'errorMessage' => 'confirm failed' ), array() ) );
$service->run( 'cron' );
dpd_autosync_assert( DeliveryStatus::CREATED_IN_CARRIER === (string) $repository->find( $order )['universal_status_code'] && 'error' === $settings->autosync_last_result(), 'confirm error must record error while keeping already processed status changes.' );
dpd_autosync_assert( '2026-06-21 14:55:00' === $settings->autosync_last_run(), 'DPD autosync must save last_run timestamp.' );

echo "DPD autosync smoke passed\n";