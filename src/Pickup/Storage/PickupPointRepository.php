<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Storage;

use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Pickup\PickupPoint;

defined( 'ABSPATH' ) || exit;

final class PickupPointRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function save( PickupPoint $point ): int {
		$now      = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$existing = $this->find_row_by_code( $point->carrier_key, $point->code );
		$data     = $this->point_to_row( $point, $now, is_array( $existing ) ? (string) ( $existing['created_at'] ?? $now ) : $now );

		if ( is_array( $existing ) && isset( $existing['id'] ) ) {
			$this->wpdb->update( $this->table_name(), $data, array( 'id' => (int) $existing['id'] ), $this->formats(), array( '%d' ) );
			return (int) $existing['id'];
		}

		$this->wpdb->insert( $this->table_name(), $data, $this->formats() );

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @param array<int,PickupPoint> $points
	 */
	public function save_many( array $points ): int {
		$count = 0;
		foreach ( $points as $point ) {
			if ( $point instanceof PickupPoint ) {
				$this->save( $point );
				++$count;
			}
		}

		return $count;
	}

	public function find_by_code( string $carrier, string $code ): ?PickupPoint {
		$row = $this->find_row_by_code( $carrier, $code );

		return is_array( $row ) ? $this->row_to_point( $row ) : null;
	}

