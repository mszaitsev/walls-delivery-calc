<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoPipelineV2ExecutionLock {
	public const OPTION_NAME = 'wdc_yandex_delivery_geo_pipeline_v2_execution_lock';
	private const TTL_SECONDS = 300;

	private mixed $wpdb;

	public function __construct( mixed $db = null ) {
		if ( is_object( $db ) ) {
			$this->wpdb = $db;
			return;
		}

		global $wpdb;
		$this->wpdb = is_object( $wpdb ?? null ) ? $wpdb : null;
	}

	public function acquire( string $session_id ): ?string {
		$session_id = trim( $session_id );
		if ( '' === $session_id ) {
			return null;
		}

		$now = time();
		$token = $this->new_token();
		$value = array(
			'session_id' => $session_id,
			'token' => $token,
			'acquired_at' => $now,
			'expires_at' => $now + self::TTL_SECONDS,
		);
		if ( add_option( self::OPTION_NAME, $value, '', 'no' ) ) {
			return $token;
		}

		$current = get_option( self::OPTION_NAME, null );
		if ( ! is_array( $current ) || (int) ( $current['expires_at'] ?? 0 ) > $now ) {
			return null;
		}
		if ( ! $this->compare_and_delete( $current ) ) {
			return null;
		}

		return add_option( self::OPTION_NAME, $value, '', 'no' ) ? $token : null;
	}

	public function owns( string $session_id, string $token ): bool {
		$current = get_option( self::OPTION_NAME, null );

		return is_array( $current )
			&& (int) ( $current['expires_at'] ?? 0 ) > time()
			&& hash_equals( (string) ( $current['session_id'] ?? '' ), $session_id )
			&& hash_equals( (string) ( $current['token'] ?? '' ), $token );
	}

	public function renew( string $session_id, string $token ): bool {
		$current = get_option( self::OPTION_NAME, null );
		$now = time();
		if ( ! is_array( $current )
			|| (int) ( $current['expires_at'] ?? 0 ) <= $now
			|| ! hash_equals( (string) ( $current['session_id'] ?? '' ), $session_id )
			|| ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
			return false;
		}

		$renewed = $current;
		$renewed['expires_at'] = $now + self::TTL_SECONDS;
		if ( (int) $current['expires_at'] >= (int) $renewed['expires_at'] ) {
			return true;
		}

		return $this->compare_and_replace( $current, $renewed );
	}

	public function release( string $session_id, string $token ): void {
		for ( $attempt = 0; $attempt < 2; ++$attempt ) {
			$current = get_option( self::OPTION_NAME, null );
			if ( ! is_array( $current )
				|| ! hash_equals( (string) ( $current['session_id'] ?? '' ), $session_id )
				|| ! hash_equals( (string) ( $current['token'] ?? '' ), $token ) ) {
				return;
			}
			if ( $this->compare_and_delete( $current ) ) {
				return;
			}
			$this->clear_option_cache();
		}
	}

	/** @param array<string,mixed> $expected */
	private function compare_and_delete( array $expected ): bool {
		if ( ! is_object( $this->wpdb ) || ! isset( $this->wpdb->options ) || ! method_exists( $this->wpdb, 'prepare' ) || ! method_exists( $this->wpdb, 'query' ) ) {
			if ( get_option( self::OPTION_NAME, null ) !== $expected ) {
				return false;
			}

			return delete_option( self::OPTION_NAME );
		}

		$serialized = $this->serialize( $expected );
		$this->wpdb->last_error = '';
		$sql = $this->wpdb->prepare(
			"DELETE FROM {$this->wpdb->options} WHERE option_name = %s AND option_value = %s LIMIT 1",
			self::OPTION_NAME,
			$serialized
		);
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) {
			throw new \RuntimeException( 'Yandex geo pipeline execution lock compare-delete failed.' );
		}
		if ( 1 !== (int) $result ) {
			return false;
		}
		$this->clear_option_cache();

		return true;
	}

	/** @param array<string,mixed> $expected @param array<string,mixed> $replacement */
	private function compare_and_replace( array $expected, array $replacement ): bool {
		if ( ! is_object( $this->wpdb ) || ! isset( $this->wpdb->options ) || ! method_exists( $this->wpdb, 'prepare' ) || ! method_exists( $this->wpdb, 'query' ) ) {
			if ( get_option( self::OPTION_NAME, null ) !== $expected ) {
				return false;
			}

			return update_option( self::OPTION_NAME, $replacement, false );
		}

		$this->wpdb->last_error = '';
		$sql = $this->wpdb->prepare(
			"UPDATE {$this->wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s LIMIT 1",
			$this->serialize( $replacement ),
			self::OPTION_NAME,
			$this->serialize( $expected )
		);
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) {
			throw new \RuntimeException( 'Yandex geo pipeline execution lock compare-renew failed.' );
		}
		if ( 1 !== (int) $result ) {
			$this->clear_option_cache();
			return $this->owns( (string) ( $expected['session_id'] ?? '' ), (string) ( $expected['token'] ?? '' ) );
		}
		$this->clear_option_cache();

		return true;
	}

	/** @param array<string,mixed> $value */
	private function serialize( array $value ): string {
		return (string) ( function_exists( 'maybe_serialize' ) ? maybe_serialize( $value ) : serialize( $value ) );
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
