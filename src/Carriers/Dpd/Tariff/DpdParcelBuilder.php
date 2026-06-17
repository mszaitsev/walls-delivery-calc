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
	private const LONG_ITEM_THRESHOLD_CM = 49;
	private const STACKED_ROW_WIDTH_LIMIT_CM = 45;

	public function __construct(
		private DpdSettings $settings
	) {
	}

	/**
	 * @return array{parcels:array<int,DpdTariffParcel>,declared_value_rub:float,package_builder_source:string,parcels_count:int,long_item_parcels_count:int,regular_items_count:int,total_weight_g:int,dimensions:array{length:float,width:float,height:float},parcel_dimensions:array<int,array{weight_g:int,length:float,width:float,height:float,quantity:int}>,box_limit:array{length:int,width:int,height:int,row_width_limit:int,long_item_threshold:int}}
	 */
	public function build( QuoteRequest $request ): array {
		$package = $request->package;
		$total_weight_g = max( 1, $package->get_total_weight_g(), $this->settings->tariff_default_weight_g() );
		$declared_value = $this->declared_value_rub( $request );
		$items = $this->expanded_items( $package );
		$long_items = array();
		$regular_items = array();
		foreach ( $items as $item ) {
			if ( max( $item['length'], $item['width'], $item['height'] ) > self::LONG_ITEM_THRESHOLD_CM ) {
				$long_items[] = $item;
			} else {
				$regular_items[] = $item;
			}
		}

		$parcels = array();
		foreach ( $long_items as $item ) {
			$parcels[] = new DpdTariffParcel( max( 1, $item['weight_g'] ), (float) $item['length'], (float) $item['width'], (float) $item['height'], 1 );
		}

		$regular_source = '';
		if ( array() !== $regular_items ) {
			$regular = $this->regular_items_parcel( $regular_items, $package );
			$parcels[] = $regular['parcel'];
			$regular_source = $regular['source'];
		}

		if ( array() === $parcels ) {
			$fallback = $this->fallback_parcel( $package, $total_weight_g );
			$parcels[] = $fallback['parcel'];
			$regular_source = $fallback['source'];
		}

		$source = $this->source( count( $long_items ), $regular_source );
		$parcel_dimensions = $this->parcel_dimensions( $parcels );
		$dimensions = $this->aggregate_dimensions( $parcel_dimensions );

		return array(
			'parcels' => $parcels,
			'declared_value_rub' => $declared_value,
			'package_builder_source' => $source,
			'parcels_count' => count( $parcels ),
			'long_item_parcels_count' => count( $long_items ),
			'regular_items_count' => count( $regular_items ),
			'total_weight_g' => array_sum( array_map( static fn( DpdTariffParcel $parcel ): int => $parcel->weight_g * max( 1, $parcel->quantity ), $parcels ) ),
			'dimensions' => $dimensions,
			'parcel_dimensions' => $parcel_dimensions,
			'box_limit' => array_merge( self::BOX_LIMIT, array( 'row_width_limit' => self::STACKED_ROW_WIDTH_LIMIT_CM, 'long_item_threshold' => self::LONG_ITEM_THRESHOLD_CM ) ),
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
	 * @param array<int,array{weight_g:int,length:int,width:int,height:int}> $regular_items
	 * @return array{parcel:DpdTariffParcel,source:string}
	 */
	private function regular_items_parcel( array $regular_items, Package $package ): array {
		$weight = array_sum( array_map( static fn( array $item ): int => max( 1, $item['weight_g'] ), $regular_items ) );
		if ( $this->items_have_dimensions( $regular_items ) ) {
			$fit = $this->single_box_fit( $regular_items );
			if ( is_array( $fit ) ) {
				return array( 'parcel' => new DpdTariffParcel( $weight, (float) $fit['length'], (float) $fit['width'], (float) $fit['height'], 1 ), 'source' => 'items_single_box_fit' );
			}

			$stacked = $this->stacked_rows_dimensions( $regular_items );
			return array( 'parcel' => new DpdTariffParcel( $weight, (float) $stacked['length'], (float) $stacked['width'], (float) $stacked['height'], 1 ), 'source' => 'items_stacked_rows' );
		}

		if ( $this->has_package_dimensions( $package ) ) {
			return array(
				'parcel' => new DpdTariffParcel( $weight, (float) $package->length_cm, (float) $package->width_cm, (float) $package->height_cm, 1 ),
				'source' => 'package_dimensions',
			);
		}
		$dimensions = $this->default_dimensions();

		return array( 'parcel' => new DpdTariffParcel( $weight, $dimensions['length'], $dimensions['width'], $dimensions['height'], 1 ), 'source' => 'defaults' );
	}

	/**
	 * @return array{parcel:DpdTariffParcel,source:string}
	 */
	private function fallback_parcel( Package $package, int $weight_g ): array {
		if ( $this->has_package_dimensions( $package ) ) {
			return array(
				'parcel' => new DpdTariffParcel( $weight_g, (float) $package->length_cm, (float) $package->width_cm, (float) $package->height_cm, 1 ),
				'source' => 'package_dimensions',
			);
		}
		$dimensions = $this->default_dimensions();

		return array( 'parcel' => new DpdTariffParcel( $weight_g, $dimensions['length'], $dimensions['width'], $dimensions['height'], 1 ), 'source' => 'defaults' );
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
	private function single_box_fit( array $items ): ?array {
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
	private function expanded_items( Package $package ): array {
		$items = array();
		foreach ( $package->get_items() as $item ) {
			if ( ! $item instanceof PackageItem || 'WDC_PACKAGING' === strtoupper( trim( $item->sku ) ) ) {
				continue;
			}
			$dimensions = array(
				'weight_g' => max( 1, $item->weight_g ),
				'length' => max( 0, $item->length_cm ),
				'width' => max( 0, $item->width_cm ),
				'height' => max( 0, $item->height_cm ),
			);
			for ( $index = 0; $index < max( 0, $item->quantity ); ++$index ) {
				$items[] = $dimensions;
			}
		}

		return $items;
	}

	/**
	 * @param array<int,array{weight_g:int,length:int,width:int,height:int}> $items
	 */
	private function items_have_dimensions( array $items ): bool {
		foreach ( $items as $item ) {
			if ( $item['length'] <= 0 || $item['width'] <= 0 || $item['height'] <= 0 ) {
				return false;
			}
		}

		return array() !== $items;
	}

	/**
	 * @param array<int,array{weight_g:int,length:int,width:int,height:int}> $items
	 * @return array{length:int,width:int,height:int}
	 */
	private function stacked_rows_dimensions( array $items ): array {
		$rows = array();
		$current = array( 'width' => 0, 'height' => 0 );
		$length = 0;
		foreach ( $items as $item ) {
			$oriented = $this->natural_orientation( $item );
			$length = max( $length, $oriented['length'] );
			$current['width'] += $oriented['width'];
			$current['height'] = max( $current['height'], $oriented['height'] );
			if ( $current['width'] >= self::STACKED_ROW_WIDTH_LIMIT_CM ) {
				$rows[] = $current;
				$current = array( 'width' => 0, 'height' => 0 );
			}
		}
		if ( $current['width'] > 0 ) {
			$rows[] = $current;
		}

		return array(
			'length' => max( 1, $length ),
			'width' => max( 1, max( array_map( static fn( array $row ): int => $row['width'], $rows ) ) ),
			'height' => max( 1, array_sum( array_map( static fn( array $row ): int => $row['height'], $rows ) ) ),
		);
	}

	/**
	 * @param array{length:int,width:int,height:int} $item
	 * @return array{length:int,width:int,height:int}
	 */
	private function natural_orientation( array $item ): array {
		$values = array_values( $this->item_dimensions( $item ) );
		sort( $values );

		return array( 'length' => $values[2], 'width' => $values[1], 'height' => $values[0] );
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
			$natural = $this->natural_orientation( $items[0] );
			$candidate = array(
				'length' => $natural['length'],
				'width' => $natural['width'] * count( $items ),
				'height' => $natural['height'],
			);

			return $candidate['width'] <= self::STACKED_ROW_WIDTH_LIMIT_CM && $this->box_within_limits( $candidate, $box ) ? $candidate : null;
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
		$first_dimensions = $this->item_dimensions( $first );
		foreach ( $items as $item ) {
			if ( $this->item_dimensions( $item ) !== $first_dimensions ) {
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
		$values = array_values( $this->item_dimensions( $dimensions ) );
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
	 * @param array{length:int,width:int,height:int} $item
	 * @return array{length:int,width:int,height:int}
	 */
	private function item_dimensions( array $item ): array {
		return array(
			'length' => (int) $item['length'],
			'width' => (int) $item['width'],
			'height' => (int) $item['height'],
		);
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

	private function source( int $long_count, string $regular_source ): string {
		if ( $long_count > 0 && '' !== $regular_source ) {
			return 'mixed_' . $regular_source;
		}
		if ( $long_count > 0 ) {
			return 'long_items';
		}

		return '' !== $regular_source ? $regular_source : 'defaults';
	}

	/**
	 * @param array<int,DpdTariffParcel> $parcels
	 * @return array<int,array{weight_g:int,length:float,width:float,height:float,quantity:int}>
	 */
	private function parcel_dimensions( array $parcels ): array {
		return array_values(
			array_map(
				static fn( DpdTariffParcel $parcel ): array => array(
					'weight_g' => $parcel->weight_g,
					'length' => $parcel->length_cm,
					'width' => $parcel->width_cm,
					'height' => $parcel->height_cm,
					'quantity' => max( 1, $parcel->quantity ),
				),
				$parcels
			)
		);
	}

	/**
	 * @param array<int,array{weight_g:int,length:float,width:float,height:float,quantity:int}> $parcels
	 * @return array{length:float,width:float,height:float}
	 */
	private function aggregate_dimensions( array $parcels ): array {
		return array(
			'length' => array() !== $parcels ? max( array_map( static fn( array $parcel ): float => $parcel['length'], $parcels ) ) : 0.0,
			'width' => array() !== $parcels ? max( array_map( static fn( array $parcel ): float => $parcel['width'], $parcels ) ) : 0.0,
			'height' => array() !== $parcels ? max( array_map( static fn( array $parcel ): float => $parcel['height'], $parcels ) ) : 0.0,
		);
	}
}