	/**
	 * @return array<int,PickupPoint>
	 */
	public function search( string $carrier, string $country, string $city ): array {
		$carrier = trim( $carrier );
		$country = strtoupper( trim( $country ) );
		$city    = trim( $city );

		if ( '' === $carrier || '' === $country ) {
			return array();
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name()} WHERE active = 1 AND carrier_key = %s AND country_code = %s ORDER BY city_name ASC, address ASC LIMIT 100",
				$carrier,
				$country
			),
			ARRAY_A
		);

		$points = $this->rows_to_points( is_array( $rows ) ? $rows : array() );
		if ( '' === $city ) {
			return $points;
		}

		$query = $this->normalize_city( $city );

		return array_values(
			array_filter(
				$points,
				fn ( PickupPoint $point ): bool => str_contains( $this->normalize_city( $point->city ), $query )
					|| str_contains( $query, $this->normalize_city( $point->city ) )
			)
		);
	}

	public function count_all(): int {
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name()}" );
	}

	public function delete_all(): void {
		$this->wpdb->query( "DELETE FROM {$this->table_name()}" );
	}

	/**
	 * @param array<int,string> $carrier_keys
	 */
	public function delete_by_carrier_keys( array $carrier_keys ): int {
		$carrier_keys = array_values(
			array_filter(
				array_map( static fn ( mixed $key ): string => trim( (string) $key ), $carrier_keys ),
				static fn ( string $key ): bool => '' !== $key
			)
		);

		if ( array() === $carrier_keys ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $carrier_keys ), '%s' ) );
		$result       = $this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table_name()} WHERE carrier_key IN ({$placeholders})",
				...$carrier_keys
			)
		);

		return is_int( $result ) ? $result : 0;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{inserted:int,updated:int,skipped:int}
	 */
	public function upsert_passport_batch( string $carrier_key, array $rows ): array {
		$carrier_key = trim( $carrier_key );
		if ( '' === $carrier_key ) {
			return array( 'inserted' => 0, 'updated' => 0, 'skipped' => count( $rows ) );
		}

		$stats = array( 'inserted' => 0, 'updated' => 0, 'skipped' => 0 );
		$now   = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		foreach ( array_chunk( $rows, 100 ) as $chunk ) {
			$normalized_rows = array();
			foreach ( $chunk as $row ) {
				if ( ! is_array( $row ) ) {
					++$stats['skipped'];
					continue;
				}

				$row['carrier_key'] = $carrier_key;
				$row                = $this->normalize_passport_row( $row, $now );
				if ( '' === (string) $row['point_code'] || null === $row['latitude'] || null === $row['longitude'] ) {
					++$stats['skipped'];
					continue;
				}

				$normalized_rows[] = $row;
			}

			$existing_by_code = $this->find_rows_by_codes( $carrier_key, array_map( static fn( array $row ): string => (string) $row['point_code'], $normalized_rows ) );
			foreach ( $normalized_rows as $row ) {
				$point_code = (string) $row['point_code'];
				$existing = $existing_by_code[ $point_code ] ?? null;
				if ( is_array( $existing ) && isset( $existing['id'] ) ) {
					$row['created_at'] = (string) ( $existing['created_at'] ?? $row['created_at'] ?? $now );
					$this->wpdb->update( $this->table_name(), $row, array( 'id' => (int) $existing['id'] ), $this->passport_formats( $row ), array( '%d' ) );
					++$stats['updated'];
					continue;
				}

				$this->wpdb->insert( $this->table_name(), $row, $this->passport_formats( $row ) );
				++$stats['inserted'];
			}
		}

		return $stats;
	}

	public function mark_missing_inactive( string $carrier_key, string $run_started_at ): int {
		$carrier_key     = trim( $carrier_key );
		$run_started_at  = trim( $run_started_at );
		if ( '' === $carrier_key || '' === $run_started_at ) {
			return 0;
		}

		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table_name()} SET active = 0, updated_at = %s WHERE active = 1 AND carrier_key = %s AND (last_seen_at IS NULL OR last_seen_at = '' OR last_seen_at < %s)",
				function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
				$carrier_key,
				$run_started_at
			)
		);

		return is_int( $result ) ? $result : 0;
	}

	public function count_active( string $carrier_key = '', string $point_type = '' ): int {
		$where = array( 'active = 1' );
		$args  = array();
		if ( '' !== trim( $carrier_key ) ) {
			$where[] = 'carrier_key = %s';
			$args[]  = trim( $carrier_key );
		}
		if ( '' !== trim( $point_type ) ) {
			$where[] = 'point_type = %s';
			$args[]  = strtoupper( trim( $point_type ) );
		}

		$sql = 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE ' . implode( ' AND ', $where );
		if ( array() !== $args ) {
			$sql = $this->wpdb->prepare( $sql, ...$args );
		}

		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * @return array<string,int>
	 */
	public function count_by_type( string $carrier_key ): array {
		$carrier_key = trim( $carrier_key );
		if ( '' === $carrier_key ) {
			return array();
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT point_type, COUNT(*) AS total FROM {$this->table_name()} WHERE active = 1 AND carrier_key = %s GROUP BY point_type",
				$carrier_key
			),
			ARRAY_A
		);

		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$type = strtoupper( trim( (string) ( $row['point_type'] ?? '' ) ) );
			if ( '' !== $type ) {
				$result[ $type ] = (int) ( $row['total'] ?? 0 );
			}
		}

		return $result;
	}

	public function find_by_id( int $id ): ?PickupPoint {
		if ( $id <= 0 ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table_name()} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->row_to_point( $row ) : null;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,PickupPoint>
	 */
	public function find_by_bbox( string $carrier_key, float $min_lng, float $min_lat, float $max_lng, float $max_lat, array $filters = array() ): array {
		$carrier_key = trim( $carrier_key );
		if ( '' === $carrier_key ) {
			return array();
		}

		$where = array(
			'active = 1',
			'carrier_key = %s',
			'longitude BETWEEN %f AND %f',
			'latitude BETWEEN %f AND %f',
		);
		$args = array( $carrier_key, $min_lng, $max_lng, $min_lat, $max_lat );
		$this->append_point_filters( $where, $args, $filters );

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM ' . $this->table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY city_name ASC, address ASC LIMIT 500',
				...$args
			),
			ARRAY_A
		);

		return $this->rows_to_points( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,PickupPoint>
	 */
	public function search_points( string $carrier_key, string $query, array $filters = array() ): array {
		$carrier_key = trim( $carrier_key );
		$query       = trim( $query );
		if ( '' === $carrier_key || '' === $query ) {
			return array();
		}

		$where = array(
			'active = 1',
			'carrier_key = %s',
			'(point_code LIKE %s OR postcode LIKE %s OR city_name LIKE %s OR address LIKE %s)',
		);
		$like = '%' . $this->wpdb->esc_like( $query ) . '%';
		$args = array( $carrier_key, $like, $like, $like, $like );
		$this->append_point_filters( $where, $args, $filters );

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM ' . $this->table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY city_name ASC, address ASC LIMIT 100',
				...$args
			),
			ARRAY_A
		);

		return $this->rows_to_points( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function find_row_by_code( string $carrier, string $code ): ?array {
		$carrier = trim( $carrier );
		$code    = trim( $code );
		if ( '' === $carrier || '' === $code ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table_name()} WHERE carrier_key = %s AND point_code = %s LIMIT 1", $carrier, $code ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<int,string> $codes
	 * @return array<string,array<string,mixed>>
	 */
	private function find_rows_by_codes( string $carrier, array $codes ): array {
		$carrier = trim( $carrier );
		$codes = array_values(
			array_unique(
				array_filter(
					array_map( static fn( mixed $code ): string => trim( (string) $code ), $codes ),
					static fn( string $code ): bool => '' !== $code
				)
			)
		);
		if ( '' === $carrier || array() === $codes ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $codes ), '%s' ) );
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name()} WHERE carrier_key = %s AND point_code IN ({$placeholders})",
				$carrier,
				...$codes
			),
			ARRAY_A
		);

		$by_code = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$code = (string) ( $row['point_code'] ?? '' );
			if ( '' !== $code ) {
				$by_code[ $code ] = $row;
			}
		}

		return $by_code;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,PickupPoint>
	 */
	private function rows_to_points( array $rows ): array {
		return array_map( fn ( array $row ): PickupPoint => $this->row_to_point( $row ), $rows );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function row_to_point( array $row ): PickupPoint {
		$raw = json_decode( (string) ( $row['raw_reference'] ?? '' ), true );

		return new PickupPoint(
			(string) ( $row['carrier_key'] ?? '' ),
			(string) ( $row['point_code'] ?? '' ),
			(string) ( $row['address'] ?? '' ),
			(string) ( $row['city_name'] ?? '' ),
			(string) ( $row['region_name'] ?? '' ),
			(string) ( $row['postcode'] ?? '' ),
			null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			(string) ( $row['point_type'] ?? 'unknown' ),
			(string) ( $row['work_time'] ?? '' ),
			(string) ( $row['comment'] ?? '' ),
			(int) ( $row['extra_cost_kopecks'] ?? 0 ) > 0 ? Money::from_kopecks( (int) $row['extra_cost_kopecks'] ) : null,
			(bool) (int) ( $row['active'] ?? 1 ),
			is_array( $raw ) ? $raw : array()
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function point_to_row( PickupPoint $point, string $updated_at, string $created_at ): array {
		return array(
			'carrier_key'          => $point->carrier_key,
			'point_code'           => $point->code,
			'point_type'           => $point->type,
			'country_code'         => (string) ( $point->raw_reference['country_code'] ?? 'RU' ),
			'region_name'          => $point->region,
			'city_name'            => $point->city,
			'address'              => $point->address,
			'postcode'             => $point->postcode,
			'latitude'             => $point->latitude,
			'longitude'            => $point->longitude,
			'work_time'            => $point->work_time,
			'comment'              => $point->comment,
			'extra_cost_kopecks'   => $point->extra_cost?->get_kopecks() ?? 0,
			'active'               => $point->active ? 1 : 0,
			'raw_reference'        => wp_json_encode( $point->raw_reference ),
			'updated_at'           => $updated_at,
			'created_at'           => $created_at,
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function formats(): array {
		return array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s', '%d', '%d', '%s', '%s', '%s' );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalize_passport_row( array $row, string $now ): array {
		$allowed = array(
			'carrier_key' => '',
			'point_code' => '',
			'point_type' => 'OPS',
			'country_code' => 'RU',
			'region_name' => '',
			'city_name' => '',
			'address' => '',
			'postcode' => '',
			'latitude' => null,
			'longitude' => null,
			'work_time' => '',
			'comment' => '',
			'extra_cost_kopecks' => 0,
			'active' => 1,
			'raw_reference' => null,
			'source_hash' => null,
			'last_seen_at' => $now,
			'brand_name' => null,
			'description' => null,
			'street' => null,
			'house' => null,
			'fias_location_guid' => null,
			'fias_address_guid' => null,
			'gar_region_id' => null,
			'geohash' => null,
			'work_time_json' => null,
			'ecom_options_json' => null,
			'services_json' => null,
			'phones_json' => null,
			'images_json' => null,
			'weight_limit_grams' => null,
			'size_limit_json' => null,
			'accepts_cash' => null,
			'accepts_card' => null,
			'partial_redemption' => null,
			'return_available' => null,
			'fitting_available' => null,
			'contents_checking' => null,
			'functionality_checking' => null,
			'updated_at' => $now,
			'created_at' => $now,
		);

		$normalized = array_intersect_key( $row, $allowed ) + $allowed;
		foreach ( array( 'raw_reference', 'work_time_json', 'ecom_options_json', 'services_json', 'phones_json', 'images_json', 'size_limit_json' ) as $json_key ) {
			if ( is_array( $normalized[ $json_key ] ) ) {
				$encoded = wp_json_encode( $normalized[ $json_key ] );
				$normalized[ $json_key ] = is_string( $encoded ) ? $encoded : null;
			}
		}
		foreach ( array( 'accepts_cash', 'accepts_card', 'partial_redemption', 'return_available', 'fitting_available', 'contents_checking', 'functionality_checking' ) as $bool_key ) {
			$normalized[ $bool_key ] = null === $normalized[ $bool_key ] ? null : ( ! empty( $normalized[ $bool_key ] ) ? 1 : 0 );
		}
		$normalized['point_type'] = strtoupper( trim( (string) $normalized['point_type'] ) );
		$normalized['active'] = ! empty( $normalized['active'] ) ? 1 : 0;
		$normalized['extra_cost_kopecks'] = (int) $normalized['extra_cost_kopecks'];
		$normalized['weight_limit_grams'] = null === $normalized['weight_limit_grams'] ? null : max( 0, (int) $normalized['weight_limit_grams'] );
		$normalized['latitude'] = null === $normalized['latitude'] || '' === $normalized['latitude'] ? null : (float) $normalized['latitude'];
		$normalized['longitude'] = null === $normalized['longitude'] || '' === $normalized['longitude'] ? null : (float) $normalized['longitude'];

		return $normalized;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<int,string>
	 */
	private function passport_formats( array $row ): array {
		$formats = array(
			'carrier_key' => '%s',
			'point_code' => '%s',
			'point_type' => '%s',
			'country_code' => '%s',
			'region_name' => '%s',
			'city_name' => '%s',
			'address' => '%s',
			'postcode' => '%s',
			'latitude' => '%f',
			'longitude' => '%f',
			'work_time' => '%s',
			'comment' => '%s',
			'extra_cost_kopecks' => '%d',
			'active' => '%d',
			'raw_reference' => '%s',
			'source_hash' => '%s',
			'last_seen_at' => '%s',
			'brand_name' => '%s',
			'description' => '%s',
			'street' => '%s',
			'house' => '%s',
			'fias_location_guid' => '%s',
			'fias_address_guid' => '%s',
			'gar_region_id' => '%s',
			'geohash' => '%s',
			'work_time_json' => '%s',
			'ecom_options_json' => '%s',
			'services_json' => '%s',
			'phones_json' => '%s',
			'images_json' => '%s',
			'weight_limit_grams' => '%d',
			'size_limit_json' => '%s',
			'accepts_cash' => '%d',
			'accepts_card' => '%d',
			'partial_redemption' => '%d',
			'return_available' => '%d',
			'fitting_available' => '%d',
			'contents_checking' => '%d',
			'functionality_checking' => '%d',
			'updated_at' => '%s',
			'created_at' => '%s',
		);

		return array_values( array_intersect_key( $formats, $row ) );
	}

	/**
	 * @param array<int,string> $where
	 * @param array<int,mixed> $args
	 * @param array<string,mixed> $filters
	 */
	private function append_point_filters( array &$where, array &$args, array $filters ): void {
		if ( '' !== trim( (string) ( $filters['point_type'] ?? '' ) ) ) {
			$where[] = 'point_type = %s';
			$args[]  = strtoupper( trim( (string) $filters['point_type'] ) );
		}
		if ( '' !== trim( (string) ( $filters['city'] ?? '' ) ) ) {
			$where[] = 'city_name LIKE %s';
			$args[]  = '%' . $this->wpdb->esc_like( trim( (string) $filters['city'] ) ) . '%';
		}
		if ( array_key_exists( 'accepts_card', $filters ) ) {
			$where[] = 'accepts_card = %d';
			$args[]  = ! empty( $filters['accepts_card'] ) ? 1 : 0;
		}
		if ( array_key_exists( 'accepts_cash', $filters ) ) {
			$where[] = 'accepts_cash = %d';
			$args[]  = ! empty( $filters['accepts_cash'] ) ? 1 : 0;
		}
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_pickup_points';
	}

	private function normalize_city( string $value ): string {
		$value = trim( $value );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
