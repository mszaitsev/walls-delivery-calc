<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

defined( 'ABSPATH' ) || exit;

final class RussianPostPickupImportLock {
	public const OPTION_NAME = 'wdc_russian_post_pickup_import_lock';
	private const LEGACY_TRANSIENT_NAME = 'wdc_russian_post_pickup_import_lock';
	private const TTL_SECONDS = 10800;

	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		if ( $db instanceof \wpdb ) {
			$this->wpdb = $db;
			return;
		}

		global $wpdb;
		$this->wpdb = $wpdb;
	}

	public function acquire( string $job_id, string $reason = 'acquire' ): bool {
		$job_id = trim( $job_id );
		if ( '' === $job_id || $this->has_legacy_lock() ) {
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
			return true;
		}

		$current = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $current ) || (int) ( $current['expires_at'] ?? 0 ) > $now ) {
			return false;
		}

		if ( ! $this->compare_and_delete( $current, $job_id, $reason . ':expired_takeover' ) ) {
			return false;
		}

		return add_option( self::OPTION_NAME, $value, '', 'no' );
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
		$now = time();
		if ( ! is_array( $current ) || (int) ( $current['expires_at'] ?? 0 ) <= $now || ! hash_equals( (string) ( $current['job_id'] ?? '' ), $job_id ) ) {
			return false;
		}

		$renewed = $current;
		$renewed['expires_at'] = $now + self::TTL_SECONDS;
		if ( (int) $current['expires_at'] >= (int) $renewed['expires_at'] ) {
			return true;
		}

		return $this->compare_and_replace( $current, $renewed );
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
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$current = get_option( self::OPTION_NAME, null );
			if ( is_array( $current ) && '' !== (string) ( $current['job_id'] ?? '' ) && ! hash_equals( (string) $current['job_id'], $job_id ) ) {
				return;
			}
			if ( ! is_array( $current ) ) {
				return;
			}
			if ( $this->compare_and_delete( $current, $job_id, $reason ) ) {
				return;
			}

			// A concurrent owner-safe renew may have changed expires_at after
			// this request cached the option. Refresh once, then re-check job_id.
			$this->clear_option_cache();
		}
	}

	public function release_terminal( string $job_id, string $reason = 'terminal_release' ): void {
		$this->release( $job_id, $reason );
		if ( $this->has_legacy_lock() ) {
			delete_transient( self::LEGACY_TRANSIENT_NAME );
		}
		$current = get_option( self::OPTION_NAME, null );
		if ( null !== $current && ! is_array( $current ) ) {
			$this->compare_and_delete( $current, $job_id, $reason . ':legacy_scalar' );
		}
	}

	private function has_legacy_lock(): bool {
		return function_exists( 'get_transient' ) && false !== get_transient( self::LEGACY_TRANSIENT_NAME );
	}

	private function compare_and_delete( mixed $expected, string $requested_job_id, string $reason ): bool {
		if ( ! isset( $this->wpdb->options ) || ! method_exists( $this->wpdb, 'prepare' ) || ! method_exists( $this->wpdb, 'query' ) ) {
			if ( get_option( self::OPTION_NAME, array() ) !== $expected ) {
				return false;
			}

			return delete_option( self::OPTION_NAME );
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
			return false;
		}
		$this->clear_option_cache();

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
