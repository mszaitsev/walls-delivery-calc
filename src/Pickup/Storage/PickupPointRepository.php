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

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_pickup_points';
	}

	private function normalize_city( string $value ): string {
		$value = trim( $value );

		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
	}
}
