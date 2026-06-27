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
		$offset = max( 0, $offset );
		$limit = max( 1, min( 500, $limit ) );
		$rows = $this->fetch_empty_region_geo_rows( $offset, $limit );
		$result = array( 'processed' => 0, 'updated' => 0, 'needs_review' => 0, 'not_found' => 0, 'skipped' => 0, 'errors' => 0, 'next_offset' => $offset + count( $rows ), 'done' => count( $rows ) < $limit, 'items' => array() );
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
				++$result['processed'];
				++$result['errors'];
				$result['items'][] = array(
					'yandex_geo_id' => (int) ( $geo['yandex_geo_id'] ?? 0 ),
					'locality' => (string) ( $geo['locality'] ?? '' ),
					'status' => 'errors',
					'region' => '',
					'distance' => null,
					'candidate_count' => 0,
					'reason' => $exception->getMessage(),
				);
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
		$audit_base = array(
			'locality_terms' => $terms,
			'matched_location_id' => 0,
			'matched_region' => '',
			'distance_km' => null,
			'candidate_count' => 0,
			'reason' => '',
			'matched_wdc_region' => '',
			'resolved_yandex_region' => '',
			'region_mapping_source' => 'region_mapping_v2',
		);
		if ( $geo_id <= 0 || '' === $base ) {
			return $this->result( $geo, 'skipped', '', null, 0, 'invalid_locality' );
		}
		if ( ! $this->has_valid_geo_coordinates( $geo ) ) {
			return $this->result( $geo, 'skipped', '', null, 0, 'invalid_coords' );
		}
		$lat = (float) $geo['centroid_lat'];
		$lon = (float) $geo['centroid_lon'];
		$radius = is_numeric( $geo['coverage_radius_safe_km'] ?? null ) ? (float) $geo['coverage_radius_safe_km'] : 0.0;
		$threshold = max( 5.0, $radius + 2.0 );
		$candidates = $this->candidate_rows( $this->fetch_wdc_candidates( $terms ), $base, $lat, $lon, $threshold );
		if ( array() === $candidates ) {
			return $this->result( $geo, 'not_found', '', null, 0, 'no_candidates' );
		}
		$this->sort_candidates( $candidates );
		$chosen = null;
		$reason = 'multiple_regions';
		$regions = array_values( array_unique( array_map( static fn( array $candidate ): string => (string) ( $candidate['location']['region_name'] ?? '' ), $candidates ) ) );
		if ( 1 === count( $regions ) ) {
			$chosen = $candidates[0];
			$reason = 'single_region';
		} elseif ( count( $candidates ) > 1 ) {
			$primary = $candidates[0];
			$second = $candidates[1];
			if ( (float) $second['distance_km'] - (float) $primary['distance_km'] >= 3.0 ) {
				$chosen = $primary;
				$reason = 'dominant_candidate';
			}
		}
		if ( null === $chosen ) {
			return $this->result( $geo, 'needs_review', '', $candidates[0]['distance_km'], count( $candidates ), $reason );
		}
		$location = $chosen['location'];
		$wdc_region = trim( (string) ( $location['region_name'] ?? '' ) );
		$yandex_regions = $this->region_mapping->find_yandex_regions_for_wdc( $wdc_region );
		$resolved_yandex_region = $yandex_regions[0] ?? '';
		$audit = array_merge(
			$audit_base,
			array(
				'matched_location_id' => (int) ( $location['id'] ?? 0 ),
				'matched_region' => $wdc_region,
				'distance_km' => $chosen['distance_km'],
				'candidate_count' => count( $candidates ),
				'reason' => $reason,
				'matched_wdc_region' => $wdc_region,
				'resolved_yandex_region' => $resolved_yandex_region,
			)
		);
		if ( '' === $resolved_yandex_region ) {
			return $this->result( $geo, 'needs_review', '', $chosen['distance_km'], count( $candidates ), 'region_mapping_missing' );
		}
		$updated = $this->geo_repository->update_region_from_location( $geo_id, $resolved_yandex_region, $audit );
		if ( ! $updated ) {
			return $this->result( $geo, 'needs_review', '', $chosen['distance_km'], count( $candidates ), 'update_failed' );
		}

		return $this->result( $geo, 'updated', $resolved_yandex_region, $chosen['distance_km'], count( $candidates ), $reason );
	}

	/** @return array<int,array<string,mixed>> */
	private function fetch_empty_region_geo_rows( int $offset, int $limit ): array {
		if ( $this->has_test_geo_rows() ) {
			$rows = array_values( array_filter( $this->wpdb->yandex_delivery_geo_v2, static fn( array $row ): bool => ! empty( $row['active'] ) && '' === trim( (string) ( $row['region'] ?? '' ) ) ) );
			usort( $rows, static fn( array $a, array $b ): int => (int) ( $b['points_count'] ?? 0 ) <=> (int) ( $a['points_count'] ?? 0 ) ?: (int) ( $a['yandex_geo_id'] ?? 0 ) <=> (int) ( $b['yandex_geo_id'] ?? 0 ) );

			return array_slice( $rows, $offset, $limit );
		}
		$sql = 'SELECT * FROM ' . $this->geo_table_name() . " WHERE active = 1 AND (region IS NULL OR region = '') ORDER BY points_count DESC, yandex_geo_id ASC LIMIT %d OFFSET %d";
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $limit, $offset ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<int,string> $terms @return array<int,array<string,mixed>> */
	private function fetch_wdc_candidates( array $terms ): array {
		$terms = array_values( array_unique( array_filter( array_map( 'strval', $terms ), static fn( string $term ): bool => mb_strlen( trim( $term ), 'UTF-8' ) >= 3 ) ) );
		if ( array() === $terms ) {
			return array();
		}
		if ( $this->has_test_locations() ) {
			$normalized_terms = array_map( fn( string $term ): string => $this->normalizer->normalize_place( $term ), $terms );
			return array_values(
				array_filter(
					$this->wpdb->wdc_locations,
					function ( array $row ) use ( $terms, $normalized_terms ): bool {
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
					}
				)
			);
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

	/** @param array<int,array<string,mixed>> $locations @return array<int,array<string,mixed>> */
	private function candidate_rows( array $locations, string $base, float $lat, float $lon, float $threshold ): array {
		$candidates = array();
		foreach ( $locations as $location ) {
			$effective = $this->normalizer->effective_location_locality( $location );
			if ( null === $effective || $effective['value'] !== $base || ! $this->has_valid_location_coordinates( $location ) ) {
				continue;
			}
			$distance = round( $this->distance_km( $lat, $lon, (float) $location['latitude'], (float) $location['longitude'] ), 3 );
			if ( $distance > $threshold ) {
				continue;
			}
			$candidates[] = array( 'location' => $location, 'distance_km' => $distance );
		}

		return $candidates;
	}

	/** @param array<int,array<string,mixed>> $candidates */
	private function sort_candidates( array &$candidates ): void {
		usort( $candidates, static fn( array $a, array $b ): int => (float) ( $a['distance_km'] ?? 999999 ) <=> (float) ( $b['distance_km'] ?? 999999 ) ?: (int) ( $a['location']['id'] ?? 0 ) <=> (int) ( $b['location']['id'] ?? 0 ) );
	}

	/** @param array<string,mixed> $geo @return array<string,mixed> */
	private function result( array $geo, string $status, string $region, mixed $distance, int $candidate_count, string $reason ): array {
		return array(
			'yandex_geo_id' => (int) ( $geo['yandex_geo_id'] ?? 0 ),
			'locality' => (string) ( $geo['locality'] ?? '' ),
			'status' => $status,
			'region' => $region,
			'distance' => is_numeric( $distance ) ? round( (float) $distance, 3 ) : null,
			'candidate_count' => $candidate_count,
			'reason' => $reason,
		);
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
