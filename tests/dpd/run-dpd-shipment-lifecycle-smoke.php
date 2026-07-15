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
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

function dpd_lifecycle_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_lifecycle_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_lifecycle_options'][ $key ] = $value; return true; }
function wp_salt( string $scheme = '' ): string { return 'lifecycle'; }
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }
function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
final class DpdLifecycleFakeSoap implements DpdSoapClientInterface { public array $calls = array(); public array $responses = array(); public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse { $this->calls[] = compact( 'service', 'method', 'payload', 'options' ); $body = array_shift( $this->responses ) ?? array(); return new DpdSoapResponse( true, $body, array() ); } public function is_available(): bool { return true; } }
$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin( array( DpdSettings::ENVIRONMENT_KEY => DpdSettings::ENV_TEST, DpdSettings::TEST_CLIENT_NUMBER_KEY => '123', 'dpd_test_client_key' => 'secret' ) );
dpd_lifecycle_assert( 10 === $settings->order_create_timeout(), 'createOrder2 timeout must be exactly 10 seconds.' );
$soap = new DpdLifecycleFakeSoap();
$client = new DpdApiClient( $settings, $soap );
$client->getOrderStatus( array( 'order' => array( array( 'orderNumberInternal' => '100', 'datePickup' => '2026-06-20' ) ) ) );
$client->cancelOrder( array( 'cancel' => array( array( 'orderNum' => 'DPD1', 'pickupdate' => '2026-06-20' ) ) ) );
$client->getStatesByDPDOrder( array( 'dpdOrderNr' => 'DPD1', 'pickupYear' => 2026 ) );
dpd_lifecycle_assert( 'order2' === $soap->calls[0]['service'] && 'getOrderStatus' === $soap->calls[0]['method'] && DpdSoapRequest::WRAPPER_ORDER_STATUS === $soap->calls[0]['options']['wrapper'], 'getOrderStatus must use order2/orderStatus wrapper.' );
dpd_lifecycle_assert( 'order2' === $soap->calls[1]['service'] && 'cancelOrder' === $soap->calls[1]['method'] && DpdSoapRequest::WRAPPER_ORDERS === $soap->calls[1]['options']['wrapper'], 'cancelOrder must use order2/orders wrapper.' );
dpd_lifecycle_assert( 'tracing1-1' === $soap->calls[2]['service'] && 'getStatesByDPDOrder' === $soap->calls[2]['method'] && DpdSoapRequest::WRAPPER_REQUEST === $soap->calls[2]['options']['wrapper'] && 2026 === $soap->calls[2]['payload']['pickupYear'], 'getStatesByDPDOrder must use tracing1-1/request with pickupYear.' );
$business_soap = new DpdLifecycleFakeSoap();
$business_soap->responses = array(
	array( 'return' => array( 'status' => 'OrderDuplicate', 'errorMessage' => 'duplicate from DPD' ) ),
	array( 'return' => array( 'status' => 'OrderError', 'errorMessage' => 'error from DPD' ) ),
	array( 'return' => array( 'status' => 'OrderCancelled', 'errorMessage' => 'cancelled from DPD' ) ),
	array( 'return' => array( 'status' => 'OrderDuplicate', 'errorMessage' => 'duplicate create' ) ),
);
$business_client = new DpdApiClient( $settings, $business_soap );
foreach ( array( 'OrderDuplicate', 'OrderError', 'OrderCancelled' ) as $expected_status ) {
	$response = $business_client->getOrderStatus( array( 'order' => array( array( 'orderNumberInternal' => '100', 'datePickup' => '2026-06-20' ) ) ) );
	$row = $response['body']['return'] ?? array();
	dpd_lifecycle_assert( ! empty( $response['success'] ) && $expected_status === (string) ( $row['status'] ?? '' ) && 'dpd_business_error' !== (string) ( $response['error_code'] ?? '' ), 'getOrderStatus ' . $expected_status . ' must remain a business response for registration service.' );
}
$create_business = $business_client->createOrder2( array( 'order' => array() ) );
dpd_lifecycle_assert( ! empty( $create_business['success'] ) && 'OrderDuplicate' === (string) ( $create_business['order']['status'] ?? '' ) && 'duplicate create' === (string) ( $create_business['error_message'] ?? '' ), 'createOrder2 OrderDuplicate with errorMessage must not become transport_error before registration service.' );
$adapter_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdShipmentAdapter.php' );
$registration_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdOrderRegistrationService.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
dpd_lifecycle_assert( str_contains( $registration_source, 'registration_started_at' ) && str_contains( $registration_source, 'dpd_registration_state' ) && str_contains( $registration_source, 'submit' ), 'Registration service must save pending attempt before submit createOrder2.' );
dpd_lifecycle_assert( str_contains( $registration_source, 'refresh_created_shipment( $order )' ) && str_contains( $registration_source, '$this->events->sync()' ) && str_contains( $registration_source, 'enrich_current_order( $order )' ), 'createOrder2 OK and manual update must run DPD event sync/enrichment refresh.' );
dpd_lifecycle_assert( str_contains( $registration_source, '$refresh[' ) && str_contains( $registration_source, 'shipment' ) && str_contains( $registration_source, '$shipment = $this->touch_tracking_checked( $order );' ), 'createOrder2 OK response must return the shipment reread after event sync/enrichment.' );
dpd_lifecycle_assert( str_contains( $metabox_source, '?array $shipment_override = null' ) && str_contains( $metabox_source, 'is_array( $result' ) && str_contains( $metabox_source, '? $result' ), 'DPD create AJAX payload must build UI status from the refreshed shipment returned by registration service.' );
dpd_lifecycle_assert( str_contains( $registration_source, "'tracking_checked_at'" ) && str_contains( $registration_source, 'touch_tracking_checked' ), 'Manual DPD update must touch tracking_checked_at/updated_at even without a new event.' );
dpd_lifecycle_assert( str_contains( $registration_source, 'sent_places_fields' ) && str_contains( $registration_source, "'dpd_sent_places'") && str_contains( $registration_source, "'dpd_cargo_num_pack'") && str_contains( $registration_source, "'dpd_cargo_weight'") && str_contains( $registration_source, "'dpd_cargo_volume'"), 'Registration service must persist sent DPD places and cargo summary from createOrder2 payload.' );
dpd_lifecycle_assert( str_contains( $registration_source, 'remove_local( object $order ): array { $this->repository->delete( $order );' ) && str_contains( $registration_source, 'CanceledPreviously' ) && substr_count( $registration_source, '$this->repository->delete( $order )' ) >= 2 && str_contains( $registration_source, "'temporary_can_remove' => true" ), 'Local remove and successful cancel must delete the whole DPD shipment while cancel errors keep it.' );
$event_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdEventSyncService.php' );
dpd_lifecycle_assert( str_contains( $event_source, 'event_matches_shipment' ) && str_contains( $event_source, 'normalize_dpd_number' ) && str_contains( $event_source, 'if ( ' ) && str_contains( $event_source, '=== $saved_dpd' ), 'DPD event sync must allow clientOrderNr fallback only while local shipment has no DPD number.' );
dpd_lifecycle_assert( str_contains( $event_source, 'is_valid_pending_client_event' ) && str_contains( $event_source, 'registration_started_at' ) && str_contains( $event_source, 'stale_or_cancelled_pending_event' ), 'Pending DPD registration fallback must ignore stale/cancelled old shipment events without blocking confirm.' );
dpd_lifecycle_assert( str_contains( $event_source, 'event_is_later' ) && str_contains( $event_source, 'select_pending_client_events' ), 'Pending DPD registration fallback must choose the latest valid event by clientOrderNr.' );
dpd_lifecycle_assert( str_contains( $adapter_source, 'begin_registration' ) && str_contains( $adapter_source, 'submit_registration' ) && str_contains( $adapter_source, 'supports_status_auto_sync(): bool' ), 'DPD adapter must expose two-stage registration and remain no autosync.' );
dpd_lifecycle_assert( ! str_contains( $plugin_source, 'DpdShipmentAdapter::class ), $this->container->get( ShipmentStatusAutoSyncCron' ) && ! str_contains( $plugin_source, 'dpd_cron' ), 'DPD scheduled sync/cron must not be registered.' );
$creation_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/ShipmentCreationService.php' );
$dpd_mapper_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdShipmentPersistenceMapper.php' );
dpd_lifecycle_assert( ! str_contains( $creation_source, 'dpd_order_number' ) && ! str_contains( $creation_source, 'cdek_request_uuid' ) && str_contains( $dpd_mapper_source, 'dpd_order_number' ) && str_contains( $dpd_mapper_source, 'dpd_request_number' ), 'ShipmentCreationService must delegate DPD persistence fields to DpdShipmentPersistenceMapper.' );
echo "DPD shipment lifecycle smoke passed
";
