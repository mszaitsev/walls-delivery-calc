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
use WallsShop\WDC\Carriers\Pek\Pickup\PekCargoConstraintsConverter;
use WallsShop\WDC\Carriers\Pek\Pickup\PekDestinationTerminalRequest;
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
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
}
if ( ! function_exists( 'current_datetime' ) ) {
	function current_datetime(): DateTimeImmutable { return new DateTimeImmutable( '2026-08-03 10:00:00', wp_timezone() ); }
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
	function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_transients'][ $key ] ); return true; }
}

function pek_pickup_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function pek_pickup_legacy_mapping_fingerprint( array $location ): string {
	$inputs = ( new PekAddressBuilder() )->fingerprint_inputs( WallsShop\WDC\Locations\ValueObjects\Location::from_array( $location ) );
	$json = wp_json_encode( $inputs );

	return hash( 'sha256', false !== $json ? $json : serialize( $inputs ) );
}

final class PekPickupFakeHttp implements PekHttpClientInterface {
	public array $requests = array();
	public array $nearest_response;
	public function __construct( ?array $nearest_response = null ) {
		$this->nearest_response = null === $nearest_response ? array(
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
			return array( 'status' => 200, 'body' => wp_json_encode( array( 'zoneId' => 'zone-address', 'zoneName' => 'Zone address', 'branchUID' => 'branch-address', 'branchTitle' => 'Branch address', 'mainWarehouseId' => 'main-address', 'warehousePoint' => array( 'latitude' => 56.0, 'longitude' => 83.0 ), 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'country_code' => 'RU', 'formatted' => 'Россия, Линево' ) ) ) ) );
		}
		if ( isset( $this->nearest_response['__status'] ) ) {
			return array( 'status' => (int) $this->nearest_response['__status'], 'body' => wp_json_encode( $this->nearest_response['body'] ?? array() ) );
		}
		return array( 'status' => 200, 'body' => wp_json_encode( $this->nearest_response ) );
	}
}

final class PekPickupZoneFailureFakeHttp implements PekHttpClientInterface {
	public array $requests = array();
	private PekPickupFakeHttp $nearest;
	public function __construct( private array $zone_response ) {
		$this->nearest = new PekPickupFakeHttp();
	}
	public function request( string $method, string $url, array $args ): array {
		$this->requests[] = compact( 'method', 'url', 'args' );
		$path = (string) parse_url( $url, PHP_URL_PATH );
		if ( str_contains( $path, 'findzonebyaddress' ) || str_contains( $path, 'findzonebycoordinates' ) ) {
			return array( 'status' => 200, 'body' => wp_json_encode( $this->zone_response ) );
		}

		return array( 'status' => 200, 'body' => wp_json_encode( $this->nearest->nearest_response ) );
	}
}

