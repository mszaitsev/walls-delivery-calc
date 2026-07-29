<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class JetLogisticGeographyMatcher {
	public function __construct(
		private LocationRepository $locations,
		private JetLogisticGeographyOverrideRepository $overrides,
		private ?JetLogisticRegionNameNormalizer $region_normalizer = null
	) {
		$this->region_normalizer ??= new JetLogisticRegionNameNormalizer();
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	public function match( array $row ): array {
		if ( 'ambiguous' === (string) ( $row['match_status'] ?? '' ) && 'duplicate_conflict' === (string) ( $row['match_source'] ?? '' ) ) {
			return array_merge( $row, array( 'active' => 0 ) );
		}
		$country = strtoupper( (string) ( $row['country_code'] ?? '' ) );
		$city = trim( (string) ( $row['source_city'] ?? '' ) );
		$place_type = trim( (string) ( $row['source_place_type'] ?? '' ) );
		$region = trim( (string) ( $row['source_region'] ?? '' ) );
		if ( '' === $city ) {
			return array_merge( $row, array( 'match_status' => 'invalid', 'match_source' => 'missing_city', 'active' => 0 ) );
		}
		if ( '' === $region ) {
			return array_merge( $row, array( 'match_status' => 'unmatched', 'match_source' => 'missing_region', 'active' => 0 ) );
		}

		$identity = (string) ( $row['source_identity'] ?? '' );
		$override = $this->overrides->find( $identity );
		if ( array() === $override ) {
			$legacy_identity = (string) ( $row['legacy_source_identity'] ?? '' );
			$legacy_override = '' !== $legacy_identity && $legacy_identity !== $identity ? $this->overrides->find( $legacy_identity ) : array();
			if ( array() !== $legacy_override && $this->overrides->save( $identity, (int) $legacy_override['location_id'], (string) $legacy_override['country_code'] ) ) {
				$this->overrides->delete( $legacy_identity );
				$override = $this->overrides->find( $identity );
			}
		}
		if ( (int) ( $override['location_id'] ?? 0 ) > 0 ) {
			$location = $this->locations->find_by_id( (int) $override['location_id'] );
			if ( null !== $location && $location->active ) {
				return array_merge( $row, array( 'location_id' => $location->id, 'country_code' => $location->country_code ?: $country, 'match_status' => 'matched', 'match_source' => 'manual_override' ) );
			}
		}

		$normalized_region = $this->region_normalizer->normalize( $region );
		$matches = '' !== $country
			? array_values( array_filter( $this->locations->find_active_by_place_and_region_matches( $city, $normalized_region, $place_type ), static fn( $location ): bool => $country === strtoupper( $location->country_code ) ) )
			: $this->locations->find_active_by_place_and_region_matches( $city, $normalized_region, $place_type );
		$count = count( $matches );
		if ( $count > 1 ) {
			return array_merge( $row, array( 'match_status' => 'ambiguous', 'match_source' => '' !== $country ? 'exact_name_region_multiple' : 'exact_name_region_multiple', 'active' => 0 ) );
		}
		if ( 1 === $count ) {
			$location = $matches[0];
			if ( 'RU' === strtoupper( $location->country_code ) ) {
				return array_merge( $row, array( 'location_id' => $location->id, 'country_code' => 'RU', 'match_status' => 'ignored', 'match_source' => '' === $country ? 'country_ru_inferred_by_region' : 'country_ru', 'active' => 0 ) );
			}

			return array_merge( $row, array( 'location_id' => $location->id, 'country_code' => $location->country_code, 'match_status' => 'matched', 'match_source' => '' === $country ? 'exact_name_region_inferred_country' : 'exact_name_region', 'active' => 1 ) );
		}

		return array_merge( $row, array( 'match_status' => 'unmatched', 'match_source' => 'exact_name_region_not_found', 'active' => 0 ) );
	}
}
