<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2Repository;

defined( 'ABSPATH' ) || exit;

final class YandexGeoV2RegionEnrichmentService {
	private object $wpdb;
	private YandexLocationMappingV2NameNormalizer $normalizer;
	private YandexRegionMappingV2Repository $region_mapping;

	public function __construct( private YandexDeliveryGeoV2Repository $geo_repository, ?object $wpdb = null, ?YandexLocationMappingV2NameNormalizer $normalizer = null, ?YandexRegionMappingV2Repository $region_mapping = null ) {
		$db = $wpdb;
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		$this->wpdb = $db;
		$this->normalizer = $normalizer ?? new YandexLocationMappingV2NameNormalizer();
		$this->region_mapping = $region_mapping ?? new YandexRegionMappingV2Repository( $this->wpdb );
	}

	/** @return array{processed:int,updated:int,needs_review:int,not_found:int,skipped:int,errors:int,next_offset:int,done:bool,items:array<int,array<string,mixed>>} */
	public function enrich_batch( int $offset, int $limit ): array {
		$limit = max( 1, min( 500, $limit ) );
		$rows = $this->geo_repository->find_pending_empty_region_rows_for_enrichment( $limit );
		$result = array( 'processed' => 0, 'updated' => 0, 'needs_review' => 0, 'not_found' => 0, 'skipped' => 0, 'errors' => 0, 'next_offset' => max( 0, $offset ), 'done' => count( $rows ) < $limit, 'items' => array() );
		foreach ( $rows as $geo ) {
			try {
				$item = $this->enrich_one( $geo );
				++$result['processed'];
				$status = (string) ( $item['status'] ?? 'errors' );
				if ( isset( $result[ $status ] ) ) {
					++$result[ $status ];
				}
				$result['items'][] = $item;
			} catch ( \Throwable $exception ) {
				$audit = $this->base_audit( $geo, $this->normalizer->search_terms_for_locality( (string) ( $geo['locality'] ?? '' ) ), '', 0.0, 0.0 );
				$audit['reason'] = $exception->getMessage();
				$this->geo_repository->mark_region_enrichment_attempt( (int) ( $geo['yandex_geo_id'] ?? 0 ), 'errors', $audit );
				++$result['processed'];
				++$result['errors'];
				$result['items'][] = $this->result( $geo, 'errors', '', null, 0, $exception->getMessage() );
			}
		}

		return $result;
	}

