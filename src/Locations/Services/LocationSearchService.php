<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Services;

use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class LocationSearchService {
	public function __construct( private LocationRepository $repository ) {
	}

	/**
	 * @return array<int, Location>
	 */
	public function search( string $query, int $limit = 20 ): array {
		$normalized = $this->normalize( $query );
		if ( '' === $normalized ) {
			return array();
		}

		$locations = $this->repository->search( $normalized, max( $limit * 3, 50 ) );
		usort(
			$locations,
			fn( Location $a, Location $b ): int => $this->rank( $b, $normalized ) <=> $this->rank( $a, $normalized )
				?: strcmp( $a->display_name, $b->display_name )
		);

		return array_slice( $locations, 0, max( 1, $limit ) );
	}

	/**
	 * @return array<string, array<int, Location>>
	 */
	public function grouped( string $query ): array {
		$locations = $this->search( $query, 50 );
		$grouped   = array();

		foreach ( $locations as $location ) {
			$region = '' !== $location->region_name ? $location->region_name : __( 'Регион не указан', 'walls-delivery-calc' );
			$grouped[ $region ][] = $location;
		}

		ksort( $grouped );

		return $grouped;
	}

	public function normalize( string $value ): string {
		return Location::normalize_search_text( $value );
	}

	private function rank( Location $location, string $query ): int {
		$settlement = $this->normalize( '' !== $location->settlement_name ? $location->settlement_name : $location->city_name );
		$city       = $this->normalize( $location->city_name );
		$region     = $this->normalize( $location->region_name );
		$display    = $this->normalize( $location->display_name );
		$searchable = $location->get_searchable_text();
		$score      = 0;

		if ( $settlement === $query || $city === $query ) {
			$score += 1000;
		}

		if ( $region === $query ) {
			$score += 700;
		}

		foreach ( array( $settlement, $city, $region, $display ) as $field ) {
			if ( '' !== $field && str_starts_with( $field, $query ) ) {
				$score += 300;
			} elseif ( '' !== $field && str_contains( $field, $query ) ) {
				$score += 100;
			}
		}

		if ( str_contains( $searchable, $query ) ) {
			$score += 25;
		}

		return $score;
	}
}
