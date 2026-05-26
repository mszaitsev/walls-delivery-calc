<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Domain\Address\AddressNormalizationResult;

defined( 'ABSPATH' ) || exit;

final class CheckoutSessionManager {
	private const DELIVERY_TYPE_KEY = 'wdc_platform_selected_delivery_type';
	private const PICKUP_SELECTION_KEY = 'wdc_platform_pickup_selection';
	private const PICKUP_CARRIER_KEY = 'wdc_platform_selected_pickup_carrier';
	private const SORT_MODE_KEY     = 'wdc_platform_checkout_sort_mode';
	private const RATES_KEY         = 'wdc_platform_rates';
	private const SELECTED_TARIFFS_KEY = 'wdc_platform_selected_tariffs';
	private const DEBUG_KEY         = 'wdc_platform_debug';
	private const NORMALIZED_ADDRESS_KEY = 'wdc_platform_normalized_address';
	private const SELECTED_CITY_KEY      = 'wdc_platform_selected_city';
	private const CITY_CONTEXT_KEY       = 'wdc_platform_city_context';
	private const FALLBACK_CITY_KEY      = 'wdc_platform_fallback_city';
	private const ADDRESS_FINGERPRINT_KEY = 'wdc_platform_address_fingerprint';

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

	public function clear_pickup_selection(): void {
		$this->set( self::PICKUP_SELECTION_KEY, array() );
		$this->set( self::PICKUP_CARRIER_KEY, '' );
	}

	public function pickup_selection_matches( string $carrierKey, string $rateId ): bool {
		$selection = $this->pickup_selection();
		if ( array() === $selection || '' === trim( (string) ( $selection['point_code'] ?? '' ) ) ) {
			return false;
		}

		if ( trim( (string) ( $selection['carrier_key'] ?? '' ) ) !== trim( $carrierKey ) ) {
			return false;
		}

		$selection_rate_id = trim( (string) ( $selection['rate_id'] ?? '' ) );
		if ( '' === $selection_rate_id ) {
			return true;
		}

		return $this->normalize_rate_id( $selection_rate_id ) === $this->normalize_rate_id( $rateId );
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
	 * @param array<string,mixed> $selection
	 */
	public function save_selected_tariff( string $service_key, array $selection ): void {
		$selected = $this->selected_tariffs();
		$selected[ $service_key ] = $selection;
		$this->set( self::SELECTED_TARIFFS_KEY, $selected );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function selected_tariff( string $service_key ): array {
		$selected = $this->selected_tariffs();
		$value = $selected[ $service_key ] ?? array();

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function selected_tariffs(): array {
		$selected = $this->get( self::SELECTED_TARIFFS_KEY, array() );

		return is_array( $selected ) ? $selected : array();
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

	public function save_normalized_address_result( AddressNormalizationResult $result ): void {
		$this->set( self::NORMALIZED_ADDRESS_KEY, $result->to_array() );
	}

	public function normalized_address_result(): ?AddressNormalizationResult {
		$result = $this->get( self::NORMALIZED_ADDRESS_KEY, array() );

		return is_array( $result ) && array() !== $result ? AddressNormalizationResult::from_array( $result ) : null;
	}

	public function clear_normalized_address(): void {
		$this->set( self::NORMALIZED_ADDRESS_KEY, array() );
		$this->set( self::SELECTED_CITY_KEY, array() );
		$this->set( self::CITY_CONTEXT_KEY, array() );
		$this->set( self::FALLBACK_CITY_KEY, '' );
	}

	/**
	 * @param array<string,mixed> $city
	 */
	public function save_selected_city( array $city ): void {
		$this->set( self::SELECTED_CITY_KEY, $city );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function selected_city(): array {
		$city = $this->get( self::SELECTED_CITY_KEY, array() );

		return is_array( $city ) ? $city : array();
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function save_city_context( array $context ): void {
		$this->set( self::CITY_CONTEXT_KEY, $context );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function city_context(): array {
		$context = $this->get( self::CITY_CONTEXT_KEY, array() );

		return is_array( $context ) ? $context : array();
	}

	public function save_fallback_city( string $city ): void {
		$this->set( self::FALLBACK_CITY_KEY, $city );
	}

	public function fallback_city(): string {
		return (string) $this->get( self::FALLBACK_CITY_KEY, '' );
	}

	public function save_address_fingerprint( string $fingerprint ): void {
		$this->set( self::ADDRESS_FINGERPRINT_KEY, $fingerprint );
	}

	public function address_fingerprint(): string {
		return (string) $this->get( self::ADDRESS_FINGERPRINT_KEY, '' );
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

	private function normalize_rate_id( string $rate_id ): string {
		$prefix = NewShippingMethod::METHOD_ID . ':';
		if ( str_starts_with( $rate_id, $prefix ) ) {
			return substr( $rate_id, strlen( $prefix ) );
		}

		return $rate_id;
	}
}
