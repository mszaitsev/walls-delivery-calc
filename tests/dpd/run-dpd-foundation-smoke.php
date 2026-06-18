<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCredentials;
use WallsShop\WDC\Carriers\Dpd\DpdEndpoints;
use WallsShop\WDC\Carriers\Dpd\DpdException;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSoapClientInterface;
use WallsShop\WDC\Carriers\Dpd\DpdSoapRequest;
use WallsShop\WDC\Carriers\Dpd\DpdSoapResponse;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRegistry;
use WallsShop\WDC\Infrastructure\Logging\LogRedactor;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;

function dpd_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-16 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'dpd-smoke-salt-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_options'][ $key ] = $value; return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $services = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			$data['id'] = ++$this->insert_id;
			$this->services[] = $data;
			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			foreach ( $this->services as $index => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? 0 ) ) {
					$this->services[ $index ] = array_merge( $row, $data );
				}
			}
			return true;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (string) $row['service_key'] === $matches[1] && ( str_contains( $query, 'ORDER BY deleted ASC' ) || empty( $row['deleted'] ) ) ) {
						return $row;
					}
				}
			}
			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_delivery_services' ) ) {
				return array_values( array_filter( $this->services, static fn ( array $row ): bool => empty( $row['deleted'] ) ) );
			}
			return array();
		}
	}
}

final class DpdFakeSoapClient implements DpdSoapClientInterface {
	public bool $available = false;
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse {
		$this->calls[] = compact( 'service', 'method', 'payload', 'credentials', 'options' );
		if ( ! $this->available ) {
			throw new DpdException( 'PHP SOAP extension is not available.' );
		}
		return new DpdSoapResponse( true, array( 'ok' => true ) );
	}

	public function is_available(): bool {
		return $this->available;
	}
}

$GLOBALS['wdc_dpd_options'] = array();
$GLOBALS['wpdb'] = new wpdb();

$settings_repository = new SettingsRepository();
$encryption = new EncryptionService();
$settings = new DpdSettings( $settings_repository, $encryption );

dpd_smoke_assert( DpdSettings::ENV_TEST === $settings->environment(), 'DPD default environment must be test.' );
dpd_smoke_assert( DpdSettings::DEFAULT_REQUEST_TIMEOUT === $settings->request_timeout(), 'DPD default timeout mismatch.' );
dpd_smoke_assert( isset( $settings_repository->defaults()[ DpdSettings::TEST_CLIENT_KEY_ENCRYPTED_KEY ] ), 'DPD defaults must be registered in core settings.' );

$settings->save_from_admin(
	array(
		DpdSettings::ENVIRONMENT_KEY => DpdSettings::ENV_TEST,
		DpdSettings::TEST_CLIENT_NUMBER_KEY => 'test-client-number',
		'dpd_test_client_key' => 'test-client-key',
		DpdSettings::PRODUCTION_CLIENT_NUMBER_KEY => 'production-client-number',
		'dpd_production_client_key' => 'production-client-key',
		DpdSettings::REQUEST_TIMEOUT_KEY => 25,
		DpdSettings::DEBUG_KEY => '1',
	)
);

$stored = get_option( 'wdc_core_settings', array() );
dpd_smoke_assert( 'test-client-number' === $settings->credentials()->client_number, 'DPD test client number mismatch.' );
dpd_smoke_assert( 'test-client-key' === $settings->credentials()->client_key, 'DPD test client key decrypt mismatch.' );
dpd_smoke_assert( 'test-client-key' !== (string) ( $stored[ DpdSettings::TEST_CLIENT_KEY_ENCRYPTED_KEY ] ?? '' ), 'DPD test client key must not be stored plaintext.' );
dpd_smoke_assert( 'production-client-key' !== (string) ( $stored[ DpdSettings::PRODUCTION_CLIENT_KEY_ENCRYPTED_KEY ] ?? '' ), 'DPD production client key must not be stored plaintext.' );
dpd_smoke_assert( 25 === $settings->request_timeout(), 'DPD timeout must be saved.' );
dpd_smoke_assert( $settings->debug_enabled(), 'DPD debug flag must be saved.' );

