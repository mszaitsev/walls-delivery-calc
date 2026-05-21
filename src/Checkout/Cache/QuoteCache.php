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

	public function __construct(
		private ?object $cache = null
	) {
		if ( null === $this->cache && class_exists( '\WDC_Cache' ) ) {
			$this->cache = new \WDC_Cache();
		}

		$this->namespace = $this->load_namespace();
	}

	public function get( QuoteRequest $request, string $carrier_key, string $delivery_type = '' ): ?DeliveryQuote {
		$key  = $this->key( $request, $carrier_key, $delivery_type );
		if ( $this->cache instanceof \WDC_Cache ) {
			$data = $this->cache->get( $key );
		} else {
			$entry = self::$memory[ $key ] ?? null;
			if ( is_array( $entry ) && (int) ( $entry['expires'] ?? 0 ) < time() ) {
				unset( self::$memory[ $key ] );
				$entry = null;
			}

			$data = is_array( $entry ) ? ( $entry['value'] ?? null ) : null;
		}

		if ( ! is_array( $data ) ) {
			return null;
		}

		return DeliveryQuote::from_array( array_merge( $data, array( 'cache_hit' => true, 'source' => 'cache' ) ) );
	}

	public function set( QuoteRequest $request, string $carrier_key, DeliveryQuote $quote, string $delivery_type = '' ): void {
		$key  = $this->key( $request, $carrier_key, $delivery_type );
		$data = $quote->to_array();
		$ttl  = $this->ttl();

		if ( $this->cache instanceof \WDC_Cache ) {
			$this->cache->set( $key, $data, $ttl );
			return;
		}

		self::$memory[ $key ] = array( 'value' => $data, 'expires' => time() + $ttl );
	}

	public function invalidate_all(): void {
		$this->namespace = sha1( (string) microtime( true ) );
		self::$memory    = array();

		if ( function_exists( 'update_option' ) ) {
			update_option( 'wdc_quote_cache_namespace', $this->namespace, false );
		}
	}

	private function key( QuoteRequest $request, string $carrier_key, string $delivery_type ): string {
		$parts = array(
			$this->namespace,
			$request->country_code,
			$request->destination->city ?: $request->destination->settlement,
			(string) $request->package->get_total_weight_g(),
			(string) $request->order_total->get_kopecks(),
			$carrier_key,
			$delivery_type,
			$request->calculation_date,
		);

		return 'quote_' . sha1( implode( '|', array_map( 'strtolower', $parts ) ) );
	}

	private function ttl(): int {
		if ( $this->cache instanceof \WDC_Cache ) {
			return $this->cache->get_seconds_until_end_of_day();
		}

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
