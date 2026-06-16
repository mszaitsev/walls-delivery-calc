<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Cache;

use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class QuoteCache {
	/** @var array<string,array<string,mixed>> */
	private static array $memory = array();

	private string $namespace = 'v1';

	public function __construct() {
		$this->namespace = $this->load_namespace();
	}

	public function get( QuoteRequest $request, string $carrier_key, string $delivery_type = '', string $service_key = '' ): ?DeliveryQuote {
		$key   = $this->key( $request, $carrier_key, $delivery_type, $service_key );
		$entry = self::$memory[ $key ] ?? null;
		if ( is_array( $entry ) && (int) ( $entry['expires'] ?? 0 ) < time() ) {
			unset( self::$memory[ $key ] );
			$entry = null;
		}

		$data = is_array( $entry ) ? ( $entry['value'] ?? null ) : null;
		if ( ! is_array( $data ) ) {
			return null;
		}

		return DeliveryQuote::from_array( array_merge( $data, array( 'cache_hit' => true, 'source' => 'cache' ) ) );
	}

	public function set( QuoteRequest $request, string $carrier_key, DeliveryQuote $quote, string $delivery_type = '', string $service_key = '' ): void {
		$key  = $this->key( $request, $carrier_key, $delivery_type, $service_key );
		$data = $quote->to_array();
		$ttl  = $this->ttl();

		self::$memory[ $key ] = array( 'value' => $data, 'expires' => time() + $ttl );
	}

	public function invalidate_all(): void {
		$this->namespace = sha1( (string) microtime( true ) );
		self::$memory    = array();

		if ( function_exists( 'update_option' ) ) {
			update_option( 'wdc_quote_cache_namespace', $this->namespace, false );
		}
	}

	public function cache_key( QuoteRequest $request, string $carrier_key, string $delivery_type = '', string $service_key = '' ): string {
		return $this->key( $request, $carrier_key, $delivery_type, $service_key );
	}

	private function key( QuoteRequest $request, string $carrier_key, string $delivery_type, string $service_key = '' ): string {
		$parts = array(
			$this->namespace,
			$request->country_code,
			$request->destination->city ?: $request->destination->settlement,
			(string) $request->package->get_total_weight_g(),
			(string) ( $request->package->length_cm ?? '' ),
			(string) ( $request->package->width_cm ?? '' ),
			(string) ( $request->package->height_cm ?? '' ),
			(string) $request->package->declared_value->get_kopecks(),
			(string) $request->order_total->get_kopecks(),
			(string) ( $request->customer_context['selected_location_id'] ?? '' ),
			(string) ( $request->customer_context['selected_location_fias_id'] ?? '' ),
			$carrier_key,
			$service_key,
			$delivery_type,
			$request->calculation_date,
		);

		return 'quote_' . sha1( implode( '|', array_map( 'strtolower', $parts ) ) );
	}

	private function ttl(): int {
		$end = strtotime( 'today 23:59:59' );

		return is_int( $end ) ? max( 1, $end - time() ) : 86400;
	}

	private function load_namespace(): string {
		if ( function_exists( 'get_option' ) ) {
			$value = get_option( 'wdc_quote_cache_namespace', 'v1' );

			return is_string( $value ) && '' !== $value ? $value : 'v1';
		}

		return 'v1';
	}
}
