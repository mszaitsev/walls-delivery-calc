<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoMappingRepository {
	public const TECHNICAL_ERROR_GEO_ID = 999999999;
	private const REGION_CLEANUP_LOCATION_BATCH = 500;

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
				yandex_locality varchar(255) NULL,
				yandex_region varchar(255) NULL,
				source_query varchar(255) NULL,
				status varchar(32) NULL,
				confidence decimal(5,2) NULL,
				is_primary tinyint(1) NOT NULL DEFAULT 0,
				raw_json longtext NULL,
				created_at datetime NULL,
				updated_at datetime NULL,
				PRIMARY KEY  (id),
				KEY location_id (location_id),
				KEY yandex_geo_id (yandex_geo_id),
				KEY status (status),
				KEY is_primary (is_primary)
			) {$charset_collate};"
		);
	}

	/** @param array<string,mixed> $mapping @return array<string,mixed> */
	public function save_mapping( array $mapping ): array {
		$this->create_schema_if_needed();
		$row = $this->normalize_mapping( $mapping );
		$existing_id = $this->find_existing_mapping_id( $row );
		if ( null !== $existing_id ) {
			$row['id'] = $existing_id;
			$row['created_at'] = $this->existing_created_at( $existing_id ) ?? $row['created_at'];
			if ( $this->has_test_rows() ) {
				foreach ( $this->wpdb->yandex_delivery_geo_mappings as $index => $existing ) {
					if ( (int) ( $existing['id'] ?? 0 ) === $existing_id ) {
						$this->wpdb->yandex_delivery_geo_mappings[ $index ] = array_merge( $existing, $row );
						return $this->wpdb->yandex_delivery_geo_mappings[ $index ];
					}
				}
			}
			$this->update_row( $existing_id, $row );
			return $row;
		}

		if ( $this->has_test_rows() ) {
			$row['id'] = count( $this->wpdb->yandex_delivery_geo_mappings ) + 1;
			$this->wpdb->yandex_delivery_geo_mappings[] = $row;
			return $row;
		}

		$row['id'] = $this->insert_row( $row );

		return $row;
	}

	/** @param array<int,array<string,mixed>> $mappings @return array<int,array<string,mixed>> */
	public function save_mappings( array $mappings ): array {
		$saved = array();
		foreach ( $mappings as $mapping ) {
			$saved[] = $this->save_mapping( $mapping );
		}

		return $saved;
	}

	/** @return array<int,array<string,mixed>> */
	public function find_by_location_id( int $location_id ): array {
		if ( $location_id <= 0 ) {
			return array();
		}
		if ( $this->has_test_rows() ) {
			$rows = array_values( array_filter( $this->test_rows(), static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) === $location_id ) );
			usort( $rows, static fn( array $a, array $b ): int => ( (int) ( $b['is_primary'] ?? 0 ) <=> (int) ( $a['is_primary'] ?? 0 ) ) ?: ( (float) ( $b['confidence'] ?? 0 ) <=> (float) ( $a['confidence'] ?? 0 ) ) );
			return $rows;
		}
		$this->create_schema_if_needed();
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE location_id = %d ORDER BY is_primary DESC, confidence DESC, id ASC', $location_id ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	public function find_primary_geo_id( int $location_id ): ?int {
		foreach ( $this->find_by_location_id( $location_id ) as $row ) {
			if ( ! empty( $row['is_primary'] ) && null !== ( $row['yandex_geo_id'] ?? null ) && (int) $row['yandex_geo_id'] > 0 && ! $this->is_technical_error_geo_id( (int) $row['yandex_geo_id'] ) ) {
				return (int) $row['yandex_geo_id'];
			}
		}

		return null;
	}

	public function set_primary( int $location_id, int $yandex_geo_id ): bool {
		if ( $location_id <= 0 || $yandex_geo_id <= 0 || $this->is_technical_error_geo_id( $yandex_geo_id ) ) {
			return false;
		}
		$this->create_schema_if_needed();
		if ( $this->has_test_rows() ) {
			$found = false;
			foreach ( $this->wpdb->yandex_delivery_geo_mappings as $index => $row ) {
				if ( (int) ( $row['location_id'] ?? 0 ) !== $location_id ) {
					continue;
				}
				$is_target = null !== ( $row['yandex_geo_id'] ?? null ) && (int) $row['yandex_geo_id'] === $yandex_geo_id;
				$this->wpdb->yandex_delivery_geo_mappings[ $index ]['is_primary'] = $is_target ? 1 : 0;
				if ( $is_target ) {
					$this->wpdb->yandex_delivery_geo_mappings[ $index ]['status'] = YandexDeliveryGeoMappingStatus::MANUAL;
					$found = true;
				}
			}
			return $found;
		}
		$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name() . ' SET is_primary = 0, updated_at = %s WHERE location_id = %d', $this->now(), $location_id ) );
		$result = $this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name() . ' SET is_primary = 1, status = %s, updated_at = %s WHERE location_id = %d AND yandex_geo_id = %d', YandexDeliveryGeoMappingStatus::MANUAL, $this->now(), $location_id, $yandex_geo_id ) );

		return is_numeric( $result ) && (int) $result > 0;
	}

	public function save_technical_error_marker( int $location_id, string $source_query, string $message ): array {
		return $this->save_mapping(
			array(
				'location_id' => $location_id,
				'yandex_geo_id' => self::TECHNICAL_ERROR_GEO_ID,
				'source_query' => $source_query,
				'status' => YandexDeliveryGeoMappingStatus::ERROR,
				'confidence' => 0,
				'is_primary' => 0,
				'raw_json' => $this->json(
					array(
						'type' => 'technical_error',
						'message' => $this->truncate( $message, 500 ),
						'marker_geo_id' => self::TECHNICAL_ERROR_GEO_ID,
						'updated_at' => $this->now(),
					)
				),
			)
		);
	}

	/** @return array<int,int> */
	public function find_technical_error_location_ids_after( int $after_id, int $limit ): array {
		$after_id = max( 0, $after_id );
		$limit = max( 1, min( 1000, $limit ) );
		if ( $this->has_test_rows() ) {
			$ids = array_values(
				array_unique(
					array_map(
						static fn( array $row ): int => (int) ( $row['location_id'] ?? 0 ),
						array_filter(
							$this->test_rows(),
							fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) > $after_id
								&& $this->is_technical_error_geo_id( (int) ( $row['yandex_geo_id'] ?? 0 ) )
						)
					)
				)
			);
			sort( $ids );
			return array_slice( array_filter( $ids, static fn( int $id ): bool => $id > 0 ), 0, $limit );
		}
		$this->create_schema_if_needed();
		$rows = $this->wpdb->get_col( $this->wpdb->prepare( 'SELECT DISTINCT location_id FROM ' . $this->table_name() . ' WHERE location_id > %d AND yandex_geo_id = %d ORDER BY location_id ASC LIMIT %d', $after_id, self::TECHNICAL_ERROR_GEO_ID, $limit ) );

		return is_array( $rows ) ? array_values( array_map( 'intval', $rows ) ) : array();
	}

	public function count_technical_error_markers(): int {
		if ( $this->has_test_rows() ) {
			$ids = array();
			foreach ( $this->test_rows() as $row ) {
				if ( $this->is_technical_error_geo_id( (int) ( $row['yandex_geo_id'] ?? 0 ) ) ) {
					$ids[ (int) ( $row['location_id'] ?? 0 ) ] = true;
				}
			}
			unset( $ids[0] );
			return count( $ids );
		}
		$this->create_schema_if_needed();
		$value = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(DISTINCT location_id) FROM ' . $this->table_name() . ' WHERE yandex_geo_id = %d', self::TECHNICAL_ERROR_GEO_ID ) );

		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	public function find_max_processed_location_id(): int {
		if ( $this->has_test_rows() ) {
			$max = 0;
			foreach ( $this->test_rows() as $row ) {
				$max = max( $max, (int) ( $row['location_id'] ?? 0 ) );
			}

			return $max;
		}
		$this->create_schema_if_needed();
		$value = $this->wpdb->get_var( 'SELECT MAX(location_id) FROM ' . $this->table_name() );

		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	public function clear_technical_error_marker( int $location_id ): void {
		if ( $location_id <= 0 ) {
			return;
		}
		if ( $this->has_test_rows() ) {
			$this->wpdb->yandex_delivery_geo_mappings = array_values(
				array_filter(
					$this->wpdb->yandex_delivery_geo_mappings,
					fn( array $row ): bool => ! ( (int) ( $row['location_id'] ?? 0 ) === $location_id && $this->is_technical_error_geo_id( (int) ( $row['yandex_geo_id'] ?? 0 ) ) )
				)
			);
			return;
		}
		$this->create_schema_if_needed();
		$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name() . ' WHERE location_id = %d AND yandex_geo_id = %d', $location_id, self::TECHNICAL_ERROR_GEO_ID ) );
	}

	public function is_technical_error_geo_id( int $geo_id ): bool {
		return self::TECHNICAL_ERROR_GEO_ID === $geo_id;
	}
	public function delete_location_mappings( int $location_id ): int {
		if ( $location_id <= 0 ) {
			return 0;
		}
		if ( $this->has_test_rows() ) {
			$before = count( $this->wpdb->yandex_delivery_geo_mappings );
			$this->wpdb->yandex_delivery_geo_mappings = array_values( array_filter( $this->wpdb->yandex_delivery_geo_mappings, static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) !== $location_id ) );
			return $before - count( $this->wpdb->yandex_delivery_geo_mappings );
		}
		$this->create_schema_if_needed();
		$result = $this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name() . ' WHERE location_id = %d', $location_id ) );

		return is_numeric( $result ) ? (int) $result : 0;
	}


	/** @param array<string,mixed> $args @return array<int,array<string,mixed>> */
	public function find_needs_review_locations( array $args = array() ): array {
		$page = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		if ( $this->has_test_rows() ) {
			return array_slice( $this->group_needs_review_test_rows( $args ), ( $page - 1 ) * $per_page, $per_page );
		}

		$this->create_schema_if_needed();
		[$where, $where_args] = $this->needs_review_where_sql( $args );
		$offset = ( $page - 1 ) * $per_page;
		$mapping_table = $this->table_name();
		$location_table = $this->locations_table_name();
		$ids_sql = "SELECT DISTINCT m.location_id FROM {$mapping_table} m LEFT JOIN {$location_table} l ON l.id = m.location_id WHERE " . implode( ' AND ', $where ) . ' ORDER BY m.location_id ASC LIMIT %d OFFSET %d';
		$location_ids = $this->wpdb->get_col( $this->wpdb->prepare( $ids_sql, ...array_merge( $where_args, array( $per_page, $offset ) ) ) );
		$location_ids = is_array( $location_ids ) ? array_values( array_filter( array_map( 'intval', $location_ids ) ) ) : array();
		if ( array() === $location_ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $location_ids ), '%d' ) );
		$rows_sql = "SELECT m.*, l.display_name, l.region_name AS location_region_name, l.settlement_type, l.place_type, l.city_type
			FROM {$mapping_table} m
			LEFT JOIN {$location_table} l ON l.id = m.location_id
			WHERE m.status = %s AND m.location_id IN ({$placeholders})
			ORDER BY m.location_id ASC, m.confidence DESC, m.id ASC";
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $rows_sql, ...array_merge( array( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW ), $location_ids ) ), ARRAY_A );

		return $this->group_needs_review_rows( is_array( $rows ) ? $rows : array() );
	}

	/** @param array<string,mixed> $args */
	public function count_needs_review_locations( array $args = array() ): int {
		if ( $this->has_test_rows() ) {
			return count( $this->group_needs_review_test_rows( $args ) );
		}

		$this->create_schema_if_needed();
		[$where, $where_args] = $this->needs_review_where_sql( $args );
		$sql = 'SELECT COUNT(DISTINCT m.location_id) FROM ' . $this->table_name() . ' m LEFT JOIN ' . $this->locations_table_name() . ' l ON l.id = m.location_id WHERE ' . implode( ' AND ', $where );
		$value = $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$where_args ) );

		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	public function approve_mapping( int $location_id, int $yandex_geo_id ): bool {
		if ( $location_id <= 0 || $yandex_geo_id <= 0 || $this->is_technical_error_geo_id( $yandex_geo_id ) ) {
			return false;
		}
		$now = $this->now();
		if ( $this->has_test_rows() ) {
			$found = false;
			foreach ( $this->wpdb->yandex_delivery_geo_mappings as $index => $row ) {
				if ( (int) ( $row['location_id'] ?? 0 ) !== $location_id ) {
					continue;
				}
				$is_target = (int) ( $row['yandex_geo_id'] ?? 0 ) === $yandex_geo_id;
				if ( $is_target ) {
					$found = true;
				}
				$this->wpdb->yandex_delivery_geo_mappings[ $index ]['is_primary'] = $is_target ? 1 : 0;
				$this->wpdb->yandex_delivery_geo_mappings[ $index ]['status'] = $is_target ? YandexDeliveryGeoMappingStatus::MAPPED : YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES;
				$this->wpdb->yandex_delivery_geo_mappings[ $index ]['updated_at'] = $now;
				if ( $is_target ) {
					$this->wpdb->yandex_delivery_geo_mappings[ $index ]['raw_json'] = $this->with_manual_review_audit( (string) ( $row['raw_json'] ?? '' ), 'approved', '' );
				}
			}
			return $found;
		}

		$this->create_schema_if_needed();
		$target_id = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . $this->table_name() . ' WHERE location_id = %d AND yandex_geo_id = %d LIMIT 1', $location_id, $yandex_geo_id ) );
		if ( ! is_numeric( $target_id ) || (int) $target_id <= 0 ) {
			return false;
		}
		$raw_json = (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT raw_json FROM ' . $this->table_name() . ' WHERE id = %d LIMIT 1', (int) $target_id ) );
		$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name() . ' SET is_primary = 0, status = %s, updated_at = %s WHERE location_id = %d AND id != %d', YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES, $now, $location_id, (int) $target_id ) );
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				'UPDATE ' . $this->table_name() . ' SET status = %s, is_primary = 1, raw_json = %s, updated_at = %s WHERE id = %d',
				YandexDeliveryGeoMappingStatus::MAPPED,
				$this->with_manual_review_audit( $raw_json, 'approved', '' ),
				$now,
				(int) $target_id
			)
		);

		return is_numeric( $result ) && (int) $result > 0;
	}

	public function reject_mapping( int $location_id, string $message = '' ): bool {
		if ( $location_id <= 0 ) {
			return false;
		}
		$had_rows = array() !== $this->find_by_location_id( $location_id );
		$this->delete_location_mappings( $location_id );
		$this->save_mapping(
			array(
				'location_id' => $location_id,
				'yandex_geo_id' => null,
				'status' => YandexDeliveryGeoMappingStatus::NOT_FOUND,
				'confidence' => 0,
				'is_primary' => 0,
				'raw_json' => $this->with_manual_review_audit( '{}', 'rejected', $message ),
			)
		);

		return $had_rows;
	}

	/** @param array<int,int|string> $location_ids */
	public function bulk_reject_locations( array $location_ids, string $message = '' ): int {
		$count = 0;
		foreach ( array_values( array_unique( array_map( 'intval', $location_ids ) ) ) as $location_id ) {
			if ( $this->reject_mapping( $location_id, $message ) ) {
				++$count;
			}
		}

		return $count;
	}

	/** @return array{matched_locations:int,checked_candidates:int,removed_candidates:int,converted_to_not_found:int} */
	public function cleanup_needs_review_by_region( string $region_fragment ): array {
		$fragment = trim( $region_fragment );
		$stats = array(
			'matched_locations' => 0,
			'checked_candidates' => 0,
			'removed_candidates' => 0,
			'converted_to_not_found' => 0,
		);
		if ( '' === $fragment ) {
			return $stats;
		}

		if ( $this->has_test_rows() ) {
			$matched_locations = array();
			$location_ids_to_review = array();
			foreach ( $this->wpdb->yandex_delivery_geo_mappings as $index => $row ) {
				$location_id = (int) ( $row['location_id'] ?? 0 );
				if ( $location_id <= 0 || YandexDeliveryGeoMappingStatus::NEEDS_REVIEW !== (string) ( $row['status'] ?? '' ) || ! empty( $row['is_primary'] ) || $this->is_technical_error_geo_id( (int) ( $row['yandex_geo_id'] ?? 0 ) ) ) {
					continue;
				}
				$meta = $this->test_location_meta( $location_id );
				if ( ! $this->contains_fragment( (string) ( $meta['location_region_name'] ?? $meta['region_name'] ?? '' ), $fragment ) ) {
					continue;
				}
				$matched_locations[ $location_id ] = true;
				$location_ids_to_review[ $location_id ] = true;
				++$stats['checked_candidates'];
				if ( ! $this->contains_fragment( (string) ( $row['yandex_locality'] ?? '' ), $fragment ) ) {
					unset( $this->wpdb->yandex_delivery_geo_mappings[ $index ] );
					++$stats['removed_candidates'];
				}
			}
			$this->wpdb->yandex_delivery_geo_mappings = array_values( $this->wpdb->yandex_delivery_geo_mappings );
			$stats['matched_locations'] = count( $matched_locations );
			foreach ( array_keys( $location_ids_to_review ) as $location_id ) {
				if ( null === $this->find_primary_geo_id( (int) $location_id ) && ! $this->location_has_needs_review_candidates( (int) $location_id ) ) {
					$this->save_cleanup_not_found( (int) $location_id, $fragment );
					++$stats['converted_to_not_found'];
				}
			}

			return $stats;
		}

		$this->create_schema_if_needed();
		$mapping_table = $this->table_name();
		$location_table = $this->locations_table_name();
		$region_like = '%' . $this->wpdb->esc_like( $fragment ) . '%';
		$stats['matched_locations'] = $this->count_region_cleanup_locations( $region_like );
		$stats['checked_candidates'] = $this->count_region_cleanup_candidates( $region_like );

		$after_location_id = 0;
		while ( true ) {
			$location_ids = $this->find_region_cleanup_location_ids_after( $region_like, $after_location_id, self::REGION_CLEANUP_LOCATION_BATCH );
			if ( array() === $location_ids ) {
				break;
			}
			$after_location_id = max( $location_ids );
			$placeholders = implode( ',', array_fill( 0, count( $location_ids ), '%d' ) );
			$delete_sql = "DELETE m FROM {$mapping_table} m INNER JOIN {$location_table} l ON l.id = m.location_id WHERE m.status = %s AND m.is_primary = 0 AND (m.yandex_geo_id IS NULL OR m.yandex_geo_id != %d) AND l.region_name LIKE %s AND m.location_id IN ({$placeholders}) AND (m.yandex_locality IS NULL OR m.yandex_locality NOT LIKE %s)";
			$deleted = $this->wpdb->query(
				$this->wpdb->prepare(
					$delete_sql,
					...array_merge(
						array( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, self::TECHNICAL_ERROR_GEO_ID, $region_like ),
						$location_ids,
						array( $region_like )
					)
				)
			);
			if ( is_numeric( $deleted ) && (int) $deleted > 0 ) {
				$stats['removed_candidates'] += (int) $deleted;
			}
			foreach ( $location_ids as $location_id ) {
				if ( null === $this->find_primary_geo_id( (int) $location_id ) && ! $this->location_has_needs_review_candidates( (int) $location_id ) ) {
					$this->save_cleanup_not_found( (int) $location_id, $fragment );
					++$stats['converted_to_not_found'];
				}
			}
		}

		return $stats;
	}

	/** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
	public function search( array $filters = array() ): array {
		$limit = max( 1, min( 100, (int) ( $filters['limit'] ?? 20 ) ) );
		if ( $this->has_test_rows() ) {
			$rows = array_values( array_filter( $this->test_rows(), fn( array $row ): bool => $this->matches_filters( $row, $filters ) ) );
			return array_slice( $rows, 0, $limit );
		}
		$this->create_schema_if_needed();
		$where = array( '1=1' );
		$args = array();
		foreach ( array( 'location_id' => '%d', 'status' => '%s', 'is_primary' => '%d' ) as $key => $format ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
				$where[] = "{$key} = {$format}";
				$args[] = '%d' === $format ? (int) $filters[ $key ] : trim( (string) $filters[ $key ] );
			}
		}
		if ( array_key_exists( 'yandex_geo_id', $filters ) && '' !== (string) $filters['yandex_geo_id'] ) {
			if ( null === $filters['yandex_geo_id'] ) {
				$where[] = 'yandex_geo_id IS NULL';
			} else {
				$where[] = 'yandex_geo_id = %d';
				$args[] = (int) $filters['yandex_geo_id'];
			}
		}
		if ( isset( $filters['query'] ) && '' !== trim( (string) $filters['query'] ) ) {
			$where[] = '(yandex_locality LIKE %s OR yandex_region LIKE %s OR source_query LIKE %s)';
			$like = '%' . $this->wpdb->esc_like( trim( (string) $filters['query'] ) ) . '%';
			array_push( $args, $like, $like, $like );
		}
		$args[] = $limit;
		$sql = 'SELECT * FROM ' . $this->table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC, id DESC LIMIT %d';
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, ...$args ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<string,int> */
	public function statistics(): array {
		$stats = array( 'locations_with_mappings' => 0, 'primary_mappings' => 0, 'multiple_matches' => 0, 'not_found' => 0, 'manual_mappings' => 0 );
		if ( $this->has_test_rows() ) {
			$locations = array();
			foreach ( $this->test_rows() as $row ) {
				$locations[ (int) ( $row['location_id'] ?? 0 ) ] = true;
				$stats['primary_mappings'] += ! empty( $row['is_primary'] ) ? 1 : 0;
				$status = (string) ( $row['status'] ?? '' );
				$stats['multiple_matches'] += YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES === $status ? 1 : 0;
				$stats['not_found'] += YandexDeliveryGeoMappingStatus::NOT_FOUND === $status ? 1 : 0;
				$stats['manual_mappings'] += YandexDeliveryGeoMappingStatus::MANUAL === $status ? 1 : 0;
			}
			unset( $locations[0] );
			$stats['locations_with_mappings'] = count( $locations );
			return $stats;
		}
		$this->create_schema_if_needed();
		$table = $this->table_name();
		$stats['locations_with_mappings'] = (int) $this->wpdb->get_var( "SELECT COUNT(DISTINCT location_id) FROM {$table}" );
		$stats['primary_mappings'] = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE is_primary = 1" );
		$stats['multiple_matches'] = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES ) );
		$stats['not_found'] = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", YandexDeliveryGeoMappingStatus::NOT_FOUND ) );
		$stats['manual_mappings'] = (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", YandexDeliveryGeoMappingStatus::MANUAL ) );

		return $stats;
	}

	/** @param array<string,mixed> $mapping @return array<string,mixed> */
	private function normalize_mapping( array $mapping ): array {
		$now = $this->now();
		$geo_id = isset( $mapping['yandex_geo_id'] ) && is_numeric( $mapping['yandex_geo_id'] ) && (int) $mapping['yandex_geo_id'] > 0 ? (int) $mapping['yandex_geo_id'] : null;

		return array(
			'location_id' => max( 0, (int) ( $mapping['location_id'] ?? 0 ) ),
			'yandex_geo_id' => $geo_id,
			'yandex_locality' => $this->nullable_string( $mapping['yandex_locality'] ?? null, 255 ),
			'yandex_region' => $this->nullable_string( $mapping['yandex_region'] ?? null, 255 ),
			'source_query' => $this->nullable_string( $mapping['source_query'] ?? null, 255 ),
			'status' => YandexDeliveryGeoMappingStatus::normalize( (string) ( $mapping['status'] ?? YandexDeliveryGeoMappingStatus::ERROR ) ),
			'confidence' => is_numeric( $mapping['confidence'] ?? null ) ? round( max( 0.0, min( 100.0, (float) $mapping['confidence'] ) ), 2 ) : null,
			'is_primary' => empty( $mapping['is_primary'] ) || null === $geo_id ? 0 : 1,
			'raw_json' => $this->nullable_string( $mapping['raw_json'] ?? null, 0 ),
			'created_at' => $this->nullable_string( $mapping['created_at'] ?? $now, 32 ),
			'updated_at' => $this->nullable_string( $mapping['updated_at'] ?? $now, 32 ),
		);
	}

	/** @param array<string,mixed> $row */
	private function find_existing_mapping_id( array $row ): ?int {
		if ( $this->has_test_rows() ) {
			foreach ( $this->test_rows() as $existing ) {
				if ( $this->same_mapping_identity( $existing, $row ) ) {
					return (int) ( $existing['id'] ?? 0 );
				}
			}
			return null;
		}

		if ( null === $row['yandex_geo_id'] ) {
			if ( null === $row['source_query'] ) {
				$id = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . $this->table_name() . ' WHERE location_id = %d AND yandex_geo_id IS NULL AND status = %s AND source_query IS NULL ORDER BY id ASC LIMIT 1', (int) $row['location_id'], (string) $row['status'] ) );
				return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
			}
			$id = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . $this->table_name() . ' WHERE location_id = %d AND yandex_geo_id IS NULL AND status = %s AND source_query = %s ORDER BY id ASC LIMIT 1', (int) $row['location_id'], (string) $row['status'], (string) $row['source_query'] ) );
			return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
		}

		$id = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT id FROM ' . $this->table_name() . ' WHERE location_id = %d AND yandex_geo_id = %d ORDER BY id ASC LIMIT 1', (int) $row['location_id'], (int) $row['yandex_geo_id'] ) );

		return is_numeric( $id ) && (int) $id > 0 ? (int) $id : null;
	}

	/** @param array<string,mixed> $existing @param array<string,mixed> $row */
	private function same_mapping_identity( array $existing, array $row ): bool {
		if ( (int) ( $existing['location_id'] ?? 0 ) !== (int) $row['location_id'] ) {
			return false;
		}
		$existing_geo_id = isset( $existing['yandex_geo_id'] ) && is_numeric( $existing['yandex_geo_id'] ) && (int) $existing['yandex_geo_id'] > 0 ? (int) $existing['yandex_geo_id'] : null;
		if ( null !== $row['yandex_geo_id'] ) {
			return $existing_geo_id === (int) $row['yandex_geo_id'];
		}

		return null === $existing_geo_id
			&& (string) ( $existing['status'] ?? '' ) === (string) $row['status']
			&& (string) ( $existing['source_query'] ?? '' ) === (string) ( $row['source_query'] ?? '' );
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
		$columns = array( 'location_id', 'yandex_geo_id', 'yandex_locality', 'yandex_region', 'source_query', 'status', 'confidence', 'is_primary', 'raw_json', 'created_at', 'updated_at' );
		$sql = sprintf( 'INSERT INTO %s (%s) VALUES (%s)', $this->table_name(), implode( ',', $columns ), implode( ',', array_map( fn( string $column ): string => $this->sql_literal_for_column( $column, $row[ $column ] ?? null ), $columns ) ) );
		$this->wpdb->query( $sql );

		return (int) ( $this->wpdb->insert_id ?? 0 );
	}

	/** @param array<string,mixed> $row */
	private function update_row( int $id, array $row ): void {
		$columns = array( 'location_id', 'yandex_geo_id', 'yandex_locality', 'yandex_region', 'source_query', 'status', 'confidence', 'is_primary', 'raw_json', 'updated_at' );
		$assignments = array_map( fn( string $column ): string => $column . ' = ' . $this->sql_literal_for_column( $column, $row[ $column ] ?? null ), $columns );
		$this->wpdb->query( 'UPDATE ' . $this->table_name() . ' SET ' . implode( ', ', $assignments ) . $this->wpdb->prepare( ' WHERE id = %d', $id ) );
	}

	private function sql_literal_for_column( string $column, mixed $value ): string {
		$type = match ( $column ) {
			'location_id', 'yandex_geo_id', 'is_primary' => 'int',
			'confidence' => 'float',
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
		if ( 'float' === $type ) {
			return is_numeric( $value ) ? (string) (float) $value : 'NULL';
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

	/** @param array<string,mixed> $row @param array<string,mixed> $filters */
	private function matches_filters( array $row, array $filters ): bool {
		foreach ( array( 'location_id', 'is_primary' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] && (int) ( $row[ $key ] ?? 0 ) !== (int) $filters[ $key ] ) {
				return false;
			}
		}
		if ( array_key_exists( 'yandex_geo_id', $filters ) && '' !== (string) $filters['yandex_geo_id'] ) {
			$row_geo_id = isset( $row['yandex_geo_id'] ) && is_numeric( $row['yandex_geo_id'] ) && (int) $row['yandex_geo_id'] > 0 ? (int) $row['yandex_geo_id'] : null;
			if ( null === $filters['yandex_geo_id'] ) {
				if ( null !== $row_geo_id ) {
					return false;
				}
			} elseif ( $row_geo_id !== (int) $filters['yandex_geo_id'] ) {
				return false;
			}
		}
		if ( isset( $filters['status'] ) && '' !== trim( (string) $filters['status'] ) && (string) ( $row['status'] ?? '' ) !== trim( (string) $filters['status'] ) ) {
			return false;
		}
		if ( isset( $filters['query'] ) && '' !== trim( (string) $filters['query'] ) ) {
			$haystack = implode( ' ', array( (string) ( $row['yandex_locality'] ?? '' ), (string) ( $row['yandex_region'] ?? '' ), (string) ( $row['source_query'] ?? '' ) ) );
			return false !== stripos( $haystack, trim( (string) $filters['query'] ) );
		}

		return true;
	}


	/** @param array<string,mixed> $args @return array<int,array<string,mixed>> */
	private function group_needs_review_test_rows( array $args ): array {
		$rows = array();
		foreach ( $this->test_rows() as $row ) {
			if ( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW !== (string) ( $row['status'] ?? '' ) ) {
				continue;
			}
			$row = array_merge( $this->test_location_meta( (int) ( $row['location_id'] ?? 0 ) ), $row );
			if ( $this->matches_needs_review_filters( $row, $args ) ) {
				$rows[] = $row;
			}
		}
		usort( $rows, static fn( array $a, array $b ): int => ( (int) ( $a['location_id'] ?? 0 ) <=> (int) ( $b['location_id'] ?? 0 ) ) ?: ( (float) ( $b['confidence'] ?? 0 ) <=> (float) ( $a['confidence'] ?? 0 ) ) );

		return $this->group_needs_review_rows( $rows );
	}

	/** @param array<int,array<string,mixed>> $rows @return array<int,array<string,mixed>> */
	private function group_needs_review_rows( array $rows ): array {
		$grouped = array();
		foreach ( $rows as $row ) {
			$location_id = (int) ( $row['location_id'] ?? 0 );
			if ( $location_id <= 0 ) {
				continue;
			}
			if ( ! isset( $grouped[ $location_id ] ) ) {
				$meta = $this->location_meta_from_row( $row );
				$grouped[ $location_id ] = array(
					'location_id' => $location_id,
					'display_name' => $meta['display_name'],
					'region_name' => $meta['region_name'],
					'settlement_type' => $meta['settlement_type'],
					'candidates' => array(),
				);
			}
			$scoring = $this->mapping_scoring( $row );
			$grouped[ $location_id ]['candidates'][] = array(
				'yandex_geo_id' => isset( $row['yandex_geo_id'] ) && is_numeric( $row['yandex_geo_id'] ) ? (int) $row['yandex_geo_id'] : null,
				'yandex_locality' => (string) ( $row['yandex_locality'] ?? '' ),
				'status' => (string) ( $row['status'] ?? '' ),
				'confidence' => is_numeric( $row['confidence'] ?? null ) ? (float) $row['confidence'] : 0.0,
				'matched_by' => $scoring['matched_by'],
				'reason' => $scoring['reason'],
				'is_primary' => (int) ( $row['is_primary'] ?? 0 ),
			);
		}

		return array_values( $grouped );
	}

	/** @param array<string,mixed> $row @return array{display_name:string,region_name:string,settlement_type:string} */
	private function location_meta_from_row( array $row ): array {
		$display = (string) ( $row['display_name'] ?? '' );
		$region = (string) ( $row['location_region_name'] ?? ( $row['region_name'] ?? '' ) );
		$type = (string) ( $row['settlement_type'] ?? ( $row['place_type'] ?? ( $row['city_type'] ?? '' ) ) );
		if ( '' === $display ) {
			$display = trim( implode( ', ', array_filter( array( $region, (string) ( $row['source_query'] ?? '' ) ) ) ) );
		}

		return array( 'display_name' => $display, 'region_name' => $region, 'settlement_type' => $type );
	}

	/** @return array{display_name:string,location_region_name:string,settlement_type:string,place_type:string,city_type:string} */
	private function test_location_meta( int $location_id ): array {
		if ( property_exists( $this->wpdb, 'locations' ) && is_array( $this->wpdb->locations ) ) {
			foreach ( $this->wpdb->locations as $location ) {
				if ( (int) ( $location['id'] ?? 0 ) === $location_id ) {
					return array(
						'display_name' => (string) ( $location['display_name'] ?? '' ),
						'location_region_name' => (string) ( $location['region_name'] ?? '' ),
						'settlement_type' => (string) ( $location['settlement_type'] ?? '' ),
						'place_type' => (string) ( $location['place_type'] ?? '' ),
						'city_type' => (string) ( $location['city_type'] ?? '' ),
					);
				}
			}
		}

		return array( 'display_name' => '', 'location_region_name' => '', 'settlement_type' => '', 'place_type' => '', 'city_type' => '' );
	}

	/** @param array<string,mixed> $row @param array<string,mixed> $args */
	private function matches_needs_review_filters( array $row, array $args ): bool {
		if ( isset( $args['max_location_id_exclusive'] ) && (int) $args['max_location_id_exclusive'] > 0 && (int) ( $row['location_id'] ?? 0 ) >= (int) $args['max_location_id_exclusive'] ) {
			return false;
		}
		$search = trim( (string) ( $args['search'] ?? '' ) );
		if ( '' !== $search ) {
			$haystack = implode( ' ', array( (string) ( $row['display_name'] ?? '' ), (string) ( $row['source_query'] ?? '' ), (string) ( $row['yandex_locality'] ?? '' ), (string) ( $row['yandex_region'] ?? '' ) ) );
			if ( false === stripos( $haystack, $search ) ) {
				return false;
			}
		}
		$region = trim( (string) ( $args['region'] ?? '' ) );
		if ( '' !== $region && false === stripos( (string) ( $row['location_region_name'] ?? '' ), $region ) ) {
			return false;
		}
		$type = trim( (string) ( $args['settlement_type'] ?? '' ) );
		if ( '' !== $type ) {
			$type_haystack = implode( ' ', array( (string) ( $row['settlement_type'] ?? '' ), (string) ( $row['place_type'] ?? '' ), (string) ( $row['city_type'] ?? '' ) ) );
			if ( false === stripos( $type_haystack, $type ) ) {
				return false;
			}
		}
		$confidence = is_numeric( $row['confidence'] ?? null ) ? (float) $row['confidence'] : 0.0;
		if ( isset( $args['min_confidence'] ) && '' !== (string) $args['min_confidence'] && $confidence < (float) $args['min_confidence'] ) {
			return false;
		}
		if ( isset( $args['max_confidence'] ) && '' !== (string) $args['max_confidence'] && $confidence > (float) $args['max_confidence'] ) {
			return false;
		}

		return true;
	}

	/** @param array<string,mixed> $args @return array{0:array<int,string>,1:array<int,mixed>} */
	private function needs_review_where_sql( array $args ): array {
		$where = array( 'm.status = %s' );
		$sql_args = array( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW );
		if ( isset( $args['max_location_id_exclusive'] ) && (int) $args['max_location_id_exclusive'] > 0 ) {
			$where[] = 'm.location_id < %d';
			$sql_args[] = (int) $args['max_location_id_exclusive'];
		}
		if ( '' !== trim( (string) ( $args['search'] ?? '' ) ) ) {
			$like = '%' . $this->wpdb->esc_like( trim( (string) $args['search'] ) ) . '%';
			$where[] = '(l.display_name LIKE %s OR m.source_query LIKE %s OR m.yandex_locality LIKE %s OR m.yandex_region LIKE %s)';
			array_push( $sql_args, $like, $like, $like, $like );
		}
		if ( '' !== trim( (string) ( $args['region'] ?? '' ) ) ) {
			$where[] = 'l.region_name LIKE %s';
			$sql_args[] = '%' . $this->wpdb->esc_like( trim( (string) $args['region'] ) ) . '%';
		}
		if ( '' !== trim( (string) ( $args['settlement_type'] ?? '' ) ) ) {
			$where[] = '(l.settlement_type LIKE %s OR l.place_type LIKE %s OR l.city_type LIKE %s)';
			$like = '%' . $this->wpdb->esc_like( trim( (string) $args['settlement_type'] ) ) . '%';
			array_push( $sql_args, $like, $like, $like );
		}
		if ( isset( $args['min_confidence'] ) && '' !== (string) $args['min_confidence'] ) {
			$where[] = 'm.confidence >= %f';
			$sql_args[] = (float) $args['min_confidence'];
		}
		if ( isset( $args['max_confidence'] ) && '' !== (string) $args['max_confidence'] ) {
			$where[] = 'm.confidence <= %f';
			$sql_args[] = (float) $args['max_confidence'];
		}

		return array( $where, $sql_args );
	}

	/** @param array<string,mixed> $row @return array{matched_by:array<int,string>,reason:string} */
	private function mapping_scoring( array $row ): array {
		$raw = json_decode( (string) ( $row['raw_json'] ?? '' ), true );
		$scoring = is_array( $raw ) && is_array( $raw['scoring'] ?? null ) ? $raw['scoring'] : array();
		$matched_by = is_array( $scoring['matched_by'] ?? null ) ? array_values( array_filter( array_map( 'strval', $scoring['matched_by'] ) ) ) : array();
		$reason = is_scalar( $scoring['reason'] ?? null ) ? (string) $scoring['reason'] : '';

		return array( 'matched_by' => $matched_by, 'reason' => $reason );
	}

	private function with_manual_review_audit( string $raw_json, string $action, string $message ): string {
		$raw = json_decode( $raw_json, true );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		$raw['manual_review'] = array(
			'action' => $action,
			'approved_at' => 'approved' === $action ? $this->now() : null,
			'rejected_at' => 'rejected' === $action ? $this->now() : null,
			'source' => 'admin',
		);
		if ( '' !== trim( $message ) ) {
			$raw['manual_review']['message'] = $this->truncate( $message, 500 );
		}

		return $this->json( $raw );
	}


	private function count_region_cleanup_locations( string $region_like ): int {
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(DISTINCT m.location_id) FROM ' . $this->table_name() . ' m INNER JOIN ' . $this->locations_table_name() . ' l ON l.id = m.location_id WHERE m.status = %s AND m.is_primary = 0 AND (m.yandex_geo_id IS NULL OR m.yandex_geo_id != %d) AND l.region_name LIKE %s',
				YandexDeliveryGeoMappingStatus::NEEDS_REVIEW,
				self::TECHNICAL_ERROR_GEO_ID,
				$region_like
			)
		);

		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	private function count_region_cleanup_candidates( string $region_like ): int {
		$value = $this->wpdb->get_var(
			$this->wpdb->prepare(
				'SELECT COUNT(*) FROM ' . $this->table_name() . ' m INNER JOIN ' . $this->locations_table_name() . ' l ON l.id = m.location_id WHERE m.status = %s AND m.is_primary = 0 AND (m.yandex_geo_id IS NULL OR m.yandex_geo_id != %d) AND l.region_name LIKE %s',
				YandexDeliveryGeoMappingStatus::NEEDS_REVIEW,
				self::TECHNICAL_ERROR_GEO_ID,
				$region_like
			)
		);

		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/** @return array<int,int> */
	private function find_region_cleanup_location_ids_after( string $region_like, int $after_location_id, int $limit ): array {
		$limit = max( 1, min( self::REGION_CLEANUP_LOCATION_BATCH, $limit ) );
		$ids = $this->wpdb->get_col(
			$this->wpdb->prepare(
				'SELECT DISTINCT m.location_id FROM ' . $this->table_name() . ' m INNER JOIN ' . $this->locations_table_name() . ' l ON l.id = m.location_id WHERE m.status = %s AND m.is_primary = 0 AND (m.yandex_geo_id IS NULL OR m.yandex_geo_id != %d) AND l.region_name LIKE %s AND m.location_id > %d ORDER BY m.location_id ASC LIMIT %d',
				YandexDeliveryGeoMappingStatus::NEEDS_REVIEW,
				self::TECHNICAL_ERROR_GEO_ID,
				$region_like,
				$after_location_id,
				$limit
			)
		);

		return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : array();
	}

	private function contains_fragment( string $haystack, string $fragment ): bool {
		$haystack = trim( $haystack );
		$fragment = trim( $fragment );
		if ( '' === $haystack || '' === $fragment ) {
			return false;
		}
		if ( function_exists( 'mb_stripos' ) ) {
			return false !== mb_stripos( $haystack, $fragment );
		}

		return false !== stripos( $haystack, $fragment );
	}

	private function location_has_needs_review_candidates( int $location_id ): bool {
		if ( $location_id <= 0 ) {
			return false;
		}
		if ( $this->has_test_rows() ) {
			foreach ( $this->test_rows() as $row ) {
				if ( (int) ( $row['location_id'] ?? 0 ) === $location_id && YandexDeliveryGeoMappingStatus::NEEDS_REVIEW === (string) ( $row['status'] ?? '' ) ) {
					return true;
				}
			}

			return false;
		}
		$value = $this->wpdb->get_var( $this->wpdb->prepare( 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE location_id = %d AND status = %s', $location_id, YandexDeliveryGeoMappingStatus::NEEDS_REVIEW ) );

		return is_numeric( $value ) && (int) $value > 0;
	}

	private function save_cleanup_not_found( int $location_id, string $region_fragment ): void {
		$this->save_mapping(
			array(
				'location_id' => $location_id,
				'yandex_geo_id' => null,
				'status' => YandexDeliveryGeoMappingStatus::NOT_FOUND,
				'confidence' => 0,
				'is_primary' => 0,
				'raw_json' => $this->json(
					array(
						'manual_cleanup' => array(
							'action' => 'region_cleanup_not_found',
							'region_fragment' => $region_fragment,
							'cleaned_at' => $this->now(),
							'source' => 'admin',
						),
					)
				),
			)
		);
	}

	private function locations_table_name(): string {
		return $this->wpdb->prefix . 'wdc_locations';
	}
	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_delivery_geo_mappings';
	}

	private function has_test_rows(): bool {
		return property_exists( $this->wpdb, 'yandex_delivery_geo_mappings' ) && is_array( $this->wpdb->yandex_delivery_geo_mappings );
	}

	/** @return array<int,array<string,mixed>> */
	private function test_rows(): array {
		return $this->has_test_rows() ? $this->wpdb->yandex_delivery_geo_mappings : array();
	}

	private function can_create_schema(): bool {
		return defined( 'ABSPATH' ) && is_string( ABSPATH ) && '' !== ABSPATH && method_exists( $this->wpdb, 'get_charset_collate' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
	/** @param array<string,mixed> $value */
	private function json( array $value ): string {
		return ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE ) ) ?: '{}';
	}

	private function truncate( string $value, int $length ): string {
		$value = trim( $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
	}
}
