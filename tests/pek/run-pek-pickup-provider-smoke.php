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
	public array $nearest_response;
	public function __construct( array $nearest_response = array() ) {
		$this->nearest_response = array() === $nearest_response ? array(
			'freeDepartments' => array(
				array( 'warehouseId' => 'free-ok', 'branchId' => 'b1', 'branchName' => 'Новосибирск', 'divisionName' => 'Центр', 'departmentTypeId' => 0, 'departmentType' => 'Отделение компании', 'address' => 'Адрес 1', 'coordinates' => array( 'latitude' => 55.1, 'longitude' => 82.9 ), 'timeZone' => '07:00:00', 'priority' => 10, 'maxWeight' => 0.0, 'maxVolume' => 0.0, 'maxDimension' => 0.0, 'maxWeightOnePlace' => 0.0, 'maxCount' => 0 ),
				array( 'warehouseId' => 'too-small', 'branchName' => 'Новосибирск', 'address' => 'Адрес 2', 'coordinates' => array( 'latitude' => 55.2, 'longitude' => 82.8 ), 'maxWeight' => 0.5, 'maxVolume' => 0, 'maxDimension' => 0, 'maxWeightOnePlace' => 0, 'maxCount' => 0 ),
				array( 'warehouseId' => '', 'address' => 'bad', 'coordinates' => array( 'latitude' => 55.2, 'longitude' => 82.8 ) ),
			),
			'paidDepartments' => array(
				array( 'warehouseId' => 'paid-ok', 'branchId' => 'b2', 'branchName' => 'Новосибирск', 'divisionName' => 'ПВЗ', 'departmentTypeId' => 1, 'departmentType' => 'ПВЗ', 'address' => 'Адрес 3', 'coordinates' => array( 'latitude' => 55.3, 'longitude' => 82.7 ), 'timeZone' => '07:30:00', 'priority' => 5, 'maxWeight' => 10, 'maxVolume' => 1, 'maxDimension' => 2, 'maxWeightOnePlace' => 10, 'maxCount' => 10 ),
			),
		) : $nearest_response;
	}
	public function request( string $method, string $url, array $args ): array {
		$this->requests[] = compact( 'method', 'url', 'args' );
		$path = (string) parse_url( $url, PHP_URL_PATH );
		if ( str_contains( $path, 'findzonebycoordinates' ) ) {
			return array( 'status' => 200, 'body' => wp_json_encode( array( array( 'zoneId' => 'zone', 'zoneName' => 'Zone', 'branchUID' => 'branch', 'branchTitle' => 'Branch', 'warehousePoint' => array( 'latitude' => 55.0, 'longitude' => 82.0 ) ) ) ) );
		}
		if ( str_contains( $path, 'findzonebyaddress' ) ) {
			return array( 'status' => 200, 'body' => wp_json_encode( array( 'zoneId' => 'zone-address', 'zoneName' => 'Zone address', 'branchUID' => 'branch-address', 'branchTitle' => 'Branch address', 'warehousePoint' => array( 'latitude' => 56.0, 'longitude' => 83.0 ), 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'country_code' => 'RU', 'formatted' => 'Россия, Линево' ) ) ) ) );
		}
		return array( 'status' => 200, 'body' => wp_json_encode( $this->nearest_response ) );
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $locations = array();
		public array $pek_location_mappings = array();
		public array $pek_terminals = array();
		public string $last_error = '';
		public bool $pek_terminal_insert_fails = false;
		public bool $pek_terminal_update_fails = false;
		public bool $pek_terminal_read_fails = false;
		public bool $pek_terminal_statistics_fails = false;
	}
}

$wpdb = new wpdb();
$GLOBALS['pek_pickup_options'] = array();
define( 'APP_ENCRYPTION_KEY', 'pek-pickup-test-key' );
$wpdb->pek_location_mappings = array();
$wpdb->pek_terminals = array();
$wpdb->locations = array(
	array( 'id' => 10, 'country_code' => 'RU', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'city_name' => 'Новосибирск', 'city_type' => 'г', 'place_name' => 'Новосибирск', 'place_type' => 'г', 'display_name' => 'Новосибирск', 'latitude' => 55.030204, 'longitude' => 82.92043, 'active' => 1, 'fias_id' => 'fias', 'gar_object_id' => 1, 'region_code' => '54' ),
	array( 'id' => 11, 'country_code' => 'RU', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'district_name' => 'Искитимский', 'district_type' => 'р-н', 'place_name' => 'Линево', 'place_type' => 'рп', 'display_name' => 'Линево', 'active' => 1, 'fias_id' => 'fias2', 'gar_object_id' => 2, 'region_code' => '54' ),
);

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
pek_pickup_assert( isset( $payload['coordinates'] ) && 55.030204 === (float) $payload['coordinates']['latitude'] && 82.92043 === (float) $payload['coordinates']['longitude'] && ! isset( $payload['address'] ), 'Coordinate request must use canonical coordinates only and not send conflicting address.' );

