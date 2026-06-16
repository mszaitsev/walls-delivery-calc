<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class DpdDuplicateCityResolver {
	/**
	 * @param array<int,array<string,mixed>> $duplicates
	 * @return array{city:array<string,mixed>,score:int,matched_by:array<int,string>}|null
	 */
	public function resolve( array $duplicates, Location $location ): ?array {
		$best = null;
		foreach ( $duplicates as $candidate ) {
			if ( ! is_array( $candidate ) ) {
				continue;
			}
			$score = $this->score_candidate( $candidate, $location );
			if ( null === $best || $score['score'] > $best['score'] ) {
				$best = array(
					'city' => $candidate,
					'score' => $score['score'],
					'matched_by' => $score['matched_by'],
				);
			}
		}

		if ( null === $best || $best['score'] <= 0 ) {
			return null;
		}

		return $best;
	}

	/**
	 * @param array<string,mixed> $response
	 * @return array{city:array<string,mixed>|null,multiple:bool,resolver_applied:bool,score:int,matched_by:array<int,string>}
	 */
	public function resolve_from_response( array $response, Location $location ): array {
		$duplicates = array_merge(
			$this->list_from_key( $response, 'pickupDups' ),
			$this->list_from_key( $response, 'deliveryDups' )
		);
		if ( array() === $duplicates ) {
			return array(
				'city' => null,
				'multiple' => false,
				'resolver_applied' => false,
				'score' => 0,
				'matched_by' => array(),
			);
		}

		$selected = $this->resolve( $duplicates, $location );

		return array(
			'city' => null !== $selected ? $selected['city'] : null,
			'multiple' => count( $duplicates ) > 1,
			'resolver_applied' => true,
			'score' => null !== $selected ? $selected['score'] : 0,
			'matched_by' => null !== $selected ? $selected['matched_by'] : array(),
		);
	}

	/**
	 * @param array<string,mixed> $candidate
	 * @return array{score:int,matched_by:array<int,string>}
	 */
	private function score_candidate( array $candidate, Location $location ): array {
		$score = 0;
		$matched_by = array();

		if ( '' !== $this->location_fias( $location ) && $this->candidate_guid_matches( $candidate, array( 'fiasGuid', 'fiasId', 'fias_id', 'fias', 'guid' ), $this->location_fias( $location ) ) ) {
			$score += 100;
			$matched_by[] = 'fias_guid';
		}
		if ( $location->gar_object_id > 0 && $this->candidate_value_matches( $candidate, array( 'garId', 'gar_id', 'garObjectId', 'gar_object_id' ), (string) $location->gar_object_id ) ) {
			$score += 80;
			$matched_by[] = 'gar_id';
		}
		if ( '' !== trim( $location->kladr_id ) && $this->candidate_value_matches( $candidate, array( 'cityCode', 'city_code', 'kladr', 'kladrId', 'kladr_id' ), trim( $location->kladr_id ) ) ) {
			$score += 60;
			$matched_by[] = 'city_code';
		}
		if ( '' !== trim( $location->region_code ) && $this->candidate_value_matches( $candidate, array( 'regionCode', 'region_code' ), trim( $location->region_code ) ) ) {
			$score += 30;
			$matched_by[] = 'region_code';
		}
		if ( '' !== trim( $location->postal_code ) && $this->candidate_postal_code_matches( $candidate, trim( $location->postal_code ) ) ) {
			$score += 25;
			$matched_by[] = 'postal_code';
		}
		if ( $this->candidate_city_name_matches( $candidate, $location ) ) {
			$score += 20;
			$matched_by[] = 'city_name';
		}

		return array( 'score' => $score, 'matched_by' => array_values( array_unique( $matched_by ) ) );
	}

	/**
	 * @param array<string,mixed> $response
	 * @return array<int,array<string,mixed>>
	 */
	private function list_from_key( array $response, string $key ): array {
		if ( ! isset( $response[ $key ] ) ) {
			return array();
		}
		$value = $response[ $key ];
		if ( is_array( $value ) && $this->is_list( $value ) ) {
			return array_values( array_filter( $value, 'is_array' ) );
		}
		if ( is_array( $value ) ) {
			return array( $value );
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $candidate
	 * @param array<int,string> $keys
	 */
	private function candidate_guid_matches( array $candidate, array $keys, string $expected ): bool {
		$expected = $this->normalize_guid( $expected );
		foreach ( $keys as $key ) {
			if ( isset( $candidate[ $key ] ) && $expected === $this->normalize_guid( (string) $candidate[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $candidate
	 * @param array<int,string> $keys
	 */
	private function candidate_value_matches( array $candidate, array $keys, string $expected ): bool {
		$expected = $this->normalize_code( $expected );
		foreach ( $keys as $key ) {
			if ( isset( $candidate[ $key ] ) && $expected === $this->normalize_code( (string) $candidate[ $key ] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $candidate
	 */
	private function candidate_postal_code_matches( array $candidate, string $postal_code ): bool {
		$postal_code = preg_replace( '/\D+/', '', $postal_code ) ?? '';
		foreach ( array( 'postalCode', 'postal_code', 'index', 'indexMin' ) as $key ) {
			if ( isset( $candidate[ $key ] ) && $postal_code === ( preg_replace( '/\D+/', '', (string) $candidate[ $key ] ) ?? '' ) ) {
				return true;
			}
		}
		$min = isset( $candidate['indexMin'] ) ? (int) preg_replace( '/\D+/', '', (string) $candidate['indexMin'] ) : 0;
		$max = isset( $candidate['indexMax'] ) ? (int) preg_replace( '/\D+/', '', (string) $candidate['indexMax'] ) : 0;
		$postcode = (int) $postal_code;

		return $postcode > 0 && $min > 0 && $max >= $min && $postcode >= $min && $postcode <= $max;
	}

	/**
	 * @param array<string,mixed> $candidate
	 */
	private function candidate_city_name_matches( array $candidate, Location $location ): bool {
		$names = array_filter(
			array(
				$location->resolved_place_name(),
				$location->city_name,
				$location->settlement_name,
				$location->place_name,
			),
			static fn( string $value ): bool => '' !== trim( $value )
		);
		$candidate_names = array();
		foreach ( array( 'cityName', 'city_name', 'city', 'name', 'settlementName' ) as $key ) {
			if ( isset( $candidate[ $key ] ) ) {
				$candidate_names[] = (string) $candidate[ $key ];
			}
		}
		foreach ( $names as $name ) {
			foreach ( $candidate_names as $candidate_name ) {
				if ( $this->normalize_name( $name ) === $this->normalize_name( $candidate_name ) ) {
					return true;
				}
			}
		}

		return false;
	}

	private function location_fias( Location $location ): string {
		return '' !== trim( $location->fias_id ) ? trim( $location->fias_id ) : trim( $location->city_fias_id );
	}

	private function normalize_guid( string $value ): string {
		return strtolower( preg_replace( '/[^a-f0-9]+/i', '', trim( $value ) ) ?? '' );
	}

	private function normalize_code( string $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9]+/i', '', trim( $value ) ) ?? '' );
	}

	private function normalize_name( string $value ): string {
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) ) ?? '';

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}

	/**
	 * @param array<mixed> $value
	 */
	private function is_list( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
