<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;

defined( 'ABSPATH' ) || exit;

final class RussianPostPickupImporter {
	public const SCHEDULE_HOOK = 'wdc_russian_post_pickup_import';
	public const INIT_HOOK = 'wdc_russian_post_pickup_import_init';
	public const BATCH_HOOK = 'wdc_russian_post_pickup_import_batch';
	public const FINALIZE_HOOK = 'wdc_russian_post_pickup_import_finalize';
	private const LOCK_KEY = 'wdc_russian_post_pickup_import_lock';
	private const BATCH_SIZE = 500;
	private const MAX_STORED_ERRORS = 10;

	public function __construct(
		private RussianPostOtpravkaApiSettings $settings,
		private RussianPostOtpravkaApiClient $client,
		private RussianPostPickupPointRepository $repository,
		private RussianPostPassportPointNormalizer $normalizer,
		private ?RussianPostPickupImportStateService $state = null,
		private ?ActionScheduler $scheduler = null,
		private ?RussianPostPickupLocationResolver $location_resolver = null
	) {
	}

	public function register(): void {
		add_action( self::SCHEDULE_HOOK, array( $this, 'run_scheduled' ) );
		add_action( self::INIT_HOOK, array( $this, 'run_import_init' ), 10, 2 );
		add_action( self::BATCH_HOOK, array( $this, 'run_import_batch' ), 10, 3 );
		add_action( self::FINALIZE_HOOK, array( $this, 'run_import_finalize' ), 10, 2 );
		add_action( 'init', array( $this, 'sync_schedule' ) );
	}

	public function run_scheduled( string $type = '' ): void {
		$this->queue_background_import( '' !== trim( $type ) ? $type : $this->settings->unload_type() );
	}

