<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\Storage\PlaceRegionMatchResult;

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
		if ( array() === $override && true === (bool) ( $row['legacy_override_migration_allowed'] ?? false ) ) {
			$legacy_identities = array_map( 'strval', (array) ( $row['legacy_override_migration_allowed_identities'] ?? array() ) );
			if ( array() === $legacy_identities ) {
				$legacy_identities = array_merge(
					array( (string) ( $row['legacy_source_identity'] ?? '' ) ),
					array_map( 'strval', (array) ( $row['legacy_source_identities'] ?? array() ) )
				);
			}
			$legacy_identities = array_filter(
				array_unique( $legacy_identities ),
				static fn( string $legacy_identity ): bool => '' !== $legacy_identity && $legacy_identity !== $identity
			);
			foreach ( $legacy_identities as $legacy_identity ) {
				$legacy_override = $this->overrides->find( $legacy_identity );
				if ( array() !== $legacy_override && $this->overrides->save( $identity, (int) $legacy_override['location_id'], (string) $legacy_override['country_code'] ) ) {
					$this->overrides->delete( $legacy_identity );
					$override = $this->overrides->find( $identity );
					break;
				}
			}
		}
		if ( (int) ( $override['location_id'] ?? 0 ) > 0 ) {
			$location = $this->locations->find_by_id( (int) $override['location_id'] );
			if ( null !== $location && $location->active ) {
				return array_merge( $row, array( 'location_id' => $location->id, 'country_code' => $location->country_code ?: $country, 'match_status' => 'matched', 'match_source' => 'manual_override' ) );
			}
		}

		$normalized_region = $this->region_normalizer->normalize( $region );
		$result = $this->locations->resolve_active_by_place_and_region( $city, $normalized_region, $place_type, $country );
		$matches = $result->matches;
		$count = count( $matches );
		if ( $count > 1 ) {
			return array_merge( $row, array( 'match_status' => 'ambiguous', 'match_source' => 'exact_name_region_multiple', 'active' => 0 ) );
		}
		if ( 1 === $count ) {
			$location = $matches[0];
			if ( 'RU' === strtoupper( $location->country_code ) ) {
				return array_merge( $row, array( 'location_id' => $location->id, 'country_code' => 'RU', 'match_status' => 'ignored', 'match_source' => '' === $country ? 'country_ru_inferred_by_region' : 'country_ru', 'active' => 0 ) );
			}

			return array_merge( $row, array( 'location_id' => $location->id, 'country_code' => $location->country_code, 'match_status' => 'matched', 'match_source' => '' === $country ? 'exact_name_region_inferred_country' : 'exact_name_region', 'active' => 1 ) );
		}

		return array_merge( $row, array( 'match_status' => 'unmatched', 'match_source' => PlaceRegionMatchResult::TYPE_MISMATCH === $result->resolution ? 'place_type_mismatch' : 'exact_name_region_not_found', 'active' => 0 ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{rows:array<int,array<string,mixed>>,legacy_override_migration_failures:int}
	 */
	public function match_many( array $rows ): array {
		$identities = array();
		$legacy_identities = array();
		foreach ( $rows as $row ) {
			$identities[] = (string) ( $row['source_identity'] ?? '' );
			if ( true === (bool) ( $row['legacy_override_migration_allowed'] ?? false ) ) {
				foreach ( array_map( 'strval', (array) ( $row['legacy_override_migration_allowed_identities'] ?? array() ) ) as $legacy_identity ) {
					$legacy_identities[] = $legacy_identity;
				}
			}
		}
		$overrides = $this->overrides->find_many( array_merge( $identities, $legacy_identities ) );
		$migration_failures = 0;
		$automatic_requests = array();
		$pending = array();
		$matched = array();
		$manual_rows = array();
		$manual_location_ids = array();
		foreach ( $rows as $index => $row ) {
			if ( 'ambiguous' === (string) ( $row['match_status'] ?? '' ) && 'duplicate_conflict' === (string) ( $row['match_source'] ?? '' ) ) {
				$matched[ $index ] = array_merge( $row, array( 'active' => 0 ) );
				continue;
			}
			$manual = $this->manual_override_for_row( $row, $overrides, $migration_failures );
			if ( array() !== $manual ) {
				$manual_rows[ $index ] = array( 'row' => $row, 'manual' => $manual );
				$manual_location_ids[] = (int) $manual['location_id'];
				continue;
			}
			$city = trim( (string) ( $row['source_city'] ?? '' ) );
			$region = trim( (string) ( $row['source_region'] ?? '' ) );
			if ( '' === $city ) {
				$matched[ $index ] = array_merge( $row, array( 'match_status' => 'invalid', 'match_source' => 'missing_city', 'active' => 0 ) );
				continue;
			}
			if ( '' === $region ) {
				$matched[ $index ] = array_merge( $row, array( 'match_status' => 'unmatched', 'match_source' => 'missing_region', 'active' => 0 ) );
				continue;
			}
			$request = array(
				'source_city' => $city,
				'normalized_region' => $this->region_normalizer->normalize( $region ),
				'source_place_type' => trim( (string) ( $row['source_place_type'] ?? '' ) ),
				'country_code' => strtoupper( (string) ( $row['country_code'] ?? '' ) ),
			);
			$pending[ $index ] = $request;
			$automatic_requests[] = $request;
		}

		foreach ( $this->locations->find_active_map_by_ids( $manual_location_ids ) as $location_id => $location ) {
			foreach ( $manual_rows as $index => $manual_row ) {
				if ( (int) ( $manual_row['manual']['location_id'] ?? 0 ) === (int) $location_id ) {
					$matched[ $index ] = array_merge( $manual_row['row'], array( 'location_id' => $location->id, 'country_code' => $location->country_code ?: strtoupper( (string) ( $manual_row['row']['country_code'] ?? '' ) ), 'match_status' => 'matched', 'match_source' => 'manual_override', 'active' => 1 ) );
					unset( $manual_rows[ $index ] );
				}
			}
		}
		foreach ( $manual_rows as $index => $manual_row ) {
			$row = $manual_row['row'];
			$city = trim( (string) ( $row['source_city'] ?? '' ) );
			$region = trim( (string) ( $row['source_region'] ?? '' ) );
			if ( '' === $city ) {
				$matched[ $index ] = array_merge( $row, array( 'match_status' => 'invalid', 'match_source' => 'missing_city', 'active' => 0 ) );
				continue;
			}
			if ( '' === $region ) {
				$matched[ $index ] = array_merge( $row, array( 'match_status' => 'unmatched', 'match_source' => 'missing_region', 'active' => 0 ) );
				continue;
			}
			$request = array(
				'source_city' => $city,
				'normalized_region' => $this->region_normalizer->normalize( $region ),
				'source_place_type' => trim( (string) ( $row['source_place_type'] ?? '' ) ),
				'country_code' => strtoupper( (string) ( $row['country_code'] ?? '' ) ),
			);
			$pending[ $index ] = $request;
			$automatic_requests[] = $request;
		}

		$batch_results = $this->locations->resolve_active_place_region_batch( $automatic_requests );
		foreach ( $pending as $index => $request ) {
			$row = $rows[ $index ];
			$key = $this->locations->place_region_request_key( $request['source_city'], $request['normalized_region'], $request['source_place_type'], $request['country_code'] );
			$matched[ $index ] = $this->row_from_place_region_result( $row, $batch_results[ $key ] ?? new PlaceRegionMatchResult( array(), PlaceRegionMatchResult::NOT_FOUND ), '' === (string) $request['country_code'] );
		}

		ksort( $matched );

		return array( 'rows' => array_values( $matched ), 'legacy_override_migration_failures' => $migration_failures );
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,array<string,mixed>> $overrides
	 * @return array<string,mixed>
	 */
	private function manual_override_for_row( array $row, array &$overrides, int &$migration_failures ): array {
		$identity = (string) ( $row['source_identity'] ?? '' );
		if ( isset( $overrides[ $identity ] ) ) {
			return $overrides[ $identity ];
		}
		if ( true !== (bool) ( $row['legacy_override_migration_allowed'] ?? false ) ) {
			return array();
		}
		foreach ( array_map( 'strval', (array) ( $row['legacy_override_migration_allowed_identities'] ?? array() ) ) as $legacy_identity ) {
			if ( ! isset( $overrides[ $legacy_identity ] ) ) {
				continue;
			}
			$legacy_override = $overrides[ $legacy_identity ];
			if ( $this->overrides->save( $identity, (int) $legacy_override['location_id'], (string) $legacy_override['country_code'] ) ) {
				$this->overrides->delete( $legacy_identity );
				$overrides[ $identity ] = array_merge( $legacy_override, array( 'source_identity' => $identity ) );
				unset( $overrides[ $legacy_identity ] );
				return $overrides[ $identity ];
			}
			++$migration_failures;
			return array();
		}

		return array();
	}

	/** @param array<string,mixed> $row */
	private function row_from_place_region_result( array $row, PlaceRegionMatchResult $result, bool $country_inferred ): array {
		$count = count( $result->matches );
		if ( $count > 1 ) {
			return array_merge( $row, array( 'match_status' => 'ambiguous', 'match_source' => 'exact_name_region_multiple', 'active' => 0 ) );
		}
		if ( 1 === $count ) {
			$location = $result->matches[0];
			if ( 'RU' === strtoupper( $location->country_code ) ) {
				return array_merge( $row, array( 'location_id' => $location->id, 'country_code' => 'RU', 'match_status' => 'ignored', 'match_source' => $country_inferred ? 'country_ru_inferred_by_region' : 'country_ru', 'active' => 0 ) );
			}

			return array_merge( $row, array( 'location_id' => $location->id, 'country_code' => $location->country_code, 'match_status' => 'matched', 'match_source' => $country_inferred ? 'exact_name_region_inferred_country' : 'exact_name_region', 'active' => 1 ) );
		}

		return array_merge( $row, array( 'match_status' => 'unmatched', 'match_source' => PlaceRegionMatchResult::TYPE_MISMATCH === $result->resolution ? 'place_type_mismatch' : 'exact_name_region_not_found', 'active' => 0 ) );
	}
}
