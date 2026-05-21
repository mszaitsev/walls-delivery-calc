<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Fias;

use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class FiasRateLimiter {
	private const MINUTE_KEY = 'wdc_fias_requests_minute';
	private const DAY_KEY = 'wdc_fias_requests_day';

	public function __construct(
		private SettingsRepository $settings,
		private FiasLogger $logger
	) {
	}

	public function can_request(): bool {
		$stats = $this->stats();
		$can = $stats['minute_count'] < $stats['minute_limit'] && $stats['day_count'] < $stats['daily_limit'];
		if ( ! $can ) {
			$this->logger->limiter_block( 'normalize', $stats );
		}

		return $can;
	}

	public function increment(): void {
		$this->set_counter( self::MINUTE_KEY, $this->counter( self::MINUTE_KEY ) + 1, defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 );
		$this->set_counter( self::DAY_KEY, $this->counter( self::DAY_KEY ) + 1, defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
	}

	public function stats(): array {
		$minute_limit = max( 1, $this->settings->get_int( 'fias_api_minute_limit', 100 ) );
		$daily_limit  = max( 1, $this->settings->get_int( 'fias_api_daily_limit', 10000 ) );

		return array(
			'minute_count' => $this->counter( self::MINUTE_KEY ),
			'minute_limit' => $minute_limit,
			'day_count'    => $this->counter( self::DAY_KEY ),
			'daily_limit'  => $daily_limit,
		);
	}

	private function counter( string $key ): int {
		$value = function_exists( 'get_transient' ) ? get_transient( $key ) : false;
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	private function set_counter( string $key, int $value, int $ttl ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $value, $ttl );
		}
	}
}
