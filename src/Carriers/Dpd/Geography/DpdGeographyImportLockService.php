<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyImportLockService {
	public const OPTION_NAME = 'wdc_dpd_geography_import_step_lock';

	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		if ( $db instanceof \wpdb ) {
			$this->wpdb = $db;
			return;
		}

		global $wpdb;
		$this->wpdb = $wpdb;
	}

	/**
	 * @return string|null Lock token when acquired, null when another live lease owns the lock.
	 */
	public function acquire( string $job_id, int $ttl_seconds = 600 ): ?string {
		$job_id = '' !== trim( $job_id ) ? trim( $job_id ) : 'global';
		$ttl_seconds = max( 30, $ttl_seconds );
		$now = time();
		$token = $this->new_token();
		$payload = array(
			'job_id' => $job_id,
			'token' => $token,
			'acquired_at' => $now,
			'expires_at' => $now + $ttl_seconds,
		);
		if ( add_option( self::OPTION_NAME, $payload, '', 'no' ) ) {
			return $token;
		}

		$current = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $current ) || (int) ( $current['expires_at'] ?? 0 ) <= $now ) {
			$expected = is_array( $current ) ? $current : array();
			if ( $this->compare_and_delete( $expected ) && add_option( self::OPTION_NAME, $payload, '', 'no' ) ) {
				return $token;
			}
		}

		return null;
	}

	public function release( string $token ): void {
		$current = get_option( self::OPTION_NAME, array() );
		if ( is_array( $current ) && hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
			$this->compare_and_delete( $current );
		}
	}

	private function compare_and_delete( array $expected ): bool {
		$serialized = function_exists( 'maybe_serialize' ) ? maybe_serialize( $expected ) : serialize( $expected );
		$this->wpdb->last_error = '';
		$sql = $this->wpdb->prepare(
			"DELETE FROM {$this->wpdb->options} WHERE option_name = %s AND option_value = %s LIMIT 1",
			self::OPTION_NAME,
			$serialized
		);
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) {
			throw new \RuntimeException( 'DPD geography import lock compare-delete failed: ' . $this->sanitize_sql_error( (string) $this->wpdb->last_error ) );
		}
		if ( 1 !== (int) $result ) {
			return false;
		}
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( self::OPTION_NAME, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}

		return true;
	}

	private function sanitize_sql_error( string $message ): string {
		$message = preg_replace( '/[\r\n\t]+/', ' ', $message ) ?? $message;
		return trim( $message );
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
