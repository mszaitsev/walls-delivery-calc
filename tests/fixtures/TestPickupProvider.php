<?php
declare(strict_types=1);

use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Pickup\PickupPoint;

final class TestPickupProvider {
	public function __construct(
		private string $dataset_path = ''
	) {
	}

	/**
	 * @return array<int,PickupPoint>
	 */
	public function get_points( string $carrier_key, Address $destination ): array {
		if ( 'demo' !== trim( $carrier_key ) || 'RU' !== strtoupper( trim( $destination->country_code ) ) ) {
			return array();
		}

		$city = trim( $destination->settlement ?: $destination->city );

		return array_values(
			array_filter(
				$this->load_points(),
				fn ( PickupPoint $point ): bool => '' === $city || $this->city_matches( $point->city, $city )
			)
		);
	}

	/**
	 * @return array<int,PickupPoint>
	 */
	public function load_points(): array {
		$path = '' !== $this->dataset_path ? $this->dataset_path : dirname( __DIR__ ) . '/fixtures/demo/pickup-points-demo.json';
		if ( ! is_readable( $path ) ) {
			return array();
		}

		$data = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $data ) ) {
			return array();
		}

		$points = array();
		foreach ( $data as $item ) {
			if ( is_array( $item ) ) {
				$item['raw_reference'] = array_merge( $item, array( 'country_code' => (string) ( $item['country_code'] ?? 'RU' ) ) );
				$points[] = PickupPoint::from_array( $item );
			}
		}

		return $points;
	}

	private function city_matches( string $point_city, string $query ): bool {
		$point_city = $this->normalize_city( $point_city );
		$query      = $this->normalize_city( $query );

		return $point_city === $query
			|| str_contains( $point_city, $query )
			|| str_contains( $query, $point_city );
	}

	private function normalize_city( string $value ): string {
		$value = trim( $value );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
