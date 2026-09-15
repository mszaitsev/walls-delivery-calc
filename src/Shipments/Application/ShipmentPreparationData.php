<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Domain\Package\ShipmentPlace;

defined( 'ABSPATH' ) || exit;

final class ShipmentPreparationData {
	/**
	 * @param array<int,ShipmentPlace> $places
	 * @param array<int,array<string,mixed>> $item_rows
	 */
	public function __construct(
		public readonly array $places,
		public readonly array $item_rows
	) {
	}
}
