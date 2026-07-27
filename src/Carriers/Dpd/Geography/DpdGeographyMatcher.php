<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyMatcher {
	public function __construct(
		private DpdLocationIndex $index
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
			$resolved = $this->index->disambiguate_fias_candidates( $own_candidates, $row );
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
