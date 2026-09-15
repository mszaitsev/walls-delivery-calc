<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Application;

use Throwable;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class ShopProcessingOrderQueueCounter {
	public const CACHE_TTL_SECONDS = 300;
	private const TRANSIENT_PREFIX = 'wdc_shop_processing_queue_count_';
	private const GENERATION_OPTION = 'wdc_shop_processing_queue_count_generation';
	private const LAST_COUNTS_OPTION = 'wdc_shop_processing_queue_last_counts';
	private const LAST_COUNTS_LIMIT = 20;

	public function __construct(
		private Logger $logger,
		private mixed $order_query = null
	) {
	}

	/**
	 * @param list<string> $statuses
	 */
	public function count( array $statuses ): int {
		$statuses = SettingsRepository::normalize_order_status_values( $statuses );
		if ( array() === $statuses ) {
			return 0;
		}

		sort( $statuses );
		$fingerprint = $this->fingerprint( $statuses );
		$cache_key = $this->cache_key( $fingerprint );
		$cached = $this->get_transient( $cache_key );
		if ( is_numeric( $cached ) ) {
			return max( 0, (int) $cached );
		}

		try {
			$count = $this->query_total( $statuses );
			$this->set_transient( $cache_key, $count, self::CACHE_TTL_SECONDS );
			$this->remember_successful_count( $fingerprint, $count );

			return $count;
		} catch ( Throwable $exception ) {
			$this->logger->warning(
				'Unable to resolve dynamic shop processing order queue count.',
				array(
					'statuses' => $statuses,
					'error'    => $exception->getMessage(),
				)
			);
			$last = $this->last_successful_count( $fingerprint );

			return null !== $last ? $last : 0;
		}
	}

	public function invalidate(): void {
		$generation = $this->generation() + 1;
		if ( function_exists( 'update_option' ) ) {
			update_option( self::GENERATION_OPTION, $generation, false );
		}
	}

	/**
	 * @param list<string> $statuses
	 */
	private function query_total( array $statuses ): int {
		$query = $this->order_query;
		if ( null !== $query ) {
			$result = $query( $statuses );

			return max( 0, (int) $result );
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			throw new \RuntimeException( 'wc_get_orders is unavailable.' );
		}

		$result = wc_get_orders(
			array(
				'status'   => $statuses,
				'limit'    => 1,
				'paginate' => true,
				'return'   => 'ids',
			)
		);

		if ( is_object( $result ) && isset( $result->total ) && is_numeric( $result->total ) ) {
			return max( 0, (int) $result->total );
		}

		throw new \RuntimeException( 'wc_get_orders returned an unexpected result for a paginated count query.' );
	}

	/**
	 * @param list<string> $statuses
	 */
	private function fingerprint( array $statuses ): string {
		return md5( implode( ',', $statuses ) );
	}

	private function cache_key( string $fingerprint ): string {
		return self::TRANSIENT_PREFIX . $this->generation() . '_' . $fingerprint;
	}

	private function generation(): int {
		return max( 1, (int) ( function_exists( 'get_option' ) ? get_option( self::GENERATION_OPTION, 1 ) : 1 ) );
	}

	private function get_transient( string $key ): mixed {
		return function_exists( 'get_transient' ) ? get_transient( $key ) : false;
	}

	private function set_transient( string $key, int $value, int $ttl ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $value, $ttl );
		}
	}

	private function last_successful_count( string $fingerprint ): ?int {
		$counts = $this->last_counts();
		if ( ! isset( $counts[ $fingerprint ] ) || ! is_numeric( $counts[ $fingerprint ] ) ) {
			return null;
		}

		return max( 0, (int) $counts[ $fingerprint ] );
	}

	private function remember_successful_count( string $fingerprint, int $count ): void {
		if ( ! function_exists( 'update_option' ) ) {
			return;
		}

		$counts = $this->last_counts();
		unset( $counts[ $fingerprint ] );
		$counts = array( $fingerprint => $count ) + $counts;
		$counts = array_slice( $counts, 0, self::LAST_COUNTS_LIMIT, true );
		update_option( self::LAST_COUNTS_OPTION, $counts, false );
	}

	/**
	 * @return array<string, int>
	 */
	private function last_counts(): array {
		$value = function_exists( 'get_option' ) ? get_option( self::LAST_COUNTS_OPTION, array() ) : array();
		if ( ! is_array( $value ) ) {
			return array();
		}

		$counts = array();
		foreach ( $value as $fingerprint => $count ) {
			if ( is_string( $fingerprint ) && is_numeric( $count ) ) {
				$counts[ $fingerprint ] = max( 0, (int) $count );
			}
		}

		return $counts;
	}
}
