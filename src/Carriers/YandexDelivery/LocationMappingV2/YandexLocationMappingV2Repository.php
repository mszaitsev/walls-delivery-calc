<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

defined( 'ABSPATH' ) || exit;

final class YandexLocationMappingV2Repository {
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
			yandex_geo_id bigint(20) unsigned NOT NULL,
			location_id bigint(20) unsigned NOT NULL,
			status varchar(32) NOT NULL DEFAULT 'needs_review',
			confidence decimal(5,2) NULL,
			distance_km decimal(10,3) NULL,
			region_match tinyint(1) NOT NULL DEFAULT 0,
			locality_match tinyint(1) NOT NULL DEFAULT 0,
			coordinate_match tinyint(1) NOT NULL DEFAULT 0,
			matched_by_json longtext NULL,
			raw_json longtext NULL,
			is_primary tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY geo_location (yandex_geo_id, location_id),
			KEY yandex_geo_id (yandex_geo_id),
			KEY location_id (location_id),
			KEY status (status),
			KEY is_primary (is_primary)
		) {$charset};";
	}

	public function create_schema_if_needed(): void {
		if ( ! $this->can_create_schema() ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema() );
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
			$this->wpdb->yandex_location_mapping_v2_staging = array();
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
			$this->wpdb->yandex_location_mapping_v2 = $this->wpdb->yandex_location_mapping_v2_staging ?? array();
			$this->wpdb->yandex_location_mapping_v2_staging = array();
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

	public function primary_geo_id_for_location( int $location_id ): int {
		$location_id = max( 0, $location_id );
		if ( $location_id <= 0 ) {
			return 0;
		}
		if ( $this->has_test_rows() ) {
			$rows = array_values( array_filter( $this->wpdb->{$this->test_rows_property()}, static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) === $location_id && ! empty( $row['is_primary'] ) && in_array( (string) ( $row['status'] ?? '' ), array( 'mapped', 'manual' ), true ) && (int) ( $row['yandex_geo_id'] ?? 0 ) > 0 ) );
			usort( $rows, static fn( array $a, array $b ): int => (float) ( $b['confidence'] ?? 0 ) <=> (float) ( $a['confidence'] ?? 0 ) );

			return (int) ( $rows[0]['yandex_geo_id'] ?? 0 );
		}

		$this->create_schema_if_needed();
		$sql = 'SELECT yandex_geo_id FROM ' . $this->table_name() . ' WHERE location_id = %d AND is_primary = 1 AND status IN (%s, %s) AND yandex_geo_id > 0 ORDER BY confidence DESC LIMIT 1';

		return (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, $location_id, 'mapped', 'manual' ) );
	}

	/** @return array<int,int> */
	public function geo_ids_for_location( int $location_id ): array {
		$location_id = max( 0, $location_id );
		if ( $location_id <= 0 ) {
			return array();
		}
		if ( $this->has_test_rows() ) {
			$geo_ids = array_map(
				static fn( array $row ): int => (int) ( $row['yandex_geo_id'] ?? 0 ),
				array_values( array_filter( $this->wpdb->{$this->test_rows_property()}, static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) === $location_id && in_array( (string) ( $row['status'] ?? '' ), array( 'mapped', 'manual' ), true ) && (int) ( $row['yandex_geo_id'] ?? 0 ) > 0 ) )
			);
			$geo_ids = array_values( array_unique( array_filter( $geo_ids, static fn( int $geo_id ): bool => $geo_id > 0 ) ) );
			sort( $geo_ids, SORT_NUMERIC );

			return $geo_ids;
		}

		$this->create_schema_if_needed();
		$sql = 'SELECT DISTINCT yandex_geo_id FROM ' . $this->table_name() . ' WHERE location_id = %d AND status IN (%s, %s) AND yandex_geo_id > 0 ORDER BY yandex_geo_id ASC';
		$rows = $this->wpdb->get_col( $this->wpdb->prepare( $sql, $location_id, 'mapped', 'manual' ) );
		$geo_ids = array_map( 'intval', is_array( $rows ) ? $rows : array() );

		return array_values( array_filter( $geo_ids, static fn( int $geo_id ): bool => $geo_id > 0 ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function find_by_geo( int $geo_id ): array {
		return $this->search( array( 'yandex_geo_id' => $geo_id, 'limit' => 500, 'active' => null ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function find_by_location( int $location_id ): array {
		return $this->search( array( 'location_id' => $location_id, 'limit' => 500, 'active' => null ) );
	}

	/** @param array<string,mixed> $args @return array<int,array<string,mixed>> */
	public function search( array $args = array() ): array {
		$limit = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );
		if ( $this->has_test_rows() ) {
			$rows = array_values( array_filter( $this->wpdb->{$this->test_rows_property()}, fn( array $row ): bool => $this->matches_filters( $row, $args ) ) );
			usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['yandex_geo_id'] ?? 0 ) <=> (int) ( $b['yandex_geo_id'] ?? 0 ) ?: (int) ( $a['location_id'] ?? 0 ) <=> (int) ( $b['location_id'] ?? 0 ) );

			return array_slice( $rows, $offset, $limit );
		}
		$this->create_schema_if_needed();
		$where = array();
		$params = array();
		if ( isset( $args['yandex_geo_id'] ) && (int) $args['yandex_geo_id'] > 0 ) {
			$where[] = 'yandex_geo_id = %d';
			$params[] = (int) $args['yandex_geo_id'];
		}
		if ( isset( $args['location_id'] ) && (int) $args['location_id'] > 0 ) {
			$where[] = 'location_id = %d';
			$params[] = (int) $args['location_id'];
		}
		if ( isset( $args['status'] ) && '' !== trim( (string) $args['status'] ) ) {
			$where[] = 'status = %s';
			$params[] = trim( (string) $args['status'] );
		}
		$where_sql = array() === $where ? '1=1' : implode( ' AND ', $where );
		$params[] = $limit;
		$params[] = $offset;
		$sql = 'SELECT * FROM ' . $this->table_name() . ' WHERE ' . $where_sql . ' ORDER BY yandex_geo_id ASC, is_primary DESC, confidence DESC LIMIT %d OFFSET %d';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$params ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,mixed> */
	public function statistics(): array {
		$rows = $this->has_test_rows() ? $this->wpdb->{$this->test_rows_property()} : null;
		if ( is_array( $rows ) ) {
			return array(
				'total' => count( $rows ),
				'mapped' => $this->count_status_rows( $rows, 'mapped' ),
				'needs_review' => $this->count_status_rows( $rows, 'needs_review' ),
				'no_match' => $this->count_status_rows( $rows, 'no_match' ),
				'manual' => $this->count_status_rows( $rows, 'manual' ),
				'error' => $this->count_status_rows( $rows, 'error' ),
				'avg_confidence' => $this->avg_numeric( $rows, 'confidence' ),
				'avg_distance' => $this->avg_numeric( $rows, 'distance_km' ),
				'no_match_region_not_mapped' => $this->count_no_match_reason_rows( $rows, 'region_not_mapped' ),
				'no_match_no_locality_match' => $this->count_no_match_reason_rows( $rows, 'no_locality_match' ),
				'territory_fallback' => $this->count_raw_flag_rows( $rows, 'territory_fallback' ),
				'mapped_by_dominance' => $this->mapped_by_dominance_rows( $rows ),
			);
		}
		$this->create_schema_if_needed();

		return array(
			'total' => (int) $this->wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->table_name() ),
			'mapped' => $this->count_status( 'mapped' ),
			'needs_review' => $this->count_status( 'needs_review' ),
			'no_match' => $this->count_status( 'no_match' ),
			'manual' => $this->count_status( 'manual' ),
			'error' => $this->count_status( 'error' ),
			'avg_confidence' => $this->nullable_float_var( 'SELECT AVG(confidence) FROM ' . $this->table_name() ),
			'avg_distance' => $this->nullable_float_var( 'SELECT AVG(distance_km) FROM ' . $this->table_name() ),
			'no_match_region_not_mapped' => $this->count_no_match_reason( 'region_not_mapped' ),
			'no_match_no_locality_match' => $this->count_no_match_reason( 'no_locality_match' ),
			'territory_fallback' => $this->count_raw_flag( 'territory_fallback' ),
			'mapped_by_dominance' => $this->mapped_by_dominance(),
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function find_recent_no_match( int $limit = 20 ): array {
		$limit = max( 1, min( 100, $limit ) );
		if ( $this->has_test_rows() ) {
			$geo_rows = property_exists( $this->wpdb, 'yandex_delivery_geo_v2' ) && is_array( $this->wpdb->yandex_delivery_geo_v2 ) ? $this->wpdb->yandex_delivery_geo_v2 : array();
			$geo_by_id = array();
			foreach ( $geo_rows as $geo ) {
				$geo_by_id[ (int) ( $geo['yandex_geo_id'] ?? 0 ) ] = $geo;
			}
			$rows = array_values( array_filter( $this->wpdb->{$this->test_rows_property()}, static fn( array $row ): bool => 'no_match' === (string) ( $row['status'] ?? '' ) ) );
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) ) ?: (int) ( $b['yandex_geo_id'] ?? 0 ) <=> (int) ( $a['yandex_geo_id'] ?? 0 ) );
			$items = array();
			foreach ( $rows as $row ) {
				$geo = $geo_by_id[ (int) ( $row['yandex_geo_id'] ?? 0 ) ] ?? array();
				if ( $this->has_active_manual_override_for_geo( $row, $geo ) ) {
					continue;
				}
				$items[] = $this->no_match_row( $row, $geo );
				if ( count( $items ) >= $limit ) {
					break;
				}
			}
			return $items;
		}
		$this->create_schema_if_needed();
		$fetch_limit = $limit * 5;
		$sql = 'SELECT m.yandex_geo_id, m.raw_json, m.updated_at, g.region, g.locality, g.centroid_lat, g.centroid_lon, g.first_full_address FROM ' . $this->table_name() . ' m LEFT JOIN ' . $this->geo_table_name() . ' g ON g.yandex_geo_id = m.yandex_geo_id WHERE m.status = %s ORDER BY m.updated_at DESC, m.yandex_geo_id DESC LIMIT %d';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, 'no_match', $fetch_limit ), ARRAY_A );
		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $this->has_active_manual_override_for_geo( $row, $row ) ) {
				continue;
			}
			$items[] = $this->no_match_row( $row, $row );
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return $items;
	}


	/** @return array<int,array<string,mixed>> */
	public function find_recent_review_items( int $limit = 20 ): array {
		$limit = max( 1, min( 100, $limit ) );
		if ( $this->has_test_rows() ) {
			$geo_rows = property_exists( $this->wpdb, 'yandex_delivery_geo_v2' ) && is_array( $this->wpdb->yandex_delivery_geo_v2 ) ? $this->wpdb->yandex_delivery_geo_v2 : array();
			$geo_by_id = array();
			foreach ( $geo_rows as $geo ) {
				$geo_by_id[ (int) ( $geo['yandex_geo_id'] ?? 0 ) ] = $geo;
			}
			$rows = array_values( array_filter( $this->wpdb->{$this->test_rows_property()}, static fn( array $row ): bool => in_array( (string) ( $row['status'] ?? '' ), array( 'needs_review', 'no_match' ), true ) ) );
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) ) ?: (int) ( $b['yandex_geo_id'] ?? 0 ) <=> (int) ( $a['yandex_geo_id'] ?? 0 ) ?: (int) ( $b['is_primary'] ?? 0 ) <=> (int) ( $a['is_primary'] ?? 0 ) );
			$items = array();
			foreach ( $rows as $row ) {
				$geo = $geo_by_id[ (int) ( $row['yandex_geo_id'] ?? 0 ) ] ?? array();
				if ( $this->has_active_manual_override_for_geo( $row, $geo ) ) {
					continue;
				}
				$items[] = $this->review_item_row( $row, $geo );
				if ( count( $items ) >= $limit ) {
					break;
				}
			}
			return $items;
		}
		$this->create_schema_if_needed();
		$fetch_limit = $limit * 5;
		$sql = 'SELECT m.*, g.region, g.locality, g.points_count, g.dropoff_count, g.coverage_radius_safe_km, g.centroid_lat, g.centroid_lon, g.first_full_address, l.latitude AS candidate_latitude, l.longitude AS candidate_longitude FROM ' . $this->table_name() . ' m LEFT JOIN ' . $this->geo_table_name() . ' g ON g.yandex_geo_id = m.yandex_geo_id LEFT JOIN ' . $this->locations_table_name() . ' l ON l.id = m.location_id WHERE m.status IN (%s, %s) ORDER BY m.updated_at DESC, m.yandex_geo_id DESC, m.is_primary DESC LIMIT %d';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, 'needs_review', 'no_match', $fetch_limit ), ARRAY_A );
		$items = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( $this->has_active_manual_override_for_geo( $row, $row ) ) {
				continue;
			}
			$items[] = $this->review_item_row( $row, $row );
			if ( count( $items ) >= $limit ) {
				break;
			}
		}
		return $items;
	}
	public function truncate(): void {
		if ( $this->has_test_rows() ) {
			$this->wpdb->{$this->test_rows_property()} = array();
			return;
		}
		$this->create_schema_if_needed();
		$this->wpdb->query( 'TRUNCATE TABLE ' . $this->table_name() );
	}

	/** @return array<int,string> */
	private function columns(): array {
		return array( 'yandex_geo_id', 'location_id', 'status', 'confidence', 'distance_km', 'region_match', 'locality_match', 'coordinate_match', 'matched_by_json', 'raw_json', 'is_primary', 'created_at', 'updated_at' );
	}

	/** @param array<string,mixed> $row @return array<string,mixed>|null */
	private function normalize_row( array $row ): ?array {
		$geo_id = (int) ( $row['yandex_geo_id'] ?? 0 );
		$location_id = (int) ( $row['location_id'] ?? 0 );
		if ( $geo_id <= 0 || $location_id < 0 ) {
			return null;
		}
		$status = (string) ( $row['status'] ?? 'needs_review' );
		if ( ! in_array( $status, array( 'mapped', 'needs_review', 'no_match', 'manual', 'error' ), true ) ) {
			$status = 'needs_review';
		}
		$now = $this->now();
		$normalized = array();
		foreach ( $this->columns() as $column ) {
			$normalized[ $column ] = $row[ $column ] ?? null;
		}
		$normalized['yandex_geo_id'] = $geo_id;
		$normalized['location_id'] = $location_id;
		$normalized['status'] = $status;
		$normalized['confidence'] = is_numeric( $row['confidence'] ?? null ) ? round( (float) $row['confidence'], 2 ) : null;
		$normalized['distance_km'] = is_numeric( $row['distance_km'] ?? null ) ? round( (float) $row['distance_km'], 3 ) : null;
		foreach ( array( 'region_match', 'locality_match', 'coordinate_match', 'is_primary' ) as $column ) {
			$normalized[ $column ] = empty( $row[ $column ] ) ? 0 : 1;
		}
		$normalized['matched_by_json'] = $this->nullable_string( $row['matched_by_json'] ?? null );
		$normalized['raw_json'] = $this->nullable_string( $row['raw_json'] ?? null );
		$normalized['created_at'] = $this->nullable_string( $row['created_at'] ?? $now ) ?? $now;
		$normalized['updated_at'] = $this->nullable_string( $row['updated_at'] ?? $now ) ?? $now;

		return $normalized;
	}

	/** @param array<string,mixed> $row */
	private function upsert_one( array $row ): bool {
		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->{$this->test_rows_property()} as $index => $existing ) {
				if ( (int) ( $existing['yandex_geo_id'] ?? 0 ) === (int) $row['yandex_geo_id'] && (int) ( $existing['location_id'] ?? -1 ) === (int) $row['location_id'] ) {
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
		$values = array_map( fn( string $column ): string => $this->sql_literal( $row[ $column ] ?? null, $this->column_type( $column ) ), $columns );
		$updates = array();
		foreach ( array_diff( $columns, array( 'yandex_geo_id', 'location_id', 'created_at' ) ) as $column ) {
			$updates[] = $column . '=VALUES(' . $column . ')';
		}
		$sql = sprintf( 'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s', $this->table_name(), implode( ',', $columns ), implode( ',', $values ), implode( ', ', $updates ) );

		return false !== $this->wpdb->query( $sql );
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $args */
	private function matches_filters( array $row, array $args ): bool {
		if ( isset( $args['yandex_geo_id'] ) && (int) $args['yandex_geo_id'] > 0 && (int) ( $row['yandex_geo_id'] ?? 0 ) !== (int) $args['yandex_geo_id'] ) {
			return false;
		}
		if ( isset( $args['location_id'] ) && (int) $args['location_id'] > 0 && (int) ( $row['location_id'] ?? 0 ) !== (int) $args['location_id'] ) {
			return false;
		}
		if ( isset( $args['status'] ) && '' !== trim( (string) $args['status'] ) && (string) ( $row['status'] ?? '' ) !== trim( (string) $args['status'] ) ) {
			return false;
		}

		return true;
	}


	/** @param array<int,array<string,mixed>> $rows @return array<string,int> */
	private function mapped_by_dominance_rows( array $rows ): array {
		$counts = array();
		foreach ( $rows as $row ) {
			if ( 'mapped' !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}
			$raw = $this->decoded_raw_json( $row );
			if ( true !== ( $raw['dominance_auto_pick'] ?? false ) ) {
				continue;
			}
			$rule = (string) ( $raw['dominance_rule'] ?? ( $raw['dominance_reason'] ?? '' ) );
			if ( '' !== $rule ) {
				$counts[ $rule ] = ( $counts[ $rule ] ?? 0 ) + 1;
			}
		}
		ksort( $counts );

		return $counts;
	}

	/** @return array<string,int> */
	private function mapped_by_dominance(): array {
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT raw_json FROM ' . $this->table_name() . ' WHERE status = %s AND raw_json LIKE %s', 'mapped', '%"dominance_auto_pick":true%' ), ARRAY_A );
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$raw = $this->decoded_raw_json( $row );
			$rule = (string) ( $raw['dominance_rule'] ?? ( $raw['dominance_reason'] ?? '' ) );
			if ( '' !== $rule ) {
				$counts[ $rule ] = ( $counts[ $rule ] ?? 0 ) + 1;
			}
		}
		ksort( $counts );

		return $counts;
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function count_status_rows( array $rows, string $status ): int {
		return count( array_filter( $rows, static fn( array $row ): bool => $status === (string) ( $row['status'] ?? '' ) ) );
	}

	private function count_status( string $status ): int {
		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE status = %s', $status ) );
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function count_no_match_reason_rows( array $rows, string $reason ): int {
		return count(
			array_filter(
				$rows,
				fn( array $row ): bool => 'no_match' === (string) ( $row['status'] ?? '' )
					&& $reason === (string) ( $this->decoded_raw_json( $row )['reason'] ?? '' )
			)
		);
	}

	/** @param array<int,array<string,mixed>> $rows */
	private function count_raw_flag_rows( array $rows, string $flag ): int {
		return count(
			array_filter(
				$rows,
				fn( array $row ): bool => true === ( $this->decoded_raw_json( $row )[ $flag ] ?? false )
			)
		);
	}

	private function count_no_match_reason( string $reason ): int {
		$needle = '%"reason":"' . $this->escape_like( $reason ) . '"%';

		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE status = %s AND raw_json LIKE %s', 'no_match', $needle ) );
	}

	private function count_raw_flag( string $flag ): int {
		$needle = '%"' . $this->escape_like( $flag ) . '":true%';

		return (int) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE raw_json LIKE %s', $needle ) );
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

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private function decoded_raw_json( array $row ): array {
		$raw = json_decode( (string) ( $row['raw_json'] ?? '' ), true );

		return is_array( $raw ) ? $raw : array();
	}

	private function escape_like( string $value ): string {
		return method_exists( $this->wpdb, 'esc_like' ) ? $this->wpdb->esc_like( $value ) : addcslashes( $value, '_%\\' );
	}

	private function column_type( string $column ): string {
		if ( in_array( $column, array( 'yandex_geo_id', 'location_id', 'region_match', 'locality_match', 'coordinate_match', 'is_primary' ), true ) ) {
			return 'int';
		}
		if ( in_array( $column, array( 'confidence', 'distance_km' ), true ) ) {
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

	private function nullable_string( mixed $value ): ?string {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return null;
		}
		$value = trim( (string) $value );

		return '' === $value ? null : $value;
	}


	/** @param array<string,mixed> $mapping @param array<string,mixed> $geo @return array<string,mixed> */
	private function review_item_row( array $mapping, array $geo ): array {
		$raw = json_decode( (string) ( $mapping['raw_json'] ?? '' ), true );
		return array(
			'yandex_geo_id' => (int) ( $mapping['yandex_geo_id'] ?? 0 ),
			'location_id' => (int) ( $mapping['location_id'] ?? 0 ),
			'status' => (string) ( $mapping['status'] ?? '' ),
			'confidence' => $mapping['confidence'] ?? null,
			'distance_km' => $mapping['distance_km'] ?? null,
			'is_primary' => (int) ( $mapping['is_primary'] ?? 0 ),
			'region' => (string) ( $geo['region'] ?? '' ),
			'locality' => (string) ( $geo['locality'] ?? '' ),
			'points_count' => (int) ( $geo['points_count'] ?? 0 ),
			'dropoff_count' => (int) ( $geo['dropoff_count'] ?? 0 ),
			'coverage_radius_safe_km' => $geo['coverage_radius_safe_km'] ?? null,
			'first_full_address' => (string) ( $geo['first_full_address'] ?? '' ),
			'centroid_lat' => $geo['centroid_lat'] ?? null,
			'centroid_lon' => $geo['centroid_lon'] ?? null,
			'candidate_latitude' => $this->candidate_coordinate( $mapping, 'latitude' ),
			'candidate_longitude' => $this->candidate_coordinate( $mapping, 'longitude' ),
			'raw' => is_array( $raw ) ? $raw : array(),
		);
	}
	/** @param array<string,mixed> $mapping @param array<string,mixed> $geo @return array<string,mixed> */
	private function no_match_row( array $mapping, array $geo ): array {
		return array(
			'yandex_geo_id' => (int) ( $mapping['yandex_geo_id'] ?? 0 ),
			'region' => (string) ( $geo['region'] ?? '' ),
			'locality' => (string) ( $geo['locality'] ?? '' ),
			'first_full_address' => (string) ( $geo['first_full_address'] ?? '' ),
			'centroid_lat' => $geo['centroid_lat'] ?? null,
			'centroid_lon' => $geo['centroid_lon'] ?? null,
			'updated_at' => (string) ( $mapping['updated_at'] ?? '' ),
		);
	}

	/** @param array<string,mixed> $mapping @param array<string,mixed> $geo */
	private function has_active_manual_override_for_geo( array $mapping, array $geo ): bool {
		$geo_id = (int) ( $mapping['yandex_geo_id'] ?? 0 );
		$region = (string) ( $geo['region'] ?? '' );
		$locality = (string) ( $geo['locality'] ?? '' );
		if ( $geo_id <= 0 || '' === trim( $region ) || '' === trim( $locality ) ) {
			return false;
		}
		$overrides = new YandexLocationManualOverrideV2Repository( $this->wpdb );
		return array() !== $overrides->find_active_for_geo_identity( $geo_id, $region, $locality ) || array() !== $overrides->find_active_for_identity( $region, $locality );
	}

	/** @param array<string,mixed> $mapping */
	private function candidate_coordinate( array $mapping, string $axis ): mixed {
		$alias = 'latitude' === $axis ? 'candidate_latitude' : 'candidate_longitude';
		if ( array_key_exists( $alias, $mapping ) ) {
			return $mapping[ $alias ];
		}
		$location_id = (int) ( $mapping['location_id'] ?? 0 );
		if ( $location_id <= 0 ) {
			return null;
		}
		$location = $this->location_by_id( $location_id );
		return $location[ $axis ] ?? null;
	}

	/** @return array<string,mixed> */
	private function location_by_id( int $location_id ): array {
		if ( property_exists( $this->wpdb, 'wdc_locations' ) && is_array( $this->wpdb->wdc_locations ) ) {
			foreach ( $this->wpdb->wdc_locations as $location ) {
				if ( (int) ( $location['id'] ?? 0 ) === $location_id ) {
					return $location;
				}
			}
			return array();
		}
		if ( $location_id <= 0 || ! method_exists( $this->wpdb, 'get_row' ) ) {
			return array();
		}
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE id = %d LIMIT 1', $location_id ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}
	private function table_name(): string {
		return $this->use_staging_table ? $this->staging_table_name() : $this->live_table_name();
	}

	private function live_table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_location_mapping_v2';
	}

	private function staging_table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_location_mapping_v2_staging';
	}

	private function geo_table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_delivery_geo_v2';
	}

	private function locations_table_name(): string {
		return $this->wpdb->prefix . 'wdc_locations';
	}

	private function has_test_rows(): bool {
		$property = $this->test_rows_property();
		if ( $this->use_staging_table && ! property_exists( $this->wpdb, $property ) && property_exists( $this->wpdb, 'yandex_location_mapping_v2' ) ) {
			$this->wpdb->{$property} = array();
		}
		return property_exists( $this->wpdb, $property ) && is_array( $this->wpdb->{$property} );
	}

	private function is_test_environment(): bool {
		return property_exists( $this->wpdb, 'yandex_location_mapping_v2' ) || property_exists( $this->wpdb, 'yandex_location_mapping_v2_staging' );
	}

	private function test_rows_property(): string {
		return $this->use_staging_table ? 'yandex_location_mapping_v2_staging' : 'yandex_location_mapping_v2';
	}

	private function quote_identifier( string $identifier ): string {
		$quote = chr( 96 );
		return $quote . str_replace( $quote, $quote . $quote, $identifier ) . $quote;
	}

	private function can_create_schema(): bool {
		return defined( 'ABSPATH' ) && is_string( ABSPATH ) && '' !== ABSPATH && method_exists( $this->wpdb, 'get_charset_collate' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
