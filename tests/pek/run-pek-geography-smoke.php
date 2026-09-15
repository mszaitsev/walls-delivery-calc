<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\Api\PekHttpClientInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekRequestBudget;
use WallsShop\WDC\Carriers\Pek\Geography\PekAddressBuilder;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationMappingRepository;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationResolver;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string { unset( $type ); return '2026-08-03 10:00:00'; }
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone(): DateTimeZone { return new DateTimeZone( '+07:00' ); }
}
if ( ! function_exists( 'current_datetime' ) ) {
	function current_datetime(): DateTimeImmutable { return new DateTimeImmutable( '2026-08-03 10:00:00', wp_timezone() ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_geo_options'][ $option ] ?? $default; }
	function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['pek_geo_options'][ $option ] = $value; return true; }
}
$GLOBALS['pek_geo_transients'] = array();
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $ttl ): bool { $GLOBALS['pek_geo_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
	function get_transient( string $key ): mixed { return $GLOBALS['pek_geo_transients'][ $key ]['value'] ?? false; }
}

function pek_geo_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class PekGeoFakeHttp implements PekHttpClientInterface {
	public array $requests = array();
	public function __construct( private array $responses ) {}
	public function request( string $method, string $url, array $args ): array {
		$this->requests[] = compact( 'method', 'url', 'args' );
		$key = $method . ' ' . parse_url( $url, PHP_URL_PATH );
		$body = $this->responses[ $key ] ?? array();
		if ( is_array( $body ) && isset( $body['__status'] ) ) {
			return array( 'status' => (int) $body['__status'], 'body' => wp_json_encode( $body['body'] ?? array() ) );
		}
		return array( 'status' => 200, 'body' => wp_json_encode( $body ) );
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $locations = array();
		public array $pek_location_mappings = array();
		public string $last_error = '';
		public bool $pek_location_mapping_insert_fails = false;
		public bool $pek_location_mapping_update_fails = false;
		public bool $pek_location_mapping_read_fails = false;
		public bool $pek_location_mapping_delete_fails = false;
		public bool $pek_location_mapping_statistics_fails = false;
	}
}

$wpdb = new wpdb();
$GLOBALS['pek_geo_options'] = array();
define( 'APP_ENCRYPTION_KEY', 'pek-geo-test-key' );
$wpdb->pek_location_mappings = array();
$wpdb->locations = array(
	array( 'id' => 10, 'country_code' => 'RU', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'city_name' => 'Новосибирск', 'city_type' => 'г', 'place_name' => 'Новосибирск', 'place_type' => 'г', 'display_name' => 'Новосибирск', 'latitude' => 55.030204, 'longitude' => 82.92043, 'active' => 1, 'fias_id' => 'fias', 'gar_object_id' => 1, 'region_code' => '54' ),
	array( 'id' => 11, 'country_code' => 'RU', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'district_name' => 'Искитимский', 'district_type' => 'р-н', 'place_name' => 'Линево', 'place_type' => 'рп', 'display_name' => 'Линево', 'active' => 1, 'fias_id' => 'fias2', 'gar_object_id' => 2, 'region_code' => '54' ),
	array( 'id' => 20, 'country_code' => 'BY', 'region_name' => 'Минская область', 'city_name' => 'Минск', 'city_type' => 'г', 'place_name' => 'Минск', 'place_type' => 'г', 'display_name' => 'Минск', 'active' => 1 ),
	array( 'id' => 21, 'country_code' => 'KZ', 'region_name' => 'Алматинская область', 'city_name' => 'Алматы', 'city_type' => 'г', 'place_name' => 'Алматы', 'place_type' => 'г', 'display_name' => 'Алматы', 'active' => 1 ),
	array( 'id' => 22, 'country_code' => 'AM', 'city_name' => 'Ереван', 'city_type' => 'г', 'place_name' => 'Ереван', 'place_type' => 'г', 'display_name' => 'Ереван', 'active' => 1 ),
	array( 'id' => 23, 'country_code' => 'KG', 'city_name' => 'Бишкек', 'city_type' => 'г', 'place_name' => 'Бишкек', 'place_type' => 'г', 'display_name' => 'Бишкек', 'active' => 1 ),
	array( 'id' => 24, 'country_code' => 'KZ', 'city_name' => 'Шымкент', 'city_type' => 'г', 'place_name' => 'Шымкент', 'place_type' => 'г', 'display_name' => 'Шымкент', 'active' => 1 ),
	array( 'id' => 31, 'country_code' => 'RU', 'city_name' => 'Partial Lat', 'display_name' => 'Partial Lat', 'latitude' => 55.0, 'active' => 1 ),
	array( 'id' => 32, 'country_code' => 'RU', 'city_name' => 'Partial Lng', 'display_name' => 'Partial Lng', 'longitude' => 82.0, 'active' => 1 ),
	array( 'id' => 33, 'country_code' => 'RU', 'city_name' => 'Bad Lat', 'display_name' => 'Bad Lat', 'latitude' => 91.0, 'longitude' => 82.0, 'active' => 1 ),
	array( 'id' => 34, 'country_code' => 'RU', 'city_name' => 'Bad Lng', 'display_name' => 'Bad Lng', 'latitude' => 55.0, 'longitude' => 181.0, 'active' => 1 ),
	array( 'id' => 99, 'country_code' => 'US', 'city_name' => 'Boston', 'place_name' => 'Boston', 'display_name' => 'Boston', 'active' => 1 ),
);

$settings_repo = new SettingsRepository();
$settings = new PekSettings( $settings_repo, new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() );
$encryption = new EncryptionService( 'test-key' );
$credentials = new PekCredentials( $settings_repo, $encryption );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret' ) );
$http = new PekGeoFakeHttp( array(
	'POST /api/v1/branches/findzonebycoordinates/' => array( array( 'zoneId' => 'zone-coord', 'zoneName' => 'Новосибирск город', 'branchUID' => 'branch-coord', 'branchTitle' => 'Новосибирск', 'warehousePoint' => array( 'latitude' => 55.1, 'longitude' => 82.9 ) ) ),
	'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'zone-address', 'zoneName' => 'Линево', 'branchUID' => 'branch-address', 'branchTitle' => 'Новосибирск', 'mainWarehouseId' => 'main-wh', 'GeoData' => array( 'precision' => 'near', 'Address' => array( 'formatted' => 'Россия, Новосибирская область, Линево' ) ) ),
) );
$resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) ), $settings );

