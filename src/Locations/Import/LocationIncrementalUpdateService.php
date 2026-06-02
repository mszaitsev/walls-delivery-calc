<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Import;

use RuntimeException;
use SplFileObject;
use WallsShop\WDC\Locations\Services\LocationAliasGenerator;
use WallsShop\WDC\Locations\Services\LocationCountryIndexService;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class LocationIncrementalUpdateService {
	private const ACTIVE_JOB_OPTION = 'wdc_locations_incremental_update_job';
	private const CSV_BATCH_SIZE = 1000;
	private const ALIAS_BATCH_SIZE = 500;
	private const MAX_COUNT_DELTA_RATIO = 0.20;

	/** @var array<int,string> */
	private array $csv_columns = array(
		'region_code',
		'region_name',
		'region_type',
		'region_fias_id',
		'region_kladr_id',
		'district_name',
		'district_type',
		'district_fias_id',
		'district_kladr_id',
		'district_gar_object_id',
		'district_level',
		'city_name',
		'city_type',
		'city_fias_id',
		'city_kladr_id',
		'place_name',
		'place_type',
		'place_level',
		'display_name',
		'fias_id',
		'gar_object_id',
		'kladr_id',
		'okato',
		'oktmo',
		'postal_code',
	);

	/** @var array<int,string> */
	private array $required_columns = array( 'region_code', 'region_name', 'place_name' );

	/** @var array<int,string> */
	private array $location_columns = array(
		'gar_object_id',
		'fias_id',
		'kladr_id',
		'gar_id',
		'country_code',
		'region_name',
		'region_code',
		'region_type',
		'district_name',
		'district_type',
		'district_fias_id',
		'district_kladr_id',
		'district_gar_object_id',
		'district_level',
		'city_name',
		'city_type',
		'city_fias_id',
		'city_kladr_id',
		'settlement_name',
		'settlement_type',
		'place_name',
		'place_type',
		'place_level',
		'display_name',
		'searchable_text',
		'okato',
		'oktmo',
		'postal_code',
		'latitude',
		'longitude',
		'active',
		'created_at',
		'updated_at',
	);

	/** @var array<int,string> */
	private array $diff_fields = array(
		'country_code',
		'region_name',
		'region_code',
		'city_name',
		'settlement_name',
		'settlement_type',
		'display_name',
		'postal_code',
		'active',
	);

	private \wpdb $wpdb;

	public function __construct( private LocationAliasGenerator $alias_generator, ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function create_job( string $path, string $job_id = '' ): array {
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'GAR CSV file is not readable.' );
		}

		$token = $this->token( '' !== $job_id ? $job_id : sha1( $path . '|' . microtime( true ) ) );

		return array(
			'job_id'                 => $token,
			'source_path'            => $path,
			'phase'                  => 'staging',
			'rows_total_estimated'   => max( 1, (int) floor( filesize( $path ) / 400 ) ),
			'rows_read'              => 0,
			'stage_rows'             => 0,
			'unsupported_rows'       => 0,
			'byte_offset'            => 0,
			'header_map'             => array(),
			'current_count'          => 0,
			'staging_count'          => 0,
			'new_count'              => 0,
			'removed_count'          => 0,
			'changed_count'          => 0,
			'candidate_count'        => 0,
			'candidate_aliases'      => 0,
			'staging_table'          => $this->staging_table( $token ),
			'candidate_table'        => $this->candidate_table( $token ),
			'candidate_alias_table'  => $this->candidate_alias_table( $token ),
			'previous_table'         => $this->previous_table( $token ),
			'previous_alias_table'   => $this->previous_alias_table( $token ),
			'validation'             => array(),
			'errors'                 => array(),
			'started_at'             => $this->now(),
			'updated_at'             => $this->now(),
		);
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public function step_job( array $job ): array {
		try {
			if ( 'staging' === (string) ( $job['phase'] ?? '' ) ) {
				return $this->step_staging_job( $job );
			}

			if ( 'diff' === (string) ( $job['phase'] ?? '' ) ) {
				return $this->build_diff( $job );
			}
		} catch ( \Throwable $exception ) {
			$job['phase'] = 'failed';
			$job['errors'][] = $exception->getMessage();
		}

		$job['updated_at'] = $this->now();
		return $job;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public function build_diff( array $job ): array {
		if ( $this->is_memory_db() ) {
			return $this->memory_build_diff( $job );
		}

		$current = $this->locations_table();
		$stage = $this->table_name( (string) ( $job['staging_table'] ?? '' ) );
		$this->ensure_table( $stage );

		$job['current_count'] = $this->count_table( $current );
		$job['staging_count'] = $this->count_table( $stage );
		$job['new_count'] = $this->new_count( $stage, $current );
		$job['removed_count'] = $this->removed_count( $stage, $current );
		$job['changed_count'] = $this->changed_count( $stage, $current );
		$job['samples'] = $this->diff_samples( $stage, $current );
		$job['phase'] = 'analysis';
		$job['updated_at'] = $this->now();

		return $job;
	}

	/**
	 * @param array<string,mixed> $job
	 * @param array<string,array<int,string>> $selected
	 * @return array<string,mixed>
	 */
	public function prepare_candidate( array $job, array $selected ): array {
		if ( $this->is_memory_db() ) {
			return $this->memory_prepare_candidate( $job, $selected );
		}

		$current = $this->locations_table();
		$stage = $this->table_name( (string) ( $job['staging_table'] ?? '' ) );
		$candidate = $this->table_name( (string) ( $job['candidate_table'] ?? '' ) );
		$alias_candidate = $this->table_name( (string) ( $job['candidate_alias_table'] ?? '' ) );

		$this->replace_like_table( $candidate, $current );
		$this->query_or_fail( "INSERT INTO {$candidate} SELECT * FROM {$current}", 'Unable to seed candidate locations table.' );

		$this->apply_new_rows( $stage, $candidate, $selected['new'] ?? array() );
		$this->apply_removed_rows( $candidate, $selected['removed'] ?? array() );
		$this->apply_changed_rows( $stage, $candidate, $selected['changed'] ?? array() );

		$validation = $this->validate_candidate( $candidate, $this->count_table( $current ) );
		$job['validation'] = $validation;
		$job['candidate_count'] = $this->count_table( $candidate );
		$job['candidate_aliases'] = $this->rebuild_aliases( $candidate, $alias_candidate );
		$job['phase'] = empty( $validation['errors'] ) ? 'candidate_ready' : 'candidate_failed';
		$job['updated_at'] = $this->now();

		return $job;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	public function apply_candidate( array $job ): array {
		if ( $this->is_memory_db() ) {
			return $this->memory_apply_candidate( $job );
		}

		$current = $this->locations_table();
		$aliases = $this->aliases_table();
		$candidate = $this->table_name( (string) ( $job['candidate_table'] ?? '' ) );
		$alias_candidate = $this->table_name( (string) ( $job['candidate_alias_table'] ?? '' ) );
		$previous = $this->table_name( (string) ( $job['previous_table'] ?? '' ) );
		$previous_aliases = $this->table_name( (string) ( $job['previous_alias_table'] ?? '' ) );

		$this->ensure_table( $candidate );
		$this->ensure_table( $alias_candidate );
		$validation = $this->validate_candidate( $candidate, $this->count_table( $current ) );
		if ( ! empty( $validation['errors'] ) ) {
			$job['validation'] = $validation;
			$job['phase'] = 'candidate_failed';
			return $job;
		}

		$this->query_or_fail(
			"RENAME TABLE {$current} TO {$previous}, {$candidate} TO {$current}, {$aliases} TO {$previous_aliases}, {$alias_candidate} TO {$aliases}",
			'Unable to atomically swap location tables.'
		);

		$job['phase'] = 'applied';
		$job['applied_at'] = $this->now();
		$job['validation'] = $validation;
		$this->update_option(
			'wdc_locations_incremental_update_last_apply',
			array(
				'applied_at' => $job['applied_at'],
				'current_table' => $current,
				'previous_table' => $previous,
				'current_alias_table' => $aliases,
				'previous_alias_table' => $previous_aliases,
			)
		);
		LocationCountryIndexService::mark_option_stale();

		return $job;
	}

	/**
	 * @return array<int,array{table:string,type:string,rows_count:int,created_hint:string,safe_to_drop:bool}>
	 */
	public function list_temporary_tables(): array {
		if ( $this->is_memory_db() ) {
			return $this->memory_list_temporary_tables();
		}

		return $this->discover_temporary_tables( true )['tables'];
	}

	/**
	 * @return array{dropped:array<int,string>,skipped:array<int,string>,errors:array<int,string>,active_job_cleared:bool}
	 */
	public function cleanup_temporary_tables(): array {
		if ( $this->is_memory_db() ) {
			return $this->memory_cleanup_temporary_tables();
		}

		$started = microtime( true );
		$discovery = $this->discover_temporary_tables( false );
		$result = array(
			'dropped' => array(),
			'skipped' => $discovery['skipped'],
			'errors' => array(),
			'active_job_cleared' => false,
			'debug' => array(
				'found' => $discovery['found'],
				'whitelisted' => count( $discovery['tables'] ),
				'dropped' => 0,
				'skipped' => count( $discovery['skipped'] ),
				'elapsed_ms' => 0,
			),
		);
		foreach ( $discovery['tables'] as $row ) {
			$table = (string) $row['table'];
			if ( ! $this->temporary_table_type( $table ) ) {
				$result['skipped'][] = $table;
				continue;
			}
			try {
				$this->query_or_fail( "DROP TABLE IF EXISTS {$table}", 'Unable to drop temporary incremental update table.' );
				$result['dropped'][] = $table;
			} catch ( RuntimeException $exception ) {
				$result['errors'][] = $table . ': ' . $exception->getMessage();
			}
		}
		$result['active_job_cleared'] = $this->delete_option( self::ACTIVE_JOB_OPTION );
		$result['debug']['dropped'] = count( $result['dropped'] );
		$result['debug']['skipped'] = count( $result['skipped'] );
		$result['debug']['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		return $result;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function step_staging_job( array $job ): array {
		if ( $this->is_memory_db() ) {
			return $this->memory_step_staging_job( $job );
		}

		$path = (string) ( $job['source_path'] ?? '' );
		if ( ! is_readable( $path ) ) {
			throw new RuntimeException( 'GAR CSV file is not readable.' );
		}

		$stage = $this->table_name( (string) ( $job['staging_table'] ?? '' ) );
		if ( 0 === (int) ( $job['byte_offset'] ?? 0 ) ) {
			$this->replace_like_table( $stage, $this->locations_table() );
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'GAR CSV file cannot be opened.' );
		}

		$header = is_array( $job['header_map'] ?? null ) ? $job['header_map'] : array();
		if ( array() === $header ) {
			$row = fgetcsv( $handle, 0, ';', '"', '\\' );
			if ( ! is_array( $row ) || array( null ) === $row ) {
				fclose( $handle );
				throw new RuntimeException( 'GAR CSV header row is missing.' );
			}
			$header = $this->header_map( $row );
			foreach ( $this->required_columns as $required ) {
				if ( ! array_key_exists( $required, $header ) ) {
					fclose( $handle );
					throw new RuntimeException( sprintf( 'GAR CSV missing required column: %s', $required ) );
				}
			}
			$job['header_map'] = $header;
			$job['byte_offset'] = ftell( $handle );
		} else {
			fseek( $handle, (int) $job['byte_offset'] );
		}

		$batch = array();
		$read = 0;
		while ( $read < self::CSV_BATCH_SIZE && false !== ( $row = fgetcsv( $handle, 0, ';', '"', '\\' ) ) ) {
			if ( ! is_array( $row ) || array( null ) === $row ) {
				continue;
			}
			++$read;
			++$job['rows_read'];
			$mapped = $this->map_csv_row_to_location_row( $row, $header );
			if ( null === $mapped ) {
				++$job['unsupported_rows'];
				continue;
			}
			$batch[] = $mapped;
		}

		if ( array() !== $batch ) {
			$job['stage_rows'] += $this->insert_location_rows( $stage, $batch, false );
		}

		$job['byte_offset'] = ftell( $handle );
		$eof = feof( $handle );
		fclose( $handle );

		if ( $eof ) {
			$job['phase'] = 'diff';
		}
		$job['updated_at'] = $this->now();

		return $job;
	}

	/**
	 * @param array<int,mixed> $row
	 * @return array<string,int>
	 */
	private function header_map( array $row ): array {
		$map = array();
		foreach ( $row as $index => $column ) {
			$name = strtolower( preg_replace( '/^\xEF\xBB\xBF/', '', trim( (string) $column ) ) ?? trim( (string) $column ) );
			if ( '' !== $name && ! isset( $map[ $name ] ) ) {
				$map[ $name ] = (int) $index;
			}
		}

		return $map;
	}

	/**
	 * @param array<int,mixed> $row
	 * @param array<string,int> $header
	 * @return array<string,mixed>|null
	 */
	private function map_csv_row_to_location_row( array $row, array $header ): ?array {
		$mapped = array();
		foreach ( $this->csv_columns as $column ) {
			$mapped[ $column ] = $this->csv_value( $row, $header, $column );
		}
		foreach ( $this->required_columns as $required ) {
			if ( '' === $mapped[ $required ] ) {
				return null;
			}
		}

		$gar_object_id = (int) $mapped['gar_object_id'];
		$fias_id = trim( (string) $mapped['fias_id'] );
		if ( '' === $fias_id && $gar_object_id <= 0 ) {
			return null;
		}

		$display_name = trim( (string) $mapped['display_name'] );
		$place_name = trim( (string) $mapped['place_name'] );
		$place_type = trim( (string) $mapped['place_type'] );
		if ( '' === $display_name ) {
			$display_name = $this->fallback_display_name( $mapped );
		}

		$location = Location::from_array(
			array(
				'gar_object_id' => $gar_object_id,
				'fias_id' => $fias_id,
				'gar_id' => $gar_object_id > 0 ? (string) $gar_object_id : '',
				'kladr_id' => $mapped['kladr_id'],
				'country_code' => 'RU',
				'region_name' => $mapped['region_name'],
				'region_code' => $mapped['region_code'],
				'region_type' => $mapped['region_type'],
				'district_name' => $mapped['district_name'],
				'district_type' => $mapped['district_type'],
				'district_fias_id' => $mapped['district_fias_id'],
				'district_kladr_id' => $mapped['district_kladr_id'],
				'district_gar_object_id' => (int) $mapped['district_gar_object_id'],
				'district_level' => '' === $mapped['district_level'] ? null : (int) $mapped['district_level'],
				'city_name' => $mapped['city_name'],
				'city_type' => $mapped['city_type'],
				'city_fias_id' => $mapped['city_fias_id'],
				'city_kladr_id' => $mapped['city_kladr_id'],
				'settlement_name' => $place_name,
				'settlement_type' => $place_type,
				'place_name' => $place_name,
				'place_type' => $place_type,
				'place_level' => '' === $mapped['place_level'] ? 0 : (int) $mapped['place_level'],
				'display_name' => $display_name,
				'postal_code' => $mapped['postal_code'],
				'okato' => $mapped['okato'],
				'oktmo' => $mapped['oktmo'],
				'active' => true,
			)
		);

		$now = $this->now();
		return array(
			'gar_object_id' => $gar_object_id,
			'fias_id' => $fias_id,
			'kladr_id' => $mapped['kladr_id'],
			'gar_id' => $gar_object_id > 0 ? (string) $gar_object_id : '',
			'country_code' => 'RU',
			'region_name' => $mapped['region_name'],
			'region_code' => $mapped['region_code'],
			'region_type' => $mapped['region_type'],
			'district_name' => $mapped['district_name'],
			'district_type' => $mapped['district_type'],
			'district_fias_id' => $mapped['district_fias_id'],
			'district_kladr_id' => $mapped['district_kladr_id'],
			'district_gar_object_id' => $mapped['district_gar_object_id'] !== '' ? (int) $mapped['district_gar_object_id'] : null,
			'district_level' => $mapped['district_level'] !== '' ? (int) $mapped['district_level'] : null,
			'city_name' => $mapped['city_name'],
			'city_type' => $mapped['city_type'],
			'city_fias_id' => $mapped['city_fias_id'],
			'city_kladr_id' => $mapped['city_kladr_id'],
			'settlement_name' => $place_name,
			'settlement_type' => $place_type,
			'place_name' => $place_name,
			'place_type' => $place_type,
			'place_level' => $mapped['place_level'] !== '' ? (int) $mapped['place_level'] : 0,
			'display_name' => $display_name,
			'searchable_text' => $location->get_searchable_text(),
			'okato' => $mapped['okato'],
			'oktmo' => $mapped['oktmo'],
			'postal_code' => $mapped['postal_code'],
			'latitude' => null,
			'longitude' => null,
			'active' => 1,
			'created_at' => $now,
			'updated_at' => $now,
		);
	}

	/**
	 * @param array<int,mixed> $row
	 * @param array<string,int> $header
	 */
	private function csv_value( array $row, array $header, string $column ): string {
		if ( ! array_key_exists( $column, $header ) ) {
			return '';
		}

		return trim( (string) ( $row[ $header[ $column ] ] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function fallback_display_name( array $row ): string {
		return trim(
			implode(
				', ',
				array_filter(
					array(
						(string) ( $row['region_name'] ?? '' ),
						(string) ( $row['district_name'] ?? '' ),
						(string) ( $row['city_name'] ?? '' ),
						trim( (string) ( $row['place_type'] ?? '' ) . ' ' . (string) ( $row['place_name'] ?? '' ) ),
					)
				)
			)
		);
	}

	private function replace_like_table( string $target, string $source ): void {
		$this->query_or_fail( "DROP TABLE IF EXISTS {$target}", 'Unable to drop previous working table.' );
		$this->query_or_fail( "CREATE TABLE {$target} LIKE {$source}", 'Unable to create working table.' );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function insert_location_rows( string $table, array $rows, bool $include_id ): int {
		if ( array() === $rows ) {
			return 0;
		}

		$columns = $include_id ? array_merge( array( 'id' ), $this->location_columns ) : $this->location_columns;
		$formats = array_map( fn( string $column ): string => $this->format_for_column( $column ), $columns );
		$row_placeholder = '(' . implode( ', ', $formats ) . ')';
		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES %s',
			$table,
			implode( ', ', $columns ),
			implode( ', ', array_fill( 0, count( $rows ), $row_placeholder ) )
		);
		$args = array();
		foreach ( $rows as $row ) {
			foreach ( $columns as $column ) {
				$args[] = $row[ $column ] ?? null;
			}
		}

		$this->query_or_fail( $this->wpdb->prepare( $sql, ...$args ), 'Unable to insert location rows.' );
		return count( $rows );
	}

	private function apply_new_rows( string $stage, string $candidate, array $keys ): void {
		$keys = $this->sanitize_keys( $keys );
		if ( array() === $keys ) {
			return;
		}
		$columns = implode( ', ', $this->location_columns );
		$where = $this->key_in_condition( 's', $keys );
		$this->query_or_fail( "INSERT IGNORE INTO {$candidate} ({$columns}) SELECT {$columns} FROM {$stage} s WHERE {$where}", 'Unable to add selected new locations.' );
	}

	private function apply_removed_rows( string $candidate, array $keys ): void {
		$keys = $this->sanitize_keys( $keys );
		if ( array() === $keys ) {
			return;
		}
		$this->query_or_fail( "DELETE FROM {$candidate} WHERE {$this->key_in_condition( '', $keys )}", 'Unable to remove selected locations.' );
	}

	private function apply_changed_rows( string $stage, string $candidate, array $keys ): void {
		$keys = $this->sanitize_keys( $keys );
		if ( array() === $keys ) {
			return;
		}
		$assignments = array();
		foreach ( $this->location_columns as $column ) {
			if ( in_array( $column, array( 'created_at', 'latitude', 'longitude' ), true ) ) {
				continue;
			}
			$assignments[] = "c.{$column} = s.{$column}";
		}
		$parsed = $this->parse_keys( $keys );
		if ( array() !== $parsed['fias'] ) {
			$this->query_or_fail(
				"UPDATE {$candidate} c INNER JOIN {$stage} s ON c.fias_id = s.fias_id SET " . implode( ', ', $assignments ) . ' WHERE ' . $this->fias_in_condition( 's', $parsed['fias'] ),
				'Unable to apply selected changed locations by fias_id.'
			);
		}
		if ( array() !== $parsed['gar'] ) {
			$this->query_or_fail(
				"UPDATE {$candidate} c INNER JOIN {$stage} s ON c.gar_object_id = s.gar_object_id SET " . implode( ', ', $assignments ) . ' WHERE ' . $this->empty_fias_condition( 's' ) . ' AND ' . $this->empty_fias_condition( 'c' ) . ' AND ' . $this->gar_in_condition( 's', $parsed['gar'] ),
				'Unable to apply selected changed locations by gar_id.'
			);
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function validate_candidate( string $candidate, int $current_count ): array {
		$count = $this->count_table( $candidate );
		$errors = array();
		if ( $count <= 0 ) {
			$errors[] = 'Candidate locations table is empty.';
		}
		if ( $this->count_rows( "SELECT COUNT(*) FROM (SELECT fias_id FROM {$candidate} WHERE fias_id IS NOT NULL AND fias_id != '' GROUP BY fias_id HAVING COUNT(*) > 1) d" ) > 0 ) {
			$errors[] = 'Candidate contains duplicate fias_id values.';
		}
		if ( $this->count_rows( "SELECT COUNT(*) FROM (SELECT gar_object_id FROM {$candidate} WHERE active = 1 AND gar_object_id IS NOT NULL AND gar_object_id > 0 GROUP BY gar_object_id HAVING COUNT(*) > 1) d" ) > 0 ) {
			$errors[] = 'Candidate contains duplicate active gar_id values.';
		}
		if ( $this->count_rows( "SELECT COUNT(*) FROM {$candidate} WHERE country_code = 'RU' AND active = 1" ) <= 0 ) {
			$errors[] = 'Candidate does not contain active RU locations.';
		}
		if ( $current_count > 0 && abs( $count - $current_count ) / $current_count >= self::MAX_COUNT_DELTA_RATIO ) {
			$errors[] = 'Candidate row count differs from current table by 20% or more.';
		}
		if ( $this->count_rows( "SELECT COUNT(*) FROM {$candidate} WHERE active = 1 AND (display_name IS NULL OR display_name = '')" ) > 0 ) {
			$errors[] = 'Candidate contains active rows with empty display_name.';
		}
		if ( $this->count_rows( "SELECT COUNT(*) FROM {$candidate} WHERE fias_id IS NULL OR fias_id = ''" ) > 0 ) {
			$errors[] = 'Candidate contains rows with empty fias_id.';
		}

		return array( 'passed' => array() === $errors, 'errors' => $errors, 'current_count' => $current_count, 'candidate_count' => $count );
	}

	private function rebuild_aliases( string $candidate, string $alias_candidate ): int {
		$this->replace_like_table( $alias_candidate, $this->aliases_table() );
		$total = 0;
		$last_id = 0;
		do {
			$rows = $this->wpdb->get_results(
				$this->wpdb->prepare( "SELECT * FROM {$candidate} WHERE id > %d ORDER BY id ASC LIMIT %d", $last_id, self::ALIAS_BATCH_SIZE ),
				ARRAY_A
			);
			$rows = is_array( $rows ) ? $rows : array();
			$alias_rows = array();
			foreach ( $rows as $row ) {
				$last_id = max( $last_id, (int) ( $row['id'] ?? 0 ) );
				$location = Location::from_array( $row );
				foreach ( $this->alias_generator->generate( $location ) as $alias ) {
					$alias_rows[] = array(
						'location_id' => (int) $row['id'],
						'alias' => $alias,
						'alias_normalized' => Location::normalize_search_text( $alias ),
						'source' => 'gar_import',
						'created_at' => $this->now(),
					);
				}
			}
			$total += $this->insert_alias_rows( $alias_candidate, $alias_rows );
		} while ( count( $rows ) === self::ALIAS_BATCH_SIZE );

		return $total;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function insert_alias_rows( string $table, array $rows ): int {
		if ( array() === $rows ) {
			return 0;
		}
		$columns = array( 'location_id', 'alias', 'alias_normalized', 'source', 'created_at' );
		$sql = sprintf(
			'INSERT IGNORE INTO %s (%s) VALUES %s',
			$table,
			implode( ', ', $columns ),
			implode( ', ', array_fill( 0, count( $rows ), '(%d, %s, %s, %s, %s)' ) )
		);
		$args = array();
		foreach ( $rows as $row ) {
			foreach ( $columns as $column ) {
				$args[] = $row[ $column ] ?? '';
			}
		}
		$this->query_or_fail( $this->wpdb->prepare( $sql, ...$args ), 'Unable to insert candidate aliases.' );
		return count( $rows );
	}

	/**
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function diff_samples( string $stage, string $current ): array {
		return array(
			'new' => $this->sample_rows( $this->new_samples_sql( $stage, $current ) ),
			'removed' => $this->sample_rows( $this->removed_samples_sql( $stage, $current ) ),
			'changed' => $this->sample_rows( $this->changed_samples_sql( $stage, $current ) ),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function sample_rows( string $sql ): array {
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private function new_count( string $stage, string $current ): int {
		return $this->count_rows( "SELECT COUNT(*) FROM {$stage} s WHERE {$this->has_fias_condition( 's' )} AND NOT EXISTS (SELECT 1 FROM {$current} c WHERE c.fias_id = s.fias_id)" )
			+ $this->count_rows( "SELECT COUNT(*) FROM {$stage} s WHERE {$this->empty_fias_condition( 's' )} AND s.gar_object_id > 0 AND NOT EXISTS (SELECT 1 FROM {$current} c WHERE {$this->empty_fias_condition( 'c' )} AND c.gar_object_id = s.gar_object_id)" );
	}

	private function removed_count( string $stage, string $current ): int {
		return $this->count_rows( "SELECT COUNT(*) FROM {$current} c WHERE {$this->has_fias_condition( 'c' )} AND NOT EXISTS (SELECT 1 FROM {$stage} s WHERE s.fias_id = c.fias_id)" )
			+ $this->count_rows( "SELECT COUNT(*) FROM {$current} c WHERE {$this->empty_fias_condition( 'c' )} AND c.gar_object_id > 0 AND NOT EXISTS (SELECT 1 FROM {$stage} s WHERE {$this->empty_fias_condition( 's' )} AND s.gar_object_id = c.gar_object_id)" );
	}

	private function changed_count( string $stage, string $current ): int {
		return $this->count_rows( "SELECT COUNT(*) FROM {$stage} s INNER JOIN {$current} c ON c.fias_id = s.fias_id WHERE {$this->has_fias_condition( 's' )} AND {$this->changed_condition( 's', 'c' )}" )
			+ $this->count_rows( "SELECT COUNT(*) FROM {$stage} s INNER JOIN {$current} c ON c.gar_object_id = s.gar_object_id WHERE {$this->empty_fias_condition( 's' )} AND {$this->empty_fias_condition( 'c' )} AND s.gar_object_id > 0 AND {$this->changed_condition( 's', 'c' )}" );
	}

	private function new_samples_sql( string $stage, string $current ): string {
		return "SELECT CONCAT('f:', s.fias_id) AS `key`, s.fias_id, s.gar_object_id, s.display_name, s.postal_code FROM {$stage} s WHERE {$this->has_fias_condition( 's' )} AND NOT EXISTS (SELECT 1 FROM {$current} c WHERE c.fias_id = s.fias_id)
			UNION ALL
			SELECT CONCAT('g:', s.gar_object_id) AS `key`, s.fias_id, s.gar_object_id, s.display_name, s.postal_code FROM {$stage} s WHERE {$this->empty_fias_condition( 's' )} AND s.gar_object_id > 0 AND NOT EXISTS (SELECT 1 FROM {$current} c WHERE {$this->empty_fias_condition( 'c' )} AND c.gar_object_id = s.gar_object_id)
			LIMIT 100";
	}

	private function removed_samples_sql( string $stage, string $current ): string {
		return "SELECT CONCAT('f:', c.fias_id) AS `key`, c.fias_id, c.gar_object_id, c.display_name, c.postal_code FROM {$current} c WHERE {$this->has_fias_condition( 'c' )} AND NOT EXISTS (SELECT 1 FROM {$stage} s WHERE s.fias_id = c.fias_id)
			UNION ALL
			SELECT CONCAT('g:', c.gar_object_id) AS `key`, c.fias_id, c.gar_object_id, c.display_name, c.postal_code FROM {$current} c WHERE {$this->empty_fias_condition( 'c' )} AND c.gar_object_id > 0 AND NOT EXISTS (SELECT 1 FROM {$stage} s WHERE {$this->empty_fias_condition( 's' )} AND s.gar_object_id = c.gar_object_id)
			LIMIT 100";
	}

	private function changed_samples_sql( string $stage, string $current ): string {
		return "SELECT CONCAT('f:', s.fias_id) AS `key`, s.fias_id, s.gar_object_id, c.display_name AS old_display_name, s.display_name AS new_display_name, c.postal_code AS old_postal_code, s.postal_code AS new_postal_code FROM {$stage} s INNER JOIN {$current} c ON c.fias_id = s.fias_id WHERE {$this->has_fias_condition( 's' )} AND {$this->changed_condition( 's', 'c' )}
			UNION ALL
			SELECT CONCAT('g:', s.gar_object_id) AS `key`, s.fias_id, s.gar_object_id, c.display_name AS old_display_name, s.display_name AS new_display_name, c.postal_code AS old_postal_code, s.postal_code AS new_postal_code FROM {$stage} s INNER JOIN {$current} c ON c.gar_object_id = s.gar_object_id WHERE {$this->empty_fias_condition( 's' )} AND {$this->empty_fias_condition( 'c' )} AND s.gar_object_id > 0 AND {$this->changed_condition( 's', 'c' )}
			LIMIT 100";
	}

	private function has_fias_condition( string $alias ): string {
		return "{$alias}.fias_id IS NOT NULL AND {$alias}.fias_id != ''";
	}

	private function empty_fias_condition( string $alias ): string {
		return "({$alias}.fias_id IS NULL OR {$alias}.fias_id = '')";
	}

	private function changed_condition( string $stage_alias, string $current_alias ): string {
		$parts = array();
		foreach ( $this->diff_fields as $field ) {
			$parts[] = "COALESCE(CAST({$stage_alias}.{$field} AS CHAR), '') != COALESCE(CAST({$current_alias}.{$field} AS CHAR), '')";
		}

		return '(' . implode( ' OR ', $parts ) . ')';
	}

	/**
	 * @param array<int,string> $keys
	 */
	private function key_in_condition( string $alias, array $keys ): string {
		$parsed = $this->parse_keys( $keys );
		$fias = $parsed['fias'];
		$gar = $parsed['gar'];
		$prefix = '' !== $alias ? $alias . '.' : '';
		$parts = array();
		if ( array() !== $fias ) {
			$parts[] = $prefix . 'fias_id IN (' . implode( ', ', array_fill( 0, count( $fias ), '%s' ) ) . ')';
		}
		if ( array() !== $gar ) {
			$parts[] = '(' . $prefix . 'fias_id = \'\' AND ' . $prefix . 'gar_object_id IN (' . implode( ', ', array_fill( 0, count( $gar ), '%d' ) ) . '))';
		}
		if ( array() === $parts ) {
			return '1 = 0';
		}

		return $this->wpdb->prepare( '(' . implode( ' OR ', $parts ) . ')', ...array_merge( $fias, $gar ) );
	}

	/**
	 * @param array<int,string> $keys
	 * @return array{fias:array<int,string>,gar:array<int,int>}
	 */
	private function parse_keys( array $keys ): array {
		$fias = array();
		$gar = array();
		foreach ( $keys as $key ) {
			if ( str_starts_with( $key, 'f:' ) ) {
				$fias[] = substr( $key, 2 );
			} elseif ( str_starts_with( $key, 'g:' ) ) {
				$gar[] = (int) substr( $key, 2 );
			}
		}

		return array( 'fias' => array_values( array_unique( $fias ) ), 'gar' => array_values( array_unique( array_filter( $gar ) ) ) );
	}

	/**
	 * @param array<int,string> $fias_ids
	 */
	private function fias_in_condition( string $alias, array $fias_ids ): string {
		if ( array() === $fias_ids ) {
			return '1 = 0';
		}

		return $this->wpdb->prepare( "{$alias}.fias_id IN (" . implode( ', ', array_fill( 0, count( $fias_ids ), '%s' ) ) . ')', ...$fias_ids );
	}

	/**
	 * @param array<int,int> $gar_ids
	 */
	private function gar_in_condition( string $alias, array $gar_ids ): string {
		if ( array() === $gar_ids ) {
			return '1 = 0';
		}

		return $this->wpdb->prepare( "{$alias}.gar_object_id IN (" . implode( ', ', array_fill( 0, count( $gar_ids ), '%d' ) ) . ')', ...$gar_ids );
	}

	/**
	 * @param array<int,string> $keys
	 * @return array<int,string>
	 */
	private function sanitize_keys( array $keys ): array {
		$result = array();
		foreach ( $keys as $key ) {
			$key = trim( (string) $key );
			if ( preg_match( '/^f:[A-Za-z0-9\\-]{1,64}$/', $key ) || preg_match( '/^g:[0-9]{1,20}$/', $key ) ) {
				$result[] = $key;
			}
		}

		return array_values( array_unique( $result ) );
	}

	private function format_for_column( string $column ): string {
		return match ( $column ) {
			'id', 'gar_object_id', 'district_gar_object_id', 'district_level', 'place_level', 'active' => '%d',
			'latitude', 'longitude' => '%f',
			default => '%s',
		};
	}

	private function count_table( string $table ): int {
		return $this->count_rows( "SELECT COUNT(*) FROM {$table}" );
	}

	private function count_rows( string $sql ): int {
		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * @return array<int,string>
	 */
	private function show_tables_like( string $pattern ): array {
		$prepared = $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $pattern );
		if ( method_exists( $this->wpdb, 'get_col' ) ) {
			$rows = $this->wpdb->get_col( $prepared );
			return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
		}
		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
		$tables = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$value = reset( $row );
				if ( false !== $value ) {
					$tables[] = (string) $value;
				}
			}
		}

		return $tables;
	}

	/**
	 * @return array{tables:array<int,array{table:string,type:string,rows_count:int,created_hint:string,safe_to_drop:bool}>,found:int,skipped:array<int,string>}
	 */
	private function discover_temporary_tables( bool $with_counts ): array {
		$tables = array();
		$skipped = array();
		$found = 0;
		foreach ( $this->temporary_table_patterns() as $pattern ) {
			foreach ( $this->show_tables_like( $pattern ) as $table ) {
				++$found;
				$table = (string) $table;
				$type = $this->temporary_table_type( $table );
				if ( '' === $type ) {
					$skipped[] = $table;
					continue;
				}
				$tables[ $table ] = array(
					'table' => $table,
					'type' => $type,
					'rows_count' => $with_counts ? $this->count_table( $table ) : -1,
					'created_hint' => $this->created_hint_from_table( $table ),
					'safe_to_drop' => true,
				);
			}
		}
		ksort( $tables );

		return array( 'tables' => array_values( $tables ), 'found' => $found, 'skipped' => array_values( array_unique( $skipped ) ) );
	}

	/**
	 * @return array<string,string>
	 */
	private function temporary_table_patterns(): array {
		$prefix = $this->wpdb->prefix;
		return array(
			'staging' => $prefix . 'wdc_locations_update_staging_%',
			'candidate' => $prefix . 'wdc_locations_candidate_%',
			'candidate_alias' => $prefix . 'wdc_location_aliases_candidate_%',
		);
	}

	private function temporary_table_type( string $table ): string {
		$prefix = preg_quote( $this->wpdb->prefix, '/' );
		$patterns = array(
			'staging' => '/^' . $prefix . 'wdc_locations_update_staging_[a-z0-9]{8,40}$/',
			'candidate' => '/^' . $prefix . 'wdc_locations_candidate_[a-z0-9]{8,40}$/',
			'candidate_alias' => '/^' . $prefix . 'wdc_location_aliases_candidate_[a-z0-9]{8,40}$/',
		);
		foreach ( $patterns as $type => $pattern ) {
			if ( preg_match( $pattern, $table ) ) {
				return $type;
			}
		}

		return '';
	}

	private function created_hint_from_table( string $table ): string {
		if ( preg_match( '/_([a-z0-9]{8,40})$/', $table, $match ) ) {
			return $match[1];
		}

		return '';
	}

	private function ensure_table( string $table ): void {
		$result = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( in_array( $result, array( null, '', 0, '0' ), true ) ) {
			throw new RuntimeException( sprintf( 'Table does not exist: %s', $table ) );
		}
	}

	private function query_or_fail( mixed $query, string $message ): void {
		$result = $this->wpdb->query( $query );
		if ( false === $result ) {
			$error = trim( (string) ( $this->wpdb->last_error ?? '' ) );
			throw new RuntimeException( trim( $message . ' ' . ( '' !== $error ? $error : 'Unknown SQL error.' ) ) );
		}
	}

	private function table_name( string $table ): string {
		$table = preg_replace( '/[^A-Za-z0-9_]/', '', $table ) ?? '';
		if ( '' === $table ) {
			throw new RuntimeException( 'Working table name is missing.' );
		}

		return $table;
	}

	private function token( string $seed ): string {
		$token = strtolower( preg_replace( '/[^a-z0-9]/i', '', $seed ) ?? '' );
		if ( '' === $token ) {
			$token = sha1( microtime( true ) . '|' . random_int( 1, PHP_INT_MAX ) );
		}

		return substr( $token, 0, 12 );
	}

	private function staging_table( string $token ): string {
		return $this->wpdb->prefix . 'wdc_locations_update_staging_' . $token;
	}

	private function candidate_table( string $token ): string {
		return $this->wpdb->prefix . 'wdc_locations_candidate_' . $token;
	}

	private function candidate_alias_table( string $token ): string {
		return $this->wpdb->prefix . 'wdc_location_aliases_candidate_' . $token;
	}

	private function previous_table( string $token ): string {
		return $this->wpdb->prefix . 'wdc_locations_previous_' . $token;
	}

	private function previous_alias_table( string $token ): string {
		return $this->wpdb->prefix . 'wdc_location_aliases_previous_' . $token;
	}

	private function locations_table(): string {
		return $this->wpdb->prefix . 'wdc_locations';
	}

	private function aliases_table(): string {
		return $this->wpdb->prefix . 'wdc_location_aliases';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function update_option( string $key, mixed $value ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( $key, $value, false );
		}
	}

	private function delete_option( string $key ): bool {
		return function_exists( 'delete_option' ) ? delete_option( $key ) : false;
	}

	private function is_memory_db(): bool {
		return property_exists( $this->wpdb, 'wdc_incremental_tables' );
	}

	/**
	 * @return array<int,array{table:string,type:string,rows_count:int,created_hint:string,safe_to_drop:bool}>
	 */
	private function memory_list_temporary_tables(): array {
		$tables = array();
		foreach ( array_keys( $this->wpdb->wdc_incremental_tables ) as $table ) {
			$type = $this->temporary_table_type( (string) $table );
			if ( '' === $type ) {
				continue;
			}
			$tables[] = array(
				'table' => (string) $table,
				'type' => $type,
				'rows_count' => count( $this->wpdb->wdc_incremental_tables[ $table ] ?? array() ),
				'created_hint' => $this->created_hint_from_table( (string) $table ),
				'safe_to_drop' => true,
			);
		}
		usort( $tables, static fn( array $a, array $b ): int => strcmp( $a['table'], $b['table'] ) );

		return $tables;
	}

	/**
	 * @return array{dropped:array<int,string>,skipped:array<int,string>,errors:array<int,string>,active_job_cleared:bool}
	 */
	private function memory_cleanup_temporary_tables(): array {
		$started = microtime( true );
		$result = array( 'dropped' => array(), 'skipped' => array(), 'errors' => array(), 'active_job_cleared' => false, 'debug' => array( 'found' => 0, 'whitelisted' => 0, 'dropped' => 0, 'skipped' => 0, 'elapsed_ms' => 0 ) );
		foreach ( array_keys( $this->wpdb->wdc_incremental_tables ) as $table ) {
			if ( '' === $this->temporary_table_type( (string) $table ) ) {
				if ( str_contains( (string) $table, 'wdc_locations_' ) || str_contains( (string) $table, 'wdc_location_aliases_' ) ) {
					$result['skipped'][] = (string) $table;
				}
				continue;
			}
			++$result['debug']['found'];
			++$result['debug']['whitelisted'];
			unset( $this->wpdb->wdc_incremental_tables[ $table ] );
			$result['dropped'][] = (string) $table;
		}
		$result['active_job_cleared'] = $this->delete_option( self::ACTIVE_JOB_OPTION );
		$result['debug']['dropped'] = count( $result['dropped'] );
		$result['debug']['skipped'] = count( $result['skipped'] );
		$result['debug']['elapsed_ms'] = (int) round( ( microtime( true ) - $started ) * 1000 );

		return $result;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function memory_step_staging_job( array $job ): array {
		$path = (string) ( $job['source_path'] ?? '' );
		$token = (string) ( $job['job_id'] ?? '' );
		$stage = (string) ( $job['staging_table'] ?? $this->staging_table( $token ) );
		$this->wpdb->wdc_incremental_tables[ $stage ] = array();

		$file = new SplFileObject( $path, 'rb' );
		$file->setFlags( SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY );
		$file->setCsvControl( ';', '"', '\\' );
		$header = null;
		foreach ( $file as $row ) {
			if ( is_array( $row ) && array( null ) !== $row ) {
				$header = $this->header_map( $row );
				break;
			}
		}
		if ( null === $header ) {
			throw new RuntimeException( 'GAR CSV header row is missing.' );
		}

		foreach ( $this->required_columns as $required ) {
			if ( ! array_key_exists( $required, $header ) ) {
				throw new RuntimeException( sprintf( 'GAR CSV missing required column: %s', $required ) );
			}
		}

		while ( ! $file->eof() ) {
			$row = $file->fgetcsv();
			if ( ! is_array( $row ) || array( null ) === $row ) {
				continue;
			}
			++$job['rows_read'];
			$mapped = $this->map_csv_row_to_location_row( $row, $header );
			if ( null === $mapped ) {
				++$job['unsupported_rows'];
				continue;
			}
			$mapped['id'] = count( $this->wpdb->wdc_incremental_tables[ $stage ] ) + 1;
			$this->wpdb->wdc_incremental_tables[ $stage ][ $mapped['id'] ] = $mapped;
			++$job['stage_rows'];
		}

		$job['phase'] = 'diff';
		$job['updated_at'] = $this->now();
		return $job;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function memory_build_diff( array $job ): array {
		$current = $this->wpdb->wdc_incremental_tables[ $this->locations_table() ] ?? array();
		$stage = $this->wpdb->wdc_incremental_tables[ (string) $job['staging_table'] ] ?? array();
		$diff = $this->memory_diff( $current, $stage );
		$job['current_count'] = count( $current );
		$job['staging_count'] = count( $stage );
		$job['new_count'] = count( $diff['new'] );
		$job['removed_count'] = count( $diff['removed'] );
		$job['changed_count'] = count( $diff['changed'] );
		$job['samples'] = $diff;
		$job['phase'] = 'analysis';
		return $job;
	}

	/**
	 * @param array<int,array<string,mixed>> $current
	 * @param array<int,array<string,mixed>> $stage
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function memory_diff( array $current, array $stage ): array {
		$current_by_key = $this->memory_index_by_key( $current );
		$stage_by_key = $this->memory_index_by_key( $stage );
		$new = array();
		$removed = array();
		$changed = array();

		foreach ( $stage_by_key as $key => $row ) {
			if ( ! isset( $current_by_key[ $key ] ) ) {
				$new[] = $row + array( 'key' => $key );
				continue;
			}
			$changes = $this->memory_changed_fields( $current_by_key[ $key ], $row );
			if ( array() !== $changes ) {
				$changed[] = $row + array( 'key' => $key, 'changes' => $changes, 'old' => $current_by_key[ $key ] );
			}
		}
		foreach ( $current_by_key as $key => $row ) {
			if ( ! isset( $stage_by_key[ $key ] ) ) {
				$removed[] = $row + array( 'key' => $key );
			}
		}

		return array( 'new' => array_values( $new ), 'removed' => array_values( $removed ), 'changed' => array_values( $changed ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,array<string,mixed>>
	 */
	private function memory_index_by_key( array $rows ): array {
		$result = array();
		foreach ( $rows as $row ) {
			$key = $this->memory_key( $row );
			if ( '' !== $key ) {
				$result[ $key ] = $row;
			}
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function memory_key( array $row ): string {
		$fias = trim( (string) ( $row['fias_id'] ?? '' ) );
		if ( '' !== $fias ) {
			return 'f:' . $fias;
		}
		$gar = (int) ( $row['gar_object_id'] ?? $row['gar_id'] ?? 0 );
		return $gar > 0 ? 'g:' . $gar : '';
	}

	/**
	 * @param array<string,mixed> $old
	 * @param array<string,mixed> $new
	 * @return array<string,array{old:string,new:string}>
	 */
	private function memory_changed_fields( array $old, array $new ): array {
		$changes = array();
		foreach ( $this->diff_fields as $field ) {
			if ( (string) ( $old[ $field ] ?? '' ) !== (string) ( $new[ $field ] ?? '' ) ) {
				$changes[ $field ] = array( 'old' => (string) ( $old[ $field ] ?? '' ), 'new' => (string) ( $new[ $field ] ?? '' ) );
			}
		}

		return $changes;
	}

	/**
	 * @param array<string,mixed> $job
	 * @param array<string,array<int,string>> $selected
	 * @return array<string,mixed>
	 */
	private function memory_prepare_candidate( array $job, array $selected ): array {
		$current_table = $this->locations_table();
		$current = $this->wpdb->wdc_incremental_tables[ $current_table ] ?? array();
		$stage = $this->wpdb->wdc_incremental_tables[ (string) $job['staging_table'] ] ?? array();
		$candidate_table = (string) $job['candidate_table'];
		$aliases_table = (string) $job['candidate_alias_table'];
		$candidate = $current;
		$candidate_by_key = $this->memory_index_by_key( $candidate );
		$stage_by_key = $this->memory_index_by_key( $stage );

		foreach ( $this->sanitize_keys( $selected['new'] ?? array() ) as $key ) {
			if ( isset( $stage_by_key[ $key ] ) && ! isset( $candidate_by_key[ $key ] ) ) {
				$row = $stage_by_key[ $key ];
				$row['id'] = $this->memory_next_id( $candidate );
				$candidate[ (int) $row['id'] ] = $row;
				$candidate_by_key[ $key ] = $row;
			}
		}
		foreach ( $this->sanitize_keys( $selected['removed'] ?? array() ) as $key ) {
			foreach ( $candidate as $id => $row ) {
				if ( $this->memory_key( $row ) === $key ) {
					unset( $candidate[ $id ] );
				}
			}
		}
		foreach ( $this->sanitize_keys( $selected['changed'] ?? array() ) as $key ) {
			foreach ( $candidate as $id => $row ) {
				if ( $this->memory_key( $row ) === $key && isset( $stage_by_key[ $key ] ) ) {
					$next = array_merge( $row, $stage_by_key[ $key ] );
					$next['id'] = $id;
					$next['created_at'] = $row['created_at'] ?? $next['created_at'];
					$next['latitude'] = $row['latitude'] ?? null;
					$next['longitude'] = $row['longitude'] ?? null;
					$candidate[ $id ] = $next;
				}
			}
		}

		$this->wpdb->wdc_incremental_tables[ $candidate_table ] = $candidate;
		$validation = $this->memory_validate_candidate( $candidate, count( $current ) );
		$this->wpdb->wdc_incremental_tables[ $aliases_table ] = $this->memory_aliases_for( $candidate );
		$job['validation'] = $validation;
		$job['candidate_count'] = count( $candidate );
		$job['candidate_aliases'] = count( $this->wpdb->wdc_incremental_tables[ $aliases_table ] );
		$job['phase'] = empty( $validation['errors'] ) ? 'candidate_ready' : 'candidate_failed';
		return $job;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function memory_next_id( array $rows ): int {
		return 1 + max( array_merge( array( 0 ), array_map( 'intval', array_keys( $rows ) ) ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $candidate
	 * @return array<string,mixed>
	 */
	private function memory_validate_candidate( array $candidate, int $current_count ): array {
		$errors = array();
		$count = count( $candidate );
		if ( $count <= 0 ) {
			$errors[] = 'Candidate locations table is empty.';
		}
		$fias_seen = array();
		$gar_seen = array();
		$has_ru = false;
		foreach ( $candidate as $row ) {
			$fias = trim( (string) ( $row['fias_id'] ?? '' ) );
			if ( '' === $fias ) {
				$errors[] = 'Candidate contains rows with empty fias_id.';
			} elseif ( isset( $fias_seen[ $fias ] ) ) {
				$errors[] = 'Candidate contains duplicate fias_id values.';
			}
			$fias_seen[ $fias ] = true;
			if ( 1 === (int) ( $row['active'] ?? 1 ) ) {
				if ( 'RU' === (string) ( $row['country_code'] ?? '' ) ) {
					$has_ru = true;
				}
				if ( '' === trim( (string) ( $row['display_name'] ?? '' ) ) ) {
					$errors[] = 'Candidate contains active rows with empty display_name.';
				}
				$gar = (int) ( $row['gar_object_id'] ?? 0 );
				if ( $gar > 0 && isset( $gar_seen[ $gar ] ) ) {
					$errors[] = 'Candidate contains duplicate active gar_id values.';
				}
				$gar_seen[ $gar ] = true;
			}
		}
		if ( ! $has_ru ) {
			$errors[] = 'Candidate does not contain active RU locations.';
		}
		if ( $current_count > 0 && abs( $count - $current_count ) / $current_count >= self::MAX_COUNT_DELTA_RATIO ) {
			$errors[] = 'Candidate row count differs from current table by 20% or more.';
		}

		return array( 'passed' => array() === $errors, 'errors' => array_values( array_unique( $errors ) ), 'current_count' => $current_count, 'candidate_count' => $count );
	}

	/**
	 * @param array<int,array<string,mixed>> $locations
	 * @return array<int,array<string,mixed>>
	 */
	private function memory_aliases_for( array $locations ): array {
		$aliases = array();
		$id = 0;
		foreach ( $locations as $row ) {
			$location = Location::from_array( $row );
			foreach ( $this->alias_generator->generate( $location ) as $alias ) {
				++$id;
				$aliases[ $id ] = array(
					'id' => $id,
					'location_id' => (int) $row['id'],
					'alias' => $alias,
					'alias_normalized' => Location::normalize_search_text( $alias ),
					'source' => 'gar_import',
					'created_at' => $this->now(),
				);
			}
		}

		return $aliases;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function memory_apply_candidate( array $job ): array {
		$current = $this->locations_table();
		$aliases = $this->aliases_table();
		$previous = (string) $job['previous_table'];
		$previous_aliases = (string) $job['previous_alias_table'];
		$candidate = (string) $job['candidate_table'];
		$alias_candidate = (string) $job['candidate_alias_table'];

		$this->wpdb->wdc_incremental_tables[ $previous ] = $this->wpdb->wdc_incremental_tables[ $current ] ?? array();
		$this->wpdb->wdc_incremental_tables[ $previous_aliases ] = $this->wpdb->wdc_incremental_tables[ $aliases ] ?? array();
		$this->wpdb->wdc_incremental_tables[ $current ] = $this->wpdb->wdc_incremental_tables[ $candidate ] ?? array();
		$this->wpdb->wdc_incremental_tables[ $aliases ] = $this->wpdb->wdc_incremental_tables[ $alias_candidate ] ?? array();
		$job['phase'] = 'applied';
		$job['applied_at'] = $this->now();
		return $job;
	}
}