function pek_pickup_valid_row( array $overrides = array() ): array {
	return array_merge(
		array(
			'warehouseId' => 'valid-' . substr( md5( serialize( $overrides ) ), 0, 8 ),
			'branchId' => 'branch',
			'branchName' => 'Новосибирск',
			'divisionName' => 'Центр',
			'departmentTypeId' => 0,
			'departmentType' => 'Отделение компании',
			'address' => 'Адрес',
			'coordinates' => array( 'latitude' => 55.1, 'longitude' => 82.9 ),
			'timeZone' => '07:00:00',
			'priority' => 1,
			'maxWeight' => 0,
			'maxVolume' => 0,
			'maxDimension' => 0,
			'maxWeightOnePlace' => 0,
			'maxCount' => 0,
		),
		$overrides
	);
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
$payload_probe = ( new PekDestinationTerminalRequest( '', 55.0302040, 82.9204300, 1.0, 0.001, 0.1, 1.0, 1, 50, 50 ) )->to_payload();
pek_pickup_assert( is_string( $payload_probe['coordinates']['latitude'] ) && is_string( $payload_probe['coordinates']['longitude'] ) && '55.030204' === $payload_probe['coordinates']['latitude'] && '82.92043' === $payload_probe['coordinates']['longitude'] && ! str_contains( $payload_probe['coordinates']['latitude'], 'E' ), 'PEK destination coordinates must serialize as decimal strings without scientific notation.' );
$zero_payload_probe = ( new PekDestinationTerminalRequest( '', -0.0, 0.0, 1.0, 0.001, 0.1, 1.0, 1, 50, 50 ) )->to_payload();
pek_pickup_assert( '0' === $zero_payload_probe['coordinates']['latitude'] && '0' === $zero_payload_probe['coordinates']['longitude'], 'PEK coordinate serialization must normalize negative zero to zero.' );

$points = $provider->search( $query );
pek_pickup_assert( count( $points ) === 2, 'PEK provider must return free and paid points after filtering invalid/limit rows.' );
pek_pickup_assert( $points[0]->type === 'terminal' && $points[0]->raw_reference['source'] === 'free', 'freeDepartments must become terminal source free.' );
pek_pickup_assert( $points[1]->type === 'pvz' && $points[1]->raw_reference['source'] === 'paid', 'paidDepartments must become pvz source paid.' );
pek_pickup_assert( $points[0]->raw_reference['timezone'] === 'UTC+07:00', 'PEK destination timeZone must normalize to canonical timezone.' );
pek_pickup_assert( '' === $points[0]->city && 'Новосибирск' === $points[0]->raw_reference['branch_name'], 'PEK organizational branchName must not be exposed as PickupPoint city.' );
pek_pickup_assert( array() === $points[0]->raw_reference['availability']['scheduleShortWorkDays'] && array() === $points[0]->raw_reference['availability']['scheduleHolidayDays'], 'Absent PEK schedule arrays must normalize to empty compact availability arrays.' );
pek_pickup_assert( count( $wpdb->pek_terminals ) === 2 && array() !== $repo->find_by_warehouse_id( 'free-ok' ), 'Terminal repository must upsert found terminals.' );
$nearest_request = $http->requests[1];
$payload = json_decode( (string) $nearest_request['args']['body'], true );
pek_pickup_assert( 3 === (int) $payload['departmentOperation'] && 3 === (int) $payload['type'], 'Destination nearestdepartments must use operation=3 and type=3.' );
pek_pickup_assert( isset( $payload['coordinates'] ) && '55.030204' === $payload['coordinates']['latitude'] && '82.92043' === $payload['coordinates']['longitude'] && is_string( $payload['coordinates']['latitude'] ) && is_string( $payload['coordinates']['longitude'] ) && ! isset( $payload['address'] ), 'Coordinate request must send canonical coordinates as PEK decimal strings and not send conflicting address.' );

$requests_after_first = count( $http->requests );
$cached = $provider->search( $query );
pek_pickup_assert( count( $cached ) === 2 && count( $http->requests ) === $requests_after_first, 'Terminal search cache hit must avoid API.' );

$cache = new PekDestinationTerminalSearchCache();
$cache_fingerprint = $cache->fingerprint( array( 'case' => 'valid' ) );
$cache->save( $cache_fingerprint, array( 'query_fingerprint' => $cache_fingerprint ), array( $points[0] ), 600 );
pek_pickup_assert( 2 === (int) $GLOBALS['wdc_transients'][ 'wdc_pek_destination_terminals_' . $cache_fingerprint ]['value']['format_version'], 'PEK terminal cache must save format version 2.' );
pek_pickup_assert( true === $cache->get( $cache_fingerprint )['hit'] && 1 === count( $cache->get( $cache_fingerprint )['points'] ), 'Versioned terminal cache must accept valid non-empty PEK points.' );
$unsafe_point = new WallsShop\WDC\Domain\Pickup\PickupPoint( 'pek', 'unsafe', 'Address', '', '', '', 55.1, 82.9, 'terminal', '', '', null, true, array( 'warehouse_id' => 'unsafe', 'raw_response' => array( 'secret' => true ), 'nested' => array( 'Authorization' => 'Basic secret' ), 'availability' => array( 'scheduleHolidayDays' => array(), 'body' => 'secret' ) ) );
$unsafe_fingerprint = $cache->fingerprint( array( 'case' => 'unsafe' ) );
$cache->save( $unsafe_fingerprint, array( 'query_fingerprint' => $unsafe_fingerprint ), array( $unsafe_point ), 600 );
$stored_unsafe = $GLOBALS['wdc_transients'][ 'wdc_pek_destination_terminals_' . $unsafe_fingerprint ]['value']['points'][0];
$stored_unsafe_json = wp_json_encode( $stored_unsafe );
pek_pickup_assert( ! str_contains( $stored_unsafe_json, 'raw_response' ) && ! str_contains( $stored_unsafe_json, 'Authorization' ) && ! str_contains( $stored_unsafe_json, 'body' ) && ! array_key_exists( 'nested', $stored_unsafe['raw_reference'] ), 'Terminal cache must project PickupPoint raw_reference through a safe allowlist.' );
$empty_fingerprint = $cache->fingerprint( array( 'case' => 'empty' ) );
$cache->save( $empty_fingerprint, array( 'query_fingerprint' => $empty_fingerprint ), array(), 600 );
pek_pickup_assert( 2 === (int) $GLOBALS['wdc_transients'][ 'wdc_pek_destination_terminals_' . $empty_fingerprint ]['value']['format_version'] && true === $cache->get( $empty_fingerprint )['hit'] && array() === $cache->get( $empty_fingerprint )['points'], 'Versioned terminal cache must accept valid empty successful results.' );
$legacy_cache_fingerprint = $cache->fingerprint( array( 'case' => 'legacy-v1' ) );
$GLOBALS['wdc_transients'][ 'wdc_pek_destination_terminals_' . $legacy_cache_fingerprint ] = array(
	'value' => array(
		'format_version' => 1,
		'fingerprint' => $legacy_cache_fingerprint,
		'metadata' => array( 'query_fingerprint' => $legacy_cache_fingerprint ),
		'points' => array(
			array_merge(
				$points[0]->to_array(),
				array(
					'code' => 'legacy-terminal',
					'raw_reference' => array(
						'warehouse_id' => 'legacy-terminal',
						'Authorization' => 'Basic secret',
						'raw_response' => array( 'secret' => true ),
						'unsafe_custom' => 'must not survive',
					),
				)
			),
		),
	),
	'ttl' => 600,
);
$legacy_cache_result = $cache->get( $legacy_cache_fingerprint );
pek_pickup_assert( false === $legacy_cache_result['hit'] && array() === $legacy_cache_result['metadata'] && array() === $legacy_cache_result['points'] && ! isset( $GLOBALS['wdc_transients'][ 'wdc_pek_destination_terminals_' . $legacy_cache_fingerprint ] ), 'Terminal cache format 1 must be invalidated and deleted before point deserialization.' );
foreach ( array(
	array( 'case' => 'wrong-version', 'value' => array( 'format_version' => 1, 'fingerprint' => '', 'metadata' => array(), 'points' => array() ) ),
	array( 'case' => 'missing-version', 'value' => array( 'fingerprint' => '', 'metadata' => array(), 'points' => array() ) ),
	array( 'case' => 'missing-points', 'value' => array( 'format_version' => 2, 'fingerprint' => '', 'metadata' => array() ) ),
	array( 'case' => 'scalar-points', 'value' => array( 'format_version' => 2, 'fingerprint' => '', 'metadata' => array(), 'points' => 'bad' ) ),
	array( 'case' => 'malformed-point', 'value' => array( 'format_version' => 2, 'fingerprint' => '', 'metadata' => array(), 'points' => array( array( 'carrier_key' => 'pek', 'code' => '', 'type' => 'terminal' ) ) ) ),
	array( 'case' => 'foreign-carrier', 'value' => array( 'format_version' => 2, 'fingerprint' => '', 'metadata' => array(), 'points' => array( array_merge( $points[0]->to_array(), array( 'carrier_key' => 'cdek' ) ) ) ) ),
) as $cache_case ) {
	$bad_fingerprint = $cache->fingerprint( array( 'case' => $cache_case['case'] ) );
	$cache_case['value']['fingerprint'] = 'wrong-fingerprint' === $cache_case['case'] ? 'other' : $bad_fingerprint;
	$GLOBALS['wdc_transients'][ 'wdc_pek_destination_terminals_' . $bad_fingerprint ] = array( 'value' => $cache_case['value'], 'ttl' => 600 );
	pek_pickup_assert( false === $cache->get( $bad_fingerprint )['hit'] && ! isset( $GLOBALS['wdc_transients'][ 'wdc_pek_destination_terminals_' . $bad_fingerprint ] ), 'Malformed terminal cache must be deleted and treated as miss: ' . $cache_case['case'] );
}
$wrong_fingerprint = $cache->fingerprint( array( 'case' => 'wrong-fingerprint' ) );
$GLOBALS['wdc_transients'][ 'wdc_pek_destination_terminals_' . $wrong_fingerprint ] = array( 'value' => array( 'format_version' => 2, 'fingerprint' => 'different', 'metadata' => array(), 'points' => array() ), 'ttl' => 600 );
pek_pickup_assert( false === $cache->get( $wrong_fingerprint )['hit'] && ! isset( $GLOBALS['wdc_transients'][ 'wdc_pek_destination_terminals_' . $wrong_fingerprint ] ), 'Wrong cache fingerprint must be deleted and treated as miss.' );

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

foreach ( array(
	'missing-free' => array( 'paidDepartments' => array() ),
	'missing-paid' => array( 'freeDepartments' => array() ),
	'free-null' => array( 'freeDepartments' => null, 'paidDepartments' => array() ),
	'paid-null' => array( 'freeDepartments' => array(), 'paidDepartments' => null ),
	'assoc-free' => array( 'freeDepartments' => array( 'warehouseId' => 'bad' ), 'paidDepartments' => array() ),
	'assoc-paid' => array( 'freeDepartments' => array(), 'paidDepartments' => array( 'warehouseId' => 'bad' ) ),
	'root-list' => array(),
) as $case_name => $response ) {
	$bad_http = new PekPickupFakeHttp( $response );
	$bad_api = new PekApiClient( $settings, $credentials, $bad_http, new PekRequestBudget( $settings ) );
	try {
		$bad_api->destination_nearest_departments( new PekDestinationTerminalRequest( 'Россия, Новосибирск', null, null, 1.0, 0.001, 0.1, 1.0, 1, 50, 50 ) );
		pek_pickup_assert( false, 'Malformed destination nearestdepartments response must fail closed: ' . $case_name );
	} catch ( PekApiException $exception ) {
		pek_pickup_assert( 'pek_unexpected_destination_nearest_departments' === (string) ( $exception->context()['error_code'] ?? '' ), 'Malformed destination response must use stable destination error code.' );
	}
}
$sender_bad_http = new PekPickupFakeHttp( array() );
$sender_bad_api = new PekApiClient( $settings, $credentials, $sender_bad_http, new PekRequestBudget( $settings ) );
try {
	$sender_bad_api->nearest_departments( 'Россия, Новосибирск' );
	pek_pickup_assert( false, 'Malformed sender nearestdepartments response must fail closed.' );
} catch ( PekApiException $exception ) {
	pek_pickup_assert( 'pek_unexpected_nearest_departments' === (string) ( $exception->context()['error_code'] ?? '' ), 'Malformed sender response must preserve sender-specific stable error code.' );
}

function pek_pickup_search_with_response( array $response, wpdb $wpdb, PekSettings $settings, PekCredentials $credentials ): array {
	$GLOBALS['wdc_transients'] = array();
	$http = new PekPickupFakeHttp( $response );
	$api = new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) );
	$resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), $api, $settings );
	$repo = new PekTerminalRepository( $wpdb );
	$service = new PekTerminalService( $resolver, $api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), $repo, $settings );
	$query = new CarrierPickupPointQuery( 'pek', 10, 'RU', '', null, null, new PickupCargoConstraints( 1000, 1000, 10, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 );

	return array( $service, $http, $repo, $query, $service->search( $query ) );
}