$address_builder = new PekAddressBuilder();
pek_geo_assert( str_contains( $address_builder->build( Location::from_array( $wpdb->locations[0] ) ), 'Россия' ), 'Address builder must include country.' );
pek_geo_assert( str_contains( $address_builder->build( Location::from_array( $wpdb->locations[4] ) ), 'Армения' ), 'Address builder must support AM without FIAS/GAR.' );
pek_geo_assert( str_contains( $address_builder->build( Location::from_array( $wpdb->locations[2] ) ), 'Беларусь' ), 'Address builder must support BY without FIAS/GAR.' );
pek_geo_assert( str_contains( $address_builder->build( Location::from_array( $wpdb->locations[5] ) ), 'Кыргызстан' ), 'Address builder must support KG without FIAS/GAR.' );
pek_geo_assert( str_contains( $address_builder->build( Location::from_array( $wpdb->locations[3] ) ), 'Казахстан' ), 'Address builder must support KZ foreign locations.' );

$before = $wpdb->locations;
$coord = $resolver->resolve( 10 );
pek_geo_assert( 'coordinates' === $coord['resolution_method'] && 'resolved' === $coord['mapping_state'] && 'branch-coord' === $coord['branch_id'], 'Coordinate endpoint must resolve active location.' );
pek_geo_assert( 55.030204 === (float) $coord['latitude'] && 82.92043 === (float) $coord['longitude'], 'Mapping coordinates must equal canonical destination coordinates, not warehousePoint.' );
pek_geo_assert( str_contains( $http->requests[0]['url'], '/branches/findzonebycoordinates/' ), 'Coordinates must use coordinate endpoint.' );
$again = $resolver->resolve( 10 );
pek_geo_assert( true === ( $again['cache_hit'] ?? false ) && count( $http->requests ) === 1, 'Fresh mapping cache hit must avoid API.' );

$address = $resolver->resolve( 11 );
pek_geo_assert( 'address' === $address['resolution_method'] && 'near' === $address['mapping_state'] && 'main-wh' === $address['main_warehouse_id'], 'Address endpoint must normalize near mapping and main warehouse.' );
pek_geo_assert( null === $address['latitude'] && null === $address['longitude'], 'Address-only mapping must not store warehousePoint as destination coordinates.' );
pek_geo_assert( str_contains( $http->requests[1]['url'], '/branches/findzonebyaddress/' ), 'Missing coordinates must use address endpoint.' );
pek_geo_assert( $before === $wpdb->locations, 'PEK resolver must not mutate canonical wdc_locations rows.' );

$unsupported = $resolver->resolve( 99 );
pek_geo_assert( 'unsupported' === $unsupported['mapping_state'], 'Unsupported country must return unsupported mapping.' );
$fingerprint_before = $resolver->fingerprint( Location::from_array( $wpdb->locations[1] ) );
$changed = $wpdb->locations[1];
$changed['place_name'] = 'Линево-2';
pek_geo_assert( $fingerprint_before !== $resolver->fingerprint( Location::from_array( $changed ) ), 'Canonical location changes must invalidate fingerprint.' );

