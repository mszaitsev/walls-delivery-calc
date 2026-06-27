<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

defined( 'ABSPATH' ) || exit;

final class YandexLocationMapperV2Service {
	private object $wpdb;
	private YandexLocationMappingV2NameNormalizer $normalizer;
	private YandexRegionMappingV2Repository $region_mapping;

	public function __construct( private YandexLocationMappingV2Repository $repository, ?object $wpdb = null, ?YandexLocationMappingV2NameNormalizer $normalizer = null, ?YandexRegionMappingV2Repository $region_mapping = null ) {
		$db = $wpdb;
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		$this->wpdb = $db;
		$this->normalizer = $normalizer ?? new YandexLocationMappingV2NameNormalizer();
		$this->region_mapping = $region_mapping ?? new YandexRegionMappingV2Repository( $this->wpdb );
	}

	/** @return array{processed_geo_ids:int,mapped:int,needs_review:int,no_match:int,saved:int,errors:int,next_offset:int,done:bool} */
	public function build_all( int $limit = 100, int $offset = 0 ): array {
		$limit = max( 1, min( 500, $limit ) );
		$offset = max( 0, $offset );
		$geo_rows = $this->fetch_geo_rows( $limit, $offset );
		$result = array( 'processed_geo_ids' => count( $geo_rows ), 'mapped' => 0, 'needs_review' => 0, 'no_match' => 0, 'saved' => 0, 'errors' => 0, 'next_offset' => $offset + count( $geo_rows ), 'done' => count( $geo_rows ) < $limit );
		foreach ( $geo_rows as $geo ) {
			try {
				$rows = $this->map_geo_row( $geo );
				$report = $this->repository->upsert( $rows );
				$result['saved'] += (int) ( $report['saved'] ?? 0 );
				$status = (string) ( $rows[0]['status'] ?? 'error' );
				if ( isset( $result[ $status ] ) ) {
					++$result[ $status ];
				}
			} catch ( \Throwable ) {
				++$result['errors'];
			}
		}

		return $result;
	}

	/** @param array<string,mixed> $geo @return array<int,array<string,mixed>> */
	public function map_geo_row( array $geo ): array {
		$geo_id = (int) ( $geo['yandex_geo_id'] ?? 0 );
		if ( $geo_id <= 0 ) {
			return array();
		}
		$diagnostics = array();
		$candidates = $this->find_candidates( $geo, $diagnostics );
		if ( array() === $candidates ) {
			$territory_candidate = $this->territory_coordinate_fallback( $geo, $diagnostics );
			if ( null !== $territory_candidate ) {
				return array( $this->mapping_row( $geo, $territory_candidate['location'], 'mapped', $territory_candidate['confidence'], $territory_candidate['distance_km'], $territory_candidate['matched_by'], $territory_candidate['raw'] ) );
			}

			return array( $this->mapping_row( $geo, array(), 'no_match', 0, null, array(), array_merge( array( 'candidate_count' => 0 ), $diagnostics ) ) );
		}
		$candidates = $this->choose_dominant_candidate( $candidates, $geo );
		$status = 1 === count( $candidates ) ? 'mapped' : 'needs_review';
		$rows = array();
		foreach ( $candidates as $index => $candidate ) {
			$rows[] = $this->mapping_row( $geo, $candidate['location'], $status, $candidate['confidence'], $candidate['distance_km'], $candidate['matched_by'], $candidate['raw'], 0 === $index );
		}

		return $rows;
	}

