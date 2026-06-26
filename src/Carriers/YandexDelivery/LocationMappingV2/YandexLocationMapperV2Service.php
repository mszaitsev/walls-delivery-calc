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
			return array( $this->mapping_row( $geo, array(), 'no_match', 0, null, array(), array_merge( array( 'candidate_count' => 0 ), $diagnostics ) ) );
		}
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
		$region = $this->normalize_region( (string) ( $geo['region'] ?? '' ) );
		$search_terms = $this->normalizer->search_terms_for_locality( $locality_raw );
		$diagnostics = array(
			'sql_search_terms' => $search_terms,
			'candidate_count_before_filters' => 0,
			'candidate_count_after_filters' => 0,
		);
		if ( '' === $locality || array() === $search_terms ) {
			return array();
		}
		$centroid_lat = is_numeric( $geo['centroid_lat'] ?? null ) ? (float) $geo['centroid_lat'] : null;
		$centroid_lon = is_numeric( $geo['centroid_lon'] ?? null ) ? (float) $geo['centroid_lon'] : null;
		$safe_radius = is_numeric( $geo['coverage_radius_safe_km'] ?? null ) ? (float) $geo['coverage_radius_safe_km'] : 0.0;
		$threshold = max( 50.0, $safe_radius + 10.0 );
		$sql_candidates = $this->fetch_location_candidates( $search_terms );
		$diagnostics['candidate_count_before_filters'] = count( $sql_candidates );
		$candidates = array();
		foreach ( $sql_candidates as $location ) {
			$effective_locality = $this->normalizer->effective_location_locality( $location );
			if ( null === $effective_locality || $effective_locality['value'] !== $locality ) {
				continue;
			}
			$region_match = '' !== $region && $this->normalize_region( (string) ( $location['region_name'] ?? '' ) ) === $region;
			$distance = null;
			$coordinate_match = false;
			if ( null !== $centroid_lat && null !== $centroid_lon && is_numeric( $location['latitude'] ?? null ) && is_numeric( $location['longitude'] ?? null ) ) {
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
			$candidates[] = array(
				'location' => $location,
				'distance_km' => $distance,
				'confidence' => $this->confidence( true, $region_match && '' !== $region, $coordinate_match ),
				'matched_by' => $matched_by,
				'raw' => array( 'distance' => $distance, 'radius' => $safe_radius, 'threshold' => $threshold, 'candidate_count' => 0, 'region_matched' => $region_match, 'locality_source' => $effective_locality['source'], 'locality_raw' => $effective_locality['raw'], 'effective_locality' => $effective_locality['value'], 'sql_search_terms' => $search_terms, 'candidate_count_before_filters' => 0, 'candidate_count_after_filters' => 0 ),
			);
		}
		$count = count( $candidates );
		$diagnostics['candidate_count_after_filters'] = $count;
		foreach ( $candidates as &$candidate ) {
			$candidate['raw']['candidate_count'] = $count;
			$candidate['raw']['candidate_count_before_filters'] = $diagnostics['candidate_count_before_filters'];
			$candidate['raw']['candidate_count_after_filters'] = $count;
		}
		unset( $candidate );
		usort(
			$candidates,
			static fn( array $a, array $b ): int => ( (int) ( $b['confidence'] ?? 0 ) <=> (int) ( $a['confidence'] ?? 0 ) )
				?: ( (float) ( $a['distance_km'] ?? 999999 ) <=> (float) ( $b['distance_km'] ?? 999999 ) )
		);

		return $candidates;
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
			'coordinate_match' => in_array( 'coordinates', $matched_by, true ) ? 1 : 0,
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
		$where = '(' . implode( ' OR ', $where_parts ) . ') AND active = 1';
		$sql = 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE ' . $where . ' LIMIT 1000';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}


	private function normalize_region( string $value ): string {
		$value = str_replace( 'ё', 'е', mb_strtolower( trim( $value ), 'UTF-8' ) );
		$value = preg_replace( '/\b(область|обл|край|республика|респ|г|город|автономный округ|ао)\b/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}

	private function confidence( bool $locality, bool $region, bool $coordinates ): int {
		return 40 + ( $locality ? 30 : 0 ) + ( $region ? 20 : 0 ) + ( $coordinates ? 10 : 0 );
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