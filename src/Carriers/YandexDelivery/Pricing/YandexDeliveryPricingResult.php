<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pricing;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPricingResult {
	public function __construct(
		public readonly int $price_kopecks,
		public readonly ?int $delivery_days = null,
		public readonly array $raw = array()
	) {
	}

	public function delivery_time_label(): string {
		return null !== $this->delivery_days && $this->delivery_days > 0 ? $this->delivery_days . ' дн.' : 'без указания срока';
	}
}