$country_http = new PekGeoFakeHttp( array(
	'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'zone-by', 'branchUID' => 'branch-by', 'mainWarehouseId' => 'main-by', 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'country_code' => 'BY', 'formatted' => 'Беларусь, Минск' ) ) ),
) );
$country_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $country_http, new PekRequestBudget( $settings ) ), $settings );
$by = $country_resolver->resolve( 20 );
pek_geo_assert( 'resolved' === $by['mapping_state'], 'Matching documented GeoData.Address.country_code must allow mapping.' );
$wpdb->pek_location_mappings = array_values( array_filter( $wpdb->pek_location_mappings, static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) !== 20 ) );
$mismatch_http = new PekGeoFakeHttp( array(
	'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'zone-bad', 'branchUID' => 'branch-bad', 'mainWarehouseId' => 'main-bad', 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'country_code' => 'RU', 'formatted' => 'Россия, Минск' ) ) ),
) );
$mismatch_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $mismatch_http, new PekRequestBudget( $settings ) ), $settings );
$mismatch = $mismatch_resolver->resolve( 20 );
pek_geo_assert( 'unsupported' === $mismatch['mapping_state'] && str_contains( (string) $mismatch['safe_diagnostic_json'], 'country_mismatch' ), 'Mismatching documented response country must create unsupported mapping.' );

foreach ( array( 31, 32, 33, 34 ) as $partial_id ) {
	$wpdb->pek_location_mappings = array_values( array_filter( $wpdb->pek_location_mappings, static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) !== $partial_id ) );
	$partial_http = new PekGeoFakeHttp( array(
		'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'zone-partial', 'zoneName' => 'Partial', 'branchUID' => 'branch-partial', 'branchTitle' => 'Partial', 'mainWarehouseId' => 'main-partial', 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'formatted' => 'Россия, Partial', 'country_code' => 'RU' ) ) ),
	) );
	$partial_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $partial_http, new PekRequestBudget( $settings ) ), $settings );
	$partial_mapping = $partial_resolver->resolve( $partial_id );
	pek_geo_assert( 'address' === $partial_mapping['resolution_method'] && null === $partial_mapping['latitude'] && null === $partial_mapping['longitude'], 'Partial or invalid canonical coordinates must fall back to address mapping without storing coordinates.' );
	pek_geo_assert( str_contains( $partial_http->requests[0]['url'], '/branches/findzonebyaddress/' ), 'Partial or invalid canonical coordinates must not call coordinate zone endpoint.' );
}

$method_cases = array(
	array( 'id' => 11, 'method' => 'address', 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'bad' ) ), 'state' => 'unsupported', 'code' => 'bad_precision' ),
	array( 'id' => 10, 'method' => 'coordinates', 'response' => array( array( 'zoneId' => 'z', 'branchUID' => 'b' ) ), 'state' => 'resolved', 'code' => '' ),
);
foreach ( $method_cases as $case ) {
	$wpdb->pek_location_mappings = array_values( array_filter( $wpdb->pek_location_mappings, static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) !== (int) $case['id'] ) );
	$path = 'coordinates' === $case['method'] ? 'findzonebycoordinates' : 'findzonebyaddress';
	$case_http = new PekGeoFakeHttp( array( 'POST /api/v1/branches/' . $path . '/' => $case['response'] ) );
	$case_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $case_http, new PekRequestBudget( $settings ) ), $settings );
	$case_mapping = $case_resolver->resolve( (int) $case['id'] );
	pek_geo_assert( $case['state'] === $case_mapping['mapping_state'], 'Method-specific zone response policy must produce expected mapping_state.' );
	if ( '' !== $case['code'] ) {
		pek_geo_assert( str_contains( (string) $case_mapping['safe_diagnostic_json'], (string) $case['code'] ), 'Method-specific zone response policy must report stable diagnostic code.' );
	}
}

foreach ( array( '', null ) as $absent_country ) {
	$wpdb->pek_location_mappings = array_values( array_filter( $wpdb->pek_location_mappings, static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) !== 11 ) );
	$country_address = array( 'formatted' => 'Россия, Линево' );
	if ( null !== $absent_country ) {
		$country_address['country_code'] = $absent_country;
	}
	$country_case_http = new PekGeoFakeHttp( array(
		'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'exact', 'Address' => $country_address ) ),
	) );
	$country_case_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $country_case_http, new PekRequestBudget( $settings ) ), $settings );
	$country_case_mapping = $country_case_resolver->resolve( 11 );
	pek_geo_assert( 'resolved' === $country_case_mapping['mapping_state'], 'Absent or empty response country must leave validation unavailable without failing mapping.' );
}

function pek_geo_expect_exception( callable $callback, string $code, string $message ): void {
	try {
		$callback();
		pek_geo_assert( false, $message );
	} catch ( PekApiException $exception ) {
		$actual = (string) ( $exception->context()['error_code'] ?? '' );
		pek_geo_assert( $code === $actual, $message . ' stable code mismatch: expected ' . $code . ', got ' . $actual . '.' );
	}
}

function pek_geo_clear_mapping( wpdb $wpdb, int $location_id ): void {
	$wpdb->pek_location_mappings = array_values( array_filter( $wpdb->pek_location_mappings, static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) !== $location_id ) );
}

