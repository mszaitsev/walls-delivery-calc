<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Manual;

defined( 'ABSPATH' ) || exit;

final class ManualDeliveryPricingConfig {
	/**
	 * @param array<int,ManualDeliveryWeightRange> $ranges
	 */
	public function __construct(
		public readonly string $mode,
		public readonly int $flat_price_kopecks,
		public readonly int $price_per_kg_kopecks,
		public readonly ?int $minimum_price_kopecks,
		public readonly int $billing_weight_step_g,
		public readonly array $ranges = array()
	) {
	}
}