$invalid_rows = array(
	'warehouse-array' => pek_pickup_valid_row( array( 'warehouseId' => array() ) ),
	'warehouse-int' => pek_pickup_valid_row( array( 'warehouseId' => 123 ) ),
	'warehouse-bool' => pek_pickup_valid_row( array( 'warehouseId' => true ) ),
	'warehouse-empty' => pek_pickup_valid_row( array( 'warehouseId' => "\n\t" ) ),
	'warehouse-too-long' => pek_pickup_valid_row( array( 'warehouseId' => str_repeat( 'a', 65 ) ) ),
	'branch-array' => pek_pickup_valid_row( array( 'branchId' => array() ) ),
	'branch-name-array' => pek_pickup_valid_row( array( 'branchName' => array() ) ),
	'division-object' => pek_pickup_valid_row( array( 'divisionName' => (object) array() ) ),
	'type-array' => pek_pickup_valid_row( array( 'departmentType' => array() ) ),
	'address-array' => pek_pickup_valid_row( array( 'address' => array() ) ),
	'timezone-array' => pek_pickup_valid_row( array( 'timeZone' => array() ) ),
	'missing-latitude' => pek_pickup_valid_row( array( 'coordinates' => array( 'longitude' => 82.9 ) ) ),
	'missing-longitude' => pek_pickup_valid_row( array( 'coordinates' => array( 'latitude' => 55.1 ) ) ),
	'array-latitude' => pek_pickup_valid_row( array( 'coordinates' => array( 'latitude' => array(), 'longitude' => 82.9 ) ) ),
	'out-latitude' => pek_pickup_valid_row( array( 'coordinates' => array( 'latitude' => 91, 'longitude' => 82.9 ) ) ),
	'out-longitude' => pek_pickup_valid_row( array( 'coordinates' => array( 'latitude' => 55.1, 'longitude' => 181 ) ) ),
	'negative-maxWeight' => pek_pickup_valid_row( array( 'maxWeight' => -1 ) ),
	'nonnumeric-maxVolume' => pek_pickup_valid_row( array( 'maxVolume' => 'nope' ) ),
	'array-maxDimension' => pek_pickup_valid_row( array( 'maxDimension' => array() ) ),
	'negative-maxWeightOnePlace' => pek_pickup_valid_row( array( 'maxWeightOnePlace' => -1 ) ),
	'fractional-maxCount' => pek_pickup_valid_row( array( 'maxCount' => '1.5' ) ),
	'negative-maxCount' => pek_pickup_valid_row( array( 'maxCount' => -1 ) ),
	'array-schedule' => pek_pickup_valid_row( array( 'divisionTimeOfWork' => array( 'bad' => array() ) ) ),
	'oversized-work-time' => pek_pickup_valid_row( array( 'divisionTimeOfWork' => array_fill( 0, 15, array( 'dayOfWeek' => '1', 'workFrom' => '09:00', 'workTo' => '18:00' ) ) ) ),
	'assoc-short-days' => pek_pickup_valid_row( array( 'scheduleShortWorkDays' => array( 'date' => '2026-01-01T00:00:00' ) ) ),
	'list-short-day-item' => pek_pickup_valid_row( array( 'scheduleShortWorkDays' => array( array( '2026-01-01T00:00:00' ) ) ) ),
	'invalid-short-day-field' => pek_pickup_valid_row( array( 'scheduleShortWorkDays' => array( array( 'date' => array() ) ) ) ),
	'oversized-short-days' => pek_pickup_valid_row( array( 'scheduleShortWorkDays' => array_fill( 0, 101, array( 'date' => '2026-01-01T00:00:00' ) ) ) ),
	'assoc-holiday-days' => pek_pickup_valid_row( array( 'scheduleHolidayDays' => array( 'date' => '2026-01-01T00:00:00' ) ) ),
	'nonstr-holiday-day' => pek_pickup_valid_row( array( 'scheduleHolidayDays' => array( 123 ) ) ),
	'invalid-holiday-date' => pek_pickup_valid_row( array( 'scheduleHolidayDays' => array( '2026-02-30T00:00:00' ) ) ),
	'oversized-holiday-days' => pek_pickup_valid_row( array( 'scheduleHolidayDays' => array_fill( 0, 101, '2026-01-01T00:00:00' ) ) ),
);
foreach ( $invalid_rows as $case_name => $row ) {
	$before_terminals = count( $wpdb->pek_terminals );
	try {
		pek_pickup_search_with_response( array( 'freeDepartments' => array( $row ), 'paidDepartments' => array() ), $wpdb, $settings, $credentials );
		pek_pickup_assert( false, 'All-invalid terminal response must throw: ' . $case_name );
	} catch ( PekApiException $exception ) {
		pek_pickup_assert( 'pek_destination_terminal_rows_invalid' === (string) ( $exception->context()['error_code'] ?? '' ), 'All-invalid terminal rows must use stable code: ' . $case_name );
		pek_pickup_assert( $before_terminals === count( $wpdb->pek_terminals ), 'All-invalid terminal rows must not persist repository rows: ' . $case_name );
		pek_pickup_assert( array() === array_filter( array_keys( $GLOBALS['wdc_transients'] ), static fn( string $key ): bool => str_starts_with( $key, 'wdc_pek_destination_terminals_' ) ), 'All-invalid terminal rows must not persist terminal cache: ' . $case_name );
	}
}

