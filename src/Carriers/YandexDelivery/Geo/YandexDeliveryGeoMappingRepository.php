<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoMappingRepository {
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
				yandex_geo_id bigint(20) unsigned NOT NULL,
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
		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->yandex_delivery_geo_mappings as $index => $existing ) {
				if ( (int) ( $existing['location_id'] ?? 0 ) === $row['location_id'] && (int) ( $existing['yandex_geo_id'] ?? 0 ) === $row['yandex_geo_id'] ) {
					$row['id'] = (int) ( $existing['id'] ?? ( $index + 1 ) );
					$row['created_at'] = (string) ( $existing['created_at'] ?? $row['created_at'] );
					$this->wpdb->yandex_delivery_geo_mappings[ $index ] = array_merge( $existing, $row );
					return $this->wpdb->yandex_delivery_geo_mappings[ $index ];
				}
			}
			$row['id'] = count( $this->wpdb->yandex_delivery_geo_mappings ) + 1;
			$this->wpdb->yandex_delivery_geo_mappings[] = $row;
			return $row;
		}

		$this->wpdb->insert( $this->table_name(), $row, array( '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%d', '%s', '%s', '%s' ) );
		$row['id'] = (int) ( $this->wpdb->insert_id ?? 0 );

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
			if ( ! empty( $row['is_primary'] ) && (int) ( $row['yandex_geo_id'] ?? 0 ) > 0 ) {
				return (int) $row['yandex_geo_id'];
			}
		}

		return null;
	}

	public function set_primary( int $location_id, int $yandex_geo_id ): bool {
		if ( $location_id <= 0 || $yandex_geo_id <= 0 ) {
			return false;
		}
		$this->create_schema_if_needed();
		if ( $this->has_test_rows() ) {
			$found = false;
			foreach ( $this->wpdb->yandex_delivery_geo_mappings as $index => $row ) {
				if ( (int) ( $row['location_id'] ?? 0 ) !== $location_id ) {
					continue;
				}
				$is_target = (int) ( $row['yandex_geo_id'] ?? 0 ) === $yandex_geo_id;
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
		foreach ( array( 'location_id' => '%d', 'yandex_geo_id' => '%d', 'status' => '%s', 'is_primary' => '%d' ) as $key => $format ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] ) {
				$where[] = "{$key} = {$format}";
				$args[] = '%d' === $format ? (int) $filters[ $key ] : trim( (string) $filters[ $key ] );
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
		return array(
			'location_id' => max( 0, (int) ( $mapping['location_id'] ?? 0 ) ),
			'yandex_geo_id' => max( 0, (int) ( $mapping['yandex_geo_id'] ?? 0 ) ),
			'yandex_locality' => $this->nullable_string( $mapping['yandex_locality'] ?? null, 255 ),
			'yandex_region' => $this->nullable_string( $mapping['yandex_region'] ?? null, 255 ),
			'source_query' => $this->nullable_string( $mapping['source_query'] ?? null, 255 ),
			'status' => YandexDeliveryGeoMappingStatus::normalize( (string) ( $mapping['status'] ?? YandexDeliveryGeoMappingStatus::ERROR ) ),
			'confidence' => is_numeric( $mapping['confidence'] ?? null ) ? round( max( 0.0, min( 100.0, (float) $mapping['confidence'] ) ), 2 ) : null,
			'is_primary' => empty( $mapping['is_primary'] ) ? 0 : 1,
			'raw_json' => $this->nullable_string( $mapping['raw_json'] ?? null, 0 ),
			'created_at' => $this->nullable_string( $mapping['created_at'] ?? $now, 32 ),
			'updated_at' => $this->nullable_string( $mapping['updated_at'] ?? $now, 32 ),
		);
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
		foreach ( array( 'location_id', 'yandex_geo_id', 'is_primary' ) as $key ) {
			if ( isset( $filters[ $key ] ) && '' !== (string) $filters[ $key ] && (int) ( $row[ $key ] ?? 0 ) !== (int) $filters[ $key ] ) {
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
}