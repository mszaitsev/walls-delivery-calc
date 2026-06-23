<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoCoverageRepository {
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
				location_id bigint(20) unsigned NOT NULL,
				yandex_geo_id bigint(20) unsigned NULL,
				source_status varchar(32) NULL,
				coverage_status varchar(32) NOT NULL,
				pickup_points_count int(10) unsigned NOT NULL DEFAULT 0,
				dropoff_points_count int(10) unsigned NOT NULL DEFAULT 0,
				operators_json longtext NULL,
				sample_points_json longtext NULL,
				last_checked_at datetime NULL,
				message text NULL,
				raw_stats_json longtext NULL,
				created_at datetime NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (id),
				KEY location_id (location_id),
				KEY yandex_geo_id (yandex_geo_id),
				KEY coverage_status (coverage_status),
				KEY last_checked_at (last_checked_at)
			) {$charset_collate};"
		);
	}

	/** @param array<string,mixed> $result @return array<string,mixed> */
	public function save_result( array $result ): array {
		$this->create_schema_if_needed();
		$row = $this->normalize_result( $result );
		$existing_id = $this->find_existing_id( (int) $row['location_id'] );
		if ( null !== $existing_id ) {
			$row['id'] = $existing_id;
			$row['created_at'] = $this->existing_created_at( $existing_id ) ?? $row['created_at'];
			if ( $this->has_test_rows() ) {
				foreach ( $this->wpdb->yandex_delivery_geo_coverage as $index => $existing ) {
					if ( (int) ( $existing['id'] ?? 0 ) === $existing_id ) {
						$this->wpdb->yandex_delivery_geo_coverage[ $index ] = array_merge( $existing, $row );
						return $this->wpdb->yandex_delivery_geo_coverage[ $index ];
					}
				}
			}
			$this->update_row( $existing_id, $row );
			return $row;
		}

		if ( $this->has_test_rows() ) {
			$row['id'] = count( $this->wpdb->yandex_delivery_geo_coverage ) + 1;
			$this->wpdb->yandex_delivery_geo_coverage[] = $row;
			return $row;
		}

		$row['id'] = $this->insert_row( $row );

		return $row;
	}

	/** @return array<string,mixed>|null */
	public function find_by_location_id( int $location_id ): ?array {
		if ( $location_id <= 0 ) {
			return null;
		}
		if ( $this->has_test_rows() ) {
			foreach ( $this->test_rows() as $row ) {
				if ( (int) ( $row['location_id'] ?? 0 ) === $location_id ) {
					return $row;
				}
			}
			return null;
		}
		$this->create_schema_if_needed();
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE location_id = %d LIMIT 1', $location_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	public function find_recent( int $limit = 50 ): array {
		$limit = max( 1, min( 200, $limit ) );
		if ( $this->has_test_rows() ) {
			$rows = $this->test_rows();
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $b['last_checked_at'] ?? '' ), (string) ( $a['last_checked_at'] ?? '' ) ) ?: ( (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ) ) );
			return array_slice( $rows, 0, $limit );
		}
		$this->create_schema_if_needed();
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name() . ' ORDER BY last_checked_at DESC, id DESC LIMIT %d', $limit ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,int> */
	public function stats(): array {
		$stats = array_fill_keys( YandexDeliveryGeoCoverageStatus::all(), 0 );
		if ( $this->has_test_rows() ) {
			foreach ( $this->test_rows() as $row ) {
				$status = YandexDeliveryGeoCoverageStatus::normalize( (string) ( $row['coverage_status'] ?? '' ) );
				++$stats[ $status ];
			}
			return $stats;
		}
		$this->create_schema_if_needed();
		$table = $this->table_name();
		foreach ( array_keys( $stats ) as $status ) {
			$stats[ $status ] = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE coverage_status = %s", $status ) );
		}

		return $stats;
	}

	/** @param array<string,mixed> $result @return array<string,mixed> */
	private function normalize_result( array $result ): array {
		$now = $this->now();
		$geo_id = isset( $result['yandex_geo_id'] ) && is_numeric( $result['yandex_geo_id'] ) && (int) $result['yandex_geo_id'] > 0 ? (int) $result['yandex_geo_id'] : null;

		return array(
			'location_id' => max( 0, (int) ( $result['location_id'] ?? 0 ) ),
			'yandex_geo_id' => $geo_id,
			'source_status' => $this->nullable_string( $result['source_status'] ?? null, 32 ),
			'coverage_status' => YandexDeliveryGeoCoverageStatus::normalize( (string) ( $result['coverage_status'] ?? YandexDeliveryGeoCoverageStatus::UNKNOWN ) ),
			'pickup_points_count' => max( 0, (int) ( $result['pickup_points_count'] ?? 0 ) ),
			'dropoff_points_count' => max( 0, (int) ( $result['dropoff_points_count'] ?? 0 ) ),
			'operators_json' => $this->json_or_null( $result['operators_json'] ?? null ),
			'sample_points_json' => $this->json_or_null( $result['sample_points_json'] ?? null ),
			'last_checked_at' => $this->nullable_string( $result['last_checked_at'] ?? $now, 32 ),
			'message' => $this->nullable_string( $result['message'] ?? null, 0 ),
			'raw_stats_json' => $this->json_or_null( $result['raw_stats_json'] ?? null ),
			'created_at' => $this->nullable_string( $result['created_at'] ?? $now, 32 ),
			'updated_at' => $this->nullable_string( $result['updated_at'] ?? $now, 32 ),
		);
	}

	private function find_existing_id( int $location_id ): ?int {
		if ( $location_id <= 0 ) {
			return null;
		}
		if ( $this->has_test_rows() ) {
			foreach ( $this->test_rows() as $row ) {
				if ( (int) ( $row['location_id'] ?? 0 ) === $location_id ) {
					return (int) ( $row['id'] ?? 0 );
				}
			}
			return null;
		}

		$id = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . $this->table_name() . ' WHERE location_id = %d ORDER BY id ASC LIMIT 1', $location_id ) );

		return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
	}

	private function existing_created_at( int $id ): ?string {
		if ( $this->has_test_rows() ) {
			foreach ( $this->test_rows() as $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $id ) {
					return $this->nullable_string( $row['created_at'] ?? null, 32 );
				}
			}
			return null;
		}

		$value = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT created_at FROM ' . $this->table_name() . ' WHERE id = %d LIMIT 1', $id ) );

		return is_scalar( $value ) ? $this->nullable_string( $value, 32 ) : null;
	}

	/** @param array<string,mixed> $row */
	private function insert_row( array $row ): int {
		$columns = $this->columns();
		$sql = sprintf( 'INSERT INTO %s (%s) VALUES (%s)', $this->table_name(), implode( ',', $columns ), implode( ',', array_map( fn( string $column ): string => $this->sql_literal_for_column( $column, $row[ $column ] ?? null ), $columns ) ) );
		$this->wpdb->query( $sql );

		return (int) ( $this->wpdb->insert_id ?? 0 );
	}

	/** @param array<string,mixed> $row */
	private function update_row( int $id, array $row ): void {
		$columns = array_diff( $this->columns(), array( 'created_at' ) );
		$assignments = array_map( fn( string $column ): string => $column . ' = ' . $this->sql_literal_for_column( $column, $row[ $column ] ?? null ), $columns );
		$this->wpdb->query( 'UPDATE ' . $this->table_name() . ' SET ' . implode( ', ', $assignments ) . $this->wpdb->prepare( ' WHERE id = %d', $id ) );
	}

	/** @return array<int,string> */
	private function columns(): array {
		return array( 'location_id', 'yandex_geo_id', 'source_status', 'coverage_status', 'pickup_points_count', 'dropoff_points_count', 'operators_json', 'sample_points_json', 'last_checked_at', 'message', 'raw_stats_json', 'created_at', 'updated_at' );
	}

	private function sql_literal_for_column( string $column, mixed $value ): string {
		$type = match ( $column ) {
			'location_id', 'yandex_geo_id', 'pickup_points_count', 'dropoff_points_count' => 'int',
			default => 'string',
		};

		return $this->sql_literal( $value, $type );
	}

	private function sql_literal( mixed $value, string $type ): string {
		if ( null === $value ) {
			return 'NULL';
		}
		if ( 'int' === $type ) {
			return (string) (int) $value;
		}

		return $this->wpdb->prepare( '%s', (string) $value );
	}

	private function nullable_string( mixed $value, int $max_length ): ?string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}

		return $max_length > 0 ? substr( $value, 0, $max_length ) : $value;
	}

	private function json_or_null( mixed $value ): ?string {
		if ( is_array( $value ) ) {
			$encoded = ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE ) );
			return false !== $encoded ? $encoded : null;
		}

		return $this->nullable_string( $value, 0 );
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_delivery_geo_coverage';
	}

	private function has_test_rows(): bool {
		return property_exists( $this->wpdb, 'yandex_delivery_geo_coverage' ) && is_array( $this->wpdb->yandex_delivery_geo_coverage );
	}

	/** @return array<int,array<string,mixed>> */
	private function test_rows(): array {
		return $this->has_test_rows() ? $this->wpdb->yandex_delivery_geo_coverage : array();
	}

	private function can_create_schema(): bool {
		return defined( 'ABSPATH' ) && is_string( ABSPATH ) && '' !== ABSPATH && method_exists( $this->wpdb, 'get_charset_collate' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