$requests_after_first = count( $http->requests );
$cached = $provider->search( $query );
pek_pickup_assert( count( $cached ) === 2 && count( $http->requests ) === $requests_after_first, 'Terminal search cache hit must avoid API.' );
$selected = $provider->resolve_selection( new CarrierPickupPointSelectionQuery( $query, 'paid-ok' ) );
pek_pickup_assert( null !== $selected && $selected->code === 'paid-ok' && count( $http->requests ) > $requests_after_first, 'resolve_selection must perform fresh API validation.' );
pek_pickup_assert( ! property_exists( new CarrierPickupPointSelectionQuery( $query, 'paid-ok' ), 'fresh_validation_required' ), 'Selection query must not expose unused fresh_validation_required flag.' );
pek_pickup_assert( array() === $repo->find_by_ids( array( 'missing-scoped-absence' ) ), 'Scoped search absence must not globally deactivate or invent terminals.' );
$json = wp_json_encode( $points[0]->raw_reference );
pek_pickup_assert( ! str_contains( $json, 'secret' ) && ! str_contains( $json, 'Authorization' ) && ! str_contains( $json, 'freeDepartments' ), 'PickupPoint raw_reference must stay safe.' );
$schema = $repo->schema();
foreach ( array( 'wdc_pek_terminals', 'warehouse_id', 'max_weight_one_place', 'availability_json', 'fetched_at' ) as $needle ) {
	pek_pickup_assert( str_contains( $schema, $needle ), 'PEK terminal schema must contain ' . $needle );
}

