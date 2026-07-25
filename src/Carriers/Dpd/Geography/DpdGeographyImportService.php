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

	public function __construct(
		private DpdGeographyCsvParser $parser,
		private DpdGeographyMatcher $matcher,
		private DpdLocationIndex $index,
		private DpdGeographyImportStateService $state,
		private DpdGeographyStageRepository $stage,
		private LocationRepository $locations,
		private LocationDeliveryCodeRepository $delivery_codes,
		private ?DpdSettings $settings = null
	) {
	}

	/**
	 * Backward-compatible synchronous wrapper for smoke tests and CLI diagnostics.
	 *
	 * @return array<string,mixed>
	 */
	public function import_file( string $path, string $source, string $source_file ): array {
		$job = $this->start_from_existing_file( $path, $source, $source_file, false );
		while ( in_array( (string) ( $job['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
			$job = $this->step( (string) $job['job_id'], 10000 );
		}

		return $this->report_from_state( $job );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function start_from_uploaded_file( array $file ): array {
		if ( (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return $this->state->fail( 'DPD geography manual import: CSV upload failed.' );
		}
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		$name = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( (string) ( $file['name'] ?? 'manual.csv' ) ) : basename( (string) ( $file['name'] ?? 'manual.csv' ) );
		if ( '' === $tmp || ! file_exists( $tmp ) || ! str_ends_with( strtolower( $name ), '.csv' ) ) {
			return $this->state->fail( 'DPD geography manual import: upload must be a CSV file.' );
		}

		$target = $this->copy_to_import_temp( $tmp, $name );
		@unlink( $tmp );

		return $this->start_from_existing_file( $target, 'manual', $name, true );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function start_from_ftp( DpdGeographyFtpClient $ftp ): array {
		$download = $ftp->download_latest();
		if ( 'warning' === (string) ( $download['status'] ?? '' ) ) {
			$current = $this->state->public_state();
			$current['status'] = 'warning';
			$current['last_message'] = (string) $download['message'];
			return $current;
		}
		if ( empty( $download['success'] ) ) {
			return $this->state->fail( (string) $download['message'] );
		}

		$this->state->update( array( 'phase' => 'downloading', 'last_message' => 'Downloading DPD GeographyNewDPD CSV from SFTP.' ) );
		return $this->start_from_existing_file( (string) $download['path'], 'ftp', (string) $download['source_file'], true );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function step( string $job_id = '', int $limit = self::DEFAULT_STEP_LIMIT ): array {
		$state = $this->state->current();
		if ( '' !== $job_id && $job_id !== (string) ( $state['job_id'] ?? '' ) ) {
			return $this->state->fail( 'DPD geography import job_id is stale.' );
		}
		if ( ! in_array( (string) ( $state['phase'] ?? '' ), array( 'ready', 'importing' ), true ) ) {
			return $this->state->public_state();
		}
		$file = (string) ( $state['file_path'] ?? '' );
		if ( '' === $file || ! file_exists( $file ) ) {
			return $this->state->fail( 'DPD geography import file is missing.' );
		}
		$index_path = (string) ( $state['index_path'] ?? '' );
		if ( '' === $index_path || ! file_exists( $index_path ) ) {
			return $this->state->fail( 'DPD geography location index is missing.' );
		}
		$stage_table = (string) ( $state['stage_table'] ?? '' );
		if ( '' === $stage_table || ! $this->stage->exists( $stage_table ) ) {
			return $this->state->fail( 'DPD geography staging table is missing.' );
		}

		$loaded = unserialize( (string) file_get_contents( $index_path ), array( 'allowed_classes' => false ) );
		$this->index->load( is_array( $loaded ) ? $loaded : array() );
		$columns = is_array( $state['columns'] ?? null ) ? $state['columns'] : array();
		try {
			$step = $this->parser->read_step( $file, (int) $state['byte_offset'], $columns, $limit );
		} catch ( Throwable $throwable ) {
			return $this->state->fail( 'DPD geography CSV parse failed: ' . $throwable->getMessage() );
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
		return $this->state->public_state();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function reset(): array {
		$current = $this->state->current();
		$stage_table = (string) ( $current['stage_table'] ?? '' );
		if ( '' !== $stage_table ) {
			$this->stage->drop( $stage_table );
		}
		$this->state->reset();
		return $this->state->public_state();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function start_from_existing_file( string $path, string $source, string $source_file, bool $delete_on_finish ): array {
		try {
			$inspect = $this->parser->inspect_header( $path );
			$this->index->build();
			$index_path = $this->temp_path( 'index-' . $source_file . '.ser' );
			file_put_contents( $index_path, serialize( $this->index->export() ) );
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
			return $this->state->fail( 'DPD geography import start failed: ' . $throwable->getMessage() );
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
		$location_id = $this->delivery_codes->find_location_id_by_dpd_city_id( $dpd_city_id );
		$existing = null !== $location_id ? $this->locations->find_by_id( $location_id ) : null;
		if ( ! $existing instanceof Location ) {
			$existing = $this->locations->find_foreign_by_place_identity( $country, $place, $region, $district, $place_type );
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

		try {
			$saved_id = $this->locations->save( $location );
		} catch ( \RuntimeException $exception ) {
			$this->inc( $patch, 'foreign_save_failed' );
			$this->add_error(
				$patch,
				sprintf(
					'Failed to save foreign DPD location for dpd_city_id=%s country_code=%s place_name=%s: %s',
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

	private function foreign_display_name( string $country, string $region, string $region_type, string $district, string $district_type, string $place, string $place_type ): string {
		$parts = array( $country );
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
			return $this->state->fail( 'DPD geography staging table is missing during finalization.' );
		}
		try {
			$finalized = $this->stage->finalize_into_delivery_codes( $stage_table );
		} catch ( \RuntimeException $exception ) {
			$failed = $this->state->fail( 'DPD geography finalization failed: ' . $this->sanitize_error( $exception->getMessage() ) );
			$this->settings?->save_geography_import_report( $this->report_from_state( $failed ) );
			return $failed;
		}
		$finalized_mappings = is_array( $finalized ) ? (int) ( $finalized['mappings'] ?? 0 ) : (int) $finalized;
		$finalized_changes = is_array( $finalized ) ? (int) ( $finalized['changes'] ?? 0 ) : (int) $finalized;
		$state = $this->state->update(
			array(
				'phase' => 'finalizing',
				'finalized_mappings' => $finalized_mappings,
				'finalized_changes' => $finalized_changes,
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

		if ( (int) ( $state['foreign_save_failed'] ?? 0 ) > 0 || (int) ( $state['errors_total'] ?? 0 ) > 0 ) {
			$warning_state = $this->state->finish(
				sprintf(
					'DPD geography import finished with %d errors.',
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
			'skipped_non_ru' => (int) ( $state['skipped_non_ru'] ?? 0 ),
			'skipped_invalid' => (int) ( $state['skipped_invalid'] ?? 0 ),
			'matched_by_fias' => (int) ( $state['matched_by_fias'] ?? 0 ),
			'matched_by_kladr' => (int) ( $state['matched_by_kladr'] ?? 0 ),
			'matched_by_name' => (int) ( $state['matched_by_name'] ?? 0 ),
			'saved_candidates' => (int) ( $state['saved_candidates'] ?? 0 ),
			'finalized_mappings' => (int) ( $state['finalized_mappings'] ?? 0 ),
			'finalized_changes' => (int) ( $state['finalized_changes'] ?? 0 ),
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

	private function copy_to_import_temp( string $source, string $name ): string {
		$target = $this->temp_path( $name );
		if ( ! copy( $source, $target ) ) {
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
