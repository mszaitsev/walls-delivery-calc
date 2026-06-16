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
		unset( $state['file_path'], $state['index_path'], $state['stage_table'], $state['delete_file_on_finish'], $state['columns'] );
		$total = max( 0, (int) ( $state['total_rows'] ?? 0 ) );
		$read = max( 0, (int) ( $state['rows_read'] ?? 0 ) );
		$state['percent_complete'] = $total > 0 ? min( 100, round( $read / $total * 100, 1 ) ) : 0;

		return $state;
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function start( array $context ): array {
		$now = $this->now();
		$state = array_merge(
			$this->defaults(),
			array(
				'job_id' => (string) ( $context['job_id'] ?? $this->new_job_id() ),
				'phase' => (string) ( $context['phase'] ?? 'ready' ),
				'source' => (string) ( $context['source'] ?? 'manual' ),
				'source_file' => (string) ( $context['source_file'] ?? '' ),
				'file_path' => (string) ( $context['file_path'] ?? '' ),
				'index_path' => (string) ( $context['index_path'] ?? '' ),
				'stage_table' => (string) ( $context['stage_table'] ?? '' ),
				'delete_file_on_finish' => (bool) ( $context['delete_file_on_finish'] ?? true ),
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
		$state = array_merge( $this->current(), $patch );
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

		return $this->update(
			array(
				'phase' => 'failed',
				'errors' => $errors,
				'last_message' => $message,
				'finished_at' => $this->now(),
			)
		);
	}

	public function finish( string $message = 'Import finished.' ): array {
		return $this->update( array( 'phase' => 'finished', 'last_message' => $message, 'finished_at' => $this->now() ) );
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
		$reset = array_merge( $this->defaults(), array( 'phase' => 'cancelled', 'last_message' => 'Import was reset by admin.', 'updated_at' => $this->now(), 'finished_at' => $this->now() ) );
		$this->save( $reset );

		return $reset;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return array(
			'job_id' => '',
			'phase' => 'idle',
			'source' => '',
			'source_file' => '',
			'file_path' => '',
			'index_path' => '',
			'stage_table' => '',
			'delete_file_on_finish' => true,
			'byte_offset' => 0,
			'rows_read' => 0,
			'total_rows' => 0,
			'ru_rows' => 0,
			'skipped_non_ru' => 0,
			'skipped_invalid' => 0,
			'matched_by_fias' => 0,
			'matched_by_kladr' => 0,
			'matched_by_name' => 0,
			'saved_candidates' => 0,
			'finalized_mappings' => 0,
			'unchanged_mappings' => 0,
			'conflicts' => 0,
			'ambiguous' => 0,
			'unmatched' => 0,
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
