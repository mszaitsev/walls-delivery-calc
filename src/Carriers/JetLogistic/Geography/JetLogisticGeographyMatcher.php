<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class JetLogisticGeographyMatcher {
	public function __construct(
		private LocationRepository $locations,
		private JetLogisticGeographyOverrideRepository $overrides
	) {
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	public function match( array $row ): array {
		$country = strtoupper( (string) ( $row['country_code'] ?? '' ) );
		if ( 'RU' === $country ) {
			return array_merge( $row, array( 'match_status' => 'ignored', 'match_source' => 'country_ru', 'active' => 0 ) );
		}
		if ( '' === $country || '' === trim( (string) ( $row['source_city'] ?? '' ) ) ) {
			return array_merge( $row, array( 'match_status' => 'invalid', 'match_source' => 'invalid_source', 'active' => 0 ) );
		}

		$override = $this->overrides->find( (string) $row['source_identity'] );
		if ( (int) ( $override['location_id'] ?? 0 ) > 0 ) {
			$location = $this->locations->find_by_id( (int) $override['location_id'] );
			if ( null !== $location && $location->active ) {
				return array_merge( $row, array( 'location_id' => $location->id, 'country_code' => $location->country_code ?: $country, 'match_status' => 'matched', 'match_source' => 'manual_override' ) );
			}
		}

		$matches = $this->locations->find_foreign_by_place_identity_matches( $country, (string) $row['source_city'], (string) ( $row['source_region'] ?? '' ) );
		if ( 1 === count( $matches ) ) {
			return array_merge( $row, array( 'location_id' => $matches[0]->id, 'match_status' => 'matched', 'match_source' => 'exact_name_region' ) );
		}
		if ( count( $matches ) > 1 ) {
			return array_merge( $row, array( 'match_status' => 'ambiguous', 'match_source' => 'exact_name_region' ) );
		}

		$matches = $this->locations->find_foreign_by_place_identity_matches( $country, (string) $row['source_city'] );
		if ( 1 === count( $matches ) ) {
			return array_merge( $row, array( 'location_id' => $matches[0]->id, 'match_status' => 'matched', 'match_source' => 'exact_name_unique' ) );
		}
		if ( count( $matches ) > 1 ) {
			return array_merge( $row, array( 'match_status' => 'ambiguous', 'match_source' => 'exact_name' ) );
		}

		return array_merge( $row, array( 'match_status' => 'unmatched', 'match_source' => '' ) );
	}
}
