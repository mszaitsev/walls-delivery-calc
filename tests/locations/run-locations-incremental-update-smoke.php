<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Locations\Import\LocationIncrementalUpdateService;
use WallsShop\WDC\Locations\Services\LocationAliasGenerator;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

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

function update_option( string $key, mixed $value, bool|string $autoload = false ): bool {
	return true;
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
incremental_smoke_assert( count( $db->wdc_incremental_tables[ $prepared['candidate_alias_table'] ] ) > 0, 'Aliases candidate rebuild must create aliases.' );
incremental_smoke_assert( 3 === count( $db->wdc_incremental_tables['wdc_locations'] ), 'Current table must not be modified before final confirm.' );

$applied = $service->apply_candidate( $prepared );
incremental_smoke_assert( 'applied' === $applied['phase'], 'Atomic swap must apply candidate.' );
incremental_smoke_assert( isset( $db->wdc_incremental_tables[ $prepared['previous_table'] ] ) && 3 === count( $db->wdc_incremental_tables[ $prepared['previous_table'] ] ), 'Atomic swap must leave old table intact.' );
incremental_smoke_assert( array_any( $db->wdc_incremental_tables['wdc_locations'], static fn( array $row ): bool => 'fias-d' === $row['fias_id'] ), 'Atomic swap must promote candidate locations.' );
incremental_smoke_assert( isset( $db->wdc_incremental_tables[ $prepared['previous_alias_table'] ] ), 'Atomic swap must leave old aliases intact.' );
incremental_smoke_assert( count( $db->wdc_incremental_tables['wdc_location_aliases'] ) > 0, 'Atomic swap must promote candidate aliases.' );

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

@unlink( $csv );
echo "Locations incremental update smoke passed\n";
