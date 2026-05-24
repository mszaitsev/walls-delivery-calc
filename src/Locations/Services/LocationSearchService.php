<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Services;

use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class LocationSearchService {
	/** @var array<string,mixed> */
	private array $last_search_meta = array(
		'original_query'   => '',
		'corrected_query'  => '',
		'correction_used'  => false,
		'results_count'    => 0,
	);

	public function __construct(
		private LocationRepository $repository,
		private ?KeyboardLayoutTransformer $keyboard_layout = null
	) {
		$this->keyboard_layout = $this->keyboard_layout ?? new KeyboardLayoutTransformer();
	}

	/**
	 * @return array<int, Location>
	 */
	public function search( string $query, int $limit = 20 ): array {
		$original_normalized = $this->normalize( $query );
		$this->last_search_meta = array(
			'original_query'   => $query,
			'corrected_query'  => '',
			'correction_used'  => false,
			'results_count'    => 0,
		);

		$normalized = $original_normalized;
		if ( '' === $normalized ) {
			return array();
		}

		$locations = $this->repository->search( $normalized, max( $limit * 3, 50 ) );
		$corrected = '';
		if ( array() === $locations ) {
			foreach ( $this->keyboard_layout->variants( $query ) as $variant ) {
				$variant_normalized = $this->normalize( $variant );
				if ( '' === $variant_normalized || $variant_normalized === $original_normalized ) {
					continue;
				}

				$variant_locations = $this->repository->search( $variant_normalized, max( $limit * 3, 50 ) );
				if ( array() !== $variant_locations ) {
					$locations  = $variant_locations;
					$normalized = $variant_normalized;
					$corrected  = $variant;
					$this->last_search_meta['correction_used'] = true;
					break;
				}
			}
		}

		usort(
			$locations,
			fn( Location $a, Location $b ): int => $this->rank( $b, $normalized ) <=> $this->rank( $a, $normalized )
				?: strcmp( $a->display_name, $b->display_name )
		);

		$final = array_slice( $locations, 0, max( 1, $limit ) );
		$this->last_search_meta['corrected_query'] = $corrected;
		$this->last_search_meta['results_count']   = count( $final );

		return $final;
	}

	/**
	 * @return array<string, array<int, Location>>
	 */
	public function grouped( string $query, int $limit = 100 ): array {
		$locations = $this->search( $query, $limit );
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

	/**
	 * @param array<int,string> $tokens
	 * @return array<int, Location>
	 */
	public function search_by_tokens( array $tokens, int $limit = 300, bool $require_all = false, string $force_region_code = '' ): array {
		return $this->repository->search_by_tokens( $tokens, $limit, $require_all, $force_region_code );
	}

	/**
	 * @param array<int,string> $tokens
	 * @return array<int, Location>
	 */
	public function checkout_hierarchy_candidates( array $tokens, int $limit = 1000, string $force_region_code = '' ): array {
		return $this->repository->checkout_hierarchy_candidates( $tokens, $limit, $force_region_code );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function last_search_meta(): array {
		return $this->last_search_meta;
	}

	private function rank( Location $location, string $query ): int {
		$settlement = $this->normalize( $location->resolved_place_name() );
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
