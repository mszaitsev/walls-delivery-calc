<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

defined( 'ABSPATH' ) || exit;

final class RussianPostPickupImportStateService {
	public const OPTION_NAME = 'wdc_russian_post_pickup_import_state';
	private const MAX_STORED_ERRORS = 10;
	private const STALE_AFTER_SECONDS = 7200;
	private const DOWNLOAD_STALE_AFTER_SECONDS = 900;

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
	public function queue( string $type ): array {
		$now = $this->now();
		$state = array_merge(
			$this->defaults(),
			array(
				'status' => 'queued',
				'stage' => 'queued',
				'started_at' => '',
				'finished_at' => '',
				'last_activity_at' => $now,
				'type' => $this->normalize_type( $type ),
				'errors' => array(),
			)
		);
		$this->save( $state );

		return $state;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function start( string $type ): array {
		$now = $this->now();
		$state = array_merge(
			$this->defaults(),
			array(
				'status' => 'running',
				'stage' => 'download',
				'started_at' => $now,
				'finished_at' => '',
				'last_activity_at' => $now,
				'type' => $this->normalize_type( $type ),
				'errors' => array(),
				'memory_peak' => $this->memory_peak(),
			)
		);
		$this->save( $state );

		return $state;
	}

	/**
	 * @param array<string,mixed> $counters
	 * @return array<string,mixed>
	 */
	public function update( string $stage, array $counters = array() ): array {
		$state = $this->current();
		$state['status'] = in_array( (string) $state['status'], array( 'queued', 'running' ), true ) ? 'running' : (string) $state['status'];
		$state['stage'] = $this->normalize_stage( $stage );
		$state['last_activity_at'] = $this->now();
		$state['memory_peak'] = max( (int) ( $state['memory_peak'] ?? 0 ), $this->memory_peak() );

		foreach ( array( 'downloaded', 'parsed', 'inserted', 'updated', 'deactivated', 'skipped' ) as $key ) {
			if ( array_key_exists( $key, $counters ) ) {
				$state[ $key ] = max( 0, (int) $counters[ $key ] );
			}
		}
		if ( isset( $counters['errors'] ) && is_array( $counters['errors'] ) ) {
			$state['errors'] = array_slice( array_map( 'strval', $counters['errors'] ), 0, self::MAX_STORED_ERRORS );
		}

		$this->save( $state );

		return $state;
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	public function success( array $result ): array {
		return $this->finish( 'success', 'finished', $result );
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	public function failed( array $result ): array {
		return $this->finish( 'failed', 'failed', $result );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function cancel_by_admin(): array {
		$state = $this->current();
		$now = $this->now();
		$state['status'] = 'failed';
		$state['stage'] = 'failed';
		$state['finished_at'] = $now;
		$state['last_activity_at'] = $now;
		$errors = is_array( $state['errors'] ?? null ) ? $state['errors'] : array();
		$errors[] = 'Import was manually cancelled/reset by admin.';
		$state['errors'] = array_slice( array_map( 'strval', $errors ), 0, self::MAX_STORED_ERRORS );
		$state['memory_peak'] = max( (int) ( $state['memory_peak'] ?? 0 ), $this->memory_peak() );
		$this->save( $state );

		return $state;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function reset_stale_if_needed(): array {
		$state = $this->current();
		if ( ! in_array( (string) $state['status'], array( 'queued', 'running' ), true ) ) {
			return $state;
		}

		$was_download = 'download' === (string) ( $state['stage'] ?? '' );
		$last = strtotime( (string) ( $state['last_activity_at'] ?? '' ) );
		$stale_after = $was_download ? self::DOWNLOAD_STALE_AFTER_SECONDS : self::STALE_AFTER_SECONDS;
		if ( false !== $last && time() - $last < $stale_after ) {
			return $state;
		}

		$state['status'] = 'failed';
		$state['stage'] = 'failed';
		$state['finished_at'] = $this->now();
		$state['last_activity_at'] = $state['finished_at'];
		$errors = is_array( $state['errors'] ?? null ) ? $state['errors'] : array();
		$errors[] = $was_download ? 'Download stage timed out/stale.' : 'Previous import lock was stale and has been reset.';
		$state['errors'] = array_slice( array_map( 'strval', $errors ), 0, self::MAX_STORED_ERRORS );
		$this->save( $state );

		return $state;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return array(
			'status' => 'idle',
			'stage' => '',
			'started_at' => '',
			'finished_at' => '',
			'last_activity_at' => '',
			'type' => 'ALL',
			'downloaded' => 0,
			'parsed' => 0,
			'inserted' => 0,
			'updated' => 0,
			'deactivated' => 0,
			'skipped' => 0,
			'errors' => array(),
			'memory_peak' => 0,
		);
	}

	/**
	 * @param array<string,mixed> $state
	 */
	private function save( array $state ): void {
		update_option( self::OPTION_NAME, array_merge( $this->defaults(), $state ), false );
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private function finish( string $status, string $stage, array $result ): array {
		$state = $this->current();
		foreach ( array( 'downloaded', 'parsed', 'inserted', 'updated', 'deactivated', 'skipped' ) as $key ) {
			$state[ $key ] = max( 0, (int) ( $result[ $key ] ?? $state[ $key ] ?? 0 ) );
		}
		$state['status'] = $status;
		$state['stage'] = $stage;
		$state['finished_at'] = (string) ( $result['finished_at'] ?? $this->now() );
		$state['last_activity_at'] = $state['finished_at'];
		$state['type'] = $this->normalize_type( (string) ( $result['type'] ?? $state['type'] ?? 'ALL' ) );
		$state['errors'] = array_slice( array_map( 'strval', is_array( $result['errors'] ?? null ) ? $result['errors'] : array() ), 0, self::MAX_STORED_ERRORS );
		$state['memory_peak'] = max( (int) ( $state['memory_peak'] ?? 0 ), $this->memory_peak() );
		$this->save( $state );

		return $state;
	}

	private function normalize_type( string $type ): string {
		$type = strtoupper( trim( $type ) );

		return in_array( $type, array( 'ALL', 'OPS', 'PVZ', 'APS' ), true ) ? $type : 'ALL';
	}

	private function normalize_stage( string $stage ): string {
		return in_array( $stage, array( 'queued', 'download', 'extract', 'parse', 'upsert', 'deactivate', 'finished', 'failed' ), true ) ? $stage : 'parse';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function memory_peak(): int {
		return function_exists( 'memory_get_peak_usage' ) ? (int) memory_get_peak_usage( true ) : 0;
	}
}
