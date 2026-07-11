<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocation;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationItem;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationPlace;

/**
 * Converts the existing CDEK shipment-editor rows to the carrier-neutral allocation view.
 * It deliberately does not alter CDEK request construction.
 */
final class CdekShipmentAllocationAdapter {
	/**
	 * @param array<int,ShipmentPlace> $places
	 * @param array<int,array<string,mixed>> $item_rows Existing meta['cdek_item_rows'] rows.
	 */
	public function from_cdek_rows( array $places, array $item_rows ): ShipmentAllocation {
		$rows_by_place = array();
		foreach ( $item_rows as $row_index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$place_number = max( 1, (int) ( $row['place_number'] ?? 1 ) );
			$source_item_id = trim( (string) ( $row['item_key'] ?? '' ) );
			if ( '' === $source_item_id ) {
				$source_item_id = 'cdek-row-' . (string) ( $row_index + 1 );
			}
			$rows_by_place[ $place_number ][] = new ShipmentAllocationItem(
				$source_item_id,
				array( 'order_item_id' => $source_item_id ),
				(string) ( $row['name'] ?? 'Товар' ),
				(string) ( $row['ware_key'] ?? '' ),
				max( 1, (int) ( $row['amount'] ?? 1 ) ),
				(int) round( (float) str_replace( ',', '.', (string) ( $row['cost'] ?? 0 ) ) * 100 ),
				max( 0, (int) ( $row['weight'] ?? 0 ) )
			);
		}

		$allocation_places = array();
		foreach ( $places as $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				continue;
			}
			$allocation_places[] = new ShipmentAllocationPlace(
				$place->place_number,
				$place->weight_g,
				$place->length_cm,
				$place->width_cm,
				$place->height_cm,
				$rows_by_place[ $place->place_number ] ?? array()
			);
		}

		return new ShipmentAllocation( $allocation_places );
	}
}
