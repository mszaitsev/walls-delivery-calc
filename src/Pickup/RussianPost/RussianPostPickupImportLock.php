<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

defined( 'ABSPATH' ) || exit;

final class RussianPostPickupImportLock {
	public const OPTION_NAME = 'wdc_russian_post_pickup_import_lock';
	private const LEGACY_TRANSIENT_NAME = 'wdc_russian_post_pickup_import_lock';
	private const TTL_SECONDS = 10800;

	private \wpdb $wpdb;
	private RussianPostPickupImportLockAudit $audit;

	public function __construct( ?\wpdb $db = null, ?RussianPostPickupImportLockAudit $audit = null ) {
		$this->audit = $audit ?? new RussianPostPickupImportLockAudit();
		if ( $db instanceof \wpdb ) {
			$this->wpdb = $db;
			return;
		}

		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public function acquire( string $job_id, string $reason = 'acquire' ): bool {
		$job_id = trim( $job_id );
		$current = get_option( self::OPTION_NAME, null );
		$this->record( 'acquire_attempt', $job_id, $current, $current, null, $reason );
		if ( '' === $job_id || $this->has_legacy_lock() ) {
			$this->record( 'acquire_failed', $job_id, $current, $current, false, $reason );
			return false;
		}

		$now = time();
		$value = array(
			'job_id' => $job_id,
			'token' => $this->new_token(),
			'acquired_at' => $now,
			'expires_at' => $now + self::TTL_SECONDS,
		);
		if ( add_option( self::OPTION_NAME, $value, '', 'no' ) ) {
			$this->record( 'acquire_success', $job_id, $current, $value, true, $reason );
			return true;
		}

		$current = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $current ) || (int) ( $current['expires_at'] ?? 0 ) > $now ) {
			$this->record( 'acquire_failed', $job_id, $current, $current, false, $reason );
			return false;
		}

		$this->record( 'expired_lock_detected', $job_id, $current, $current, null, $reason );
		if ( ! $this->compare_and_delete( $current, $job_id, $reason . ':expired_takeover' ) ) {
			$this->record( 'acquire_failed', $job_id, $current, get_option( self::OPTION_NAME, null ), false, $reason );
			return false;
		}
		$this->record( 'expired_lock_deleted', $job_id, $current, null, true, $reason );
		$acquired = add_option( self::OPTION_NAME, $value, '', 'no' );
		$this->record( $acquired ? 'replacement_acquired' : 'acquire_failed', $job_id, null, get_option( self::OPTION_NAME, null ), $acquired, $reason );