foreach ( array(
	'absent-limits' => array_diff_key( pek_pickup_valid_row(), array_flip( array( 'maxWeight', 'maxVolume', 'maxDimension', 'maxWeightOnePlace', 'maxCount' ) ) ),
	'null-limits' => pek_pickup_valid_row( array( 'maxWeight' => null, 'maxVolume' => null, 'maxDimension' => null, 'maxWeightOnePlace' => null, 'maxCount' => null ) ),
	'zero-limits' => pek_pickup_valid_row( array( 'warehouseId' => 'zero-limits', 'maxWeight' => 0, 'maxVolume' => 0, 'maxDimension' => 0, 'maxWeightOnePlace' => 0, 'maxCount' => 0 ) ),
	'positive-string-limits' => pek_pickup_valid_row( array( 'warehouseId' => 'string-limits', 'maxWeight' => '10', 'maxVolume' => '1', 'maxDimension' => '2', 'maxWeightOnePlace' => '10', 'maxCount' => '10' ) ),
	'null-schedules' => pek_pickup_valid_row( array( 'warehouseId' => 'null-schedules', 'scheduleShortWorkDays' => null, 'scheduleHolidayDays' => null, 'divisionTimeOfWork' => null ) ),
	'empty-schedules' => pek_pickup_valid_row( array( 'warehouseId' => 'empty-schedules', 'scheduleShortWorkDays' => array(), 'scheduleHolidayDays' => array(), 'divisionTimeOfWork' => array() ) ),
	'compact-schedules' => pek_pickup_valid_row( array( 'warehouseId' => 'compact-schedules', 'scheduleShortWorkDays' => array( array( 'date' => '2026-01-02T00:00:00', 'workTime' => array( 'periodTimeFrom' => '09:00:00', 'periodTimeTo' => '17:00:00' ), 'breakTime' => null, 'ignored' => 'drop' ) ), 'scheduleHolidayDays' => array( '2026-01-03T00:00:00' ), 'divisionTimeOfWork' => array( array( 'dayOfWeek' => '1', 'workFrom' => '09:00', 'workTo' => '18:00' ) ) ) ),
) as $case_name => $row ) {
	$result = pek_pickup_search_with_response( array( 'freeDepartments' => array( $row ), 'paidDepartments' => array() ), $wpdb, $settings, $credentials );
	pek_pickup_assert( 1 === count( $result[4] ), 'Absent/null/zero/positive limits must be valid row forms: ' . $case_name );
	if ( 'compact-schedules' === $case_name ) {
		$availability = $result[4][0]->raw_reference['availability'];
		pek_pickup_assert( array( 'date' => '2026-01-02T00:00:00', 'workTime' => array( 'periodTimeFrom' => '09:00:00', 'periodTimeTo' => '17:00:00' ) ) === $availability['scheduleShortWorkDays'][0] && array( '2026-01-03T00:00:00' ) === $availability['scheduleHolidayDays'] && ! array_key_exists( 'ignored', $availability['scheduleShortWorkDays'][0] ), 'Valid schedule data must normalize to compact allowlisted availability.' );
	}
}

