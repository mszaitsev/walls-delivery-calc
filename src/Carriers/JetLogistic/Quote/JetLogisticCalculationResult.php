<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Quote;

defined( 'ABSPATH' ) || exit;

final class JetLogisticCalculationResult {
	public function __construct(
		public readonly int $price_zabor,
		public readonly int $price_terminal,
		public readonly int $price_delivery,
		public readonly int $price_dop,
		public readonly string $city_from,
		public readonly string $city_terminal_from,
		public readonly string $city_terminal_to,
		public readonly string $city_to,
		public readonly ?int $day_from,
		public readonly ?int $day_to,
		public readonly string $valuta,
		public readonly string $valuta_name
	) {
	}
}
