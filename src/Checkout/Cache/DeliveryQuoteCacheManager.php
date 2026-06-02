<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Cache;

defined( 'ABSPATH' ) || exit;

final class DeliveryQuoteCacheManager {
	/** @var array<int,string> */
	private const TRANSIENT_PREFIXES = array(
		'wdc_rp_domestic_',
		'wdc_rp_tariff_',
	);

	private \wpdb $wpdb;

	public function __construct( private ?QuoteCache $quote_cache = null, ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	/**
	 * @return array<int,string>
	 */
	public static function quote_transient_prefixes(): array {
		return self::TRANSIENT_PREFIXES;
	}

	public function clear_all_quote_cache(): int {
		$keys = $this->transient_keys();
		$deleted = 0;
		foreach ( $keys as $key ) {
			if ( $this->delete_transient_key( $key ) ) {
				++$deleted;
			}
		}

		if ( $this->quote_cache instanceof QuoteCache ) {
			$this->quote_cache->invalidate_all();
		}

		return $deleted;
	}

	/**
	 * @return array<int,string>
	 */
	private function transient_keys(): array {
		$names = $this->transient_option_names();
		$keys = array();
		foreach ( $names as $name ) {
			$key = $this->transient_key_from_option_name( $name );
			if ( '' !== $key && $this->is_quote_cache_key( $key ) ) {
				$keys[ $key ] = $key;
			}
		}

		return array_values( $keys );
	}

	/**
	 * @return array<int,string>
	 */
	private function transient_option_names(): array {
		if ( property_exists( $this->wpdb, 'options' ) && is_array( $this->wpdb->options ) ) {
			return array_keys( $this->wpdb->options );
		}

		$where = array();
		$args = array();
		foreach ( self::TRANSIENT_PREFIXES as $prefix ) {
			$where[] = 'option_name LIKE %s';
			$args[] = '\_transient\_' . $this->wpdb->esc_like( $prefix ) . '%';
			$where[] = 'option_name LIKE %s';
			$args[] = '\_transient\_timeout\_' . $this->wpdb->esc_like( $prefix ) . '%';
		}
		$sql = "SELECT option_name FROM {$this->wpdb->options} WHERE " . implode( ' OR ', $where );
		$prepared = $this->wpdb->prepare( $sql, ...$args );
		if ( method_exists( $this->wpdb, 'get_col' ) ) {
			$names = $this->wpdb->get_col( $prepared );
			return is_array( $names ) ? array_map( 'strval', $names ) : array();
		}

		$rows = $this->wpdb->get_results( $prepared, ARRAY_A );
		$names = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) && isset( $row['option_name'] ) ) {
				$names[] = (string) $row['option_name'];
			} elseif ( is_object( $row ) && isset( $row->option_name ) ) {
				$names[] = (string) $row->option_name;
			}
		}

		return $names;
	}

	private function transient_key_from_option_name( string $option_name ): string {
		foreach ( array( '_transient_timeout_', '_transient_' ) as $prefix ) {
			if ( str_starts_with( $option_name, $prefix ) ) {
				return substr( $option_name, strlen( $prefix ) );
			}
		}

		return '';
	}

	private function is_quote_cache_key( string $key ): bool {
		foreach ( self::TRANSIENT_PREFIXES as $prefix ) {
			if ( str_starts_with( $key, $prefix ) ) {
				return true;
			}
		}

		return false;
	}

	private function delete_transient_key( string $key ): bool {
		if ( function_exists( 'delete_transient' ) ) {
			return delete_transient( $key );
		}

		$deleted = false;
		foreach ( array( '_transient_' . $key, '_transient_timeout_' . $key ) as $option ) {
			if ( function_exists( 'delete_option' ) ) {
				$deleted = delete_option( $option ) || $deleted;
			}
		}

		return $deleted;
	}
}
