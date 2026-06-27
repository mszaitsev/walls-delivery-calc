<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

defined( 'ABSPATH' ) || exit;

final class YandexLocationMapperV2Service {
	private object $wpdb;
	private YandexLocationMappingV2NameNormalizer $normalizer;

	public function __construct( private YandexLocationMappingV2Repository $repository, ?object $wpdb = null, ?YandexLocationMappingV2NameNormalizer $normalizer = null ) {
		$db = $wpdb;
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		$this->wpdb = $db;
		$this->normalizer = $normalizer ?? new YandexLocationMappingV2NameNormalizer();
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
		$candidates = $this->apply_dominance_rule( $candidates, $geo );
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
		$diagnostics = $this->default_diagnostics( $search_terms );
		if ( '' === $locality || array() === $search_terms ) {
			return array();
		}

		$base_name = $this->normalizer->base_name_for_locality( $locality_raw );
		$exact_sql_candidates = '' !== $base_name ? $this->fetch_exact_location_candidates( $base_name ) : array();
		$diagnostics['exact_before_filters'] = count( $exact_sql_candidates );
		$exact_candidates = $this->build_candidate_rows( $exact_sql_candidates, $geo, $locality, $search_terms, $diagnostics );
		$diagnostics['exact_after_filters'] = count( $exact_candidates );

		if ( array() !== $exact_candidates ) {
			$diagnostics['candidate_search_mode'] = 'exact_first';
			$candidates = $exact_candidates;
		} else {
			$sql_candidates = $this->fetch_location_candidates( $search_terms );
			$diagnostics['candidate_search_mode'] = 'broad_fallback';
			$diagnostics['broad_before_filters'] = count( $sql_candidates );
			$candidates = $this->build_candidate_rows( $sql_candidates, $geo, $locality, $search_terms, $diagnostics );
			$diagnostics['broad_after_filters'] = count( $candidates );
		}

		$diagnostics['candidate_count_before_filters'] = 'exact_first' === $diagnostics['candidate_search_mode'] ? $diagnostics['exact_before_filters'] : $diagnostics['broad_before_filters'];
		$diagnostics['candidate_count_after_filters'] = count( $candidates );
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
		$region = $this->normalize_region( (string) ( $geo['region'] ?? '' ) );
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
			$region_match = '' !== $region && $this->normalize_region( (string) ( $location['region_name'] ?? '' ) ) === $region;
			$distance = null;
			$coordinate_match = false;
			if ( null !== $centroid_lat && null !== $centroid_lon && $this->has_valid_location_coordinates( $location ) ) {
				$distance = round( $this->distance_km( $centroid_lat, $centroid_lon, (float) $location['latitude'], (float) $location['longitude'] ), 3 );
				$coordinate_match = $distance <= $threshold;
			}
			if ( ! $coordinate_match ) {
				continue;
			}
			$matched_by = array( 'locality' );
			if ( $region_match && '' !== $region ) {
				$matched_by[] = 'region';
			}
			$matched_by[] = 'coordinates';
			$type_score = $this->normalizer->type_match_score( $locality_raw, $location );
			$yandex_type = $this->normalizer->detect_locality_type( $locality_raw );
			$wdc_type = $this->normalizer->detect_location_type( $location );
			$candidates[] = array(
				'location' => $location,
				'distance_km' => $distance,
				'type_score' => $type_score,
				'confidence' => $this->confidence( true, $region_match && '' !== $region, $coordinate_match, $type_score ),
				'matched_by' => $matched_by,
				'raw' => array_merge(
					$diagnostics,
					array(
						'distance' => $distance,
						'radius' => $safe_radius,
						'threshold' => $threshold,
						'candidate_count' => 0,
						'region_matched' => $region_match,
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
				$this->normalize_region( (string) ( $location['region_name'] ?? '' ) ),
				$this->normalize_region( (string) ( $location['district_name'] ?? '' ) ),
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

	/** @param array<int,string> $search_terms @return array<string,mixed> */
	private function default_diagnostics( array $search_terms ): array {
		return array(
			'sql_search_terms' => $search_terms,
			'candidate_search_mode' => '',
			'exact_before_filters' => 0,
			'exact_after_filters' => 0,
			'broad_before_filters' => 0,
			'broad_after_filters' => 0,
			'candidate_count_before_filters' => 0,
			'candidate_count_after_filters' => 0,
			'dedupe_before' => 0,
			'dedupe_after' => 0,
			'territory_fallback' => false,
		);
	}

	/** @param array<int,array<string,mixed>> $candidates @param array<string,mixed> $geo @return array<int,array<string,mixed>> */
	private function apply_dominance_rule( array $candidates, array $geo ): array {
		if ( count( $candidates ) <= 1 ) {
			return $candidates;
		}
		$primary = $candidates[0];
		$second = $candidates[1];
		$primary_distance = is_numeric( $primary['distance_km'] ?? null ) ? (float) $primary['distance_km'] : 999999.0;
		$second_distance = is_numeric( $second['distance_km'] ?? null ) ? (float) $second['distance_km'] : 999999.0;
		$safe_radius = is_numeric( $geo['coverage_radius_safe_km'] ?? null ) ? (float) $geo['coverage_radius_safe_km'] : 0.0;
		$local_accept_distance = max( 3.0, $safe_radius + 2.0 );
		$primary_type_score = (int) ( $primary['type_score'] ?? 0 );
		$second_type_score = (int) ( $second['type_score'] ?? 0 );
		$primary_confidence = (int) ( $primary['confidence'] ?? 0 );
		$second_confidence = (int) ( $second['confidence'] ?? 0 );
		if ( $primary_distance > $local_accept_distance || $primary_type_score < 0 ) {
			return $candidates;
		}
		$reason = '';
		if ( $second_distance - $primary_distance >= 3.0 ) {
			$reason = 'distance_gap';
		} elseif ( $primary_type_score > $second_type_score ) {
			$reason = 'type_score';
		} elseif ( $primary_confidence - $second_confidence >= 20 ) {
			$reason = 'confidence_gap';
		}
		if ( '' === $reason ) {
			return $candidates;
		}
		$primary['raw']['dominance_auto_pick'] = true;
		$primary['raw']['dominance_reason'] = $reason;
		$primary['raw']['rejected_candidates'] = $this->dominance_rejected_candidates( array_slice( $candidates, 1, 10 ) );

		return array( $primary );
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
		$locations = $this->fetch_nearby_locations_for_territory( $centroid_lat, $centroid_lon );
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
				'territory_fallback_reason' => 'territorial_like_coordinates',
				'locality_source' => (string) ( $effective['source'] ?? '' ),
				'locality_raw' => (string) ( $effective['raw'] ?? '' ),
				'effective_locality' => (string) ( $effective['value'] ?? '' ),
				'region_matched' => false,
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
			'matched_by' => array( 'territory_coordinates' ),
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

	/** @return array<int,array<string,mixed>> */
	private function fetch_exact_location_candidates( string $base_name ): array {
		if ( $this->has_test_locations() ) {
			$base = $this->normalizer->normalize_place( $base_name );
			return array_values(
				array_filter(
					$this->wpdb->wdc_locations,
					function ( array $row ) use ( $base ): bool {
						if ( isset( $row['active'] ) && empty( $row['active'] ) ) {
							return false;
						}
						foreach ( array( 'city_name', 'settlement_name', 'place_name' ) as $field ) {
							if ( $this->normalizer->normalize_place( (string) ( $row[ $field ] ?? '' ) ) === $base ) {
								return true;
							}
						}
						$display = $this->normalizer->normalize_place( (string) ( $row['display_name'] ?? '' ) );

						return '' !== $base && str_contains( $display, $base );
					}
				)
			);
		}
		$like = '%' . $this->wpdb->esc_like( trim( $base_name ) ) . '%';
		$sql = 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE active = 1 AND (city_name = %s OR settlement_name = %s OR place_name = %s OR display_name LIKE %s) LIMIT 1000';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $base_name, $base_name, $base_name, $like ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<int,string> $search_terms @return array<int,array<string,mixed>> */
	private function fetch_location_candidates( array $search_terms ): array {
		if ( $this->has_test_locations() ) {
			$terms = array_map( fn( string $term ): string => mb_strtolower( $term, 'UTF-8' ), $search_terms );

			return array_values(
				array_filter(
					$this->wpdb->wdc_locations,
					static function ( array $row ) use ( $terms ): bool {
						if ( isset( $row['active'] ) && empty( $row['active'] ) ) {
							return false;
						}
						$haystack = mb_strtolower( implode( ' ', array( (string) ( $row['city_name'] ?? '' ), (string) ( $row['settlement_name'] ?? '' ), (string) ( $row['place_name'] ?? '' ), (string) ( $row['display_name'] ?? '' ) ) ), 'UTF-8' );
						foreach ( $terms as $term ) {
							if ( '' !== $term && str_contains( $haystack, $term ) ) {
								return true;
							}
						}

						return false;
					}
				)
			);
		}
		$where_parts = array();
		$params = array();
		foreach ( $search_terms as $term ) {
			$like = '%' . $this->wpdb->esc_like( trim( $term ) ) . '%';
			$where_parts[] = '(city_name LIKE %s OR settlement_name LIKE %s OR place_name LIKE %s OR display_name LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( array() === $where_parts ) {
			return array();
		}
		$where = '(' . implode( ' OR ', $where_parts ) . ') AND active = 1';
		$sql = 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE ' . $where . ' LIMIT 1000';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,array<string,mixed>> */
	private function fetch_nearby_locations_for_territory( float $lat, float $lon ): array {
		if ( $this->has_test_locations() ) {
			return array_values(
				array_filter(
					$this->wpdb->wdc_locations,
					static fn( array $row ): bool => ( ! isset( $row['active'] ) || ! empty( $row['active'] ) ) && is_numeric( $row['latitude'] ?? null ) && is_numeric( $row['longitude'] ?? null )
				)
			);
		}
		$sql = 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE active = 1 AND latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f LIMIT 1000';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $lat - 0.2, $lat + 0.2, $lon - 0.3, $lon + 0.3 ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<string,mixed> $location */
	private function has_valid_location_coordinates( array $location ): bool {
		return is_numeric( $location['latitude'] ?? null ) && is_numeric( $location['longitude'] ?? null ) && 0.0 !== (float) $location['latitude'] && 0.0 !== (float) $location['longitude'];
	}

	private function normalize_region( string $value ): string {
		$value = str_replace( 'ё', 'е', mb_strtolower( trim( $value ), 'UTF-8' ) );
		$value = preg_replace( '/\b(область|обл|край|республика|респ|г|город|автономный округ|ао)\b/u', ' ', $value ) ?? $value;
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
