<?php
/**
 * Transient cache abstraction.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Cache {
	private const PREFIX = 'wdc_';

	public function get( string $key ) {
		return get_transient( $this->normalize_key( $key ) );
	}

	public function set( string $key, $value, ?int $expiration = null ): bool {
		$expiration = null === $expiration ? DAY_IN_SECONDS : max( 0, $expiration );

		return set_transient( $this->normalize_key( $key ), $value, $expiration );
	}

	public function delete( string $key ): bool {
		return delete_transient( $this->normalize_key( $key ) );
	}

	public function get_seconds_until_end_of_day(): int {
		if ( function_exists( 'current_datetime' ) ) {
			$now = current_datetime();
			$end = $now->setTime( 23, 59, 59 );

			return max( 1, $end->getTimestamp() - $now->getTimestamp() );
		}

		$end = strtotime( 'today 23:59:59' );

		return is_int( $end ) ? max( 1, $end - time() ) : DAY_IN_SECONDS;
	}

	public function get_prefix(): string {
		return self::PREFIX;
	}

	private function normalize_key( string $key ): string {
		$key = sanitize_key( $key );

		return self::PREFIX . substr( $key, 0, 168 );
	}
}
