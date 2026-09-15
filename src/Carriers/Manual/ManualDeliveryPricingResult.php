<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Manual;

use WallsShop\WDC\Domain\Common\Money;

defined( 'ABSPATH' ) || exit;

final class ManualDeliveryPricingResult {
	public function __construct(
		public readonly bool $available,
		public readonly ?Money $price,
		public readonly string $pricing_mode,
		public readonly int $chargeable_weight_g,
		public readonly int $billing_weight_g,
		public readonly ?ManualDeliveryWeightRange $matched_range = null,
		public readonly string $reason = ''
	) {
	}

	public static function unavailable( string $mode, int $chargeable_weight_g, int $billing_weight_g, string $reason ): self {
		return new self( false, null, $mode, $chargeable_weight_g, $billing_weight_g, null, $reason );
	}
}
