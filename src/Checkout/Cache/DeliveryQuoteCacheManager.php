<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Cache;

defined( 'ABSPATH' ) || exit;

final class DeliveryQuoteCacheManager {
	public const CACHE_VERSION_OPTION = 'wdc_delivery_rates_cache_version';

	/** @var array<int,string> */
	private const TRANSIENT_PREFIXES = array(
		'wdc_rp_domestic_',
		'wdc_rp_tariff_',
		'wdc_cdek_city_',
		'wdc_cdek_deliverypoints_',
	);

	/** @var array<int,string> */
	private const WDC_SESSION_CACHE_KEYS = array(
		'wdc_platform_rates',
		'wdc_platform_selected_tariffs',
	);

	private \wpdb $wpdb;

	public function __construct( private ?QuoteCache $quote_cache = null, ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function register(): void {
		if ( function_exists( 'add_filter' ) ) {
			add_filter( 'woocommerce_cart_shipping_packages', array( $this, 'add_cache_version_to_packages' ), PHP_INT_MAX );
		}
	}

	/**
	 * @return array<int,string>
	 */
	public static function quote_transient_prefixes(): array {
		return self::TRANSIENT_PREFIXES;
	}

	public function clear_all_quote_cache(): int {
		return $this->clear_all_delivery_cache();
	}

	public function clear_all_delivery_cache(): int {
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

		$this->bump_delivery_rates_cache_version();
		$deleted += $this->clear_woocommerce_session_cache();

		return $deleted;
	}

	public function delivery_rates_cache_version(): string {
		if ( function_exists( 'get_option' ) ) {
			$value = get_option( self::CACHE_VERSION_OPTION, '1' );

			return is_scalar( $value ) && '' !== (string) $value ? (string) $value : '1';
		}

		return '1';
	}

	public function bump_delivery_rates_cache_version(): string {
		$version = sha1( uniqid( 'wdc_delivery_rates_', true ) );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::CACHE_VERSION_OPTION, $version, false );
		}

		return $version;
	}

	/**
	 * @param array<int,array<string,mixed>> $packages
	 * @return array<int,array<string,mixed>>
	 */
	public function add_cache_version_to_packages( array $packages ): array {
		$version = $this->delivery_rates_cache_version();
		foreach ( $packages as $index => $package ) {
			if ( is_array( $package ) ) {
				$package['wdc_delivery_rates_cache_version'] = $version;
				$packages[ $index ] = $package;
			}
		}

		return $packages;
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

	private function clear_woocommerce_session_cache(): int {
		if ( ! function_exists( 'WC' ) ) {
			return 0;
		}

		$woocommerce = WC();
		$this->ensure_woocommerce_session( $woocommerce );
		$session = is_object( $woocommerce ) && isset( $woocommerce->session ) ? $woocommerce->session : null;
		if ( ! is_object( $session ) ) {
			return 0;
		}

		$deleted = 0;
		for ( $index = 0; $index < 20; ++$index ) {
			if ( $this->clear_session_key( $session, 'shipping_for_package_' . $index ) ) {
				++$deleted;
			}
		}

		foreach ( self::WDC_SESSION_CACHE_KEYS as $key ) {
			if ( $this->clear_session_key( $session, $key ) ) {
				++$deleted;
			}
		}

		if ( method_exists( $session, 'save_data' ) ) {
			$session->save_data();
		}

		return $deleted;
	}

	private function ensure_woocommerce_session( mixed $woocommerce ): void {
		if ( ! is_object( $woocommerce ) ) {
			return;
		}
		if ( isset( $woocommerce->session ) && is_object( $woocommerce->session ) ) {
			return;
		}
		if ( ! class_exists( 'WC_Session_Handler' ) ) {
			return;
		}

		$session = new \WC_Session_Handler();
		if ( method_exists( $session, 'init' ) ) {
			$session->init();
		}
		$woocommerce->session = $session;
	}

	private function clear_session_key( object $session, string $key ): bool {
		$had_value = true;
		if ( method_exists( $session, 'get' ) ) {
			$had_value = null !== $session->get( $key, null );
		}

		if ( method_exists( $session, '__unset' ) ) {
			$session->__unset( $key );
		} elseif ( method_exists( $session, 'set' ) ) {
			$session->set( $key, null );
		} else {
			return false;
		}

		return $had_value;
	}
}
