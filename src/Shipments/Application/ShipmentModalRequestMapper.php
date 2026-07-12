<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentAllocationAdapter;

defined( 'ABSPATH' ) || exit;

final class ShipmentModalRequestMapper {
	/**
	 * @param array<string,mixed> $data
	 */
	public function parse( array $data ): ShipmentPreparationData {
		$places = $this->places( $data );
		$item_rows = $this->item_rows( $data );
		( new CdekShipmentAllocationAdapter() )->from_cdek_rows( $places, $item_rows );

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
				$this->required_int( $row, 'weight_g' ),
				$this->required_int( $row, 'length_cm' ),
				$this->required_int( $row, 'width_cm' ),
				$this->required_int( $row, 'height_cm' ),
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
		$rows = is_array( $data['shipment_items'] ?? null )
			? $data['shipment_items']
			: ( is_array( $data['cdek_items'] ?? null ) ? $data['cdek_items'] : array() );
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
				'ware_key' => $this->text( $row['ware_key'] ?? $row['sku'] ?? '' ),
				'amount' => (int) ( $row['amount'] ?? $row['quantity'] ?? 0 ),
				'cost' => $this->decimal_string( $row['cost'] ?? $row['unit_price'] ?? '' ),
				'assessed_cost' => $this->decimal_string( $row['assessed_cost'] ?? $row['assessed_unit_price'] ?? '' ),
				'weight' => (int) ( $row['weight'] ?? $row['weight_g'] ?? 0 ),
				'length_cm' => $this->decimal_string( $row['length_cm'] ?? '' ),
				'width_cm' => $this->decimal_string( $row['width_cm'] ?? '' ),
				'height_cm' => $this->decimal_string( $row['height_cm'] ?? '' ),
			);
		}

		return $item_rows;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function required_int( array $row, string $key ): int {
		$value = $row[ $key ] ?? null;

		return is_numeric( $value ) ? (int) $value : 0;
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
}
