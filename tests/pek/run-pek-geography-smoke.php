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
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string { unset( $type ); return '2026-08-03 10:00:00'; }
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
		return array( 'status' => 200, 'body' => wp_json_encode( $body ) );
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $locations = array();
		public array $pek_location_mappings = array();
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
	array( 'id' => 99, 'country_code' => 'US', 'city_name' => 'Boston', 'place_name' => 'Boston', 'display_name' => 'Boston', 'active' => 1 ),
);

$settings_repo = new SettingsRepository();
$settings = new PekSettings( $settings_repo );
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
pek_geo_assert( str_contains( $address_builder->build( Location::from_array( $wpdb->locations[2] ) ), 'Беларусь' ), 'Address builder must support BY without FIAS/GAR.' );
pek_geo_assert( str_contains( $address_builder->build( Location::from_array( $wpdb->locations[3] ) ), 'Казахстан' ), 'Address builder must support KZ foreign locations.' );

$before = $wpdb->locations;
$coord = $resolver->resolve( 10 );
pek_geo_assert( 'coordinates' === $coord['resolution_method'] && 'resolved' === $coord['mapping_state'] && 'branch-coord' === $coord['branch_id'], 'Coordinate endpoint must resolve active location.' );
pek_geo_assert( str_contains( $http->requests[0]['url'], '/branches/findzonebycoordinates/' ), 'Coordinates must use coordinate endpoint.' );
$again = $resolver->resolve( 10 );
pek_geo_assert( true === ( $again['cache_hit'] ?? false ) && count( $http->requests ) === 1, 'Fresh mapping cache hit must avoid API.' );

$address = $resolver->resolve( 11 );
pek_geo_assert( 'address' === $address['resolution_method'] && 'near' === $address['mapping_state'] && 'main-wh' === $address['main_warehouse_id'], 'Address endpoint must normalize near mapping and main warehouse.' );
pek_geo_assert( str_contains( $http->requests[1]['url'], '/branches/findzonebyaddress/' ), 'Missing coordinates must use address endpoint.' );
pek_geo_assert( $before === $wpdb->locations, 'PEK resolver must not mutate canonical wdc_locations rows.' );

$unsupported = $resolver->resolve( 99 );
pek_geo_assert( 'unsupported' === $unsupported['mapping_state'], 'Unsupported country must return unsupported mapping.' );
$fingerprint_before = $resolver->fingerprint( Location::from_array( $wpdb->locations[1] ) );
$changed = $wpdb->locations[1];
$changed['place_name'] = 'Линево-2';
pek_geo_assert( $fingerprint_before !== $resolver->fingerprint( Location::from_array( $changed ) ), 'Canonical location changes must invalidate fingerprint.' );

$schema = ( new PekLocationMappingRepository( $wpdb ) )->schema();
foreach ( array( 'wdc_pek_location_mappings', 'address_fingerprint', 'main_warehouse_id', 'mapping_state', 'checked_at' ) as $needle ) {
	pek_geo_assert( str_contains( $schema, $needle ), 'PEK mapping schema must contain ' . $needle );
}

echo "PEK geography smoke OK\n";