function pek_geo_legacy_fingerprint( Location $location ): string {
	$inputs = ( new PekAddressBuilder() )->fingerprint_inputs( $location );
	$json = wp_json_encode( $inputs );

	return hash( 'sha256', false !== $json ? $json : serialize( $inputs ) );
}

foreach ( array(
	array( 'method' => 'coordinates', 'id' => 10, 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b' ), 'code' => 'pek_unexpected_findzone_coordinates' ),
	array( 'method' => 'coordinates', 'id' => 10, 'response' => array( array( 'zoneId' => 'z', 'branchUID' => 'b' ), array( 'zoneId' => 'z2', 'branchUID' => 'b2' ) ), 'code' => 'pek_unexpected_findzone_coordinates' ),
	array( 'method' => 'coordinates', 'id' => 10, 'response' => array( 'scalar' ), 'code' => 'pek_unexpected_findzone_coordinates' ),
	array( 'method' => 'address', 'id' => 11, 'response' => array( array( 'zoneId' => 'z', 'branchUID' => 'b' ) ), 'code' => 'pek_unexpected_findzone_address' ),
) as $case ) {
	pek_geo_clear_mapping( $wpdb, (int) $case['id'] );
	$path = 'coordinates' === $case['method'] ? 'findzonebycoordinates' : 'findzonebyaddress';
	$root_http = new PekGeoFakeHttp( array( 'POST /api/v1/branches/' . $path . '/' => $case['response'] ) );
	$root_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $root_http, new PekRequestBudget( $settings ) ), $settings );
	pek_geo_expect_exception( static fn() => $root_resolver->resolve( (int) $case['id'] ), (string) $case['code'], 'Zone root contract failure must throw.' );
}

foreach ( array(
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'precision' => 'exact' ), 'code' => 'pek_invalid_findzone_address_geodata' ),
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'Precision' => 'exact' ), 'code' => 'pek_invalid_findzone_address_geodata' ),
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b' ), 'code' => 'pek_invalid_findzone_address_geodata' ),
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array() ), 'code' => 'pek_missing_address_precision' ),
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 1 ) ), 'code' => 'pek_unexpected_address_precision' ),
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => true ) ), 'code' => 'pek_unexpected_address_precision' ),
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => array( 'exact' ) ) ), 'code' => 'pek_unexpected_address_precision' ),
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'maybe' ) ), 'code' => 'pek_unexpected_address_precision' ),
) as $case ) {
	pek_geo_clear_mapping( $wpdb, 11 );
	$precision_http = new PekGeoFakeHttp( array( 'POST /api/v1/branches/findzonebyaddress/' => $case['response'] ) );
	$precision_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $precision_http, new PekRequestBudget( $settings ) ), $settings );
	pek_geo_expect_exception( static fn() => $precision_resolver->resolve( 11 ), (string) $case['code'], 'Address precision must be read only from GeoData.precision.' );
}

foreach ( array(
	array( 'method' => 'address', 'id' => 11, 'geodata' => array( 'bad' ), 'code' => 'pek_invalid_findzone_address_geodata' ),
	array( 'method' => 'address', 'id' => 11, 'geodata' => 'bad', 'code' => 'pek_invalid_findzone_address_geodata' ),
	array( 'method' => 'address', 'id' => 11, 'geodata' => true, 'code' => 'pek_invalid_findzone_address_geodata' ),
	array( 'method' => 'coordinates', 'id' => 10, 'geodata' => array( 'bad' ), 'code' => 'pek_invalid_findzone_coordinates_geodata' ),
) as $case ) {
	pek_geo_clear_mapping( $wpdb, (int) $case['id'] );
	$response = array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => $case['geodata'] );
	if ( 'coordinates' === $case['method'] ) {
		$response = array( $response );
	}
	$path = 'coordinates' === $case['method'] ? 'findzonebycoordinates' : 'findzonebyaddress';
	$geodata_http = new PekGeoFakeHttp( array( 'POST /api/v1/branches/' . $path . '/' => $response ) );
	$geodata_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $geodata_http, new PekRequestBudget( $settings ) ), $settings );
	pek_geo_expect_exception( static fn() => $geodata_resolver->resolve( (int) $case['id'] ), (string) $case['code'], 'Malformed GeoData must throw contract exception.' );
}

foreach ( array( array( 'bad' ), 'bad', true ) as $malformed_address ) {
	pek_geo_clear_mapping( $wpdb, 11 );
	$address_object_http = new PekGeoFakeHttp( array(
		'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'exact', 'Address' => $malformed_address ) ),
	) );
	$address_object_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $address_object_http, new PekRequestBudget( $settings ) ), $settings );
	pek_geo_expect_exception( static fn() => $address_object_resolver->resolve( 11 ), 'pek_invalid_findzone_address_object', 'Malformed GeoData.Address must throw contract exception.' );
}

