<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Import;

use RuntimeException;
use SplFileObject;
use WallsShop\WDC\Locations\Services\LocationCountryIndexService;
use WallsShop\WDC\Locations\Services\LocationDisplayNameFormatter;
use WallsShop\WDC\Checkout\Cache\DeliveryQuoteCacheManager;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class LocationIncrementalUpdateService {
	private const ACTIVE_JOB_OPTION = 'wdc_locations_incremental_update_job';
	private const CSV_BATCH_SIZE = 1000;
	private const SAMPLE_LIMIT = 100;
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
		'region_name',
		'region_code',
		'region_type',
		'district_name', 'district_type', 'district_fias_id', 'district_kladr_id', 'district_gar_object_id', 'district_level',
		'city_name',
		'city_type',
		'city_fias_id', 'city_kladr_id',
		'settlement_name',
		'settlement_type',
		'place_name', 'place_type', 'place_level', 'kladr_id', 'okato', 'oktmo',
		'active',
	);

	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null, private ?LocationIncrementalCandidateEnricher $enricher = null, private ?DeliveryQuoteCacheManager $delivery_cache = null ) {
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
			'changed_by_field'       => array(),
			'candidate_count'        => 0,
			'staging_table'          => $this->staging_table( $token ),
			'candidate_table'        => $this->candidate_table( $token ),
			'previous_table'         => $this->previous_table( $token ),
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
			$job = $this->advance_workflow( $job );
		} catch ( \Throwable $exception ) {
			$job['failed_stage'] = $job['phase'] ?? '';
			$job['phase'] = 'failed';
			$job['errors'][] = $exception->getMessage();
		}

		$job['updated_at'] = $this->now();
		return $this->progress( $job );
	}

	private function advance_workflow( array $job ): array {
		unset( $job['changed_by_field']['postal_code'] );
		$phase = (string) ( $job['phase'] ?? '' );
		if ( 'staging' === $phase ) {
			return $this->step_staging_job( $job );
		}
		if ( 'diff' === $phase ) {
			$index = (int) ( $job['diff_cursor'] ?? 0 );
			$stage = $this->table_name( $job['staging_table'] );
			$current = $this->locations_table();
			$counts = array( 'current_count', 'staging_count', 'new_count', 'removed_count', 'changed_count' );
			if ( $this->is_memory_db() ) {
				$all = $this->memory_build_diff( $job );
				if ( $index < 5 ) { $job[$counts[$index]] = $all[$counts[$index]]; }
				else { $field = $this->diff_fields[$index - 5]; $job['changed_by_field'][$field] = $all['changed_by_field'][$field] ?? 0; }
			} elseif ( $index < 5 ) {
				$job[$counts[$index]] = match ( $index ) { 0 => $this->count_table( $current ), 1 => $this->count_table( $stage ), 2 => $this->new_count( $stage, $current ), 3 => $this->removed_count( $stage, $current ), default => $this->changed_count( $stage, $current ) };
			} else {
				$field = $this->diff_fields[$index - 5];
				$condition = $this->field_changed_condition( $field, 's', 'c' );
				$job['changed_by_field'][$field] = $this->count_rows( "SELECT COUNT(*) FROM {$stage} s INNER JOIN {$current} c ON c.country_code = 'RU' AND c.fias_id = s.fias_id WHERE c.country_code = 'RU' AND {$this->has_fias_condition('s')} AND {$condition}" )
					+ $this->count_rows( "SELECT COUNT(*) FROM {$stage} s INNER JOIN {$current} c ON c.gar_object_id = s.gar_object_id WHERE {$this->empty_fias_condition('s')} AND {$this->empty_fias_condition('c')} AND s.gar_object_id > 0 AND {$condition}" );
			}
			$job['diff_cursor'] = $index + 1;
			if ( $job['diff_cursor'] < 5 + count( $this->diff_fields ) ) { return $job; }
			$job['new_total'] = $job['new_count'];
			$job['cursor'] = 0;
			$job['phase'] = 'candidate_seed';
			return $job;
		}
		if ( in_array( $phase, array( 'finished', 'failed', 'canceled', 'waiting_dadata_limit', 'waiting_cache_clear' ), true ) ) {
			return $job;
		}
		$candidate = $this->table_name( (string) $job['candidate_table'] );
		$cursor = (int) ( $job['cursor'] ?? 0 );
		if ( 'candidate_seed' === $phase ) {
			if ( empty( $job['seed_initialized'] ) ) {
				$this->create_working_table( $candidate, $this->locations_table() );
				$job['seed_initialized'] = true;
			}
			$rows = $this->rows_after( $this->locations_table(), $cursor, self::CSV_BATCH_SIZE );
			if ( array() !== $rows ) {
				$end = (int) end( $rows )['id'];
				if ( $this->is_memory_db() ) {
					$this->wpdb->wdc_incremental_tables[$candidate] = array_merge( $this->wpdb->wdc_incremental_tables[$candidate], $rows );
				} else {
					$this->query_or_fail( "INSERT INTO {$candidate} SELECT source.* FROM {$this->locations_table()} source WHERE source.id > {$cursor} AND source.id <= {$end} AND NOT EXISTS (SELECT 1 FROM {$candidate} seeded WHERE seeded.id = source.id)", 'Candidate seed failed.' );
				}
				$job['cursor'] = $end;
				$job['seed_processed'] = (int) ( $job['seed_processed'] ?? 0 ) + count( $rows );
			}
			if ( count( $rows ) < self::CSV_BATCH_SIZE ) {
				$job['phase'] = 'candidate_changes'; $job['change_type'] = 'removed'; $job['cursor'] = 0;
			}
			return $job;
		}
		if ( 'candidate_changes' === $phase ) {
			$type = (string) $job['change_type'];
			$rows = $this->workflow_diff_rows( $job, $type, $cursor, 100 );
			$keys = array_column( $rows, 'key' );
			if ( $this->is_memory_db() ) {
				$this->apply_memory_batch( $job, $type, $keys );
			} elseif ( 'removed' === $type ) {
				$this->apply_removed_rows( $candidate, $keys );
			} elseif ( 'new' === $type ) {
				$this->apply_new_rows( $job['staging_table'], $candidate, $keys );
			} else {
				$this->apply_changed_rows( $job['staging_table'], $candidate, $keys );
			}
			$job['cursor'] += count( $rows );
			if ( count( $rows ) < 100 ) {
				$job['cursor'] = 0;
				$job['change_type'] = 'removed' === $type ? 'new' : 'changed';
				if ( 'changed' === $type ) { $job['phase'] = 'candidate_derived'; $job['change_type'] = 'new'; }
			}
			return $job;
		}
		if ( 'candidate_derived' === $phase ) {
			$type = (string) $job['change_type'];
			$rows = $this->workflow_diff_rows( $job, $type, $cursor, 100 );
			$rules = get_option( 'wdc_location_type_display_rules', array() );
			$formatter = LocationDisplayNameFormatter::from_rules( is_array( $rules ) ? $rules : array() );
			foreach ( $rows as $diff ) {
				$row = $this->candidate_row( $candidate, $diff['key'] );
				$row['display_name'] = $formatter->format_location( Location::from_array( $row ) );
				$this->patch_candidate( $candidate, $row, array( 'display_name' => $row['display_name'], 'searchable_text' => Location::from_array( $row )->get_searchable_text(), 'updated_at' => $this->now() ) );
			}
			$job['cursor'] += count( $rows );
			if ( count( $rows ) < 100 ) {
				$job['cursor'] = 0; $job['change_type'] = 'changed';
				if ( 'changed' === $type ) { $job['phase'] = 'enrich_postcodes'; }
			}
			return $job;
		}
		if ( str_starts_with( $phase, 'enrich_' ) ) {
			if ( null === $this->enricher ) { throw new RuntimeException( 'Candidate enrichment service is unavailable.' ); }
			$rows = $this->workflow_diff_rows( $job, 'new', $cursor, 1 );
			if ( array() === $rows ) {
				$job['cursor'] = 0;
				$job['phase'] = match ( $phase ) { 'enrich_postcodes' => 'enrich_coordinates', 'enrich_coordinates' => 'enrich_russianpost_courier', default => 'candidate_validate' };
				return $job;
			}
			$row = $this->candidate_row( $candidate, $rows[0]['key'] );
			$result = $this->enricher->resolve( $phase, $row, $job['enrichment_state'] ?? array() );
			if ( $result['pause'] ) {
				$job['resume_phase'] = $phase; $job['phase'] = 'waiting_dadata_limit';
				return $job;
			}
			$this->patch_candidate( $candidate, $row, $result['patch'] );
			$job['enrichment_state'] = $result['state'];
			if ( $result['done'] ) {
				$prefix = match ( $phase ) { 'enrich_postcodes' => 'postcode', 'enrich_coordinates' => 'coordinates', default => 'russianpost' };
				foreach ( array( 'processed', $result['outcome'] ) as $counter ) {
					$key = $prefix . '_' . $counter; $job[$key] = (int) ( $job[$key] ?? 0 ) + 1;
				}
				$job['cursor']++;
			}
			$job['last_diagnostic'] = $result['message'];
			return $job;
		}
		if ( 'candidate_validate' === $phase ) {
			$job['validation'] = $this->is_memory_db() ? $this->memory_validate_candidate( $this->wpdb->wdc_incremental_tables[$candidate], $job['current_count'] ) : $this->validate_candidate( $candidate, $job['current_count'] );
			$job['candidate_count'] = $job['validation']['candidate_count'];
			if ( $job['candidate_count'] !== $job['current_count'] - $job['removed_count'] + $job['new_count'] ) {
				throw new RuntimeException( 'Candidate row count does not match the source diff.' );
			}
			if ( ! $job['validation']['passed'] ) { throw new RuntimeException( implode( ' ', $job['validation']['errors'] ) ); }
			$job['phase'] = 'ready_to_apply'; $job['cursor'] = 0;
			return $job;
		}
		if ( 'ready_to_apply' === $phase ) { $job['phase'] = 'applying'; return $job; }
		if ( 'applying' === $phase ) {
			if ( null === $this->delivery_cache ) { throw new RuntimeException( 'Delivery cache service is unavailable.' ); }
			// Recover an interrupted response after the atomic rename.
			if ( $this->swap_completed( $job ) ) {
				$job['phase'] = 'applied';
				$job['applied_at'] = $job['applied_at'] ?? $this->now();
			} else {
				$job = $this->apply_candidate( $job );
			}
			if ( 'applied' !== $job['phase'] ) { throw new RuntimeException( 'Candidate apply validation failed.' ); }
			$job['phase'] = 'cache_invalidate';
			$this->update_option( self::ACTIVE_JOB_OPTION, $job );
			return $this->advance_workflow( $job );
		}
		if ( 'cache_invalidate' === $phase ) {
			try {
				if ( empty( $job['country_index_invalidated'] ) ) {
					LocationCountryIndexService::mark_option_stale();
					$job['country_index_invalidated'] = true;
				}
				$this->delivery_cache->clear_all_delivery_cache();
				$job['cache_invalidated'] = true;
				$job['phase'] = 'cleanup';
			} catch ( \Throwable $error ) {
				$this->delivery_cache->bump_delivery_rates_cache_version();
				( new \WallsShop\WDC\Infrastructure\Logging\Logger() )->error( 'Locations applied; cache invalidation requires retry.', array( 'error' => $error->getMessage() ) );
				$job['phase'] = 'waiting_cache_clear';
				$job['resume_phase'] = 'cache_invalidate';
				$job['last_diagnostic'] = 'База применена. Требуется повторить очистку кеша.';
			}
			return $job;
		}
		if ( 'cleanup' === $phase ) {
			$this->cleanup_job( $job ); $job['phase'] = 'finished'; $job['finished_at'] = $this->now(); return $job;
		}
		throw new RuntimeException( 'Unsupported update phase; cancel the old job and start a new update.' );
	}

	public function resume_job( array $job ): array {
		if ( in_array( $job['phase'] ?? '', array( 'waiting_dadata_limit', 'waiting_cache_clear' ), true ) ) { $job['phase'] = $job['resume_phase']; }
		return $this->progress( $job );
	}

	public function cancel_job( array $job ): array {
		if ( ! empty( $job['applied_at'] ) || $this->swap_completed( $job ) ) { throw new RuntimeException( 'The database has already been applied; cancellation is unavailable.' ); }
		$this->cleanup_job( $job ); $job['phase'] = 'canceled'; return $this->progress( $job );
	}

	private function cleanup_job( array $job ): void {
		$token = $this->token( (string) $job['job_id'] );
		foreach ( array( $this->staging_table( $token ), $this->candidate_table( $token ), $this->previous_table( $token ) ) as $table ) {
			if ( $this->is_memory_db() ) { unset( $this->wpdb->wdc_incremental_tables[$table] ); }
			else { $this->query_or_fail( "DROP TABLE IF EXISTS {$table}", 'Unable to clean update tables.' ); }
		}
	}

	private function swap_completed( array $job ): bool {
		return $this->table_exists( $job['previous_table'] )
			&& ! $this->table_exists( $job['candidate_table'] )
			&& $this->table_exists( $this->locations_table() );
	}

	private function table_exists( string $table ): bool {
		if ( $this->is_memory_db() ) { return isset( $this->wpdb->wdc_incremental_tables[$table] ); }
		$name = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $this->wpdb->esc_like( $this->table_name( $table ) ) ) );
		if ( '' !== $this->wpdb->last_error ) { throw new RuntimeException( $this->wpdb->last_error ); }
		return $name === $table;
	}

	private function create_working_table( string $target, string $source ): void {
		if ( $this->is_memory_db() ) { $this->wpdb->wdc_incremental_tables[$target] = array(); }
		else { $this->replace_like_table( $target, $source ); }
	}

	private function rows_after( string $table, int $cursor, int $limit ): array {
		if ( $this->is_memory_db() ) {
			$rows = array_values( array_filter( $this->wpdb->wdc_incremental_tables[$table] ?? array(), fn( array $row ): bool => (int) $row['id'] > $cursor ) );
			usort( $rows, fn( array $a, array $b ): int => $a['id'] <=> $b['id'] );
			return array_slice( $rows, 0, $limit );
		}
		return $this->sample_rows( "SELECT * FROM {$table} WHERE id > {$cursor} ORDER BY id ASC LIMIT {$limit}" );
	}

	private function workflow_diff_rows( array $job, string $type, int $offset, int $limit ): array {
		if ( $this->is_memory_db() ) {
			$diff = $this->memory_diff( $this->wpdb->wdc_incremental_tables[$this->locations_table()], $this->wpdb->wdc_incremental_tables[$job['staging_table']] );
			$rows = $diff[$type]; usort( $rows, fn( array $a, array $b ): int => strcmp( $a['key'], $b['key'] ) );
			return array_slice( $rows, $offset, $limit );
		}
		return match ( $type ) {
			'new' => $this->list_new_rows( $job['staging_table'], $this->locations_table(), $offset, $limit ),
			'removed' => $this->list_removed_rows( $job['staging_table'], $this->locations_table(), $offset, $limit ),
			default => $this->list_changed_rows( $job['staging_table'], $this->locations_table(), $offset, $limit ),
		};
	}

	private function candidate_row( string $table, string $key ): array {
		if ( $this->is_memory_db() ) {
			foreach ( $this->wpdb->wdc_incremental_tables[$table] as $row ) { if ( $this->memory_key( $row ) === $key && 'RU' === $row['country_code'] ) { return $row; } }
		} else {
			$rows = $this->sample_rows( "SELECT * FROM {$table} WHERE country_code = 'RU' AND " . $this->key_in_condition( '', array( $key ) ) . ' LIMIT 1' );
			if ( isset( $rows[0] ) ) { return $rows[0]; }
		}
		throw new RuntimeException( 'Candidate identity is missing.' );
	}

	private function patch_candidate( string $table, array $row, array $patch ): void {
		if ( array() === $patch ) { return; }
		if ( $this->is_memory_db() ) {
			foreach ( $this->wpdb->wdc_incremental_tables[$table] as &$stored ) { if ( $stored['id'] === $row['id'] ) { $stored = array_replace( $stored, $patch ); } }
		} elseif ( false === $this->wpdb->update( $table, $patch, array( 'id' => $row['id'] ) ) ) { throw new RuntimeException( 'Candidate update failed.' ); }
	}

	private function apply_memory_batch( array $job, string $type, array $keys ): void {
		$table = $job['candidate_table'];
		foreach ( $keys as $key ) {
			if ( 'removed' === $type ) {
				$this->wpdb->wdc_incremental_tables[$table] = array_values( array_filter( $this->wpdb->wdc_incremental_tables[$table], fn( array $r ): bool => 'RU' !== $r['country_code'] || $this->memory_key( $r ) !== $key ) );
				continue;
			}
			$source = $this->candidate_row( $job['staging_table'], $key );
			if ( 'new' === $type ) { $source['id'] = $this->memory_next_id( $this->wpdb->wdc_incremental_tables[$table] ); $this->wpdb->wdc_incremental_tables[$table][] = $source; }
			else { $this->patch_candidate( $table, $this->candidate_row( $table, $key ), array_intersect_key( $source, array_flip( $this->diff_fields ) ) ); }
		}
	}

	public function progress( array $job ): array {
		$labels = array(
			'staging' => 'Загрузка нового GAR', 'diff' => 'Анализ изменений',
			'candidate_seed' => 'Подготовка новой базы', 'candidate_changes' => 'Применение изменений',
			'candidate_derived' => 'Подготовка названий', 'enrich_postcodes' => 'Получение почтовых индексов',
			'enrich_coordinates' => 'Получение координат', 'enrich_russianpost_courier' => 'Подбор индексов курьерской Почты',
			'candidate_validate' => 'Проверка новой базы',
			'ready_to_apply' => 'Новая база готова', 'applying' => 'Применение новой базы',
			'cache_invalidate' => 'Очистка кеша доставки', 'cleanup' => 'Очистка временных данных', 'finished' => 'Готово',
		);
		$phase = (string) ( $job['phase'] ?? 'staging' );
		$job['failed_stage_label'] = $labels[$job['failed_stage'] ?? ''] ?? '';
		$job['stage_label'] = $labels[$phase] ?? match ( $phase ) {
			'waiting_dadata_limit' => 'Лимит DaData исчерпан. Обновление приостановлено до продолжения.',
			'waiting_cache_clear' => 'База применена. Требуется повторить очистку кеша.',
			'canceled' => 'Обновление отменено', default => 'Обновление остановлено',
		};
		$counts = match ( $phase ) {
			'staging' => array( $job['rows_read'] ?? 0, $job['rows_total_estimated'] ?? 0 ),
			'diff' => array( $job['diff_cursor'] ?? 0, 5 + count( $this->diff_fields ) ),
			'candidate_seed' => array( $job['seed_processed'] ?? 0, $job['current_count'] ?? 0 ),
			'candidate_changes', 'candidate_derived' => array( $job['cursor'] ?? 0, $job[($job['change_type'] ?? 'new') . '_count'] ?? 0 ),
			'enrich_postcodes', 'enrich_coordinates', 'enrich_russianpost_courier' => array( $job['cursor'] ?? 0, $job['new_count'] ?? 0 ),
			'aliases_build' => array( $job['aliases_processed'] ?? 0, $job['candidate_count'] ?? 0 ),
			default => array( 0, 0 ),
		};
		$job['stage_processed'] = (int) $counts[0];
		$job['stage_total'] = max( $job['stage_processed'], (int) $counts[1] );
		$index = array_search( $phase, array_keys( $labels ), true );
		$fraction = $job['stage_total'] > 0 ? min( 1, $job['stage_processed'] / $job['stage_total'] ) : 0;
		$percent = false === $index ? (int) ( $job['overall_percent'] ?? 0 ) : (int) floor( 100 * ( $index + $fraction ) / count( $labels ) );
		$job['overall_percent'] = 'finished' === $phase ? 100 : max( $percent, (int) ( $job['overall_percent'] ?? 0 ) );
		foreach ( array( 'postcode', 'coordinates', 'russianpost' ) as $prefix ) {
			foreach ( array( 'processed', 'updated', 'skipped', 'no_index', 'errors' ) as $counter ) {
				$job[$prefix . '_' . $counter] = (int) ( $job[$prefix . '_' . $counter] ?? 0 );
			}
		}
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
		$candidate = $this->table_name( (string) ( $job['candidate_table'] ?? '' ) );
		$previous = $this->table_name( (string) ( $job['previous_table'] ?? '' ) );

		$this->ensure_table( $candidate );
		$validation = $this->validate_candidate( $candidate, $this->count_table( $current ) );
		if ( ! empty( $validation['errors'] ) ) {
			$job['validation'] = $validation;
			$job['phase'] = 'candidate_failed';
			return $job;
		}

		$this->query_or_fail(
			"RENAME TABLE {$current} TO {$previous}, {$candidate} TO {$current}",
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
			)
		);

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
		$args = array();
		$values = array();
		foreach ( $rows as $row ) {
			$formats = array();
			foreach ( $columns as $column ) {
				if ( in_array( $column, array( 'latitude', 'longitude' ), true ) && null === ( $row[$column] ?? null ) ) {
					$formats[] = 'NULL';
					continue;
				}
				$formats[] = $this->format_for_column( $column );
				$args[] = $row[ $column ] ?? null;
			}
			$values[] = '(' . implode( ', ', $formats ) . ')';
		}
		$sql = 'INSERT INTO ' . $table . ' (' . implode( ', ', $columns ) . ') VALUES ' . implode( ', ', $values );

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
		$this->query_or_fail( "DELETE FROM {$candidate} WHERE country_code = 'RU' AND {$this->key_in_condition( '', $keys )}", 'Unable to remove selected locations.' );
	}

	private function apply_changed_rows( string $stage, string $candidate, array $keys ): void {
		$keys = $this->sanitize_keys( $keys );
		if ( array() === $keys ) {
			return;
		}
		$assignments = array();
		foreach ( $this->location_columns as $column ) {
			if ( ! in_array( $column, $this->diff_fields, true ) ) {
				continue;
			}
			$assignments[] = "c.{$column} = s.{$column}";
		}
		$parsed = $this->parse_keys( $keys );
		if ( array() !== $parsed['fias'] ) {
			$this->query_or_fail(
				"UPDATE {$candidate} c INNER JOIN {$stage} s ON c.country_code = 'RU' AND c.fias_id = s.fias_id SET " . implode( ', ', $assignments ) . ' WHERE ' . $this->fias_in_condition( 's', $parsed['fias'] ),
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
		if ( $this->count_rows( "SELECT COUNT(*) FROM {$candidate} WHERE country_code = 'RU' AND (fias_id IS NULL OR fias_id = '')" ) > 0 ) {
			$errors[] = 'Candidate contains rows with empty fias_id.';
		}

		return array( 'passed' => array() === $errors, 'errors' => $errors, 'current_count' => $current_count, 'candidate_count' => $count );
	}



	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function sample_rows( string $sql ): array {
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( ! is_array( $rows ) || '' !== $this->wpdb->last_error ) { throw new RuntimeException( 'Unable to read update rows: ' . $this->wpdb->last_error ); }
		return $rows;
	}

	private function new_count( string $stage, string $current ): int {
		return $this->count_rows( "SELECT COUNT(*) FROM {$stage} s WHERE {$this->has_fias_condition( 's' )} AND NOT EXISTS (SELECT 1 FROM {$current} c WHERE c.country_code = 'RU' AND c.fias_id = s.fias_id)" )
			+ $this->count_rows( "SELECT COUNT(*) FROM {$stage} s WHERE {$this->empty_fias_condition( 's' )} AND s.gar_object_id > 0 AND NOT EXISTS (SELECT 1 FROM {$current} c WHERE {$this->empty_fias_condition( 'c' )} AND c.gar_object_id = s.gar_object_id)" );
	}

	private function removed_count( string $stage, string $current ): int {
		return $this->count_rows( "SELECT COUNT(*) FROM {$current} c WHERE {$this->has_fias_condition( 'c' )} AND NOT EXISTS (SELECT 1 FROM {$stage} s WHERE s.fias_id = c.fias_id)" )
			+ $this->count_rows( "SELECT COUNT(*) FROM {$current} c WHERE {$this->empty_fias_condition( 'c' )} AND c.gar_object_id > 0 AND NOT EXISTS (SELECT 1 FROM {$stage} s WHERE {$this->empty_fias_condition( 's' )} AND s.gar_object_id = c.gar_object_id)" );
	}

	private function changed_count( string $stage, string $current ): int {
		return $this->count_rows( "SELECT COUNT(*) FROM {$stage} s INNER JOIN {$current} c ON c.country_code = 'RU' AND c.fias_id = s.fias_id WHERE {$this->has_fias_condition( 's' )} AND {$this->changed_condition( 's', 'c' )}" )
			+ $this->count_rows( "SELECT COUNT(*) FROM {$stage} s INNER JOIN {$current} c ON c.gar_object_id = s.gar_object_id WHERE {$this->empty_fias_condition( 's' )} AND {$this->empty_fias_condition( 'c' )} AND s.gar_object_id > 0 AND {$this->changed_condition( 's', 'c' )}" );
	}


	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function list_new_rows( string $stage, string $current, int $offset, int $limit ): array {
		return $this->sample_rows( $this->paged_sql( $this->new_samples_sql( $stage, $current, 0 ), $offset, $limit ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function list_removed_rows( string $stage, string $current, int $offset, int $limit ): array {
		return $this->sample_rows( $this->paged_sql( $this->removed_samples_sql( $stage, $current, 0 ), $offset, $limit ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function list_changed_rows( string $stage, string $current, int $offset, int $limit ): array {
		return $this->sample_rows( $this->paged_sql( $this->changed_samples_sql( $stage, $current, 0 ), $offset, $limit ) );
	}

	private function paged_sql( string $sql, int $offset, int $limit ): string {
		$offset = max( 0, $offset );
		$limit = max( 1, $limit );
		return $sql . ' ORDER BY `key` ASC LIMIT ' . $offset . ', ' . $limit;
	}

	private function new_samples_sql( string $stage, string $current, int $limit = 100 ): string {
		$sql = "SELECT CONCAT('f:', s.fias_id) AS `key`, s.fias_id, s.gar_object_id, s.display_name, s.postal_code FROM {$stage} s WHERE {$this->has_fias_condition( 's' )} AND NOT EXISTS (SELECT 1 FROM {$current} c WHERE c.country_code = 'RU' AND c.fias_id = s.fias_id)
			UNION ALL
			SELECT CONCAT('g:', s.gar_object_id) AS `key`, s.fias_id, s.gar_object_id, s.display_name, s.postal_code FROM {$stage} s WHERE {$this->empty_fias_condition( 's' )} AND s.gar_object_id > 0 AND NOT EXISTS (SELECT 1 FROM {$current} c WHERE {$this->empty_fias_condition( 'c' )} AND c.gar_object_id = s.gar_object_id)";
		return $limit > 0 ? $sql . ' LIMIT ' . (int) $limit : $sql;
	}

	private function removed_samples_sql( string $stage, string $current, int $limit = 100 ): string {
		$sql = "SELECT CONCAT('f:', c.fias_id) AS `key`, c.fias_id, c.gar_object_id, c.display_name, c.postal_code FROM {$current} c WHERE {$this->has_fias_condition( 'c' )} AND NOT EXISTS (SELECT 1 FROM {$stage} s WHERE s.fias_id = c.fias_id)
			UNION ALL
			SELECT CONCAT('g:', c.gar_object_id) AS `key`, c.fias_id, c.gar_object_id, c.display_name, c.postal_code FROM {$current} c WHERE {$this->empty_fias_condition( 'c' )} AND c.gar_object_id > 0 AND NOT EXISTS (SELECT 1 FROM {$stage} s WHERE {$this->empty_fias_condition( 's' )} AND s.gar_object_id = c.gar_object_id)";
		return $limit > 0 ? $sql . ' LIMIT ' . (int) $limit : $sql;
	}

	private function changed_samples_sql( string $stage, string $current, int $limit = 100 ): string {
		$diff_json = $this->changed_diff_json_object( 's', 'c' );
		$sql = "SELECT CONCAT('f:', s.fias_id) AS `key`, s.fias_id, s.gar_object_id, c.display_name, {$diff_json} AS changes FROM {$stage} s INNER JOIN {$current} c ON c.country_code = 'RU' AND c.fias_id = s.fias_id WHERE {$this->has_fias_condition( 's' )} AND {$this->changed_condition( 's', 'c' )}
			UNION ALL
			SELECT CONCAT('g:', s.gar_object_id) AS `key`, s.fias_id, s.gar_object_id, c.display_name, {$diff_json} AS changes FROM {$stage} s INNER JOIN {$current} c ON c.gar_object_id = s.gar_object_id WHERE {$this->empty_fias_condition( 's' )} AND {$this->empty_fias_condition( 'c' )} AND s.gar_object_id > 0 AND {$this->changed_condition( 's', 'c' )}";
		return $limit > 0 ? $sql . ' LIMIT ' . (int) $limit : $sql;
	}

	private function has_fias_condition( string $alias ): string {
		return "{$alias}.country_code = 'RU' AND {$alias}.fias_id IS NOT NULL AND {$alias}.fias_id != ''";
	}

	private function empty_fias_condition( string $alias ): string {
		return "({$alias}.country_code = 'RU' AND ({$alias}.fias_id IS NULL OR {$alias}.fias_id = ''))";
	}

	private function changed_condition( string $stage_alias, string $current_alias ): string {
		$parts = array();
		foreach ( $this->diff_fields as $field ) {
			$parts[] = $this->field_changed_condition( $field, $stage_alias, $current_alias );
		}

		return '(' . implode( ' OR ', $parts ) . ')';
	}

	private function field_changed_condition( string $field, string $stage_alias, string $current_alias ): string {
		return $this->normalized_sql_value( $stage_alias, $field ) . ' != ' . $this->normalized_sql_value( $current_alias, $field );
	}

	private function normalized_sql_value( string $alias, string $field ): string {
		if ( 'active' === $field ) {
			return "CAST(COALESCE(NULLIF(TRIM(CAST({$alias}.{$field} AS CHAR)), ''), '0') AS UNSIGNED)";
		}
		if ( in_array( $field, array( 'city_type', 'settlement_type' ), true ) ) {
			$value = "LOWER(COALESCE(NULLIF(TRIM(CAST({$alias}.{$field} AS CHAR)), ''), ''))";
			$value = "REPLACE(REPLACE(REPLACE({$value}, '  ', ' '), '  ', ' '), '  ', ' ')";
			return "TRIM(TRAILING '.' FROM {$value})";
		}

		return "COALESCE(NULLIF(TRIM(CAST({$alias}.{$field} AS CHAR)), ''), '')";
	}

	private function changed_diff_json_object( string $stage_alias, string $current_alias ): string {
		$parts = array();
		foreach ( $this->diff_fields as $field ) {
			$parts[] = "'{$field}', IF({$this->field_changed_condition( $field, $stage_alias, $current_alias )}, JSON_OBJECT('old', {$this->normalized_sql_value( $current_alias, $field )}, 'new', {$this->normalized_sql_value( $stage_alias, $field )}), NULL)";
		}

		return 'JSON_OBJECT(' . implode( ', ', $parts ) . ')';
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
			$parts[] = '((' . $prefix . 'fias_id IS NULL OR ' . $prefix . 'fias_id = \'\') AND ' . $prefix . 'gar_object_id IN (' . implode( ', ', array_fill( 0, count( $gar ), '%d' ) ) . '))';
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
		$value = $this->wpdb->get_var( $sql );
		if ( null === $value || '' !== $this->wpdb->last_error ) { throw new RuntimeException( 'Unable to count update rows: ' . $this->wpdb->last_error ); }
		return (int) $value;
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
		);
	}

	private function temporary_table_type( string $table ): string {
		$prefix = preg_quote( $this->wpdb->prefix, '/' );
		$patterns = array(
			'staging' => '/^' . $prefix . 'wdc_locations_update_staging_[a-z0-9]{8,40}$/',
			'candidate' => '/^' . $prefix . 'wdc_locations_candidate_[a-z0-9]{8,40}$/',
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


	private function previous_table( string $token ): string {
		return $this->wpdb->prefix . 'wdc_locations_previous_' . $token;
	}


	private function locations_table(): string {
		return $this->wpdb->prefix . 'wdc_locations';
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
				if ( str_contains( (string) $table, 'wdc_locations_' ) ) {
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
		$job['changed_by_field'] = $this->memory_changed_by_field_counts( $diff['changed'] );
		$job['samples'] = array_map( fn( array $rows ): array => array_slice( $rows, 0, self::SAMPLE_LIMIT ), $diff );
		$job['phase'] = 'analysis';
		return $job;
	}

	/**
	 * @param array<int,array<string,mixed>> $current
	 * @param array<int,array<string,mixed>> $stage
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private function memory_diff( array $current, array $stage ): array {
		$current = array_filter( $current, fn( array $row ): bool => 'RU' === ( $row['country_code'] ?? '' ) );
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
			$old_value = $this->memory_normalized_value( $old, $field );
			$new_value = $this->memory_normalized_value( $new, $field );
			if ( $old_value !== $new_value ) {
				$changes[ $field ] = array( 'old' => $old_value, 'new' => $new_value );
			}
		}

		return $changes;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function memory_normalized_value( array $row, string $field ): string {
		if ( 'active' === $field ) {
			$value = trim( (string) ( $row[ $field ] ?? '0' ) );
			return (string) (int) ( '' === $value ? 0 : $value );
		}
		if ( in_array( $field, array( 'city_type', 'settlement_type' ), true ) ) {
			$value = preg_replace( '/\s+/u', ' ', trim( (string) ( $row[ $field ] ?? '' ) ) ) ?? '';
			$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
			return rtrim( $value, '.' );
		}

		return trim( (string) ( $row[ $field ] ?? '' ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $changed
	 * @return array<string,int>
	 */
	private function memory_changed_by_field_counts( array $changed ): array {
		$result = array_fill_keys( $this->diff_fields, 0 );
		foreach ( $changed as $row ) {
			foreach ( is_array( $row['changes'] ?? null ) ? $row['changes'] : array() as $field => $change ) {
				if ( array_key_exists( (string) $field, $result ) ) {
					++$result[ (string) $field ];
				}
			}
		}

		return $result;
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
			if ( '' === $fias && 'RU' === ( $row['country_code'] ?? '' ) ) {
				$errors[] = 'Candidate contains rows with empty fias_id.';
			} elseif ( '' !== $fias && isset( $fias_seen[ $fias ] ) ) {
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
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function memory_apply_candidate( array $job ): array {
		$current = $this->locations_table();
		$previous = (string) $job['previous_table'];
		$candidate = (string) $job['candidate_table'];

		$this->wpdb->wdc_incremental_tables[ $previous ] = $this->wpdb->wdc_incremental_tables[ $current ] ?? array();
		$this->wpdb->wdc_incremental_tables[ $current ] = $this->wpdb->wdc_incremental_tables[ $candidate ] ?? array();
		unset( $this->wpdb->wdc_incremental_tables[$candidate] );
		$job['phase'] = 'applied';
		$job['applied_at'] = $this->now();
		return $job;
	}
}
