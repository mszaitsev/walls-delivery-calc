<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryQuoteResult {
	/** @param array<string,mixed> $meta */
	public function __construct(
		public readonly Money $price,
		public readonly DateRange $delivery_days,
		public readonly string $destination_point_id,
		public readonly int $package_count,
		public readonly int $shipment_method_id,
		public readonly string $endpoint,
		public readonly int $http_status,
		public readonly array $meta = array()
	) {
	}
}
