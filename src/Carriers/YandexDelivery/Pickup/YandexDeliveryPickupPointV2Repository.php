<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pickup;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPickupPointV2Repository {
	private object $wpdb;
	private bool $use_staging_table = false;

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
			platform_station_id varchar(80) NOT NULL,
			operator_station_id varchar(80) NULL,
			operator_id varchar(80) NULL,
			type varchar(64) NOT NULL,
			name varchar(255) NULL,
			yandex_geo_id bigint(20) unsigned NULL,
			country varchar(128) NULL,
			region varchar(255) NULL,
			sub_region varchar(255) NULL,
			locality varchar(255) NULL,
			street varchar(255) NULL,
			house varchar(64) NULL,
			housing varchar(64) NULL,
			building varchar(64) NULL,
			apartment varchar(64) NULL,
			postal_code varchar(32) NULL,
			full_address text NULL,
			latitude decimal(10,7) NULL,
			longitude decimal(10,7) NULL,
			instruction text NULL,
			phone varchar(255) NULL,
			schedule_text text NULL,
			is_yandex_branded tinyint(1) NOT NULL DEFAULT 0,
			is_market_partner tinyint(1) NOT NULL DEFAULT 0,
			is_dark_store tinyint(1) NOT NULL DEFAULT 0,
			is_post_office tinyint(1) NOT NULL DEFAULT 0,
			available_for_dropoff tinyint(1) NOT NULL DEFAULT 0,
			deactivation_date datetime NULL,
			deactivation_date_predicted_debt datetime NULL,
			location_details_json longtext NULL,
			station_contact_json longtext NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			last_seen_at datetime NULL,
			raw_hash char(40) NOT NULL,
			created_at datetime NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY platform_station_id (platform_station_id),
			KEY operator_station_id (operator_station_id),
			KEY operator_id (operator_id),
			KEY type (type),
			KEY yandex_geo_id (yandex_geo_id),
			KEY locality (locality),
			KEY postal_code (postal_code),
			KEY active (active),
			KEY raw_hash (raw_hash)
		) {$charset};";
	}

	public function create_schema_if_needed(): void {
		if ( ! $this->can_create_schema() ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema() );
	}

	public function truncate(): void {
		if ( $this->has_test_rows() ) {
			$this->wpdb->{$this->test_rows_property()} = array();
			if ( ! $this->use_staging_table && property_exists( $this->wpdb, 'yandex_delivery_pickup_points_v2_truncate_count' ) ) {
				++$this->wpdb->yandex_delivery_pickup_points_v2_truncate_count;
			}
			return;
		}

		$this->create_schema_if_needed();
		$this->wpdb->query( 'TRUNCATE TABLE ' . $this->table_name() );
	}

	public function use_staging_table(): void {
		$this->use_staging_table = true;
	}

	public function use_live_table(): void {
		$this->use_staging_table = false;
	}

	public function prepare_staging_table(): void {
		$this->use_staging_table();
		$this->create_schema_if_needed();
		$this->truncate();
	}

	public function drop_staging_table(): void {
		if ( $this->is_test_environment() ) {
			$this->wpdb->yandex_delivery_pickup_points_v2_staging = array();
			$this->use_live_table();
			return;
		}
		if ( method_exists( $this->wpdb, 'query' ) ) {
			$this->wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $this->staging_table_name() ) );
		}
		$this->use_live_table();
	}

	public function promote_staging_to_live(): void {
		if ( $this->is_test_environment() ) {
			$this->wpdb->yandex_delivery_pickup_points_v2 = $this->wpdb->yandex_delivery_pickup_points_v2_staging ?? array();
			$this->wpdb->yandex_delivery_pickup_points_v2_staging = array();
			$this->use_live_table();
			return;
		}
		$this->create_schema_if_needed();
		$live = $this->live_table_name();
		$staging = $this->staging_table_name();
		$backup = $live . '_old_' . gmdate( 'YmdHis' );
		$this->wpdb->query( 'CREATE TABLE IF NOT EXISTS ' . $this->quote_identifier( $live ) . ' LIKE ' . $this->quote_identifier( $staging ) );
		$this->wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $backup ) );
		$this->wpdb->query( 'RENAME TABLE ' . $this->quote_identifier( $live ) . ' TO ' . $this->quote_identifier( $backup ) . ', ' . $this->quote_identifier( $staging ) . ' TO ' . $this->quote_identifier( $live ) );
		$this->wpdb->query( 'DROP TABLE IF EXISTS ' . $this->quote_identifier( $backup ) );
		$this->use_live_table();
	}
	/**
	 * @param array<int,array<string,mixed>>|array<string,mixed> $rows
	 * @return array{received:int,saved:int,skipped_invalid:int}
	 */
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
	public function find( string $platform_station_id ): ?array {
		$rows = $this->search( array( 'platform_station_id' => $platform_station_id, 'limit' => 1, 'active' => null ) );

		return $rows[0] ?? null;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function search( array $filters = array() ): array {
		$limit = max( 1, min( 500, (int) ( $filters['limit'] ?? 20 ) ) );
		if ( $this->has_test_rows() ) {
			$rows = array_values( array_filter( $this->wpdb->{$this->test_rows_property()}, fn( array $row ): bool => $this->matches_filters( $row, $filters ) ) );
			usort(
				$rows,
				static fn( array $a, array $b ): int => strcmp( (string) ( $a['locality'] ?? '' ) . (string) ( $a['name'] ?? '' ), (string) ( $b['locality'] ?? '' ) . (string) ( $b['name'] ?? '' ) )
			);

			return array_slice( $rows, 0, $limit );
		}

		$this->create_schema_if_needed();
		$where = array();
		$args = array();
		$this->append_where( $where, $args, $filters );
		$where_sql = array() === $where ? '1=1' : implode( ' AND ', $where );
		$args[] = $limit;
		$sql = 'SELECT * FROM ' . $this->table_name() . ' WHERE ' . $where_sql . ' ORDER BY locality ASC, name ASC, platform_station_id ASC LIMIT %d';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}


	/** @return array<int,array<string,mixed>> */
	public function source_dropoff_points_by_geo_id( int $yandex_geo_id, int $limit = 500 ): array {
		$yandex_geo_id = max( 0, $yandex_geo_id );
		$limit = max( 1, min( 1000, $limit ) );
		if ( $yandex_geo_id <= 0 ) {
			return array();
		}
		if ( $this->has_test_rows() ) {
			$rows = array_values( array_filter( $this->wpdb->{$this->test_rows_property()}, fn( array $row ): bool => $this->is_source_dropoff_row( $row ) && (int) ( $row['yandex_geo_id'] ?? 0 ) === $yandex_geo_id ) );
			usort(
				$rows,
				static fn( array $a, array $b ): int => strcmp( (string) ( $a['name'] ?? '' ) . (string) ( $a['platform_station_id'] ?? '' ), (string) ( $b['name'] ?? '' ) . (string) ( $b['platform_station_id'] ?? '' ) )
			);

			return array_slice( $rows, 0, $limit );
		}

		$this->create_schema_if_needed();
		$sql = 'SELECT platform_station_id, name, locality, full_address, yandex_geo_id, available_for_dropoff, active FROM ' . $this->table_name() . ' WHERE active = 1 AND available_for_dropoff = 1 AND yandex_geo_id = %d AND platform_station_id <> %s ORDER BY name ASC, platform_station_id ASC LIMIT %d';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $yandex_geo_id, '', $limit ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @param array<string,mixed> $filters */
	public function count( array $filters = array() ): int {
		if ( $this->has_test_rows() ) {
			return count( array_filter( $this->wpdb->{$this->test_rows_property()}, fn( array $row ): bool => $this->matches_filters( $row, $filters ) ) );
		}

		$this->create_schema_if_needed();
		$where = array();
		$args = array();
		$this->append_where( $where, $args, $filters );
		$where_sql = array() === $where ? '1=1' : implode( ' AND ', $where );
		$sql = 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE ' . $where_sql;
		if ( array() !== $args ) {
			$sql = $this->wpdb->prepare( $sql, ...$args );
		}

		return (int) $this->wpdb->get_var( $sql );
	}

	public function count_all(): int {
		return $this->count( array( 'active' => null ) );
	}

	public function count_active(): int {
		return $this->count( array( 'active' => 1 ) );
	}

	/** @return array<string,int> */
	public function count_by_type(): array {
		if ( $this->has_test_rows() ) {
			$counts = array();
			foreach ( $this->wpdb->{$this->test_rows_property()} as $row ) {
				$type = trim( (string) ( $row['type'] ?? '' ) );
				if ( '' !== $type ) {
					$counts[ $type ] = ( $counts[ $type ] ?? 0 ) + 1;
				}
			}
			return $counts;
		}
		$rows = $this->wpdb->get_results( 'SELECT type, COUNT(*) AS total FROM ' . $this->table_name() . ' GROUP BY type', ARRAY_A );
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$counts[ (string) $row['type'] ] = (int) $row['total'];
		}
		return $counts;
	}

	public function count_unique_geo_ids(): int {
		if ( $this->has_test_rows() ) {
			$ids = array();
			foreach ( $this->wpdb->{$this->test_rows_property()} as $row ) {
				$id = (string) ( $row['yandex_geo_id'] ?? '' );
				if ( '' !== $id && '0' !== $id ) {
					$ids[ $id ] = true;
				}
			}
			return count( $ids );
		}
		$this->create_schema_if_needed();
		return (int) $this->wpdb->get_var( 'SELECT COUNT(DISTINCT yandex_geo_id) FROM ' . $this->table_name() . ' WHERE yandex_geo_id IS NOT NULL AND yandex_geo_id > 0' );
	}

	public function count_active_unique_geo_ids(): int {
		if ( $this->has_test_rows() ) {
			$ids = array();
			foreach ( $this->wpdb->{$this->test_rows_property()} as $row ) {
				if ( empty( $row['active'] ) ) {
					continue;
				}
				$id = (string) ( $row['yandex_geo_id'] ?? '' );
				if ( '' !== $id && '0' !== $id ) {
					$ids[ $id ] = true;
				}
			}
			return count( $ids );
		}
		$this->create_schema_if_needed();
		return (int) $this->wpdb->get_var( 'SELECT COUNT(DISTINCT yandex_geo_id) FROM ' . $this->table_name() . ' WHERE active = 1 AND yandex_geo_id IS NOT NULL AND yandex_geo_id > 0' );
	}
	public function latest_seen_at(): string {
		if ( $this->has_test_rows() ) {
			$latest = '';
			foreach ( $this->wpdb->{$this->test_rows_property()} as $row ) {
				$value = (string) ( $row['last_seen_at'] ?? '' );
				if ( $value > $latest ) {
					$latest = $value;
				}
			}
			return $latest;
		}
		$this->create_schema_if_needed();
		return (string) $this->wpdb->get_var( 'SELECT MAX(last_seen_at) FROM ' . $this->table_name() );
	}

	/** @param array<int,string> $where @param array<int,mixed> $args @param array<string,mixed> $filters */
	private function append_where( array &$where, array &$args, array $filters ): void {
		if ( ! array_key_exists( 'active', $filters ) || null !== $filters['active'] ) {
			$where[] = 'active = %d';
			$args[] = empty( $filters['active'] ?? 1 ) ? 0 : 1;
		}
		foreach ( array( 'platform_station_id', 'operator_station_id', 'operator_id', 'type', 'postal_code' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== trim( (string) $filters[ $key ] ) ) {
				$where[] = "{$key} = %s";
				$args[] = trim( (string) $filters[ $key ] );
			}
		}
		if ( isset( $filters['yandex_geo_id'] ) && '' !== trim( (string) $filters['yandex_geo_id'] ) ) {
			$where[] = 'yandex_geo_id = %d';
			$args[] = (int) $filters['yandex_geo_id'];
		}
		foreach ( array( 'locality', 'full_address', 'name' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== trim( (string) $filters[ $key ] ) ) {
				$where[] = "{$key} LIKE %s";
				$args[] = '%' . $this->wpdb->esc_like( trim( (string) $filters[ $key ] ) ) . '%';
			}
		}
	}

	/** @param array<string,mixed> $row */
	private function upsert_one( array $row ): bool {
		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->{$this->test_rows_property()} as $index => $existing ) {
				if ( (string) ( $existing['platform_station_id'] ?? '' ) === $row['platform_station_id'] ) {
					$row['id'] = $existing['id'] ?? $index + 1;
					$row['created_at'] = $existing['created_at'] ?? $row['created_at'];
					$this->wpdb->{$this->test_rows_property()}[ $index ] = $row;
					return true;
				}
			}
			$row['id'] = count( $this->wpdb->{$this->test_rows_property()} ) + 1;
			$this->wpdb->{$this->test_rows_property()}[] = $row;
			return true;
		}

		$columns = $this->columns();
		$values = array();
		foreach ( $columns as $column ) {
			$values[] = $this->sql_literal( $row[ $column ] ?? null, $this->column_type( $column ) );
		}
		$updates = array();
		foreach ( array_diff( $columns, array( 'platform_station_id', 'created_at' ) ) as $column ) {
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

	/** @param array<string,mixed> $row @return array<string,mixed>|null */
	private function normalize_row( array $row ): ?array {
		$platform_station_id = $this->nullable_string( $row['platform_station_id'] ?? null, 80 );
		$type = $this->nullable_string( $row['type'] ?? null, 64 );
		if ( null === $platform_station_id || null === $type ) {
			return null;
		}
		$now = $this->now();
		$normalized = array();
		foreach ( $this->columns() as $column ) {
			$normalized[ $column ] = $row[ $column ] ?? null;
		}
		$normalized['platform_station_id'] = $platform_station_id;
		$normalized['operator_station_id'] = $this->nullable_string( $row['operator_station_id'] ?? null, 80 );
		$normalized['operator_id'] = $this->nullable_string( $row['operator_id'] ?? null, 80 );
		$normalized['type'] = $type;
		foreach ( array( 'name', 'country', 'region', 'sub_region', 'locality', 'street', 'house', 'housing', 'building', 'apartment', 'postal_code', 'phone' ) as $column ) {
			$normalized[ $column ] = $this->nullable_string( $row[ $column ] ?? null, 255 );
		}
		$normalized['yandex_geo_id'] = is_numeric( $row['yandex_geo_id'] ?? null ) ? (int) $row['yandex_geo_id'] : null;
		foreach ( array( 'full_address', 'instruction', 'schedule_text', 'location_details_json', 'station_contact_json' ) as $column ) {
			$normalized[ $column ] = $this->nullable_string( $row[ $column ] ?? null, 0 );
		}
		$normalized['latitude'] = is_numeric( $row['latitude'] ?? null ) ? round( (float) $row['latitude'], 7 ) : null;
		$normalized['longitude'] = is_numeric( $row['longitude'] ?? null ) ? round( (float) $row['longitude'], 7 ) : null;
		foreach ( array( 'is_yandex_branded', 'is_market_partner', 'is_dark_store', 'is_post_office', 'available_for_dropoff', 'active' ) as $column ) {
			$normalized[ $column ] = empty( $row[ $column ] ) ? 0 : 1;
		}
		foreach ( array( 'deactivation_date', 'deactivation_date_predicted_debt', 'last_seen_at' ) as $column ) {
			$normalized[ $column ] = $this->nullable_string( $row[ $column ] ?? null, 32 );
		}
		$normalized['raw_hash'] = $this->nullable_string( $row['raw_hash'] ?? null, 40 ) ?? sha1( $this->json( $row ) );
		$normalized['created_at'] = $this->nullable_string( $row['created_at'] ?? $now, 32 );
		$normalized['updated_at'] = $this->nullable_string( $row['updated_at'] ?? $now, 32 );

		return $normalized;
	}

	/** @return array<int,string> */
	private function columns(): array {
		return array( 'platform_station_id', 'operator_station_id', 'operator_id', 'type', 'name', 'yandex_geo_id', 'country', 'region', 'sub_region', 'locality', 'street', 'house', 'housing', 'building', 'apartment', 'postal_code', 'full_address', 'latitude', 'longitude', 'instruction', 'phone', 'schedule_text', 'is_yandex_branded', 'is_market_partner', 'is_dark_store', 'is_post_office', 'available_for_dropoff', 'deactivation_date', 'deactivation_date_predicted_debt', 'location_details_json', 'station_contact_json', 'active', 'last_seen_at', 'raw_hash', 'created_at', 'updated_at' );
	}

	private function column_type( string $column ): string {
		if ( in_array( $column, array( 'is_yandex_branded', 'is_market_partner', 'is_dark_store', 'is_post_office', 'available_for_dropoff', 'active', 'yandex_geo_id' ), true ) ) {
			return 'int';
		}
		if ( in_array( $column, array( 'latitude', 'longitude' ), true ) ) {
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

	private function is_source_dropoff_row( array $row ): bool {
		return ! empty( $row['active'] ) && ! empty( $row['available_for_dropoff'] ) && '' !== trim( (string) ( $row['platform_station_id'] ?? '' ) );
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $filters */
	private function matches_filters( array $row, array $filters ): bool {
		if ( ( ! array_key_exists( 'active', $filters ) || null !== $filters['active'] ) && ( ! empty( $row['active'] ) ? 1 : 0 ) !== ( empty( $filters['active'] ?? 1 ) ? 0 : 1 ) ) {
			return false;
		}
		foreach ( array( 'platform_station_id', 'operator_station_id', 'operator_id', 'type', 'postal_code' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== trim( (string) $filters[ $key ] ) && (string) ( $row[ $key ] ?? '' ) !== trim( (string) $filters[ $key ] ) ) {
				return false;
			}
		}
		if ( isset( $filters['yandex_geo_id'] ) && '' !== trim( (string) $filters['yandex_geo_id'] ) && (int) ( $row['yandex_geo_id'] ?? 0 ) !== (int) $filters['yandex_geo_id'] ) {
			return false;
		}
		foreach ( array( 'locality', 'full_address', 'name' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== trim( (string) $filters[ $key ] ) && false === stripos( (string) ( $row[ $key ] ?? '' ), trim( (string) $filters[ $key ] ) ) ) {
				return false;
			}
		}

		return true;
	}

	private function table_name(): string {
		return $this->use_staging_table ? $this->staging_table_name() : $this->live_table_name();
	}

	private function live_table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_delivery_pickup_points_v2';
	}

	private function staging_table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_delivery_pickup_points_v2_staging';
	}

	private function has_test_rows(): bool {
		$property = $this->test_rows_property();
		if ( $this->use_staging_table && ! property_exists( $this->wpdb, $property ) && property_exists( $this->wpdb, 'yandex_delivery_pickup_points_v2' ) ) {
			$this->wpdb->{$property} = array();
		}
		return property_exists( $this->wpdb, $property ) && is_array( $this->wpdb->{$property} );
	}

	private function is_test_environment(): bool {
		return property_exists( $this->wpdb, 'yandex_delivery_pickup_points_v2' ) || property_exists( $this->wpdb, 'yandex_delivery_pickup_points_v2_staging' );
	}

	private function test_rows_property(): string {
		return $this->use_staging_table ? 'yandex_delivery_pickup_points_v2_staging' : 'yandex_delivery_pickup_points_v2';
	}

	private function quote_identifier( string $identifier ): string {
		$quote = chr( 96 );
		return $quote . str_replace( $quote, $quote . $quote, $identifier ) . $quote;
	}

	private function can_create_schema(): bool {
		return defined( 'ABSPATH' )
			&& is_string( ABSPATH )
			&& '' !== ABSPATH
			&& method_exists( $this->wpdb, 'get_charset_collate' )
			&& file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' );
	}

	private function json( mixed $value ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : '';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
