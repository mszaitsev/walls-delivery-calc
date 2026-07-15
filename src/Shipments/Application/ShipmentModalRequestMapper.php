<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationBuilder;

defined( 'ABSPATH' ) || exit;

final class ShipmentModalRequestMapper {
	/**
	 * @param array<string,mixed> $data
	 */
	public function parse( array $data ): ShipmentPreparationData {
		$places = $this->places( $data );
		$item_rows = $this->item_rows( $data );
		( new ShipmentAllocationBuilder() )->build( $item_rows, $places );

		return new ShipmentPreparationData( $places, $item_rows );
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<int,ShipmentPlace>
	 */
	public function places( array $data ): array {
		$rows = is_array( $data['places'] ?? null ) ? $data['places'] : array();
		if ( array() === $rows ) {
			throw new \InvalidArgumentException( 'shipment places must not be empty' );
		}
		$places = array();
		foreach ( array_values( $rows ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				throw new \InvalidArgumentException( 'shipment place row must be an array' );
			}
			$places[] = new ShipmentPlace(
				(int) ( $row['place_number'] ?? $row['number'] ?? ( $index + 1 ) ),
				$this->integer_value( $row['weight_g'] ?? null ),
				$this->dimension_cm( $row['length_cm'] ?? null ),
				$this->dimension_cm( $row['width_cm'] ?? null ),
				$this->dimension_cm( $row['height_cm'] ?? null ),
				Money::from_kopecks( 0 ),
				array()
			);
		}

		return $places;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<int,array<string,mixed>>
	 */
	public function item_rows( array $data ): array {
		$rows = is_array( $data['shipment_items'] ?? null ) ? $data['shipment_items'] : array();
		if ( array() === $rows ) {
			throw new \InvalidArgumentException( 'shipment items must not be empty' );
		}
		$item_rows = array();
		foreach ( array_values( $rows ) as $row ) {
			if ( ! is_array( $row ) ) {
				throw new \InvalidArgumentException( 'shipment item row must be an array' );
			}
			$item_rows[] = array(
				'item_key' => $this->text( $row['item_key'] ?? $row['order_item_id'] ?? '' ),
				'ordered_quantity' => (int) ( $row['ordered_quantity'] ?? $row['quantity'] ?? $row['amount'] ?? 0 ),
				'place_number' => (int) ( $row['place_number'] ?? 0 ),
				'name' => $this->text( $row['name'] ?? '' ),
				'sku' => $this->text( $row['sku'] ?? $row['ware_key'] ?? '' ),
				'amount' => (int) ( $row['amount'] ?? $row['quantity'] ?? 0 ),
				'unit_price_kopecks' => $this->rubles_to_kopecks( $row['cost'] ?? '' ),
				'assessed_unit_price_kopecks' => $this->assessed_kopecks( $row['assessed_cost'] ?? null, $row['cost'] ?? '' ),
				'weight' => (int) ( $row['weight'] ?? $row['weight_g'] ?? 0 ),
				'length_cm' => $this->decimal_string( $row['length_cm'] ?? '' ),
				'width_cm' => $this->decimal_string( $row['width_cm'] ?? '' ),
				'height_cm' => $this->decimal_string( $row['height_cm'] ?? '' ),
			);
		}

		return $item_rows;
	}

	private function integer_value( mixed $value ): int {
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
		$value = trim( (string) $value );

		return 1 === preg_match( '/^-?\d+$/', $value ) ? (int) $value : 0;
	}

	private function dimension_cm( mixed $value ): int {
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
		$value = trim( str_replace( ',', '.', (string) $value ) );
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return (int) ceil( (float) $value );
	}

	private function text( mixed $value ): string {
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( (string) $value ) );

		return (string) $value;
	}

	private function decimal_string( mixed $value ): string {
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;

		return trim( str_replace( ',', '.', (string) $value ) );
	}

	private function assessed_kopecks( mixed $assessed, mixed $unit ): int {
		$value = $this->decimal_string( $assessed );
		if ( '' === $value ) {
			return $this->rubles_to_kopecks( $unit );
		}

		return $this->rubles_to_kopecks( $value );
	}

	private function rubles_to_kopecks( mixed $value ): int {
		$value = $this->decimal_string( $value );
		if ( '' === $value || ! is_numeric( $value ) ) {
			return -1;
		}

		return (int) round( (float) $value * 100 );
	}
}