$mixed_result = pek_pickup_search_with_response(
	array(
		'freeDepartments' => array(
			pek_pickup_valid_row( array( 'warehouseId' => array() ) ),
			pek_pickup_valid_row( array( 'warehouseId' => 'bad-schedule', 'scheduleHolidayDays' => array( '2026-02-30T00:00:00' ) ) ),
			pek_pickup_valid_row( array( 'warehouseId' => 'limit-rejected', 'maxWeight' => 0.5 ) ),
			pek_pickup_valid_row( array( 'warehouseId' => 'mixed-ok' ) ),
		),
		'paidDepartments' => array(),
	),
	$wpdb,
	$settings,
	$credentials
);
$mixed_report = $mixed_result[0]->last_report();
pek_pickup_assert( 1 === count( $mixed_result[4] ) && 'mixed-ok' === $mixed_result[4][0]->code && 2 === (int) $mixed_report['rejected_invalid'] && 1 === (int) $mixed_report['rejected_limits'] && true === $mixed_report['success'], 'Mixed valid/invalid response must keep exact counters and still succeed when one row is valid.' );

$success_before_error = $provider->search( $query );
pek_pickup_assert( array() !== $success_before_error && true === $service->last_report()['success'], 'Sequence setup must start with a successful terminal search.' );
foreach ( array(
	'http' => array( '__status' => 403, 'body' => array() ),
	'malformed-top' => array( 'freeDepartments' => array( 'warehouseId' => 'bad' ), 'paidDepartments' => array() ),
	'all-invalid' => array( 'freeDepartments' => array( pek_pickup_valid_row( array( 'warehouseId' => array() ) ) ), 'paidDepartments' => array() ),
) as $case_name => $response ) {
	$GLOBALS['wdc_transients'] = array();
	$error_http = new PekPickupFakeHttp( $response );
	$error_api = new PekApiClient( $settings, $credentials, $error_http, new PekRequestBudget( $settings ) );
	$error_service = new PekTerminalService( new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), $error_api, $settings ), $error_api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), new PekTerminalRepository( $wpdb ), $settings );
	try {
		$error_service->search( $query );
		pek_pickup_assert( false, 'API exception sequence must propagate: ' . $case_name );
	} catch ( PekApiException ) {
		$error_report = $error_service->last_report();
		pek_pickup_assert( false === $error_report['success'] && 'api' === $error_report['api_source'] && false === $error_report['cache_hit'] && 0 === (int) $error_report['total_returned'], 'Failed search must leave current failed last_report without previous counters: ' . $case_name );
	}
}

