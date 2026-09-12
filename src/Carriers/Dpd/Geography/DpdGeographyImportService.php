<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use Throwable;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\Storage\LocationWriteLock;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyImportService {
	public const WORKER_HOOK = 'wdc_dpd_geography_import_worker';
	private const DEFAULT_STEP_LIMIT = 3000;
	private const MATCH_BATCH_SIZE = 500;
	private const WORKER_STEP_LIMIT = 500;
	private const WORKER_SOFT_TIME_BUDGET_SECONDS = 18.0;
	private const MAX_STEPS_PER_WORKER_SLICE = 10;
	private const MEMORY_BUDGET_FRACTION = 0.8;
	private const ACTION_GROUP = 'walls-delivery-calc';
	private const LOCK_BUSY_RETRY_MS = 1500;
	private const STEP_LOCK_TTL_SECONDS = 600;
	private const START_LOCK_TTL_SECONDS = 1800;
	private const RUNNER_PROTOCOL_VERSION = 1;

	public function __construct(
		private DpdGeographyCsvParser $parser,
		private DpdGeographyMatcher $matcher,
		private DpdGeographyImportStateService $state,
		private DpdGeographyStageRepository $stage,
		private LocationRepository $locations,
		private LocationDeliveryCodeRepository $delivery_codes,
		private ?DpdSettings $settings = null,
		private ?DpdGeographyImportLockService $lock = null,
		private ?LocationWriteLock $locations_write_lock = null,
		private ?ActionScheduler $scheduler = null,
		private mixed $execution_budget_factory = null,
		private mixed $ftp_download = null
	) {
		$this->lock ??= new DpdGeographyImportLockService();
	}

	public function register(): void {
		add_action( self::WORKER_HOOK, array( $this, 'run_background_worker' ), 10, 2 );
		$this->scheduler?->when_initialized(
			'dpd-geography-import-worker',
			function (): void {
				$current = $this->state->current();
				if ( $this->active_phase( (string) ( $current['phase'] ?? '' ) ) ) {
					$this->schedule_worker_once( (string) ( $current['job_id'] ?? '' ), (int) ( $current['byte_offset'] ?? 0 ) );
				}
			}
		);
	}

	/**
	 * Backward-compatible synchronous wrapper for smoke tests and CLI diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public function import_file( string $path, string $source, string $source_file ): array {
		$job = $this->run_locked_start(
			$source,
			fn(): array => $this->start_from_existing_file_unlocked( $path, $source, $source_file, false, false )
		);
		while ( in_array( (string) ( $job['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
			$job = $this->step( (string) $job['job_id'], 10000 );
		}

		return $this->report_from_state( $job );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function start_from_uploaded_file( array $file ): array {
		return $this->run_locked_start(
			'manual',
			function () use ( $file ): array {
				if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
					return $this->fail_with_report( 'DPD geography manual import: CSV upload failed.', true, array( 'source' => 'manual' ) );
				}
				$tmp = (string) ( $file['tmp_name'] ?? '' );
				$name = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( (string) ( $file['name'] ?? 'manual.csv' ) ) : basename( (string) ( $file['name'] ?? 'manual.csv' ) );
				if ( '' === $tmp || ! file_exists( $tmp ) || ! str_ends_with( strtolower( $name ), '.csv' ) ) {
					return $this->fail_with_report( 'DPD geography manual import: upload must be a CSV file.', true, array( 'source' => 'manual', 'source_file' => $name ) );
				}

				try {
					$target = $this->copy_to_import_temp( $tmp, $name );
				} catch ( Throwable ) {
					return $this->fail_with_report(
						'DPD geography manual import: unable to copy uploaded CSV.',
						true,
						array(
							'source' => 'manual',
							'source_file' => $name,
							'file_size' => is_file( $tmp ) ? (int) filesize( $tmp ) : 0,
						)
					);
				}
				@unlink( $tmp );

				return $this->start_from_existing_file_unlocked( $target, 'manual', $name, true );
			}
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function start_from_ftp( DpdGeographyFtpClient $ftp ): array {
		return $this->run_locked_start(
			'ftp',
			function () use ( $ftp ): array {
				$download = is_callable( $this->ftp_download ) ? ( $this->ftp_download )( $ftp ) : $ftp->download_latest();
				if ( 'warning' === (string) ( $download['status'] ?? '' ) ) {
					$current = $this->state->public_state();
					$current['status'] = 'warning';
					$current['last_message'] = (string) $download['message'];
					return $current;
				}
				if ( empty( $download['success'] ) ) {
					return $this->fail_with_report( (string) $download['message'], true, array( 'source' => 'ftp', 'source_file' => (string) ( $download['source_file'] ?? '' ) ) );
				}

				return $this->start_from_existing_file_unlocked( (string) $download['path'], 'ftp', (string) $download['source_file'], true );
			}
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function step( string $job_id = '', int $limit = self::DEFAULT_STEP_LIMIT, ?int $expected_byte_offset = null ): array {
		return $this->with_locations_write_lock( fn(): array => $this->step_with_import_lock( $job_id, $limit, $expected_byte_offset ), true );
	}

	/**
	 * Action Scheduler callback. Each step is independently checkpointed and
	 * owns the shared location-write lock only for that atomic unit.
	 *
	 * @return array<string,mixed>
	 */
	public function run_background_worker( string $job_id = '', int $expected_byte_offset = 0 ): array {
		$initial = $this->state->current();
		if ( $job_id !== (string) ( $initial['job_id'] ?? '' ) || ! $this->active_phase( (string) ( $initial['phase'] ?? '' ) ) ) {
			return $this->state->public_state();
		}
		if ( $expected_byte_offset !== (int) ( $initial['byte_offset'] ?? 0 ) ) {
			return $this->with_step_control( $this->state->public_state(), 'stale' );
		}

		$budget = $this->new_execution_budget();
		$budget->start();
		$stop_reason = '';
		try {
			while ( $budget->can_continue() ) {
				$current = $this->state->current();
				if ( $job_id !== (string) ( $current['job_id'] ?? '' ) ) {
					return $this->state->public_state();
				}
				if ( ! $this->active_phase( (string) ( $current['phase'] ?? '' ) ) ) {
					$stop_reason = $this->terminal_stop_reason( (string) ( $current['phase'] ?? '' ) );
					break;
				}
				$offset = (int) ( $current['byte_offset'] ?? 0 );
				$result = $this->step( $job_id, self::WORKER_STEP_LIMIT, $offset );
				$control = (string) ( $result['step_control']['outcome'] ?? '' );
				if ( 'busy' === $control ) {
					$stop_reason = 'lock_busy';
					break;
				}
				if ( 'stale' === $control ) {
					return $this->state->public_state();
				}
				$budget->mark_unit_processed();
				$current = $this->state->current();
				if ( $job_id !== (string) ( $current['job_id'] ?? '' ) ) {
					return $this->state->public_state();
				}
				if ( ! $this->active_phase( (string) ( $current['phase'] ?? '' ) ) ) {
					$stop_reason = $this->terminal_stop_reason( (string) ( $current['phase'] ?? '' ) );
					break;
				}
			}
		} catch ( Throwable $throwable ) {
			$stop_reason = 'error';
			$this->fail_background_if_owned( $job_id, 'DPD geography background worker failed: ' . $this->sanitize_error( $throwable->getMessage() ) );
		}

		if ( '' === $stop_reason ) {
			$stop_reason = $budget->stop_reason();
		}
		$this->save_worker_metrics_if_owned( $job_id, $budget, $stop_reason );
		$current = $this->state->current();
		if ( $job_id === (string) ( $current['job_id'] ?? '' ) && $this->active_phase( (string) ( $current['phase'] ?? '' ) ) ) {
			if ( ! $this->schedule_worker_once( $job_id, (int) ( $current['byte_offset'] ?? 0 ) ) ) {
				$this->fail_background_if_owned( $job_id, 'Unable to schedule DPD geography import continuation.' );
			}
		}

		return $this->state->public_state();
	}

	private function step_with_import_lock( string $job_id, int $limit, ?int $expected_byte_offset ): array {
		$state = $this->state->current();
		if ( $this->legacy_runner_protocol( $state ) ) {
			return $this->legacy_runner_response();
		}
		if ( '' !== $job_id && $job_id !== (string) ( $state['job_id'] ?? '' ) ) {
			return $this->with_step_control( $this->state->public_state(), 'stale' );
		}
		if ( ! in_array( (string) ( $state['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
			return $this->state->public_state();
		}
		$lock_job_id = (string) ( $state['job_id'] ?? $job_id );
		$token = $this->lock?->acquire( $lock_job_id, self::STEP_LOCK_TTL_SECONDS );
		if ( null === $token ) {
			return $this->with_step_control( $this->state->public_state(), 'busy' );
		}
		try {
			$state = $this->state->current();
			if ( $this->legacy_runner_protocol( $state ) ) {
				return $this->legacy_runner_response();
			}
			if ( '' !== $job_id && $job_id !== (string) ( $state['job_id'] ?? '' ) ) {
				return $this->with_step_control( $this->state->public_state(), 'stale' );
			}
			if ( ! in_array( (string) ( $state['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
				return $this->state->public_state();
			}
			if ( null !== $expected_byte_offset && $expected_byte_offset !== (int) ( $state['byte_offset'] ?? 0 ) ) {
				return $this->with_step_control( $this->state->public_state(), 'stale' );
			}
			$started_at = microtime( true );
			$result = $this->step_unlocked( $state, max( 1, $limit ) );
			$this->record_step_metrics_if_owned( $lock_job_id, (int) round( ( microtime( true ) - $started_at ) * 1000 ) );

			return isset( $result['step_control'] ) ? $result : $this->state->public_state();
		} finally {
			$this->lock?->release( $token );
		}
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private function step_unlocked( array $state, int $limit ): array {
		$start_job_id = (string) ( $state['job_id'] ?? '' );
		$start_offset = (int) ( $state['byte_offset'] ?? 0 );
		$file = (string) ( $state['file_path'] ?? '' );
		if ( '' === $file || ! file_exists( $file ) ) {
			return $this->fail_with_report( 'DPD geography import file is missing.' );
		}
		$stage_table = (string) ( $state['stage_table'] ?? '' );
		if ( '' === $stage_table || ! $this->stage->exists( $stage_table ) ) {
			return $this->fail_with_report( 'DPD geography staging table is missing.' );
		}

		$columns = is_array( $state['columns'] ?? null ) ? $state['columns'] : array();
		try {
			$step = $this->parser->read_step( $file, $start_offset, $columns, $limit );
		} catch ( Throwable $throwable ) {
			return $this->fail_with_report( 'DPD geography CSV parse failed: ' . $throwable->getMessage() );
		}
		if ( $this->step_state_is_stale( $start_job_id, $start_offset ) ) {
			return $this->with_step_control( $this->state->public_state(), 'stale' );
		}
		$patch = array(
			'phase' => 'importing',
			'byte_offset' => (int) $step['new_byte_offset'],
			'rows_read' => (int) $state['rows_read'] + (int) $step['rows_read_count'],
			'last_message' => 'DPD geography import is processing CSV rows.',
		);
		foreach ( array_chunk( $step['rows'], self::MATCH_BATCH_SIZE ) as $rows ) {
			$context = $this->match_context_for_rows( $rows, $patch );
			$candidates_by_row = array();
			$foreign_rows = array();
			foreach ( $rows as $row_index => $row ) {
				$country = strtoupper( trim( (string) ( $row['country_code'] ?? '' ) ) );
				if ( in_array( $country, array( 'AM', 'BY', 'KZ', 'KG' ), true ) ) {
					$foreign_rows[ (int) $row_index ] = $row;
					continue;
				}
				$row_candidates = array();
				$this->process_row( $row, $patch, $context, $row_candidates );
				if ( array() !== $row_candidates ) {
					$candidates_by_row[ (int) $row_index ] = $row_candidates[0];
				}
			}
			$this->process_foreign_rows_batch( $foreign_rows, $patch, $candidates_by_row );
			ksort( $candidates_by_row, SORT_NUMERIC );
			$candidates = array_values( $candidates_by_row );
			$this->stage_candidates( $stage_table, $candidates, $patch );
			if ( $this->step_state_is_stale( $start_job_id, $start_offset ) ) {
				return $this->with_step_control( $this->state->public_state(), 'stale' );
			}
		}

		if ( $this->step_state_is_stale( $start_job_id, $start_offset ) ) {
			return $this->with_step_control( $this->state->public_state(), 'stale' );
		}
		$state = $this->state->update( $patch );
		if ( ! empty( $step['eof'] ) ) {
			if ( $this->step_job_is_stale( $start_job_id ) ) {
				return $this->with_step_control( $this->state->public_state(), 'stale' );
			}
			$state = $this->finalize( $state );
		}

		return $this->state->public_state();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function current_state(): array {
		if ( $this->legacy_runner_protocol( $this->state->current() ) ) {
			return $this->legacy_runner_response();
		}

		return $this->state->public_state();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function reset(): array {
		$current = $this->state->current();
		$token = $this->lock?->acquire( (string) ( $current['job_id'] ?? 'dpd-geography-reset' ), self::STEP_LOCK_TTL_SECONDS );
		if ( null === $token ) {
			$state = $this->with_operation_control( $this->state->public_state(), 'busy' );
			$state['last_message'] = 'Шаг импорта действительно выполняется. Подождите завершения либо используйте принудительную отмену.';
			return $state;
		}
		try {
			$current = $this->state->current();
			$this->unschedule_worker( (string) ( $current['job_id'] ?? '' ), (int) ( $current['byte_offset'] ?? 0 ) );
			$stage_table = (string) ( $current['stage_table'] ?? '' );
			if ( '' !== $stage_table ) {
				$this->stage->drop( $stage_table );
			}
			$this->state->reset();
			return $this->state->public_state();
		} finally {
			$this->lock?->release( $token );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function force_cancel(): array {
		$current = $this->state->current();
		$this->state->force_cancel( 'DPD geography import was force-cancelled by admin.' );
		$this->unschedule_worker( (string) ( $current['job_id'] ?? '' ), (int) ( $current['byte_offset'] ?? 0 ) );
		$this->lock?->force_release();

		return $this->state->public_state();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function start_from_existing_file( string $path, string $source, string $source_file, bool $delete_on_finish ): array {
		return $this->run_locked_start(
			$source,
			fn(): array => $this->start_from_existing_file_unlocked( $path, $source, $source_file, $delete_on_finish )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function start_from_existing_file_unlocked( string $path, string $source, string $source_file, bool $delete_on_finish, bool $schedule_background = true ): array {
		$stage_table = '';
		try {
			$inspect = $this->parser->inspect_header( $path );
			$job_id = sha1( microtime( true ) . '|' . $source . '|' . $source_file . '|' . ( function_exists( 'wp_rand' ) ? (string) wp_rand() : (string) random_int( 1, PHP_INT_MAX ) ) );
			$stage_table = $this->stage->table_name_for_job( $job_id );
			$this->stage->create( $stage_table );
			$state = $this->state->start(
				array(
					'job_id' => $job_id,
					'phase' => 'ready',
					'source' => $source,
					'source_file' => $source_file,
					'file_path' => $path,
					'index_path' => '',
					'stage_table' => $stage_table,
					'index_format_version' => 0,
					'index_size' => 0,
					'index_sha256' => '',
					'index_stats' => array(),
					'runner_protocol_version' => self::RUNNER_PROTOCOL_VERSION,
					'delete_file_on_finish' => $delete_on_finish,
					'file_size' => is_file( $path ) ? (int) filesize( $path ) : 0,
					'total_rows' => 0,
					'byte_offset' => (int) $inspect['data_offset'],
					'columns' => $inspect['columns'],
					'last_message' => $delete_on_finish ? 'DPD geography import job created.' : 'DPD geography import job created for existing file.',
				)
			);
			if ( $schedule_background && $this->scheduler instanceof ActionScheduler && ! $this->schedule_worker_once( $job_id, (int) $inspect['data_offset'] ) ) {
				return $this->fail_with_report( 'DPD geography import job was created, but its background worker could not be scheduled.' );
			}

			return $this->state->public_state();
		} catch ( Throwable $throwable ) {
			return $this->fail_with_report(
				'DPD geography import start failed: ' . $throwable->getMessage(),
				true,
				array( 'source' => $source, 'source_file' => $source_file, 'file_path' => $path, 'index_path' => '', 'stage_table' => $stage_table, 'delete_file_on_finish' => $delete_on_finish, 'file_size' => is_file( $path ) ? (int) filesize( $path ) : 0 )
			);
		}
	}

	/**
	 * @param array<string,string> $row
	 * @param array<string,mixed> $patch
	 */
	private function process_row( array $row, array &$patch, DpdGeographyMatchContext $context, array &$candidates ): void {
		$country = strtoupper( trim( (string) ( $row['country_code'] ?? '' ) ) );
		if ( 'RU' !== $country ) {
			if ( in_array( $country, array( 'AM', 'BY', 'KZ', 'KG' ), true ) ) {
				$batched = array();
				$this->process_foreign_rows_batch( array( 0 => $row ), $patch, $batched );
				if ( isset( $batched[0] ) ) {
					$candidates[] = $batched[0];
				}
				return;
			}
			$this->inc( $patch, 'skipped_non_ru' );
			return;
		}
		$this->inc( $patch, 'ru_rows' );
		$dpd_city_id = preg_replace( '/\D+/', '', (string) ( $row['dpd_city_id'] ?? '' ) ) ?? '';
		if ( '' === $dpd_city_id ) {
			$this->inc( $patch, 'skipped_invalid' );
			return;
		}
		$match = $this->matcher->match( $row, $context );
		if ( 'ambiguous' === $match['status'] ) {
			$this->inc( $patch, 'ambiguous' );
			if ( ! empty( $match['true_fias_ambiguity'] ) ) {
				$this->inc( $patch, 'true_fias_ambiguity' );
			}
			return;
		}
		$location_id = (int) ( $match['location_id'] ?? 0 );
		if ( 'matched' !== $match['status'] || $location_id <= 0 ) {
			$this->inc( $patch, 'unmatched' );
			return;
		}
		$method = (string) ( $match['method'] ?? '' );
		if ( '' !== $method ) {
			$this->inc( $patch, 'matched_by_' . $method );
			if ( 'own_fias' === $method || 'city_fias' === $method ) {
				$this->inc( $patch, 'matched_by_fias' );
			}
		}
		if ( ! empty( $match['resolved_after_fias_disambiguation'] ) ) {
			$this->inc( $patch, 'resolved_after_fias_disambiguation' );
		}
		$candidates[] = array( 'location_id' => $location_id, 'dpd_city_id' => $dpd_city_id, 'match_method' => $method, 'foreign_location_outcome' => '' );
	}

	/**
	 * @param array<int,array<string,string>> $rows
	 * @param array<string,mixed> $patch
	 * @param array<int,array<string,mixed>> $candidates_by_row
	 */
	private function process_foreign_rows_batch( array $rows, array &$patch, array &$candidates_by_row ): void {
		if ( array() === $rows ) {
			return;
		}
		$prepared = array();
		foreach ( $rows as $row_index => $row ) {
			$country = strtoupper( trim( (string) ( $row['country_code'] ?? '' ) ) );
			$this->inc( $patch, 'foreign_rows' );
			$this->inc( $patch, 'foreign_' . strtolower( $country ) . '_rows' );
			$dpd_city_id = preg_replace( '/\D+/', '', (string) ( $row['dpd_city_id'] ?? '' ) ) ?? '';
			$place = trim( (string) ( $row['settlement'] ?? '' ) );
			if ( '' === $dpd_city_id || '0' === $dpd_city_id || '' === $place ) {
				$this->inc( $patch, 'skipped_invalid' );
				continue;
			}
			$prepared[ (string) $row_index ] = array(
				'row_index' => (int) $row_index,
				'dpd_city_id' => $dpd_city_id,
				'country' => $country,
				'place' => $place,
				'region' => trim( (string) ( $row['region'] ?? '' ) ),
				'district' => trim( (string) ( $row['district'] ?? '' ) ),
				'place_type' => $this->normalize_foreign_place_type( (string) ( $row['settlement_type'] ?? '' ) ),
			);
		}
		if ( array() === $prepared ) {
			return;
		}

		try {
			$mapped_ids = $this->delivery_codes->find_location_ids_by_dpd_city_ids( array_column( $prepared, 'dpd_city_id' ) );
			$mapped_locations = $this->locations->find_map_by_ids( array_values( $mapped_ids ) );
			$identity_requests = array();
			foreach ( $prepared as $key => $item ) {
				$identity_requests[ $key ] = array(
					'country_code' => $item['country'],
					'place_name' => $item['place'],
					'region_name' => $item['region'],
					'district_name' => $item['district'],
					'place_type' => $item['place_type'],
				);
			}
			$resolutions = $this->locations->find_foreign_place_identity_resolutions_batch( $identity_requests );
		} catch ( \RuntimeException $exception ) {
			foreach ( $prepared as $item ) {
				$this->record_foreign_save_failure( $patch, $item, $exception->getMessage() );
			}
			return;
		}

		$locations_to_save = array();
		$existing_to_save = array();
		$plans = array();
		$new_identity_seen = array();
		$mutated_by_id = array();
		$mutated_by_identity = array();
		foreach ( $prepared as $key => $item ) {
			$resolution_data = $resolutions[ $key ] ?? array( 'identity_key' => '', 'legacy_key' => '', 'matches' => array(), 'legacy' => null );
			$identity_key = (string) ( $resolution_data['identity_key'] ?? $key );
			$legacy_key = (string) ( $resolution_data['legacy_key'] ?? '' );
			$mapped_id = $mapped_ids[ $item['dpd_city_id'] ] ?? null;
			$mapped_existing = null !== $mapped_id ? ( $mutated_by_id[ $mapped_id ]['location'] ?? $mapped_locations[ $mapped_id ] ?? null ) : null;
			$matches = array();
			foreach ( (array) $resolution_data['matches'] as $match ) {
				if ( ! $match instanceof Location || null === $match->id ) {
					continue;
				}
				$mutation = $mutated_by_id[ $match->id ] ?? null;
				if ( null === $mutation ) {
					$matches[] = $match;
				} elseif ( $identity_key === (string) $mutation['identity_key'] ) {
					$matches[] = $mutation['location'];
				}
			}
			foreach ( $mutated_by_identity[ $identity_key ] ?? array() as $id => $mutated_location ) {
				$matches[ (int) $id ] = $mutated_location;
			}
			$matches = array_values( $matches );
			$legacy = $resolution_data['legacy'] ?? null;
			if ( $legacy instanceof Location && null !== $legacy->id && isset( $mutated_by_id[ $legacy->id ] ) ) {
				$mutation = $mutated_by_id[ $legacy->id ];
				$legacy = $legacy_key === (string) $mutation['legacy_key'] ? $mutation['location'] : null;
			}
			$resolution = $this->resolve_foreign_canonical_location_from_matches( $matches, $mapped_id );
			$existing = $resolution['location'] instanceof Location ? $resolution['location'] : $mapped_existing;
			if ( (int) $resolution['match_count'] > 1 ) {
				$this->inc( $patch, 'foreign_duplicate_identity_rows' );
			}
			if ( ! $existing instanceof Location && $legacy instanceof Location ) {
				$existing = $legacy;
			}
			$location = $this->foreign_location( $item, $existing );
			if ( array() !== $location->validate() ) {
				$this->inc( $patch, 'skipped_invalid' );
				continue;
			}
			$persistence_key = $existing instanceof Location && null !== $existing->id ? 'id:' . $existing->id : 'new:' . $identity_key;
			$outcome = $existing instanceof Location || isset( $new_identity_seen[ $identity_key ] ) ? 'foreign_locations_updated' : 'foreign_locations_inserted';
			$new_identity_seen[ $identity_key ] = true;
			$locations_to_save[ $persistence_key ] = $location;
			if ( ! array_key_exists( $persistence_key, $existing_to_save ) ) {
				$existing_to_save[ $persistence_key ] = $existing;
			}
			if ( $existing instanceof Location && null !== $existing->id ) {
				$mutated_by_id[ $existing->id ] = array(
					'identity_key' => $identity_key,
					'legacy_key' => '' === trim( $item['district'] ) ? $legacy_key : '',
					'location' => $location,
				);
				$mutated_by_identity[ $identity_key ][ $existing->id ] = $location;
			}
			$plans[ $key ] = array( 'persistence_key' => $persistence_key, 'outcome' => $outcome, 'item' => $item );
		}

		$saved = $this->locations->save_foreign_locations_batch( $locations_to_save, $existing_to_save );
		foreach ( $plans as $plan ) {
			$item = $plan['item'];
			$persistence_key = (string) $plan['persistence_key'];
			if ( isset( $saved['errors'][ $persistence_key ] ) ) {
				$this->record_foreign_save_failure( $patch, $item, (string) $saved['errors'][ $persistence_key ] );
				continue;
			}
			$saved_id = (int) ( $saved['ids'][ $persistence_key ] ?? 0 );
			if ( $saved_id <= 0 ) {
				$this->record_foreign_save_failure( $patch, $item, 'missing saved id' );
				continue;
			}
			$candidates_by_row[ (int) $item['row_index'] ] = array(
				'location_id' => $saved_id,
				'dpd_city_id' => $item['dpd_city_id'],
				'match_method' => 'foreign',
				'foreign_location_outcome' => $plan['outcome'],
				'error_context' => 'dpd_city_id=' . $item['dpd_city_id'] . ' country_code=' . $item['country'] . ' place_name=' . $item['place'],
			);
		}
	}

	private function foreign_location( array $item, ?Location $existing ): Location {
		$region_type = $this->foreign_region_type( $item['region'], $item['place'], $item['place_type'] );
		$district_type = '' !== $item['district'] ? 'р-н' : '';
		$is_city = $this->foreign_place_type_is_city( $item['place_type'] );

		return Location::from_array( array(
			'id' => $existing?->id,
			'country_code' => $item['country'],
			'region_name' => $item['region'],
			'region_type' => $region_type,
			'district_name' => $item['district'],
			'district_type' => $district_type,
			'city_name' => $is_city ? $item['place'] : '',
			'city_type' => $is_city ? 'г' : '',
			'settlement_name' => $item['place'],
			'settlement_type' => $item['place_type'],
			'place_name' => $item['place'],
			'place_type' => $item['place_type'],
			'place_level' => 0,
			'display_name' => $this->foreign_display_name( $item['country'], $item['region'], $region_type, $item['district'], $district_type, $item['place'], $item['place_type'] ),
			'postal_code' => '',
			'russianpost_courier_calc_postal_code' => '',
			'fias_id' => '',
			'gar_object_id' => 0,
			'gar_id' => '',
			'kladr_id' => '',
			'latitude' => null,
			'longitude' => null,
			'active' => true,
		) );
	}

	private function record_foreign_save_failure( array &$patch, array $item, string $message ): void {
		$this->inc( $patch, 'foreign_save_failed' );
		$this->add_error( $patch, sprintf(
			'Failed to resolve or save foreign DPD location for dpd_city_id=%s country_code=%s place_name=%s: %s',
			$item['dpd_city_id'],
			$item['country'],
			$item['place'],
			$this->sanitize_error( $message )
		) );
	}

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @param array<string,mixed> $patch
	 */
	private function stage_candidates( string $stage_table, array $candidates, array &$patch ): void {
		if ( array() === $candidates ) {
			return;
		}
		$results = $this->stage->upsert_candidates_batch( $stage_table, $candidates );
		foreach ( $candidates as $index => $candidate ) {
			$result = (string) ( $results[ $index ] ?? 'invalid' );
			if ( 'inserted' === $result ) {
				$this->inc( $patch, 'saved_candidates' );
			} elseif ( 'unchanged' === $result ) {
				$this->inc( $patch, 'unchanged_mappings' );
			} elseif ( 'conflict' === $result ) {
				$this->inc( $patch, 'conflicts' );
				if ( 'foreign' === (string) ( $candidate['match_method'] ?? '' ) ) {
					$this->inc( $patch, 'foreign_mapping_conflicts' );
				}
				continue;
			} else {
				$context = (string) ( $candidate['error_context'] ?? 'location_id=' . (int) ( $candidate['location_id'] ?? 0 ) );
				$this->add_error( $patch, 'Failed to stage DPD mapping for ' . $context );
				continue;
			}
			$outcome = (string) ( $candidate['foreign_location_outcome'] ?? '' );
			if ( '' !== $outcome ) {
				$this->inc( $patch, $outcome );
			}
		}
	}

	private function normalize_foreign_place_type( string $type ): string {
		$type = trim( mb_strtolower( str_replace( 'ё', 'е', $type ), 'UTF-8' ) );
		$type = trim( str_replace( '.', '', $type ) );
		if ( in_array( $type, array( 'd', 'д', 'деревня', 'derevnya' ), true ) ) {
			return 'д';
		}
		return match ( $type ) {
			'g', 'г', 'город' => 'г',
			'p', 'п' => 'п',
			's', 'с' => 'с',
			default => trim( $type ),
		};
	}

	private function foreign_place_type_is_city( string $type ): bool {
		return 'г' === $this->normalize_foreign_place_type( $type );
	}

	private function foreign_region_type( string $region, string $place, string $place_type ): string {
		if ( $this->foreign_place_type_is_city( $place_type ) && '' !== trim( $region ) && $this->normalize_foreign_name_for_compare( $region ) === $this->normalize_foreign_name_for_compare( $place ) ) {
			return 'г';
		}

		return '' !== trim( $region ) ? 'обл.' : '';
	}

	private function normalize_foreign_name_for_compare( string $value ): string {
		$value = mb_strtolower( str_replace( 'ё', 'е', trim( $value ) ), 'UTF-8' );
		$value = preg_replace( '/(^|\s)(г|город)\.?\s+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+(обл|область)\.?$/u', '', $value ) ?? $value;
		$value = preg_replace( '/[\.\s]+/u', '', $value ) ?? $value;
		return trim( $value );
	}

	/**
	 * @param array<int,Location> $matches
	 * @return array{location:?Location,duplicate_ids:array<int,int>,match_count:int,method:string}
	 */
	private function resolve_foreign_canonical_location_from_matches( array $matches, ?int $mapped_location_id ): array {
		$count = count( $matches );
		if ( 0 === $count ) {
			return array( 'location' => null, 'duplicate_ids' => array(), 'match_count' => 0, 'method' => 'new' );
		}
		if ( 1 === $count ) {
			return array( 'location' => $matches[0], 'duplicate_ids' => array(), 'match_count' => 1, 'method' => 'single' );
		}

		$duplicate_ids = array_values(
			array_filter(
				array_map( static fn( Location $location ): int => null !== $location->id ? (int) $location->id : 0, $matches ),
				static fn( int $id ): bool => $id > 0
			)
		);
		if ( null !== $mapped_location_id ) {
			foreach ( $matches as $location ) {
				if ( null !== $location->id && (int) $location->id === $mapped_location_id ) {
					return array( 'location' => $location, 'duplicate_ids' => $duplicate_ids, 'match_count' => $count, 'method' => 'existing_dpd_mapping' );
				}
			}
		}

		usort(
			$matches,
			static fn( Location $a, Location $b ): int => ( null !== $a->id && $a->id > 0 ? $a->id : PHP_INT_MAX ) <=> ( null !== $b->id && $b->id > 0 ? $b->id : PHP_INT_MAX )
		);

		return array( 'location' => $matches[0], 'duplicate_ids' => $duplicate_ids, 'match_count' => $count, 'method' => 'lowest_id' );
	}

	private function foreign_display_name( string $country, string $region, string $region_type, string $district, string $district_type, string $place, string $place_type ): string {
		unset( $country );
		$parts = array();
		$duplicate_region_city = 'г' === $region_type && $this->normalize_foreign_name_for_compare( $region ) === $this->normalize_foreign_name_for_compare( $place );
		if ( '' !== trim( $region ) && ! $duplicate_region_city ) {
			$parts[] = trim( trim( $region ) . ( '' !== trim( $region_type ) ? ' ' . trim( $region_type ) : '' ) );
		}
		if ( '' !== trim( $district ) ) {
			$parts[] = trim( trim( $district ) . ( '' !== trim( $district_type ) ? ' ' . trim( $district_type ) : '' ) );
		}
		$parts[] = trim( ( '' !== trim( $place_type ) ? trim( $place_type ) . ' ' : '' ) . trim( $place ) );

		return implode( ', ', array_values( array_filter( $parts, static fn( string $value ): bool => '' !== trim( $value ) ) ) );
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private function finalize( array $state ): array {
		$stage_table = (string) ( $state['stage_table'] ?? '' );
		if ( '' === $stage_table || ! $this->stage->exists( $stage_table ) ) {
			return $this->fail_with_report( 'DPD geography staging table is missing during finalization.' );
		}
		$has_row_errors = (int) ( $state['errors_total'] ?? 0 ) > 0 || (int) ( $state['foreign_save_failed'] ?? 0 ) > 0;
		try {
			$finalized = $this->stage->finalize_into_delivery_codes( $stage_table, ! $has_row_errors );
		} catch ( \RuntimeException $exception ) {
			return $this->fail_with_report( 'DPD geography finalization failed: ' . $this->sanitize_error( $exception->getMessage() ) );
		}
		$finalized_mappings = is_array( $finalized ) ? (int) ( $finalized['mappings'] ?? 0 ) : (int) $finalized;
		$finalized_changes = is_array( $finalized ) ? (int) ( $finalized['changes'] ?? 0 ) : (int) $finalized;
		$stale_cleared = is_array( $finalized ) ? (int) ( $finalized['stale_cleared'] ?? 0 ) : 0;
		$stale_cleanup_skipped = is_array( $finalized ) && ! empty( $finalized['stale_cleanup_skipped'] );
		$state = $this->state->update(
			array(
				'phase' => 'finalizing',
				'finalized_mappings' => $finalized_mappings,
				'finalized_changes' => $finalized_changes,
				'stale_cleared' => $stale_cleared,
				'stale_cleanup_skipped' => $stale_cleanup_skipped,
				'last_message' => 'DPD geography import is finalizing mappings.',
			)
		);
		$file = (string) ( $state['file_path'] ?? '' );
		if ( ! empty( $state['delete_file_on_finish'] ) && '' !== $file && file_exists( $file ) ) {
			@unlink( $file );
		}
		$index_file = (string) ( $state['index_path'] ?? '' );
		if ( '' !== $index_file && file_exists( $index_file ) ) {
			@unlink( $index_file );
		}
		$this->stage->drop( $stage_table );

		if ( $has_row_errors ) {
			$warning_state = $this->state->finish(
				sprintf(
					'DPD geography import finished with %d errors; stale mapping cleanup was skipped.',
					max( (int) ( $state['errors_total'] ?? 0 ), (int) ( $state['foreign_save_failed'] ?? 0 ) )
				),
				'warning'
			);
			$this->settings?->save_geography_import_report( $this->report_from_state( $warning_state ) );
			return $warning_state;
		}

		$final = $this->state->finish( 'DPD geography import finished.', 'success' );
		$this->settings?->save_geography_import_report( $this->report_from_state( $final ) );
		return $final;
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private function report_from_state( array $state ): array {
		return array(
			'phase' => (string) ( $state['phase'] ?? '' ),
			'status' => (string) ( $state['status'] ?? '' ),
			'source' => (string) ( $state['source'] ?? '' ),
			'source_file' => (string) ( $state['source_file'] ?? '' ),
			'file_size' => (int) ( $state['file_size'] ?? 0 ),
			'total_rows' => (int) ( $state['total_rows'] ?? 0 ),
			'ru_rows' => (int) ( $state['ru_rows'] ?? 0 ),
			'foreign_rows' => (int) ( $state['foreign_rows'] ?? 0 ),
			'foreign_am_rows' => (int) ( $state['foreign_am_rows'] ?? 0 ),
			'foreign_by_rows' => (int) ( $state['foreign_by_rows'] ?? 0 ),
			'foreign_kz_rows' => (int) ( $state['foreign_kz_rows'] ?? 0 ),
			'foreign_kg_rows' => (int) ( $state['foreign_kg_rows'] ?? 0 ),
			'foreign_locations_inserted' => (int) ( $state['foreign_locations_inserted'] ?? 0 ),
			'foreign_locations_updated' => (int) ( $state['foreign_locations_updated'] ?? 0 ),
			'foreign_save_failed' => (int) ( $state['foreign_save_failed'] ?? 0 ),
			'foreign_mapping_conflicts' => (int) ( $state['foreign_mapping_conflicts'] ?? 0 ),
			'foreign_duplicate_identity_rows' => (int) ( $state['foreign_duplicate_identity_rows'] ?? 0 ),
			'skipped_non_ru' => (int) ( $state['skipped_non_ru'] ?? 0 ),
			'skipped_invalid' => (int) ( $state['skipped_invalid'] ?? 0 ),
			'matched_by_fias' => (int) ( $state['matched_by_fias'] ?? 0 ),
			'matched_by_own_fias' => (int) ( $state['matched_by_own_fias'] ?? 0 ),
			'matched_by_city_fias' => (int) ( $state['matched_by_city_fias'] ?? 0 ),
			'resolved_after_fias_disambiguation' => (int) ( $state['resolved_after_fias_disambiguation'] ?? 0 ),
			'true_fias_ambiguity' => (int) ( $state['true_fias_ambiguity'] ?? 0 ),
			'matched_by_kladr' => (int) ( $state['matched_by_kladr'] ?? 0 ),
			'matched_by_name' => (int) ( $state['matched_by_name'] ?? 0 ),
			'match_batches' => (int) ( $state['match_batches'] ?? 0 ),
			'max_match_batch_rows' => (int) ( $state['max_match_batch_rows'] ?? 0 ),
			'lookup_query_groups' => (int) ( $state['lookup_query_groups'] ?? 0 ),
			'match_context_candidates_peak' => (int) ( $state['match_context_candidates_peak'] ?? 0 ),
			'saved_candidates' => (int) ( $state['saved_candidates'] ?? 0 ),
			'finalized_mappings' => (int) ( $state['finalized_mappings'] ?? 0 ),
			'finalized_changes' => (int) ( $state['finalized_changes'] ?? 0 ),
			'stale_cleared' => (int) ( $state['stale_cleared'] ?? 0 ),
			'stale_cleanup_skipped' => ! empty( $state['stale_cleanup_skipped'] ),
			'unchanged_mappings' => (int) ( $state['unchanged_mappings'] ?? 0 ),
			'conflicts' => (int) ( $state['conflicts'] ?? 0 ),
			'ambiguous' => (int) ( $state['ambiguous'] ?? 0 ),
			'unmatched' => (int) ( $state['unmatched'] ?? 0 ),
			'errors_total' => (int) ( $state['errors_total'] ?? 0 ),
			'errors' => is_array( $state['errors'] ?? null ) ? $state['errors'] : array(),
			'started_at' => (string) ( $state['started_at'] ?? '' ),
			'finished_at' => (string) ( $state['finished_at'] ?? ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) ) ),
			'last_message' => (string) ( $state['last_message'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $patch
	 */
	private function inc( array &$patch, string $key ): void {
		$patch[ $key ] = max( 0, (int) ( $patch[ $key ] ?? $this->state->current()[ $key ] ?? 0 ) + 1 );
	}

	/**
	 * @param array<string,mixed> $patch
	 */
	private function add_error( array &$patch, string $message ): void {
		$errors = is_array( $patch['errors'] ?? null ) ? $patch['errors'] : array();
		$errors[] = $this->sanitize_error( $message );
		$patch['errors'] = $errors;
		$this->inc( $patch, 'errors_total' );
	}

	private function sanitize_error( string $message ): string {
		$message = preg_replace( '/[\r\n\t]+/', ' ', $message ) ?? $message;
		return trim( $message );
	}

	/**
	 * @param array<int,array<string,string>> $rows
	 * @param array<string,mixed> $patch
	 */
	private function match_context_for_rows( array $rows, array &$patch ): DpdGeographyMatchContext {
		$keys = DpdGeographyLookupKeys::from_rows( $rows );
		$context = new DpdGeographyMatchContext();
		$query_groups = 0;

		$fias = $keys->fias_guids();
		if ( array() !== $fias ) {
			$context->add_own_fias_rows( $this->locations->dpd_find_own_fias_candidates( $fias ) );
			$context->add_city_fias_rows( $this->locations->dpd_find_city_fias_candidates( $fias ) );
			$query_groups += 2;
		}

		$kladr = $keys->kladr_keys();
		if ( array() !== $kladr ) {
			$context->add_kladr_rows( $this->locations->dpd_find_kladr_candidates( $kladr ) );
			++$query_groups;
		}

		$names = $keys->names();
		if ( array() !== $names ) {
			$context->add_name_rows( $this->locations->dpd_find_name_candidates( $names ) );
			++$query_groups;
		}

		$this->inc( $patch, 'match_batches' );
		$patch['max_match_batch_rows'] = max( (int) ( $patch['max_match_batch_rows'] ?? $this->state->current()['max_match_batch_rows'] ?? 0 ), count( $rows ) );
		$patch['lookup_query_groups'] = max( 0, (int) ( $patch['lookup_query_groups'] ?? $this->state->current()['lookup_query_groups'] ?? 0 ) + $query_groups );
		$patch['match_context_candidates_peak'] = max( (int) ( $patch['match_context_candidates_peak'] ?? $this->state->current()['match_context_candidates_peak'] ?? 0 ), $context->candidate_count() );

		return $context;
	}

	/**
	 */
	private function step_state_is_stale( string $job_id, int $byte_offset ): bool {
		$current = $this->state->current();
		return $job_id !== (string) ( $current['job_id'] ?? '' )
			|| ! in_array( (string) ( $current['phase'] ?? '' ), array( 'ready', 'importing' ), true )
			|| $byte_offset !== (int) ( $current['byte_offset'] ?? 0 );
	}

	private function step_job_is_stale( string $job_id ): bool {
		$current = $this->state->current();
		return $job_id !== (string) ( $current['job_id'] ?? '' )
			|| ! in_array( (string) ( $current['phase'] ?? '' ), array( 'ready', 'importing' ), true );
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function fail_with_report( string $message, bool $new_job = false, array $context = array() ): array {
		$failed = $new_job ? $this->state->fail_new( $message, $context ) : $this->state->fail( $message );
		$this->settings?->save_geography_import_report( $this->report_from_state( $failed ) );

		return $this->state->public_state();
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private function with_step_control( array $state, string $outcome, int $retry_after_ms = self::LOCK_BUSY_RETRY_MS ): array {
		$state['step_control'] = array(
			'outcome' => $outcome,
			'retry_after_ms' => max( 250, $retry_after_ms ),
		);

		return $state;
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private function with_operation_control( array $state, string $outcome, int $retry_after_ms = self::LOCK_BUSY_RETRY_MS ): array {
		$state['operation_control'] = array(
			'outcome' => $outcome,
			'retry_after_ms' => max( 250, $retry_after_ms ),
		);

		return $state;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function run_locked_start( string $source, callable $callback ): array {
		return $this->with_locations_write_lock( fn(): array => $this->start_with_import_lock( $source, $callback ) );
	}

	private function with_locations_write_lock( callable $callback, bool $step = false ): array {
		try {
			return null !== $this->locations_write_lock ? $this->locations_write_lock->run( $callback ) : $callback();
		} catch ( \RuntimeException $error ) {
			if ( 409 !== $error->getCode() ) {
				throw $error;
			}
			$state = $step ? $this->with_step_control( $this->state->public_state(), 'busy' ) : $this->with_operation_control( $this->state->public_state(), 'busy' );
			$state['last_message'] = $error->getMessage();
			return $state;
		}
	}

	private function start_with_import_lock( string $source, callable $callback ): array {
		$token = $this->lock?->acquire( 'dpd-geography-start', self::START_LOCK_TTL_SECONDS );
		if ( null === $token ) {
			$state = $this->with_operation_control( $this->state->public_state(), 'busy' );
			$state['last_message'] = 'Другой запуск или шаг импорта уже выполняется.';
			return $state;
		}

		try {
			$reason = $this->start_block_reason( $this->state->current() );
			if ( '' !== $reason ) {
				return $this->start_block_response( $reason );
			}

			return $callback();
		} catch ( Throwable $throwable ) {
			return $this->fail_with_report(
				'DPD geography import start failed: ' . $this->sanitize_error( $throwable->getMessage() ),
				true,
				array( 'source' => $source )
			);
		} finally {
			$this->lock?->release( $token );
		}
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function start_block_reason( array $state ): string {
		$phase = (string) ( $state['phase'] ?? '' );
		if ( in_array( $phase, array( 'preparing', 'indexing_locations', 'downloading', 'ready', 'importing', 'finalizing' ), true ) ) {
			return 'active';
		}
		if (
			'failed' === $phase
			&& (
				'' !== (string) ( $state['file_path'] ?? '' )
				|| '' !== (string) ( $state['index_path'] ?? '' )
				|| '' !== (string) ( $state['stage_table'] ?? '' )
			)
		) {
			return 'reset_required';
		}

		return '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function start_block_response( string $reason ): array {
		if ( 'reset_required' === $reason ) {
			$state = $this->with_operation_control( $this->state->public_state(), 'reset_required' );
			$state['last_message'] = 'Предыдущий неуспешный импорт содержит служебные данные. Сначала выполните сброс.';
			return $state;
		}

		$state = $this->with_operation_control( $this->state->public_state(), 'busy' );
		$state['last_message'] = 'Импорт уже выполняется. Сначала дождитесь завершения или выполните сброс.';

		return $state;
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function legacy_runner_protocol( array $state ): bool {
		return in_array( (string) ( $state['phase'] ?? '' ), array( 'ready', 'importing', 'finalizing' ), true )
			&& self::RUNNER_PROTOCOL_VERSION !== (int) ( $state['runner_protocol_version'] ?? 0 );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function legacy_runner_response(): array {
		$state = $this->with_operation_control( $this->state->public_state(), 'reset_required' );
		$state['last_message'] = 'Этот импорт создан предыдущей версией runner. Выполните сброс и запустите импорт заново.';

		return $state;
	}

	private function active_phase( string $phase ): bool {
		return in_array( $phase, array( 'ready', 'importing' ), true );
	}

	private function terminal_stop_reason( string $phase ): string {
		return match ( $phase ) {
			'finished' => 'eof',
			'failed' => 'error',
			default => 'cancelled',
		};
	}

	private function schedule_worker_once( string $job_id, int $byte_offset ): bool {
		if ( ! $this->scheduler instanceof ActionScheduler ) {
			return false;
		}
		$args = array( $job_id, max( 0, $byte_offset ) );
		if ( $this->scheduler->has_scheduled( self::WORKER_HOOK, $args, self::ACTION_GROUP ) ) {
			return true;
		}

		return null !== $this->scheduler->schedule_single( time() + 5, self::WORKER_HOOK, $args, self::ACTION_GROUP );
	}

	private function unschedule_worker( string $job_id, int $byte_offset ): void {
		if ( $this->scheduler instanceof ActionScheduler && '' !== $job_id ) {
			$this->scheduler->unschedule( self::WORKER_HOOK, array( $job_id, max( 0, $byte_offset ) ), self::ACTION_GROUP );
		}
	}

	private function new_execution_budget(): BackgroundExecutionBudget {
		if ( is_callable( $this->execution_budget_factory ) ) {
			$budget = ( $this->execution_budget_factory )();
			if ( $budget instanceof BackgroundExecutionBudget ) {
				return $budget;
			}
		}
		$memory_limit = (string) ini_get( 'memory_limit' );

		return new BackgroundExecutionBudget(
			self::WORKER_SOFT_TIME_BUDGET_SECONDS,
			self::MAX_STEPS_PER_WORKER_SLICE,
			BackgroundExecutionBudget::memory_threshold_from_limit( $memory_limit, self::MEMORY_BUDGET_FRACTION )
		);
	}

	private function save_worker_metrics_if_owned( string $job_id, BackgroundExecutionBudget $budget, string $stop_reason ): void {
		$current = $this->state->current();
		if ( $job_id !== (string) ( $current['job_id'] ?? '' ) ) {
			return;
		}
		$this->state->update(
			array(
				'worker_slice_units' => $budget->units_processed(),
				'worker_slice_duration_ms' => (int) round( $budget->elapsed_seconds() * 1000 ),
				'worker_slice_stop_reason' => $stop_reason,
			)
		);
	}

	private function record_step_metrics_if_owned( string $job_id, int $duration_ms ): void {
		$current = $this->state->current();
		if ( $job_id !== (string) ( $current['job_id'] ?? '' ) ) {
			return;
		}
		$this->state->update(
			array(
				'last_step_duration_ms' => max( 0, $duration_ms ),
				'max_step_duration_ms' => max( max( 0, $duration_ms ), (int) ( $current['max_step_duration_ms'] ?? 0 ) ),
			)
		);
	}

	private function fail_background_if_owned( string $job_id, string $message ): void {
		$current = $this->state->current();
		if ( $job_id !== (string) ( $current['job_id'] ?? '' ) || ! $this->active_phase( (string) ( $current['phase'] ?? '' ) ) ) {
			return;
		}
		$this->fail_with_report( $message );
	}

	private function copy_to_import_temp( string $source, string $name ): string {
		$target = $this->temp_path( $name );
		if ( ! @copy( $source, $target ) ) {
			if ( file_exists( $target ) ) {
				@unlink( $target );
			}
			throw new \RuntimeException( 'Unable to copy uploaded DPD geography CSV to import temp directory.' );
		}

		return $target;
	}

	private function temp_path( string $name ): string {
		$base = function_exists( 'wp_tempnam' ) ? wp_tempnam( $name ) : tempnam( sys_get_temp_dir(), 'wdc-dpd-geography-' );
		if ( ! is_string( $base ) || '' === $base ) {
			throw new \RuntimeException( 'Unable to allocate DPD geography import temp file.' );
		}

		return $base;
	}
}