foreach ( array( array( 'formatted' ), (object) array(), 123, true ) as $malformed_formatted ) {
	pek_geo_clear_mapping( $wpdb, 11 );
	$formatted_http = new PekGeoFakeHttp( array(
		'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'formatted' => $malformed_formatted ) ) ),
	) );
	$formatted_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $formatted_http, new PekRequestBudget( $settings ) ), $settings );
	pek_geo_expect_exception( static fn() => $formatted_resolver->resolve( 11 ), 'pek_invalid_findzone_formatted_address', 'Malformed GeoData.Address.formatted must throw contract exception.' );
}

foreach ( array( array(), array( 'formatted' => null ), array( 'formatted' => '' ) ) as $address_object ) {
	pek_geo_clear_mapping( $wpdb, 11 );
	$fallback_http = new PekGeoFakeHttp( array(
		'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'address' => array( 'DO NOT USE' ), 'GeoData' => array( 'precision' => 'exact', 'Address' => $address_object ) ),
	) );
	$fallback_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $fallback_http, new PekRequestBudget( $settings ) ), $settings );
	$fallback_mapping = $fallback_resolver->resolve( 11 );
	pek_geo_assert( 'resolved' === $fallback_mapping['mapping_state'] && ! str_contains( $fallback_mapping['normalized_address'], 'DO NOT USE' ), 'Missing/null/empty formatted must use canonical address builder and ignore top-level address.' );
}

foreach ( array( 'exact', 'near' ) as $precision ) {
	pek_geo_clear_mapping( $wpdb, 11 );
	$missing_main_http = new PekGeoFakeHttp( array(
		'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'GeoData' => array( 'precision' => $precision, 'Address' => array( 'formatted' => 'Россия, Линево' ) ) ),
	) );
	$missing_main_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $missing_main_http, new PekRequestBudget( $settings ) ), $settings );
	pek_geo_expect_exception( static fn() => $missing_main_resolver->resolve( 11 ), 'pek_incomplete_findzone_address', 'Address exact/near mapping must require mainWarehouseId.' );
}

$near_main_http = new PekGeoFakeHttp( array(
	'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'near', 'Address' => array( 'formatted' => 'Россия, Линево' ) ) ),
) );
pek_geo_clear_mapping( $wpdb, 11 );
$near_main_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $near_main_http, new PekRequestBudget( $settings ) ), $settings );
$near_main_mapping = $near_main_resolver->resolve( 11 );
pek_geo_assert( 'near' === $near_main_mapping['mapping_state'] && 'main' === $near_main_mapping['main_warehouse_id'], 'Address near with mainWarehouseId must remain near mapping.' );

foreach ( array(
	array( 'response' => array( 'zoneId' => array(), 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'exact' ) ) ),
	array( 'response' => array( 'zoneId' => 123, 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'exact' ) ) ),
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => (object) array(), 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'exact' ) ) ),
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => true, 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'exact' ) ) ),
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'zoneName' => array(), 'GeoData' => array( 'precision' => 'exact' ) ) ),
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'branchTitle' => array(), 'GeoData' => array( 'precision' => 'exact' ) ) ),
	array( 'response' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => array(), 'GeoData' => array( 'precision' => 'exact' ) ) ),
) as $case ) {
	pek_geo_clear_mapping( $wpdb, 11 );
	$field_http = new PekGeoFakeHttp( array( 'POST /api/v1/branches/findzonebyaddress/' => $case['response'] ) );
	$field_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $field_http, new PekRequestBudget( $settings ) ), $settings );
	pek_geo_expect_exception( static fn() => $field_resolver->resolve( 11 ), 'pek_invalid_findzone_address_contract', 'Non-string critical/text zone fields must throw contract exception.' );
}

foreach ( array( '643', 'R1', 'RUS', array( 'RU' ) ) as $malformed_country ) {
	pek_geo_clear_mapping( $wpdb, 11 );
	$country_http = new PekGeoFakeHttp( array(
		'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'country_code' => $malformed_country ) ) ),
	) );
	$country_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $country_http, new PekRequestBudget( $settings ) ), $settings );
	pek_geo_expect_exception( static fn() => $country_resolver->resolve( 11 ), 'pek_invalid_response_country', 'Malformed non-empty response country must throw contract exception.' );
}

