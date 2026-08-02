<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekHttpClientInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekRequestBudget;
use WallsShop\WDC\Carriers\Pek\Geography\PekAddressBuilder;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationMappingRepository;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationResolver;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Pickup\PekCargoConstraintsConverter;
use WallsShop\WDC\Carriers\Pek\Pickup\PekDestinationTerminalSearchCache;
use WallsShop\WDC\Carriers\Pek\Pickup\PekPickupPointProvider;
use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalRepository;
use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalService;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string { unset( $type ); return '2026-08-03 10:00:00'; }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_pickup_options'][ $option ] ?? $default; }
	function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['pek_pickup_options'][ $option ] = $value; return true; }
}
$GLOBALS['wdc_transients'] = array();
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $ttl ): bool { $GLOBALS['wdc_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
	function get_transient( string $key ): mixed { return $GLOBALS['wdc_transients'][ $key ]['value'] ?? false; }
}

function pek_pickup_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class PekPickupFakeHttp implements PekHttpClientInterface {
	public array $requests = array();
	public function request( string $method, string $url, array $args ): array {
		$this->requests[] = compact( 'method', 'url', 'args' );
		$path = (string) parse_url( $url, PHP_URL_PATH );
		if ( str_contains( $path, 'findzonebycoordinates' ) ) {
			return array( 'status' => 200, 'body' => wp_json_encode( array( array( 'zoneId' => 'zone', 'zoneName' => 'Zone', 'branchUID' => 'branch', 'branchTitle' => 'Branch', 'warehousePoint' => array( 'latitude' => 55.0, 'longitude' => 82.0 ) ) ) ) );
		}
		return array( 'status' => 200, 'body' => wp_json_encode( array(
			'freeDepartments' => array(
				array( 'warehouseId' => 'free-ok', 'branchId' => 'b1', 'branchName' => 'Новосибирск', 'divisionName' => 'Центр', 'departmentTypeId' => 0, 'departmentType' => 'Отделение компании', 'address' => 'Адрес 1', 'coordinates' => array( 'latitude' => 55.1, 'longitude' => 82.9 ), 'timeZone' => '07:00:00', 'priority' => 10, 'maxWeight' => 0.0, 'maxVolume' => 0.0, 'maxDimension' => 0.0, 'maxWeightOnePlace' => 0.0, 'maxCount' => 0 ),
				array( 'warehouseId' => 'too-small', 'branchName' => 'Новосибирск', 'address' => 'Адрес 2', 'coordinates' => array( 'latitude' => 55.2, 'longitude' => 82.8 ), 'maxWeight' => 0.5, 'maxVolume' => 0, 'maxDimension' => 0, 'maxWeightOnePlace' => 0, 'maxCount' => 0 ),
				array( 'warehouseId' => '', 'address' => 'bad', 'coordinates' => array( 'latitude' => 55.2, 'longitude' => 82.8 ) ),
			),
			'paidDepartments' => array(
				array( 'warehouseId' => 'paid-ok', 'branchId' => 'b2', 'branchName' => 'Новосибирск', 'divisionName' => 'ПВЗ', 'departmentTypeId' => 1, 'departmentType' => 'ПВЗ', 'address' => 'Адрес 3', 'coordinates' => array( 'latitude' => 55.3, 'longitude' => 82.7 ), 'timeZone' => '07:30:00', 'priority' => 5, 'maxWeight' => 10, 'maxVolume' => 1, 'maxDimension' => 2, 'maxWeightOnePlace' => 10, 'maxCount' => 10 ),
			),
		) ) );
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $locations = array();
		public array $pek_location_mappings = array();
		public array $pek_terminals = array();
	}
}

$wpdb = new wpdb();
$GLOBALS['pek_pickup_options'] = array();
define( 'APP_ENCRYPTION_KEY', 'pek-pickup-test-key' );
$wpdb->pek_location_mappings = array();
$wpdb->pek_terminals = array();
$wpdb->locations = array( array( 'id' => 10, 'country_code' => 'RU', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'city_name' => 'Новосибирск', 'city_type' => 'г', 'place_name' => 'Новосибирск', 'place_type' => 'г', 'display_name' => 'Новосибирск', 'latitude' => 55.030204, 'longitude' => 82.92043, 'active' => 1, 'fias_id' => 'fias', 'gar_object_id' => 1, 'region_code' => '54' ) );

$settings_repo = new SettingsRepository();
$settings = new PekSettings( $settings_repo );
$credentials = new PekCredentials( $settings_repo, new EncryptionService( 'test-key' ) );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret' ) );
$http = new PekPickupFakeHttp();
$api = new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) );
$resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), $api, $settings );
$repo = new PekTerminalRepository( $wpdb );
$service = new PekTerminalService( $resolver, $api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), $repo, $settings );
$provider = new PekPickupPointProvider( $service );
$query = new CarrierPickupPointQuery( 'pek', 10, 'ru', '', null, null, new PickupCargoConstraints( 1000, 1000, 10, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 );

$converted = ( new PekCargoConstraintsConverter() )->convert( new PickupCargoConstraints( 1001, 1, 1, 1001, 2 ) );
pek_pickup_assert( 1.001 === $converted['weight_kg'] && 0.000001 === $converted['volume_m3'] && 0.01 === $converted['max_dimension_m'] && 1.001 === $converted['max_weight_per_place_kg'], 'PEK cargo conversion must be upward-safe.' );

$points = $provider->search( $query );
pek_pickup_assert( count( $points ) === 2, 'PEK provider must return free and paid points after filtering invalid/limit rows.' );
pek_pickup_assert( $points[0]->type === 'terminal' && $points[0]->raw_reference['source'] === 'free', 'freeDepartments must become terminal source free.' );
pek_pickup_assert( $points[1]->type === 'pvz' && $points[1]->raw_reference['source'] === 'paid', 'paidDepartments must become pvz source paid.' );
pek_pickup_assert( $points[0]->raw_reference['timezone'] === 'UTC+07:00', 'PEK destination timeZone must normalize to canonical timezone.' );
pek_pickup_assert( count( $wpdb->pek_terminals ) === 2 && array() !== $repo->find_by_warehouse_id( 'free-ok' ), 'Terminal repository must upsert found terminals.' );
$nearest_request = $http->requests[1];
$payload = json_decode( (string) $nearest_request['args']['body'], true );
pek_pickup_assert( 3 === (int) $payload['departmentOperation'] && 3 === (int) $payload['type'], 'Destination nearestdepartments must use operation=3 and type=3.' );
pek_pickup_assert( isset( $payload['coordinates'] ) && ! isset( $payload['address'] ), 'Coordinate request must not send conflicting address.' );

$requests_after_first = count( $http->requests );
$cached = $provider->search( $query );
pek_pickup_assert( count( $cached ) === 2 && count( $http->requests ) === $requests_after_first, 'Terminal search cache hit must avoid API.' );
$selected = $provider->resolve_selection( new CarrierPickupPointSelectionQuery( $query, 'paid-ok' ) );
pek_pickup_assert( null !== $selected && $selected->code === 'paid-ok' && count( $http->requests ) > $requests_after_first, 'resolve_selection must perform fresh API validation.' );
pek_pickup_assert( array() === $repo->find_by_ids( array( 'missing-scoped-absence' ) ), 'Scoped search absence must not globally deactivate or invent terminals.' );
$json = wp_json_encode( $points[0]->raw_reference );
pek_pickup_assert( ! str_contains( $json, 'secret' ) && ! str_contains( $json, 'Authorization' ) && ! str_contains( $json, 'freeDepartments' ), 'PickupPoint raw_reference must stay safe.' );
$schema = $repo->schema();
foreach ( array( 'wdc_pek_terminals', 'warehouse_id', 'max_weight_one_place', 'availability_json', 'fetched_at' ) as $needle ) {
	pek_pickup_assert( str_contains( $schema, $needle ), 'PEK terminal schema must contain ' . $needle );
}

echo "PEK pickup provider smoke OK\n";
