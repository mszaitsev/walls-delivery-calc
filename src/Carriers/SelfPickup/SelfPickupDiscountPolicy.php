<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\SelfPickup;

defined( 'ABSPATH' ) || exit;

final class SelfPickupDiscountPolicy {
	/** @param array{enabled:bool,percent:float,minimum_kopecks:int,fee_label:string} $settings */
	public function calculate( bool $selected, int $base_kopecks, array $settings ): SelfPickupDiscountResult {
		$percent = max( 0.0, min( 100.0, (float) $settings['percent'] ) );
		$minimum = max( 0, (int) $settings['minimum_kopecks'] );
		$available = ! empty( $settings['enabled'] ) && $percent > 0.0 && $base_kopecks >= $minimum;
		$applied = $available && $selected;

		return new SelfPickupDiscountResult(
			$available,
			$applied,
			$applied,
			$applied ? max( 0, (int) round( $base_kopecks * $percent / 100 ) ) : 0,
			$percent,
			$minimum,
			(string) $settings['fee_label']
		);
	}
}
