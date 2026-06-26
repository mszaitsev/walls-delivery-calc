<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\GeoV2;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoV2Repository {
	private object $wpdb;

	public function __construct( ?object $wpdb = null ) {
		$db = $wpdb;
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}

		$this->wpdb = $db;
	}

	public function schema(): string {
		$charset = method_exists( $this->wpdb, 'get_charset_collate' ) ? $this->wpdb->get_charset_collate() : '';
		$table = $this->table_name();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			yandex_geo_id bigint(20) unsigned NOT NULL,
			region varchar(255) NOT NULL DEFAULT '',
			sub_region varchar(255) NOT NULL DEFAULT '',
			locality varchar(255) NOT NULL DEFAULT '',
			points_count int(10) unsigned NOT NULL DEFAULT 0,
			dropoff_count int(10) unsigned NOT NULL DEFAULT 0,
			types_json longtext NULL,
			operators_json longtext NULL,
			min_lat decimal(10,7) NULL,
			max_lat decimal(10,7) NULL,
			min_lon decimal(10,7) NULL,
			max_lon decimal(10,7) NULL,
			centroid_lat decimal(10,7) NULL,
			centroid_lon decimal(10,7) NULL,
			coverage_radius_km decimal(10,3) NULL,
			coverage_radius_safe_km decimal(10,3) NULL,
			first_point_id varchar(128) NOT NULL DEFAULT '',
			first_full_address text NULL,
			sample_points_json longtext NULL,
			raw_stats_json longtext NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			built_at datetime NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY yandex_geo_id (yandex_geo_id),
			KEY region_locality (region(80), locality(120)),
			KEY locality (locality(120)),
			KEY active (active),
			KEY points_count (points_count)
		) {$charset};";
	}

	public function create_schema_if_needed(): void {
		if ( ! $this->can_create_schema() ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema() );
	}

	/** @param array<int,array<string,mixed>>|array<string,mixed> $rows @return array{received:int,saved:int,skipped_invalid:int} */
	public function upsert( array $rows ): array {
		$this->create_schema_if_needed();
		$rows = array_is_list( $rows ) ? $rows : array( $rows );
		$report = array( 'received' => count( $rows ), 'saved' => 0, 'skipped_invalid' => 0 );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				++$report['skipped_invalid'];
				continue;
			}
			$normalized = $this->normalize_row( $row );
			if ( null === $normalized ) {
				++$report['skipped_invalid'];
				continue;
			}
			if ( $this->upsert_one( $normalized ) ) {
				++$report['saved'];
			} else {
				++$report['skipped_invalid'];
			}
		}

		return $report;
	}

	/** @return array<string,mixed>|null */
	public function find_by_geo_id( int $geo_id ): ?array {
		if ( $geo_id <= 0 ) {
			return null;
		}
		$rows = $this->search( array( 'yandex_geo_id' => $geo_id, 'limit' => 1, 'active' => null ) );

		return $rows[0] ?? null;
	}

	/** @param array<string,mixed> $args @return array<int,array<string,mixed>> */
	public function search( array $args = array() ): array {
		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 20 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		if ( $this->has_test_rows() ) {
			$rows = array_values( array_filter( $this->wpdb->yandex_delivery_geo_v2, fn( array $row ): bool => $this->matches_filters( $row, $args ) ) );
			usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['yandex_geo_id'] ?? 0 ) <=> (int) ( $b['yandex_geo_id'] ?? 0 ) );

			return array_slice( $rows, $offset, $limit );
		}

		$this->create_schema_if_needed();
		$where = array();
		$params = array();
		$this->append_where( $where, $params, $args );
		$where_sql = array() === $where ? '1=1' : implode( ' AND ', $where );
		$params[] = $limit;
		$params[] = $offset;
		$sql = 'SELECT * FROM ' . $this->table_name() . ' WHERE ' . $where_sql . ' ORDER BY yandex_geo_id ASC LIMIT %d OFFSET %d';

		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public function count_all(): int {
		if ( $this->has_test_rows() ) {
			return count( $this->wpdb->yandex_delivery_geo_v2 );
		}
		$this->create_schema_if_needed();

		return (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() );
	}

	public function count_active(): int {
		if ( $this->has_test_rows() ) {
			return count( array_filter( $this->wpdb->yandex_delivery_geo_v2, static fn( array $row ): bool => ! empty( $row['active'] ) ) );
		}
		$this->create_schema_if_needed();

		return (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE active = 1' );
	}

	/** @return array<string,mixed> */
	public function statistics(): array {
		if ( $this->has_test_rows() ) {
			$rows = $this->wpdb->yandex_delivery_geo_v2;
			return array(
				'total' => count( $rows ),
				'active' => count( array_filter( $rows, static fn( array $row ): bool => ! empty( $row['active'] ) ) ),
				'points_total' => array_sum( array_map( static fn( array $row ): int => (int) ( $row['points_count'] ?? 0 ), $rows ) ),
				'dropoff_total' => array_sum( array_map( static fn( array $row ): int => (int) ( $row['dropoff_count'] ?? 0 ), $rows ) ),
				'top_regions' => $this->top_counts( $rows, 'region' ),
				'top_localities' => $this->top_counts( $rows, 'locality' ),
				'no_region' => count( array_filter( $rows, static fn( array $row ): bool => '' === trim( (string) ( $row['region'] ?? '' ) ) ) ),
				'no_dropoff' => count( array_filter( $rows, static fn( array $row ): bool => 0 === (int) ( $row['dropoff_count'] ?? 0 ) ) ),
				'max_coverage_radius_km' => $this->max_numeric( $rows, 'coverage_radius_km' ),
				'avg_coverage_radius_km' => $this->avg_numeric( $rows, 'coverage_radius_km' ),
				'max_coverage_radius_safe_km' => $this->max_numeric( $rows, 'coverage_radius_safe_km' ),
				'avg_coverage_radius_safe_km' => $this->avg_numeric( $rows, 'coverage_radius_safe_km' ),
			);
		}
		$this->create_schema_if_needed();

		return array(
			'total' => $this->count_all(),
			'active' => $this->count_active(),
			'points_total' => (int) $this->wpdb->get_var( 'SELECT COALESCE(SUM(points_count), 0) FROM ' . $this->table_name() ),
			'dropoff_total' => (int) $this->wpdb->get_var( 'SELECT COALESCE(SUM(dropoff_count), 0) FROM ' . $this->table_name() ),
			'top_regions' => $this->fetch_group_counts( 'region' ),
			'top_localities' => $this->fetch_group_counts( 'locality' ),
			'no_region' => (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() . " WHERE region = ''" ),
			'no_dropoff' => (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE dropoff_count = 0' ),
			'max_coverage_radius_km' => $this->nullable_float_var( 'SELECT MAX(coverage_radius_km) FROM ' . $this->table_name() ),
			'avg_coverage_radius_km' => $this->nullable_float_var( 'SELECT AVG(coverage_radius_km) FROM ' . $this->table_name() ),
			'max_coverage_radius_safe_km' => $this->nullable_float_var( 'SELECT MAX(coverage_radius_safe_km) FROM ' . $this->table_name() ),
			'avg_coverage_radius_safe_km' => $this->nullable_float_var( 'SELECT AVG(coverage_radius_safe_km) FROM ' . $this->table_name() ),
		);
	}

	public function truncate(): void {
		if ( $this->has_test_rows() ) {
			$this->wpdb->yandex_delivery_geo_v2 = array();
			return;
		}
		$this->create_schema_if_needed();
		$this->wpdb->query( 'TRUNCATE TABLE ' . $this->table_name() );
	}

	/** @return array<int,string> */
	private function columns(): array {
		return array( 'yandex_geo_id', 'region', 'sub_region', 'locality', 'points_count', 'dropoff_count', 'types_json', 'operators_json', 'min_lat', 'max_lat', 'min_lon', 'max_lon', 'centroid_lat', 'centroid_lon', 'coverage_radius_km', 'coverage_radius_safe_km', 'first_point_id', 'first_full_address', 'sample_points_json', 'raw_stats_json', 'active', 'built_at', 'created_at', 'updated_at' );
	}

	/** @param array<string,mixed> $row @return array<string,mixed>|null */
	private function normalize_row( array $row ): ?array {
		$geo_id = (int) ( $row['yandex_geo_id'] ?? 0 );
		if ( $geo_id <= 0 ) {
			return null;
		}
		$now = $this->now();
		$normalized = array();
		foreach ( $this->columns() as $column ) {
			$normalized[ $column ] = $row[ $column ] ?? null;
		}
		$normalized['yandex_geo_id'] = $geo_id;
		foreach ( array( 'region', 'sub_region', 'locality' ) as $column ) {
			$normalized[ $column ] = $this->string( $row[ $column ] ?? '', 255 );
		}
		$normalized['points_count'] = max( 0, (int) ( $row['points_count'] ?? 0 ) );
		$normalized['dropoff_count'] = max( 0, (int) ( $row['dropoff_count'] ?? 0 ) );
		foreach ( array( 'types_json', 'operators_json', 'first_full_address', 'sample_points_json', 'raw_stats_json' ) as $column ) {
			$normalized[ $column ] = $this->nullable_string( $row[ $column ] ?? null, 0 );
		}
		foreach ( array( 'min_lat', 'max_lat', 'min_lon', 'max_lon', 'centroid_lat', 'centroid_lon' ) as $column ) {
			$normalized[ $column ] = is_numeric( $row[ $column ] ?? null ) ? round( (float) $row[ $column ], 7 ) : null;
		}
		foreach ( array( 'coverage_radius_km', 'coverage_radius_safe_km' ) as $column ) {
			$normalized[ $column ] = is_numeric( $row[ $column ] ?? null ) ? round( (float) $row[ $column ], 3 ) : null;
		}
		$normalized['first_point_id'] = $this->string( $row['first_point_id'] ?? '', 128 );
		$normalized['active'] = empty( $row['active'] ) ? 0 : 1;
		$normalized['built_at'] = $this->nullable_string( $row['built_at'] ?? $now, 32 );
		$normalized['created_at'] = $this->nullable_string( $row['created_at'] ?? $now, 32 ) ?? $now;
		$normalized['updated_at'] = $this->nullable_string( $row['updated_at'] ?? $now, 32 ) ?? $now;

		return $normalized;
	}

	/** @param array<string,mixed> $row */
	private function upsert_one( array $row ): bool {
		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->yandex_delivery_geo_v2 as $index => $existing ) {
				if ( (int) ( $existing['yandex_geo_id'] ?? 0 ) === (int) $row['yandex_geo_id'] ) {
					$row['id'] = $existing['id'] ?? $index + 1;
					$row['created_at'] = $existing['created_at'] ?? $row['created_at'];
					$this->wpdb->yandex_delivery_geo_v2[ $index ] = $row;
					return true;
				}
			}
			$row['id'] = count( $this->wpdb->yandex_delivery_geo_v2 ) + 1;
			$this->wpdb->yandex_delivery_geo_v2[] = $row;
			return true;
		}

		$columns = $this->columns();
		$values = array_map( fn( string $column ): string => $this->sql_literal( $row[ $column ] ?? null, $this->column_type( $column ) ), $columns );
		$updates = array();
		foreach ( array_diff( $columns, array( 'yandex_geo_id', 'created_at' ) ) as $column ) {
			$updates[] = $column . '=VALUES(' . $column . ')';
		}
		$sql = sprintf( 'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s', $this->table_name(), implode( ',', $columns ), implode( ',', $values ), implode( ', ', $updates ) );

		return false !== $this->wpdb->query( $sql );
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_delivery_geo_v2';
	}

	private function has_test_rows(): bool {
		return property_exists( $this->wpdb, 'yandex_delivery_geo_v2' ) && is_array( $this->wpdb->yandex_delivery_geo_v2 );
	}

	/** @param array<int,string> $where @param array<int,mixed> $params @param array<string,mixed> $args */
	private function append_where( array &$where, array &$params, array $args ): void {
		if ( ! array_key_exists( 'active', $args ) || null !== $args['active'] ) {
			$where[] = 'active = %d';
			$params[] = empty( $args['active'] ?? 1 ) ? 0 : 1;
		}
		if ( isset( $args['yandex_geo_id'] ) && (int) $args['yandex_geo_id'] > 0 ) {
			$where[] = 'yandex_geo_id = %d';
			$params[] = (int) $args['yandex_geo_id'];
		}
		foreach ( array( 'region', 'locality' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== trim( (string) $args[ $key ] ) ) {
				$where[] = $key . ' LIKE %s';
				$params[] = '%' . $this->wpdb->esc_like( trim( (string) $args[ $key ] ) ) . '%';
			}
		}
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $args */
	private function matches_filters( array $row, array $args ): bool {
		if ( ( ! array_key_exists( 'active', $args ) || null !== $args['active'] ) && ( ! empty( $row['active'] ) ? 1 : 0 ) !== ( empty( $args['active'] ?? 1 ) ? 0 : 1 ) ) {
			return false;
		}
		if ( isset( $args['yandex_geo_id'] ) && (int) $args['yandex_geo_id'] > 0 && (int) ( $row['yandex_geo_id'] ?? 0 ) !== (int) $args['yandex_geo_id'] ) {
			return false;
		}
		foreach ( array( 'region', 'locality' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== trim( (string) $args[ $key ] ) && false === stripos( (string) ( $row[ $key ] ?? '' ), trim( (string) $args[ $key ] ) ) ) {
				return false;
			}
		}

		return true;
	}

	/** @param array<int,array<string,mixed>> $rows @return array<string,int> */
	private function top_counts( array $rows, string $key ): array {
		$counts = array();
		foreach ( $rows as $row ) {
			$name = trim( (string) ( $row[ $key ] ?? '' ) );
			if ( '' !== $name ) {
				$counts[ $name ] = ( $counts[ $name ] ?? 0 ) + 1;
			}
		}
		arsort( $counts );

		return array_slice( $counts, 0, 10, true );
	}

	/** @return array<string,int> */
	private function fetch_group_counts( string $column ): array {
		$rows = $this->wpdb->get_results( 'SELECT ' . $column . ', COUNT(*) AS total FROM ' . $this->table_name() . " WHERE {$column} <> '' GROUP BY {$column} ORDER BY total DESC LIMIT 10", ARRAY_A );
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row[ $column ] ] = (int) $row['total'];
		}

		return $counts;
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function max_numeric( array $rows, string $column ): ?float {
		$values = array_values( array_map( 'floatval', array_filter( array_column( $rows, $column ), 'is_numeric' ) ) );

		return array() === $values ? null : round( max( $values ), 3 );
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function avg_numeric( array $rows, string $column ): ?float {
		$values = array_values( array_map( 'floatval', array_filter( array_column( $rows, $column ), 'is_numeric' ) ) );

		return array() === $values ? null : round( array_sum( $values ) / count( $values ), 3 );
	}

	private function nullable_float_var( string $sql ): ?float {
		$value = $this->wpdb->get_var( $sql );

		return is_numeric( $value ) ? round( (float) $value, 3 ) : null;
	}

	private function column_type( string $column ): string {
		if ( in_array( $column, array( 'yandex_geo_id', 'points_count', 'dropoff_count', 'active' ), true ) ) {
			return 'int';
		}
		if ( in_array( $column, array( 'min_lat', 'max_lat', 'min_lon', 'max_lon', 'centroid_lat', 'centroid_lon', 'coverage_radius_km', 'coverage_radius_safe_km' ), true ) ) {
			return 'float';
		}

		return 'string';
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

	private function string( mixed $value, int $max_length ): string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';

		return $max_length > 0 ? substr( $value, 0, $max_length ) : $value;
	}

	private function nullable_string( mixed $value, int $max_length ): ?string {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		return $max_length > 0 ? substr( $value, 0, $max_length ) : $value;
	}

	private function can_create_schema(): bool {
		return defined( 'ABSPATH' ) && is_string( ABSPATH ) && '' !== ABSPATH && method_exists( $this->wpdb, 'get_charset_collate' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
