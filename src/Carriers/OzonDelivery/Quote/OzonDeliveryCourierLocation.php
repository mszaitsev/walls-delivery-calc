<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryCourierLocation {
	public function __construct(
		public readonly int $location_id,
		public readonly float $latitude,
		public readonly float $longitude
	) {}
}
