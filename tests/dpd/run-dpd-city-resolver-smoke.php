<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $delivery_codes = array();
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		public int $insert_id = 0;
	}
}

function current_time( string $type ): string {
	static $tick = 0;
	++$tick;

	return '2026-06-16 12:00:' . str_pad( (string) $tick, 2, '0', STR_PAD_LEFT );
}

function wp_json_encode( mixed $value ): string|false {
	return json_encode( $value );
}

function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['wdc_test_options'][ $name ] ?? $default;
}

function update_option( string $name, mixed $value, bool $autoload = true ): bool {
	$GLOBALS['wdc_test_options'][ $name ] = $value;

	return true;
}

function wp_salt( string $scheme = '' ): string {
	return 'wdc-test-salt-' . $scheme;
}

require_once __DIR__ . '/../../src/Domain/Status/DeliveryStatus.php';
require_once __DIR__ . '/../../src/Shipments/Cdek/CdekStatusMappingService.php';
require_once __DIR__ . '/../../src/Shipments/Dpd/DpdStatusMapping.php';
require_once __DIR__ . '/../../src/Carriers/Cdek/CdekSettings.php';
require_once __DIR__ . '/../../src/Infrastructure/Settings/SettingsRepository.php';
require_once __DIR__ . '/../../src/Infrastructure/Security/EncryptionService.php';
require_once __DIR__ . '/../../src/Locations/ValueObjects/Location.php';
require_once __DIR__ . '/../../src/Locations/Storage/LocationRepository.php';
require_once __DIR__ . '/../../src/Locations/Storage/LocationDeliveryCodeRepository.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdSettings.php';
require_once __DIR__ . '/../../src/Carriers/YandexDelivery/YandexDeliverySettings.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdCredentials.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdEndpoints.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdException.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdSoapResponse.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdSoapClientInterface.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdApiClient.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdCityResolver.php';
require_once __DIR__ . '/../../src/Carriers/Dpd/DpdGeographyDiagnosticService.php';

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCityResolver;
use WallsShop\WDC\Carriers\Dpd\DpdCredentials;
use WallsShop\WDC\Carriers\Dpd\DpdGeographyDiagnosticService;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSoapClientInterface;
use WallsShop\WDC\Carriers\Dpd\DpdSoapResponse;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

final class FakeDpdSoapClient implements DpdSoapClientInterface {
	/** @var array<int,array{method:string,payload:array<string,mixed>}> */
	public array $calls = array();

	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse {
		$this->calls[] = array( 'method' => $method, 'payload' => $payload );

		return new DpdSoapResponse( true, array( 'return' => array() ) );
	}

	public function is_available(): bool {
		return true;
	}
}

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wdc_test_options'] = array(
	'dpd_test_client_number' => 'client-number',
	'dpd_test_client_key_encrypted' => ( new EncryptionService() )->encrypt( 'secret-client-key' ),
);
$GLOBALS['wpdb']->locations[] = array(
	'id' => 10,
	'fias_id' => '11111111-2222-3333-4444-555555555555',
	'gar_id' => '123456789',
	'gar_object_id' => 123456789,
	'kladr_id' => '5400000200000',
	'country_code' => 'RU',
	'region_code' => '54',
	'region_name' => 'Новосибирская область',
	'city_name' => 'Бердск',
	'settlement_name' => 'Бердск',
	'place_name' => 'Бердск',
	'place_type' => 'г',
	'display_name' => 'Бердск, Новосибирская область',
	'postal_code' => '633010',
	'active' => 1,
);

$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$soap = new FakeDpdSoapClient();
$api = new DpdApiClient( $settings, $soap );
$delivery_codes = new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] );
$resolver = new DpdCityResolver( $delivery_codes );
$diagnostics = new DpdGeographyDiagnosticService( $resolver, $delivery_codes, new LocationRepository( $GLOBALS['wpdb'] ) );

$location = Location::from_array( $GLOBALS['wpdb']->locations[0] );
$result = $resolver->resolve( $location );
assert_true( null === $result, 'resolver returns null when DPD cityId mapping is missing' );
assert_true( 'DPD cityId mapping was not found. Run DPD geography import, DaData fallback, or manual mapping.' === $resolver->last_error(), 'resolver exposes import/DaData/manual mapping required message' );
assert_true( 0 === count( $soap->calls ), 'resolver does not call DPD API when mapping is missing' );

$missing_diagnostic = $diagnostics->diagnose_location_id( 10 );
assert_true( false === $missing_diagnostic['success'], 'diagnostic returns success=false when mapping is missing' );
assert_true( str_contains( $missing_diagnostic['message'], 'location_id=10' ), 'diagnostic message includes location_id' );
assert_true( str_contains( $missing_diagnostic['message'], 'Run DPD geography import, DaData fallback, or add cityId manually' ), 'diagnostic asks for import/DaData/manual cityId mapping' );
assert_true( 0 === count( $soap->calls ), 'diagnostic does not call DPD API when mapping is missing' );

$manual_result = $diagnostics->save_manual_mapping( 10, '900001' );
assert_true( true === $manual_result['success'], 'admin service can save a manual DPD cityId mapping' );
assert_true( 1 === count( $GLOBALS['wpdb']->delivery_codes ), 'manual save writes one delivery-code row' );
assert_true( '900001' === (string) $GLOBALS['wpdb']->delivery_codes[0]['dpd_city_id'], 'manual admin mapping updates dpd_city_id' );

$call_count_before_cached_diagnostic = count( $soap->calls );
$cached_diagnostic = $diagnostics->diagnose_location_id( 10 );
assert_true( true === $cached_diagnostic['success'], 'diagnostic can resolve from existing mapping' );
assert_true( '900001' === $cached_diagnostic['city_id'], 'diagnostic returns stored mapping cityId' );
assert_true( 'mapping' === $cached_diagnostic['source'], 'diagnostic source is mapping after manual save' );
assert_true( $call_count_before_cached_diagnostic === count( $soap->calls ), 'existing mapping prevents API call in diagnostic' );

$cities_wrapper = $api->getCitiesCashPay( array( 'countryCode' => 'RU' ) );
assert_true( true === $cities_wrapper['success'], 'getCitiesCashPay wrapper returns normalized fake response' );
$extra_wrapper = $api->getPossibleExtraService( array( 'request' => 'fake' ) );
assert_true( true === $extra_wrapper['success'], 'getPossibleExtraService wrapper remains available as low-level wrapper' );

$plugin_source = file_get_contents( __DIR__ . '/../../src/Core/Plugin.php' );
assert_true( is_string( $plugin_source ) && str_contains( $plugin_source, 'DpdShipmentAdapter' ), 'DPD dry-run shipment adapter is registered outside city resolver runtime.' );
assert_true( is_string( $plugin_source ) && ! str_contains( $plugin_source, 'DpdCarrier' ), 'DPD runtime carrier is not registered' );
assert_true( is_string( $plugin_source ) && ! str_contains( $plugin_source, 'LocationCarrierCodeRepository' ), 'legacy LocationCarrierCodeRepository is not registered' );

echo "DPD city resolver smoke OK\n";
