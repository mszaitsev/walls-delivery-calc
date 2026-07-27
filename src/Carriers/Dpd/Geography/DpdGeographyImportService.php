<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use Throwable;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyImportService {
	private const DEFAULT_STEP_LIMIT = 3000;
	private const INDEX_FORMAT_VERSION = 2;
	private const LOCK_BUSY_RETRY_MS = 1500;
	private const STEP_LOCK_TTL_SECONDS = 600;
	private const START_LOCK_TTL_SECONDS = 1800;
	private const RUNNER_PROTOCOL_VERSION = 1;

	public function __construct(
		private DpdGeographyCsvParser $parser,
		private DpdGeographyMatcher $matcher,
		private DpdLocationIndex $index,
		private DpdGeographyImportStateService $state,
		private DpdGeographyStageRepository $stage,
		private LocationRepository $locations,
		private LocationDeliveryCodeRepository $delivery_codes,
		private ?DpdSettings $settings = null,
		private ?DpdGeographyImportLockService $lock = null
	) {
		$this->lock ??= new DpdGeographyImportLockService();
	}

	/**
	 * Backward-compatible synchronous wrapper for smoke tests and CLI diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public function import_file( string $path, string $source, string $source_file ): array {
		$job = $this->run_locked_start(
			$source,
			fn(): array => $this->start_from_existing_file_unlocked( $path, $source, $source_file, false )
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
				$download = $ftp->download_latest();
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
			return $this->step_unlocked( $state, max( 1, $limit ) );
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
		$index_path = (string) ( $state['index_path'] ?? '' );
		if ( '' === $index_path || ! file_exists( $index_path ) ) {
			return $this->fail_with_report( 'DPD geography location index is missing.' );
		}
		$stage_table = (string) ( $state['stage_table'] ?? '' );
		if ( '' === $stage_table || ! $this->stage->exists( $stage_table ) ) {
			return $this->fail_with_report( 'DPD geography staging table is missing.' );
		}

		try {
			$this->load_location_index_from_state( $state );
		} catch ( \RuntimeException $exception ) {
			return $this->fail_with_report( $exception->getMessage() );
		}
		$columns = is_array( $state['columns'] ?? null ) ? $state['columns'] : array();
		try {
			$step = $this->parser->read_step( $file, $start_offset, $columns, $limit );
		} catch ( Throwable $throwable ) {
			return $this->fail_with_report( 'DPD geography CSV parse failed: ' . $throwable->getMessage() );
		}
		$patch = array(
			'phase' => 'importing',
			'byte_offset' => (int) $step['new_byte_offset'],
			'rows_read' => (int) $state['rows_read'] + (int) $step['rows_read_count'],
			'last_message' => 'DPD geography import is processing CSV rows.',
		);
		foreach ( $step['rows'] as $row ) {
			$this->process_row( $stage_table, $row, $patch );
		}

		$current = $this->state->current();
		if (
			$start_job_id !== (string) ( $current['job_id'] ?? '' )
			|| ! in_array( (string) ( $current['phase'] ?? '' ), array( 'ready', 'importing' ), true )
			|| $start_offset !== (int) ( $current['byte_offset'] ?? 0 )
		) {
			return $this->with_step_control( $this->state->public_state(), 'stale' );
		}
		$state = $this->state->update( $patch );
		if ( ! empty( $step['eof'] ) ) {
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
		$token = $this->lock?->acquire( (string) ( $current['job_id'] ?? 'reset' ), self::STEP_LOCK_TTL_SECONDS );
		if ( null === $token ) {
			$state = $this->with_operation_control( $this->state->public_state(), 'busy' );
			$state['last_message'] = 'DPD geography import step is still running. Try reset again in a few seconds.';
			return $state;
		}
		try {
			$current = $this->state->current();
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
	private function start_from_existing_file( string $path, string $source, string $source_file, bool $delete_on_finish ): array {
		return $this->run_locked_start(
			$source,
			fn(): array => $this->start_from_existing_file_unlocked( $path, $source, $source_file, $delete_on_finish )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function start_from_existing_file_unlocked( string $path, string $source, string $source_file, bool $delete_on_finish ): array {
		$index_path = '';
		$stage_table = '';
		try {
			$inspect = $this->parser->inspect_header( $path );
			$this->index->build();
			$index_data = DpdLocationIndex::validate_export( $this->index->export() );
			$index_path = $this->temp_path( 'index-' . $source_file . '.ser' );
			$index_metadata = $this->persist_location_index( $index_path, $index_data );
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
					'index_path' => $index_path,
					'stage_table' => $stage_table,
					'index_format_version' => (int) $index_metadata['index_format_version'],
					'index_size' => (int) $index_metadata['index_size'],
					'index_sha256' => (string) $index_metadata['index_sha256'],
					'index_stats' => $index_metadata['index_stats'],
					'runner_protocol_version' => self::RUNNER_PROTOCOL_VERSION,
					'delete_file_on_finish' => $delete_on_finish,
					'file_size' => is_file( $path ) ? (int) filesize( $path ) : 0,
					'total_rows' => 0,
					'byte_offset' => (int) $inspect['data_offset'],
					'columns' => $inspect['columns'],
					'last_message' => $delete_on_finish ? 'DPD geography import job created.' : 'DPD geography import job created for existing file.',
				)
			);

			return $this->state->public_state();
		} catch ( Throwable $throwable ) {
			return $this->fail_with_report(
				'DPD geography import start failed: ' . $throwable->getMessage(),
				true,
				array( 'source' => $source, 'source_file' => $source_file, 'file_path' => $path, 'index_path' => $index_path, 'stage_table' => $stage_table, 'delete_file_on_finish' => $delete_on_finish, 'file_size' => is_file( $path ) ? (int) filesize( $path ) : 0 )
			);
		}
	}

	/**
	 * @param array<string,string> $row
	 * @param array<string,mixed> $patch
	 */
	private function process_row( string $stage_table, array $row, array &$patch ): void {
		$country = strtoupper( trim( (string) ( $row['country_code'] ?? '' ) ) );
		if ( 'RU' !== $country ) {
			if ( in_array( $country, array( 'AM', 'BY', 'KZ', 'KG' ), true ) ) {
				$this->process_foreign_row( $stage_table, $row, $patch, $country );
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
		$match = $this->matcher->match( $row );
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
		$result = $this->stage->upsert_candidate( $stage_table, $location_id, $dpd_city_id, $method );
		if ( 'inserted' === $result ) {
			$this->inc( $patch, 'saved_candidates' );
			return;
		}
		if ( 'unchanged' === $result ) {
			$this->inc( $patch, 'unchanged_mappings' );
			return;
		}
		if ( 'conflict' === $result ) {
			$this->inc( $patch, 'conflicts' );
			return;
		}
		$errors = is_array( $patch['errors'] ?? null ) ? $patch['errors'] : array();
		$errors[] = 'Failed to stage mapping for location_id=' . $location_id;
		$patch['errors'] = $errors;
		$this->inc( $patch, 'errors_total' );
	}

	/**
	 * @param array<string,string> $row
	 * @param array<string,mixed> $patch
	 */
	private function process_foreign_row( string $stage_table, array $row, array &$patch, string $country ): void {
		$this->inc( $patch, 'foreign_rows' );
		$this->inc( $patch, 'foreign_' . strtolower( $country ) . '_rows' );
		$dpd_city_id = preg_replace( '/\D+/', '', (string) ( $row['dpd_city_id'] ?? '' ) ) ?? '';
		$place = trim( (string) ( $row['settlement'] ?? '' ) );
		if ( '' === $dpd_city_id || '0' === $dpd_city_id || '' === $place ) {
			$this->inc( $patch, 'skipped_invalid' );
			return;
		}

		$region = trim( (string) ( $row['region'] ?? '' ) );
		$district = trim( (string) ( $row['district'] ?? '' ) );
		$place_type = $this->normalize_foreign_place_type( (string) ( $row['settlement_type'] ?? '' ) );
		$region_type = $this->foreign_region_type( $region, $place, $place_type );
		$district_type = '' !== $district ? 'р-н' : '';
		$is_city = $this->foreign_place_type_is_city( $place_type );

		try {
			$mapped_location_id = $this->delivery_codes->find_location_id_by_dpd_city_id( $dpd_city_id );
			$mapped_existing = null !== $mapped_location_id ? $this->locations->find_by_id( $mapped_location_id ) : null;
			$resolution = $this->resolve_foreign_canonical_location( $country, $place, $region, $district, $place_type, $mapped_location_id );
			$existing = $resolution['location'] instanceof Location ? $resolution['location'] : $mapped_existing;
			if ( (int) $resolution['match_count'] > 1 ) {
				$this->inc( $patch, 'foreign_duplicate_identity_rows' );
			}
			if ( ! $existing instanceof Location ) {
				$existing = $this->locations->find_legacy_foreign_by_place_identity( $country, $place, $region );
			}

			$location = Location::from_array(
				array(
					'id' => $existing?->id,
					'country_code' => $country,
					'region_name' => $region,
					'region_type' => $region_type,
					'district_name' => $district,
					'district_type' => $district_type,
					'city_name' => $is_city ? $place : '',
					'city_type' => $is_city ? 'г' : '',
					'settlement_name' => $place,
					'settlement_type' => $place_type,
					'place_name' => $place,
					'place_type' => $place_type,
					'place_level' => 0,
					'display_name' => $this->foreign_display_name( $country, $region, $region_type, $district, $district_type, $place, $place_type ),
					'postal_code' => '',
					'russianpost_courier_calc_postal_code' => '',
					'fias_id' => '',
					'gar_object_id' => 0,
					'gar_id' => '',
					'kladr_id' => '',
					'latitude' => null,
					'longitude' => null,
					'active' => true,
				)
			);
			if ( array() !== $location->validate() ) {
				$this->inc( $patch, 'skipped_invalid' );
				return;
			}

			$saved_id = $this->locations->save( $location );
		} catch ( \RuntimeException $exception ) {
			$this->inc( $patch, 'foreign_save_failed' );
			$this->add_error(
				$patch,
				sprintf(
					'Failed to resolve or save foreign DPD location for dpd_city_id=%s country_code=%s place_name=%s: %s',
					$dpd_city_id,
					$country,
					$place,
					$this->sanitize_error( $exception->getMessage() )
				)
			);
			return;
		}
		if ( $saved_id <= 0 ) {
			$this->inc( $patch, 'foreign_save_failed' );
			$this->add_error( $patch, 'Failed to save foreign DPD location for dpd_city_id=' . $dpd_city_id . ' country_code=' . $country . ' place_name=' . $place . ': missing saved id' );
			return;
		}
		$result = $this->stage->upsert_candidate( $stage_table, $saved_id, $dpd_city_id, 'foreign' );
		if ( 'conflict' === $result ) {
			$this->inc( $patch, 'foreign_mapping_conflicts' );
			$this->inc( $patch, 'conflicts' );
			return;
		}
		if ( ! in_array( $result, array( 'inserted', 'unchanged' ), true ) ) {
			$this->add_error( $patch, 'Failed to stage foreign DPD mapping for dpd_city_id=' . $dpd_city_id . ' country_code=' . $country . ' place_name=' . $place );
			return;
		}
		$this->inc( $patch, 'inserted' === $result ? 'saved_candidates' : 'unchanged_mappings' );

		$this->inc( $patch, null === $existing ? 'foreign_locations_inserted' : 'foreign_locations_updated' );
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
	 * @return array{location:?Location,duplicate_ids:array<int,int>,match_count:int,method:string}
	 */
	private function resolve_foreign_canonical_location( string $country, string $place, string $region, string $district, string $place_type, ?int $mapped_location_id ): array {
		$matches = $this->locations->find_foreign_by_place_identity_matches( $country, $place, $region, $district, $place_type );
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
	 * @param array<string,mixed> $index_data
	 * @return array{index_format_version:int,index_size:int,index_sha256:string,index_stats:array<string,int>}
	 */
	private function persist_location_index( string $index_path, array $index_data ): array {
		$validated = DpdLocationIndex::validate_export( $index_data );
		$serialized = serialize( $validated );
		if ( '' === $serialized ) {
			throw new \RuntimeException( 'DPD geography location index persistence failed: empty payload.' );
		}
		$expected_size = strlen( $serialized );
		$written = file_put_contents( $index_path, $serialized, LOCK_EX );
		if ( false === $written || (int) $written !== $expected_size ) {
			throw new \RuntimeException( 'DPD geography location index persistence failed: incomplete write.' );
		}
		clearstatcache( true, $index_path );
		if ( ! file_exists( $index_path ) || ! is_readable( $index_path ) ) {
			throw new \RuntimeException( 'DPD geography location index persistence failed: written file is not readable.' );
		}
		$actual_size = filesize( $index_path );
		if ( false === $actual_size || (int) $actual_size !== $expected_size ) {
			throw new \RuntimeException( 'DPD geography location index persistence failed: size mismatch.' );
		}
		$expected_hash = hash( 'sha256', $serialized );
		$actual_hash = hash_file( 'sha256', $index_path );
		if ( ! is_string( $actual_hash ) || 64 !== strlen( $actual_hash ) || ! hash_equals( $expected_hash, $actual_hash ) ) {
			throw new \RuntimeException( 'DPD geography location index persistence failed: checksum mismatch.' );
		}

		return array(
			'index_format_version' => self::INDEX_FORMAT_VERSION,
			'index_size' => $expected_size,
			'index_sha256' => $actual_hash,
			'index_stats' => $this->index_stats( $validated ),
		);
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function load_location_index_from_state( array $state ): void {
		$prefix = 'DPD geography location index validation failed: ';
		$index_path = (string) ( $state['index_path'] ?? '' );
		$expected_size = max( 0, (int) ( $state['index_size'] ?? 0 ) );
		$expected_hash = (string) ( $state['index_sha256'] ?? '' );
		$expected_version = max( 0, (int) ( $state['index_format_version'] ?? 0 ) );
		$expected_stats = is_array( $state['index_stats'] ?? null ) ? $state['index_stats'] : array();
		if ( '' === $index_path || ! file_exists( $index_path ) || ! is_readable( $index_path ) ) {
			throw new \RuntimeException( $prefix . 'file is missing.' );
		}
		if ( self::INDEX_FORMAT_VERSION !== $expected_version ) {
			throw new \RuntimeException( $prefix . 'unsupported format version.' );
		}
		if ( $expected_size <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', $expected_hash ) ) {
			throw new \RuntimeException( $prefix . 'missing integrity metadata.' );
		}
		$raw = file_get_contents( $index_path );
		if ( ! is_string( $raw ) || '' === $raw ) {
			throw new \RuntimeException( $prefix . 'empty file.' );
		}
		if ( strlen( $raw ) !== $expected_size ) {
			throw new \RuntimeException( $prefix . 'size mismatch.' );
		}
		$actual_hash = hash( 'sha256', $raw );
		if ( ! hash_equals( $expected_hash, $actual_hash ) ) {
			throw new \RuntimeException( $prefix . 'checksum mismatch.' );
		}
		$loaded = unserialize( $raw, array( 'allowed_classes' => false ) );
		if ( ! is_array( $loaded ) ) {
			throw new \RuntimeException( $prefix . 'invalid serialized payload.' );
		}
		try {
			$validated = DpdLocationIndex::validate_export( $loaded );
		} catch ( \InvalidArgumentException $exception ) {
			throw new \RuntimeException( $prefix . $this->sanitize_error( $exception->getMessage() ) );
		}
		if ( $this->index_stats( $validated ) !== $this->normalize_index_stats( $expected_stats ) ) {
			throw new \RuntimeException( $prefix . 'stats mismatch.' );
		}

		$this->index->load( $validated );
	}

	/**
	 * @param array<string,mixed> $index_data
	 * @return array<string,int>
	 */
	private function index_stats( array $index_data ): array {
		$own_fias_keys = count( $index_data['own_fias'] ?? array() );
		$city_fias_keys = count( $index_data['city_fias'] ?? array() );

		return array(
			'fias_keys' => $own_fias_keys + $city_fias_keys,
			'own_fias_keys' => $own_fias_keys,
			'city_fias_keys' => $city_fias_keys,
			'kladr_keys' => count( $index_data['kladr'] ?? array() ),
			'name_keys' => count( $index_data['name'] ?? array() ),
		);
	}

	/**
	 * @param array<string,mixed> $stats
	 * @return array<string,int>
	 */
	private function normalize_index_stats( array $stats ): array {
		return array(
			'fias_keys' => max( 0, (int) ( $stats['fias_keys'] ?? 0 ) ),
			'own_fias_keys' => max( 0, (int) ( $stats['own_fias_keys'] ?? 0 ) ),
			'city_fias_keys' => max( 0, (int) ( $stats['city_fias_keys'] ?? 0 ) ),
			'kladr_keys' => max( 0, (int) ( $stats['kladr_keys'] ?? 0 ) ),
			'name_keys' => max( 0, (int) ( $stats['name_keys'] ?? 0 ) ),
		);
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
