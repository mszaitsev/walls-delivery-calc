<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use Throwable;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyImportService {
	private const DEFAULT_STEP_LIMIT = 3000;

	public function __construct(
		private DpdGeographyCsvParser $parser,
		private DpdGeographyMatcher $matcher,
		private DpdLocationIndex $index,
		private DpdGeographyImportStateService $state,
		private DpdGeographyStageRepository $stage,
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
		if ( 'RU' !== strtoupper( trim( (string) ( $row['country_code'] ?? '' ) ) ) ) {
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
		$finalized = $this->stage->finalize_into_delivery_codes( $stage_table );
		$state = $this->state->update(
			array(
				'phase' => 'finalizing',
				'finalized_mappings' => $finalized,
				'last_message' => 'DPD geography import is finalizing mappings.',
			)
		);
		$report = $this->report_from_state( $state );
		$this->settings?->save_geography_import_report( $report );
		$file = (string) ( $state['file_path'] ?? '' );
		if ( ! empty( $state['delete_file_on_finish'] ) && '' !== $file && file_exists( $file ) ) {
			@unlink( $file );
		}
		$index_file = (string) ( $state['index_path'] ?? '' );
		if ( '' !== $index_file && file_exists( $index_file ) ) {
			@unlink( $index_file );
		}
		$this->stage->drop( $stage_table );

		return $this->state->finish( 'DPD geography import finished.' );
	}

	/**
	 * @param array<string,mixed> $state
	 * @return array<string,mixed>
	 */
	private function report_from_state( array $state ): array {
		return array(
			'source' => (string) ( $state['source'] ?? '' ),
			'source_file' => (string) ( $state['source_file'] ?? '' ),
			'file_size' => (int) ( $state['file_size'] ?? 0 ),
			'total_rows' => (int) ( $state['total_rows'] ?? 0 ),
			'ru_rows' => (int) ( $state['ru_rows'] ?? 0 ),
			'skipped_non_ru' => (int) ( $state['skipped_non_ru'] ?? 0 ),
			'skipped_invalid' => (int) ( $state['skipped_invalid'] ?? 0 ),
			'matched_by_fias' => (int) ( $state['matched_by_fias'] ?? 0 ),
			'matched_by_kladr' => (int) ( $state['matched_by_kladr'] ?? 0 ),
			'matched_by_name' => (int) ( $state['matched_by_name'] ?? 0 ),
			'saved_candidates' => (int) ( $state['saved_candidates'] ?? 0 ),
			'finalized_mappings' => (int) ( $state['finalized_mappings'] ?? 0 ),
			'unchanged_mappings' => (int) ( $state['unchanged_mappings'] ?? 0 ),
			'conflicts' => (int) ( $state['conflicts'] ?? 0 ),
			'ambiguous' => (int) ( $state['ambiguous'] ?? 0 ),
			'unmatched' => (int) ( $state['unmatched'] ?? 0 ),
			'errors' => is_array( $state['errors'] ?? null ) ? $state['errors'] : array(),
			'started_at' => (string) ( $state['started_at'] ?? '' ),
			'finished_at' => (string) ( $state['finished_at'] ?? ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) ) ),
		);
	}

	/**
	 * @param array<string,mixed> $patch
	 */
	private function inc( array &$patch, string $key ): void {
		$patch[ $key ] = max( 0, (int) ( $patch[ $key ] ?? $this->state->current()[ $key ] ?? 0 ) + 1 );
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