foreach ( array(
	'address-zone' => array( 'location_id' => 11, 'zone_response' => array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'formatted' => array() ) ) ), 'code' => 'pek_invalid_findzone_formatted_address' ),
	'coordinate-zone' => array( 'location_id' => 10, 'zone_response' => array( array( 'zoneId' => 'z', 'branchUID' => 'b', 'GeoData' => array( 'bad' ) ) ), 'code' => 'pek_invalid_findzone_coordinates_geodata' ),
) as $case_name => $case ) {
	$GLOBALS['wdc_transients'] = array();
	$wpdb->pek_location_mappings = array_values( array_filter( $wpdb->pek_location_mappings, static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) !== (int) $case['location_id'] ) );
	$zone_http = new PekPickupZoneFailureFakeHttp( $case['zone_response'] );
	$zone_api = new PekApiClient( $settings, $credentials, $zone_http, new PekRequestBudget( $settings ) );
	$zone_service = new PekTerminalService( new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), $zone_api, $settings ), $zone_api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), new PekTerminalRepository( $wpdb ), $settings );
	try {
		$zone_service->search( new CarrierPickupPointQuery( 'pek', (int) $case['location_id'], 'RU', '', null, null, new PickupCargoConstraints( 1000, 1000, 10, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) );
		pek_pickup_assert( false, 'Malformed zone response must propagate as operation failure: ' . $case_name );
	} catch ( PekApiException ) {
		$zone_report = $zone_service->last_report();
		pek_pickup_assert( false === $zone_report['success'] && $case['code'] === $zone_report['error_code'] && 'api' === $zone_report['api_source'] && array() === $zone_report['mapping'] && 0 === (int) $zone_report['total_returned'], 'Malformed zone response must set current failed last_report: ' . $case_name );
	}
}

