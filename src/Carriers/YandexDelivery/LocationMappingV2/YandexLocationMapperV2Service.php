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

			$strict_candidate = $this->coordinate_fallback_strict( $geo, $diagnostics );
			if ( null !== $strict_candidate ) {
				return array( $this->mapping_row( $geo, $strict_candidate['location'], 'mapped', $strict_candidate['confidence'], $strict_candidate['distance_km'], $strict_candidate['matched_by'], $strict_candidate['raw'] ) );
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

		$exact_rows = $this->fetch_exact_locations_by_regions( $mapped_regions, $search_terms );
		$diagnostics['exact_region_rows'] = count( $exact_rows );
		$candidates = array();
		if ( array() !== $exact_rows ) {
			$candidates = $this->build_candidate_rows( $exact_rows, $geo, $locality, $search_terms, $diagnostics );
		}
		$diagnostics['exact_candidates'] = count( $candidates );
		$sql_candidates = $exact_rows;
		if ( array() === $candidates ) {
			$diagnostics['candidate_search_mode'] = 'region_mapping_regional_scan_fallback';
			$sql_candidates = $this->fetch_locations_by_regions( $mapped_regions );
			$diagnostics['regional_scan_rows'] = count( $sql_candidates );
			$candidates = $this->build_candidate_rows( $sql_candidates, $geo, $locality, $search_terms, $diagnostics );
			$diagnostics['regional_scan_candidates'] = count( $candidates );
		} else {
			$diagnostics['candidate_search_mode'] = 'region_mapping_exact_first';
		}
		$diagnostics['region_before_filters'] = count( $sql_candidates );
		$diagnostics['region_after_filters'] = count( $candidates );
		$diagnostics['candidate_count_before_filters'] = $diagnostics['region_before_filters'];
		$diagnostics['candidate_count_after_filters'] = count( $candidates );
		if ( array() === $candidates ) {
			$address_terms = $this->address_locality_terms( $geo );
			$diagnostics['address_locality_terms'] = $address_terms;
			if ( array() !== $address_terms ) {
				$diagnostics['address_locality_used'] = true;
				$diagnostics['candidate_search_mode'] = 'address_locality_fallback';
				$address_rows = $this->fetch_exact_locations_by_regions( $mapped_regions, $address_terms );
				$diagnostics['exact_region_rows'] = max( (int) $diagnostics['exact_region_rows'], count( $address_rows ) );
				$candidates = $this->build_address_candidate_rows( $address_rows, $geo, $address_terms, $diagnostics );
				if ( array() === $candidates ) {
					$address_rows = array() !== $sql_candidates ? $sql_candidates : $this->fetch_locations_by_regions( $mapped_regions );
					$diagnostics['regional_scan_rows'] = max( (int) $diagnostics['regional_scan_rows'], count( $address_rows ) );
					$candidates = $this->build_address_candidate_rows( $address_rows, $geo, $address_terms, $diagnostics );
				}
				$diagnostics['regional_scan_candidates'] = max( (int) $diagnostics['regional_scan_candidates'], count( $candidates ) );
			}
		}
		if ( array() !== $candidates ) {
			$diagnostics['region_after_filters'] = count( $candidates );
			$diagnostics['candidate_count_after_filters'] = count( $candidates );
		}
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
	private function build_candidate_rows( array $locations, array $geo, string $locality, array $search_terms, array $diagnostics, ?string $locality_raw_override = null ): array {
		$locality_raw = null !== $locality_raw_override ? $locality_raw_override : (string) ( $geo['locality'] ?? '' );
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
	/** @param array<int,array<string,mixed>> $locations @param array<string,mixed> $geo @param array<int,string> $address_terms @param array<string,mixed> $diagnostics @return array<int,array<string,mixed>> */
	private function build_address_candidate_rows( array $locations, array $geo, array $address_terms, array $diagnostics ): array {
		$candidates = array();
		$seen = array();
		foreach ( $address_terms as $term ) {
			$locality = $this->normalizer->normalize_place( $term );
			if ( '' === $locality ) {
				continue;
			}
			$rows = $this->build_candidate_rows( $locations, $geo, $locality, $address_terms, $diagnostics, $term );
			foreach ( $rows as $row ) {
				$id = (int) ( $row['location']['id'] ?? 0 );
				if ( $id > 0 && isset( $seen[ $id ] ) ) {
					continue;
				}
				$row['raw']['address_locality_used'] = true;
				$row['raw']['address_locality_term'] = $term;
				$seen[ $id ] = true;
				$candidates[] = $row;
			}
		}

		return $candidates;
	}

	/** @param array<string,mixed> $geo @return array<int,string> */
	private function address_locality_terms( array $geo ): array {
		$terms = array();
		$addresses = array();
		$first = trim( (string) ( $geo['first_full_address'] ?? '' ) );
		if ( '' !== $first ) {
			$addresses[] = $first;
		}
		foreach ( $this->sample_point_addresses( $geo ) as $address ) {
			$addresses[] = $address;
		}
		foreach ( array_slice( array_values( array_unique( $addresses ) ), 0, 6 ) as $address ) {
			foreach ( $this->normalizer->extract_locality_candidates_from_full_address( $address ) as $term ) {
				$key = mb_strtolower( $term, 'UTF-8' );
				$terms[ $key ] = $term;
			}
		}

		return array_values( $terms );
	}

	/** @param array<string,mixed> $geo @return array<int,string> */
	private function sample_point_addresses( array $geo ): array {
		$json = (string) ( $geo['sample_points_json'] ?? '' );
		if ( '' === trim( $json ) ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$addresses = array();
		foreach ( $decoded as $item ) {
			if ( is_array( $item ) && '' !== trim( (string) ( $item['full_address'] ?? '' ) ) ) {
				$addresses[] = trim( (string) $item['full_address'] );
			}
			if ( count( $addresses ) >= 5 ) {
				break;
			}
		}

		return $addresses;
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
			'candidate_search_mode' => 'region_mapping_exact_first',
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
			'exact_region_rows' => 0,
			'exact_candidates' => 0,
			'address_locality_terms' => array(),
			'address_locality_used' => false,
			'regional_scan_rows' => 0,
			'regional_scan_candidates' => 0,
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
		$local_accept_distance = max( $safe_radius + 1.0, 2.0 );
		$threshold = max( 50.0, $safe_radius + 10.0 );
		$primary_type_score = (int) ( $primary['type_score'] ?? 0 );
		$second_type_score = (int) ( $second['type_score'] ?? 0 );
		if ( $this->near_exact_type_dominates( $candidates, $safe_radius ) ) {
			return $this->dominance_pick( $primary, $candidates, 'near_exact_type_dominates' );
		}
		if ( $this->same_type_nearest_dominates( $primary, $second ) ) {
			return $this->dominance_pick( $primary, $candidates, 'same_type_nearest_dominates' );
		}
		if ( $primary_type_score > $second_type_score && $primary_distance <= $local_accept_distance && $second_distance < $primary_distance ) {
			$reason = 'near_exact_type_beats_closer_wrong_type';
		} elseif ( $primary_type_score > $second_type_score && $primary_distance <= $local_accept_distance && $second_distance <= $threshold ) {
			$reason = 'type_score_priority_close';
		} elseif ( $safe_radius > 0.0 && $primary_distance <= $local_accept_distance && $second_distance > $safe_radius * 2.0 ) {
			$reason = 'far_second_same_type';
		} elseif ( $safe_radius > 0.0 && $primary_type_score > $second_type_score && $primary_distance <= $safe_radius ) {
			$reason = 'large_radius_type_priority';
		} elseif ( $second_distance - $primary_distance >= 5.0 ) {
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
			$candidate['raw']['ambiguous_reason'] = 'similar_candidates';
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
	/** @param array<int,array<string,mixed>> $candidates */
	private function near_exact_type_dominates( array $candidates, float $safe_radius ): bool {
		if ( count( $candidates ) < 2 ) {
			return false;
		}
		$primary = $candidates[0];
		$primary_score = (int) ( $primary['type_score'] ?? 0 );
		$primary_distance = $this->candidate_distance( $primary );
		if ( $primary_score < 20 || $primary_distance > max( 1.5, $safe_radius + 0.5 ) || $this->has_close_cross_region_tie( $candidates ) ) {
			return false;
		}
		foreach ( array_slice( $candidates, 1 ) as $candidate ) {
			$score = (int) ( $candidate['type_score'] ?? 0 );
			$distance = $this->candidate_distance( $candidate );
			if ( ! ( $score < $primary_score || $distance >= $primary_distance + 5.0 || ( $safe_radius > 0.0 && $distance >= $safe_radius * 2.0 ) ) ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string,mixed> $primary @param array<string,mixed> $second */
	private function same_type_nearest_dominates( array $primary, array $second ): bool {
		return (int) ( $primary['type_score'] ?? 0 ) === (int) ( $second['type_score'] ?? 0 )
			&& $this->candidate_distance( $primary ) <= 1.0
			&& $this->candidate_distance( $second ) >= $this->candidate_distance( $primary ) + 5.0;
	}

	/** @param array<int,array<string,mixed>> $candidates */
	private function has_close_cross_region_tie( array $candidates ): bool {
		$primary = $candidates[0] ?? null;
		if ( ! is_array( $primary ) ) {
			return false;
		}
		$primary_region = (string) ( $primary['location']['region_name'] ?? '' );
		$primary_score = (int) ( $primary['type_score'] ?? 0 );
		foreach ( array_slice( $candidates, 1 ) as $candidate ) {
			if ( (string) ( $candidate['location']['region_name'] ?? '' ) !== $primary_region && (int) ( $candidate['type_score'] ?? 0 ) === $primary_score && $this->candidate_distance( $candidate ) < 2.0 ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,mixed> $geo @param array<string,mixed> $diagnostics @return array<string,mixed>|null */
	private function coordinate_fallback_strict( array $geo, array $diagnostics ): ?array {
		$mapped_regions = is_array( $diagnostics['mapped_regions'] ?? null ) ? $diagnostics['mapped_regions'] : array();
		if ( array() === $mapped_regions || 'region_not_mapped' === (string) ( $diagnostics['reason'] ?? '' ) || ! is_numeric( $geo['centroid_lat'] ?? null ) || ! is_numeric( $geo['centroid_lon'] ?? null ) ) {
			return null;
		}
		if ( $this->is_unsafe_city_coordinate_fallback( $geo ) ) {
			return null;
		}
		$lat = (float) $geo['centroid_lat'];
		$lon = (float) $geo['centroid_lon'];
		$safe_radius = is_numeric( $geo['coverage_radius_safe_km'] ?? null ) ? (float) $geo['coverage_radius_safe_km'] : 0.0;
		$radius = min( 10.0, max( 3.0, $safe_radius + 2.0 ) );
		$locations = $this->fetch_nearby_locations_for_territory( $lat, $lon, $mapped_regions, $radius );
		$nearby = array();
		foreach ( $locations as $location ) {
			if ( ! $this->has_valid_location_coordinates( $location ) ) {
				continue;
			}
			$distance = round( $this->distance_km( $lat, $lon, (float) $location['latitude'], (float) $location['longitude'] ), 3 );
			if ( $distance <= $radius ) {
				$nearby[] = array( 'location' => $location, 'distance_km' => $distance );
			}
		}
		usort( $nearby, static fn( array $a, array $b ): int => ( (float) $a['distance_km'] <=> (float) $b['distance_km'] ) ?: ( (int) ( $a['location']['id'] ?? 0 ) <=> (int) ( $b['location']['id'] ?? 0 ) ) );
		$primary = $nearby[0] ?? null;
		if ( ! is_array( $primary ) || (float) $primary['distance_km'] > 3.0 ) {
			return null;
		}
		$second = $nearby[1] ?? null;
		$dominant = ! is_array( $second ) || (float) $second['distance_km'] >= (float) $primary['distance_km'] + 5.0 || $this->same_wdc_context( $nearby ) || ( $this->normalizer->detect_locality_type( (string) ( $geo['locality'] ?? '' ) ) === 'station' || $this->is_territory_like_geo( $geo ) );
		if ( ! $dominant ) {
			return null;
		}
		$effective = $this->normalizer->effective_location_locality( $primary['location'] );
		$bbox = $this->territory_bounding_box( $lat, $radius );
		$raw = array_merge( $diagnostics, array(
			'distance' => (float) $primary['distance_km'],
			'radius' => $safe_radius,
			'threshold' => $radius,
			'candidate_count' => 1,
			'coordinate_fallback_strict' => true,
			'coordinate_fallback_radius' => round( $radius, 3 ),
			'coordinate_fallback_checked' => count( $locations ),
			'coordinate_fallback_candidates' => count( $nearby ),
			'coordinate_fallback_reason' => 'strict_nearest_dominates',
			'territory_fallback' => false,
			'locality_source' => (string) ( $effective['source'] ?? '' ),
			'locality_raw' => (string) ( $effective['raw'] ?? '' ),
			'effective_locality' => (string) ( $effective['value'] ?? '' ),
			'territory_bbox' => $bbox,
		) );

		return array(
			'location' => $primary['location'],
			'distance_km' => (float) $primary['distance_km'],
			'confidence' => 70,
			'matched_by' => array( 'coordinates_strict' ),
			'raw' => $raw,
		);
	}

	/** @param array<string,mixed> $geo */
	private function is_unsafe_city_coordinate_fallback( array $geo ): bool {
		$type = $this->normalizer->detect_locality_type( (string) ( $geo['locality'] ?? '' ) );
		$locality = $this->normalizer->normalize_place( (string) ( $geo['locality'] ?? '' ) );
		$points = (int) ( $geo['points_count'] ?? 0 );
		return in_array( $locality, array( 'москва', 'санкт петербург' ), true ) || 'city' === $type || ( 'city' === $type && $points > 3 );
	}

	/** @param array<int,array{location:array<string,mixed>,distance_km:float}> $nearby */
	private function same_wdc_context( array $nearby ): bool {
		$contexts = array();
		foreach ( $nearby as $item ) {
			$location = $item['location'];
			$contexts[] = implode( '|', array( (string) ( $location['region_name'] ?? '' ), (string) ( $location['district_name'] ?? '' ), (string) ( $location['city_name'] ?? '' ) ) );
		}
		return 1 === count( array_unique( $contexts ) );
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
		if ( ! $this->is_territory_like_geo( $geo ) ) {
			return null;
		}
		if ( ! is_numeric( $geo['centroid_lat'] ?? null ) || ! is_numeric( $geo['centroid_lon'] ?? null ) ) {
			return null;
		}
		$centroid_lat = (float) $geo['centroid_lat'];
		$centroid_lon = (float) $geo['centroid_lon'];
		$mapped_regions = is_array( $diagnostics['mapped_regions'] ?? null ) ? $diagnostics['mapped_regions'] : array();
		if ( array() === $mapped_regions ) {
			return null;
		}
		$safe_radius = is_numeric( $geo['coverage_radius_safe_km'] ?? null ) ? (float) $geo['coverage_radius_safe_km'] : 0.0;
		$territory_radius = min( 25.0, max( 10.0, $safe_radius + 5.0 ) );
		$territory_bbox = $this->territory_bounding_box( $centroid_lat, $territory_radius );
		$locations = $this->fetch_nearby_locations_for_territory( $centroid_lat, $centroid_lon, $mapped_regions, $territory_radius );
		$nearest = null;
		foreach ( $locations as $location ) {
			if ( ! $this->has_valid_location_coordinates( $location ) ) {
				continue;
			}
			$distance = round( $this->distance_km( $centroid_lat, $centroid_lon, (float) $location['latitude'], (float) $location['longitude'] ), 3 );
			if ( $distance > $territory_radius ) {
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
				'threshold' => $territory_radius,
				'candidate_count' => 1,
				'territory_fallback' => true,
				'reason' => 'territory_fallback',
				'territory_fallback_reason' => 'territory_like_coordinate_match',
				'territory_radius_km' => round( $territory_radius, 3 ),
				'territory_bbox' => $territory_bbox,
				'territory_candidates_checked' => count( $locations ),
				'territory_checked_candidates' => count( $locations ),
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
			'confidence' => $this->territory_confidence( (float) $nearest['distance_km'], $geo, $location, $effective ),
			'matched_by' => $this->territory_matched_by( $geo, $location, $effective ),
			'raw' => $raw,
		);
	}


	/** @param array<string,mixed> $geo */
	private function is_territory_like_geo( array $geo ): bool {
		$value = mb_strtolower( str_replace( 'ё', 'е', (string) ( $geo['locality'] ?? '' ) . ' ' . (string) ( $geo['first_full_address'] ?? '' ) . ' ' . implode( ' ', $this->sample_point_addresses( $geo ) ) ), 'UTF-8' );
		$value = preg_replace( '/[.,()]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		foreach ( array( 'садоводческое некоммерческое товарищество', 'садоводческое товарищество', 'садовое товарищество', 'товарищество собственников', 'коттеджный поселок', 'муниципальный округ', 'городской округ', 'производственно административная зона', 'промышленная зона', 'садоводство', 'поселение', 'горбольницы', 'фабрики', 'санатория', 'совхоза', 'опытного хозяйства', 'промзона', 'территория', 'район', 'снт', 'днп', 'тлпх', 'кп', 'тер ', 'массив', 'м в' ) as $needle ) {
			if ( str_contains( $value, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,mixed> $geo @param array<string,mixed> $location @param array{source:string,value:string,raw:string}|null $effective @return array<int,string> */
	private function territory_matched_by( array $geo, array $location, ?array $effective ): array {
		$matched_by = array( 'territory_coordinates' );
		if ( $this->territory_address_clue( $geo, $location, $effective ) ) {
			$matched_by[] = 'address_clue';
		}

		return $matched_by;
	}

	/** @param array<string,mixed> $geo @param array<string,mixed> $location @param array{source:string,value:string,raw:string}|null $effective */
	private function territory_confidence( float $distance, array $geo, array $location, ?array $effective ): int {
		$confidence = 60;
		if ( $distance <= 5.0 ) {
			$confidence += 20;
		}
		if ( $this->territory_address_clue( $geo, $location, $effective ) ) {
			$confidence += 10;
		}

		return min( 90, $confidence );
	}

	/** @param array<string,mixed> $geo @param array<string,mixed> $location @param array{source:string,value:string,raw:string}|null $effective */
	private function territory_address_clue( array $geo, array $location, ?array $effective ): bool {
		$address = $this->normalizer->normalize_place( (string) ( $geo['first_full_address'] ?? '' ) );
		if ( '' === $address ) {
			return false;
		}
		foreach ( array( (string) ( $effective['value'] ?? '' ), $this->normalizer->normalize_place( (string) ( $location['place_name'] ?? '' ) ), $this->normalizer->normalize_place( (string) ( $location['settlement_name'] ?? '' ) ) ) as $needle ) {
			if ( mb_strlen( $needle, 'UTF-8' ) >= 3 && str_contains( $address, $needle ) ) {
				return true;
			}
		}

		return false;
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
			'coordinate_match' => in_array( 'coordinates', $matched_by, true ) || in_array( 'territory_coordinates', $matched_by, true ) || in_array( 'coordinates_strict', $matched_by, true ) ? 1 : 0,
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


	/** @param array<int,string> $regions @param array<int,string> $terms @return array<int,array<string,mixed>> */
	private function fetch_exact_locations_by_regions( array $regions, array $terms ): array {
		$regions = array_values( array_unique( array_filter( array_map( 'strval', $regions ), static fn( string $region ): bool => '' !== trim( $region ) ) ) );
		$terms = array_values( array_unique( array_filter( array_map( 'strval', $terms ), static fn( string $term ): bool => '' !== trim( $term ) ) ) );
		if ( array() === $regions || array() === $terms ) {
			return array();
		}
		if ( $this->has_test_locations() ) {
			$allowed_regions = array_fill_keys( $regions, true );
			$allowed_terms = array_fill_keys( $terms, true );
			$normalized_terms = array_fill_keys( array_map( fn( string $term ): string => $this->normalizer->normalize_place( $term ), $terms ), true );
			return array_values( array_filter( $this->wpdb->wdc_locations, function ( array $row ) use ( $allowed_regions, $allowed_terms, $normalized_terms ): bool {
				if ( ( isset( $row['active'] ) && empty( $row['active'] ) ) || ! isset( $allowed_regions[ (string) ( $row['region_name'] ?? '' ) ] ) ) {
					return false;
				}
				foreach ( array( 'city_name', 'settlement_name', 'place_name' ) as $field ) {
					$value = (string) ( $row[ $field ] ?? '' );
					if ( isset( $allowed_terms[ $value ] ) || isset( $normalized_terms[ $this->normalizer->normalize_place( $value ) ] ) ) {
						return true;
					}
				}

				return false;
			} ) );
		}
		$region_placeholders = implode( ',', array_fill( 0, count( $regions ), '%s' ) );
		$term_placeholders = implode( ',', array_fill( 0, count( $terms ), '%s' ) );
		$sql = 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE active = 1 AND region_name IN (' . $region_placeholders . ') AND (city_name IN (' . $term_placeholders . ') OR settlement_name IN (' . $term_placeholders . ') OR place_name IN (' . $term_placeholders . '))';
		$params = array_merge( $regions, $terms, $terms, $terms );
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );

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
	private function fetch_nearby_locations_for_territory( float $lat, float $lon, array $regions, float $radius_km ): array {
		if ( array() === $regions ) {
			return array();
		}
		if ( $this->has_test_locations() ) {
			$allowed = array_fill_keys( array_map( 'strval', $regions ), true );
			return array_values(
				array_filter(
					$this->wpdb->wdc_locations,
					fn( array $row ): bool => ( ! isset( $row['active'] ) || ! empty( $row['active'] ) ) && isset( $allowed[ (string) ( $row['region_name'] ?? '' ) ] ) && is_numeric( $row['latitude'] ?? null ) && is_numeric( $row['longitude'] ?? null ) && $this->distance_km( $lat, $lon, (float) $row['latitude'], (float) $row['longitude'] ) <= $radius_km
				)
			);
		}
		$placeholders = implode( ',', array_fill( 0, count( $regions ), '%s' ) );
		$bbox = $this->territory_bounding_box( $lat, $radius_km );
		$sql = 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE active = 1 AND region_name IN (' . $placeholders . ') AND latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f LIMIT 1000';
		$params = array_merge( array_values( $regions ), array( $lat - $bbox['lat_delta'], $lat + $bbox['lat_delta'], $lon - $bbox['lon_delta'], $lon + $bbox['lon_delta'] ) );
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @return array{lat_delta:float,lon_delta:float} */
	private function territory_bounding_box( float $lat, float $radius_km ): array {
		$lat_delta = $radius_km / 111.0;
		$lon_delta = $radius_km / ( 111.0 * max( 0.2, cos( deg2rad( $lat ) ) ) );

		return array(
			'lat_delta' => round( $lat_delta, 6 ),
			'lon_delta' => round( $lon_delta, 6 ),
		);
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
