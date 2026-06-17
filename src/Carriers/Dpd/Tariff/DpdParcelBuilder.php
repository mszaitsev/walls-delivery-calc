<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Tariff;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class DpdParcelBuilder {
	private const BOX_LIMIT = array( 'length' => 50, 'width' => 50, 'height' => 30 );

	public function __construct(
		private DpdSettings $settings
	) {
	}

	/**
	 * @return array{parcels:array<int,DpdTariffParcel>,declared_value_rub:float,package_builder_source:string,parcels_count:int,total_weight_g:int,dimensions:array{length:float,width:float,height:float},box_limit:array{length:int,width:int,height:int}}
	 */
	public function build( QuoteRequest $request ): array {
		$package = $request->package;
		$total_weight_g = max( 1, $package->get_total_weight_g(), $this->settings->tariff_default_weight_g() );
		$declared_value = $this->declared_value_rub( $request );
		$source = 'defaults';
		$dimensions = $this->default_dimensions();

		if ( $this->has_package_dimensions( $package ) ) {
			$dimensions = array(
				'length' => (float) $package->length_cm,
				'width' => (float) $package->width_cm,
				'height' => (float) $package->height_cm,
			);
			$source = 'package_dimensions';
		} else {
			$fit = $this->single_box_fit( $package );
			if ( is_array( $fit ) ) {
				$dimensions = array(
					'length' => (float) $fit['length'],
					'width' => (float) $fit['width'],
					'height' => (float) $fit['height'],
				);
				$source = 'items_single_box_fit';
			}
		}

		return array(
			'parcels' => array( new DpdTariffParcel( $total_weight_g, $dimensions['length'], $dimensions['width'], $dimensions['height'], 1 ) ),
			'declared_value_rub' => $declared_value,
			'package_builder_source' => $source,
			'parcels_count' => 1,
			'total_weight_g' => $total_weight_g,
			'dimensions' => $dimensions,
			'box_limit' => self::BOX_LIMIT,
		);
	}

	private function declared_value_rub( QuoteRequest $request ): float {
		$declared = $request->package->declared_value->get_rubles();
		if ( $declared > 0 ) {
			return $declared;
		}
		$total = $request->order_total->get_rubles();
		if ( $total > 0 ) {
			return $total;
		}

		return $this->settings->tariff_default_declared_value_rub();
	}

	private function has_package_dimensions( Package $package ): bool {
		return null !== $package->length_cm && $package->length_cm > 0
			&& null !== $package->width_cm && $package->width_cm > 0
			&& null !== $package->height_cm && $package->height_cm > 0;
	}

	/**
	 * @return array{length:float,width:float,height:float}
	 */
	private function default_dimensions(): array {
		return array(
			'length' => max( 0.1, $this->settings->tariff_default_length_cm() ),
			'width' => max( 0.1, $this->settings->tariff_default_width_cm() ),
			'height' => max( 0.1, $this->settings->tariff_default_height_cm() ),
		);
	}

	/**
	 * @return array{length:int,width:int,height:int}|null
	 */
	private function single_box_fit( Package $package ): ?array {
		$items = $this->expanded_item_dimensions( $package );
		if ( array() === $items ) {
			return null;
		}
		$total_volume = 0;
		foreach ( $items as $dimensions ) {
			$total_volume += $dimensions['length'] * $dimensions['width'] * $dimensions['height'];
			if ( ! $this->item_fits_box( $dimensions, self::BOX_LIMIT ) ) {
				return null;
			}
		}
		if ( $total_volume > self::BOX_LIMIT['length'] * self::BOX_LIMIT['width'] * self::BOX_LIMIT['height'] ) {
			return null;
		}

		return $this->calculated_single_box_dimensions( $items, self::BOX_LIMIT );
	}

	/**
	 * @return array<int,array{length:int,width:int,height:int}>
	 */
	private function expanded_item_dimensions( Package $package ): array {
		$defaults = $this->default_dimensions();
		$items = array();
		foreach ( $package->get_items() as $item ) {
			if ( ! $item instanceof PackageItem || 'WDC_PACKAGING' === strtoupper( trim( $item->sku ) ) ) {
				continue;
			}
			$dimensions = array(
				'length' => $this->dimension_or_default( $item->length_cm, (int) $defaults['length'] ),
				'width' => $this->dimension_or_default( $item->width_cm, (int) $defaults['width'] ),
				'height' => $this->dimension_or_default( $item->height_cm, (int) $defaults['height'] ),
			);
			for ( $index = 0; $index < max( 0, $item->quantity ); ++$index ) {
				$items[] = $dimensions;
			}
		}

		return $items;
	}

	/**
	 * @param array{length:int,width:int,height:int} $item
	 * @param array{length:int,width:int,height:int} $box
	 */
	private function item_fits_box( array $item, array $box ): bool {
		foreach ( $this->orientations( $item ) as $orientation ) {
			if ( $orientation['length'] <= $box['length'] && $orientation['width'] <= $box['width'] && $orientation['height'] <= $box['height'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,array{length:int,width:int,height:int}> $items
	 * @param array{length:int,width:int,height:int} $box
	 * @return array{length:int,width:int,height:int}|null
	 */
	private function calculated_single_box_dimensions( array $items, array $box ): ?array {
		if ( $this->all_dimensions_equal( $items ) ) {
			return $this->single_sku_box_dimensions( $items[0], count( $items ), $box );
		}
		$best = null;
		usort( $items, static fn( array $a, array $b ): int => ( $b['length'] * $b['width'] * $b['height'] ) <=> ( $a['length'] * $a['width'] * $a['height'] ) );
		foreach ( $this->orientations( $items[0] ) as $first_orientation ) {
			$layout = $this->row_layer_layout_dimensions( $items, $box, $first_orientation );
			if ( is_array( $layout ) ) {
				$best = $this->better_box( $best, $layout );
			}
		}

		return $best;
	}

	/**
	 * @param array<int,array{length:int,width:int,height:int}> $items
	 */
	private function all_dimensions_equal( array $items ): bool {
		$first = $items[0] ?? null;
		if ( ! is_array( $first ) ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( $item !== $first ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * @param array{length:int,width:int,height:int} $item
	 * @param array{length:int,width:int,height:int} $box
	 * @return array{length:int,width:int,height:int}|null
	 */
	private function single_sku_box_dimensions( array $item, int $quantity, array $box ): ?array {
		$best = null;
		foreach ( $this->orientations( $item ) as $orientation ) {
			for ( $x = 1; $x <= $quantity; ++$x ) {
				for ( $y = 1; $y <= $quantity; ++$y ) {
					$z = (int) ceil( $quantity / max( 1, $x * $y ) );
					if ( $x * $y * $z < $quantity ) {
						continue;
					}
					$candidate = array(
						'length' => $orientation['length'] * $x,
						'width' => $orientation['width'] * $y,
						'height' => $orientation['height'] * $z,
					);
					if ( $this->box_within_limits( $candidate, $box ) ) {
						$best = $this->better_box( $best, $candidate );
					}
				}
			}
		}

		return $best;
	}

	/**
	 * @param array<int,array{length:int,width:int,height:int}> $items
	 * @param array{length:int,width:int,height:int} $box
	 * @param array{length:int,width:int,height:int} $first_orientation
	 * @return array{length:int,width:int,height:int}|null
	 */
	private function row_layer_layout_dimensions( array $items, array $box, array $first_orientation ): ?array {
		$length = 0;
		$used_length = 0;
		$row_width = 0;
		$layer_height = 0;
		$used_width = 0;
		$used_height = 0;
		foreach ( $items as $index => $item ) {
			$placed = false;
			$orientations = 0 === $index ? array( $first_orientation ) : $this->orientations( $item );
			foreach ( $orientations as $orientation ) {
				if ( $length + $orientation['length'] <= $box['length'] && max( $used_width, $row_width + $orientation['width'] ) <= $box['width'] && max( $used_height, $layer_height + $orientation['height'] ) <= $box['height'] ) {
					$length += $orientation['length'];
					$used_length = max( $used_length, $length );
					$used_width = max( $used_width, $row_width + $orientation['width'] );
					$used_height = max( $used_height, $layer_height + $orientation['height'] );
					$placed = true;
					break;
				}
			}
			if ( $placed ) {
				continue;
			}
			$length = 0;
			$row_width = $used_width;
			foreach ( $this->orientations( $item ) as $orientation ) {
				if ( $orientation['length'] <= $box['length'] && $row_width + $orientation['width'] <= $box['width'] && max( $used_height, $layer_height + $orientation['height'] ) <= $box['height'] ) {
					$length = $orientation['length'];
					$used_length = max( $used_length, $length );
					$used_width = max( $used_width, $row_width + $orientation['width'] );
					$used_height = max( $used_height, $layer_height + $orientation['height'] );
					$placed = true;
					break;
				}
			}
			if ( ! $placed ) {
				return null;
			}
		}
		$candidate = array( 'length' => min( $box['length'], max( 1, $used_length ) ), 'width' => max( 1, $used_width ), 'height' => max( 1, $used_height ) );

		return $this->box_within_limits( $candidate, $box ) ? $candidate : null;
	}

	/**
	 * @param array{length:int,width:int,height:int} $dimensions
	 * @return array<int,array{length:int,width:int,height:int}>
	 */
	private function orientations( array $dimensions ): array {
		$values = array_values( $dimensions );
		$permutations = array(
			array( $values[0], $values[1], $values[2] ),
			array( $values[0], $values[2], $values[1] ),
			array( $values[1], $values[0], $values[2] ),
			array( $values[1], $values[2], $values[0] ),
			array( $values[2], $values[0], $values[1] ),
			array( $values[2], $values[1], $values[0] ),
		);
		$orientations = array();
		foreach ( $permutations as $permutation ) {
			$key = implode( 'x', $permutation );
			$orientations[ $key ] = array( 'length' => $permutation[0], 'width' => $permutation[1], 'height' => $permutation[2] );
		}

		return array_values( $orientations );
	}

	/**
	 * @param array{length:int,width:int,height:int} $candidate
	 * @param array{length:int,width:int,height:int} $limits
	 */
	private function box_within_limits( array $candidate, array $limits ): bool {
		return $candidate['length'] <= $limits['length'] && $candidate['width'] <= $limits['width'] && $candidate['height'] <= $limits['height'];
	}

	/**
	 * @param array{length:int,width:int,height:int}|null $best
	 * @param array{length:int,width:int,height:int} $candidate
	 * @return array{length:int,width:int,height:int}
	 */
	private function better_box( ?array $best, array $candidate ): array {
		if ( null === $best ) {
			return $candidate;
		}
		$best_volume = $best['length'] * $best['width'] * $best['height'];
		$candidate_volume = $candidate['length'] * $candidate['width'] * $candidate['height'];
		if ( $candidate_volume === $best_volume ) {
			return ( implode( 'x', $candidate ) < implode( 'x', $best ) ) ? $candidate : $best;
		}

		return $candidate_volume < $best_volume ? $candidate : $best;
	}

	private function dimension_or_default( int $value, int $default ): int {
		return max( 1, $value > 0 ? $value : $default );
	}
}