	public function sync_schedule(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		$scheduled = wp_next_scheduled( self::SCHEDULE_HOOK );
		if ( $this->settings->schedule_enabled() && false === $scheduled ) {
			wp_schedule_event( time() + ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 ), 'weekly', self::SCHEDULE_HOOK );
		}
		if ( ! $this->settings->schedule_enabled() && false !== $scheduled && function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::SCHEDULE_HOOK );
		}
	}

	/**
	 * Backward-compatible entry point: starts the resumable background pipeline,
	 * but does not process the full passport payload in this request.
	 *
	 * @return array<string,mixed>
	 */
	public function import( string $type = 'ALL' ): array {
		$queued = $this->queue_background_import( $type );
		$state = $this->state instanceof RussianPostPickupImportStateService ? $this->state->current() : array();

		return array(
			'success' => $queued,
			'queued' => $queued,
			'type' => $this->normalize_type( $type ),
			'import_id' => (string) ( $state['import_id'] ?? '' ),
			'errors' => is_array( $state['errors'] ?? null ) ? $state['errors'] : array(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function run_import_init( string $import_id = '', string $type = 'ALL' ): array {
		$type = $this->normalize_type( $type );
		$state = $this->state instanceof RussianPostPickupImportStateService ? $this->state->current() : array();
		if ( '' === $import_id || $import_id !== (string) ( $state['import_id'] ?? '' ) ) {
			return array( 'success' => false, 'errors' => array( 'Import init ignored: stale import id.' ) );
		}

		$this->lock();
		$staging_table = $this->repository->staging_table( $import_id );
		$main_table = $this->repository->main_table();
		$backup_table = $this->repository->backup_table( $import_id );
		$source = in_array( (string) ( $state['source'] ?? '' ), array( 'uploaded_zip', 'uploaded_payload' ), true ) ? (string) $state['source'] : 'api_download';
		$uploaded_zip = (string) ( $state['temp_zip_file'] ?? '' );
		$uploaded_payload = (string) ( $state['payload_file'] ?? '' );
		$original_upload_name = (string) ( $state['original_upload_name'] ?? '' );
		$uploaded_file_size = max( 0, (int) ( $state['uploaded_file_size'] ?? 0 ) );
		$this->repository->drop_table( $staging_table );
		$this->repository->create_schema_if_needed( $staging_table );
		$this->state?->start( $type, $import_id );
		$result = $this->base_result( $type, $import_id );
		$result['staging_table'] = $staging_table;
		$result['main_table'] = $main_table;
		$result['backup_table'] = $backup_table;
		$result['source'] = $source;
		$result['original_upload_name'] = $original_upload_name;
		$result['uploaded_file_size'] = $uploaded_file_size;
		$temp_file = '';
		try {
			if ( 'uploaded_payload' === $source ) {
				$payload_size = is_file( $uploaded_payload ) ? (int) filesize( $uploaded_payload ) : 0;
				if ( '' === $uploaded_payload || 0 >= $payload_size ) {
					$result['payload_file'] = $uploaded_payload;
					$result['payload_size'] = $payload_size;
					$result['errors'][] = 'Uploaded TXT/JSON payload file is missing or empty.';
					return $this->fail_pipeline( $result );
				}
				$result['payload_file'] = $uploaded_payload;
				$result['payload_size'] = $payload_size;
				$result['payload_offset'] = 0;
				$this->state?->update( 'parse', $result );
				if ( ! $this->schedule_single( self::BATCH_HOOK, array( $import_id, $type, 0 ) ) ) {
					$result['errors'][] = 'Unable to schedule background import batch job.';
					return $this->fail_pipeline( $result );
				}

				return array( 'success' => true, 'import_id' => $import_id, 'payload_file' => $uploaded_payload );
			}

			if ( 'uploaded_zip' === $source ) {
				$temp_file = $uploaded_zip;
				$result['temp_zip_file'] = $temp_file;
				$result['downloaded'] = is_file( $temp_file ) ? (int) filesize( $temp_file ) : $uploaded_file_size;
				$result['temp_file_size'] = $result['downloaded'];
				if ( '' === $temp_file || ! is_file( $temp_file ) || 0 >= (int) filesize( $temp_file ) ) {
					$result['errors'][] = 'Uploaded ZIP file is missing or empty.';
					return $this->fail_pipeline( $result );
				}
				$this->state?->update( 'extract', $result );
			} else {
				$result['download_url'] = $this->client->passport_url( $type );
				$result['download_started_at'] = $this->now();
				$this->state?->update(
					'download',
					array_merge(
						$result,
						array(
							'download_url' => $result['download_url'],
							'download_started_at' => $result['download_started_at'],
						)
					)
				);
				$download = $this->client->download_passport_zip( $type );
				$result = array_merge( $result, $this->download_result_state( $download ) );
				if ( empty( $download['success'] ) ) {
					$result['errors'][] = (string) ( $download['error'] ?? 'Download failed.' );
					return $this->fail_pipeline( $result );
				}

				$temp_file = (string) $download['temp_file'];
				$result['downloaded'] = is_file( $temp_file ) ? (int) filesize( $temp_file ) : (int) ( $download['temp_file_size'] ?? 0 );
				$result['temp_zip_file'] = $temp_file;
				$this->state?->update( 'extract', $result );
			}

			$extract_started = microtime( true );
			$result = array_merge(
				$result,
				array(
					'extract_started_at' => $this->now(),
					'extract_zip_file' => $temp_file,
					'extract_zip_size' => is_file( $temp_file ) ? (int) filesize( $temp_file ) : 0,
					'ziparchive_available' => $this->ziparchive_available(),
					'extract_backend' => 'ziparchive',
					'extract_duration_ms' => 0,
					'extract_error' => '',
					'extract_success' => false,
				)
			);
			$this->state?->update( 'extract', $result );
			$extract = $this->extract_first_payload_from_zip( $temp_file );
			$extract['extract_duration_ms'] = (int) round( ( microtime( true ) - $extract_started ) * 1000 );
			$result = array_merge( $result, $extract );
			$this->delete_temp_file( $temp_file );
			$temp_file = '';
			$this->state?->update( 'extract', $result );
			if ( empty( $extract['success'] ) ) {
				$result['errors'][] = (string) ( $extract['extract_error'] ?? 'ZIP does not contain JSON/TXT passport payload.' );
				return $this->fail_pipeline( $result );
			}

			$payload_file = (string) $extract['payload_file'];
			$result['payload_file'] = $payload_file;
			$result['payload_size'] = is_file( $payload_file ) ? (int) filesize( $payload_file ) : 0;
			$result['payload_offset'] = 0;
			$this->state?->update( 'parse', $result );
			if ( ! $this->schedule_single( self::BATCH_HOOK, array( $import_id, $type, 0 ) ) ) {
				$result['errors'][] = 'Unable to schedule background import batch job.';
				return $this->fail_pipeline( $result );
			}

			return array( 'success' => true, 'import_id' => $import_id, 'payload_file' => $payload_file );
		} finally {
			$this->delete_temp_file( $temp_file );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function run_import_batch( string $import_id = '', string $type = 'ALL', int $payload_offset = 0 ): array {
		$type = $this->normalize_type( $type );
		$state = $this->state instanceof RussianPostPickupImportStateService ? $this->state->current() : array();
		if ( '' === $import_id || $import_id !== (string) ( $state['import_id'] ?? '' ) || ! in_array( (string) ( $state['status'] ?? '' ), array( 'queued', 'running' ), true ) ) {
			return array( 'success' => false, 'errors' => array( 'Import batch ignored: stale import state.' ) );
		}

		$payload_file = (string) ( $state['payload_file'] ?? '' );
		$result = $this->state_to_result( $state, $type, $import_id );
		if ( '' === $payload_file || ! is_file( $payload_file ) ) {
			$result['errors'][] = 'Passport payload file is missing.';
			return $this->fail_pipeline( $result );
		}

		$started = microtime( true );
		$read = $this->read_next_passport_objects( $payload_file, max( 0, $payload_offset ), self::BATCH_SIZE );
		if ( empty( $read['found_array'] ) ) {
			$result['errors'][] = 'Passport payload does not contain passportElements.';
			return $this->fail_pipeline( $result );
		}

		$rows = array();
		$batch_errors = array();
		$parsed = 0;
		$skipped = 0;
		$location_stats = $this->empty_location_match_stats();
		foreach ( $read['objects'] as $json ) {
			$item = json_decode( $json, true );
			if ( ! is_array( $item ) ) {
				++$skipped;
				$this->add_limited_error( $batch_errors, 'Invalid passport item JSON: ' . json_last_error_msg() );
				continue;
			}
			++$parsed;
			$row = $this->normalizer->normalize( $item, $type, (string) ( $state['started_at'] ?? $result['started_at'] ) );
			if ( null === $row ) {
				++$skipped;
				continue;
			}
			if ( $this->location_resolver instanceof RussianPostPickupLocationResolver ) {
				$match = $this->location_resolver->resolve( $row );
				$this->increment_location_match_stats( $location_stats, $match );
				if ( 'unique' === $match['status'] && (int) ( $match['location_id'] ?? 0 ) > 0 ) {
					$row['location_id'] = (int) $match['location_id'];
				}
			}
			$rows[] = $row;
		}

		$staging_table = (string) ( $state['staging_table'] ?? '' );
		if ( '' === $staging_table ) {
			$result['errors'][] = 'Russian Post pickup staging table is missing from import state.';
			return $this->fail_pipeline( $result );
		}
		$upsert = $this->repository->insert_batch( $rows, $staging_table );
		$duration_ms = (int) round( ( microtime( true ) - $started ) * 1000 );
		$errors = array_merge( is_array( $state['errors'] ?? null ) ? $state['errors'] : array(), $batch_errors );
		if ( $duration_ms > 10000 ) {
			$this->add_limited_error( $errors, 'Slow upsert batch: ' . $duration_ms . ' ms, batch_size=' . count( $rows ) );
		}

		$result['parsed'] += $parsed;
		$result['inserted'] += (int) $upsert['inserted'];
		$result['updated'] += (int) $upsert['updated'];
		foreach ( $location_stats as $key => $value ) {
			$result[ $key ] = (int) ( $result[ $key ] ?? 0 ) + $value;
		}
		$result['rows_inserted_to_staging'] = (int) ( $state['rows_inserted_to_staging'] ?? 0 ) + (int) $upsert['inserted'];
		$result['skipped'] += $skipped + (int) $upsert['skipped'];
		$result['payload_file'] = $payload_file;
		$result['payload_offset'] = (int) $read['offset'];
		$result['objects_processed'] = (int) ( $state['objects_processed'] ?? 0 ) + count( $read['objects'] );
		$result['batches_processed'] = (int) ( $state['batches_processed'] ?? 0 ) + 1;
		$result['current_batch_size'] = count( $rows );
		$result['last_batch_duration_ms'] = $duration_ms;
		$result['max_batch_duration_ms'] = max( (int) ( $state['max_batch_duration_ms'] ?? 0 ), $duration_ms );
		$result['parser_completed'] = ! empty( $read['eof'] );
		$result['errors'] = array_slice( array_map( 'strval', $errors ), 0, self::MAX_STORED_ERRORS );
		$this->state?->update( 'upsert', $result );

		if ( ! empty( $read['eof'] ) ) {
			if ( ! $this->schedule_single( self::FINALIZE_HOOK, array( $import_id, $type ) ) ) {
				$result['errors'][] = 'Unable to schedule background import finalize job.';
				return $this->fail_pipeline( $result );
			}
		} elseif ( ! $this->schedule_single( self::BATCH_HOOK, array( $import_id, $type, (int) $read['offset'] ) ) ) {
			$result['errors'][] = 'Unable to schedule next background import batch job.';
			return $this->fail_pipeline( $result );
		}

		return array( 'success' => true, 'eof' => ! empty( $read['eof'] ), 'offset' => (int) $read['offset'], 'batch_size' => count( $rows ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function run_import_finalize( string $import_id = '', string $type = 'ALL' ): array {
		$type = $this->normalize_type( $type );
		$state = $this->state instanceof RussianPostPickupImportStateService ? $this->state->current() : array();
		if ( '' === $import_id || $import_id !== (string) ( $state['import_id'] ?? '' ) ) {
			return array( 'success' => false, 'errors' => array( 'Import finalize ignored: stale import id.' ) );
		}

		$result = $this->state_to_result( $state, $type, $import_id );
		$result['swap_started_at'] = $this->now();
		$this->state?->update( 'deactivate', $result );
		$staging_table = (string) ( $state['staging_table'] ?? '' );
		$backup_table = (string) ( $state['backup_table'] ?? $this->repository->backup_table( $import_id ) );
		$swap = '' !== $staging_table
			? $this->repository->swap_staging_to_main( $staging_table, $backup_table )
			: array( 'success' => false, 'message' => 'Russian Post pickup staging table is missing from import state.', 'recovered' => false );
		if ( empty( $swap['success'] ) ) {
			$result['errors'][] = (string) ( $swap['message'] ?? 'Unable to swap Russian Post pickup staging table.' );
			return $this->fail_pipeline( $result, false );
		}
		if ( ! $this->repository->analyze_main_table() ) {
			$result['errors'][] = 'ANALYZE TABLE for Russian Post pickup main table failed.';
		}
		$result['swap_finished_at'] = $this->now();
		$result['deactivated'] = 0;
		$result['success'] = true;
		$result['parser_completed'] = true;
		$result['finished_at'] = $this->now();
		$this->cleanup_state_files( $state, false );
		$this->settings->save_import_result( $result, true );
		$this->state?->success( $result );
		$this->unlock();

		return $result;
	}

	public function is_locked(): bool {
		$this->refresh_state_for_status();
		if ( function_exists( 'get_transient' ) ) {
			return false !== get_transient( self::LOCK_KEY );
		}

		return (bool) get_option( self::LOCK_KEY, false );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function refresh_state_for_status(): array {
		$state = $this->state?->reset_stale_if_needed() ?? array();
		if ( is_array( $state ) && 'failed' === (string) ( $state['status'] ?? '' ) && str_contains( strtolower( implode( ';', array_map( 'strval', is_array( $state['errors'] ?? null ) ? $state['errors'] : array() ) ) ), 'stale' ) ) {
			$this->cleanup_state_files( $state );
			$this->unlock();
			$state = $this->state?->current() ?? $state;
		}

		return is_array( $state ) ? $state : array();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function reset_stale_or_running_import(): array {
		$state = $this->state instanceof RussianPostPickupImportStateService ? $this->state->current() : array();
		$this->unlock();
		$this->cleanup_state_files( $state, true );

		return $this->state instanceof RussianPostPickupImportStateService ? $this->state->cancel_by_admin() : array();
	}

	public function queue_background_import( string $type ): bool {
		if ( $this->is_locked() ) {
			return false;
		}

		$current = $this->state?->reset_stale_if_needed() ?? array();
		if ( in_array( (string) ( $current['status'] ?? '' ), array( 'queued', 'running' ), true ) ) {
			return false;
		}

		$type = $this->normalize_type( $type );
		$import_id = sha1( (string) microtime( true ) . '|' . $type . '|' . ( function_exists( 'wp_rand' ) ? (string) wp_rand() : (string) random_int( 1, PHP_INT_MAX ) ) );
		if ( $this->schedule_single( self::INIT_HOOK, array( $import_id, $type ) ) ) {
			$this->state?->queue( $type, $import_id, array( 'source' => 'api_download' ) );
			$this->lock();
			return true;
		}

		$this->state?->failed(
			array(
				'type' => $type,
				'import_id' => $import_id,
				'finished_at' => $this->now(),
				'errors' => array( 'Unable to schedule background import job.' ),
			)
		);

		return false;
	}

	public function queue_background_import_from_zip( string $zip_file, string $type = 'ALL', string $original_upload_name = '' ): bool {
		if ( $this->is_locked() ) {
			return false;
		}

		$current = $this->state?->reset_stale_if_needed() ?? array();
		if ( in_array( (string) ( $current['status'] ?? '' ), array( 'queued', 'running' ), true ) ) {
			return false;
		}

		$type = $this->normalize_type( $type );
		$zip_file = trim( $zip_file );
		$file_size = '' !== $zip_file && is_file( $zip_file ) ? (int) filesize( $zip_file ) : 0;
		$import_id = sha1( (string) microtime( true ) . '|uploaded_zip|' . $type . '|' . ( function_exists( 'wp_rand' ) ? (string) wp_rand() : (string) random_int( 1, PHP_INT_MAX ) ) );
		if ( 0 >= $file_size || ! str_ends_with( strtolower( $zip_file ), '.zip' ) ) {
			$this->state?->failed(
				array(
					'type' => $type,
					'import_id' => $import_id,
					'source' => 'uploaded_zip',
					'temp_zip_file' => $zip_file,
					'original_upload_name' => $original_upload_name,
					'uploaded_file_size' => $file_size,
					'finished_at' => $this->now(),
					'errors' => array( 'Uploaded ZIP file is missing, empty, or has an invalid extension.' ),
				)
			);
			$this->delete_temp_file( $zip_file );
			return false;
		}

		if ( $this->schedule_single( self::INIT_HOOK, array( $import_id, $type ) ) ) {
			$this->state?->queue(
				$type,
				$import_id,
				array(
					'source' => 'uploaded_zip',
					'temp_zip_file' => $zip_file,
					'original_upload_name' => $original_upload_name,
					'uploaded_file_size' => $file_size,
				)
			);
			$this->lock();
			return true;
		}

		$this->state?->failed(
			array(
				'type' => $type,
				'import_id' => $import_id,
				'source' => 'uploaded_zip',
				'temp_zip_file' => $zip_file,
				'original_upload_name' => $original_upload_name,
				'uploaded_file_size' => $file_size,
				'finished_at' => $this->now(),
				'errors' => array( 'Unable to schedule background import job.' ),
			)
		);
		$this->delete_temp_file( $zip_file );

		return false;
	}

	public function queue_background_import_from_payload( string $payload_file, string $type = 'ALL', string $original_upload_name = '' ): bool {
		if ( $this->is_locked() ) {
			return false;
		}

		$current = $this->state?->reset_stale_if_needed() ?? array();
		if ( in_array( (string) ( $current['status'] ?? '' ), array( 'queued', 'running' ), true ) ) {
			return false;
		}

		$type = $this->normalize_type( $type );
		$payload_file = trim( $payload_file );
		$file_size = '' !== $payload_file && is_file( $payload_file ) ? (int) filesize( $payload_file ) : 0;
		$lower = strtolower( $payload_file );
		$import_id = sha1( (string) microtime( true ) . '|uploaded_payload|' . $type . '|' . ( function_exists( 'wp_rand' ) ? (string) wp_rand() : (string) random_int( 1, PHP_INT_MAX ) ) );
		if ( 0 >= $file_size || ( ! str_ends_with( $lower, '.txt' ) && ! str_ends_with( $lower, '.json' ) ) ) {
			$this->state?->failed(
				array(
					'type' => $type,
					'import_id' => $import_id,
					'source' => 'uploaded_payload',
					'payload_file' => $payload_file,
					'payload_size' => $file_size,
					'original_upload_name' => $original_upload_name,
					'uploaded_file_size' => $file_size,
					'finished_at' => $this->now(),
					'errors' => array( 'Uploaded TXT/JSON payload file is missing, empty, or has an invalid extension.' ),
				)
			);
			$this->delete_temp_file( $payload_file );
			return false;
		}

		if ( $this->schedule_single( self::INIT_HOOK, array( $import_id, $type ) ) ) {
			$this->state?->queue(
				$type,
				$import_id,
				array(
					'source' => 'uploaded_payload',
					'payload_file' => $payload_file,
					'payload_size' => $file_size,
					'original_upload_name' => $original_upload_name,
					'uploaded_file_size' => $file_size,
				)
			);
			$this->lock();
			return true;
		}

		$this->state?->failed(
			array(
				'type' => $type,
				'import_id' => $import_id,
				'source' => 'uploaded_payload',
				'payload_file' => $payload_file,
				'payload_size' => $file_size,
				'original_upload_name' => $original_upload_name,
				'uploaded_file_size' => $file_size,
				'finished_at' => $this->now(),
				'errors' => array( 'Unable to schedule background import job.' ),
			)
		);
		$this->delete_temp_file( $payload_file );

		return false;
	}

	private function lock(): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::LOCK_KEY, 1, 3 * ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 ) );
			return;
		}
		update_option( self::LOCK_KEY, 1, false );
	}

	private function unlock(): void {
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( self::LOCK_KEY );
			return;
		}
		delete_option( self::LOCK_KEY );
	}

	private function schedule_single( string $hook, array $args ): bool {
		if ( $this->scheduler instanceof ActionScheduler && null !== $this->scheduler->schedule_single( time() + 5, $hook, $args ) ) {
			return true;
		}
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			return (bool) wp_schedule_single_event( time() + 5, $hook, $args );
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private function fail_pipeline( array $result, bool $cleanup_tables = true ): array {
		$result['success'] = false;
		$result['finished_at'] = $this->now();
		$state = $this->state instanceof RussianPostPickupImportStateService ? $this->state->current() : array();
		$this->cleanup_state_files( array_merge( $state, $result ), $cleanup_tables );
		$this->settings->save_import_result( $result, false );
		$this->state?->failed( $result );
		$this->unlock();

		return $result;
	}

	private function delete_temp_file( string $temp_file ): void {
		if ( '' === $temp_file || ! is_file( $temp_file ) ) {
			return;
		}
		if ( function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $temp_file );
			return;
		}
		@unlink( $temp_file );
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function cleanup_state_files( array $state, bool $cleanup_tables = true ): void {
		$this->delete_temp_file( (string) ( $state['temp_zip_file'] ?? '' ) );
		$this->delete_temp_file( (string) ( $state['payload_file'] ?? '' ) );
		$this->delete_temp_file( (string) ( $state['extracted_payload_file'] ?? '' ) );
		if ( $cleanup_tables ) {
			$this->repository->drop_table( (string) ( $state['staging_table'] ?? '' ) );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function extract_first_payload_from_zip( string $temp_file ): array {
		$result = array(
			'success' => false,
			'payload_file' => '',
			'extracted_payload_file' => '',
			'extracted_payload_size' => 0,
			'extracted_payload_entry_name' => '',
			'extracted_payload_entry_index' => 0,
			'extract_success' => false,
			'extract_error' => '',
			'ziparchive_available' => $this->ziparchive_available(),
			'extract_backend' => 'ziparchive',
		);
		if ( ! $this->ziparchive_available() ) {
			$result['extract_error'] = 'PHP ZipArchive extension is not available.';
			return $result;
		}
		if ( ! is_file( $temp_file ) ) {
			$result['extract_error'] = 'ZIP file is missing before extract.';
			return $result;
		}

		$zip = new \ZipArchive();
		$open = $zip->open( $temp_file );
		if ( true !== $open ) {
			$result['extract_error'] = 'Unable to open ZIP archive. ZipArchive code: ' . (string) $open . ' (' . $this->zip_error_label( (int) $open ) . ').';
			return $result;
		}

		$temp_dir = '';
		try {
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$name = (string) $zip->getNameIndex( $i );
				$lower = strtolower( $name );
				if ( ! str_ends_with( $lower, '.json' ) && ! str_ends_with( $lower, '.txt' ) ) {
					continue;
				}
				if ( str_contains( $name, '..' ) ) {
					$result['extract_error'] = 'ZIP payload entry path is unsafe.';
					return $result;
				}
				$temp_dir = rtrim( sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR . 'wdc-rp-extract-' . sha1( uniqid( '', true ) );
				if ( ! is_dir( $temp_dir ) && ! @mkdir( $temp_dir, 0700, true ) ) {
					$result['extract_error'] = 'Unable to create temp extract directory.';
					return $result;
				}
				if ( true !== $zip->extractTo( $temp_dir, $name ) ) {
					$result['extract_error'] = 'ZipArchive::extractTo failed for payload entry.';
					return $result;
				}
				$source_file = $temp_dir . DIRECTORY_SEPARATOR . str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $name );
				$source_real = realpath( $source_file );
				$temp_real = realpath( $temp_dir );
				if ( false === $source_real || false === $temp_real || ! $this->is_path_inside( $source_real, $temp_real ) || ! is_file( $source_real ) ) {
					$result['extract_error'] = 'Extracted payload file is missing or unsafe.';
					return $result;
				}
				$payload_file = wp_tempnam( 'wdc-russian-post-passport-payload.json' );
				if ( ! is_string( $payload_file ) || '' === $payload_file ) {
					$result['extract_error'] = 'Unable to create temp payload file.';
					return $result;
				}
				$in = fopen( $source_real, 'rb' );
				$out = fopen( $payload_file, 'wb' );
				if ( ! is_resource( $in ) || ! is_resource( $out ) ) {
					if ( is_resource( $in ) ) {
						fclose( $in );
					}
					if ( is_resource( $out ) ) {
						fclose( $out );
					}
					$this->delete_temp_file( $payload_file );
					$result['extract_error'] = 'Unable to copy extracted payload file.';
					return $result;
				}
				try {
					stream_copy_to_stream( $in, $out );
				} finally {
					fclose( $out );
					fclose( $in );
				}

				$result['success'] = true;
				$result['payload_file'] = $payload_file;
				$result['extracted_payload_file'] = $payload_file;
				$result['extracted_payload_size'] = is_file( $payload_file ) ? (int) filesize( $payload_file ) : 0;
				$result['extracted_payload_entry_name'] = $name;
				$result['extracted_payload_entry_index'] = $i;
				$result['extract_success'] = true;
				return $result;
			}
			$result['extract_error'] = 'ZIP does not contain JSON/TXT passport payload.';
			return $result;
		} finally {
			$zip->close();
			if ( '' !== $temp_dir ) {
				$this->delete_temp_dir( $temp_dir );
			}
		}
	}

	private function ziparchive_available(): bool {
		return class_exists( \ZipArchive::class ) && empty( $GLOBALS['wdc_rp_force_ziparchive_unavailable'] );
	}

	private function is_path_inside( string $path, string $base ): bool {
		$path = rtrim( str_replace( '\\', '/', $path ), '/' );
		$base = rtrim( str_replace( '\\', '/', $base ), '/' );
		if ( $this->is_windows_path( $path ) || $this->is_windows_path( $base ) ) {
			$path = strtolower( $path );
			$base = strtolower( $base );
		}
		if ( '' === $path || '' === $base ) {
			return false;
		}

		return $path === $base || str_starts_with( $path, $base . '/' );
	}

	private function is_windows_path( string $path ): bool {
		return 1 === preg_match( '/^[a-zA-Z]:[\\\\\\/]/', $path ) || str_contains( $path, '\\' );
	}

	private function zip_error_label( int $code ): string {
		if ( ! class_exists( \ZipArchive::class ) ) {
			return 'ZipArchive unavailable';
		}
		$labels = array(
			\ZipArchive::ER_EXISTS => 'ER_EXISTS',
			\ZipArchive::ER_INCONS => 'ER_INCONS',
			\ZipArchive::ER_INVAL => 'ER_INVAL',
			\ZipArchive::ER_MEMORY => 'ER_MEMORY',
			\ZipArchive::ER_NOENT => 'ER_NOENT',
			\ZipArchive::ER_NOZIP => 'ER_NOZIP',
			\ZipArchive::ER_OPEN => 'ER_OPEN',
			\ZipArchive::ER_READ => 'ER_READ',
			\ZipArchive::ER_SEEK => 'ER_SEEK',
		);

		return $labels[ $code ] ?? 'UNKNOWN';
	}

	private function delete_temp_dir( string $dir ): void {
		if ( '' === $dir || ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( false !== $items ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$path = $dir . DIRECTORY_SEPARATOR . $item;
				if ( is_dir( $path ) ) {
					$this->delete_temp_dir( $path );
				} else {
					$this->delete_temp_file( $path );
				}
			}
		}
		@rmdir( $dir );
	}

	/**
	 * @return array{objects:array<int,string>,offset:int,eof:bool,found_array:bool}
	 */
	private function read_next_passport_objects( string $payload_file, int $offset, int $limit ): array {
		$handle = fopen( $payload_file, 'rb' );
		if ( ! is_resource( $handle ) ) {
			return array( 'objects' => array(), 'offset' => $offset, 'eof' => true, 'found_array' => false );
		}

		$objects = array();
		$found_array = $offset > 0;
		$eof = false;
		try {
			if ( $offset > 0 ) {
				fseek( $handle, $offset );
			} else {
				$found_array = $this->seek_passport_elements_array( $handle );
			}
			if ( ! $found_array ) {
				return array( 'objects' => array(), 'offset' => (int) ftell( $handle ), 'eof' => true, 'found_array' => false );
			}

			while ( ! feof( $handle ) && count( $objects ) < $limit ) {
				$char = fgetc( $handle );
				if ( false === $char ) {
					$eof = true;
					break;
				}
				if ( ']' === $char ) {
					$eof = true;
					break;
				}
				if ( '{' !== $char ) {
					continue;
				}
				$objects[] = $this->read_balanced_object( $handle, '{' );
			}

			return array( 'objects' => $objects, 'offset' => (int) ftell( $handle ), 'eof' => $eof, 'found_array' => true );
		} finally {
			fclose( $handle );
		}
	}

	private function seek_passport_elements_array( mixed $handle ): bool {
		$buffer = '';
		while ( ! feof( $handle ) ) {
			$char = fgetc( $handle );
			if ( false === $char ) {
				return false;
			}
			$buffer .= $char;
			if ( strlen( $buffer ) > 1048576 ) {
				$buffer = substr( $buffer, -1048576 );
			}
			if ( preg_match( '/"passportElements"\s*:\s*\[\s*$/s', $buffer ) ) {
				return true;
			}
		}

		return false;
	}

	private function read_balanced_object( mixed $handle, string $first_char ): string {
		$object = $first_char;
		$depth = 1;
		$in_string = false;
		$escaped = false;
		while ( ! feof( $handle ) && $depth > 0 ) {
			$char = fgetc( $handle );
			if ( false === $char ) {
				break;
			}
			$object .= $char;
			if ( $in_string ) {
				if ( $escaped ) {
					$escaped = false;
					continue;
				}
				if ( '\\' === $char ) {
					$escaped = true;
					continue;
				}
				if ( '"' === $char ) {
					$in_string = false;
				}
				continue;
			}
			if ( '"' === $char ) {
				$in_string = true;
				continue;
			}
			if ( '{' === $char ) {
				++$depth;
				continue;
			}
			if ( '}' === $char ) {
				--$depth;
			}
		}

		return $object;
	}

	/**
	 * @param array<int,string> $errors
	 */
	private function add_limited_error( array &$errors, string $message ): void {
		if ( count( $errors ) >= self::MAX_STORED_ERRORS ) {
			return;
		}
		$errors[] = $message;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function base_result( string $type, string $import_id ): array {
		return array(
			'success' => false,
			'import_id' => $import_id,
			'type' => $type,
			'source' => 'api_download',
			'original_upload_name' => '',
			'uploaded_file_size' => 0,
			'downloaded' => 0,
			'download_url' => '',
			'download_started_at' => '',
			'download_duration_ms' => 0,
			'download_http_code' => 0,
			'download_response_message' => '',
			'download_error' => '',
			'download_backend' => '',
			'fallback_used' => false,
			'first_backend_error' => '',
			'curl_errno' => 0,
			'curl_error' => '',
			'temp_file_size' => 0,
			'extract_started_at' => '',
			'extract_zip_file' => '',
			'extract_zip_size' => 0,
			'ziparchive_available' => false,
			'extract_backend' => '',
			'extract_duration_ms' => 0,
			'extract_error' => '',
			'extract_success' => false,
			'extracted_payload_file' => '',
			'extracted_payload_size' => 0,
			'extracted_payload_entry_name' => '',
			'extracted_payload_entry_index' => 0,
			'parsed' => 0,
			'inserted' => 0,
			'updated' => 0,
			'deactivated' => 0,
			'rows_inserted_to_staging' => 0,
			'location_matched_fias' => 0,
			'location_matched_postal_code' => 0,
			'location_matched_region_city' => 0,
			'location_match_no_match' => 0,
			'location_match_ambiguous' => 0,
			'skipped' => 0,
			'staging_table' => '',
			'main_table' => $this->repository->main_table(),
			'backup_table' => '',
			'swap_started_at' => '',
			'swap_finished_at' => '',
			'payload_file' => '',
			'payload_size' => 0,
			'temp_zip_file' => '',
			'payload_offset' => 0,
			'objects_processed' => 0,
			'batches_processed' => 0,
			'current_batch_size' => 0,
			'last_batch_duration_ms' => 0,
			'max_batch_duration_ms' => 0,
			'parser_completed' => false,
			'errors' => array(),
			'started_at' => $this->now(),
			'finished_at' => '',
		);
	}

	/**
	 * @param array<string,mixed> $download
	 * @return array<string,mixed>
	 */
	private function download_result_state( array $download ): array {
		return array(
			'download_url' => (string) ( $download['url'] ?? '' ),
			'download_duration_ms' => (int) ( $download['duration_ms'] ?? 0 ),
			'download_http_code' => (int) ( $download['http_code'] ?? 0 ),
			'download_response_message' => (string) ( $download['response_message'] ?? '' ),
			'download_error' => (string) ( $download['wp_error_message'] ?? ( $download['error'] ?? '' ) ),
			'download_backend' => (string) ( $download['download_backend'] ?? '' ),
			'fallback_used' => ! empty( $download['fallback_used'] ),
			'first_backend_error' => (string) ( $download['first_backend_error'] ?? '' ),
			'curl_errno' => (int) ( $download['curl_errno'] ?? 0 ),
			'curl_error' => (string) ( $download['curl_error'] ?? '' ),
			'temp_file_size' => (int) ( $download['temp_file_size'] ?? 0 ),
			'downloaded' => (int) ( $download['temp_file_size'] ?? 0 ),
		);
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private function state_to_result( array $state, string $type, string $import_id ): array {
		$result = $this->base_result( $type, $import_id );
		foreach ( $result as $key => $value ) {
			if ( array_key_exists( $key, $state ) ) {
				$result[ $key ] = $state[ $key ];
			}
		}
		$result['type'] = $type;
		$result['import_id'] = $import_id;

		return $result;
	}

	/**
	 * @return array<string,int>
	 */
	private function empty_location_match_stats(): array {
		return array(
			'location_matched_fias' => 0,
			'location_matched_postal_code' => 0,
			'location_matched_region_city' => 0,
			'location_match_no_match' => 0,
			'location_match_ambiguous' => 0,
		);
	}

	/**
	 * @param array<string,int> $stats
	 * @param array{status:string,strategy:string,location_id:int|null,location:mixed} $match
	 */
	private function increment_location_match_stats( array &$stats, array $match ): void {
		if ( 'ambiguous' === (string) ( $match['status'] ?? '' ) ) {
			++$stats['location_match_ambiguous'];
			return;
		}
		if ( 'unique' !== (string) ( $match['status'] ?? '' ) ) {
			++$stats['location_match_no_match'];
			return;
		}

		$key = match ( (string) ( $match['strategy'] ?? '' ) ) {
			'fias' => 'location_matched_fias',
			'postal_code' => 'location_matched_postal_code',
			'region_city' => 'location_matched_region_city',
			default => 'location_match_no_match',
		};
		++$stats[ $key ];
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function normalize_type( string $type ): string {
		$type = strtoupper( trim( $type ) );

		return in_array( $type, array( 'ALL', 'OPS', 'PVZ', 'APS' ), true ) ? $type : 'ALL';
	}
}