$legacy_location = Location::from_array( $wpdb->locations[1] );
$legacy_fingerprint = pek_geo_legacy_fingerprint( $legacy_location );
$current_fingerprint = $resolver->fingerprint( $legacy_location );
pek_geo_assert( $legacy_fingerprint !== $current_fingerprint, 'PEK mapping contract revision must invalidate legacy fingerprints without using plugin version.' );
$wpdb->pek_location_mappings = array(
	array(
		'id' => 41,
		'location_id' => 11,
		'country_code' => 'RU',
		'address_fingerprint' => $legacy_fingerprint,
		'resolution_method' => 'address',
		'mapping_state' => 'resolved',
		'precision' => 'exact',
		'zone_id' => 'legacy-zone',
		'branch_id' => 'legacy-branch',
		'main_warehouse_id' => 'legacy-main',
		'normalized_address' => 'Россия, Линево',
		'latitude' => 55.1,
		'longitude' => 82.9,
		'checked_at' => '2026-08-03 10:00:00',
		'created_at' => '2026-08-03 10:00:00',
		'updated_at' => '2026-08-03 10:00:00',
	),
);
$legacy_refresh_http = new PekGeoFakeHttp( array(
	'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'new-zone', 'branchUID' => 'new-branch', 'mainWarehouseId' => 'new-main', 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'country_code' => 'RU', 'formatted' => 'Россия, Новосибирская область, Линево' ) ) ),
) );
$legacy_refresh_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $legacy_refresh_http, new PekRequestBudget( $settings ) ), $settings );
$legacy_refreshed = $legacy_refresh_resolver->resolve( 11 );
pek_geo_assert( false === ( $legacy_refreshed['cache_hit'] ?? true ) && 1 === count( $legacy_refresh_http->requests ) && 'new-main' === $legacy_refreshed['main_warehouse_id'] && null === $legacy_refreshed['latitude'] && null === $legacy_refreshed['longitude'], 'Legacy address mapping with warehousePoint coordinates must be refreshed through current contract.' );
pek_geo_assert( $current_fingerprint === (string) ( new PekLocationMappingRepository( $wpdb ) )->find_by_location_id( 11 )['address_fingerprint'], 'Successful current resolve must replace legacy fingerprint.' );

$wpdb->pek_location_mappings = array(
	array(
		'id' => 42,
		'location_id' => 11,
		'country_code' => 'RU',
		'address_fingerprint' => $current_fingerprint,
		'resolution_method' => 'address',
		'mapping_state' => 'resolved',
		'precision' => 'exact',
		'zone_id' => 'legacy-zone',
		'branch_id' => 'legacy-branch',
		'main_warehouse_id' => '',
		'normalized_address' => 'Россия, Линево',
		'latitude' => null,
		'longitude' => null,
		'checked_at' => '2026-08-03 10:00:00',
		'created_at' => '2026-08-03 10:00:00',
		'updated_at' => '2026-08-03 10:00:00',
	),
);
$missing_main_http = new PekGeoFakeHttp( array(
	'POST /api/v1/branches/findzonebyaddress/' => array( 'zoneId' => 'fixed-zone', 'branchUID' => 'fixed-branch', 'mainWarehouseId' => 'fixed-main', 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'formatted' => 'Россия, Линево' ) ) ),
) );
$missing_main_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $missing_main_http, new PekRequestBudget( $settings ) ), $settings );
$missing_main_refreshed = $missing_main_resolver->resolve( 11 );
pek_geo_assert( false === ( $missing_main_refreshed['cache_hit'] ?? true ) && 1 === count( $missing_main_http->requests ) && 'fixed-main' === $missing_main_refreshed['main_warehouse_id'], 'Legacy address mapping without main warehouse must be refreshed and replaced by valid current mapping.' );

$wpdb->pek_location_mappings = array(
	array(
		'id' => 43,
		'location_id' => 11,
		'country_code' => 'RU',
		'address_fingerprint' => $current_fingerprint,
		'resolution_method' => 'address',
		'mapping_state' => 'resolved',
		'precision' => 'exact',
		'zone_id' => 'legacy-zone',
		'branch_id' => 'legacy-branch',
		'main_warehouse_id' => '',
		'normalized_address' => 'Россия, Линево',
		'latitude' => null,
		'longitude' => null,
		'checked_at' => '2026-07-01 00:00:00',
		'created_at' => '2026-07-01 00:00:00',
		'updated_at' => '2026-07-01 00:00:00',
	),
);
$legacy_error_http = new PekGeoFakeHttp( array( 'POST /api/v1/branches/findzonebyaddress/' => array( '__status' => 503, 'body' => array( 'message' => 'down' ) ) ) );
$legacy_error_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $legacy_error_http, new PekRequestBudget( $settings ) ), $settings );
try {
	$legacy_error_resolver->resolve( 11 );
	pek_geo_assert( false, 'Legacy mapping without main warehouse must not be used as stale fallback after API failure.' );
} catch ( PekApiException ) {
	$preserved_legacy = ( new PekLocationMappingRepository( $wpdb ) )->find_by_location_id( 11 );
	pek_geo_assert( '' === $preserved_legacy['main_warehouse_id'], 'Invalid persisted mapping must be preserved, not destructively deleted, after API failure.' );
}