	/** @param array<string,mixed> $geo @param array<string,mixed> $diagnostics @return array<int,array<string,mixed>> */
	private function find_candidates( array $geo, array &$diagnostics = array() ): array {
		$locality_raw = (string) ( $geo['locality'] ?? '' );
		$locality = $this->normalizer->normalize_place( $locality_raw );
		$search_terms = $this->normalizer->search_terms_for_locality( $locality_raw );
		$mapped_regions = $this->region_mapping->find_wdc_regions_for_yandex( (string) ( $geo['region'] ?? '' ) );
		$diagnostics = $this->default_diagnostics( $search_terms, $mapped_regions, (string) ( $geo['region'] ?? '' ) );
		if ( array() === $mapped_regions ) {
			$diagnostics['reason'] = 'region_not_mapped';
			return array();
		}
		if ( '' === $locality || array() === $search_terms ) {
			$diagnostics['reason'] = 'no_locality_match';
			return array();
		}

		$sql_candidates = $this->fetch_locations_by_regions( $mapped_regions );
		$diagnostics['region_before_filters'] = count( $sql_candidates );
		$candidates = $this->build_candidate_rows( $sql_candidates, $geo, $locality, $search_terms, $diagnostics );
		$diagnostics['region_after_filters'] = count( $candidates );
		$diagnostics['candidate_count_before_filters'] = $diagnostics['region_before_filters'];
		$diagnostics['candidate_count_after_filters'] = count( $candidates );
		if ( array() === $candidates ) {
			$diagnostics['reason'] = 'no_locality_match';
		}
		$diagnostics['dedupe_before'] = count( $candidates );
		$candidates = $this->dedupe_candidates( $candidates );
		$diagnostics['dedupe_after'] = count( $candidates );
		$this->finalize_candidate_diagnostics( $candidates, $diagnostics );
		$this->sort_candidates( $candidates );

		return $candidates;
	}

	/** @param array<int,array<string,mixed>> $locations @param array<string,mixed> $geo @param array<int,string> $search_terms @param array<string,mixed> $diagnostics @return array<int,array<string,mixed>> */
	private function build_candidate_rows( array $locations, array $geo, string $locality, array $search_terms, array $diagnostics ): array {
		$locality_raw = (string) ( $geo['locality'] ?? '' );
		$centroid_lat = is_numeric( $geo['centroid_lat'] ?? null ) ? (float) $geo['centroid_lat'] : null;
		$centroid_lon = is_numeric( $geo['centroid_lon'] ?? null ) ? (float) $geo['centroid_lon'] : null;
		$safe_radius = is_numeric( $geo['coverage_radius_safe_km'] ?? null ) ? (float) $geo['coverage_radius_safe_km'] : 0.0;
		$threshold = max( 50.0, $safe_radius + 10.0 );
		$candidates = array();

		foreach ( $locations as $location ) {
			$effective_locality = $this->normalizer->effective_location_locality( $location );
			if ( null === $effective_locality || $effective_locality['value'] !== $locality ) {
				continue;
			}
			$distance = null;
			$coordinate_match = false;
			if ( null !== $centroid_lat && null !== $centroid_lon && $this->has_valid_location_coordinates( $location ) ) {
				$distance = round( $this->distance_km( $centroid_lat, $centroid_lon, (float) $location['latitude'], (float) $location['longitude'] ), 3 );
				$coordinate_match = $distance <= $threshold;
			}
			if ( ! $coordinate_match ) {
				continue;
			}
			$matched_by = array( 'locality', 'region', 'coordinates' );
			$type_score = $this->normalizer->type_match_score( $locality_raw, $location );
			$yandex_type = $this->normalizer->detect_locality_type( $locality_raw );
			$wdc_type = $this->normalizer->detect_location_type( $location );
			$candidates[] = array(
				'location' => $location,
				'distance_km' => $distance,
				'type_score' => $type_score,
				'confidence' => $this->confidence( true, true, $coordinate_match, $type_score ),
				'matched_by' => $matched_by,
				'raw' => array_merge(
					$diagnostics,
					array(
						'distance' => $distance,
						'radius' => $safe_radius,
						'threshold' => $threshold,
						'candidate_count' => 0,
						'region_matched' => true,
						'locality_source' => $effective_locality['source'],
						'locality_raw' => $effective_locality['raw'],
						'effective_locality' => $effective_locality['value'],
						'yandex_type' => $yandex_type,
						'wdc_type' => $wdc_type,
						'type_score' => $type_score,
						'sql_search_terms' => $search_terms,
					)
				),
			);
		}

		return $candidates;
	}

	/** @param array<int,array<string,mixed>> $candidates @return array<int,array<string,mixed>> */
	private function dedupe_candidates( array $candidates ): array {
		$groups = array();
		foreach ( $candidates as $candidate ) {
			$key = $this->dedupe_key( $candidate );
			$groups[ $key ][] = $candidate;
		}
		$result = array();
		foreach ( $groups as $group ) {
			usort( $group, static fn( array $a, array $b ): int => (int) ( $a['location']['id'] ?? 0 ) <=> (int) ( $b['location']['id'] ?? 0 ) );
			$primary = $group[0];
			$ids = array_values( array_unique( array_map( static fn( array $candidate ): int => (int) ( $candidate['location']['id'] ?? 0 ), $group ) ) );
			if ( count( $ids ) > 1 ) {
				$primary['raw']['deduped_location_ids'] = array_slice( $ids, 0, 20 );
			}
			$result[] = $primary;
		}

		return $result;
	}

