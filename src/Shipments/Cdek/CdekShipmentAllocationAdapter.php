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
		$this->assert_valid_source( $places, $item_rows );

		$rows_by_place = array();
		foreach ( $item_rows as $row_index => $row ) {
			$place_number = (int) $row['place_number'];
			$source_item_id = trim( (string) ( $row['item_key'] ?? '' ) );
			$price_kopecks = (int) round( (float) str_replace( ',', '.', (string) $row['cost'] ) * 100 );
			$rows_by_place[ $place_number ][] = new ShipmentAllocationItem(
				$source_item_id,
				array( 'order_item_id' => $source_item_id ),
				(string) ( $row['name'] ?? '' ),
				(string) ( $row['ware_key'] ?? '' ),
				(int) $row['amount'],
				$price_kopecks,
				$price_kopecks,
				(int) $row['weight']
			);
		}

		$allocation_places = array();
		foreach ( $places as $place ) {
			$allocation_places[] = new ShipmentAllocationPlace(
				$place->place_number,
				$place->weight_g,
				$place->length_cm,
				$place->width_cm,
				$place->height_cm,
				$rows_by_place[ $place->place_number ] ?? array()
			);
		}

		$allocation = new ShipmentAllocation( $allocation_places );
		$errors = $allocation->validate();
		if ( array() !== $errors ) {
			throw new \InvalidArgumentException( implode( "\n", $errors ) );
		}

		return $allocation;
	}

	/**
	 * @param array<int,ShipmentPlace> $places
	 * @param array<int,array<string,mixed>> $item_rows
	 */
	private function assert_valid_source( array $places, array $item_rows ): void {
		$errors = array();
		$known_places = array();
		foreach ( $places as $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				$errors[] = 'Shipment allocation source places must contain ShipmentPlace values.';
				continue;
			}
			foreach ( $place->validate() as $error ) {
				$errors[] = sprintf( 'Shipment place %d: %s.', $place->place_number, $error );
			}
			$known_places[ $place->place_number ] = true;
		}
		if ( array() === $known_places ) {
			$errors[] = 'Shipment allocation source must contain at least one place.';
		}

		foreach ( $item_rows as $row_index => $row ) {
			$label = 'CDEK allocation row ' . (string) ( $row_index + 1 );
			if ( ! is_array( $row ) ) {
				$errors[] = $label . ' must be an array.';
				continue;
			}
			$place_number = (int) ( $row['place_number'] ?? 0 );
			if ( ! isset( $known_places[ $place_number ] ) ) {
				$errors[] = $label . ' references an unknown shipment place.';
			}
			if ( '' === trim( (string) ( $row['item_key'] ?? '' ) ) ) {
				$errors[] = $label . ' must contain item_key.';
			}
			if ( (int) ( $row['amount'] ?? 0 ) <= 0 ) {
				$errors[] = $label . ' amount must be greater than 0.';
			}
			if ( (int) ( $row['weight'] ?? 0 ) <= 0 ) {
				$errors[] = $label . ' weight must be greater than 0.';
			}
			$cost = str_replace( ',', '.', (string) ( $row['cost'] ?? '' ) );
			if ( '' === $cost || ! is_numeric( $cost ) || (float) $cost < 0 ) {
				$errors[] = $label . ' cost must be greater than or equal to 0.';
			}
		}

		if ( array() !== $errors ) {
			throw new \InvalidArgumentException( implode( "\n", array_values( array_unique( $errors ) ) ) );
		}
	}
}
