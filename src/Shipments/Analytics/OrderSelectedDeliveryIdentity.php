<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

defined( 'ABSPATH' ) || exit;

final class OrderSelectedDeliveryIdentity {
	public function __construct(
		public readonly string $carrier_key,
		public readonly string $service_key = ''
	) {
	}
}
