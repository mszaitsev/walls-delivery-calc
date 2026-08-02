<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Pickup;

use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

defined( 'ABSPATH' ) || exit;

final class PekCargoConstraintsConverter {
	/** @return array{weight_kg:float,volume_m3:float,max_dimension_m:float,max_weight_per_place_kg:float,places_count:int} */
	public function convert( PickupCargoConstraints $constraints ): array {
		return array(
			'weight_kg' => $this->ceil_decimal( $constraints->weight_g / 1000, 3 ),
			'volume_m3' => $this->ceil_decimal( $constraints->volume_cm3 / 1000000, 6 ),
			'max_dimension_m' => $this->ceil_decimal( $constraints->max_dimension_cm / 100, 3 ),
			'max_weight_per_place_kg' => $this->ceil_decimal( $constraints->max_place_weight_g / 1000, 3 ),
			'places_count' => max( 1, $constraints->places_count ),
		);
	}

	private function ceil_decimal( float $value, int $precision ): float {
		if ( $value <= 0 ) {
			return 0.0;
		}
		$factor = 10 ** $precision;

		return ceil( $value * $factor ) / $factor;
	}
}
