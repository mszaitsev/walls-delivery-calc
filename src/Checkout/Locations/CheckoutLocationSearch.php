<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Locations;

use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class CheckoutLocationSearch {
	public function __construct(
		private LocationSearchService $search_service
	) {
	}

	/**
	 * @return array<int,Location>
	 */
	public function search( string $query, int $limit = 100 ): array {
		return $this->search_service->search( $query, $limit );
	}

	/**
	 * @return array<string,array<int,Location>>
	 */
	public function grouped( string $query, int $limit = 100 ): array {
		return $this->search_service->grouped( $query, $limit );
	}

	public function best_match( string $query ): ?Location {
		$normalized = $this->search_service->normalize( $query );
		if ( '' === $normalized ) {
			return null;
		}

		$locations = $this->search_service->search( $query, 50 );
		if ( array() === $locations ) {
			return null;
		}

		usort(
			$locations,
			fn ( Location $a, Location $b ): int => $this->score( $b, $normalized ) <=> $this->score( $a, $normalized )
				?: strcmp( $a->display_name, $b->display_name )
		);

		return $locations[0] ?? null;
	}

	private function score( Location $location, string $query ): int {
		$settlement = $this->normalize( $location->settlement_name );
		$city       = $this->normalize( $location->city_name );
		$region     = $this->normalize( $location->region_name );
		$display    = $this->normalize( $location->display_name );
		$score      = 0;

		if ( '' !== $settlement && $settlement === $query ) {
			$score += 1200;
		}

		if ( '' !== $city && $city === $query ) {
			$score += 1000;
		}

		if ( '' !== $region && $region === $query ) {
			$score += 650;
		}

		foreach (
			array(
				$settlement => 320,
				$city       => 280,
				$region     => 160,
				$display    => 120,
			) as $field => $prefix_score
		) {
			if ( '' === $field ) {
				continue;
			}

			if ( str_starts_with( $field, $query ) ) {
				$score += $prefix_score;
				continue;
			}

			if ( str_contains( $field, $query ) ) {
				$score += (int) floor( $prefix_score / 3 );
			}
		}

		return $score;
	}

	private function normalize( string $value ): string {
		return $this->search_service->normalize( $value );
	}
}