$wpdb->pek_location_mappings = array(
	array(
		'id' => 44,
		'location_id' => 10,
		'country_code' => 'RU',
		'address_fingerprint' => $resolver->fingerprint( Location::from_array( $wpdb->locations[0] ) ),
		'resolution_method' => 'coordinates',
		'mapping_state' => 'resolved',
		'precision' => '',
		'zone_id' => 'legacy-zone',
		'branch_id' => 'legacy-branch',
		'main_warehouse_id' => '',
		'normalized_address' => 'Новосибирск',
		'latitude' => 55.1,
		'longitude' => 82.9,
		'checked_at' => '2026-08-03 10:00:00',
		'created_at' => '2026-08-03 10:00:00',
		'updated_at' => '2026-08-03 10:00:00',
	),
);
$coordinate_refresh_http = new PekGeoFakeHttp( array(
	'POST /api/v1/branches/findzonebycoordinates/' => array( array( 'zoneId' => 'coord-new', 'branchUID' => 'coord-branch' ) ),
) );
$coordinate_refresh_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $coordinate_refresh_http, new PekRequestBudget( $settings ) ), $settings );
$coordinate_refreshed = $coordinate_refresh_resolver->resolve( 10 );
pek_geo_assert( false === ( $coordinate_refreshed['cache_hit'] ?? true ) && 55.030204 === (float) $coordinate_refreshed['latitude'] && 82.92043 === (float) $coordinate_refreshed['longitude'], 'Coordinate mapping with coordinates different from canonical location must be refreshed.' );

$wpdb->pek_location_mappings = array(
	array(
		'id' => 46,
		'location_id' => 10,
		'country_code' => 'RU',
		'address_fingerprint' => $resolver->fingerprint( Location::from_array( $wpdb->locations[0] ) ),
		'resolution_method' => 'coordinates',
		'mapping_state' => 'resolved',
		'precision' => '',
		'zone_id' => 'partial-zone',
		'branch_id' => 'partial-branch',
		'main_warehouse_id' => '',
		'normalized_address' => 'Новосибирск',
		'latitude' => 55.030204,
		'longitude' => null,
		'checked_at' => '2026-08-03 10:00:00',
		'created_at' => '2026-08-03 10:00:00',
		'updated_at' => '2026-08-03 10:00:00',
	),
);
$partial_coordinate_http = new PekGeoFakeHttp( array(
	'POST /api/v1/branches/findzonebycoordinates/' => array( array( 'zoneId' => 'coord-partial-new', 'branchUID' => 'coord-partial-branch' ) ),
) );
$partial_coordinate_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $partial_coordinate_http, new PekRequestBudget( $settings ) ), $settings );
$partial_coordinate_refreshed = $partial_coordinate_resolver->resolve( 10 );
pek_geo_assert( false === ( $partial_coordinate_refreshed['cache_hit'] ?? true ) && 1 === count( $partial_coordinate_http->requests ), 'Persisted partial coordinate pair must be treated as cache miss without PHP warnings.' );

$same_fingerprint = $resolver->fingerprint( Location::from_array( $wpdb->locations[1] ) );
$wpdb->pek_location_mappings = array( array( 'id' => 45, 'location_id' => 11, 'country_code' => 'RU', 'address_fingerprint' => $same_fingerprint, 'resolution_method' => 'address', 'mapping_state' => 'resolved', 'precision' => 'exact', 'zone_id' => 'z', 'branch_id' => 'b', 'main_warehouse_id' => 'main', 'normalized_address' => 'Россия, Линево', 'latitude' => null, 'longitude' => null, 'checked_at' => '2026-08-03 10:00:00', 'created_at' => '2026-08-03 10:00:00', 'updated_at' => '2026-08-03 10:00:00' ) );
$current_hit_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, new PekGeoFakeHttp( array() ), new PekRequestBudget( $settings ) ), $settings );
$current_hit = $current_hit_resolver->resolve( 11 );
pek_geo_assert( true === ( $current_hit['cache_hit'] ?? false ), 'Valid current-contract mapping must still be accepted as a fresh cache hit.' );

$same_fingerprint = $resolver->fingerprint( Location::from_array( $wpdb->locations[1] ) );
$wpdb->pek_location_mappings = array( array( 'id' => 1, 'location_id' => 11, 'country_code' => 'RU', 'address_fingerprint' => $same_fingerprint, 'resolution_method' => 'address', 'mapping_state' => 'resolved', 'precision' => 'exact', 'zone_id' => 'z', 'branch_id' => 'b', 'main_warehouse_id' => 'main', 'normalized_address' => 'Россия, Линево', 'latitude' => null, 'longitude' => null, 'checked_at' => '2026-07-01 00:00:00', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00' ) );
$error_http = new PekGeoFakeHttp( array( 'POST /api/v1/branches/findzonebyaddress/' => array( '__status' => 503, 'body' => array( 'message' => 'down' ) ) ) );
$stale_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), new PekApiClient( $settings, $credentials, $error_http, new PekRequestBudget( $settings ) ), $settings );
$stale = $stale_resolver->resolve( 11 );
pek_geo_assert( true === ( $stale['stale_fallback'] ?? false ) && false === ( $stale['cache_hit'] ?? true ), 'Same-fingerprint stale resolved mapping may be returned on transport failure.' );
$wpdb->locations[1]['place_name'] = 'Линево-2';
try {
	$stale_resolver->resolve( 11 );
	pek_geo_assert( false, 'Different fingerprint must not return old mapping after PEK API failure.' );
} catch ( Throwable ) {
	$preserved = ( new PekLocationMappingRepository( $wpdb ) )->find_by_location_id( 11 );
	pek_geo_assert( 'Россия, Линево' === $preserved['normalized_address'], 'Old mapping row must be preserved after different-fingerprint API failure.' );
}

