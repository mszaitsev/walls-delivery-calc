<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Allocation;

final class ShipmentAllocation {
	/**
	 * @param array<int,ShipmentAllocationPlace> $places
	 */
	public function __construct(
		public readonly array $places
	) {
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();
		if ( array() === $this->places ) {
			$errors[] = 'places must not be empty';
		}
		$known_places = array();
		foreach ( $this->places as $place ) {
			if ( ! $place instanceof ShipmentAllocationPlace ) {
				$errors[] = 'places must contain ShipmentAllocationPlace values';
				continue;
			}
			if ( isset( $known_places[ $place->place_number ] ) ) {
				$errors[] = 'place_number must be unique';
			}
			$known_places[ $place->place_number ] = true;
			$errors = array_merge( $errors, $place->validate() );
		}

		return array_values( array_unique( $errors ) );
	}
}
