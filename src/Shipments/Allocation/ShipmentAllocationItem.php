<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Allocation;

/**
 * A quantity of one original order row allocated to one shipment place.
 */
final class ShipmentAllocationItem {
	/**
	 * @param array<string,mixed> $identity Stable source identifiers (order_item_id preferred).
	 */
	public function __construct(
		public readonly string $source_item_id,
		public readonly array $identity,
		public readonly string $name,
		public readonly string $sku,
		public readonly int $quantity,
		public readonly int $unit_price_kopecks,
		public readonly int $weight_g = 0
	) {
	}
}
