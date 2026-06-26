<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

defined( 'ABSPATH' ) || exit;

final class YandexLocationMapperV2Service {
	private object $wpdb;

	public function __construct( private YandexLocationMappingV2Repository $repository, ?object $wpdb = null ) {
		$db = $wpdb;
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		$this->wpdb = $db;
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
		$candidates = $this->find_candidates( $geo );
		if ( array() === $candidates ) {
			return array( $this->mapping_row( $geo, array(), 'no_match', 0, null, array(), array( 'candidate_count' => 0 ) ) );
		}
		$status = 1 === count( $candidates ) ? 'mapped' : 'needs_review';
		$rows = array();
		foreach ( $candidates as $index => $candidate ) {
			$rows[] = $this->mapping_row( $geo, $candidate['location'], $status, $candidate['confidence'], $candidate['distance_km'], $candidate['matched_by'], $candidate['raw'], 0 === $index );
		}

		return $rows;
	}

	/** @param array<string,mixed> $geo @return array<int,array<string,mixed>> */
	private function find_candidates( array $geo ): array {
		$locality = $this->normalize_place( (string) ( $geo['locality'] ?? '' ) );
		$region = $this->normalize_region( (string) ( $geo['region'] ?? '' ) );
		if ( '' === $locality ) {
			return array();
		}
		$centroid_lat = is_numeric( $geo['centroid_lat'] ?? null ) ? (float) $geo['centroid_lat'] : null;
		$centroid_lon = is_numeric( $geo['centroid_lon'] ?? null ) ? (float) $geo['centroid_lon'] : null;
		$safe_radius = is_numeric( $geo['coverage_radius_safe_km'] ?? null ) ? (float) $geo['coverage_radius_safe_km'] : 0.0;
		$threshold = max( 50.0, $safe_radius + 10.0 );
		$candidates = array();
		foreach ( $this->fetch_location_candidates( (string) ( $geo['locality'] ?? '' ), (string) ( $geo['region'] ?? '' ) ) as $location ) {
			$location_locality = $this->normalize_location_locality( $location );
			if ( '' === $location_locality || $location_locality !== $locality ) {
				continue;
			}
			$region_match = '' === $region || $this->normalize_region( (string) ( $location['region_name'] ?? '' ) ) === $region;
			if ( '' !== $region && ! $region_match ) {
				continue;
			}
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
				'raw' => array( 'distance' => $distance, 'radius' => $safe_radius, 'threshold' => $threshold, 'candidate_count' => 0 ),
			);
		}
		$count = count( $candidates );
		foreach ( $candidates as &$candidate ) {
			$candidate['raw']['candidate_count'] = $count;
		}
		unset( $candidate );
		usort( $candidates, static fn( array $a, array $b ): int => (float) ( $a['distance_km'] ?? 999999 ) <=> (float) ( $b['distance_km'] ?? 999999 ) );

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

	/** @return array<int,array<string,mixed>> */
	private function fetch_location_candidates( string $locality, string $region ): array {
		if ( $this->has_test_locations() ) {
			return array_values( array_filter( $this->wpdb->wdc_locations, static fn( array $row ): bool => ! isset( $row['active'] ) || ! empty( $row['active'] ) ) );
		}
		$like_locality = '%' . $this->wpdb->esc_like( trim( $locality ) ) . '%';
		$where = '(city_name LIKE %s OR settlement_name LIKE %s OR display_name LIKE %s) AND active = 1';
		$params = array( $like_locality, $like_locality, $like_locality );
		if ( '' !== trim( $region ) ) {
			$where .= ' AND region_name LIKE %s';
			$params[] = '%' . $this->wpdb->esc_like( trim( $region ) ) . '%';
		}
		$sql = 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE ' . $where . ' LIMIT 500';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<string,mixed> $location */
	private function normalize_location_locality( array $location ): string {
		foreach ( array( 'city_name', 'settlement_name', 'display_name' ) as $key ) {
			$normalized = $this->normalize_place( (string) ( $location[ $key ] ?? '' ) );
			if ( '' !== $normalized ) {
				return $normalized;
			}
		}

		return '';
	}

	private function normalize_place( string $value ): string {
		$value = mb_strtolower( trim( $value ), 'UTF-8' );
		$value = preg_replace( '/[«»"\'`.,()]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\b(г|город|с|село|п|пос|поселок|посёлок|д|деревня|рп|пгт|район)\b/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}

	private function normalize_region( string $value ): string {
		$value = mb_strtolower( trim( $value ), 'UTF-8' );
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
