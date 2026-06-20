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
final class DpdLifecycleFakeSoap implements DpdSoapClientInterface { public array $calls = array(); public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse { $this->calls[] = compact( 'service', 'method', 'payload', 'options' ); return new DpdSoapResponse( true, array(), array() ); } public function is_available(): bool { return true; } }
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
$adapter_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdShipmentAdapter.php' );
$registration_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdOrderRegistrationService.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
dpd_lifecycle_assert( str_contains( $registration_source, 'registration_started_at' ) && str_contains( $registration_source, 'dpd_registration_state' ) && str_contains( $registration_source, 'submit' ), 'Registration service must save pending attempt before submit createOrder2.' );
dpd_lifecycle_assert( str_contains( $adapter_source, 'begin_registration' ) && str_contains( $adapter_source, 'submit_registration' ) && str_contains( $adapter_source, 'supports_status_auto_sync(): bool' ), 'DPD adapter must expose two-stage registration and remain no autosync.' );
dpd_lifecycle_assert( ! str_contains( $plugin_source, 'DpdShipmentAdapter::class ), $this->container->get( ShipmentStatusAutoSyncCron' ) && ! str_contains( $plugin_source, 'dpd_cron' ), 'DPD scheduled sync/cron must not be registered.' );
dpd_lifecycle_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/ShipmentCreationService.php' ), 'unset( $shipment[ $cdek_key ]' ), 'ShipmentCreationService must strip cdek_* keys from DPD shipments.' );
echo "DPD shipment lifecycle smoke passed
";