	/** @param array<string,mixed> $candidate */
	private function dedupe_key( array $candidate ): string {
		$location = is_array( $candidate['location'] ?? null ) ? $candidate['location'] : array();
		$raw = is_array( $candidate['raw'] ?? null ) ? $candidate['raw'] : array();
		$lat = is_numeric( $location['latitude'] ?? null ) ? number_format( (float) $location['latitude'], 5, '.', '' ) : '';
		$lon = is_numeric( $location['longitude'] ?? null ) ? number_format( (float) $location['longitude'], 5, '.', '' ) : '';

		return implode(
			'|',
			array(
				$this->dedupe_text( (string) ( $location['region_name'] ?? '' ) ),
				$this->dedupe_text( (string) ( $location['district_name'] ?? '' ) ),
				(string) ( $raw['effective_locality'] ?? '' ),
				(string) ( $raw['wdc_type'] ?? '' ),
				$lat,
				$lon,
				$this->normalizer->normalize_place( (string) ( $location['display_name'] ?? '' ) ),
			)
		);
	}

	/** @param array<int,array<string,mixed>> $candidates @param array<string,mixed> $diagnostics */
	private function finalize_candidate_diagnostics( array &$candidates, array $diagnostics ): void {
		$count = count( $candidates );
		foreach ( $candidates as &$candidate ) {
			$candidate['raw'] = array_merge( $candidate['raw'], $diagnostics );
			$candidate['raw']['candidate_count'] = $count;
			$candidate['raw']['candidate_count_before_filters'] = $diagnostics['candidate_count_before_filters'];
			$candidate['raw']['candidate_count_after_filters'] = $diagnostics['candidate_count_after_filters'];
			$candidate['raw']['dedupe_before'] = $diagnostics['dedupe_before'];
			$candidate['raw']['dedupe_after'] = $diagnostics['dedupe_after'];
		}
		unset( $candidate );
	}

	/** @param array<int,array<string,mixed>> $candidates */
	private function sort_candidates( array &$candidates ): void {
		usort(
			$candidates,
			static fn( array $a, array $b ): int => ( (int) ( $b['confidence'] ?? 0 ) <=> (int) ( $a['confidence'] ?? 0 ) )
				?: ( (int) ( $b['type_score'] ?? 0 ) <=> (int) ( $a['type_score'] ?? 0 ) )
				?: ( (float) ( $a['distance_km'] ?? 999999 ) <=> (float) ( $b['distance_km'] ?? 999999 ) )
		);
	}

	/** @param array<int,string> $search_terms @param array<int,string> $mapped_regions @return array<string,mixed> */
	private function default_diagnostics( array $search_terms, array $mapped_regions = array(), string $yandex_region = '' ): array {
		return array(
			'sql_search_terms' => $search_terms,
			'candidate_search_mode' => 'region_mapping',
			'yandex_region' => $yandex_region,
			'mapped_regions' => array_values( $mapped_regions ),
			'reason' => '',
			'region_before_filters' => 0,
			'region_after_filters' => 0,
			'candidate_count_before_filters' => 0,
			'candidate_count_after_filters' => 0,
			'dedupe_before' => 0,
			'dedupe_after' => 0,
			'territory_fallback' => false,
		);
	}


