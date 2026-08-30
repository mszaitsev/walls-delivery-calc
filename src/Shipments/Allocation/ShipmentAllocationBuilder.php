<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Allocation;

use WallsShop\WDC\Domain\Package\ShipmentPlace;

defined( 'ABSPATH' ) || exit;

final class ShipmentAllocationBuilder {
	/**
	 * @param array<int,array<string,mixed>> $item_rows
	 * @param array<int,ShipmentPlace>      $places
	 */
	public function build( array $item_rows, array $places ): ShipmentAllocation {
		$this->assert_valid_source( $item_rows, $places );

		$rows_by_place = array();
		foreach ( $item_rows as $row ) {
			$place_number = (int) $row['place_number'];
			$source_item_id = trim( (string) ( $row['item_key'] ?? '' ) );
			$order_item_id = (int) ( $row['order_item_id'] ?? 0 );
			$unit_price_kopecks = $this->kopecks( $row['unit_price_kopecks'] ?? null );
			$assessed_unit_price_kopecks = $this->kopecks( $row['assessed_unit_price_kopecks'] ?? null );
			$rows_by_place[ $place_number ][] = new ShipmentAllocationItem(
				$source_item_id,
				array( 'order_item_id' => $order_item_id > 0 ? (string) $order_item_id : $source_item_id ),
				(string) ( $row['name'] ?? '' ),
				(string) ( $row['sku'] ?? '' ),
				(int) $row['amount'],
				$unit_price_kopecks,
				$assessed_unit_price_kopecks,
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
	 * @param array<int,array<string,mixed>> $item_rows
	 * @param array<int,ShipmentPlace>      $places
	 */
	private function assert_valid_source( array $item_rows, array $places ): void {
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
		if ( array() === $item_rows ) {
			$errors[] = 'Shipment allocation rows must not be empty.';
		}

		$row_counts_by_place = array();
		foreach ( $item_rows as $row_index => $row ) {
			$label = 'Shipment allocation row ' . (string) ( $row_index + 1 );
			if ( ! is_array( $row ) ) {
				$errors[] = $label . ' must be an array.';
				continue;
			}
			$place_number = (int) ( $row['place_number'] ?? 0 );
			if ( ! isset( $known_places[ $place_number ] ) ) {
				$errors[] = $label . ' references an unknown shipment place.';
			} else {
				$row_counts_by_place[ $place_number ] = ( $row_counts_by_place[ $place_number ] ?? 0 ) + 1;
			}
			if ( '' === trim( (string) ( $row['item_key'] ?? '' ) ) ) {
				$errors[] = $label . ' must contain item_key.';
			}
			if ( (int) ( $row['amount'] ?? 0 ) <= 0 ) {
				$errors[] = $label . ' amount must be greater than 0.';
			}
			if ( (int) ( $row['ordered_quantity'] ?? 0 ) <= 0 ) {
				$errors[] = $label . ' ordered_quantity must be greater than 0.';
			}
			if ( (int) ( $row['weight'] ?? 0 ) <= 0 ) {
				$errors[] = $label . ' weight must be greater than 0.';
			}
			foreach ( array( 'unit_price_kopecks', 'assessed_unit_price_kopecks' ) as $key ) {
				if ( ! $this->valid_kopecks( $row[ $key ] ?? null ) ) {
					$errors[] = $label . ' ' . $key . ' must be a non-negative integer.';
				}
			}
		}
		foreach ( array_keys( $known_places ) as $place_number ) {
			if ( empty( $row_counts_by_place[ $place_number ] ) ) {
				$errors[] = sprintf( 'Shipment place %d must contain at least one allocation row.', $place_number );
			}
		}

		if ( array() !== $errors ) {
			throw new \InvalidArgumentException( implode( "\n", array_values( array_unique( $errors ) ) ) );
		}
	}

	private function kopecks( mixed $value ): int {
		return (int) $value;
	}

	private function valid_kopecks( mixed $value ): bool {
		if ( is_int( $value ) ) {
			return $value >= 0;
		}

		return is_string( $value ) && 1 === preg_match( '/^\d+$/', $value );
	}
}
