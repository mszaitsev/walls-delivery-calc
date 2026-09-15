<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostThresholdPolicy {
	public const ALLOWED_OVERAGE_PERCENT = 3;
	public const STATUS_WITHIN_THRESHOLD = 'within_threshold';
	public const STATUS_OVER_THRESHOLD = 'over_threshold';
	public const STATUS_NOT_COMPARABLE = 'not_comparable';

	public function classify( ?int $base_kopecks, ?int $actual_kopecks ): string {
		if ( null === $base_kopecks || null === $actual_kopecks || $base_kopecks <= 0 || $actual_kopecks <= 0 ) {
			return self::STATUS_NOT_COMPARABLE;
		}

		return $actual_kopecks * 100 <= $base_kopecks * ( 100 + self::ALLOWED_OVERAGE_PERCENT )
			? self::STATUS_WITHIN_THRESHOLD
			: self::STATUS_OVER_THRESHOLD;
	}
}
