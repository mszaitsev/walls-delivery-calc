<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\SelfPickup;

defined( 'ABSPATH' ) || exit;

final class SelfPickupDiscountResult {
	public function __construct(
		public readonly bool $available,
		public readonly bool $applied,
		public readonly bool $eligible,
		public readonly int $amount_kopecks,
		public readonly float $percent,
		public readonly int $minimum_kopecks,
		public readonly string $fee_label
	) {
	}
}
