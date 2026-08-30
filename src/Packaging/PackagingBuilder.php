<?php
declare(strict_types=1);

namespace WallsShop\WDC\Packaging;

use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class PackagingBuilder {
	private const BOX_FORMATS = array(
		'box_50_50_30' => array( 'length' => 50, 'width' => 50, 'height' => 30 ),
		'box_40_40_40' => array( 'length' => 40, 'width' => 40, 'height' => 40 ),
	);
	private const LONG_ITEM_THRESHOLD_CM = 49;
	private const SMALL_ITEM_MAX_VOLUME_CM3 = 50;
	private const STACKED_ROW_WIDTH_LIMIT_CM = 45;
	private const MAX_EXPANDED_ITEMS_FOR_3D_PACKER = 40;
	private const MAX_PACK_ATTEMPTS = 10000;
	private const MAX_TWO_BOX_PARTITION_ATTEMPTS = 500;
	/** @var array<int,array<string,mixed>> */
	private array $parcel_meta_by_id = array();

	public function __construct(
		private PackagingBuilderConfig $config,
		private ?PackagingWeightCalculator $packaging_weight_calculator = null
	) {
	}

	public function build( QuoteRequest $request ): PackagingResult {
		$this->parcel_meta_by_id = array();
		$package = $request->package;
		$declared_value = $this->declared_value_rub( $request );
		$items = $this->expanded_items( $package );
		$long_items = array();
		$regular_items = array();
		foreach ( $items as $item ) {
			if ( $this->has_item_dimensions( $item ) && max( $item['length'], $item['width'], $item['height'] ) > self::LONG_ITEM_THRESHOLD_CM ) {
				$long_items[] = $item;
				continue;
			}
			$regular_items[] = $item;
		}

		$parcels = array();
		$parcel_meta = array();
		foreach ( $long_items as $item ) {
			$this->assert_parcel_dimensions_allowed( (float) $item['length'], (float) $item['width'], (float) $item['height'] );
			$parcels[] = $this->parcel_from_box( (float) $item['length'], (float) $item['width'], (float) $item['height'], max( 1, $item['weight_g'] ), 'long_item', '' );
		}

		$regular_result = $this->regular_items_result( $regular_items, $package );
		foreach ( $regular_result['parcels'] as $parcel ) {
			$parcels[] = $parcel;
		}

		if ( array() === $parcels ) {
			$fallback = $this->fallback_parcel( $package, max( 1, $package->get_total_weight_g(), $this->config->default_weight_g ) );
			$parcels[] = $fallback['parcel'];
			$regular_result['source'] = $fallback['source'];
			$regular_result['packing_strategy'] = $fallback['source'];
		}

		foreach ( $parcels as $parcel ) {
			$parcel_meta[] = $this->parcel_meta( $parcel );
		}

		$source = $this->source( count( $long_items ), (string) $regular_result['source'] );
		$parcel_dimensions = $this->parcel_dimensions( $parcels );

		return new PackagingResult(
			$parcels,
			array(
			'declared_value_rub' => $declared_value,
			'package_builder_source' => $source,
			'packing_strategy' => (string) $regular_result['packing_strategy'],
			'box_formats_tried' => array_keys( self::BOX_FORMATS ),
			'selected_box_format' => (string) ( $regular_result['selected_box_format'] ?? '' ),
			'selected_box_formats' => is_array( $regular_result['selected_box_formats'] ?? null ) ? $regular_result['selected_box_formats'] : array(),
			'parcels_count' => count( $parcels ),
			'long_item_parcels_count' => count( $long_items ),
			'regular_items_count' => count( $regular_items ),
			'small_items_count' => (int) ( $regular_result['small_items_count'] ?? 0 ),
			'small_items_total_volume_cm3' => (int) ( $regular_result['small_items_total_volume_cm3'] ?? 0 ),
			'small_items_total_weight_g' => (int) ( $regular_result['small_items_total_weight_g'] ?? 0 ),
			'small_items_block_dimensions' => is_array( $regular_result['small_items_block_dimensions'] ?? null ) ? $regular_result['small_items_block_dimensions'] : array(),
			'identical_groups_count' => (int) ( $regular_result['identical_groups_count'] ?? 0 ),
			'identical_grid_blocks_count' => (int) ( $regular_result['identical_grid_blocks_count'] ?? 0 ),
			'identical_grid_blocks_dimensions' => is_array( $regular_result['identical_grid_blocks_dimensions'] ?? null ) ? $regular_result['identical_grid_blocks_dimensions'] : array(),
			'parcel_dimensions' => $parcel_dimensions,
			'goods_weight_g' => array_sum( array_map( static fn( array $meta ): int => $meta['goods_weight_g'], $parcel_meta ) ),
			'packaging_weight_g' => array_sum( array_map( static fn( array $meta ): int => $meta['packaging_weight_g'], $parcel_meta ) ),
			'final_weight_g' => array_sum( array_map( static fn( array $meta ): int => $meta['final_weight_g'], $parcel_meta ) ),
			'total_weight_g' => array_sum( array_map( static fn( PackagingParcel $parcel ): int => $parcel->weight_g * max( 1, $parcel->quantity ), $parcels ) ),
			'dimensions' => $this->aggregate_dimensions( $parcel_dimensions ),
			'box_limit' => array(
				'formats' => self::BOX_FORMATS,
				'max_parcel_dimensions_cm' => $this->config->has_parcel_limits() ? array( 'length' => $this->config->max_parcel_length_cm, 'width' => $this->config->max_parcel_width_cm, 'height' => $this->config->max_parcel_height_cm ) : null,
				'row_width_limit' => self::STACKED_ROW_WIDTH_LIMIT_CM,
				'long_item_threshold' => self::LONG_ITEM_THRESHOLD_CM,
				'small_item_max_volume_cm3' => self::SMALL_ITEM_MAX_VOLUME_CM3,
				'max_expanded_items_for_3d_packer' => self::MAX_EXPANDED_ITEMS_FOR_3D_PACKER,
			),
			'packing_limit_reason' => (string) ( $regular_result['packing_limit_reason'] ?? '' ),
			)
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

		return $this->config->default_declared_value_rub;
	}

	private function has_package_dimensions( Package $package ): bool {
		return null !== $package->length_cm && $package->length_cm > 0
			&& null !== $package->width_cm && $package->width_cm > 0
			&& null !== $package->height_cm && $package->height_cm > 0;
	}

	/**
	 * @param array<int,array<string,int|string>> $regular_items
	 * @return array<string,mixed>
	 */
	private function regular_items_result( array $regular_items, Package $package ): array {
		$base = array(
			'parcels' => array(),
			'source' => '',
			'packing_strategy' => '',
			'selected_box_format' => '',
			'selected_box_formats' => array(),
			'small_items_count' => 0,
			'small_items_total_volume_cm3' => 0,
			'small_items_total_weight_g' => 0,
			'small_items_block_dimensions' => array(),
			'identical_groups_count' => 0,
			'identical_grid_blocks_count' => 0,
			'identical_grid_blocks_dimensions' => array(),
			'packing_limit_reason' => '',
		);

		if ( array() === $regular_items ) {
			return $base;
		}
		$weight = $this->items_weight( $regular_items );
		if ( ! $this->items_have_dimensions( $regular_items ) ) {
			if ( $this->has_package_dimensions( $package ) ) {
				$this->assert_parcel_dimensions_allowed( (float) $package->length_cm, (float) $package->width_cm, (float) $package->height_cm );
				$base['parcels'] = array( $this->parcel_from_box( (float) $package->length_cm, (float) $package->width_cm, (float) $package->height_cm, $weight, 'package_dimensions', '' ) );
				$base['source'] = 'package_dimensions';
				$base['packing_strategy'] = 'package_dimensions';
				return $base;
			}

			$dimensions = $this->default_dimensions();
			$this->assert_parcel_dimensions_allowed( $dimensions['length'], $dimensions['width'], $dimensions['height'] );
			$base['parcels'] = array( $this->parcel_from_box( $dimensions['length'], $dimensions['width'], $dimensions['height'], $weight, 'defaults', '' ) );
			$base['source'] = 'defaults';
			$base['packing_strategy'] = 'defaults';
			return $base;
		}

		$prepared = $this->prepare_units( $regular_items );
		$base = array_merge( $base, $prepared['diagnostics'] );
		$units = $prepared['units'];
		if ( count( $prepared['packer_units_before_synthetic'] ) > self::MAX_EXPANDED_ITEMS_FOR_3D_PACKER ) {
			$base['packing_limit_reason'] = 'max_expanded_items_for_3d_packer';
			if ( $this->config->has_parcel_limits() ) {
				return $this->limited_multi_box_result( $regular_items, $base );
			}
			return $this->stacked_result( $regular_items, $base );
		}

		$one_box = $this->best_one_box( $units );
		if ( is_array( $one_box ) ) {
			$base['parcels'] = array( $this->parcel_from_box( (float) $one_box['length'], (float) $one_box['width'], (float) $one_box['height'], (int) $one_box['goods_weight_g'], 'one_box_3d', (string) $one_box['format'] ) );
			$base['source'] = 'one_box_3d';
			$base['packing_strategy'] = 'one_box_3d';
			$base['selected_box_format'] = (string) $one_box['format'];
			$base['selected_box_formats'] = array( (string) $one_box['format'] );
			return $base;
		}

		$two_boxes = $this->best_two_boxes( $units );
		if ( is_array( $two_boxes ) ) {
			$base['parcels'] = array_map(
				fn( array $box ): PackagingParcel => $this->parcel_from_box( (float) $box['length'], (float) $box['width'], (float) $box['height'], (int) $box['goods_weight_g'], 'two_boxes_3d', (string) $box['format'] ),
				$two_boxes['boxes']
			);
			$base['source'] = 1 === count( $base['parcels'] ) ? 'one_box_3d' : 'two_boxes_3d';
			$base['packing_strategy'] = (string) $base['source'];
			$base['selected_box_formats'] = array_values( array_map( static fn( array $box ): string => (string) $box['format'], $two_boxes['boxes'] ) );
			$base['selected_box_format'] = implode( '+', $base['selected_box_formats'] );
			return $base;
		}
		if ( $this->config->has_parcel_limits() ) {
			return $this->limited_multi_box_result( $regular_items, $base );
		}

		return $this->stacked_result( $regular_items, $base );
	}

	/**
	 * @param array<int,array<string,int|string>> $items
	 * @param array<string,mixed> $base
	 * @return array<string,mixed>
	 */
	private function stacked_result( array $items, array $base ): array {
		$stacked = $this->stacked_rows_dimensions( $items );
		$this->assert_parcel_dimensions_allowed( (float) $stacked['length'], (float) $stacked['width'], (float) $stacked['height'] );
		$base['parcels'] = array( $this->parcel_from_box( (float) $stacked['length'], (float) $stacked['width'], (float) $stacked['height'], $this->items_weight( $items ), 'items_stacked_rows', '' ) );
		$base['source'] = 'items_stacked_rows';
		$base['packing_strategy'] = 'items_stacked_rows';

		return $base;
	}

	/**
	 * @param array<int,array<string,int|string>> $items
	 * @return array{units:array<int,array<string,int|string>>,packer_units_before_synthetic:array<int,array<string,int|string>>,diagnostics:array<string,mixed>}
	 */
	private function prepare_units( array $items ): array {
		$small_count = 0;
		$small_volume = 0;
		$small_weight = 0;
		$non_small = array();
		foreach ( $items as $item ) {
			$volume = $this->volume( $item );
			if ( $volume <= self::SMALL_ITEM_MAX_VOLUME_CM3 ) {
				++$small_count;
				$small_volume += $volume;
				$small_weight += max( 1, (int) $item['weight_g'] );
				continue;
			}
			$non_small[] = $item;
		}

		$packer_units_before_synthetic = $non_small;
		$units = array();
		$identical_groups_count = 0;
		$identical_grid_blocks_count = 0;
		$identical_grid_blocks_dimensions = array();
		foreach ( $this->group_identical_items( $non_small ) as $group ) {
			if ( count( $group ) > 1 ) {
				++$identical_groups_count;
				$block = $this->identical_grid_block( $group );
				if ( is_array( $block ) ) {
					$units[] = $block;
					++$identical_grid_blocks_count;
					$identical_grid_blocks_dimensions[] = $this->unit_dimensions($block);
					continue;
				}
			}
			foreach ( $group as $item ) {
				$units[] = $this->unit_from_item( $item, 'item' );
			}
		}

		$small_dimensions = array();
		if ( $small_count > 0 ) {
			$small_dimensions = $this->small_items_block_dimensions( $small_volume );
			$units[] = array(
				'length' => $small_dimensions['length'],
				'width' => $small_dimensions['width'],
				'height' => $small_dimensions['height'],
				'weight_g' => max( 1, $small_weight ),
				'index' => 1000000,
				'source' => 'small_items_block',
				'original_count' => $small_count,
			);
		}

		return array(
			'units' => $this->sort_units( $units ),
			'packer_units_before_synthetic' => $packer_units_before_synthetic,
			'diagnostics' => array(
				'small_items_count' => $small_count,
				'small_items_total_volume_cm3' => $small_volume,
				'small_items_total_weight_g' => $small_weight,
				'small_items_block_dimensions' => $small_dimensions,
				'identical_groups_count' => $identical_groups_count,
				'identical_grid_blocks_count' => $identical_grid_blocks_count,
				'identical_grid_blocks_dimensions' => $identical_grid_blocks_dimensions,
			),
		);
	}

	/**
	 * @param array<int,array<string,int|string>> $units
	 * @return array<string,mixed>|null
	 */
	private function best_one_box( array $units): ?array {
		$best = null;
		foreach ( $this->allowed_box_formats() as $name => $box ) {
			$result = $this->pack_units_in_box( $units, $box, $name );
			if ( is_array( $result ) ) {
				$best = $this->better_packed_box( $best, $result );
			}
		}

		return $best;
	}

	/**
	 * @param array<int,array<string,int|string>> $units
	 * @return array<string,mixed>|null
	 */
	private function best_two_boxes( array $units ): ?array {
		$formats = array_keys( $this->allowed_box_formats() );
		$pairs = array();
		foreach ( $formats as $first ) {
			foreach ( $formats as $second ) {
				$pairs[] = array( $first, $second );
			}
		}
		$orders = array( 'volume', 'max_side', 'weight' );
		$attempts = 0;
		$best = null;
		foreach ( $pairs as $pair ) {
			foreach ( $orders as $order ) {
				if ( ++$attempts > self::MAX_TWO_BOX_PARTITION_ATTEMPTS ) {
					return $best;
				}
				$ordered = $this->sort_units( $units, $order );
				$candidate = $this->distribute_two_boxes( $ordered, $pair[0], $pair[1] );
				if ( is_array( $candidate ) ) {
					$best = $this->better_two_box_result( $best, $candidate );
				}
			}
		}

		return $best;
	}

	/**
	 * @param array<int,array<string,int|string>> $units
	 * @return array<string,mixed>|null
	 */
	private function distribute_two_boxes( array $units, string $format_a, string $format_b ): ?array {
		$boxes = array(
			array( 'format' => $format_a, 'units' => array() ),
			array( 'format' => $format_b, 'units' => array() ),
		);
		foreach ( $units as $unit ) {
			$best_index = null;
			$best_result = null;
			foreach ( array( 0, 1 ) as $index ) {
				$test_units = array_merge( $boxes[ $index ]['units'], array( $unit ) );
				$result = $this->pack_units_in_box( $test_units, self::BOX_FORMATS[ $boxes[ $index ]['format'] ], (string) $boxes[ $index ]['format'] );
				if ( ! is_array( $result ) ) {
					continue;
				}
				if ( null === $best_result || $this->packed_box_score( $result ) < $this->packed_box_score( $best_result ) ) {
					$best_result = $result;
					$best_index = $index;
				}
			}
			if ( null === $best_index ) {
				return null;
			}
			$boxes[ $best_index ]['units'][] = $unit;
		}

		$packed = array();
		foreach ( $boxes as $box ) {
			if ( array() === $box['units'] ) {
				continue;
			}
			$result = $this->pack_units_in_box( $box['units'], self::BOX_FORMATS[ $box['format'] ], (string) $box['format'] );
			if ( ! is_array( $result ) ) {
				return null;
			}
			$packed[] = $result;
		}

		return array( 'boxes' => $packed, 'pair' => $format_a . '+' . $format_b );
	}

	/**
	 * @param array<int,array<string,int|string>> $units
	 * @param array{length:int,width:int,height:int} $box
	 * @return array<string,mixed>|null
	 */
	private function pack_units_in_box( array $units, array $box, string $format ): ?array {
		$state = $this->empty_pack_state( $format );
		$attempts = 0;
		foreach ( $units as $unit ) {
			$best = null;
			foreach ( $this->orientations( $unit ) as $orientation ) {
				foreach ( array( 'current_row', 'new_row', 'new_layer' ) as $mode ) {
					if ( ++$attempts > self::MAX_PACK_ATTEMPTS ) {
						return null;
					}
					$candidate = $this->place_unit( $state, $orientation, $unit, $box, $mode );
					if ( is_array( $candidate ) ) {
						$best = $this->better_pack_state( $best, $candidate );
					}
				}
			}
			if ( null === $best ) {
				return null;
			}
			$state = $best;
		}
		$state['goods_weight_g'] = array_sum( array_map( static fn( array $unit ): int => max( 1, (int) $unit['weight_g'] ), $units ) );
		$state['unused_volume'] = max( 0, $box['length'] * $box['width'] * $box['height'] - $state['volume'] );

		return $state;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function empty_pack_state( string $format ): array {
		return array(
			'format' => $format,
			'length' => 0,
			'width' => 0,
			'height' => 0,
			'volume' => 0,
			'unused_volume' => 0,
			'row_length' => 0,
			'row_width' => 0,
			'row_height' => 0,
			'closed_rows_width' => 0,
			'layer_height' => 0,
			'placed' => array(),
			'goods_weight_g' => 0,
		);
	}

	/**
	 * @param array<string,mixed> $state
	 * @param array{length:int,width:int,height:int} $orientation
	 * @param array<string,int|string> $unit
	 * @param array{length:int,width:int,height:int} $box
	 * @return array<string,mixed>|null
	 */
	private function place_unit( array $state, array $orientation, array $unit, array $box, string $mode ): ?array {
		$next = $state;
		if ( 'new_layer' === $mode ) {
			$next['height'] = (int) $state['height'];
			$next['width'] = max( (int) $state['width'], (int) $state['closed_rows_width'] + (int) $state['row_width'] );
			$next['row_length'] = 0;
			$next['row_width'] = 0;
			$next['row_height'] = 0;
			$next['closed_rows_width'] = 0;
			$next['layer_height'] = 0;
		} elseif ( 'new_row' === $mode ) {
			$next['closed_rows_width'] = (int) $state['closed_rows_width'] + (int) $state['row_width'];
			$next['layer_height'] = (int) $state['layer_height'];
			$next['row_length'] = 0;
			$next['row_width'] = 0;
			$next['row_height'] = 0;
		}

		$row_length = (int) $next['row_length'] + $orientation['length'];
		$row_width = max( (int) $next['row_width'], $orientation['width'] );
		$row_height = max( (int) $next['row_height'], $orientation['height'] );
		$layer_width = (int) $next['closed_rows_width'] + $row_width;
		$layer_height = max( (int) $next['layer_height'], $row_height );
		$occupied_length = max( (int) $next['length'], $row_length );
		$occupied_width = max( (int) $next['width'], $layer_width );
		$occupied_height = 'new_layer' === $mode ? (int) $state['height'] + $layer_height : max( (int) $state['height'], $layer_height );
		if ( $occupied_length > $box['length'] || $occupied_width > $box['width'] || $occupied_height > $box['height'] ) {
			return null;
		}

		$next['row_length'] = $row_length;
		$next['row_width'] = $row_width;
		$next['row_height'] = $row_height;
		$next['closed_rows_width'] = (int) $next['closed_rows_width'];
		$next['layer_height'] = $layer_height;
		$next['length'] = $occupied_length;
		$next['width'] = $occupied_width;
		$next['height'] = $occupied_height;
		$next['volume'] = max( 1, $occupied_length * $occupied_width * $occupied_height );
		$next['placed'][] = array( 'source' => (string) ( $unit['source'] ?? 'item' ), 'dimensions' => $orientation );

		return $next;
	}

	private function parcel_from_box( float $length, float $width, float $height, int $goods_weight_g, string $source, string $format ): PackagingParcel {
		$packaging_weight = $this->packaging_weight_for_goods_weight( $goods_weight_g );
		$parcel = new PackagingParcel( max( 1, $goods_weight_g + $packaging_weight ), max( 0.1, $length ), max( 0.1, $width ), max( 0.1, $height ), 1 );
		$this->parcel_meta_by_id[ spl_object_id( $parcel ) ] = array(
			'goods_weight_g' => max( 1, $goods_weight_g ),
			'packaging_weight_g' => $packaging_weight,
			'final_weight_g' => max( 1, $goods_weight_g + $packaging_weight ),
			'source' => $source,
			'box_format' => $format,
		);

		return $parcel;
	}

	private function packaging_weight_for_goods_weight( int $goods_weight_g ): int {
		if ( null === $this->packaging_weight_calculator ) {
			return 0;
		}

		return max( 0, $this->packaging_weight_calculator->packaging_weight_for_cart_weight( $goods_weight_g ) );
	}

	/**
	 * @return array{parcel:PackagingParcel,source:string}
	 */
	private function fallback_parcel( Package $package, int $weight_g ): array {
		if ( $this->has_package_dimensions( $package ) ) {
			return array(
				'parcel' => $this->parcel_from_box( (float) $package->length_cm, (float) $package->width_cm, (float) $package->height_cm, $weight_g, 'package_dimensions', '' ),
				'source' => 'package_dimensions',
			);
		}
		$dimensions = $this->default_dimensions();

		return array( 'parcel' => $this->parcel_from_box( $dimensions['length'], $dimensions['width'], $dimensions['height'], $weight_g, 'defaults', '' ), 'source' => 'defaults' );
	}

	/**
	 * @return array{length:float,width:float,height:float}
	 */
	private function default_dimensions(): array {
		return array(
			'length' => max( 0.1, $this->config->default_length_cm ),
			'width' => max( 0.1, $this->config->default_width_cm ),
			'height' => max( 0.1, $this->config->default_height_cm ),
		);
	}

	/**
	 * @return array<int,array<string,int|string>>
	 */
	private function expanded_items( Package $package ): array {
		$items = array();
		$index = 0;
		foreach ( $package->get_items() as $item ) {
			if ( ! $item instanceof PackageItem || PackagingWeightCalculator::PACKAGING_SKU === strtoupper( trim( $item->sku ) ) ) {
				continue;
			}
			$unit = array(
				'weight_g' => max( 1, $item->weight_g ),
				'length' => max( 0, $item->length_cm ),
				'width' => max( 0, $item->width_cm ),
				'height' => max( 0, $item->height_cm ),
				'index' => $index,
				'source' => 'item',
			);
			for ( $quantity_index = 0; $quantity_index < max( 0, $item->quantity ); ++$quantity_index ) {
				$unit['index'] = $index++;
				$items[] = $unit;
			}
		}

		return $items;
	}

	/**
	 * @param array<int,array<string,int|string>> $items
	 */
	private function items_have_dimensions( array $items ): bool {
		foreach ( $items as $item ) {
			if ( ! $this->has_item_dimensions( $item ) ) {
				return false;
			}
		}

		return array() !== $items;
	}

	/**
	 * @param array<string,int|string> $item
	 */
	private function has_item_dimensions( array $item ): bool {
		return (int) $item['length'] > 0 && (int) $item['width'] > 0 && (int) $item['height'] > 0;
	}

	/**
	 * @param array<int,array<string,int|string>> $items
	 */
	private function items_weight( array $items ): int {
		return array_sum( array_map( static fn( array $item ): int => max( 1, (int) $item['weight_g'] ), $items ) );
	}

	/**
	 * @param array<int,array<string,int|string>> $items
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
	 * @param array<string,int|string> $item
	 * @return array{length:int,width:int,height:int}
	 */
	private function natural_orientation( array $item ): array {
		$values = array_values( $this->item_dimensions( $item ) );
		sort( $values );

		return array( 'length' => $values[2], 'width' => $values[1], 'height' => $values[0] );
	}

	/**
	 * @param array<string,int|string> $item
	 * @return array<string,int|string>
	 */
	private function unit_from_item( array $item, string $source ): array {
		return array(
			'length' => (int) $item['length'],
			'width' => (int) $item['width'],
			'height' => (int) $item['height'],
			'weight_g' => max( 1, (int) $item['weight_g'] ),
			'index' => (int) ( $item['index'] ?? 0 ),
			'source' => $source,
		);
	}

	/**
	 * @param array<int,array<string,int|string>> $items
	 * @return array<int,array<int,array<string,int|string>>>
	 */
	private function group_identical_items( array $items ): array {
		$groups = array();
		foreach ( $items as $item ) {
			$dimensions = $this->item_dimensions( $item );
			sort( $dimensions );
			$key = implode( 'x', $dimensions ) . ':' . (int) $item['weight_g'];
			$groups[ $key ][] = $item;
		}

		return array_values( $groups );
	}

	/**
	 * @param array<int,array<string,int|string>> $group
	 * @return array<string,int|string>|null
	 */
	private function identical_grid_block( array $group ): ?array {
		$item = $group[0];
		$quantity = count( $group );
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
						'weight_g' => $this->items_weight( $group ),
						'index' => (int) ( $item['index'] ?? 0 ),
						'source' => 'identical_grid',
						'original_count' => $quantity,
						'unused_slots' => $x * $y * $z - $quantity,
					);
					if ( ! $this->fits_any_box( $candidate ) ) {
						continue;
					}
					$best = $this->better_identical_grid( $best, $candidate );
				}
			}
		}

		return $best;
	}

	/**
	 * @param array<string,int|string>|null $best
	 * @param array<string,int|string> $candidate
	 * @return array<string,int|string>
	 */
	private function better_identical_grid( ?array $best, array $candidate ): array {
		if ( null === $best ) {
			return $candidate;
		}
		$score = array(
			$this->volume( $candidate ),
			max( (int) $candidate['length'], (int) $candidate['width'], (int) $candidate['height'] ),
			(int) $candidate['height'],
			(int) $candidate['unused_slots'],
			implode( 'x', array( $candidate['length'], $candidate['width'], $candidate['height'] ) ),
		);
		$best_score = array(
			$this->volume( $best ),
			max( (int) $best['length'], (int) $best['width'], (int) $best['height'] ),
			(int) $best['height'],
			(int) $best['unused_slots'],
			implode( 'x', array( $best['length'], $best['width'], $best['height'] ) ),
		);

		return $score < $best_score ? $candidate : $best;
	}

	/**
	 * @param array<string,int|string> $candidate
	 */
	private function fits_any_box( array $candidate ): bool {
		foreach ( $this->allowed_box_formats() as $box ) {
			if ( $this->config->parcel_dimensions_allowed( (float) $candidate['length'], (float) $candidate['width'], (float) $candidate['height'] ) && $candidate['length'] <= $box['length'] && $candidate['width'] <= $box['width'] && $candidate['height'] <= $box['height'] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string,array{length:int,width:int,height:int}>
	 */
	private function allowed_box_formats(): array {
		return array_filter(
			self::BOX_FORMATS,
			fn( array $box ): bool => $this->config->parcel_dimensions_allowed( (float) $box['length'], (float) $box['width'], (float) $box['height'] )
		);
	}

	/**
	 * @param array<int,array<string,int|string>> $items
	 * @param array<string,mixed> $base
	 * @return array<string,mixed>
	 */
	private function limited_multi_box_result( array $items, array $base ): array {
		$formats = $this->allowed_box_formats();
		if ( array() === $formats ) {
			throw new PackagingException( 'packaging_parcel_limits_unusable' );
		}
		$boxes = array();
		foreach ( $this->sort_units( array_map( fn( array $item ): array => $this->unit_from_item( $item, 'item' ), $items ) ) as $unit ) {
			$best_index = null;
			$best_result = null;
			foreach ( $boxes as $index => $box ) {
				$result = $this->pack_units_in_box( array_merge( $box['units'], array( $unit ) ), $formats[ $box['format'] ], (string) $box['format'] );
				if ( is_array( $result ) && ( null === $best_result || $this->packed_box_score( $result ) < $this->packed_box_score( $best_result ) ) ) {
					$best_index = $index;
					$best_result = $result;
				}
			}
			if ( null !== $best_index ) {
				$boxes[ $best_index ]['units'][] = $unit;
				continue;
			}
			$best_format = '';
			foreach ( $formats as $format => $dimensions ) {
				$result = $this->pack_units_in_box( array( $unit ), $dimensions, $format );
				if ( is_array( $result ) && ( null === $best_result || $this->packed_box_score( $result ) < $this->packed_box_score( $best_result ) ) ) {
					$best_format = $format;
					$best_result = $result;
				}
			}
			if ( '' === $best_format ) {
				throw new PackagingException( 'packaging_item_oversize' );
			}
			$boxes[] = array( 'format' => $best_format, 'units' => array( $unit ) );
		}
		$packed = array();
		foreach ( $boxes as $box ) {
			$result = $this->pack_units_in_box( $box['units'], $formats[ $box['format'] ], (string) $box['format'] );
			if ( ! is_array( $result ) ) {
				throw new PackagingException( 'packaging_item_oversize' );
			}
			$packed[] = $result;
		}
		$base['parcels'] = array_map( fn( array $box ): PackagingParcel => $this->parcel_from_box( (float) $box['length'], (float) $box['width'], (float) $box['height'], (int) $box['goods_weight_g'], 'multi_boxes_3d', (string) $box['format'] ), $packed );
		$base['source'] = 'multi_boxes_3d';
		$base['packing_strategy'] = 'multi_boxes_3d';
		$base['selected_box_formats'] = array_values( array_map( static fn( array $box ): string => (string) $box['format'], $packed ) );
		$base['selected_box_format'] = implode( '+', $base['selected_box_formats'] );

		return $base;
	}

	private function assert_parcel_dimensions_allowed( float $length_cm, float $width_cm, float $height_cm ): void {
		if ( ! $this->config->parcel_dimensions_allowed( $length_cm, $width_cm, $height_cm ) ) {
			throw new PackagingException( 'packaging_item_oversize' );
		}
	}

	/**
	 * @return array{length:int,width:int,height:int}
	 */
	private function small_items_block_dimensions( int $volume ): array {
		$volume = max( 1, $volume );
		$side = pow( $volume / 3, 1 / 3 );
		$dimensions = array(
			'length' => max( 1, (int) ceil( 3 * $side ) ),
			'width' => max( 1, (int) ceil( $side ) ),
			'height' => max( 1, (int) ceil( $side ) ),
		);
		while ( $dimensions['length'] * $dimensions['width'] * $dimensions['height'] < $volume ) {
			++$dimensions['length'];
		}

		return $dimensions;
	}

	/**
	 * @param array<int,array<string,int|string>> $units
	 * @return array<int,array<string,int|string>>
	 */
	private function sort_units( array $units, string $mode = 'volume' ): array {
		usort(
			$units,
			function ( array $a, array $b ) use ( $mode ): int {
				$primary = match ( $mode ) {
					'max_side' => max( (int) $b['length'], (int) $b['width'], (int) $b['height'] ) <=> max( (int) $a['length'], (int) $a['width'], (int) $a['height'] ),
					'weight' => (int) $b['weight_g'] <=> (int) $a['weight_g'],
					default => $this->volume( $b ) <=> $this->volume( $a ),
				};
				if ( 0 !== $primary ) {
					return $primary;
				}
				$secondary = max( (int) $b['length'], (int) $b['width'], (int) $b['height'] ) <=> max( (int) $a['length'], (int) $a['width'], (int) $a['height'] );
				if ( 0 !== $secondary ) {
					return $secondary;
				}
				$weight = (int) $b['weight_g'] <=> (int) $a['weight_g'];
				if ( 0 !== $weight ) {
					return $weight;
				}

				return (int) $a['index'] <=> (int) $b['index'];
			}
		);

		return $units;
	}

	/**
	 * @param array<string,mixed>|null $best
	 * @param array<string,mixed> $candidate
	 * @return array<string,mixed>
	 */
	private function better_pack_state( ?array $best, array $candidate ): array {
		if ( null === $best ) {
			return $candidate;
		}

		return $this->packed_box_score( $candidate ) < $this->packed_box_score( $best ) ? $candidate : $best;
	}

	/**
	 * @param array<string,mixed>|null $best
	 * @param array<string,mixed> $candidate
	 * @return array<string,mixed>
	 */
	private function better_packed_box( ?array $best, array $candidate ): array {
		if ( null === $best ) {
			return $candidate;
		}
		$score = array(
			(int) $candidate['volume'],
			max( (int) $candidate['length'], (int) $candidate['width'], (int) $candidate['height'] ),
			(int) $candidate['height'],
			(int) $candidate['width'],
			(string) $candidate['format'],
		);
		$best_score = array(
			(int) $best['volume'],
			max( (int) $best['length'], (int) $best['width'], (int) $best['height'] ),
			(int) $best['height'],
			(int) $best['width'],
			(string) $best['format'],
		);

		return $score < $best_score ? $candidate : $best;
	}

	/**
	 * @param array<string,mixed>|null $best
	 * @param array<string,mixed> $candidate
	 * @return array<string,mixed>
	 */
	private function better_two_box_result( ?array $best, array $candidate ): array {
		if ( null === $best ) {
			return $candidate;
		}
		$score = $this->two_box_score( $candidate );
		$best_score = $this->two_box_score( $best );

		return $score < $best_score ? $candidate : $best;
	}

	/**
	 * @param array<string,mixed> $box
	 * @return array<int,int|string>
	 */
	private function packed_box_score( array $box ): array {
		return array(
			(int) $box['volume'],
			(int) $box['height'],
			(int) $box['width'],
			(int) $box['length'],
			(int) $box['unused_volume'],
			(string) $box['format'],
		);
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<int,int|string>
	 */
	private function two_box_score( array $result ): array {
		$boxes = $result['boxes'];
		$total_volume = array_sum( array_map( static fn( array $box ): int => (int) $box['volume'], $boxes ) );
		$max_side = max( array_map( static fn( array $box ): int => max( (int) $box['length'], (int) $box['width'], (int) $box['height'] ), $boxes ) );
		$total_packaging = array_sum( array_map( fn( array $box ): int => $this->packaging_weight_for_goods_weight( (int) $box['goods_weight_g'] ), $boxes ) );

		return array( count( $boxes ), $total_volume, $max_side, $total_packaging, (string) $result['pair'] );
	}

	/**
	 * @param array<string,int|string> $item
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
	 * @param array<string,int|string> $dimensions
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
	 * @param array<string,int|string> $item
	 */
	private function volume( array $item ): int {
		return max( 1, (int) $item['length'] ) * max( 1, (int) $item['width'] ) * max( 1, (int) $item['height'] );
	}

	/**
	 * @param array<string,int|string> $unit
	 * @return array{weight_g:int,length:float,width:float,height:float,quantity:int,goods_weight_g:int,packaging_weight_g:int,final_weight_g:int,source:string,box_format:string}
	 */
	private function unit_dimensions( array $unit ): array {
		$goods_weight = max( 1, (int) $unit['weight_g'] );
		$packaging_weight = $this->packaging_weight_for_goods_weight( $goods_weight );

		return array(
			'weight_g' => $goods_weight + $packaging_weight,
			'length' => (float) $unit['length'],
			'width' => (float) $unit['width'],
			'height' => (float) $unit['height'],
			'quantity' => 1,
			'goods_weight_g' => $goods_weight,
			'packaging_weight_g' => $packaging_weight,
			'final_weight_g' => $goods_weight + $packaging_weight,
			'source' => (string) ( $unit['source'] ?? '' ),
			'box_format' => '',
		);
	}

	/**
	 * @return array{weight_g:int,length:float,width:float,height:float,quantity:int,goods_weight_g:int,packaging_weight_g:int,final_weight_g:int,source:string,box_format:string}
	 */
	private function parcel_meta( PackagingParcel $parcel): array {
		$meta = $this->parcel_meta_by_id[ spl_object_id( $parcel ) ] ?? array();

		return array(
			'weight_g' => $parcel->weight_g,
			'length' => $parcel->length_cm,
			'width' => $parcel->width_cm,
			'height' => $parcel->height_cm,
			'quantity' => max( 1, $parcel->quantity ),
			'goods_weight_g' => (int) ( $meta['goods_weight_g'] ?? $parcel->weight_g ),
			'packaging_weight_g' => (int) ( $meta['packaging_weight_g'] ?? 0 ),
			'final_weight_g' => (int) ( $meta['final_weight_g'] ?? $parcel->weight_g ),
			'source' => (string) ( $meta['source'] ?? '' ),
			'box_format' => (string) ( $meta['box_format'] ?? '' ),
		);
	}

	private function source( int $long_count, string $regular_source ): string {
		if ( $long_count > 0 && '' !== $regular_source ) {
			return match ( $regular_source ) {
				'one_box_3d' => 'mixed_long_items_one_box_3d',
				'two_boxes_3d' => 'mixed_long_items_two_boxes_3d',
				'multi_boxes_3d' => 'mixed_long_items_multi_boxes_3d',
				'items_stacked_rows' => 'mixed_long_items_stacked_rows',
				default => 'mixed_long_items_' . $regular_source,
			};
		}
		if ( $long_count > 0 ) {
			return 'long_items_only';
		}

		return '' !== $regular_source ? $regular_source : 'defaults';
	}

	/**
	 * @param array<int,PackagingParcel> $parcels
	 * @return array<int,array{weight_g:int,length:float,width:float,height:float,quantity:int,goods_weight_g:int,packaging_weight_g:int,final_weight_g:int,source:string,box_format:string}>
	 */
	private function parcel_dimensions( array $parcels ): array {
		return array_values( array_map( fn( PackagingParcel $parcel ): array => $this->parcel_meta( $parcel ), $parcels ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $parcels
	 * @return array{length:float,width:float,height:float}
	 */
	private function aggregate_dimensions( array $parcels ): array {
		return array(
			'length' => array() !== $parcels ? max( array_map( static fn( array $parcel ): float => (float) $parcel['length'], $parcels ) ) : 0.0,
			'width' => array() !== $parcels ? max( array_map( static fn( array $parcel ): float => (float) $parcel['width'], $parcels ) ) : 0.0,
			'height' => array() !== $parcels ? max( array_map( static fn( array $parcel ): float => (float) $parcel['height'], $parcels ) ) : 0.0,
		);
	}
}