$schema = ( new PekLocationMappingRepository( $wpdb ) )->schema();
foreach ( array( 'wdc_pek_location_mappings', 'address_fingerprint', 'main_warehouse_id', 'mapping_state', 'checked_at' ) as $needle ) {
	pek_geo_assert( str_contains( $schema, $needle ), 'PEK mapping schema must contain ' . $needle );
}
$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Pek/Geography/PekLocationMappingRepository.php' );
pek_geo_assert( ! str_contains( $source, 'create_schema_if_needed' ), 'PEK mapping repository must not expose runtime create_schema_if_needed.' );
$repo = new PekLocationMappingRepository( $wpdb );
$wpdb->pek_location_mapping_insert_fails = true;
try {
	$repo->upsert( array( 'location_id' => 30, 'country_code' => 'RU', 'address_fingerprint' => str_repeat( 'a', 64 ), 'mapping_state' => 'resolved' ) );
	pek_geo_assert( false, 'PEK mapping insert failure must fail closed.' );
} catch ( RuntimeException ) {
	$wpdb->pek_location_mapping_insert_fails = false;
}
$repo->upsert( array( 'location_id' => 30, 'country_code' => 'RU', 'address_fingerprint' => str_repeat( 'a', 64 ), 'mapping_state' => 'resolved', 'created_at' => '2026-01-01 00:00:00' ) );
$wpdb->pek_location_mapping_update_fails = true;
try {
	$repo->upsert( array( 'location_id' => 30, 'country_code' => 'RU', 'address_fingerprint' => str_repeat( 'b', 64 ), 'mapping_state' => 'near' ) );
	pek_geo_assert( false, 'PEK mapping update failure must fail closed.' );
} catch ( RuntimeException ) {
	$wpdb->pek_location_mapping_update_fails = false;
}
$repo->upsert( array( 'location_id' => 30, 'country_code' => 'RU', 'address_fingerprint' => str_repeat( 'b', 64 ), 'mapping_state' => 'near' ) );
pek_geo_assert( '2026-01-01 00:00:00' === $repo->find_by_location_id( 30 )['created_at'], 'PEK mapping update must preserve created_at.' );
$default_timezone = date_default_timezone_get();
date_default_timezone_set( 'UTC' );
$fresh_fingerprint = str_repeat( 'c', 64 );
pek_geo_assert( $repo->is_fresh( array( 'address_fingerprint' => $fresh_fingerprint, 'checked_at' => '2026-08-03 09:30:00' ), $fresh_fingerprint, 1 ), 'Freshness must compare checked_at in WordPress timezone, not PHP default timezone.' );
pek_geo_assert( $repo->is_fresh( array( 'address_fingerprint' => $fresh_fingerprint, 'checked_at' => '2026-08-02 10:00:00' ), $fresh_fingerprint, 1 ), 'Exact TTL boundary must remain fresh.' );
pek_geo_assert( ! $repo->is_fresh( array( 'address_fingerprint' => $fresh_fingerprint, 'checked_at' => '2026-08-02 09:59:59' ), $fresh_fingerprint, 1 ), 'Mapping older than TTL boundary must be stale.' );
pek_geo_assert( ! $repo->is_fresh( array( 'address_fingerprint' => $fresh_fingerprint, 'checked_at' => 'bad-date' ), $fresh_fingerprint, 1 ), 'Invalid checked_at must not be fresh.' );
pek_geo_assert( ! $repo->is_fresh( array( 'address_fingerprint' => $fresh_fingerprint, 'checked_at' => '2026-02-30 10:00:00' ), $fresh_fingerprint, 365 ), 'Impossible checked_at must not be fresh.' );
pek_geo_assert( ! $repo->is_fresh( array( 'address_fingerprint' => $fresh_fingerprint, 'checked_at' => '2026-08-03 10:00:01' ), $fresh_fingerprint, 1 ), 'Future checked_at must not be fresh.' );
date_default_timezone_set( $default_timezone );
$wpdb->pek_location_mapping_read_fails = true;
try {
	$repo->find_by_location_id( 30 );
	pek_geo_assert( false, 'PEK mapping read SQL error must fail closed.' );
} catch ( RuntimeException ) {
	$wpdb->pek_location_mapping_read_fails = false;
}
$wpdb->pek_location_mapping_delete_fails = true;
try {
	$repo->delete_for_location( 30 );
	pek_geo_assert( false, 'PEK mapping delete SQL error must fail closed.' );
} catch ( RuntimeException ) {
	$wpdb->pek_location_mapping_delete_fails = false;
}
$wpdb->pek_location_mapping_statistics_fails = true;
try {
	$repo->statistics();
	pek_geo_assert( false, 'PEK mapping statistics SQL error must fail closed.' );
} catch ( RuntimeException ) {
	$wpdb->pek_location_mapping_statistics_fails = false;
}

echo "PEK geography smoke OK\n";
