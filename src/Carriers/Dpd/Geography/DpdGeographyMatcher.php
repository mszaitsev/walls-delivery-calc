<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyMatcher {
	public function __construct(
		private DpdLocationIndex $index,
		private LocationRepository $locations
	) {
	}

	/**
	 * @param array<string,string> $row
	 * @return array{status:string,method:string,location_id:int,resolved_after_fias_disambiguation?:bool,true_fias_ambiguity?:bool}
	 */
	public function match( array $row ): array {
		$fias = (string) ( $row['fias'] ?? '' );
		$own_candidates = $this->index->match_own_fias( $fias );
		if ( 1 === count( $own_candidates ) ) {
			return $this->matched( 'own_fias', $own_candidates[0] );
		}
		if ( count( $own_candidates ) > 1 ) {
			$resolved = $this->disambiguate_fias_candidates( $own_candidates, $row );
			if ( 1 === count( $resolved ) ) {
				return array(
					'status' => 'matched',
					'method' => 'own_fias',
					'location_id' => $resolved[0],
					'resolved_after_fias_disambiguation' => true,
				);
			}

			return array( 'status' => 'ambiguous', 'method' => 'own_fias', 'location_id' => 0, 'true_fias_ambiguity' => true );
		}

		$city_candidates = $this->index->match_city_fias( $fias );
		if ( 1 === count( $city_candidates ) ) {
			return $this->matched( 'city_fias', $city_candidates[0] );
		}
		if ( count( $city_candidates ) > 1 ) {
			return array( 'status' => 'ambiguous', 'method' => 'city_fias', 'location_id' => 0 );
		}

		$location_id = $this->index->match_kladr( (string) ( $row['kladr'] ?? '' ) );
		if ( 0 !== $location_id ) {
			if ( $this->index->is_ambiguous( $location_id ) ) {
				return array( 'status' => 'ambiguous', 'method' => 'kladr', 'location_id' => 0 );
			}
			return $this->matched( 'kladr', $location_id );
		}

		$location_id = $this->index->match_name( (string) ( $row['region'] ?? '' ), (string) ( $row['district'] ?? '' ), (string) ( $row['settlement'] ?? '' ), $this->normalize_type( (string) ( $row['settlement_type'] ?? '' ) ) );
		if ( 0 !== $location_id ) {
			if ( $this->index->is_ambiguous( $location_id ) ) {
				return array( 'status' => 'ambiguous', 'method' => 'name', 'location_id' => 0 );
			}
			return $this->matched( 'name', $location_id );
		}

		return array( 'status' => 'unmatched', 'method' => '', 'location_id' => 0 );
	}

	/**
	 * @return array{status:string,method:string,location_id:int}
	 */
	private function matched( string $method, int $location_id ): array {
		return array( 'status' => 'matched', 'method' => $method, 'location_id' => $location_id );
	}

	/**
	 * @param array<int,int> $location_ids
	 * @param array<string,string> $row
	 * @return array<int,int>
	 */
	private function disambiguate_fias_candidates( array $location_ids, array $row ): array {
		$row_country = strtoupper( trim( (string) ( $row['country_code'] ?? 'RU' ) ) );
		$row_region = $this->normalize_text( (string) ( $row['region'] ?? '' ) );
		$row_district = $this->normalize_text( (string) ( $row['district'] ?? '' ) );
		$row_name = $this->normalize_text( (string) ( $row['settlement'] ?? '' ) );
		$row_type = $this->normalize_type( (string) ( $row['settlement_type'] ?? '' ) );
		$matches = array();

		foreach ( $this->locations->find_locations_by_ids( $location_ids ) as $location ) {
			if ( ! $location instanceof Location || ! $location->active ) {
				continue;
			}
			if ( '' !== $row_country && $row_country !== strtoupper( trim( $location->country_code ) ) ) {
				continue;
			}
			if ( '' !== $row_name && $row_name !== $this->normalize_text( $this->first_non_empty_location_value( $location, array( 'place_name', 'settlement_name', 'city_name' ) ) ) ) {
				continue;
			}
			$location_type = $this->normalize_type( $this->first_non_empty_location_value( $location, array( 'place_type', 'settlement_type', 'city_type' ) ) );
			if ( '' !== $row_type && '' !== $location_type && $row_type !== $location_type ) {
				continue;
			}
			if ( '' !== $row_region && '' !== $this->normalize_text( $location->region_name ) && $row_region !== $this->normalize_text( $location->region_name ) ) {
				continue;
			}
			if ( '' !== $row_district && '' !== $this->normalize_text( $location->district_name ) && $row_district !== $this->normalize_text( $location->district_name ) ) {
				continue;
			}
			if ( null !== $location->id && $location->id > 0 ) {
				$matches[] = (int) $location->id;
			}
		}

		$matches = array_values( array_unique( $matches ) );
		sort( $matches, SORT_NUMERIC );

		return $matches;
	}

	/**
	 * @param array<int,string> $properties
	 */
	private function first_non_empty_location_value( Location $location, array $properties ): string {
		foreach ( $properties as $property ) {
			$value = trim( (string) ( $location->{$property} ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function normalize_text( string $value ): string {
		$value = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), trim( $value ) );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		return preg_replace( '/\s+/u', ' ', $value ) ?? $value;
	}

	private function normalize_type( string $value ): string {
		$value = $this->normalize_text( $value );
		return match ( $value ) {
			'город' => 'г',
			'поселок', 'посёлок' => 'п',
			'село' => 'с',
			'деревня' => 'д',
			default => $value,
		};
	}
}
