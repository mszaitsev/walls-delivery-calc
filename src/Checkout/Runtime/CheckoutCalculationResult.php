<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Runtime;

use WallsShop\WDC\Domain\Quote\DeliveryRate;

defined( 'ABSPATH' ) || exit;

final class CheckoutCalculationResult {
	/**
	 * @param array<int,DeliveryRate> $rates
	 * @param array<int,array<string,mixed>> $audit
	 * @param array<string,string> $carrier_errors
	 */
	public function __construct(
		public readonly array $rates,
		public readonly bool $fallback_used,
		public readonly int $cache_hits,
		public readonly array $audit,
		public readonly array $carrier_errors
	) {
	}
}
