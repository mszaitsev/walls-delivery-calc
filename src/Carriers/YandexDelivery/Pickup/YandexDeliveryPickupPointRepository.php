<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pickup;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPickupPointRepository {
	private object $wpdb;

	public function __construct( ?object $wpdb = null ) {
		$db = $wpdb;
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}

		$this->wpdb = $db;
	}

	public function create_schema_if_needed(): void {
		if ( ! $this->can_create_schema() ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $this->wpdb->get_charset_collate();
		$table = $this->table_name();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				platform_station_id varchar(80) NOT NULL,
				operator_id varchar(80) NULL,
				operator_name varchar(255) NULL,
				name varchar(255) NULL,
				type varchar(64) NOT NULL,
				address text NULL,
				geo_id varchar(64) NULL,
				country_code varchar(8) NULL,
				region_name varchar(255) NULL,
				city_name varchar(255) NULL,
				latitude decimal(10,7) NULL,
				longitude decimal(10,7) NULL,
				schedule text NULL,
				payment_methods text NULL,
				available_for_dropoff tinyint(1) NOT NULL DEFAULT 0,
				available_for_c2c_dropoff tinyint(1) NOT NULL DEFAULT 0,
				is_yandex_branded tinyint(1) NOT NULL DEFAULT 0,
				raw_json longtext NULL,
				is_active tinyint(1) NOT NULL DEFAULT 0,
				imported_at datetime NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY platform_station_id (platform_station_id),
				KEY type (type),
				KEY geo_id (geo_id),
				KEY city_name (city_name),
				KEY available_for_dropoff (available_for_dropoff),
				KEY is_active (is_active)
			) {$charset_collate};"
		);
	}

	public function mark_all_inactive(): int {
		if ( $this->has_test_rows() ) {
			$count = 0;
			foreach ( $this->wpdb->yandex_delivery_pickup_points as $index => $row ) {
				if ( ! empty( $row['is_active'] ) ) {
					$this->wpdb->yandex_delivery_pickup_points[ $index ]['is_active'] = 0;
					++$count;
				}
			}
			return $count;
		}

		$this->create_schema_if_needed();
		$result = $this->wpdb->query( 'UPDATE ' . $this->table_name() . ' SET is_active = 0 WHERE is_active = 1' );

		return is_numeric( $result ) ? (int) $result : 0;
	}

	/**
	 * @param array<int,array<string,mixed>> $points
	 * @return array{received:int,saved:int,skipped_invalid:int,imported_at:string}
	 */
	public function save_batch( array $points, ?string $imported_at = null ): array {
		$this->create_schema_if_needed();
		$imported_at = $imported_at ?: $this->now();
		$report = array( 'received' => count( $points ), 'saved' => 0, 'skipped_invalid' => 0, 'imported_at' => $imported_at );
		foreach ( $points as $point ) {
			$point['imported_at'] = $imported_at;
			$point['is_active'] = 0;
			$normalized = $this->normalize_point( $point );
			if ( null === $normalized ) {
				++$report['skipped_invalid'];
				continue;
			}
			if ( $this->upsert_one( $normalized ) ) {
				++$report['saved'];
			}
		}

		return $report;
	}

	public function activate_imported_points( ?string $imported_at = null ): int {
		if ( $this->has_test_rows() ) {
			$count = 0;
			foreach ( $this->wpdb->yandex_delivery_pickup_points as $index => $row ) {
				if ( null !== $imported_at && (string) ( $row['imported_at'] ?? '' ) !== $imported_at ) {
					continue;
				}
				if ( empty( $row['is_active'] ) ) {
					$this->wpdb->yandex_delivery_pickup_points[ $index ]['is_active'] = 1;
					++$count;
				}
			}
			return $count;
		}

		$this->create_schema_if_needed();
		if ( null !== $imported_at && '' !== trim( $imported_at ) ) {
			$result = $this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name() . ' SET is_active = 1 WHERE imported_at = %s', $imported_at ) );
			return is_numeric( $result ) ? (int) $result : 0;
		}
		$result = $this->wpdb->query( 'UPDATE ' . $this->table_name() . ' SET is_active = 1 WHERE is_active = 0 AND imported_at IS NOT NULL' );

		return is_numeric( $result ) ? (int) $result : 0;
	}

	public function find_by_platform_station_id( string $platform_station_id ): ?array {
		$rows = $this->search( array( 'platform_station_id' => $platform_station_id, 'limit' => 1 ) );

		return $rows[0] ?? null;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function search( array $filters = array() ): array {
		$limit = max( 1, min( 500, (int) ( $filters['limit'] ?? 20 ) ) );
		if ( $this->has_test_rows() ) {
			$rows = array_values( array_filter( $this->test_rows(), fn( array $row ): bool => $this->matches_filters( $row, $filters ) ) );
			usort(
				$rows,
				static fn( array $a, array $b ): int => strcmp( (string) ( $a['city_name'] ?? '' ) . (string) ( $a['name'] ?? '' ), (string) ( $b['city_name'] ?? '' ) . (string) ( $b['name'] ?? '' ) )
			);
			return array_slice( $rows, 0, $limit );
		}

		$this->create_schema_if_needed();
		$where = array( 'is_active = 1' );
		$args = array();
		foreach ( array( 'platform_station_id', 'type', 'geo_id', 'country_code' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== trim( (string) $filters[ $key ] ) ) {
				$where[] = "{$key} = %s";
				$args[] = trim( (string) $filters[ $key ] );
			}
		}
		if ( isset( $filters['available_for_dropoff'] ) && '' !== (string) $filters['available_for_dropoff'] ) {
			$where[] = 'available_for_dropoff = %d';
			$args[] = empty( $filters['available_for_dropoff'] ) ? 0 : 1;
		}
		if ( isset( $filters['city_name'] ) && '' !== trim( (string) $filters['city_name'] ) ) {
			$where[] = 'city_name LIKE %s';
			$args[] = '%' . $this->wpdb->esc_like( trim( (string) $filters['city_name'] ) ) . '%';
		}
		$args[] = $limit;
		$sql = 'SELECT * FROM ' . $this->table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY city_name ASC, name ASC, platform_station_id ASC LIMIT %d';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public function count_active(): int {
		if ( $this->has_test_rows() ) {
			return count( array_filter( $this->test_rows(), static fn( array $row ): bool => ! empty( $row['is_active'] ) ) );
		}
		$this->create_schema_if_needed();

		return (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE is_active = 1' );
	}

	/** @return array<string,int> */
	public function count_by_type(): array {
		if ( $this->has_test_rows() ) {
			$counts = array();
			foreach ( $this->test_rows() as $row ) {
				if ( empty( $row['is_active'] ) ) {
					continue;
				}
				$type = (string) ( $row['type'] ?? '' );
				$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
			}
			return $counts;
		}
		$this->create_schema_if_needed();
		$rows = $this->wpdb->get_results( 'SELECT type, COUNT(*) AS total FROM ' . $this->table_name() . ' WHERE is_active = 1 GROUP BY type', ARRAY_A );
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['type'] ] = (int) $row['total'];
		}

		return $counts;
	}

	public function count_dropoff_available(): int {
		if ( $this->has_test_rows() ) {
			return count( array_filter( $this->test_rows(), static fn( array $row ): bool => ! empty( $row['is_active'] ) && ! empty( $row['available_for_dropoff'] ) ) );
		}
		$this->create_schema_if_needed();

		return (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE is_active = 1 AND available_for_dropoff = 1' );
	}

	/** @param array<string,mixed> $point */
	private function upsert_one( array $point ): bool {
		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->yandex_delivery_pickup_points as $index => $row ) {
				if ( (string) ( $row['platform_station_id'] ?? '' ) === $point['platform_station_id'] ) {
					$this->wpdb->yandex_delivery_pickup_points[ $index ] = array_merge( $row, $point );
					return true;
				}
			}
			$point['id'] = count( $this->wpdb->yandex_delivery_pickup_points ) + 1;
			$this->wpdb->yandex_delivery_pickup_points[] = $point;
			return true;
		}

		$columns = array( 'platform_station_id', 'operator_id', 'operator_name', 'name', 'type', 'address', 'geo_id', 'country_code', 'region_name', 'city_name', 'latitude', 'longitude', 'schedule', 'payment_methods', 'available_for_dropoff', 'available_for_c2c_dropoff', 'is_yandex_branded', 'raw_json', 'is_active', 'imported_at', 'updated_at' );
		$values = array();
		foreach ( $columns as $column ) {
			$type = in_array( $column, array( 'available_for_dropoff', 'available_for_c2c_dropoff', 'is_yandex_branded', 'is_active' ), true ) ? 'int' : ( in_array( $column, array( 'latitude', 'longitude' ), true ) ? 'float' : 'string' );
			$values[] = $this->sql_literal( $point[ $column ] ?? null, $type );
		}
		$updates = array();
		foreach ( array_slice( $columns, 1 ) as $column ) {
			$updates[] = $column . '=VALUES(' . $column . ')';
		}
		$sql = sprintf(
			'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
			$this->table_name(),
			implode( ',', $columns ),
			implode( ',', $values ),
			implode( ', ', $updates )
		);

		return false !== $this->wpdb->query( $sql );
	}

	/** @param array<string,mixed> $point @return array<string,mixed>|null */
	private function normalize_point( array $point ): ?array {
		$platform_station_id = trim( (string) ( $point['platform_station_id'] ?? '' ) );
		$type = trim( (string) ( $point['type'] ?? '' ) );
		if ( '' === $platform_station_id || '' === $type ) {
			return null;
		}
		$now = $this->now();

		return array(
			'platform_station_id' => substr( $platform_station_id, 0, 80 ),
			'operator_id' => $this->nullable_string( $point['operator_id'] ?? null, 80 ),
			'operator_name' => $this->nullable_string( $point['operator_name'] ?? null, 255 ),
			'name' => $this->nullable_string( $point['name'] ?? null, 255 ),
			'type' => substr( $type, 0, 64 ),
			'address' => $this->nullable_string( $point['address'] ?? null, 0 ),
			'geo_id' => $this->nullable_string( $point['geo_id'] ?? null, 64 ),
			'country_code' => $this->nullable_string( $point['country_code'] ?? null, 8 ),
			'region_name' => $this->nullable_string( $point['region_name'] ?? null, 255 ),
			'city_name' => $this->nullable_string( $point['city_name'] ?? null, 255 ),
			'latitude' => is_numeric( $point['latitude'] ?? null ) ? round( (float) $point['latitude'], 7 ) : null,
			'longitude' => is_numeric( $point['longitude'] ?? null ) ? round( (float) $point['longitude'], 7 ) : null,
			'schedule' => $this->nullable_string( $point['schedule'] ?? null, 0 ),
			'payment_methods' => $this->nullable_string( $point['payment_methods'] ?? null, 0 ),
			'available_for_dropoff' => empty( $point['available_for_dropoff'] ) ? 0 : 1,
			'available_for_c2c_dropoff' => empty( $point['available_for_c2c_dropoff'] ) ? 0 : 1,
			'is_yandex_branded' => empty( $point['is_yandex_branded'] ) ? 0 : 1,
			'raw_json' => $this->nullable_string( $point['raw_json'] ?? null, 0 ),
			'is_active' => empty( $point['is_active'] ) ? 0 : 1,
			'imported_at' => $this->nullable_string( $point['imported_at'] ?? $now, 32 ),
			'updated_at' => $this->nullable_string( $point['updated_at'] ?? $now, 32 ),
		);
	}

	private function nullable_string( mixed $value, int $max_length ): ?string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		return $max_length > 0 ? substr( $value, 0, $max_length ) : $value;
	}

	private function sql_literal( mixed $value, string $type ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( 'int' === $type ) {
			return (string) (int) $value;
		}
		if ( 'float' === $type ) {
			return is_numeric( $value ) ? (string) (float) $value : 'NULL';
		}

		return $this->wpdb->prepare( '%s', (string) $value );
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_delivery_pickup_points';
	}

	private function has_test_rows(): bool {
		return property_exists( $this->wpdb, 'yandex_delivery_pickup_points' ) && is_array( $this->wpdb->yandex_delivery_pickup_points );
	}

	private function can_create_schema(): bool {
		return defined( 'ABSPATH' )
			&& is_string( ABSPATH )
			&& '' !== ABSPATH
			&& method_exists( $this->wpdb, 'get_charset_collate' )
			&& file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' );
	}

	/** @return array<int,array<string,mixed>> */
	private function test_rows(): array {
		return $this->has_test_rows() ? $this->wpdb->yandex_delivery_pickup_points : array();
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $filters */
	private function matches_filters( array $row, array $filters ): bool {
		if ( empty( $row['is_active'] ) ) {
			return false;
		}
		foreach ( array( 'platform_station_id', 'type', 'geo_id', 'country_code' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== trim( (string) $filters[ $key ] ) && (string) ( $row[ $key ] ?? '' ) !== trim( (string) $filters[ $key ] ) ) {
				return false;
			}
		}
		if ( isset( $filters['available_for_dropoff'] ) && '' !== (string) $filters['available_for_dropoff'] && ( ! empty( $row['available_for_dropoff'] ) ? 1 : 0 ) !== ( empty( $filters['available_for_dropoff'] ) ? 0 : 1 ) ) {
			return false;
		}
		if ( isset( $filters['city_name'] ) && '' !== trim( (string) $filters['city_name'] ) && false === stripos( (string) ( $row['city_name'] ?? '' ), trim( (string) $filters['city_name'] ) ) ) {
			return false;
		}

		return true;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
