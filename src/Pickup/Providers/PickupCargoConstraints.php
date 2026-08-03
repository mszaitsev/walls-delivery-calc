<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Providers;

defined( 'ABSPATH' ) || exit;

final class PickupCargoConstraints {
	public function __construct(
		public readonly int $weight_g = 0,
		public readonly int $volume_cm3 = 0,
		public readonly int $max_dimension_cm = 0,
		public readonly int $max_place_weight_g = 0,
		public readonly int $places_count = 1
	) {
	}

	/** @return array<int,string> */
	public function validate(): array {
		$errors = array();
		foreach ( array(
			'weight_g' => $this->weight_g,
			'volume_cm3' => $this->volume_cm3,
			'max_dimension_cm' => $this->max_dimension_cm,
			'max_place_weight_g' => $this->max_place_weight_g,
		) as $field => $value ) {
			if ( $value < 0 ) {
				$errors[] = $field . ' must be greater than or equal to 0';
			}
		}
		if ( $this->places_count < 1 ) {
			$errors[] = 'places_count must be greater than or equal to 1';
		}

		return $errors;
	}

	/** @return array<string,int> */
	public function to_array(): array {
		return array(
			'weight_g' => $this->weight_g,
			'volume_cm3' => $this->volume_cm3,
			'max_dimension_cm' => $this->max_dimension_cm,
			'max_place_weight_g' => $this->max_place_weight_g,
			'places_count' => $this->places_count,
		);
	}
}
