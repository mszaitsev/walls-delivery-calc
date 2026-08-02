<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Pickup;

defined( 'ABSPATH' ) || exit;

final class PekDestinationTerminalRequest {
	public function __construct(
		public readonly string $address,
		public readonly ?float $latitude,
		public readonly ?float $longitude,
		public readonly float $weight_kg,
		public readonly float $volume_m3,
		public readonly float $max_dimension_m,
		public readonly float $max_weight_per_place_kg,
		public readonly int $places_count,
		public readonly int $radius_km,
		public readonly int $limit
	) {
	}

	/** @return array<string,mixed> */
	public function to_payload(): array {
		$payload = array(
			'weight' => $this->weight_kg > 0 ? $this->weight_kg : null,
			'volume' => $this->volume_m3 > 0 ? $this->volume_m3 : null,
			'maxDimension' => $this->max_dimension_m > 0 ? $this->max_dimension_m : null,
			'maxWeightPerPlace' => $this->max_weight_per_place_kg > 0 ? $this->max_weight_per_place_kg : null,
			'departmentOperation' => 3,
			'type' => 3,
			'searchRadius' => $this->radius_km,
			'limit' => $this->limit,
		);
		if ( null !== $this->latitude && null !== $this->longitude ) {
			$payload['coordinates'] = array( 'latitude' => $this->latitude, 'longitude' => $this->longitude );
		} else {
			$payload['address'] = $this->address;
		}

		return $payload;
	}
}
