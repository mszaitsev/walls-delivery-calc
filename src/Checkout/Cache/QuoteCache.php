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

	public function get( QuoteRequest $request, string $carrier_key, string $delivery_type = '', string $service_key = '', array $carrier_context = array() ): ?DeliveryQuote {
		$key   = $this->key( $request, $carrier_key, $delivery_type, $service_key, $carrier_context );
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

	public function set( QuoteRequest $request, string $carrier_key, DeliveryQuote $quote, string $delivery_type = '', string $service_key = '', array $carrier_context = array() ): void {
		$key  = $this->key( $request, $carrier_key, $delivery_type, $service_key, $carrier_context );
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

	public function cache_key( QuoteRequest $request, string $carrier_key, string $delivery_type = '', string $service_key = '', array $carrier_context = array() ): string {
		return $this->key( $request, $carrier_key, $delivery_type, $service_key, $carrier_context );
	}

	private function key( QuoteRequest $request, string $carrier_key, string $delivery_type, string $service_key = '', array $carrier_context = array() ): string {
		$destination = $request->destination;
		$pickup_selections = is_array( $request->customer_context['pickup_selections'] ?? null ) ? $request->customer_context['pickup_selections'] : array();
		$parts = array(
			$this->namespace,
			$request->country_code,
			$request->destination->city ?: $request->destination->settlement,
			$destination->region_name,
			$destination->postcode,
			$destination->street,
			$destination->house,
			$destination->apartment,
			$destination->raw_address,
			$destination->normalized ? 'normalized' : '',
			$destination->fallback ? 'fallback' : '',
			(string) $request->package->weight_g,
			(string) $request->package->packaging_weight_g,
			(string) $request->package->total_weight_g,
			(string) $request->package->get_total_weight_g(),
			(string) ( $request->package->length_cm ?? '' ),
			(string) ( $request->package->width_cm ?? '' ),
			(string) ( $request->package->height_cm ?? '' ),
			(string) $request->package->declared_value->get_kopecks(),
			(string) $request->order_total->get_kopecks(),
			(string) ( $request->customer_context['selected_location_id'] ?? '' ),
			(string) ( $request->customer_context['selected_location_fias_id'] ?? '' ),
			(string) ( $request->customer_context['dpd_selected_terminal_code'] ?? '' ),
			(string) ( $request->customer_context['dpd_delivery_terminal_code'] ?? '' ),
			$this->hash_context( $this->pickup_selection_cache_context( $pickup_selections ) ),
			$this->hash_context( $carrier_context ),
			$carrier_key,
			$service_key,
			$delivery_type,
			$request->calculation_date,
		);

		return 'quote_' . sha1( implode( '|', array_map( 'strtolower', $parts ) ) );
	}

	/** @param array<string,mixed> $selections @return array<string,mixed> */
	private function pickup_selection_cache_context( array $selections ): array {
		$out = array();
		ksort( $selections );
		foreach ( $selections as $family => $selection ) {
			if ( ! is_array( $selection ) ) {
				continue;
			}
			$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
			$out[ (string) $family ] = array(
				'carrier_key' => (string) ( $selection['carrier_key'] ?? $snapshot['carrier_key'] ?? '' ),
				'service_key' => (string) ( $selection['service_key'] ?? $snapshot['service_key'] ?? '' ),
				'pickup_family' => (string) ( $selection['pickup_family'] ?? $snapshot['pickup_family'] ?? $family ),
				'point_code' => (string) ( $selection['point_code'] ?? $selection['point_id'] ?? $snapshot['point_code'] ?? '' ),
				'destination_fingerprint' => (string) ( $selection['destination_fingerprint'] ?? $snapshot['destination_fingerprint'] ?? '' ),
			);
		}

		return $out;
	}

	/** @param array<string,mixed> $context */
	private function hash_context( array $context ): string {
		$normalized = $this->normalize_context_value( $context );
		$json = json_encode( $normalized, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION );

		return hash( 'sha256', false !== $json ? $json : serialize( $normalized ) );
	}

	private function normalize_context_value( mixed $value ): mixed {
		if ( null === $value || is_bool( $value ) || is_int( $value ) || is_string( $value ) ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return is_finite( $value ) ? $value : null;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( is_int( $key ) || is_string( $key ) ) {
					$out[ (string) $key ] = $this->normalize_context_value( $item );
				}
			}
			ksort( $out );

			return $out;
		}

		return null;
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