	/** @param array<int,array<string,mixed>> $candidates @param array<string,mixed> $geo @return array<int,array<string,mixed>> */
	private function choose_dominant_candidate( array $candidates, array $geo ): array {
		if ( count( $candidates ) <= 1 ) {
			return $candidates;
		}
		$regions = array_values( array_unique( array_map( static fn( array $candidate ): string => (string) ( $candidate['location']['region_name'] ?? '' ), $candidates ) ) );
		if ( count( $regions ) > 1 ) {
			return $this->mark_ambiguous_dominance( $candidates );
		}
		$candidates = $this->dedupe_equivalent_candidates( $candidates );
		if ( 1 === count( $candidates ) ) {
			return $candidates;
		}
		$this->sort_candidates( $candidates );
		$primary = $candidates[0];
		$second = $candidates[1];
		$primary_distance = $this->candidate_distance( $primary );
		$second_distance = $this->candidate_distance( $second );
		$safe_radius = is_numeric( $geo['coverage_radius_safe_km'] ?? null ) ? (float) $geo['coverage_radius_safe_km'] : 0.0;
		$reason = '';
		if ( $second_distance - $primary_distance >= 5.0 ) {
			$reason = 'distance_gap';
		} elseif ( $safe_radius > 0.0 && $second_distance >= $safe_radius * 2.0 ) {
			$reason = 'safe_radius_x2';
		} elseif ( $this->type_priority( $primary ) - $this->type_priority( $second ) >= 10 ) {
			$reason = 'type_priority';
		} elseif ( $primary_distance <= 1.0 && $second_distance - $primary_distance >= 2.0 ) {
			$reason = 'near_distance_gap';
		} elseif ( 2 === count( $candidates ) && $this->same_region_and_locality( $primary, $second ) && $this->type_priority( $primary ) - $this->type_priority( $second ) >= 10 ) {
			$reason = 'same_locality_type_priority';
		} else {
			$base_candidate = $this->base_place_candidate( $candidates );
			if ( null !== $base_candidate ) {
				return $this->dominance_pick( $base_candidate, $candidates, 'base_place' );
			}
			$place_candidate = $this->place_name_candidate( $candidates );
			if ( null !== $place_candidate ) {
				return $this->dominance_pick( $place_candidate, $candidates, 'place_name_source' );
			}
			if ( $safe_radius > 0.0 && $primary_distance <= $safe_radius && $this->all_other_candidates_outside_safe_radius( array_slice( $candidates, 1 ), $safe_radius ) ) {
				$reason = 'safe_radius';
			}
		}
		if ( '' === $reason ) {
			return $this->mark_ambiguous_dominance( $candidates );
		}

		return $this->dominance_pick( $primary, $candidates, $reason );
	}

	/** @param array<int,array<string,mixed>> $candidates @return array<int,array<string,mixed>> */
	private function mark_ambiguous_dominance( array $candidates ): array {
		foreach ( $candidates as &$candidate ) {
			$candidate['raw']['dominance_auto_pick'] = false;
			$candidate['raw']['dominance_reason'] = 'ambiguous';
		}
		unset( $candidate );

		return $candidates;
	}

	/** @param array<string,mixed> $primary @param array<int,array<string,mixed>> $candidates @return array<int,array<string,mixed>> */
	private function dominance_pick( array $primary, array $candidates, string $rule ): array {
		$primary_id = (int) ( $primary['location']['id'] ?? 0 );
		$primary['raw']['dominance_auto_pick'] = true;
		$primary['raw']['dominance_rule'] = $rule;
		$primary['raw']['dominance_reason'] = $rule;
		$primary['raw']['rejected_candidates'] = $this->dominance_rejected_candidates( array_values( array_filter( $candidates, static fn( array $candidate ): bool => (int) ( $candidate['location']['id'] ?? 0 ) !== $primary_id ) ) );

		return array( $primary );
	}

	/** @param array<int,array<string,mixed>> $candidates @return array<int,array<string,mixed>> */
	private function dedupe_equivalent_candidates( array $candidates ): array {
		$groups = array();
		foreach ( $candidates as $candidate ) {
			$location = is_array( $candidate['location'] ?? null ) ? $candidate['location'] : array();
			$raw = is_array( $candidate['raw'] ?? null ) ? $candidate['raw'] : array();
			$lat = is_numeric( $location['latitude'] ?? null ) ? number_format( (float) $location['latitude'], 5, '.', '' ) : '';
			$lon = is_numeric( $location['longitude'] ?? null ) ? number_format( (float) $location['longitude'], 5, '.', '' ) : '';
			$key = implode( '|', array( $this->dedupe_text( (string) ( $location['region_name'] ?? '' ) ), (string) ( $raw['effective_locality'] ?? '' ), $lat, $lon, $this->normalizer->normalize_place( (string) ( $location['display_name'] ?? '' ) ) ) );
			$groups[ $key ][] = $candidate;
		}
		$result = array();
		foreach ( $groups as $group ) {
			usort( $group, static fn( array $a, array $b ): int => (int) ( $a['location']['id'] ?? 0 ) <=> (int) ( $b['location']['id'] ?? 0 ) );
			$primary = $group[0];
			$ids = array_values( array_unique( array_map( static fn( array $candidate ): int => (int) ( $candidate['location']['id'] ?? 0 ), $group ) ) );
			if ( count( $ids ) > 1 ) {
				$primary['raw']['deduped_location_ids'] = array_slice( $ids, 0, 20 );
			}
			$result[] = $primary;
		}

		return $result;
	}