$query_lat_only = new CarrierPickupPointQuery( 'pek', 0, 'RU', '', 55.0, null, new PickupCargoConstraints(), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 );
pek_pickup_assert( in_array( 'coordinates must contain both latitude and longitude', $query_lat_only->validate(), true ), 'Generic query must reject incomplete coordinate pair.' );
pek_pickup_assert( array() !== ( new CarrierPickupPointQuery( 'pek', 0, 'RU', '', 91.0, 82.0, new PickupCargoConstraints(), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) )->validate(), 'Generic query must reject latitude out of range.' );
pek_pickup_assert( array() !== ( new CarrierPickupPointQuery( 'pek', 0, 'RU', '', 55.0, 181.0, new PickupCargoConstraints(), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) )->validate(), 'Generic query must reject longitude out of range.' );
$requests_before_fallback_only = count( $http->requests );
$fallback_only = $provider->search( new CarrierPickupPointQuery( 'pek', 0, 'RU', 'Россия, Новосибирск', null, null, new PickupCargoConstraints( 1000, 1000, 10, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) );
pek_pickup_assert( array() === $fallback_only && count( $http->requests ) === $requests_before_fallback_only && 'pek_canonical_location_required' === (string) $service->last_report()['error_code'], 'PEK provider must reject fallback-only query without API call.' );
$fallback_selection = $provider->resolve_selection( new CarrierPickupPointSelectionQuery( new CarrierPickupPointQuery( 'pek', 0, 'RU', 'Россия, Новосибирск', null, null, new PickupCargoConstraints( 1000, 1000, 10, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ), 'free-ok' ) );
pek_pickup_assert( null === $fallback_selection && count( $http->requests ) === $requests_before_fallback_only, 'PEK resolve_selection must reject fallback-only query without API call.' );

$address_http = new PekPickupFakeHttp();
$address_api = new PekApiClient( $settings, $credentials, $address_http, new PekRequestBudget( $settings ) );
$address_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), $address_api, $settings );
$address_service = new PekTerminalService( $address_resolver, $address_api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), new PekTerminalRepository( $wpdb ), $settings );
$address_points = $address_service->search( new CarrierPickupPointQuery( 'pek', 11, 'RU', '', 1.0, 2.0, new PickupCargoConstraints( 1000, 1000, 10, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) );
$address_payload = json_decode( (string) $address_http->requests[1]['args']['body'], true );
pek_pickup_assert( count( $address_points ) === 2 && isset( $address_payload['address'] ) && ! isset( $address_payload['coordinates'] ), 'Address-only canonical location must send address only and ignore query override/warehousePoint coordinates.' );

$mismatch_requests_before = count( $address_http->requests );
$mismatch_points = $address_service->search( new CarrierPickupPointQuery( 'pek', 11, 'KZ', '', null, null, new PickupCargoConstraints( 1000, 1000, 10, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) );
pek_pickup_assert( array() === $mismatch_points && count( $address_http->requests ) === $mismatch_requests_before && 'pek_destination_country_mismatch' === (string) $address_service->last_report()['error_code'], 'Country mismatch must block terminal API and cache persistence.' );

$GLOBALS['wdc_transients'] = array();
$empty_http = new PekPickupFakeHttp( array( 'freeDepartments' => array(), 'paidDepartments' => array() ) );
$empty_api = new PekApiClient( $settings, $credentials, $empty_http, new PekRequestBudget( $settings ) );
$empty_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), $empty_api, $settings );
$empty_service = new PekTerminalService( $empty_resolver, $empty_api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), new PekTerminalRepository( $wpdb ), $settings );
$empty_query = new CarrierPickupPointQuery( 'pek', 10, 'RU', '', null, null, new PickupCargoConstraints( 1000, 1000, 10, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 );
$empty_first = $empty_service->search( $empty_query );
$empty_requests = count( $empty_http->requests );
$empty_second = $empty_service->search( $empty_query );
$empty_report = $empty_service->last_report();
pek_pickup_assert( array() === $empty_first && array() === $empty_second && count( $empty_http->requests ) === $empty_requests && true === $empty_report['success'] && true === $empty_report['cache_hit'] && 0 === $empty_report['total_returned'], 'Successful empty terminal result must be cached and reported as cache hit.' );

$multi_place = ( new PekCargoConstraintsConverter() )->convert( new PickupCargoConstraints( 10000, 10000000, 100, 1000, 10 ) );
pek_pickup_assert( 10.0 === $multi_place['volume_m3'], 'Total volume must include all cargo places.' );
$GLOBALS['wdc_transients'] = array();
$volume_http = new PekPickupFakeHttp( array( 'freeDepartments' => array( array( 'warehouseId' => 'volume-small', 'branchName' => 'Branch', 'address' => 'Address', 'coordinates' => array( 'latitude' => 55.0, 'longitude' => 82.0 ), 'maxWeight' => 0, 'maxVolume' => 5, 'maxDimension' => 0, 'maxWeightOnePlace' => 0, 'maxCount' => 0 ) ), 'paidDepartments' => array() ) );
$volume_api = new PekApiClient( $settings, $credentials, $volume_http, new PekRequestBudget( $settings ) );
$volume_service = new PekTerminalService( new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), $volume_api, $settings ), $volume_api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), new PekTerminalRepository( $wpdb ), $settings );
$volume_points = $volume_service->search( new CarrierPickupPointQuery( 'pek', 10, 'RU', '', null, null, new PickupCargoConstraints( 10000, 10000000, 100, 1000, 10 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) );
pek_pickup_assert( array() === $volume_points && 1 === (int) $volume_service->last_report()['rejected_limits'], 'Terminal maxVolume policy must compare against total volume.' );

$repo->upsert_many( array( array( 'warehouse_id' => 'created', 'source' => 'free', 'country_code' => 'RU', 'created_at' => '2026-01-01 00:00:00' ) ) );
$repo->upsert_many( array( array( 'warehouse_id' => 'created', 'source' => 'free', 'country_code' => 'RU' ) ) );
pek_pickup_assert( '2026-01-01 00:00:00' === $repo->find_by_warehouse_id( 'created' )['created_at'], 'Terminal update must preserve created_at.' );
$wpdb->pek_terminal_insert_fails = true;
try {
	$repo->upsert_many( array( array( 'warehouse_id' => 'fail', 'source' => 'free', 'country_code' => 'RU' ) ) );
	pek_pickup_assert( false, 'Terminal insert failure must fail closed.' );
} catch ( RuntimeException ) {
	$wpdb->pek_terminal_insert_fails = false;
}
$wpdb->pek_terminal_update_fails = true;
try {
	$repo->upsert_many( array( array( 'warehouse_id' => 'created', 'source' => 'free', 'country_code' => 'RU' ) ) );
	pek_pickup_assert( false, 'Terminal update failure must fail closed.' );
} catch ( RuntimeException ) {
	$wpdb->pek_terminal_update_fails = false;
}
$wpdb->pek_terminal_read_fails = true;
try {
	$repo->find_by_warehouse_id( 'created' );
	pek_pickup_assert( false, 'Terminal read SQL error must fail closed.' );
} catch ( RuntimeException ) {
	$wpdb->pek_terminal_read_fails = false;
}
$wpdb->pek_terminal_statistics_fails = true;
try {
	$repo->statistics();
	pek_pickup_assert( false, 'Terminal statistics SQL error must fail closed.' );
} catch ( RuntimeException ) {
	$wpdb->pek_terminal_statistics_fails = false;
}
$terminal_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Pek/Pickup/PekTerminalRepository.php' );
pek_pickup_assert( ! str_contains( $terminal_source, 'create_schema_if_needed' ), 'PEK terminal repository must not expose runtime create_schema_if_needed.' );

echo "PEK pickup provider smoke OK\n";