	/** @param array<string,mixed> $geo @return array<string,mixed> */
	public function enrich_one( array $geo ): array {
		$geo_id = (int) ( $geo['yandex_geo_id'] ?? 0 );
		$locality_raw = (string) ( $geo['locality'] ?? '' );
		$terms = $this->normalizer->search_terms_for_locality( $locality_raw );
		$base = $this->normalizer->normalize_place( $this->normalizer->base_name_for_locality( $locality_raw ) );
		$radius = is_numeric( $geo['coverage_radius_safe_km'] ?? null ) ? (float) $geo['coverage_radius_safe_km'] : 0.0;
		$threshold = max( 5.0, $radius + 2.0 );
		$coordinate_radius = min( 25.0, max( 10.0, $radius + 5.0 ) );
		$audit_base = $this->base_audit( $geo, $terms, $base, $threshold, $coordinate_radius );
		if ( $geo_id <= 0 || '' === $base ) {
			return $this->attempt_result( $geo, 'skipped', '', null, 0, 'invalid_locality', $audit_base );
		}
		if ( ! $this->has_valid_geo_coordinates( $geo ) ) {
			return $this->attempt_result( $geo, 'skipped', '', null, 0, 'invalid_coords', $audit_base );
		}
		$lat = (float) $geo['centroid_lat'];
		$lon = (float) $geo['centroid_lon'];

		$locality_locations = $this->fetch_wdc_candidates( $terms );
		$audit_base['diagnostics']['locality_search']['sql_rows_count'] = count( $locality_locations );
		$candidates = $this->candidate_rows( $locality_locations, $base, $lat, $lon, $threshold, $audit_base['diagnostics']['locality_search'] );
		$search_path = 'locality_search';
		if ( array() === $candidates ) {
			$coordinate_locations = $this->fetch_coordinate_candidates( $lat, $lon, $coordinate_radius );
			$audit_base['diagnostics']['coordinate_search']['sql_rows_count'] = count( $coordinate_locations );
			$candidates = $this->coordinate_candidate_rows( $coordinate_locations, $geo, $base, $lat, $lon, $threshold, $coordinate_radius, $audit_base['diagnostics']['coordinate_search'] );
			$search_path = 'coordinate_fallback';
		}
		if ( array() === $candidates ) {
			$reason = 'coordinate_fallback' === $search_path && (int) $audit_base['diagnostics']['coordinate_search']['sql_rows_count'] > 0 ? 'coordinate_fallback_low_score' : 'coordinate_fallback_no_candidates';
			$audit_base['search_path'] = $search_path;
			return $this->attempt_result( $geo, 'not_found', '', null, 0, $reason, $audit_base );
		}
		$this->sort_candidates( $candidates );
		$chosen = null;
		$reason = $search_path . '_multiple_regions';
		$regions = array_values( array_unique( array_map( static fn( array $candidate ): string => (string) ( $candidate['location']['region_name'] ?? '' ), $candidates ) ) );
		if ( 1 === count( $regions ) ) {
			$chosen = $candidates[0];
			$reason = $search_path . '_single_region';
		} elseif ( count( $candidates ) > 1 ) {
			$primary = $candidates[0];
			$second = $candidates[1];
			if ( (float) $second['distance_km'] - (float) $primary['distance_km'] >= 3.0 ) {
				$chosen = $primary;
				$reason = $search_path . '_dominant_candidate';
			}
		}
		if ( null === $chosen ) {
			$audit_base['search_path'] = $search_path;
			return $this->attempt_result( $geo, 'needs_review', '', $candidates[0]['distance_km'], count( $candidates ), $reason, array_merge( $audit_base, $this->candidate_audit( $candidates[0], count( $candidates ), $reason, '', $search_path ) ) );
		}
		$location = $chosen['location'];
		$wdc_region = trim( (string) ( $location['region_name'] ?? '' ) );
		$yandex_regions = $this->region_mapping->find_yandex_regions_for_wdc( $wdc_region );
		$resolved_yandex_region = $yandex_regions[0] ?? '';
		$audit = array_merge( $audit_base, $this->candidate_audit( $chosen, count( $candidates ), $reason, $resolved_yandex_region, $search_path ) );
		if ( '' === $resolved_yandex_region ) {
			return $this->attempt_result( $geo, 'needs_review', '', $chosen['distance_km'], count( $candidates ), 'region_mapping_missing', array_merge( $audit, array( 'resolved_yandex_region' => '' ) ) );
		}
		$updated = $this->geo_repository->update_region_from_location( $geo_id, $resolved_yandex_region, $audit );
		if ( ! $updated ) {
			return $this->attempt_result( $geo, 'needs_review', '', $chosen['distance_km'], count( $candidates ), 'update_failed', $audit );
		}

		return $this->result( $geo, 'updated', $resolved_yandex_region, $chosen['distance_km'], count( $candidates ), $reason );
	}

	/** @param array<string,mixed> $geo @param array<int,string> $terms @return array<string,mixed> */
	private function base_audit( array $geo, array $terms, string $base, float $threshold, float $coordinate_radius ): array {
		return array(
			'locality_terms' => $terms,
			'matched_location_id' => 0,
			'matched_region' => '',
			'distance_km' => null,
			'candidate_count' => 0,
			'reason' => '',
			'matched_wdc_region' => '',
			'resolved_yandex_region' => '',
			'region_mapping_source' => 'region_mapping_v2',
			'search_path' => 'locality_search',
			'matched_by' => array(),
			'diagnostics' => array(
				'base' => $base,
				'threshold_km' => round( $threshold, 3 ),
				'locality_search' => array(
					'terms' => $terms,
					'sql_rows_count' => 0,
					'after_effective_locality_count' => 0,
					'after_distance_count' => 0,
					'rejected_samples' => array(),
				),
				'coordinate_search' => array(
					'enabled' => true,
					'radius_km' => round( $coordinate_radius, 3 ),
					'sql_rows_count' => 0,
					'after_effective_locality_count' => 0,
					'after_type_or_name_count' => 0,
					'candidate_count' => 0,
					'rejected_samples' => array(),
				),
			),
		);
	}

