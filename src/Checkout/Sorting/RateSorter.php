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
				fn( array $left, array $right ): int => $this->compare_group_rates( $left['rate'], $right['rate'], $mode ) ?: ( (int) $left['index'] <=> (int) $right['index'] )
			);
			$group_rates = array_map( static fn( array $row ): DeliveryRate => $row['rate'], $indexed_rates );
			$groups[ $key ]['rates'] = $group_rates;
			$groups[ $key ]['active'] = $group_rates[0] ?? null;
		}

		$group_rows = array_values( $groups );
		usort(
			$group_rows,
			function ( array $left, array $right ) use ( $mode ): int {
				$left_active = $left['active'] ?? null;
				$right_active = $right['active'] ?? null;
				if ( $left_active instanceof DeliveryRate && $right_active instanceof DeliveryRate ) {
					return $this->compare_method_rates( $left_active, $right_active, $mode ) ?: ( (int) $left['index'] <=> (int) $right['index'] );
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

	/**
	 * @param array<int,DeliveryRate> $rates
	 * @return array<int,DeliveryRate>
	 */
	public function sort_group_rates( array $rates, string $mode = self::CHEAPEST ): array {
		$indexed = array();
		foreach ( array_values( $rates ) as $index => $rate ) {
			$indexed[] = array( 'rate' => $rate, 'index' => $index );
		}

		usort(
			$indexed,
			fn( array $left, array $right ): int => $this->compare_group_rates( $left['rate'], $right['rate'], $mode ) ?: ( (int) $left['index'] <=> (int) $right['index'] )
		);

		return array_map( static fn( array $row ): DeliveryRate => $row['rate'], $indexed );
	}

	/**
	 * @param array<int,DeliveryRate> $rates
	 * @return array<int,DeliveryRate>
	 */
	public function sort_methods( array $rates, string $mode = self::CHEAPEST ): array {
		$indexed = array();
		foreach ( array_values( $rates ) as $index => $rate ) {
			$indexed[] = array( 'rate' => $rate, 'index' => $index );
		}

		usort(
			$indexed,
			fn( array $left, array $right ): int => $this->compare_method_rates( $left['rate'], $right['rate'], $mode ) ?: ( (int) $left['index'] <=> (int) $right['index'] )
		);

		return array_map( static fn( array $row ): DeliveryRate => $row['rate'], $indexed );
	}

	private function compare_group_rates( DeliveryRate $left, DeliveryRate $right, string $mode ): int {
		if ( self::FASTEST === $mode ) {
			return $this->original_min_days( $left ) <=> $this->original_min_days( $right )
				?: $this->original_cost_kopecks( $left ) <=> $this->original_cost_kopecks( $right )
				?: strnatcasecmp( $left->title, $right->title )
				?: strnatcasecmp( $left->tariff_key, $right->tariff_key )
				?: strnatcasecmp( $left->rate_id, $right->rate_id );
		}

		return $this->original_cost_kopecks( $left ) <=> $this->original_cost_kopecks( $right )
			?: $this->original_min_days( $left ) <=> $this->original_min_days( $right )
			?: strnatcasecmp( $left->title, $right->title )
			?: strnatcasecmp( $left->tariff_key, $right->tariff_key )
			?: strnatcasecmp( $left->rate_id, $right->rate_id );
	}

	private function compare_method_rates( DeliveryRate $left, DeliveryRate $right, string $mode ): int {
		if ( self::FASTEST === $mode ) {
			return $this->final_min_days( $left ) <=> $this->final_min_days( $right )
				?: $this->final_cost_kopecks( $left ) <=> $this->final_cost_kopecks( $right )
				?: strnatcasecmp( $left->title, $right->title )
				?: strnatcasecmp( $left->tariff_key, $right->tariff_key )
				?: strnatcasecmp( $left->rate_id, $right->rate_id );
		}

		return $this->final_cost_kopecks( $left ) <=> $this->final_cost_kopecks( $right )
			?: $this->final_min_days( $left ) <=> $this->final_min_days( $right )
			?: strnatcasecmp( $left->title, $right->title )
			?: strnatcasecmp( $left->tariff_key, $right->tariff_key )
			?: strnatcasecmp( $left->rate_id, $right->rate_id );
	}

	private function method_key( DeliveryRate $rate ): string {
		if ( ! empty( $rate->meta['tariff_selector_group'] ) ) {
			$key = trim( (string) ( $rate->meta['checkout_group_id'] ?? '' ) );
			if ( '' !== $key ) {
				return 'selector:' . $key;
			}
		}

		$key = trim( $rate->rate_id );

		return '' !== $key ? 'rate:' . $key : 'rate:' . spl_object_id( $rate );
	}

	private function original_cost_kopecks( DeliveryRate $rate ): int {
		return $rate->sorting_cost()->get_kopecks();
	}

	private function final_cost_kopecks( DeliveryRate $rate ): int {
		return $rate->price->get_kopecks();
	}

	private function original_min_days( DeliveryRate $rate ): int {
		return $rate->sorting_delivery_days()->min_days ?? PHP_INT_MAX;
	}

	private function final_min_days( DeliveryRate $rate ): int {
		return $rate->delivery_days->min_days ?? PHP_INT_MAX;
	}
}