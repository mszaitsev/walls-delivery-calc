<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Locations\Import\LocationIncrementalUpdateService;
use WallsShop\WDC\Locations\Services\LocationAliasGenerator;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
$GLOBALS['wdc_incremental_smoke_options'] = array();

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		/** @var array<string,array<int,array<string,mixed>>> */
		public array $wdc_incremental_tables = array();
		public string $last_error = '';
		public array $sql = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[dsf]/', $replacement, $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function get_var( mixed $query ): int|string|null {
			$this->sql[] = (string) $query;
			$sql = (string) $query;
			if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
				return '';
			}

			return 0;
		}

		public function get_results( mixed $query, string $output ): array {
			return array();
		}

		public function query( mixed $query ): int|false {
			$this->sql[] = (string) $query;
			return 1;
		}
	}
}

function current_time( string $type ): string {
	return '2026-06-02 12:00:00';
}

function get_option( string $key, mixed $default = false ): mixed {
	return array_key_exists( $key, $GLOBALS['wdc_incremental_smoke_options'] ) ? $GLOBALS['wdc_incremental_smoke_options'][ $key ] : $default;
}

function update_option( string $key, mixed $value, bool|string $autoload = false ): bool {
	$GLOBALS['option_updates'][$key] = (int) ( $GLOBALS['option_updates'][$key] ?? 0 ) + 1;
	$GLOBALS['wdc_incremental_smoke_options'][ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
	if ( ! empty( $GLOBALS['fail_cache_delete'] ) && str_starts_with( $key, '_transient_' ) ) { throw new RuntimeException( 'Injected cache failure' ); }
	$exists = array_key_exists( $key, $GLOBALS['wdc_incremental_smoke_options'] );
	unset( $GLOBALS['wdc_incremental_smoke_options'][ $key ] );
	return $exists;
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function incremental_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

/**
 * @return array<string,mixed>
 */
function incremental_location_row( int $id, string $fias_id, int $gar_id, string $display, string $postal = '', string $city = '', string $settlement_type = 'г' ): array {
	$place = '' !== $city ? $city : $display;
	$location = Location::from_array(
		array(
			'id' => $id,
			'gar_object_id' => $gar_id,
			'fias_id' => $fias_id,
			'gar_id' => (string) $gar_id,
			'country_code' => 'RU',
			'region_name' => 'Новосибирская область',
			'region_code' => '54',
			'city_name' => $city,
			'settlement_name' => $place,
			'settlement_type' => $settlement_type,
			'place_name' => $place,
			'place_type' => $settlement_type,
			'place_level' => 1,
			'display_name' => $display,
			'postal_code' => $postal,
			'active' => true,
		)
	);

	return array(
		'id' => $id,
		'gar_object_id' => $gar_id,
		'fias_id' => $fias_id,
		'kladr_id' => '',
		'gar_id' => (string) $gar_id,
		'country_code' => 'RU',
		'region_name' => 'Новосибирская область',
		'region_code' => '54',
		'region_type' => 'обл',
		'district_name' => '',
		'district_type' => '',
		'district_fias_id' => '',
		'district_kladr_id' => '',
		'district_gar_object_id' => null,
		'district_level' => null,
		'city_name' => $city,
		'city_type' => '' !== $city ? 'г' : '',
		'city_fias_id' => '',
		'city_kladr_id' => '',
		'settlement_name' => $place,
		'settlement_type' => $settlement_type,
		'place_name' => $place,
		'place_type' => $settlement_type,
		'place_level' => 1,
		'display_name' => $display,
		'searchable_text' => $location->get_searchable_text(),
		'okato' => '',
		'oktmo' => '',
		'postal_code' => $postal,
		'latitude' => null,
		'longitude' => null,
		'active' => 1,
		'created_at' => '2026-05-01 00:00:00',
		'updated_at' => '2026-05-01 00:00:00',
	);
}

function incremental_csv( string $path ): void {
	$rows = array(
		array( 'region_code', 'region_name', 'region_type', 'place_name', 'place_type', 'place_level', 'display_name', 'fias_id', 'gar_object_id', 'postal_code' ),
		array( '54', 'Новосибирская область', 'обл', 'Город A', 'г', '1', 'Город A', 'fias-a', '1001', '630001' ),
		array( '54', 'Новосибирская область', 'обл', 'Город B', 'г', '1', 'Город B updated', 'fias-b', '1002', '630222' ),
		array( '54', 'Новосибирская область', 'обл', 'Город D', 'г', '1', 'Город D', 'fias-d', '1004', '630004' ),
		array( '54', 'Новосибирская область', 'обл', 'No key', 'г', '1', 'No key', '', '', '630999' ),
	);
	$handle = fopen( $path, 'wb' );
	foreach ( $rows as $row ) {
		fputcsv( $handle, $row, ';', '"', '\\' );
	}
	fclose( $handle );
}

function incremental_service_with_db( wpdb $db ): LocationIncrementalUpdateService {
	return new LocationIncrementalUpdateService( new LocationAliasGenerator(), $db );
}

function incremental_seed_db(): wpdb {
	$db = new wpdb();
	$db->wdc_incremental_tables['wdc_locations'] = array(
		1 => incremental_location_row( 1, 'fias-a', 1001, 'Город A', '630001' ),
		2 => incremental_location_row( 2, 'fias-b', 1002, 'Город B', '630002' ),
		3 => incremental_location_row( 3, 'fias-c', 1003, 'Город C', '630003' ),
	);
	$db->wdc_incremental_tables['wdc_locations'][2]['latitude'] = 55.030199;
	$db->wdc_incremental_tables['wdc_locations'][2]['longitude'] = 82.92043;
	$db->wdc_incremental_tables['wdc_location_aliases'] = array(
		1 => array( 'id' => 1, 'location_id' => 1, 'alias' => 'Город A', 'alias_normalized' => 'город a', 'source' => 'gar_import', 'created_at' => '2026-05-01 00:00:00' ),
	);

	return $db;
}


define( 'APP_ENCRYPTION_KEY', 'incremental-test-key' );
function wp_remote_post( string $url, array $args ): array {
	$query = json_decode( $args['body'], true )['query'];
	$GLOBALS['postcode_calls'][] = $query;
	$code = $GLOBALS['postcode_limit'] ?? false ? 429 : 200;
	return array( 'response' => array( 'code' => $code ), 'body' => 429 === $code ? '{"message":"daily limit"}' : json_encode( array( 'suggestions' => array( array( 'data' => array( 'fias_id' => $query, 'settlement' => 'Город D', 'postal_code' => $GLOBALS['postcode_value'] ?? '630004' ) ) ) ) ) );
}
class IncrementalDb extends wpdb {
	public array $options = array();
	public array $russian_post_pickup_rows = array();
}
class CoordinateClient implements \WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface {
	public array $calls = array();
	public bool $pause = false;
	public bool $no_result = false;
	public function suggest( string $stage, string $query, array $context = array() ): array {
		$this->calls[] = $query;
		if ( $this->no_result ) { return array( 'success' => true, 'suggestions' => array() ); }
		return $this->pause ? array( 'success' => false, 'error_code' => 'dadata_daily_limit_exhausted' ) : array( 'success' => true, 'suggestions' => array( array( 'data' => array( 'geo_lat' => '55.03', 'geo_lon' => '82.92' ) ) ) );
	}
}
function automatic_fixture(): array {
	$db = new IncrementalDb();
	$db->wdc_incremental_tables = incremental_seed_db()->wdc_incremental_tables;
	foreach ( array( 'AM', 'BY', 'KZ', 'KG' ) as $i => $country ) {
		$row = incremental_location_row( 100 + $i, '', 0, 'Foreign ' . $country, '123456' );
		$row['country_code'] = $country;
		$row['latitude'] = 44.5; $row['longitude'] = 65.2;
		$db->wdc_incremental_tables['wdc_locations'][] = $row;
	}
	$db->wdc_incremental_tables['wdc_locations'][2]['russianpost_courier_calc_postal_code'] = '630999';
	$repository = new \WallsShop\WDC\Locations\Storage\LocationRepository( $db );
	$settings = new \WallsShop\WDC\Infrastructure\Settings\SettingsRepository();
	$encryption = new \WallsShop\WDC\Infrastructure\Security\EncryptionService();
	$settings->replace( array( 'dadata_suggestions_tokens' => array( array( 'id' => 'test', 'encrypted_token' => $encryption->encrypt( 'test-token' ), 'enabled' => true, 'daily_limit' => 1000 ) ) ) );
	$pool = new \WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool( $settings, $encryption );
	$postcodes = new \WallsShop\WDC\Locations\Postcodes\DaDataPostcodeClient( $pool, new \WallsShop\WDC\Infrastructure\Logging\Logger() );
	$coordinates = new CoordinateClient();
	$probe = new class {
		public array $calls = array();
		public function probe( string $code ): array { $this->calls[] = $code; return array( 'success' => true ); }
	};
	$courier = new \WallsShop\WDC\Locations\Postcodes\RussianPostCourierCalcPostcodeFillStateService( $repository, $probe, $db );
	$enricher = new \WallsShop\WDC\Locations\Import\LocationIncrementalCandidateEnricher( $postcodes, new \WallsShop\WDC\Locations\Coordinates\LocationCoordinatesDadataBatchUpdater( $repository, $coordinates ), $courier );
	$service = new LocationIncrementalUpdateService( new LocationAliasGenerator(), $db, $enricher, new \WallsShop\WDC\Checkout\Cache\DeliveryQuoteCacheManager( null, $db ) );
	return array( $db, $service, $coordinates, $probe, $enricher );
}
function drive( LocationIncrementalUpdateService $service, array $job, string $until = 'finished' ): array {
	for ( $i = 0; $i < 1000 && $job['phase'] !== $until && ! in_array( $job['phase'], array( 'failed', 'waiting_dadata_limit' ), true ); ++$i ) {
		$job = $service->step_job( $job );
		incremental_smoke_assert( isset( $job['stage_label'], $job['stage_processed'], $job['stage_total'], $job['overall_percent'] ), 'Every step has progress payload.' );
	}
	return $job;
}
$csv = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-incremental-gar.csv';
incremental_csv( $csv );
[$db, $service, $coordinates, $probe, $enricher] = automatic_fixture();
$before = $db->wdc_incremental_tables['wdc_locations'];
$job = $service->create_job( $csv, 'automatic1' );
$job = drive( $service, $job, 'ready_to_apply' );
incremental_smoke_assert( 'ready_to_apply' === $job['phase'], 'Automatic pipeline reaches ready without approval: ' . json_encode( $job['errors'] ) );
incremental_smoke_assert( $before === $db->wdc_incremental_tables['wdc_locations'], 'All preparation/enrichment leaves live untouched.' );
incremental_smoke_assert( 1 === $job['new_count'] && 1 === $job['removed_count'], 'Only RU contributes to new/removed.' );
$candidate = $db->wdc_incremental_tables[$job['candidate_table']];
$by_fias = array_column( $candidate, null, 'fias_id' );
incremental_smoke_assert( 55.030199 === $by_fias['fias-b']['latitude'] && '630999' === $by_fias['fias-b']['russianpost_courier_calc_postal_code'], 'Changed keeps enrichment.' );
incremental_smoke_assert( '630222' === $by_fias['fias-b']['postal_code'], 'GAR source postcode changes explicitly.' );
incremental_smoke_assert( $before[2]['created_at'] === $by_fias['fias-b']['created_at'], 'Changed created_at preserved.' );
incremental_smoke_assert( 1 === count( $coordinates->calls ) && str_starts_with( $coordinates->calls[0], '630004, ' ), 'Coordinates new-only, postcode first.' );
incremental_smoke_assert( array( '630004' ) === $probe->calls, 'Courier probes new row only.' );
incremental_smoke_assert( empty( $GLOBALS['postcode_calls'] ), 'GAR postcode skips DaData findById.' );
$GLOBALS['option_updates'] = array();
$job = drive( $service, $job );
incremental_smoke_assert( 'finished' === $job['phase'], 'Automatic apply and cleanup finish: ' . json_encode( $job['errors'] ) );
incremental_smoke_assert( count( $before ) === count( $db->wdc_incremental_tables['wdc_locations'] ), 'Stable count after one new/one removed.' );
foreach ( array_filter( $before, fn( $r ) => 'RU' !== $r['country_code'] ) as $row ) {
	incremental_smoke_assert( in_array( $row, $db->wdc_incremental_tables['wdc_locations'], true ), 'Foreign canonical row preserved exactly.' );
}
incremental_smoke_assert( ! isset( $db->wdc_incremental_tables[$job['staging_table']], $db->wdc_incremental_tables[$job['candidate_table']] ), 'Owned temporary tables cleaned.' );
incremental_smoke_assert( ! empty( get_option( 'wdc_location_country_codes' )['stale'] ) && get_option( 'wdc_delivery_rates_cache_version' ), 'Apply invalidates country and delivery caches.' );
incremental_smoke_assert( 1 === $GLOBALS['option_updates']['wdc_location_country_codes'] && 1 === $GLOBALS['option_updates']['wdc_delivery_rates_cache_version'], 'Success invalidates each cache once.' );

// Source families versus identity/derived/enrichment exclusions.
$diff = new ReflectionMethod( $service, 'memory_changed_fields' );
$fields = new ReflectionProperty( $service, 'diff_fields' );
foreach ( $fields->getValue( $service ) as $field ) {
	$old_row = incremental_location_row( 1, 'a', 1, 'Город' ); $new_row = $old_row;
	$new_row[$field] = 'active' === $field ? 0 : 'changed';
	incremental_smoke_assert( isset( $diff->invoke( $service, $old_row, $new_row )[$field] ), 'Source field is compared: ' . $field );
}
foreach ( array( 'fias_id', 'gar_object_id', 'gar_id', 'country_code', 'display_name', 'searchable_text', 'latitude', 'longitude' ) as $field ) {
	$old_row = incremental_location_row( 1, 'a', 1, 'Город' ); $new_row = $old_row; $new_row[$field] = 'different';
	incremental_smoke_assert( array() === $diff->invoke( $service, $old_row, $new_row ), 'Not a mutable source diff: ' . $field );
}
$validate = new ReflectionMethod( $service, 'memory_validate_candidate' );
$invalid = $before; $invalid[1]['fias_id'] = '';
incremental_smoke_assert( ! $validate->invoke( $service, $invalid, count( $invalid ) )['passed'], 'RU requires FIAS.' );
incremental_smoke_assert( $validate->invoke( $service, $before, count( $before ) )['passed'], 'Foreign empty FIAS allowed.' );

// Limit preserves the precise new-row cursor; resume never repeats completed stages.
$memory_diff = new ReflectionMethod( $service, 'memory_diff' );
$old_identity = incremental_location_row( 1, 'old-fias', 1001, 'Город' );
$new_identity = array_replace( $old_identity, array( 'fias_id' => 'new-fias' ) );
$identity_diff = $memory_diff->invoke( $service, array( $old_identity ), array( $new_identity ) );
incremental_smoke_assert( 1 === count( $identity_diff['new'] ) && 1 === count( $identity_diff['removed'] ) && array() === $identity_diff['changed'], 'FIAS identity replacement is removed plus new.' );
$old_identity['fias_id'] = ''; $new_identity = array_replace( $old_identity, array( 'gar_object_id' => 1002 ) );
$identity_diff = $memory_diff->invoke( $service, array( $old_identity ), array( $new_identity ) );
incremental_smoke_assert( 1 === count( $identity_diff['new'] ) && 1 === count( $identity_diff['removed'] ), 'Empty-FIAS GAR fallback keeps identity semantics.' );
$db->sql = array();
( new ReflectionMethod( $service, 'apply_changed_rows' ) )->invoke( $service, 'stage', 'candidate', array( 'f:fias-b' ) );
$sql = end( $db->sql );
foreach ( $fields->getValue( $service ) as $field ) {
	incremental_smoke_assert( str_contains( $sql, 'c.' . $field . ' = s.' . $field ), 'SQL changed source assignment: ' . $field );
}
incremental_smoke_assert( str_contains( $sql, "c.country_code = 'RU'" ) && ! str_contains( $sql, 'c.latitude =' ) && ! str_contains( $sql, 'c.russianpost_courier_calc_postal_code =' ), 'Production SQL preserves foreign/enrichment fields.' );
( new ReflectionMethod( $service, 'apply_removed_rows' ) )->invoke( $service, 'candidate', array( 'g:1001' ) );
$sql = end( $db->sql );
incremental_smoke_assert( str_contains( $sql, "country_code = 'RU'" ) && str_contains( $sql, 'fias_id IS NULL' ), 'Removal SQL is RU-only and handles NULL fallback FIAS.' );
$row = incremental_location_row( 200, 'fias-d', 1004, 'Город D', '' );
( new ReflectionMethod( $service, 'insert_location_rows' ) )->invoke( $service, 'stage', array( $row ), false );
incremental_smoke_assert( str_contains( end( $db->sql ), 'NULL, NULL' ), 'Missing coordinates stay SQL NULL, not numeric zeros.' );

[$db, $service, $coordinates, $probe] = automatic_fixture();
$coordinates->pause = true;
$job = drive( $service, $service->create_job( $csv, 'limit1' ) );
incremental_smoke_assert( 'waiting_dadata_limit' === $job['phase'] && 0 === $job['cursor'], 'Limit pauses at current new row.' );
$job = $service->step_job( $job );
incremental_smoke_assert( 'waiting_dadata_limit' === $job['phase'], 'Ordinary step cannot bypass pause.' );
$coordinates->pause = false;
$job = drive( $service, $service->resume_job( $job ) );
incremental_smoke_assert( 'finished' === $job['phase'] && 2 === count( $coordinates->calls ), 'Resume repeats only paused row.' );

// A single-location resolver writes no live row and reuses token-pool HTTP semantics.
[$db, $service, $coordinates, $probe, $enricher] = automatic_fixture();
$row = incremental_location_row( 200, 'fias-d', 1004, 'Город D', '' );
$before = $db->wdc_incremental_tables;
$result = $enricher->resolve( 'enrich_postcodes', $row );
incremental_smoke_assert( '630004' === ( $result['patch']['postal_code'] ?? '' ), 'New missing postcode resolves through DaData.' );
incremental_smoke_assert( $before === $db->wdc_incremental_tables, 'Resolver never persists to live.' );
$row = array_replace( $row, $result['patch'] );
$result = $enricher->resolve( 'enrich_coordinates', $row );
incremental_smoke_assert( 55.03 === $result['patch']['latitude'] && str_starts_with( $coordinates->calls[0], '630004, ' ), 'Coordinates consume enriched postcode.' );
$result = $enricher->resolve( 'enrich_russianpost_courier', $row );
incremental_smoke_assert( '630004' === $result['patch']['russianpost_courier_calc_postal_code'] && $before === $db->wdc_incremental_tables, 'Courier scoped resolution never bulk-writes live.' );
$generator = new LocationAliasGenerator();
$enriched = array_replace( $row, array( 'postal_code' => '123456', 'latitude' => 1, 'longitude' => 2, 'russianpost_courier_calc_postal_code' => '123456' ) );
incremental_smoke_assert( $generator->generate( Location::from_array( $row ) ) === $generator->generate( Location::from_array( $enriched ) ), 'Aliases independent of enrichment.' );

[$db, $service] = automatic_fixture();
$before = $db->wdc_incremental_tables['wdc_locations'];
$job = drive( $service, $service->create_job( $csv, 'cancel1' ), 'candidate_changes' );
$job = $service->cancel_job( $job );
incremental_smoke_assert( 'canceled' === $job['phase'] && $before === $db->wdc_incremental_tables['wdc_locations'], 'Cancellation leaves live unchanged.' );
// Large stages advance bounded cursors instead of scanning/mutating all rows per step.
[$db, $service] = automatic_fixture();
for ( $i = 1000; $i < 2200; ++$i ) {
	$row = incremental_location_row( $i, '', 0, 'Foreign ' . $i ); $row['country_code'] = 'KZ';
	$db->wdc_incremental_tables['wdc_locations'][] = $row;
}
$job = $service->create_job( $csv, 'bounded1' ); $job['phase'] = 'candidate_seed';
$job = $service->step_job( $job );
incremental_smoke_assert( 'candidate_seed' === $job['phase'] && 1000 === $job['seed_processed'], 'Seed capped at 1000 SQL-copy rows.' );
$job = $service->step_job( $job );
$db->wdc_incremental_tables[$job['candidate_alias_table']] = array();
$job['phase'] = 'aliases_build'; $job['cursor'] = 0;
$job = $service->step_job( $job );
incremental_smoke_assert( 'aliases_build' === $job['phase'] && 500 === $job['aliases_processed'], 'Aliases capped at 500 locations.' );
$db->wdc_incremental_tables[$job['staging_table']] = array();
for ( $i = 3000; $i < 3250; ++$i ) {
	$db->wdc_incremental_tables[$job['staging_table']][] = incremental_location_row( $i, 'new-' . $i, $i, 'Город D' );
}
$job['phase'] = 'candidate_changes'; $job['change_type'] = 'new'; $job['cursor'] = 0;
$job = $service->step_job( $job );
incremental_smoke_assert( 'candidate_changes' === $job['phase'] && 100 === $job['cursor'], 'Source changes capped at 100 rows.' );

// Fatal validation of an already enriched candidate never invalidates caches or changes live.
[$db, $service] = automatic_fixture();
$job = drive( $service, $service->create_job( $csv, 'failure1' ), 'candidate_validate' );
$before = $db->wdc_incremental_tables['wdc_locations'];
foreach ( $db->wdc_incremental_tables[$job['candidate_table']] as &$row ) { if ( 'RU' === $row['country_code'] ) { $row['fias_id'] = ''; break; } } unset( $row );
$GLOBALS['option_updates'] = array();
$job = $service->step_job( $job );
incremental_smoke_assert( 'failed' === $job['phase'] && $before === $db->wdc_incremental_tables['wdc_locations'], 'Invalid enriched candidate leaves live unchanged.' );
incremental_smoke_assert( empty( $GLOBALS['option_updates']['wdc_delivery_rates_cache_version'] ), 'Failure does not invalidate delivery cache.' );

// Simulate a lost response precisely between atomic rename and job persistence.
[$db, $service] = automatic_fixture();
$job = drive( $service, $service->create_job( $csv, 'recovery1' ), 'applying' );
$service->apply_candidate( $job );
$applied = $db->wdc_incremental_tables['wdc_locations'];
try { $service->cancel_job( $job ); throw new LogicException( 'Cancel after swap must fail' ); } catch ( RuntimeException $expected ) {}
$job = drive( $service, $job );
incremental_smoke_assert( 'finished' === $job['phase'] && $applied === $db->wdc_incremental_tables['wdc_locations'], 'Interrupted apply resumes cache/cleanup, no second swap.' );

[$db, $service] = automatic_fixture();
$job = drive( $service, $service->create_job( $csv, 'cache1' ), 'applying' );
$db->options['_transient_wdc_rp_domestic_fixture'] = 'cached';
$GLOBALS['fail_cache_delete'] = true;
$GLOBALS['option_updates'] = array();
$job = $service->step_job( $job );
incremental_smoke_assert( 'waiting_cache_clear' === $job['phase'] && ! empty( $job['applied_at'] ) && ! empty( get_option( 'wdc_delivery_rates_cache_version' ) ), 'Cache failure after swap retains applied state and invalidates rate version.' );
$applied = $db->wdc_incremental_tables['wdc_locations'];
$GLOBALS['fail_cache_delete'] = false;
$job = drive( $service, $service->resume_job( $job ) );
incremental_smoke_assert( 'finished' === $job['phase'] && $applied === $db->wdc_incremental_tables['wdc_locations'] && 1 === $GLOBALS['option_updates']['wdc_location_country_codes'], 'Cache retry completes without another swap/country invalidation.' );

[$db, $service, $coordinates] = automatic_fixture();
$job = drive( $service, $service->create_job( $csv, 'postcode1' ), 'enrich_postcodes' );
foreach ( $db->wdc_incremental_tables[$job['candidate_table']] as &$row ) { if ( 'fias-d' === $row['fias_id'] ) { $row['postal_code'] = ''; } } unset( $row );
$before = $db->wdc_incremental_tables['wdc_locations'];
$job = $service->step_job( $job );
$candidate = array_column( $db->wdc_incremental_tables[$job['candidate_table']], null, 'fias_id' );
incremental_smoke_assert( 1 === $job['cursor'] && '630004' === $candidate['fias-d']['postal_code'] && $before === $db->wdc_incremental_tables['wdc_locations'], 'Postcode step patches one NEW candidate only.' );
$coordinates->no_result = true;
$job = drive( $service, $job );
$live = array_column( $db->wdc_incremental_tables['wdc_locations'], null, 'fias_id' );
incremental_smoke_assert( 'finished' === $job['phase'] && 1 === $job['coordinates_skipped'] && null === $live['fias-d']['latitude'], 'Legitimate coordinate no-result permits apply with null coordinates.' );

[$db, $service, $coordinates, $probe, $enricher] = automatic_fixture();
$row = incremental_location_row( 200, 'fias-d', 1004, 'Город D', '' );
$GLOBALS['postcode_value'] = '';
$result = $enricher->resolve( 'enrich_postcodes', $row );
incremental_smoke_assert( '999999999' === ( $result['patch']['postal_code'] ?? '' ) && 'no_index' === $result['outcome'], 'Successful no-index preserves technical marker contract.' );
unset( $GLOBALS['postcode_value'] );
$GLOBALS['postcode_limit'] = true;
$result = $enricher->resolve( 'enrich_postcodes', $row );
incremental_smoke_assert( $result['pause'] && ! $result['done'] && array() === $result['patch'], 'Postcode token exhaustion pauses without advancing or patching.' );
$GLOBALS['postcode_limit'] = false;

$source = file_get_contents( ABSPATH . 'src/Locations/Admin/LocationsAdminPage.php' );
incremental_smoke_assert( ! str_contains( $source, 'Подтвердить эту страницу' ) && ! str_contains( $source, 'wdc_locations_incremental_update_prepare' ), 'Retired manual UI/routes absent.' );
$cleanup_db = new wpdb();
$cleanup_db->prefix = 'wp_';
$cleanup_db->wdc_incremental_tables = array(
	'wp_wdc_locations' => array( 1 => array( 'id' => 1 ) ),
	'wp_wdc_location_aliases' => array( 1 => array( 'id' => 1 ) ),
	'wp_wdc_locations_update_staging_abcd1234' => array( 1 => array( 'id' => 1 ), 2 => array( 'id' => 2 ) ),
	'wp_wdc_locations_candidate_abcd1234' => array( 1 => array( 'id' => 1 ) ),
	'wp_wdc_location_aliases_candidate_abcd1234' => array( 1 => array( 'id' => 1 ), 2 => array( 'id' => 2 ), 3 => array( 'id' => 3 ) ),
	'wp_wdc_locations_previous_abcd1234' => array( 1 => array( 'id' => 1 ) ),
	'wp_wdc_location_aliases_previous_abcd1234' => array( 1 => array( 'id' => 1 ) ),
	'wp_wdc_locations_backup_20260602' => array( 1 => array( 'id' => 1 ) ),
	'wp_wdc_pickup_points_russian_post_staging_abcd1234' => array( 1 => array( 'id' => 1 ) ),
);
$cleanup_service = incremental_service_with_db( $cleanup_db );
$temporary_tables = $cleanup_service->list_temporary_tables();
$temporary_names = array_map( static fn( array $row ): string => $row['table'], $temporary_tables );
sort( $temporary_names );
incremental_smoke_assert(
	array(
		'wp_wdc_location_aliases_candidate_abcd1234',
		'wp_wdc_locations_candidate_abcd1234',
		'wp_wdc_locations_update_staging_abcd1234',
	) === $temporary_names,
	'list_temporary_tables must find only staging/candidate/candidate_alias tables.'
);
incremental_smoke_assert( 2 === (int) ( $temporary_tables[2]['rows_count'] ?? 0 ) || 2 === (int) ( $temporary_tables[0]['rows_count'] ?? 0 ), 'list_temporary_tables must include rows_count.' );
incremental_smoke_assert( ! in_array( 'wp_wdc_locations', $temporary_names, true ) && ! in_array( 'wp_wdc_location_aliases', $temporary_names, true ), 'list_temporary_tables must not include current tables.' );
incremental_smoke_assert( ! in_array( 'wp_wdc_locations_previous_abcd1234', $temporary_names, true ) && ! in_array( 'wp_wdc_location_aliases_previous_abcd1234', $temporary_names, true ), 'list_temporary_tables must not include previous tables.' );
incremental_smoke_assert( ! in_array( 'wp_wdc_locations_backup_20260602', $temporary_names, true ), 'list_temporary_tables must not include backup tables.' );

update_option( 'wdc_locations_incremental_update_job', array( 'phase' => 'failed' ) );
update_option( 'wdc_locations_incremental_update_last_apply', array( 'applied_at' => '2026-06-02 12:00:00' ) );
$cleanup_result = $cleanup_service->cleanup_temporary_tables();
$dropped = $cleanup_result['dropped'];
sort( $dropped );
incremental_smoke_assert( $temporary_names === $dropped, 'cleanup_temporary_tables must drop only whitelisted temporary tables.' );
incremental_smoke_assert( count( $cleanup_result['dropped'] ) > 0 && (int) ( $cleanup_result['debug']['dropped'] ?? 0 ) > 0, 'cleanup_temporary_tables must report dropped count for UI.' );
incremental_smoke_assert( isset( $cleanup_result['debug']['found'], $cleanup_result['debug']['whitelisted'], $cleanup_result['debug']['elapsed_ms'] ), 'cleanup_temporary_tables must expose debug timing.' );
incremental_smoke_assert( ! isset( $cleanup_db->wdc_incremental_tables['wp_wdc_locations_update_staging_abcd1234'], $cleanup_db->wdc_incremental_tables['wp_wdc_locations_candidate_abcd1234'], $cleanup_db->wdc_incremental_tables['wp_wdc_location_aliases_candidate_abcd1234'] ), 'cleanup_temporary_tables must remove staging/candidate tables.' );
incremental_smoke_assert( isset( $cleanup_db->wdc_incremental_tables['wp_wdc_locations'], $cleanup_db->wdc_incremental_tables['wp_wdc_location_aliases'] ), 'cleanup_temporary_tables must not remove current tables.' );
incremental_smoke_assert( isset( $cleanup_db->wdc_incremental_tables['wp_wdc_locations_previous_abcd1234'], $cleanup_db->wdc_incremental_tables['wp_wdc_location_aliases_previous_abcd1234'] ), 'cleanup_temporary_tables must not remove previous tables.' );
incremental_smoke_assert( isset( $cleanup_db->wdc_incremental_tables['wp_wdc_locations_backup_20260602'] ), 'cleanup_temporary_tables must not remove backup tables.' );
incremental_smoke_assert( true === $cleanup_result['active_job_cleared'] && false === get_option( 'wdc_locations_incremental_update_job', false ), 'cleanup_temporary_tables must clear failed diagnostic job state.' );
incremental_smoke_assert( is_array( get_option( 'wdc_locations_incremental_update_last_apply', array() ) ), 'cleanup_temporary_tables must not clear last_apply metadata.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Locations/Admin/LocationsAdminPage.php' );
incremental_smoke_assert( str_contains( $admin_source, 'wp_ajax_wdc_locations_incremental_update_cleanup_list' ) && str_contains( $admin_source, 'wp_ajax_wdc_locations_incremental_update_cleanup_drop' ), 'Admin cleanup actions must be registered as AJAX POST actions.' );
incremental_smoke_assert( str_contains( $admin_source, 'ajax_incremental_update_cleanup_list' ) && str_contains( $admin_source, 'ajax_incremental_update_cleanup_drop' ) && str_contains( $admin_source, '$this->guard_ajax();' ), 'Admin cleanup actions must require nonce and capability guard.' );
incremental_smoke_assert( str_contains( $admin_source, 'dropped_count' ) && str_contains( $admin_source, 'Временные таблицы не найдены.' ), 'Admin cleanup UI must receive dropped count and empty-list message.' );
unlink( $csv );
echo "Locations automatic incremental update smoke passed\n";
