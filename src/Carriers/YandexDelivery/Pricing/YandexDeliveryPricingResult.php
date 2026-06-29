<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pricing;

use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPricingResult {
	public function __construct(
		public readonly int $price_kopecks,
		public readonly ?int $delivery_days = null,
		public readonly array $raw = array()
	) {
	}

	public function delivery_time_label(): string {
		$label = DeliveryDaysFormatter::format_values( $this->delivery_days, $this->delivery_days );

		return '' !== $label ? $label : 'без указания срока';
	}
}
