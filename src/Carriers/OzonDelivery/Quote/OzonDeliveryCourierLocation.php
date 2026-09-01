<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryCourierLocation {
	public function __construct(
		public readonly string $source,
		public readonly int $location_id,
		public readonly float $latitude,
		public readonly float $longitude,
		public readonly ?int $proxy_point_id = null,
		public readonly ?int $proxy_distance_m = null,
		public readonly string $address_fingerprint = ''
	) {}
}