	/** @param array<int,string> $terms @return array<int,array<string,mixed>> */
	private function fetch_wdc_candidates( array $terms ): array {
		$terms = array_values( array_unique( array_filter( array_map( 'strval', $terms ), static fn( string $term ): bool => mb_strlen( trim( $term ), 'UTF-8' ) >= 3 ) ) );
		if ( array() === $terms ) {
			return array();
		}
		if ( $this->has_test_locations() ) {
			$normalized_terms = array_map( fn( string $term ): string => $this->normalizer->normalize_place( $term ), $terms );
			return array_values( array_filter( $this->wpdb->wdc_locations, function ( array $row ) use ( $terms, $normalized_terms ): bool {
				if ( isset( $row['active'] ) && empty( $row['active'] ) ) {
					return false;
				}
				if ( ! $this->has_valid_location_coordinates( $row ) ) {
					return false;
				}
				foreach ( array( 'city_name', 'settlement_name', 'place_name' ) as $field ) {
					$value = (string) ( $row[ $field ] ?? '' );
					if ( in_array( $value, $terms, true ) || in_array( $this->normalizer->normalize_place( $value ), $normalized_terms, true ) ) {
						return true;
					}
				}
				$display = mb_strtolower( (string) ( $row['display_name'] ?? '' ), 'UTF-8' );
				foreach ( $terms as $term ) {
					if ( '' !== $term && str_contains( $display, mb_strtolower( $term, 'UTF-8' ) ) ) {
						return true;
					}
				}

				return false;
			} ) );
		}
		$where = array();
		$params = array();
		foreach ( $terms as $term ) {
			$where[] = '(city_name = %s OR settlement_name = %s OR place_name = %s OR display_name LIKE %s)';
			$params[] = $term;
			$params[] = $term;
			$params[] = $term;
			$params[] = '%' . $this->wpdb->esc_like( $term ) . '%';
		}
		$sql = 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE active = 1 AND latitude <> 0 AND longitude <> 0 AND (' . implode( ' OR ', $where ) . ') LIMIT 1000';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,array<string,mixed>> */
	private function fetch_coordinate_candidates( float $lat, float $lon, float $radius_km ): array {
		if ( $this->has_test_locations() ) {
			return array_values( array_filter( $this->wpdb->wdc_locations, fn( array $row ): bool => ( ! isset( $row['active'] ) || ! empty( $row['active'] ) ) && $this->has_valid_location_coordinates( $row ) && $this->distance_km( $lat, $lon, (float) $row['latitude'], (float) $row['longitude'] ) <= $radius_km ) );
		}
		$lat_delta = $radius_km / 111.0;
		$lon_delta = $radius_km / ( 111.0 * max( 0.2, cos( deg2rad( $lat ) ) ) );
		$sql = 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE active = 1 AND latitude <> 0 AND longitude <> 0 AND latitude BETWEEN %f AND %f AND longitude BETWEEN %f AND %f LIMIT 1000';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $lat - $lat_delta, $lat + $lat_delta, $lon - $lon_delta, $lon + $lon_delta ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<int,array<string,mixed>> $locations @param array<string,mixed> $diagnostics @return array<int,array<string,mixed>> */
	private function candidate_rows( array $locations, string $base, float $lat, float $lon, float $threshold, array &$diagnostics ): array {
		$candidates = array();
		foreach ( $locations as $location ) {
			$effective = $this->normalizer->effective_location_locality( $location );
			if ( null === $effective ) {
				$this->add_rejected_sample( $diagnostics, $location, null, null, 'locality_mismatch' );
				continue;
			}
			if ( $effective['value'] !== $base ) {
				$this->add_rejected_sample( $diagnostics, $location, $effective, null, 'locality_mismatch' );
				continue;
			}
			++$diagnostics['after_effective_locality_count'];
			if ( ! $this->has_valid_location_coordinates( $location ) ) {
				$this->add_rejected_sample( $diagnostics, $location, $effective, null, 'invalid_coords' );
				continue;
			}
			$distance = round( $this->distance_km( $lat, $lon, (float) $location['latitude'], (float) $location['longitude'] ), 3 );
			if ( $distance > $threshold ) {
				$this->add_rejected_sample( $diagnostics, $location, $effective, $distance, 'distance_too_far' );
				continue;
			}
			++$diagnostics['after_distance_count'];
			$candidates[] = array( 'location' => $location, 'distance_km' => $distance, 'score' => 100, 'matched_by' => array( 'locality', 'coordinates' ) );
		}

		return $candidates;
	}

	/** @param array<int,array<string,mixed>> $locations @param array<string,mixed> $geo @param array<string,mixed> $diagnostics @return array<int,array<string,mixed>> */
	private function coordinate_candidate_rows( array $locations, array $geo, string $base, float $lat, float $lon, float $threshold, float $coordinate_radius, array &$diagnostics ): array {
		$candidates = array();
		foreach ( $locations as $location ) {
			$effective = $this->normalizer->effective_location_locality( $location );
			if ( null === $effective || ! $this->has_valid_location_coordinates( $location ) ) {
				$this->add_rejected_sample( $diagnostics, $location, $effective, null, null === $effective ? 'locality_mismatch' : 'invalid_coords' );
				continue;
			}
			$distance = round( $this->distance_km( $lat, $lon, (float) $location['latitude'], (float) $location['longitude'] ), 3 );
			if ( $distance > $coordinate_radius ) {
				$this->add_rejected_sample( $diagnostics, $location, $effective, $distance, 'distance_too_far' );
				continue;
			}
			$match = $this->coordinate_match_score( $geo, $location, $effective, $base, $distance, $threshold, $coordinate_radius );
			if ( ! empty( $match['locality_related'] ) ) {
				++$diagnostics['after_effective_locality_count'];
			}
			if ( (int) $match['score'] >= 50 && count( $match['matched_by'] ) > 1 ) {
				++$diagnostics['after_type_or_name_count'];
				$candidates[] = array( 'location' => $location, 'distance_km' => $distance, 'score' => (int) $match['score'], 'matched_by' => $match['matched_by'], 'type_score' => (int) $match['type_score'] );
				continue;
			}
			$this->add_rejected_sample( $diagnostics, $location, $effective, $distance, (int) $match['type_score'] < 0 ? 'type_mismatch' : 'low_score' );
		}
		$diagnostics['candidate_count'] = count( $candidates );

		return $candidates;
	}

	/** @param array<string,mixed> $geo @param array<string,mixed> $location @param array{source:string,value:string,raw:string} $effective @return array{score:int,matched_by:array<int,string>,type_score:int,locality_related:bool} */
	private function coordinate_match_score( array $geo, array $location, array $effective, string $base, float $distance, float $threshold, float $coordinate_radius ): array {
		$score = 0;
		$matched_by = array( 'coordinates' );
		$locality_related = false;
		if ( $distance <= $threshold ) {
			$score += 40;
		}
		if ( $distance <= $coordinate_radius ) {
			$score += 30;
		}
		if ( $effective['value'] === $base ) {
			$score += 30;
			$matched_by[] = 'locality';
			$locality_related = true;
		} elseif ( $this->contains_place( $effective['value'], $base ) || $this->contains_place( $base, $effective['value'] ) ) {
			$score += 20;
			$matched_by[] = 'partial_locality';
			$locality_related = true;
		} elseif ( $this->display_contains_terms( (string) ( $location['display_name'] ?? '' ), $base ) ) {
			$score += 20;
			$matched_by[] = 'display_name';
			$locality_related = true;
		}
		if ( $this->address_contains_location_clue( (string) ( $geo['first_full_address'] ?? '' ), $location, $effective ) ) {
			$score += 20;
			$matched_by[] = 'address_clue';
		}
		$type_score = $this->normalizer->type_match_score( (string) ( $geo['locality'] ?? '' ), $location );
		if ( $type_score > 0 ) {
			$score += 10;
			$matched_by[] = 'type';
		} elseif ( $type_score < 0 ) {
			$score -= 20;
		}

		return array( 'score' => $score, 'matched_by' => array_values( array_unique( $matched_by ) ), 'type_score' => $type_score, 'locality_related' => $locality_related );
	}

	/** @param array<string,mixed> $diagnostics @param array<string,mixed> $location @param array{source:string,value:string,raw:string}|null $effective */
	private function add_rejected_sample( array &$diagnostics, array $location, ?array $effective, mixed $distance, string $reason ): void {
		if ( count( $diagnostics['rejected_samples'] ?? array() ) >= 20 ) {
			return;
		}
		$diagnostics['rejected_samples'][] = array(
			'id' => (int) ( $location['id'] ?? 0 ),
			'region_name' => (string) ( $location['region_name'] ?? '' ),
			'display_name' => (string) ( $location['display_name'] ?? '' ),
			'city_name' => (string) ( $location['city_name'] ?? '' ),
			'settlement_name' => (string) ( $location['settlement_name'] ?? '' ),
			'place_name' => (string) ( $location['place_name'] ?? '' ),
			'effective_locality' => (string) ( $effective['value'] ?? '' ),
			'distance_km' => is_numeric( $distance ) ? round( (float) $distance, 3 ) : null,
			'reject_reason' => $reason,
		);
	}

	/** @param array<int,array<string,mixed>> $candidates */
	private function sort_candidates( array &$candidates ): void {
		usort( $candidates, static fn( array $a, array $b ): int => (int) ( $b['score'] ?? 0 ) <=> (int) ( $a['score'] ?? 0 ) ?: (float) ( $a['distance_km'] ?? 999999 ) <=> (float) ( $b['distance_km'] ?? 999999 ) ?: (int) ( $a['location']['id'] ?? 0 ) <=> (int) ( $b['location']['id'] ?? 0 ) );
	}

	/** @param array<string,mixed> $geo @param array<string,mixed> $audit @return array<string,mixed> */
	private function attempt_result( array $geo, string $status, string $region, mixed $distance, int $candidate_count, string $reason, array $audit ): array {
		$audit = array_merge( $audit, array( 'distance_km' => is_numeric( $distance ) ? round( (float) $distance, 3 ) : ( $audit['distance_km'] ?? null ), 'candidate_count' => $candidate_count, 'reason' => $reason ) );
		$this->geo_repository->mark_region_enrichment_attempt( (int) ( $geo['yandex_geo_id'] ?? 0 ), $status, $audit );

		return $this->result( $geo, $status, $region, $distance, $candidate_count, $reason );
	}

	/** @param array{location:array<string,mixed>,distance_km:float|int|string,score?:int,matched_by?:array<int,string>,type_score?:int} $candidate @return array<string,mixed> */
	private function candidate_audit( array $candidate, int $candidate_count, string $reason, string $resolved_yandex_region, string $search_path ): array {
		$location = $candidate['location'];
		$wdc_region = trim( (string) ( $location['region_name'] ?? '' ) );

		return array(
			'matched_location_id' => (int) ( $location['id'] ?? 0 ),
			'matched_region' => $wdc_region,
			'distance_km' => is_numeric( $candidate['distance_km'] ?? null ) ? round( (float) $candidate['distance_km'], 3 ) : null,
			'candidate_count' => $candidate_count,
			'reason' => $reason,
			'matched_wdc_region' => $wdc_region,
			'resolved_yandex_region' => $resolved_yandex_region,
			'region_mapping_source' => 'region_mapping_v2',
			'search_path' => $search_path,
			'matched_by' => $candidate['matched_by'] ?? array( 'locality', 'coordinates' ),
			'candidate_score' => (int) ( $candidate['score'] ?? 100 ),
			'type_score' => (int) ( $candidate['type_score'] ?? 0 ),
		);
	}

	/** @param array<string,mixed> $geo @return array<string,mixed> */
	private function result( array $geo, string $status, string $region, mixed $distance, int $candidate_count, string $reason ): array {
		return array( 'yandex_geo_id' => (int) ( $geo['yandex_geo_id'] ?? 0 ), 'locality' => (string) ( $geo['locality'] ?? '' ), 'status' => $status, 'region' => $region, 'distance' => is_numeric( $distance ) ? round( (float) $distance, 3 ) : null, 'candidate_count' => $candidate_count, 'reason' => $reason );
	}

	private function contains_place( string $haystack, string $needle ): bool {
		$haystack = trim( $haystack );
		$needle = trim( $needle );

		return mb_strlen( $needle, 'UTF-8' ) >= 3 && str_contains( $haystack, $needle );
	}

	private function display_contains_terms( string $display, string $base ): bool {
		$display = $this->normalizer->normalize_place( $display );
		$tokens = array_values( array_filter( preg_split( '/\s+/u', $base ) ?: array(), static fn( string $token ): bool => mb_strlen( $token, 'UTF-8' ) >= 2 ) );
		if ( array() === $tokens ) {
			return false;
		}
		foreach ( $tokens as $token ) {
			if ( ! str_contains( $display, $token ) ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<string,mixed> $location @param array{source:string,value:string,raw:string} $effective */
	private function address_contains_location_clue( string $address, array $location, array $effective ): bool {
		$address = $this->normalizer->normalize_place( $address );
		if ( '' === $address ) {
			return false;
		}
		foreach ( array( $effective['value'], $this->normalizer->normalize_place( (string) ( $location['place_name'] ?? '' ) ), $this->normalizer->normalize_place( (string) ( $location['settlement_name'] ?? '' ) ) ) as $needle ) {
			if ( mb_strlen( $needle, 'UTF-8' ) >= 3 && str_contains( $address, $needle ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,mixed> $geo */
	private function has_valid_geo_coordinates( array $geo ): bool {
		return is_numeric( $geo['centroid_lat'] ?? null ) && is_numeric( $geo['centroid_lon'] ?? null ) && 0.0 !== (float) $geo['centroid_lat'] && 0.0 !== (float) $geo['centroid_lon'];
	}

	/** @param array<string,mixed> $location */
	private function has_valid_location_coordinates( array $location ): bool {
		return is_numeric( $location['latitude'] ?? null ) && is_numeric( $location['longitude'] ?? null ) && 0.0 !== (float) $location['latitude'] && 0.0 !== (float) $location['longitude'];
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

	private function has_test_locations(): bool {
		return property_exists( $this->wpdb, 'wdc_locations' ) && is_array( $this->wpdb->wdc_locations );
	}

	private function locations_table_name(): string {
		return $this->wpdb->prefix . 'wdc_locations';
	}
}
