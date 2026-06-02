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

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[dsf]/', $replacement, $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function get_var( mixed $query ): int|string|null {
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
	$GLOBALS['wdc_incremental_smoke_options'][ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
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

$csv = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-incremental-gar.csv';
incremental_csv( $csv );

$db = incremental_seed_db();
$service = incremental_service_with_db( $db );
$job = $service->create_job( $csv, 'incremental-smoke' );
$current_before_staging = $db->wdc_incremental_tables['wdc_locations'];
$job = $service->step_job( $job );
incremental_smoke_assert( 'diff' === $job['phase'], 'Dry staging import must finish without touching current table.' );
incremental_smoke_assert( $current_before_staging === $db->wdc_incremental_tables['wdc_locations'], 'Dry staging import must not affect current locations.' );
$job = $service->step_job( $job );
incremental_smoke_assert( 'analysis' === $job['phase'], 'Diff job must enter analysis phase.' );
incremental_smoke_assert( 1 === (int) $job['new_count'], 'NEW detection must find one new location.' );
incremental_smoke_assert( 1 === (int) $job['removed_count'], 'REMOVED detection must find one removed location.' );
incremental_smoke_assert( 1 === (int) $job['changed_count'], 'CHANGED detection must find one changed location.' );
incremental_smoke_assert( 0 === count( array_filter( $job['samples']['changed'], static fn( array $row ): bool => 'f:fias-a' === (string) $row['key'] ) ), 'Unchanged rows must be ignored.' );

$filtered = $service->prepare_candidate(
	$job,
	array(
		'new' => array(),
		'removed' => array(),
		'changed' => array( 'f:fias-b' ),
	)
);
$filtered_rows = $db->wdc_incremental_tables[ $filtered['candidate_table'] ];
incremental_smoke_assert( isset( $filtered_rows[2] ) && 'Город B updated' === $filtered_rows[2]['display_name'], 'Checkbox filtering must apply selected CHANGED rows.' );
incremental_smoke_assert( isset( $filtered_rows[2] ) && '630222' === $filtered_rows[2]['postal_code'], 'Selected CHANGED row must update postal_code.' );
incremental_smoke_assert( isset( $filtered_rows[2] ) && 55.030199 === (float) $filtered_rows[2]['latitude'] && 82.92043 === (float) $filtered_rows[2]['longitude'], 'Selected CHANGED row must keep existing coordinates.' );
incremental_smoke_assert( 3 === count( $filtered_rows ) && array_any( $filtered_rows, static fn( array $row ): bool => 'fias-c' === $row['fias_id'] ) && ! array_any( $filtered_rows, static fn( array $row ): bool => 'fias-d' === $row['fias_id'] ), 'Checkbox filtering must respect unselected NEW and REMOVED rows.' );

$db = incremental_seed_db();
$service = incremental_service_with_db( $db );
$job = $service->step_job( $service->create_job( $csv, 'incremental-smoke-2' ) );
$job = $service->step_job( $job );
$prepared = $service->prepare_candidate(
	$job,
	array(
		'new' => array( 'f:fias-d' ),
		'removed' => array( 'f:fias-c' ),
		'changed' => array( 'f:fias-b' ),
	)
);
incremental_smoke_assert( 'candidate_ready' === $prepared['phase'], 'Candidate must be ready when validation passes.' );
$candidate = $db->wdc_incremental_tables[ $prepared['candidate_table'] ];
incremental_smoke_assert( 3 === count( $candidate ), 'Candidate table must contain expected row count.' );
incremental_smoke_assert( array_any( $candidate, static fn( array $row ): bool => 'fias-d' === $row['fias_id'] ), 'Candidate table must contain selected NEW row.' );
incremental_smoke_assert( ! array_any( $candidate, static fn( array $row ): bool => 'fias-c' === $row['fias_id'] ), 'Candidate table must omit selected REMOVED row.' );
incremental_smoke_assert( array_any( $candidate, static fn( array $row ): bool => 'fias-b' === $row['fias_id'] && '630222' === $row['postal_code'] ), 'Candidate table must contain selected CHANGED values.' );
incremental_smoke_assert( array_any( $candidate, static fn( array $row ): bool => 'fias-b' === $row['fias_id'] && 55.030199 === (float) $row['latitude'] && 82.92043 === (float) $row['longitude'] ), 'Candidate CHANGED row must preserve old latitude/longitude.' );
incremental_smoke_assert( array_any( $candidate, static fn( array $row ): bool => 'fias-d' === $row['fias_id'] && null === $row['latitude'] && null === $row['longitude'] ), 'Candidate NEW row may import with null latitude/longitude.' );
incremental_smoke_assert( count( $db->wdc_incremental_tables[ $prepared['candidate_alias_table'] ] ) > 0, 'Aliases candidate rebuild must create aliases.' );
incremental_smoke_assert( 3 === count( $db->wdc_incremental_tables['wdc_locations'] ), 'Current table must not be modified before final confirm.' );

$applied = $service->apply_candidate( $prepared );
incremental_smoke_assert( 'applied' === $applied['phase'], 'Atomic swap must apply candidate.' );
incremental_smoke_assert( isset( $db->wdc_incremental_tables[ $prepared['previous_table'] ] ) && 3 === count( $db->wdc_incremental_tables[ $prepared['previous_table'] ] ), 'Atomic swap must leave old table intact.' );
incremental_smoke_assert( array_any( $db->wdc_incremental_tables['wdc_locations'], static fn( array $row ): bool => 'fias-d' === $row['fias_id'] ), 'Atomic swap must promote candidate locations.' );
incremental_smoke_assert( isset( $db->wdc_incremental_tables[ $prepared['previous_alias_table'] ] ), 'Atomic swap must leave old aliases intact.' );
incremental_smoke_assert( count( $db->wdc_incremental_tables['wdc_location_aliases'] ) > 0, 'Atomic swap must promote candidate aliases.' );

$fallback_db = incremental_seed_db();
$fallback_db->wdc_incremental_tables['wdc_locations'][4] = incremental_location_row( 4, '', 2001, 'Gar removed', '620001' );
$fallback_db->wdc_incremental_tables['wdc_locations'][5] = incremental_location_row( 5, '', 2003, 'Gar old', '620003' );
$fallback_db->wdc_incremental_tables['wdc_locations'][6] = incremental_location_row( 6, 'fias-current-gar-conflict', 2004, 'Current filled fias same gar', '620004' );
$fallback_db->wdc_incremental_tables['wdc_locations'][7] = incremental_location_row( 7, '', 2005, 'Current empty fias same gar', '620005' );
$fallback_db->wdc_incremental_tables['wdc_locations'][5]['latitude'] = 56.838011;
$fallback_db->wdc_incremental_tables['wdc_locations'][5]['longitude'] = 60.597465;
$fallback_stage = 'wdc_locations_update_staging_fallback1';
$fallback_db->wdc_incremental_tables[ $fallback_stage ] = array(
	1 => incremental_location_row( 1, 'fias-a', 1001, 'Город A', '630001' ),
	2 => incremental_location_row( 2, 'fias-b', 1002, 'Город B updated', '630222' ),
	3 => incremental_location_row( 3, 'fias-new', 1009, 'Fias new', '630009' ),
	4 => incremental_location_row( 4, '', 2002, 'Gar new', '620002' ),
	5 => incremental_location_row( 5, '', 2003, 'Gar updated', '620333' ),
	6 => incremental_location_row( 6, '', 2004, 'Staging empty fias same gar', '620444' ),
	7 => incremental_location_row( 7, 'fias-staging-gar-conflict', 2005, 'Staging filled fias same gar', '620555' ),
);
$fallback_service = incremental_service_with_db( $fallback_db );
$fallback_job = $fallback_service->build_diff(
	array(
		'phase' => 'diff',
		'staging_table' => $fallback_stage,
	)
);
incremental_smoke_assert( 4 === (int) $fallback_job['new_count'], 'Diff must detect NEW rows by fias_id, gar_object_id, and gar fallback conflicts.' );
incremental_smoke_assert( 4 === (int) $fallback_job['removed_count'], 'Diff must detect REMOVED rows by fias_id, gar_object_id, and gar fallback conflicts.' );
incremental_smoke_assert( 2 === (int) $fallback_job['changed_count'], 'Diff must detect CHANGED rows by both fias_id and gar_object_id.' );
incremental_smoke_assert( array_any( $fallback_job['samples']['new'], static fn( array $row ): bool => 'f:fias-new' === $row['key'] ), 'NEW detection by fias_id must be sampled.' );
incremental_smoke_assert( array_any( $fallback_job['samples']['new'], static fn( array $row ): bool => 'g:2002' === $row['key'] ), 'NEW detection by gar_object_id must be sampled.' );
incremental_smoke_assert( array_any( $fallback_job['samples']['new'], static fn( array $row ): bool => 'g:2004' === $row['key'] ), 'Staging empty fias row must be NEW when current has same gar_object_id with filled fias_id.' );
incremental_smoke_assert( array_any( $fallback_job['samples']['removed'], static fn( array $row ): bool => 'f:fias-c' === $row['key'] ), 'REMOVED detection by fias_id must be sampled.' );
incremental_smoke_assert( array_any( $fallback_job['samples']['removed'], static fn( array $row ): bool => 'g:2001' === $row['key'] ), 'REMOVED detection by gar_object_id must be sampled.' );
incremental_smoke_assert( array_any( $fallback_job['samples']['removed'], static fn( array $row ): bool => 'g:2005' === $row['key'] ), 'Current empty fias row must be REMOVED when staging has same gar_object_id with filled fias_id.' );
incremental_smoke_assert( array_any( $fallback_job['samples']['changed'], static fn( array $row ): bool => 'f:fias-b' === $row['key'] ), 'CHANGED detection by fias_id must be sampled.' );
incremental_smoke_assert( array_any( $fallback_job['samples']['changed'], static fn( array $row ): bool => 'g:2003' === $row['key'] ), 'CHANGED detection by gar_object_id must be sampled.' );
incremental_smoke_assert( ! array_any( $fallback_job['samples']['changed'], static fn( array $row ): bool => in_array( $row['key'], array( 'g:2004', 'g:2005' ), true ) ), 'CHANGED gar fallback must only match when fias_id is empty on both sides.' );
$fallback_job['candidate_table'] = 'wdc_locations_candidate_fallback1';
$fallback_job['candidate_alias_table'] = 'wdc_location_aliases_candidate_fallback1';
$fallback_prepared = $fallback_service->prepare_candidate(
	$fallback_job,
	array(
		'new' => array( 'f:fias-new', 'g:2002' ),
		'removed' => array(),
		'changed' => array( 'f:fias-b', 'g:2003' ),
	)
);
$fallback_candidate = $fallback_db->wdc_incremental_tables[ $fallback_prepared['candidate_table'] ];
incremental_smoke_assert( array_any( $fallback_candidate, static fn( array $row ): bool => 'fias-b' === $row['fias_id'] && 'Город B updated' === $row['display_name'] && '630222' === $row['postal_code'] ), 'apply_changed_rows must update selected fias_id rows.' );
incremental_smoke_assert( array_any( $fallback_candidate, static fn( array $row ): bool => '' === $row['fias_id'] && 2003 === (int) $row['gar_object_id'] && 'Gar updated' === $row['display_name'] && '620333' === $row['postal_code'] ), 'apply_changed_rows must update selected gar_object_id rows.' );
incremental_smoke_assert( array_any( $fallback_candidate, static fn( array $row ): bool => '' === $row['fias_id'] && 2003 === (int) $row['gar_object_id'] && 56.838011 === (float) $row['latitude'] && 60.597465 === (float) $row['longitude'] ), 'apply_changed_rows by gar_object_id must preserve latitude/longitude.' );
incremental_smoke_assert( array_any( $fallback_candidate, static fn( array $row ): bool => '' === $row['fias_id'] && 2002 === (int) $row['gar_object_id'] && null === $row['latitude'] && null === $row['longitude'] ), 'NEW gar_object_id rows may import with null coordinates.' );
$incremental_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Locations/Import/LocationIncrementalUpdateService.php' );
incremental_smoke_assert( ! str_contains( $incremental_source, 'join_condition' ), 'Diff SQL contract must not use the old OR join_condition helper.' );
incremental_smoke_assert( ! preg_match( '/JOIN[^\\n]+\\sOR\\s/i', $incremental_source ), 'Diff SQL contract must not contain OR in JOIN clauses.' );
incremental_smoke_assert( str_contains( $incremental_source, "NOT EXISTS (SELECT 1 FROM {\$current} c WHERE {\$this->empty_fias_condition( 'c' )} AND c.gar_object_id = s.gar_object_id)" ), 'NEW gar fallback SQL must require empty fias_id on the current table.' );
incremental_smoke_assert( str_contains( $incremental_source, "NOT EXISTS (SELECT 1 FROM {\$stage} s WHERE {\$this->empty_fias_condition( 's' )} AND s.gar_object_id = c.gar_object_id)" ), 'REMOVED gar fallback SQL must require empty fias_id on the staging table.' );

$invalid_db = incremental_seed_db();
$invalid_db->wdc_incremental_tables['wdc_locations'][4] = incremental_location_row( 4, 'fias-a', 9999, 'Duplicate FIAS', '630009' );
$invalid_job = incremental_service_with_db( $invalid_db )->prepare_candidate(
	array(
		'candidate_table' => 'wdc_locations_candidate_invalid_fias',
		'candidate_alias_table' => 'wdc_location_aliases_candidate_invalid_fias',
		'staging_table' => 'unused',
	),
	array()
);
incremental_smoke_assert( in_array( 'Candidate contains duplicate fias_id values.', $invalid_job['validation']['errors'], true ), 'Validation must catch duplicate fias_id.' );

$invalid_db = incremental_seed_db();
$invalid_db->wdc_incremental_tables['wdc_locations'][1]['display_name'] = '';
$invalid_job = incremental_service_with_db( $invalid_db )->prepare_candidate(
	array(
		'candidate_table' => 'wdc_locations_candidate_invalid_display',
		'candidate_alias_table' => 'wdc_location_aliases_candidate_invalid_display',
		'staging_table' => 'unused',
	),
	array()
);
incremental_smoke_assert( in_array( 'Candidate contains active rows with empty display_name.', $invalid_job['validation']['errors'], true ), 'Validation must catch empty display_name.' );

$invalid_db = incremental_seed_db();
$invalid_db->wdc_incremental_tables['wdc_locations'][1]['fias_id'] = '';
$invalid_job = incremental_service_with_db( $invalid_db )->prepare_candidate(
	array(
		'candidate_table' => 'wdc_locations_candidate_invalid_empty_fias',
		'candidate_alias_table' => 'wdc_location_aliases_candidate_invalid_empty_fias',
		'staging_table' => 'unused',
	),
	array()
);
incremental_smoke_assert( in_array( 'Candidate contains rows with empty fias_id.', $invalid_job['validation']['errors'], true ), 'Validation must catch empty fias_id.' );

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

update_option( 'wdc_locations_incremental_update_job', array( 'phase' => 'analysis' ) );
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
incremental_smoke_assert( true === $cleanup_result['active_job_cleared'] && false === get_option( 'wdc_locations_incremental_update_job', false ), 'cleanup_temporary_tables must clear active incomplete job state.' );
incremental_smoke_assert( is_array( get_option( 'wdc_locations_incremental_update_last_apply', array() ) ), 'cleanup_temporary_tables must not clear last_apply metadata.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Locations/Admin/LocationsAdminPage.php' );
incremental_smoke_assert( str_contains( $admin_source, 'wp_ajax_wdc_locations_incremental_update_cleanup_list' ) && str_contains( $admin_source, 'wp_ajax_wdc_locations_incremental_update_cleanup_drop' ), 'Admin cleanup actions must be registered as AJAX POST actions.' );
incremental_smoke_assert( str_contains( $admin_source, 'ajax_incremental_update_cleanup_list' ) && str_contains( $admin_source, 'ajax_incremental_update_cleanup_drop' ) && str_contains( $admin_source, '$this->guard_ajax();' ), 'Admin cleanup actions must require nonce and capability guard.' );
incremental_smoke_assert( str_contains( $admin_source, 'dropped_count' ) && str_contains( $admin_source, 'Временные таблицы не найдены.' ), 'Admin cleanup UI must receive dropped count and empty-list message.' );

@unlink( $csv );
echo "Locations incremental update smoke passed\n";
