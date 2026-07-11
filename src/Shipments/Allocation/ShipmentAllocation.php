<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Allocation;

final class ShipmentAllocation {
	/**
	 * @param array<int,ShipmentAllocationPlace> $places
	 */
	public function __construct(
		public readonly array $places
	) {
	}
}