	/** @param array<string,mixed> $candidate */
	private function candidate_distance( array $candidate ): float {
		return is_numeric( $candidate['distance_km'] ?? null ) ? (float) $candidate['distance_km'] : 999999.0;
	}

	/** @param array<string,mixed> $candidate */
	private function type_priority( array $candidate ): int {
		$type = (string) ( $candidate['raw']['wdc_type'] ?? '' );
		return match ( $type ) {
			'city' => 100,
			'urban' => 90,
			'settlement' => 80,
			'village' => 70,
			'hamlet' => 60,
			'station' => 55,
			'farm' => 50,
			'area' => 40,
			default => 30,
		};
	}

	/** @param array<string,mixed> $a @param array<string,mixed> $b */
	private function same_region_and_locality( array $a, array $b ): bool {
		return (string) ( $a['location']['region_name'] ?? '' ) === (string) ( $b['location']['region_name'] ?? '' )
			&& (string) ( $a['raw']['effective_locality'] ?? '' ) === (string) ( $b['raw']['effective_locality'] ?? '' );
	}

	/** @param array<int,array<string,mixed>> $candidates @return array<string,mixed>|null */
	private function base_place_candidate( array $candidates ): ?array {
		$base = array_values( array_filter( $candidates, fn( array $candidate ): bool => ! $this->has_place_qualifier( (string) ( $candidate['raw']['locality_raw'] ?? '' ) ) ) );
		if ( 1 !== count( $base ) ) {
			return null;
		}
		$qualified = array_values( array_filter( $candidates, fn( array $candidate ): bool => $this->has_place_qualifier( (string) ( $candidate['raw']['locality_raw'] ?? '' ) ) ) );

		return array() !== $qualified ? $base[0] : null;
	}