$address_http = new PekPickupFakeHttp();
$address_api = new PekApiClient( $settings, $credentials, $address_http, new PekRequestBudget( $settings ) );
$address_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), $address_api, $settings );
$address_service = new PekTerminalService( $address_resolver, $address_api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), new PekTerminalRepository( $wpdb ), $settings );
$address_points = $address_service->search( new CarrierPickupPointQuery( 'pek', 11, 'RU', '', 1.0, 2.0, new PickupCargoConstraints( 1000, 1000, 10, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) );
$address_payload = json_decode( (string) $address_http->requests[1]['args']['body'], true );
pek_pickup_assert( count( $address_points ) === 2 && isset( $address_payload['address'] ) && ! isset( $address_payload['coordinates'] ), 'Address-only canonical location must send address only and ignore query override/warehousePoint coordinates.' );

$GLOBALS['wdc_transients'] = array();
$legacy_fingerprint = pek_pickup_legacy_mapping_fingerprint( $wpdb->locations[1] );
$current_address_fingerprint = $address_resolver->fingerprint( WallsShop\WDC\Locations\ValueObjects\Location::from_array( $wpdb->locations[1] ) );
$wpdb->pek_location_mappings = array( array( 'id' => 77, 'location_id' => 11, 'country_code' => 'RU', 'address_fingerprint' => $legacy_fingerprint, 'resolution_method' => 'address', 'mapping_state' => 'resolved', 'precision' => 'exact', 'zone_id' => 'legacy-zone', 'branch_id' => 'legacy-branch', 'main_warehouse_id' => 'legacy-main', 'normalized_address' => 'Россия, Линево', 'latitude' => 55.1, 'longitude' => 82.9, 'checked_at' => '2026-08-03 10:00:00', 'created_at' => '2026-08-03 10:00:00', 'updated_at' => '2026-08-03 10:00:00' ) );
$legacy_http = new PekPickupFakeHttp();
$legacy_api = new PekApiClient( $settings, $credentials, $legacy_http, new PekRequestBudget( $settings ) );
$legacy_resolver = new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), $legacy_api, $settings );
$legacy_service = new PekTerminalService( $legacy_resolver, $legacy_api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), new PekTerminalRepository( $wpdb ), $settings );
$legacy_points = $legacy_service->search( new CarrierPickupPointQuery( 'pek', 11, 'RU', '', null, null, new PickupCargoConstraints( 1000, 1000, 10, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) );
$legacy_payload = json_decode( (string) $legacy_http->requests[1]['args']['body'], true );
$legacy_mapping_after = ( new PekLocationMappingRepository( $wpdb ) )->find_by_location_id( 11 );
pek_pickup_assert( count( $legacy_points ) === 2 && 2 === count( $legacy_http->requests ) && str_contains( $legacy_http->requests[0]['url'], '/branches/findzonebyaddress/' ) && isset( $legacy_payload['address'] ) && ! isset( $legacy_payload['coordinates'] ) && $current_address_fingerprint === $legacy_mapping_after['address_fingerprint'] && null === $legacy_mapping_after['latitude'] && null === $legacy_mapping_after['longitude'], 'Legacy address mapping with warehousePoint coordinates must be refreshed before address-only terminal request.' );
$GLOBALS['wdc_transients'] = array();
$wpdb->pek_location_mappings = array( array( 'id' => 78, 'location_id' => 11, 'country_code' => 'RU', 'address_fingerprint' => $current_address_fingerprint, 'resolution_method' => 'address', 'mapping_state' => 'resolved', 'precision' => 'exact', 'zone_id' => 'legacy-zone', 'branch_id' => 'legacy-branch', 'main_warehouse_id' => '', 'normalized_address' => 'Россия, Линево', 'latitude' => null, 'longitude' => null, 'checked_at' => '2026-08-03 10:00:00', 'created_at' => '2026-08-03 10:00:00', 'updated_at' => '2026-08-03 10:00:00' ) );
$incomplete_http = new PekPickupFakeHttp();
$incomplete_api = new PekApiClient( $settings, $credentials, $incomplete_http, new PekRequestBudget( $settings ) );
$incomplete_service = new PekTerminalService( new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), $incomplete_api, $settings ), $incomplete_api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), new PekTerminalRepository( $wpdb ), $settings );
$incomplete_points = $incomplete_service->search( new CarrierPickupPointQuery( 'pek', 11, 'RU', '', null, null, new PickupCargoConstraints( 1000, 1000, 10, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) );
pek_pickup_assert( count( $incomplete_points ) === 2 && 2 === count( $incomplete_http->requests ), 'Legacy address mapping without main warehouse must be refreshed through zone API before terminal search.' );

$GLOBALS['wdc_transients'] = array();
$wpdb->pek_location_mappings = array( array( 'id' => 79, 'location_id' => 11, 'country_code' => 'RU', 'address_fingerprint' => $current_address_fingerprint, 'resolution_method' => 'address', 'mapping_state' => 'resolved', 'precision' => 'exact', 'zone_id' => 'legacy-zone', 'branch_id' => 'legacy-branch', 'main_warehouse_id' => '', 'normalized_address' => 'Россия, Линево', 'latitude' => null, 'longitude' => null, 'checked_at' => '2026-07-01 00:00:00', 'created_at' => '2026-07-01 00:00:00', 'updated_at' => '2026-07-01 00:00:00' ) );
$incomplete_error_http = new PekPickupZoneFailureFakeHttp( array( 'zoneId' => 'z', 'branchUID' => 'b', 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'formatted' => 'Россия, Линево' ) ) ) );
$incomplete_error_api = new PekApiClient( $settings, $credentials, $incomplete_error_http, new PekRequestBudget( $settings ) );
$incomplete_error_service = new PekTerminalService( new PekLocationResolver( new LocationRepository( $wpdb ), new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), $incomplete_error_api, $settings ), $incomplete_error_api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), new PekTerminalRepository( $wpdb ), $settings );
try {
	$incomplete_error_service->search( new CarrierPickupPointQuery( 'pek', 11, 'RU', '', null, null, new PickupCargoConstraints( 1000, 1000, 10, 1000, 1 ), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) );
	pek_pickup_assert( false, 'Legacy mapping without main warehouse must not be used as stale fallback after zone API failure.' );
} catch ( PekApiException ) {
	$incomplete_error_report = $incomplete_error_service->last_report();
	pek_pickup_assert( false === $incomplete_error_report['success'] && 'pek_incomplete_findzone_address' === $incomplete_error_report['error_code'] && 1 === count( $incomplete_error_http->requests ), 'Legacy missing-main mapping API failure must block terminal API and produce current failed report.' );
}

$GLOBALS['wdc_transients'] = array();
$wpdb->pek_location_mappings = array( array( 'id' => 80, 'location_id' => 11, 'country_code' => 'RU', 'address_fingerprint' => $current_address_fingerprint, 'resolution_method' => 'address', 'mapping_state' => 'resolved', 'precision' => 'exact', 'zone_id' => 'zone-address', 'branch_id' => 'branch-address', 'main_warehouse_id' => 'main-address', 'normalized_address' => 'Россия, Линево', 'latitude' => null, 'longitude' => null, 'checked_at' => '2026-08-03 10:00:00', 'created_at' => '2026-08-03 10:00:00', 'updated_at' => '2026-08-03 10:00:00' ) );
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
