<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

defined( 'ABSPATH' ) || exit;

final class CreatedShipmentIdentity {
	public function __construct(
		public readonly string $field,
		public readonly string $value
	) {
	}
}