$test_map = DpdEndpoints::wsdl_map( DpdSettings::ENV_TEST );
$production_map = DpdEndpoints::wsdl_map( DpdSettings::ENV_PRODUCTION );
dpd_smoke_assert( 'https://wstest.dpd.ru/services/geography2?wsdl' === $test_map[ DpdEndpoints::SERVICE_GEOGRAPHY ], 'DPD test geography endpoint mismatch.' );
dpd_smoke_assert( 'https://ws.dpd.ru/services/calculator2?wsdl' === $production_map[ DpdEndpoints::SERVICE_CALCULATOR ], 'DPD production calculator endpoint mismatch.' );
dpd_smoke_assert( isset( $production_map[ DpdEndpoints::SERVICE_ORDER ], $production_map[ DpdEndpoints::SERVICE_TRACING ], $production_map[ DpdEndpoints::SERVICE_TRACING_1_1 ], $production_map[ DpdEndpoints::SERVICE_EVENT_TRACKING ], $production_map[ DpdEndpoints::SERVICE_LABEL_PRINT ], $production_map[ DpdEndpoints::SERVICE_DELIVERY_MANAGEMENT ] ), 'DPD endpoint map must include planned service areas.' );

$geography_request = new DpdSoapRequest( DpdEndpoints::SERVICE_GEOGRAPHY, 'getCitiesCashPay', array( 'countryCode' => 'RU' ), $settings->credentials() );
$geography_payload = $geography_request->payload_with_auth();
dpd_smoke_assert( isset( $geography_payload['auth']['clientNumber'], $geography_payload['auth']['clientKey'] ) && ! isset( $geography_payload['request'] ), 'DPD geography methods must keep direct auth payload shape.' );
$calculator_request = new DpdSoapRequest( DpdEndpoints::SERVICE_CALCULATOR, 'getServiceCostByParcels2', array( 'pickup' => array( 'cityId' => '49455627' ), 'delivery' => array( 'cityId' => '49694102' ) ), $settings->credentials(), array( 'wrapper' => DpdSoapRequest::WRAPPER_REQUEST ) );
$calculator_payload = $calculator_request->payload_with_auth();
$calculator_shape = $calculator_request->redacted_payload_shape();
dpd_smoke_assert( isset( $calculator_payload['request']['auth']['clientNumber'], $calculator_payload['request']['auth']['clientKey'] ) && ! isset( $calculator_payload['auth'] ), 'DPD calculator2 getServiceCostByParcels2 must use request wrapper auth shape.' );
dpd_smoke_assert( DpdSoapRequest::WRAPPER_REQUEST === $calculator_shape['wrapper'] && 'yes' === $calculator_shape['has_auth'] && ! str_contains( (string) json_encode( $calculator_shape ), 'test-client-key' ), 'DPD calculator debug payload shape must be redacted.' );

$fake_soap = new DpdFakeSoapClient();
$api = new DpdApiClient( $settings, $fake_soap );
$diagnostic = $api->checkConnectionDryRun();
dpd_smoke_assert( false === $diagnostic['success'], 'DPD dry diagnostic must fail gracefully when SOAP transport is unavailable.' );
dpd_smoke_assert( isset( $diagnostic['details']['endpoints'][ DpdEndpoints::SERVICE_GEOGRAPHY ], $diagnostic['details']['endpoints'][ DpdEndpoints::SERVICE_CALCULATOR ] ), 'DPD dry diagnostic must inspect geography and calculator endpoints without live calls.' );
dpd_smoke_assert( 0 === count( $fake_soap->calls ), 'DPD dry diagnostic must not execute a SOAP API call.' );
$settings->save_connection_result( false, 'clientKey=test-client-key clientNumber test-client-number production-client-key production-client-number' );
dpd_smoke_assert( '2026-06-16 12:00:00' === $settings->last_connection_check(), 'DPD connection timestamp must be saved.' );
dpd_smoke_assert( 'error' === $settings->last_connection_status(), 'DPD connection status must be saved.' );
dpd_smoke_assert( str_contains( $settings->last_connection_message(), 'Среда: Тестовая' ), 'DPD diagnostics must include active environment.' );
dpd_smoke_assert( ! str_contains( $settings->last_connection_message(), 'test-client-key' ), 'DPD test client key must not be exposed in diagnostics.' );
dpd_smoke_assert( ! str_contains( $settings->last_connection_message(), 'production-client-key' ), 'DPD production client key must not be exposed in diagnostics.' );
dpd_smoke_assert( ! str_contains( $settings->last_connection_message(), 'test-client-number' ), 'DPD test client number must not be exposed in diagnostics.' );

