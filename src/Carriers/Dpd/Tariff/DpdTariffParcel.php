<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

defined( 'ABSPATH' ) || exit;

final class DpdTariffParcel {
	public function __construct(
		public readonly int $weight_g,
		public readonly float $length_cm,
		public readonly float $width_cm,
		public readonly float $height_cm,
		public readonly int $quantity = 1
	) {
	}
}
