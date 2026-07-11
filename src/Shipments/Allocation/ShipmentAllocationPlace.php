<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Allocation;

final class ShipmentAllocationPlace {
	/**
	 * @param array<int,ShipmentAllocationItem> $items
	 */
	public function __construct(
		public readonly int $place_number,
		public readonly int $weight_g,
		public readonly int $length_cm,
		public readonly int $width_cm,
		public readonly int $height_cm,
		public readonly array $items
	) {
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();
		if ( $this->place_number <= 0 ) {
			$errors[] = 'place_number must be greater than 0';
		}
		if ( array() === $this->items ) {
			$errors[] = 'shipment place must contain at least one item';
		}
		foreach ( array( 'weight_g' => $this->weight_g, 'length_cm' => $this->length_cm, 'width_cm' => $this->width_cm, 'height_cm' => $this->height_cm ) as $field => $value ) {
			if ( $value <= 0 ) {
				$errors[] = $field . ' must be greater than 0';
			}
		}
		foreach ( $this->items as $item ) {
			if ( ! $item instanceof ShipmentAllocationItem ) {
				$errors[] = 'items must contain ShipmentAllocationItem values';
				continue;
			}
			$errors = array_merge( $errors, $item->validate() );
		}

		return $errors;
	}
}
