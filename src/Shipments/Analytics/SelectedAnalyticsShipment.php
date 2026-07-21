<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

defined( 'ABSPATH' ) || exit;

final class SelectedAnalyticsShipment {
	/**
	 * @param array<string,mixed> $shipment
	 */
	public function __construct(
		public readonly string $shipment_key,
		public readonly array $shipment,
		public readonly string $carrier_key,
		public readonly string $service_key
	) {
	}
}