	private function has_place_qualifier( string $value ): bool {
		$value = mb_strtolower( $value, 'UTF-8' );
		foreach ( array( 'квартал', 'торфопредприятие' ) as $needle ) {
			if ( str_contains( $value, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<int,array<string,mixed>> $candidates @return array<string,mixed>|null */
	private function place_name_candidate( array $candidates ): ?array {
		$place = array_values( array_filter( $candidates, static fn( array $candidate ): bool => 'place_name' === (string) ( $candidate['raw']['locality_source'] ?? '' ) ) );
		$city = array_values( array_filter( $candidates, static fn( array $candidate ): bool => 'city_name' === (string) ( $candidate['raw']['locality_source'] ?? '' ) ) );

		return 1 === count( $place ) && array() !== $city ? $place[0] : null;
	}

	/** @param array<int,array<string,mixed>> $candidates */
	private function all_other_candidates_outside_safe_radius( array $candidates, float $safe_radius ): bool {
		foreach ( $candidates as $candidate ) {
			if ( $this->candidate_distance( $candidate ) <= $safe_radius ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<int,array<string,mixed>> $candidates @return array<int,array<string,mixed>> */
	private function dominance_rejected_candidates( array $candidates ): array {
		$rejected = array();
		foreach ( $candidates as $candidate ) {
			$location = is_array( $candidate['location'] ?? null ) ? $candidate['location'] : array();
			$raw = is_array( $candidate['raw'] ?? null ) ? $candidate['raw'] : array();
			$rejected[] = array(
				'location_id' => (int) ( $location['id'] ?? 0 ),
				'distance' => $candidate['distance_km'] ?? null,
				'confidence' => $candidate['confidence'] ?? null,
				'type_score' => $candidate['type_score'] ?? ( $raw['type_score'] ?? null ),
				'locality_raw' => (string) ( $raw['locality_raw'] ?? '' ),
			);
		}

		return $rejected;
	}

	/** @param array<string,mixed> $geo @param array<string,mixed> $diagnostics @return array<string,mixed>|null */
	private function territory_coordinate_fallback( array $geo, array $diagnostics ): ?array {
		$locality_raw = (string) ( $geo['locality'] ?? '' );
		if ( ! $this->normalizer->is_territorial_like( $locality_raw ) ) {
			return null;
		}
		if ( ! is_numeric( $geo['centroid_lat'] ?? null ) || ! is_numeric( $geo['centroid_lon'] ?? null ) ) {
			return null;
		}
		$centroid_lat = (float) $geo['centroid_lat'];
		$centroid_lon = (float) $geo['centroid_lon'];
		$locations = $this->fetch_nearby_locations_for_territory( $centroid_lat, $centroid_lon, is_array( $diagnostics['mapped_regions'] ?? null ) ? $diagnostics['mapped_regions'] : array() );
		$nearest = null;
		foreach ( $locations as $location ) {
			if ( ! $this->has_valid_location_coordinates( $location ) ) {
				continue;
			}
			$distance = round( $this->distance_km( $centroid_lat, $centroid_lon, (float) $location['latitude'], (float) $location['longitude'] ), 3 );
			if ( $distance > 15.0 ) {
				continue;
			}
			if ( null === $nearest || $distance < (float) $nearest['distance_km'] ) {
				$nearest = array( 'location' => $location, 'distance_km' => $distance );
			}
		}
		if ( null === $nearest ) {
			return null;
		}
		$location = $nearest['location'];
		$effective = $this->normalizer->effective_location_locality( $location );
		$raw = array_merge(
			$diagnostics,
			array(
				'distance' => $nearest['distance_km'],
				'radius' => is_numeric( $geo['coverage_radius_safe_km'] ?? null ) ? (float) $geo['coverage_radius_safe_km'] : null,
				'threshold' => 15.0,
				'candidate_count' => 1,
				'territory_fallback' => true,
				'reason' => 'territory_fallback',
				'territory_fallback_reason' => 'territorial_like_coordinates',
				'locality_source' => (string) ( $effective['source'] ?? '' ),
				'locality_raw' => (string) ( $effective['raw'] ?? '' ),
				'effective_locality' => (string) ( $effective['value'] ?? '' ),
				'region_matched' => true,
				'yandex_type' => $this->normalizer->detect_locality_type( $locality_raw ),
				'wdc_type' => $this->normalizer->detect_location_type( $location ),
				'type_score' => 0,
			)
		);

		return array(
			'location' => $location,
			'distance_km' => (float) $nearest['distance_km'],
			'type_score' => 0,
			'confidence' => 60,
			'matched_by' => array( 'region', 'territory_coordinates' ),
			'raw' => $raw,
		);
	}

	/** @param array<string,mixed> $geo @param array<string,mixed> $location @param array<int,string> $matched_by @param array<string,mixed> $raw */
	private function mapping_row( array $geo, array $location, string $status, int $confidence, ?float $distance, array $matched_by, array $raw, bool $primary = true ): array {
		$geo_id = (int) ( $geo['yandex_geo_id'] ?? 0 );
		$location_id = (int) ( $location['id'] ?? 0 );
		$raw = array_merge( array( 'distance' => $distance, 'radius' => is_numeric( $geo['coverage_radius_safe_km'] ?? null ) ? (float) $geo['coverage_radius_safe_km'] : null, 'threshold' => max( 50.0, ( is_numeric( $geo['coverage_radius_safe_km'] ?? null ) ? (float) $geo['coverage_radius_safe_km'] : 0.0 ) + 10.0 ) ), $raw );

		return array(
			'yandex_geo_id' => $geo_id,
			'location_id' => $location_id,
			'status' => $status,
			'confidence' => $confidence,
			'distance_km' => $distance,
			'region_match' => in_array( 'region', $matched_by, true ) ? 1 : 0,
			'locality_match' => in_array( 'locality', $matched_by, true ) ? 1 : 0,
			'coordinate_match' => in_array( 'coordinates', $matched_by, true ) || in_array( 'territory_coordinates', $matched_by, true ) ? 1 : 0,
			'matched_by_json' => $this->json( $matched_by ),
			'raw_json' => $this->json( $raw ),
			'is_primary' => $primary ? 1 : 0,
		);
	}

	/** @return array<int,array<string,mixed>> */
	private function fetch_geo_rows( int $limit, int $offset ): array {
		if ( $this->has_test_geo_rows() ) {
			$rows = array_values( array_filter( $this->wpdb->yandex_delivery_geo_v2, static fn( array $row ): bool => ! empty( $row['active'] ) && (int) ( $row['yandex_geo_id'] ?? 0 ) > 0 ) );
			usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['yandex_geo_id'] ?? 0 ) <=> (int) ( $b['yandex_geo_id'] ?? 0 ) );

			return array_slice( $rows, $offset, $limit );
		}
		$sql = 'SELECT * FROM ' . $this->geo_table_name() . ' WHERE active = 1 AND yandex_geo_id > 0 ORDER BY yandex_geo_id ASC LIMIT %d OFFSET %d';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $limit, $offset ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}


	/** @param array<int,string> $regions @return array<int,array<string,mixed>> */
	private function fetch_locations_by_regions( array $regions ): array {
		$regions = array_values( array_unique( array_filter( array_map( 'strval', $regions ), static fn( string $region ): bool => '' !== trim( $region ) ) ) );
		if ( array() === $regions ) {
			return array();
		}
		if ( $this->has_test_locations() ) {
			$allowed = array_fill_keys( $regions, true );
			return array_values(
				array_filter(
					$this->wpdb->wdc_locations,
					static fn( array $row ): bool => ( ! isset( $row['active'] ) || ! empty( $row['active'] ) ) && isset( $allowed[ (string) ( $row['region_name'] ?? '' ) ] )
				)
			);
		}
		$placeholders = implode( ',', array_fill( 0, count( $regions ), '%s' ) );
		$sql = 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE active = 1 AND region_name IN (' . $placeholders . ')';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$regions ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}


	/** @return array<int,array<string,mixed>> */
	private function fetch_nearby_locations_for_territory( float $lat, float $lon, array $regions ): array {
		if ( array() === $regions ) {
			return array();
		}
		if ( $this->has_test_locations() ) {
			$allowed = array_fill_keys( array_map( 'strval', $regions ), true );
			return array_values(
				array_filter(
					$this->wpdb->wdc_locations,
					static fn( array $row ): bool => ( ! isset( $row['active'] ) || ! empty( $row['active'] ) ) && isset( $allowed[ (string) ( $row['region_name'] ?? '' ) ] ) && is_numeric( $row['latitude'] ?? null ) && is_numeric( $row['longitude'] ?? null )
				)
			);
		}
		$placeholders = implode( ',', array_fill( 0, count( $regions ), '%s' ) );
		$sql = 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE active = 1 AND region_name IN (' . $placeholders . ') AND latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f LIMIT 1000';
		$params = array_merge( array_values( $regions ), array( $lat - 0.2, $lat + 0.2, $lon - 0.3, $lon + 0.3 ) );
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<string,mixed> $location */
	private function has_valid_location_coordinates( array $location ): bool {
		return is_numeric( $location['latitude'] ?? null ) && is_numeric( $location['longitude'] ?? null ) && 0.0 !== (float) $location['latitude'] && 0.0 !== (float) $location['longitude'];
	}

	private function dedupe_text( string $value ): string {
		$value = str_replace( 'ё', 'е', mb_strtolower( trim( $value ), 'UTF-8' ) );
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}

	private function confidence( bool $locality, bool $region, bool $coordinates, int $type_score = 0 ): int {
		$score = 40 + ( $locality ? 30 : 0 ) + ( $region ? 20 : 0 ) + ( $coordinates ? 10 : 0 ) + $type_score;

		return max( 0, min( 120, $score ) );
	}

	private function distance_km( float $lat1, float $lon1, float $lat2, float $lon2 ): float {
		$earth_radius_km = 6371.0;
		$delta_lat = deg2rad( $lat2 - $lat1 );
		$delta_lon = deg2rad( $lon2 - $lon1 );
		$rad_lat1 = deg2rad( $lat1 );
		$rad_lat2 = deg2rad( $lat2 );
		$a = sin( $delta_lat / 2 ) ** 2 + cos( $rad_lat1 ) * cos( $rad_lat2 ) * sin( $delta_lon / 2 ) ** 2;
		$c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

		return $earth_radius_km * $c;
	}

	private function json( mixed $value ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : '';
	}

	private function has_test_geo_rows(): bool {
		return property_exists( $this->wpdb, 'yandex_delivery_geo_v2' ) && is_array( $this->wpdb->yandex_delivery_geo_v2 );
	}

	private function has_test_locations(): bool {
		return property_exists( $this->wpdb, 'wdc_locations' ) && is_array( $this->wpdb->wdc_locations );
	}

	private function geo_table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_delivery_geo_v2';
	}

	private function locations_table_name(): string {
		return $this->wpdb->prefix . 'wdc_locations';
	}
}
