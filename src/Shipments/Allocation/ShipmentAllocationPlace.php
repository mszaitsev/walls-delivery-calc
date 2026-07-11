<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Allocation;

final class ShipmentAllocationPlace {
	/**
	 * @param array<int,ShipmentAllocationItem> $items
	 */
	public function __construct(
		public readonly int $place_number,
		public readonly int $weight_g,
		public readonly int $length_cm,
		public readonly int $width_cm,
		public readonly int $height_cm,
		public readonly array $items
	) {
	}
}
