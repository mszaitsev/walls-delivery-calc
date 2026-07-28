<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyMatcher {
	/**
	 * @param array<string,string> $row
	 * @return array{status:string,method:string,location_id:int,resolved_after_fias_disambiguation?:bool,true_fias_ambiguity?:bool}
	 */
	public function match( array $row, DpdGeographyMatchContext $context ): array {
		$fias = (string) ( $row['fias'] ?? '' );
		$own_candidates = $context->own_fias_candidates( $fias );
		if ( 1 === count( $own_candidates ) ) {
			return $this->matched( 'own_fias', (int) ( $own_candidates[0]['id'] ?? 0 ) );
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

		$city_candidates = $context->city_fias_candidates( $fias );
		if ( 1 === count( $city_candidates ) ) {
			return $this->matched( 'city_fias', (int) ( $city_candidates[0]['id'] ?? 0 ) );
		}
		if ( count( $city_candidates ) > 1 ) {
			return array( 'status' => 'ambiguous', 'method' => 'city_fias', 'location_id' => 0 );
		}

		$location_id = $context->match_kladr( (string) ( $row['kladr'] ?? '' ) );
		if ( 0 !== $location_id ) {
			if ( $context->is_ambiguous( $location_id ) ) {
				return array( 'status' => 'ambiguous', 'method' => 'kladr', 'location_id' => 0 );
			}
			return $this->matched( 'kladr', $location_id );
		}

		$location_id = $context->match_name( (string) ( $row['region'] ?? '' ), (string) ( $row['district'] ?? '' ), (string) ( $row['settlement'] ?? '' ), $this->normalize_type( (string) ( $row['settlement_type'] ?? '' ) ) );
		if ( 0 !== $location_id ) {
			if ( $context->is_ambiguous( $location_id ) ) {
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
	private function disambiguate_fias_candidates( array $candidates, array $row ): array {
		$row_country = strtoupper( trim( (string) ( $row['country_code'] ?? 'RU' ) ) );
		$row_region = $this->normalize_text( (string) ( $row['region'] ?? '' ) );
		$row_district = $this->normalize_text( (string) ( $row['district'] ?? '' ) );
		$row_name = $this->normalize_text( (string) ( $row['settlement'] ?? '' ) );
		$row_type = $this->normalize_type( (string) ( $row['settlement_type'] ?? '' ) );
		$matches = array();

		foreach ( $candidates as $candidate ) {
			if ( 1 !== (int) ( $candidate['active'] ?? 1 ) ) {
				continue;
			}
			if ( '' !== $row_country && $row_country !== strtoupper( trim( (string) ( $candidate['country_code'] ?? '' ) ) ) ) {
				continue;
			}
			if ( '' !== $row_name && $row_name !== $this->normalize_text( $this->first_non_empty_row_value( $candidate, array( 'place_name', 'settlement_name', 'city_name' ) ) ) ) {
				continue;
			}
			$location_type = $this->normalize_type( $this->first_non_empty_row_value( $candidate, array( 'place_type', 'settlement_type', 'city_type' ) ) );
			if ( '' !== $row_type && '' !== $location_type && $row_type !== $location_type ) {
				continue;
			}
			$location_region = $this->normalize_text( (string) ( $candidate['region_name'] ?? '' ) );
			if ( '' !== $row_region && '' !== $location_region && $row_region !== $location_region ) {
				continue;
			}
			$location_district = $this->normalize_text( (string) ( $candidate['district_name'] ?? '' ) );
			if ( '' !== $row_district && '' !== $location_district && $row_district !== $location_district ) {
				continue;
			}
			$location_id = (int) ( $candidate['id'] ?? 0 );
			if ( $location_id > 0 ) {
				$matches[] = $location_id;
			}
		}

		$matches = array_values( array_unique( $matches ) );
		sort( $matches, SORT_NUMERIC );

		return $matches;
	}

	/**
	 * @param array<int,string> $properties
	 */
	private function first_non_empty_row_value( array $row, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = trim( (string) ( $row[ $key ] ?? '' ) );
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