$settings->save_geography_action_result(
	array(
		'type' => 'success',
		'title' => 'DPD geography diagnostic',
		'message' => 'DPD cityId mapping found.',
		'details' => array(
			'location_id' => 92468,
			'cityId' => '49455627',
			'source' => 'mapping',
			'matched_by' => array( 'stored_mapping' ),
			'secret' => 'clientKey=test-client-key',
		),
	)
);
$geography_action_result = $settings->get_geography_action_result();
dpd_smoke_assert( 'success' === (string) $geography_action_result['type'], 'DPD geography action result type must be saved.' );
dpd_smoke_assert( 'DPD geography diagnostic' === (string) $geography_action_result['title'], 'DPD geography action result title must be saved.' );
dpd_smoke_assert( '49455627' === (string) $geography_action_result['details']['cityId'], 'DPD geography action result details must be saved.' );
dpd_smoke_assert( ! str_contains( (string) $geography_action_result['details']['secret'], 'test-client-key' ), 'DPD geography action result details must be redacted.' );
$settings->clear_geography_action_result();
dpd_smoke_assert( array() === $settings->get_geography_action_result(), 'DPD geography action result must be clearable.' );

$redacted = ( new LogRedactor() )->redact_context( array( 'clientKey' => 'secret', 'auth' => array( 'client_key' => 'nested' ) ) );
dpd_smoke_assert( '[redacted]' === $redacted['clientKey'] && '[redacted]' === $redacted['auth']['client_key'], 'Log redactor must redact DPD client keys.' );

$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$dpd_service = $services->ensure_dpd_service();
dpd_smoke_assert( DpdSettings::SERVICE_KEY === $dpd_service->service_key, 'DPD service key mismatch.' );
dpd_smoke_assert( DpdSettings::CARRIER_KEY === $dpd_service->carrier_key, 'DPD carrier key mismatch.' );
dpd_smoke_assert( false === $dpd_service->enabled, 'DPD delivery service must be disabled by default.' );
dpd_smoke_assert( $services->is_predefined_service_key( DpdSettings::SERVICE_KEY ), 'DPD service must be predefined.' );

$registry = new CarrierRegistry();
dpd_smoke_assert( ! $registry->has( DpdSettings::CARRIER_KEY ), 'Empty test registry must start without DPD before explicit runtime registration.' );
$services->update_service( (int) $dpd_service->id, array( 'enabled' => 1 ) );
$enabled_dpd = $services->find_by_service_key( DpdSettings::SERVICE_KEY );
$service_registry = new DeliveryServiceRegistry( $services, $registry );
dpd_smoke_assert( null !== $enabled_dpd && true === $enabled_dpd->enabled, 'DPD service enabled flag must be stored in common delivery service settings.' );
dpd_smoke_assert( null === $service_registry->carrier_for( $enabled_dpd ), 'Enabled DPD service must still depend on explicit checkout registry wiring.' );
$shipment_registry = new CarrierShipmentAdapterRegistry();
dpd_smoke_assert( ! $shipment_registry->has( DpdSettings::CARRIER_KEY ), 'Empty shipment registry must not contain DPD before explicit adapter registration.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
dpd_smoke_assert( str_contains( $admin_source, 'save_dpd_settings' ) && str_contains( $admin_source, 'check_dpd_connection' ), 'Delivery service admin must expose DPD settings and diagnostic actions.' );
dpd_smoke_assert( str_contains( $admin_source, 'render_dpd_geography_action_result' ) && str_contains( $admin_source, 'save_geography_action_result' ), 'Delivery service admin must render and save DPD geography action results.' );
dpd_smoke_assert( str_contains( $admin_source, 'DPD SFTP import' ) && str_contains( $admin_source, 'DPD DaData fallback' ), 'DPD geography actions must save visible result titles.' );
dpd_smoke_assert( str_contains( $admin_source, 'check_admin_referer( \'wdc_delivery_services\' )' ) && str_contains( $admin_source, 'current_user_can( AdminMenu::CAPABILITY )' ), 'Admin DPD actions must remain behind nonce and capability checks.' );
dpd_smoke_assert( str_contains( $plugin_source, 'DpdQuoteCarrier' ), 'Plugin must register the DPD checkout runtime quote carrier.' );
dpd_smoke_assert( str_contains( $plugin_source, 'DpdShipmentAdapter' ) && str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdShipmentAdapter.php' ), 'dpd_create_disabled' ), 'Plugin may register only the DPD dry-run shipment adapter with disabled live create.' );
dpd_smoke_assert( ! str_contains( $admin_source, 'test-client-key' ) && ! str_contains( $admin_source, 'production-client-key' ), 'Admin source must not contain DPD credentials.' );

echo "DPD foundation smoke test passed.\n";
