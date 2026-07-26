<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyImportStateService {
	public const OPTION_NAME = 'wdc_dpd_geography_import_state';
	private const MAX_ERRORS = 20;

	/**
	 * @return array<string,mixed>
	 */
	public function current(): array {
		$state = get_option( self::OPTION_NAME, array() );
		return array_merge( $this->defaults(), is_array( $state ) ? $state : array() );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function public_state(): array {
		$state = $this->current();
		unset(
			$state['file_path'],
			$state['index_path'],
			$state['stage_table'],
			$state['delete_file_on_finish'],
			$state['columns'],
			$state['index_format_version'],
			$state['index_size'],
			$state['index_sha256'],
			$state['index_stats']
		);
		$file_size = max( 0, (int) ( $state['file_size'] ?? 0 ) );
		$byte_offset = max( 0, (int) ( $state['byte_offset'] ?? 0 ) );
		$state['percent_complete'] = $file_size > 0 ? min( 100, round( $byte_offset / $file_size * 100, 1 ) ) : 0;

		return $state;
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function start( array $context ): array {
		$now = $this->now();
		$current = $this->current();
		$state = array_merge(
			$this->defaults(),
			array(
				'job_id' => (string) ( $context['job_id'] ?? $this->new_job_id() ),
				'state_revision' => max( 0, (int) ( $current['state_revision'] ?? 0 ) ) + 1,
				'phase' => (string) ( $context['phase'] ?? 'ready' ),
				'status' => '',
				'source' => (string) ( $context['source'] ?? 'manual' ),
				'source_file' => (string) ( $context['source_file'] ?? '' ),
				'runner_protocol_version' => max( 0, (int) ( $context['runner_protocol_version'] ?? 0 ) ),
				'file_path' => (string) ( $context['file_path'] ?? '' ),
				'index_path' => (string) ( $context['index_path'] ?? '' ),
				'stage_table' => (string) ( $context['stage_table'] ?? '' ),
				'index_format_version' => max( 0, (int) ( $context['index_format_version'] ?? 0 ) ),
				'index_size' => max( 0, (int) ( $context['index_size'] ?? 0 ) ),
				'index_sha256' => (string) ( $context['index_sha256'] ?? '' ),
				'index_stats' => is_array( $context['index_stats'] ?? null ) ? $context['index_stats'] : array(),
				'delete_file_on_finish' => (bool) ( $context['delete_file_on_finish'] ?? true ),
				'file_size' => max( 0, (int) ( $context['file_size'] ?? 0 ) ),
				'total_rows' => max( 0, (int) ( $context['total_rows'] ?? 0 ) ),
				'byte_offset' => max( 0, (int) ( $context['byte_offset'] ?? 0 ) ),
				'columns' => is_array( $context['columns'] ?? null ) ? $context['columns'] : array(),
				'started_at' => $now,
				'updated_at' => $now,
				'last_message' => (string) ( $context['last_message'] ?? 'Import job created.' ),
			)
		);
		$this->save( $state );

		return $state;
	}

	/**
	 * @param array<string,mixed> $patch
	 * @return array<string,mixed>
	 */
	public function update( array $patch ): array {
		$current = $this->current();
		$state = array_merge( $current, $patch );
		$state['state_revision'] = max( 0, (int) ( $current['state_revision'] ?? 0 ) ) + 1;
		$state['updated_at'] = $this->now();
		if ( isset( $state['errors'] ) && is_array( $state['errors'] ) ) {
			$state['errors'] = array_slice( array_map( 'strval', $state['errors'] ), -self::MAX_ERRORS );
		}
		$this->save( $state );

		return $state;
	}

	public function fail( string $message ): array {
		$state = $this->current();
		$errors = is_array( $state['errors'] ?? null ) ? $state['errors'] : array();
		$errors[] = $message;
		$errors_total = max( 0, (int) ( $state['errors_total'] ?? 0 ) ) + 1;

		return $this->update(
			array(
				'phase' => 'failed',
				'status' => 'error',
				'errors' => $errors,
				'errors_total' => $errors_total,
				'last_message' => $message,
				'finished_at' => $this->now(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function fail_new( string $message, array $context = array() ): array {
		$now = $this->now();
		$current = $this->current();
		$state = array_merge(
			$this->defaults(),
			array(
				'job_id' => (string) ( $context['job_id'] ?? $this->new_job_id() ),
				'state_revision' => max( 0, (int) ( $current['state_revision'] ?? 0 ) ) + 1,
				'phase' => 'failed',
				'status' => 'error',
				'source' => (string) ( $context['source'] ?? '' ),
				'source_file' => (string) ( $context['source_file'] ?? '' ),
				'runner_protocol_version' => max( 0, (int) ( $context['runner_protocol_version'] ?? 0 ) ),
				'file_path' => (string) ( $context['file_path'] ?? '' ),
				'index_path' => (string) ( $context['index_path'] ?? '' ),
				'stage_table' => (string) ( $context['stage_table'] ?? '' ),
				'index_format_version' => max( 0, (int) ( $context['index_format_version'] ?? 0 ) ),
				'index_size' => max( 0, (int) ( $context['index_size'] ?? 0 ) ),
				'index_sha256' => (string) ( $context['index_sha256'] ?? '' ),
				'index_stats' => is_array( $context['index_stats'] ?? null ) ? $context['index_stats'] : array(),
				'delete_file_on_finish' => (bool) ( $context['delete_file_on_finish'] ?? true ),
				'file_size' => max( 0, (int) ( $context['file_size'] ?? 0 ) ),
				'errors' => array( $message ),
				'errors_total' => 1,
				'started_at' => $now,
				'updated_at' => $now,
				'finished_at' => $now,
				'last_message' => $message,
			)
		);
		$this->save( $state );

		return $state;
	}

	public function finish( string $message = 'Import finished.', string $status = 'success' ): array {
		$status = in_array( $status, array( 'success', 'warning' ), true ) ? $status : 'success';
		return $this->update( array( 'phase' => 'finished', 'status' => $status, 'last_message' => $message, 'finished_at' => $this->now() ) );
	}

	public function reset(): array {
		$state = $this->current();
		$file = (string) ( $state['file_path'] ?? '' );
		if ( ! empty( $state['delete_file_on_finish'] ) && '' !== $file && file_exists( $file ) ) {
			@unlink( $file );
		}
		$index = (string) ( $state['index_path'] ?? '' );
		if ( '' !== $index && file_exists( $index ) ) {
			@unlink( $index );
		}
		$reset = array_merge( $this->defaults(), array( 'phase' => 'cancelled', 'state_revision' => max( 0, (int) ( $state['state_revision'] ?? 0 ) ) + 1, 'last_message' => 'Import was reset by admin.', 'updated_at' => $this->now(), 'finished_at' => $this->now() ) );
		$this->save( $reset );

		return $reset;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return array(
			'job_id' => '',
			'state_revision' => 0,
			'phase' => 'idle',
			'status' => '',
			'source' => '',
			'source_file' => '',
			'runner_protocol_version' => 0,
			'file_path' => '',
			'index_path' => '',
			'stage_table' => '',
			'index_format_version' => 0,
			'index_size' => 0,
			'index_sha256' => '',
			'index_stats' => array(),
			'delete_file_on_finish' => true,
			'file_size' => 0,
			'byte_offset' => 0,
			'rows_read' => 0,
			'total_rows' => 0,
			'ru_rows' => 0,
			'foreign_rows' => 0,
			'foreign_am_rows' => 0,
			'foreign_by_rows' => 0,
			'foreign_kz_rows' => 0,
			'foreign_kg_rows' => 0,
			'foreign_locations_inserted' => 0,
			'foreign_locations_updated' => 0,
			'foreign_save_failed' => 0,
			'foreign_mapping_conflicts' => 0,
			'skipped_non_ru' => 0,
			'skipped_invalid' => 0,
			'matched_by_fias' => 0,
			'matched_by_kladr' => 0,
			'matched_by_name' => 0,
			'saved_candidates' => 0,
			'finalized_mappings' => 0,
			'finalized_changes' => 0,
			'stale_cleared' => 0,
			'stale_cleanup_skipped' => false,
			'unchanged_mappings' => 0,
			'conflicts' => 0,
			'ambiguous' => 0,
			'unmatched' => 0,
			'errors_total' => 0,
			'errors' => array(),
			'started_at' => '',
			'updated_at' => '',
			'finished_at' => '',
			'last_message' => '',
			'columns' => array(),
		);
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function save( array $state ): void {
		update_option( self::OPTION_NAME, array_merge( $this->defaults(), $state ), false );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function new_job_id(): string {
		$random = function_exists( 'wp_rand' ) ? (string) wp_rand() : (string) random_int( 1, PHP_INT_MAX );
		return sha1( microtime( true ) . '|' . $random );
	}
}