		return $acquired;
	}

	public function is_locked(): bool {
		if ( $this->has_legacy_lock() ) {
			return true;
		}

		$current = get_option( self::OPTION_NAME, null );
		if ( null !== $current && ! is_array( $current ) ) {
			return true;
		}

		return is_array( $current ) && '' !== (string) ( $current['job_id'] ?? '' ) && (int) ( $current['expires_at'] ?? 0 ) > time();
	}

	public function owns( string $job_id ): bool {
		$current = get_option( self::OPTION_NAME, null );
		if ( is_array( $current ) ) {
			return (int) ( $current['expires_at'] ?? 0 ) > time() && hash_equals( (string) ( $current['job_id'] ?? '' ), $job_id );
		}

		return null !== $current || $this->has_legacy_lock();
	}

	public function renew( string $job_id, string $reason = 'renew' ): bool {
		$current = get_option( self::OPTION_NAME, null );
		$this->record( 'renew_attempt', $job_id, $current, $current, null, $reason );
		$now = time();
		if ( ! is_array( $current ) || (int) ( $current['expires_at'] ?? 0 ) <= $now || ! hash_equals( (string) ( $current['job_id'] ?? '' ), $job_id ) ) {
			$this->record( 'renew_failed', $job_id, $current, $current, false, $reason );
			return false;
		}

		$renewed = $current;
		$renewed['expires_at'] = $now + self::TTL_SECONDS;
		if ( (int) $current['expires_at'] >= (int) $renewed['expires_at'] ) {
			$this->record( 'renew_success', $job_id, $current, $current, true, $reason );
			return true;
		}

		$success = $this->compare_and_replace( $current, $renewed );
		$this->record( $success ? 'renew_success' : 'renew_failed', $job_id, $current, get_option( self::OPTION_NAME, null ), $success, $reason );

		return $success;
	}

	/** @return array{lock_exists:bool,lock_job_id:string,lock_expires_at:int,lock_owned:bool} */
	public function diagnostics( string $job_id ): array {
		$current = get_option( self::OPTION_NAME, null );
		$legacy = $this->has_legacy_lock();

		return array(
			'lock_exists' => null !== $current || $legacy,
			'lock_job_id' => is_array( $current ) ? (string) ( $current['job_id'] ?? '' ) : '',
			'lock_expires_at' => is_array( $current ) ? (int) ( $current['expires_at'] ?? 0 ) : 0,
			'lock_owned' => $this->owns( $job_id ),
		);
	}

	public function release( string $job_id, string $reason = 'release' ): void {
		$initial = get_option( self::OPTION_NAME, null );
		$this->record( 'release_attempt', $job_id, $initial, $initial, null, $reason );
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$current = get_option( self::OPTION_NAME, null );
			if ( is_array( $current ) && '' !== (string) ( $current['job_id'] ?? '' ) && ! hash_equals( (string) $current['job_id'], $job_id ) ) {
				$this->record( 'owner_mismatch', $job_id, $current, $current, false, $reason );
				return;
			}
			if ( ! is_array( $current ) ) {
				$this->record( 'release_miss', $job_id, $current, $current, false, $reason );
				return;
			}
			if ( $this->compare_and_delete( $current, $job_id, $reason ) ) {
				$this->record( 'release_success', $job_id, $current, null, true, $reason );
				return;
			}

			// A concurrent owner-safe renew may have changed expires_at after
			// this request cached the option. Refresh once, then re-check job_id.
			$this->clear_option_cache();
		}
		$this->record( 'release_miss', $job_id, $initial, get_option( self::OPTION_NAME, null ), false, $reason );
	}

	public function release_terminal( string $job_id, string $reason = 'terminal_release' ): void {
		$before = get_option( self::OPTION_NAME, null );
		$this->record( 'terminal_release_attempt', $job_id, $before, $before, null, $reason );
		$this->release( $job_id, $reason );
		if ( $this->has_legacy_lock() ) {
			$this->record( 'legacy_lock_cleanup_attempt', $job_id, get_option( self::OPTION_NAME, null ), get_option( self::OPTION_NAME, null ), null, $reason );
			delete_transient( self::LEGACY_TRANSIENT_NAME );
			$this->record( 'legacy_lock_cleanup_done', $job_id, get_option( self::OPTION_NAME, null ), get_option( self::OPTION_NAME, null ), true, $reason );
		}
		$current = get_option( self::OPTION_NAME, null );
		if ( null !== $current && ! is_array( $current ) ) {
			$this->compare_and_delete( $current, $job_id, $reason . ':legacy_scalar' );
		}
		$this->record( 'terminal_release_done', $job_id, $before, get_option( self::OPTION_NAME, null ), null === get_option( self::OPTION_NAME, null ), $reason );
	}

	public function audit_checkpoint( string $event, string $job_id, string $reason ): void {
		$current = get_option( self::OPTION_NAME, null );
		$this->record( $event, $job_id, $current, $current, $this->owns( $job_id ), $reason );
	}

	/** @return array<int,array<string,mixed>> */
	public function audit_events(): array {
		return $this->audit->events();
	}

	private function has_legacy_lock(): bool {
		return function_exists( 'get_transient' ) && false !== get_transient( self::LEGACY_TRANSIENT_NAME );
	}

	private function compare_and_delete( mixed $expected, string $requested_job_id, string $reason ): bool {
		$this->record( 'cas_delete_attempt', $requested_job_id, $expected, get_option( self::OPTION_NAME, null ), null, $reason );
		if ( ! isset( $this->wpdb->options ) || ! method_exists( $this->wpdb, 'prepare' ) || ! method_exists( $this->wpdb, 'query' ) ) {
			if ( get_option( self::OPTION_NAME, array() ) !== $expected ) {
				$this->record( 'cas_delete_miss', $requested_job_id, $expected, get_option( self::OPTION_NAME, null ), false, $reason );
				return false;
			}

			$deleted = delete_option( self::OPTION_NAME );
			$this->record( $deleted ? 'cas_delete_success' : 'cas_delete_miss', $requested_job_id, $expected, get_option( self::OPTION_NAME, null ), $deleted, $reason );

			return $deleted;
		}

		$serialized = function_exists( 'maybe_serialize' ) ? maybe_serialize( $expected ) : serialize( $expected );
		$this->wpdb->last_error = '';
		$sql = $this->wpdb->prepare(
			"DELETE FROM {$this->wpdb->options} WHERE option_name = %s AND option_value = %s LIMIT 1",
			self::OPTION_NAME,
			$serialized
		);
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) {
			throw new \RuntimeException( 'Russian Post pickup import lock compare-delete failed.' );
		}
		if ( 1 !== (int) $result ) {
			$this->record( 'cas_delete_miss', $requested_job_id, $expected, get_option( self::OPTION_NAME, null ), false, $reason );
			return false;
		}
		$this->clear_option_cache();
		$this->record( 'cas_delete_success', $requested_job_id, $expected, null, true, $reason );

		return true;
	}

	/** @param array<string,mixed> $expected @param array<string,mixed> $replacement */
	private function compare_and_replace( array $expected, array $replacement ): bool {
		if ( ! isset( $this->wpdb->options ) || ! method_exists( $this->wpdb, 'prepare' ) || ! method_exists( $this->wpdb, 'query' ) ) {
			if ( get_option( self::OPTION_NAME, array() ) !== $expected ) {
				return false;
			}

			return update_option( self::OPTION_NAME, $replacement, false );
		}

		$serialize = static fn( array $value ): string => (string) ( function_exists( 'maybe_serialize' ) ? maybe_serialize( $value ) : serialize( $value ) );
		$this->wpdb->last_error = '';
		$sql = $this->wpdb->prepare(
			"UPDATE {$this->wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s LIMIT 1",
			$serialize( $replacement ),
			self::OPTION_NAME,
			$serialize( $expected )
		);
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) {
			throw new \RuntimeException( 'Russian Post pickup import lock compare-renew failed.' );
		}
		if ( 1 !== (int) $result ) {
			$this->clear_option_cache();
			$current = get_option( self::OPTION_NAME, null );

			return is_array( $current )
				&& hash_equals( (string) ( $expected['job_id'] ?? '' ), (string) ( $current['job_id'] ?? '' ) )
				&& hash_equals( (string) ( $expected['token'] ?? '' ), (string) ( $current['token'] ?? '' ) )
				&& (int) ( $current['expires_at'] ?? 0 ) > time();
		}
		$this->clear_option_cache();

		return true;
	}

	private function clear_option_cache(): void {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	private function record( string $event, string $requested_job_id, mixed $before, mixed $after, ?bool $success, string $reason ): void {
		$this->audit->record(
			$event,
			$requested_job_id,
			array(
				'current_lock_job_id_before' => $this->lock_job_id( $before ),
				'current_lock_job_id_after' => $this->lock_job_id( $after ),
				'success' => $success,
				'reason' => $reason,
			)
		);
	}

	private function lock_job_id( mixed $value ): string {
		return is_array( $value ) ? (string) ( $value['job_id'] ?? '' ) : '';
	}

	private function new_token(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Throwable ) {
			return sha1( microtime( true ) . '|' . (string) random_int( 1, PHP_INT_MAX ) );
		}
	}
}
