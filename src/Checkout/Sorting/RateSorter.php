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
		$groups = array();
		foreach ( array_values( $rates ) as $index => $rate ) {
			$key = $this->method_key( $rate );
			$groups[ $key ] ??= array( 'index' => $index, 'rates' => array() );
			$groups[ $key ]['rates'][] = array( 'rate' => $rate, 'index' => $index );
		}

		foreach ( $groups as $key => $group ) {
			$indexed_rates = $group['rates'];
			usort(
				$indexed_rates,
				fn( array $left, array $right ): int => $this->compare_rates( $left['rate'], $right['rate'], $mode ) ?: ( (int) $left['index'] <=> (int) $right['index'] )
			);
			$group_rates = array_map( static fn( array $row ): DeliveryRate => $row['rate'], $indexed_rates );
			$groups[ $key ]['rates'] = $group_rates;
			$groups[ $key ]['best'] = $group_rates[0] ?? null;
		}

		$group_rows = array_values( $groups );
		usort(
			$group_rows,
			function ( array $left, array $right ) use ( $mode ): int {
				$left_best = $left['best'] ?? null;
				$right_best = $right['best'] ?? null;
				if ( $left_best instanceof DeliveryRate && $right_best instanceof DeliveryRate ) {
					return $this->compare_rates( $left_best, $right_best, $mode ) ?: ( (int) $left['index'] <=> (int) $right['index'] );
				}

				return ( (int) $left['index'] <=> (int) $right['index'] );
			}
		);

		$sorted = array();
		foreach ( $group_rows as $group ) {
			foreach ( $group['rates'] as $rate ) {
				$sorted[] = $rate;
			}
		}

		return $sorted;
	}

	private function compare_rates( DeliveryRate $left, DeliveryRate $right, string $mode ): int {
		if ( self::FASTEST === $mode ) {
			return $this->min_days( $left ) <=> $this->min_days( $right )
				?: $this->cost_kopecks( $left ) <=> $this->cost_kopecks( $right )
				?: strnatcasecmp( $left->title, $right->title )
				?: strnatcasecmp( $left->tariff_key, $right->tariff_key )
				?: strnatcasecmp( $left->rate_id, $right->rate_id );
		}

		return $this->cost_kopecks( $left ) <=> $this->cost_kopecks( $right )
			?: $this->min_days( $left ) <=> $this->min_days( $right )
			?: strnatcasecmp( $left->title, $right->title )
			?: strnatcasecmp( $left->tariff_key, $right->tariff_key )
			?: strnatcasecmp( $left->rate_id, $right->rate_id );
	}

	private function method_key( DeliveryRate $rate ): string {
		$key = trim( $rate->service_key );

		return '' !== $key ? $key : $rate->carrier_key;
	}

	private function cost_kopecks( DeliveryRate $rate ): int {
		return $rate->sorting_cost()->get_kopecks();
	}

	private function min_days( DeliveryRate $rate ): int {
		return $rate->sorting_delivery_days()->min_days ?? PHP_INT_MAX;
	}
}
