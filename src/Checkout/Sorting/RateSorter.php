<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Sorting;

use WallsShop\WDC\Domain\Quote\DeliveryRate;

defined( 'ABSPATH' ) || exit;

final class RateSorter {
	public const CHEAPEST = 'cheapest';
	public const FASTEST  = 'fastest';

	/**
	 * @param array<int,DeliveryRate> $rates
	 * @return array<int,DeliveryRate>
	 */
	public function sort( array $rates, string $mode = self::CHEAPEST ): array {
		usort(
			$rates,
			static function ( DeliveryRate $left, DeliveryRate $right ) use ( $mode ): int {
				if ( self::FASTEST === $mode ) {
					return ( $left->delivery_days->min_days ?? PHP_INT_MAX ) <=> ( $right->delivery_days->min_days ?? PHP_INT_MAX )
						?: $left->price->get_kopecks() <=> $right->price->get_kopecks();
				}

				return $left->price->get_kopecks() <=> $right->price->get_kopecks()
					?: ( $left->delivery_days->min_days ?? PHP_INT_MAX ) <=> ( $right->delivery_days->min_days ?? PHP_INT_MAX );
			}
		);

		return array_values( $rates );
	}
}
