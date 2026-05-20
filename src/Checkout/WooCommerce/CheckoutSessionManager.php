<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CheckoutSessionManager {
	private const DELIVERY_TYPE_KEY = 'wdc_platform_selected_delivery_type';
	private const PICKUP_SELECTION_KEY = 'wdc_platform_pickup_selection';
	private const PICKUP_CARRIER_KEY = 'wdc_platform_selected_pickup_carrier';
	private const SORT_MODE_KEY     = 'wdc_platform_checkout_sort_mode';
	private const RATES_KEY         = 'wdc_platform_rates';
	private const DEBUG_KEY         = 'wdc_platform_debug';

	public function save_selected_delivery_type( string $delivery_type ): void {
		$this->set( self::DELIVERY_TYPE_KEY, $delivery_type );
	}

	public function selected_delivery_type(): string {
		return (string) $this->get( self::DELIVERY_TYPE_KEY, '' );
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	public function save_pickup_selection( array $selection ): void {
		$this->set( self::PICKUP_SELECTION_KEY, $selection );
		$this->set( self::PICKUP_CARRIER_KEY, (string) ( $selection['carrier_key'] ?? '' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function pickup_selection(): array {
		$selection = $this->get( self::PICKUP_SELECTION_KEY, array() );

		return is_array( $selection ) ? $selection : array();
	}

	public function selected_pickup_carrier(): string {
		return (string) $this->get( self::PICKUP_CARRIER_KEY, '' );
	}

	public function save_sort_mode( string $sort_mode ): void {
		$this->set( self::SORT_MODE_KEY, $sort_mode );
	}

	public function selected_sort_mode(): string {
		return (string) $this->get( self::SORT_MODE_KEY, '' );
	}

	/**
	 * @param array<string,array<string,mixed>> $rates
	 */
	public function save_rates( array $rates ): void {
		$this->set( self::RATES_KEY, $rates );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function rates(): array {
		$rates = $this->get( self::RATES_KEY, array() );

		return is_array( $rates ) ? $rates : array();
	}

	/**
	 * @param array<string,mixed> $debug
	 */
	public function save_debug( array $debug ): void {
		$this->set( self::DEBUG_KEY, $debug );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function debug(): array {
		$debug = $this->get( self::DEBUG_KEY, array() );

		return is_array( $debug ) ? $debug : array();
	}

	private function set( string $key, mixed $value ): void {
		$session = $this->session();
		if ( is_object( $session ) && method_exists( $session, 'set' ) ) {
			$session->set( $key, $value );
		}
	}

	private function get( string $key, mixed $default ): mixed {
		$session = $this->session();
		if ( is_object( $session ) && method_exists( $session, 'get' ) ) {
			return $session->get( $key, $default );
		}

		return $default;
	}

	private function session(): mixed {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) ) {
			return WC()->session;
		}

		return null;
	}
}
