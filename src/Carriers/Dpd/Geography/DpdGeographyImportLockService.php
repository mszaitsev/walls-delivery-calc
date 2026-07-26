<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyImportLockService {
	public const OPTION_NAME = 'wdc_dpd_geography_import_step_lock';

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
			if ( $this->delete_if_current( $expected ) && add_option( self::OPTION_NAME, $payload, '', 'no' ) ) {
				return $token;
			}
		}

		return null;
	}

	public function release( string $token ): void {
		$current = get_option( self::OPTION_NAME, array() );
		if ( is_array( $current ) && hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
			delete_option( self::OPTION_NAME );
		}
	}

	private function delete_if_current( array $expected ): bool {
		$current = get_option( self::OPTION_NAME, array() );
		if ( $current !== $expected ) {
			return false;
		}

		return delete_option( self::OPTION_NAME );
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
