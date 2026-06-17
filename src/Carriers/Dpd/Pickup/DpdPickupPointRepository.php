<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Pickup;

defined( 'ABSPATH' ) || exit;

final class DpdPickupPointRepository {
	private object $wpdb;

	public function __construct( ?object $wpdb = null ) {
		if ( null === $wpdb ) {
			global $wpdb;
		}
		$this->wpdb = $wpdb;
	}

	public function create_schema_if_needed(): void {
		if ( $this->has_test_rows() ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $this->wpdb->get_charset_collate();
		$table = $this->table_name();

		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				terminal_code varchar(64) NOT NULL,
				type varchar(32) NOT NULL,
				country_code varchar(8) NOT NULL DEFAULT 'RU',
				region_code varchar(32) NULL,
				region_name varchar(255) NULL,
				city_id bigint(20) NULL,
				city_code varchar(64) NULL,
				city_name varchar(255) NULL,
				address text NULL,
				name varchar(255) NULL,
				latitude decimal(10,7) NULL,
				longitude decimal(10,7) NULL,
				schedule text NULL,
				raw_json longtext NULL,
				is_active tinyint(1) NOT NULL DEFAULT 1,
				source varchar(64) NOT NULL,
				imported_at datetime NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY terminal_type (terminal_code,type),
				KEY city_id (city_id),
				KEY city_code (city_code),
				KEY city_name (city_name),
				KEY country_code (country_code),
				KEY coordinates (latitude,longitude),
				KEY source (source),
				KEY is_active (is_active)
			) {$charset_collate};"
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $points
	 * @return array{received:int,saved:int,skipped_invalid:int}
	 */
	public function upsert_many( array $points ): array {
		$report = array( 'received' => count( $points ), 'saved' => 0, 'skipped_invalid' => 0 );
		foreach ( $points as $point ) {
			$point = $this->normalize_point( $point );
			if ( null === $point ) {
				++$report['skipped_invalid'];
				continue;
			}
			if ( $this->upsert_one( $point ) ) {
				++$report['saved'];
			}
		}

		return $report;
	}

	/**
	 * @param array<int,array<string,mixed>> $points
	 * @return array{received:int,saved:int,skipped_invalid:int,marked_inactive:int}
	 */
	public function replace_all_for_source( string $source, array $points ): array {
		$this->create_schema_if_needed();
		$source = $this->sanitize_source( $source );
		$marked = $this->mark_source_inactive( $source );
		$prepared = array();
		foreach ( $points as $point ) {
			$point['source'] = $source;
			$point['is_active'] = 1;
			$prepared[] = $point;
		}
		$report = $this->upsert_many( $prepared );

		return array(
			'received' => $report['received'],
			'saved' => $report['saved'],
			'skipped_invalid' => $report['skipped_invalid'],
			'marked_inactive' => $marked,
		);
	}

	public function find_by_terminal_code( string $terminal_code ): ?array {
		$rows = $this->search( array( 'terminal_code' => $terminal_code, 'limit' => 1 ) );

		return $rows[0] ?? null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function find_by_city_id( int $city_id ): array {
		return $this->search( array( 'city_id' => $city_id, 'limit' => 200 ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function find_by_city_name( string $city_name ): array {
		return $this->search( array( 'city_name' => $city_name, 'limit' => 200 ) );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function search( array $filters ): array {
		$limit = max( 1, min( 500, (int) ( $filters['limit'] ?? 20 ) ) );
		if ( $this->has_test_rows() ) {
			$rows = array_values( array_filter( $this->test_rows(), fn( array $row ): bool => $this->matches_filters( $row, $filters ) ) );
			return array_map( array( $this, 'normalize_row' ), array_slice( $rows, 0, $limit ) );
		}

		$where = array( 'is_active = 1' );
		$args = array();
		foreach ( array( 'terminal_code', 'type', 'city_id', 'city_code', 'country_code', 'source' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
				$where[] = "{$key} = " . ( 'city_id' === $key ? '%d' : '%s' );
				$args[] = 'city_id' === $key ? (int) $filters[ $key ] : (string) $filters[ $key ];
			}
		}
		if ( isset( $filters['city_name'] ) && '' !== trim( (string) $filters['city_name'] ) ) {
			$where[] = 'city_name LIKE %s';
			$args[] = '%' . $this->wpdb->esc_like( trim( (string) $filters['city_name'] ) ) . '%';
		}
		$args[] = $limit;
		$sql = 'SELECT * FROM ' . $this->table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY city_name ASC, name ASC, terminal_code ASC LIMIT %d';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ), ARRAY_A );

		return is_array( $rows ) ? array_map( array( $this, 'normalize_row' ), $rows ) : array();
	}

	public function count_all(): int {
		if ( $this->has_test_rows() ) {
			return count( array_filter( $this->test_rows(), static fn( array $row ): bool => ! empty( $row['is_active'] ) ) );
		}

		return (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE is_active = 1' );
	}

	/**
	 * @return array<string,int>
	 */
	public function count_by_source(): array {
		if ( $this->has_test_rows() ) {
			$counts = array();
			foreach ( $this->test_rows() as $row ) {
				if ( empty( $row['is_active'] ) ) {
					continue;
				}
				$source = (string) ( $row['source'] ?? '' );
				$counts[ $source ] = ( $counts[ $source ] ?? 0 ) + 1;
			}
			return $counts;
		}
		$rows = $this->wpdb->get_results( 'SELECT source, COUNT(*) AS total FROM ' . $this->table_name() . ' WHERE is_active = 1 GROUP BY source', ARRAY_A );
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['source'] ] = (int) $row['total'];
		}

		return $counts;
	}

	public function cleanup_inactive(): int {
		if ( $this->has_test_rows() ) {
			$before = count( $this->wpdb->dpd_pickup_points );
			$this->wpdb->dpd_pickup_points = array_values( array_filter( $this->wpdb->dpd_pickup_points, static fn( array $row ): bool => ! empty( $row['is_active'] ) ) );
			return $before - count( $this->wpdb->dpd_pickup_points );
		}

		$result = $this->wpdb->query( 'DELETE FROM ' . $this->table_name() . ' WHERE is_active = 0' );

		return is_numeric( $result ) ? (int) $result : 0;
	}

	/**
	 * @param array<string,mixed> $point
	 */
	private function upsert_one( array $point ): bool {
		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->dpd_pickup_points as $index => $row ) {
				if ( (string) ( $row['terminal_code'] ?? '' ) === $point['terminal_code'] && (string) ( $row['type'] ?? '' ) === $point['type'] ) {
					$this->wpdb->dpd_pickup_points[ $index ] = array_merge( $row, $point );
					return true;
				}
			}
			$point['id'] = count( $this->wpdb->dpd_pickup_points ) + 1;
			$this->wpdb->dpd_pickup_points[] = $point;
			return true;
		}

		$columns = array( 'terminal_code', 'type', 'country_code', 'region_code', 'region_name', 'city_id', 'city_code', 'city_name', 'address', 'name', 'latitude', 'longitude', 'schedule', 'raw_json', 'is_active', 'source', 'imported_at', 'updated_at' );
		$values = array();
		foreach ( $columns as $column ) {
			$values[] = $this->sql_literal( $point[ $column ] ?? null, in_array( $column, array( 'city_id', 'is_active' ), true ) ? 'int' : ( in_array( $column, array( 'latitude', 'longitude' ), true ) ? 'float' : 'string' ) );
		}
		$updates = array();
		foreach ( array_slice( $columns, 2 ) as $column ) {
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

	private function mark_source_inactive( string $source ): int {
		if ( $this->has_test_rows() ) {
			$count = 0;
			foreach ( $this->wpdb->dpd_pickup_points as $index => $row ) {
				if ( (string) ( $row['source'] ?? '' ) === $source && ! empty( $row['is_active'] ) ) {
					$this->wpdb->dpd_pickup_points[ $index ]['is_active'] = 0;
					++$count;
				}
			}
			return $count;
		}
		$result = $this->wpdb->update( $this->table_name(), array( 'is_active' => 0 ), array( 'source' => $source ), array( '%d' ), array( '%s' ) );

		return is_numeric( $result ) ? (int) $result : 0;
	}

	/**
	 * @param array<string,mixed> $point
	 * @return array<string,mixed>|null
	 */
	private function normalize_point( array $point ): ?array {
		$terminal_code = trim( (string) ( $point['terminal_code'] ?? '' ) );
		$type = trim( (string) ( $point['type'] ?? '' ) );
		if ( '' === $terminal_code || '' === $type ) {
			return null;
		}
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

		return array(
			'terminal_code' => substr( $terminal_code, 0, 64 ),
			'type' => substr( $type, 0, 32 ),
			'country_code' => substr( trim( (string) ( $point['country_code'] ?? 'RU' ) ) ?: 'RU', 0, 8 ),
			'region_code' => $this->nullable_string( $point['region_code'] ?? null, 32 ),
			'region_name' => $this->nullable_string( $point['region_name'] ?? null, 255 ),
			'city_id' => isset( $point['city_id'] ) && '' !== (string) $point['city_id'] ? (int) $point['city_id'] : null,
			'city_code' => $this->nullable_string( $point['city_code'] ?? null, 64 ),
			'city_name' => $this->nullable_string( $point['city_name'] ?? null, 255 ),
			'address' => $this->nullable_string( $point['address'] ?? null, 0 ),
			'name' => $this->nullable_string( $point['name'] ?? null, 255 ),
			'latitude' => is_numeric( $point['latitude'] ?? null ) ? round( (float) $point['latitude'], 7 ) : null,
			'longitude' => is_numeric( $point['longitude'] ?? null ) ? round( (float) $point['longitude'], 7 ) : null,
			'schedule' => $this->nullable_string( $point['schedule'] ?? null, 0 ),
			'raw_json' => $this->nullable_string( $point['raw_json'] ?? null, 0 ),
			'is_active' => empty( $point['is_active'] ) ? 0 : 1,
			'source' => $this->sanitize_source( (string) ( $point['source'] ?? '' ) ),
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

	private function sanitize_source( string $source ): string {
		$source = preg_replace( '/[^A-Za-z0-9_\\-]/', '', $source ) ?? '';

		return substr( '' !== $source ? $source : 'unknown', 0, 64 );
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
		return $this->wpdb->prefix . 'wdc_dpd_pickup_points';
	}

	private function has_test_rows(): bool {
		return property_exists( $this->wpdb, 'dpd_pickup_points' ) && is_array( $this->wpdb->dpd_pickup_points );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function test_rows(): array {
		return $this->has_test_rows() ? $this->wpdb->dpd_pickup_points : array();
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<string,mixed> $filters
	 */
	private function matches_filters( array $row, array $filters ): bool {
		if ( empty( $row['is_active'] ) ) {
			return false;
		}
		foreach ( array( 'terminal_code', 'type', 'city_id', 'city_code', 'country_code', 'source' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] && (string) ( $row[ $key ] ?? '' ) !== (string) $filters[ $key ] ) {
				return false;
			}
		}
		if ( isset( $filters['city_name'] ) && '' !== trim( (string) $filters['city_name'] ) && false === stripos( (string) ( $row['city_name'] ?? '' ), trim( (string) $filters['city_name'] ) ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalize_row( array $row ): array {
		return $row;
	}
}
