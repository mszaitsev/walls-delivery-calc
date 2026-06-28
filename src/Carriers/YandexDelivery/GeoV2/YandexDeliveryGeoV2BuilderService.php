<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\GeoV2;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoV2BuilderService {
	private object $wpdb;

	public function __construct( private YandexDeliveryGeoV2Repository $repository, ?object $wpdb = null ) {
		$db = $wpdb;
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		$this->wpdb = $db;
	}

	/** @return array{processed_geo_ids:int,saved:int,next_offset:int,done:bool} */
	public function build_all( int $limit = 500, int $offset = 0 ): array {
		$limit = max( 1, min( 1000, $limit ) );
		$offset = max( 0, $offset );
		$geo_ids = $this->fetch_geo_ids( $limit, $offset );
		$rows = array();
		foreach ( $geo_ids as $geo_id ) {
			$aggregate = $this->build_geo_id( $geo_id );
			if ( null !== $aggregate ) {
				$rows[] = $aggregate;
			}
		}
		$report = array() !== $rows ? $this->repository->upsert( $rows ) : array( 'saved' => 0 );

		return array(
			'processed_geo_ids' => count( $geo_ids ),
			'saved' => (int) ( $report['saved'] ?? 0 ),
			'next_offset' => $offset + count( $geo_ids ),
			'done' => count( $geo_ids ) < $limit,
		);
	}

	/** @return array<int,int> */
	private function fetch_geo_ids( int $limit, int $offset ): array {
		if ( $this->has_test_pickups() ) {
			$ids = array();
			foreach ( $this->wpdb->yandex_delivery_pickup_points_v2 as $row ) {
				if ( empty( $row['active'] ) ) {
					continue;
				}
				$geo_id = (int) ( $row['yandex_geo_id'] ?? 0 );
				if ( $geo_id > 0 ) {
					$ids[ $geo_id ] = $geo_id;
				}
			}
			sort( $ids, SORT_NUMERIC );

			return array_slice( array_values( $ids ), $offset, $limit );
		}

		$sql = 'SELECT DISTINCT yandex_geo_id FROM ' . $this->pickup_table_name() . ' WHERE active = 1 AND yandex_geo_id > 0 ORDER BY yandex_geo_id ASC LIMIT %d OFFSET %d';
		$rows = $this->wpdb->get_col( $this->wpdb->prepare( $sql, $limit, $offset ) );

		return array_map( 'intval', is_array( $rows ) ? $rows : array() );
	}

	/** @return array<string,mixed>|null */
	private function build_geo_id( int $geo_id ): ?array {
		$points = $this->fetch_points( $geo_id );
		if ( array() === $points ) {
			return null;
		}
		$types = array();
		$operators = array();
		$regions = array();
		$sub_regions = array();
		$localities = array();
		$coords = array();
		$dropoff = 0;
		$first_point_id = '';
		$first_full_address = '';
		foreach ( $points as $point ) {
			if ( ! empty( $point['available_for_dropoff'] ) ) {
				++$dropoff;
			}
			$this->count_value( $types, $point['type'] ?? '' );
			$this->count_value( $operators, $point['operator_id'] ?? '' );
			$this->count_value( $regions, $point['region'] ?? '' );
			$this->count_value( $sub_regions, $point['sub_region'] ?? '' );
			$this->count_value( $localities, $point['locality'] ?? '' );
			if ( '' === $first_point_id ) {
				$first_point_id = trim( (string) ( $point['platform_station_id'] ?? $point['id'] ?? '' ) );
			}
			if ( '' === $first_full_address && '' !== trim( (string) ( $point['full_address'] ?? '' ) ) ) {
				$first_full_address = trim( (string) $point['full_address'] );
			}
			$lat = $point['latitude'] ?? null;
			$lon = $point['longitude'] ?? null;
			if ( is_numeric( $lat ) && is_numeric( $lon ) && 0.0 !== (float) $lat && 0.0 !== (float) $lon ) {
				$coords[] = array( (float) $lat, (float) $lon );
			}
		}
		$coordinate_stats = $this->coordinate_stats( $coords );
		$sample_addresses = $this->sample_addresses( $points );
		$raw_stats = array(
			'valid_coordinate_points' => count( $coords ),
			'invalid_coordinate_points' => count( $points ) - count( $coords ),
			'sample_points_count' => count( $sample_addresses ),
			'coverage_radius_km' => $coordinate_stats['coverage_radius_km'],
			'coverage_radius_safe_km' => $coordinate_stats['coverage_radius_safe_km'],
		);

		return array_merge(
			array(
				'yandex_geo_id' => $geo_id,
				'region' => $this->most_common( $regions ),
				'sub_region' => $this->most_common( $sub_regions ),
				'locality' => $this->most_common( $localities ),
				'points_count' => count( $points ),
				'dropoff_count' => $dropoff,
				'types_json' => $this->json( $types ),
				'operators_json' => $this->json( $operators ),
				'first_point_id' => $first_point_id,
				'first_full_address' => $first_full_address,
				'sample_points_json' => $this->json( array( 'addresses' => $sample_addresses ) ),
				'raw_stats_json' => $this->json( $raw_stats ),
				'active' => 1,
				'built_at' => $this->now(),
			),
			$coordinate_stats
		);
	}

	/** @param array<int,array<string,mixed>> $points @return array<int,string> */
	private function sample_addresses( array $points ): array {
		$addresses = array();
		foreach ( $this->sample_points( $points ) as $point ) {
			$address = trim( (string) ( $point['full_address'] ?? '' ) );
			if ( '' !== $address ) {
				$addresses[ $address ] = $address;
			}
			if ( count( $addresses ) >= 5 ) {
				break;
			}
		}

		return array_values( $addresses );
	}
	/** @return array<int,array<string,mixed>> */
	private function fetch_points( int $geo_id ): array {
		if ( $this->has_test_pickups() ) {
			return array_values( array_filter( $this->wpdb->yandex_delivery_pickup_points_v2, static fn( array $row ): bool => ! empty( $row['active'] ) && (int) ( $row['yandex_geo_id'] ?? 0 ) === $geo_id ) );
		}
		$sql = 'SELECT * FROM ' . $this->pickup_table_name() . ' WHERE active = 1 AND yandex_geo_id = %d ORDER BY id ASC';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $geo_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<int,array<string,mixed>> $points @return array<int,array<string,mixed>> */
	private function sample_points( array $points ): array {
		$count = count( $points );
		if ( $count <= 20 ) {
			$indexes = range( 0, max( 0, $count - 1 ) );
		} else {
			$indexes = array( 0 );
			for ( $index = 29; $index < $count - 1; $index += 30 ) {
				$indexes[] = $index;
			}
			$indexes[] = $count - 1;
			$indexes = array_unique( $indexes );
		}
		$sample = array();
		foreach ( $indexes as $index ) {
			if ( ! isset( $points[ $index ] ) ) {
				continue;
			}
			$point = $points[ $index ];
			$sample[] = array(
				'platform_station_id' => (string) ( $point['platform_station_id'] ?? $point['id'] ?? '' ),
				'type' => (string) ( $point['type'] ?? '' ),
				'operator_id' => (string) ( $point['operator_id'] ?? '' ),
				'full_address' => (string) ( $point['full_address'] ?? '' ),
				'latitude' => is_numeric( $point['latitude'] ?? null ) ? (float) $point['latitude'] : null,
				'longitude' => is_numeric( $point['longitude'] ?? null ) ? (float) $point['longitude'] : null,
			);
		}

		return $sample;
	}

	/** @param array<int,array{0:float,1:float}> $coords @return array<string,float|null> */
	private function coordinate_stats( array $coords ): array {
		if ( array() === $coords ) {
			return array( 'min_lat' => null, 'max_lat' => null, 'min_lon' => null, 'max_lon' => null, 'centroid_lat' => null, 'centroid_lon' => null, 'coverage_radius_km' => null, 'coverage_radius_safe_km' => null );
		}
		$lats = array_column( $coords, 0 );
		$lons = array_column( $coords, 1 );
		$centroid_lat = array_sum( $lats ) / count( $lats );
		$centroid_lon = array_sum( $lons ) / count( $lons );
		$coverage_radius = 0.0;
		foreach ( $coords as $coord ) {
			$coverage_radius = max( $coverage_radius, $this->distance_km( $centroid_lat, $centroid_lon, $coord[0], $coord[1] ) );
		}
		$coverage_radius = round( $coverage_radius, 3 );

		return array(
			'min_lat' => round( min( $lats ), 7 ),
			'max_lat' => round( max( $lats ), 7 ),
			'min_lon' => round( min( $lons ), 7 ),
			'max_lon' => round( max( $lons ), 7 ),
			'centroid_lat' => round( $centroid_lat, 7 ),
			'centroid_lon' => round( $centroid_lon, 7 ),
			'coverage_radius_km' => $coverage_radius,
			'coverage_radius_safe_km' => round( max( $coverage_radius * 1.10, $coverage_radius + 1.0 ), 3 ),
		);
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

	/** @param array<string,int> $counts */
	private function count_value( array &$counts, mixed $value ): void {
		$value = trim( (string) $value );
		if ( '' !== $value ) {
			$counts[ $value ] = ( $counts[ $value ] ?? 0 ) + 1;
		}
	}

	/** @param array<string,int> $counts */
	private function most_common( array $counts ): string {
		if ( array() === $counts ) {
			return '';
		}
		arsort( $counts );

		return (string) array_key_first( $counts );
	}

	private function pickup_table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_delivery_pickup_points_v2';
	}

	private function has_test_pickups(): bool {
		return property_exists( $this->wpdb, 'yandex_delivery_pickup_points_v2' ) && is_array( $this->wpdb->yandex_delivery_pickup_points_v2 );
	}

	private function json( mixed $value ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : '';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
