<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Manual;

defined( 'ABSPATH' ) || exit;

final class ManualDeliveryWeightRange {
	public function __construct(
		public readonly int $from_weight_g,
		public readonly int $to_weight_g,
		public readonly int $price_kopecks,
		public readonly int $sort_order = 0
	) {
	}

	/**
	 * @return array{from_weight_g:int,to_weight_g:int,price_kopecks:int,sort_order:int}
	 */
	public function to_array(): array {
		return array(
			'from_weight_g' => $this->from_weight_g,
			'to_weight_g' => $this->to_weight_g,
			'price_kopecks' => $this->price_kopecks,
			'sort_order' => $this->sort_order,
		);
	}
}
