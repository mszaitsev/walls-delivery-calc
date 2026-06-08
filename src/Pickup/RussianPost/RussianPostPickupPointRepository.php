<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

defined( 'ABSPATH' ) || exit;

final class RussianPostPickupPointRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function create_schema_if_needed( string $table = '' ): void {
		$table = '' !== $table ? $this->sanitize_table_name( $table ) : $this->main_table();
		$this->wpdb->query( $this->schema_sql( $table ) );
	}

	public function schema_sql( string $table = '' ): string {
		$table = '' !== $table ? $this->sanitize_table_name( $table ) : $this->main_table();
		$charset = method_exists( $this->wpdb, 'get_charset_collate' ) ? $this->wpdb->get_charset_collate() : 'DEFAULT CHARSET=utf8mb4';

		return "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			point_code VARCHAR(128) NOT NULL,
			point_type VARCHAR(16) NOT NULL,
			postcode VARCHAR(16) NULL,
			country_code CHAR(2) NOT NULL DEFAULT 'RU',
			region_name VARCHAR(255) NULL,
			city_name VARCHAR(255) NULL,
			street VARCHAR(255) NULL,
			house VARCHAR(64) NULL,
			address TEXT NOT NULL,
			fias_location_guid VARCHAR(64) NULL,
			fias_address_guid VARCHAR(64) NULL,
			gar_region_id VARCHAR(64) NULL,
			location_id BIGINT UNSIGNED NULL,
			latitude DECIMAL(10,7) NOT NULL,
			longitude DECIMAL(10,7) NOT NULL,
			geohash VARCHAR(16) NULL,
			description TEXT NULL,
			work_time TEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			source_hash CHAR(40) NOT NULL,
			last_seen_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY uniq_point_code (point_code),
			KEY idx_type_active (point_type, active),
			KEY idx_city_active (city_name, active),
			KEY idx_postcode (postcode),
			KEY idx_location_id (location_id),
			KEY idx_lat_lng (latitude, longitude),
			KEY idx_geohash (geohash),
			KEY idx_source_hash (source_hash)
		) {$charset}";
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{inserted:int,updated:int,skipped:int}
	 */
	public function insert_batch( array $rows, string $table = '' ): array {
		$table = '' !== $table ? $this->sanitize_table_name( $table ) : $this->main_table();
		$stats = array( 'inserted' => 0, 'updated' => 0, 'skipped' => 0 );
		$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				++$stats['skipped'];
				continue;
			}
			$row = $this->normalize_row( $row, $now );
			if ( '' === (string) $row['point_code'] || '' === (string) $row['address'] || null === $row['latitude'] || null === $row['longitude'] || '' === (string) $row['source_hash'] ) {
				++$stats['skipped'];
				continue;
			}
			if ( $this->wpdb->insert( $table, $row, $this->formats( $row ) ) ) {
				++$stats['inserted'];
			} else {
				++$stats['skipped'];
			}
		}

		return $stats;
	}

	public function count_active( string $point_type = '' ): int {
		$where = array( 'active = 1' );
		$args = array();
		if ( '' !== trim( $point_type ) ) {
			$where[] = 'point_type = %s';
			$args[] = strtoupper( trim( $point_type ) );
		}
		$sql = 'SELECT COUNT(*) FROM ' . $this->main_table() . ' WHERE ' . implode( ' AND ', $where );
		if ( array() !== $args ) {
			$sql = $this->wpdb->prepare( $sql, ...$args );
		}

		return (int) $this->wpdb->get_var( $sql );
	}

	/**
	 * @return array<string,int>
	 */
	public function count_by_type(): array {
		$rows = $this->wpdb->get_results( 'SELECT point_type, COUNT(*) AS total FROM ' . $this->main_table() . ' WHERE active = 1 GROUP BY point_type', ARRAY_A );
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$type = strtoupper( trim( (string) ( $row['point_type'] ?? '' ) ) );
			if ( '' !== $type ) {
				$result[ $type ] = (int) ( $row['total'] ?? 0 );
			}
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function find_rows_by_bbox( float $min_lng, float $min_lat, float $max_lng, float $max_lat, array $filters = array() ): array {
		$where = array( 'active = 1', 'longitude BETWEEN %f AND %f', 'latitude BETWEEN %f AND %f' );
		$args = array( $min_lng, $max_lng, $min_lat, $max_lat );
		$this->append_filters( $where, $args, $filters );
		$limit = $this->limit_from_filters( $filters, 500, 1000 );

		return $this->select_rows( $where, $args, $limit );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function search_point_rows( string $query, array $filters = array() ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		$where = array( 'active = 1', '(point_code LIKE %s OR postcode LIKE %s OR city_name LIKE %s OR address LIKE %s)' );
		$like = '%' . $this->wpdb->esc_like( $query ) . '%';
		$args = array( $like, $like, $like, $like );
		if ( '' !== trim( (string) ( $filters['city'] ?? '' ) ) ) {
			$where[] = 'city_name LIKE %s';
			$args[] = '%' . $this->wpdb->esc_like( trim( (string) $filters['city'] ) ) . '%';
			unset( $filters['city'] );
		}
		$this->append_filters( $where, $args, $filters );
		$limit = $this->limit_from_filters( $filters, 50, 100 );

		return $this->select_rows( $where, $args, $limit );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function search_admin_pickup_rows( string $query, array $filters = array() ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}
		$limit = $this->limit_from_filters( $filters, 50, 100 );

		if ( property_exists( $this->wpdb, 'russian_post_pickup_rows' ) && is_array( $this->wpdb->russian_post_pickup_rows ) ) {
			$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query );
			$rows = array_values(
				array_filter(
					$this->wpdb->russian_post_pickup_rows,
					static function ( array $row ) use ( $needle ): bool {
						if ( 1 !== (int) ( $row['active'] ?? 1 ) ) {
							return false;
						}
						foreach ( array( 'postcode', 'city_name', 'address' ) as $key ) {
							$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) ( $row[ $key ] ?? '' ) ) : strtolower( (string) ( $row[ $key ] ?? '' ) );
							if ( '' !== $needle && false !== strpos( $value, $needle ) ) {
								return true;
							}
						}

						return false;
					}
				)
			);
			usort(
				$rows,
				static fn( array $a, array $b ): int => strcmp( (string) ( $a['city_name'] ?? '' ) . (string) ( $a['address'] ?? '' ), (string) ( $b['city_name'] ?? '' ) . (string) ( $b['address'] ?? '' ) )
			);

			return array_slice( $rows, 0, $limit );
		}

		$where = array( 'active = 1', '(postcode LIKE %s OR city_name LIKE %s OR address LIKE %s)' );
		$like = '%' . $this->wpdb->esc_like( $query ) . '%';
		$args = array( $like, $like, $like );

		return $this->select_rows( $where, $args, $limit );
	}

	/**
	 * @param array<string,mixed> $context
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function find_rows_by_location_context( array $context, array $filters = array() ): array {
		$limit = $this->limit_from_filters( $filters, 300, 500 );
		$match = (string) ( $filters['match'] ?? 'city_region' );
		$city_candidates = $this->location_city_candidates( $context );
		$region = trim( (string) ( $context['region_name'] ?? $context['state_value'] ?? '' ) );
		$fias = trim( (string) ( $context['fias_id'] ?? '' ) );
		$gar = trim( (string) ( $context['gar_object_id'] ?? $context['gar_id'] ?? '' ) );
		$location_id = max( 0, (int) ( $context['location_id'] ?? $context['id'] ?? 0 ) );

		if ( property_exists( $this->wpdb, 'russian_post_pickup_rows' ) && is_array( $this->wpdb->russian_post_pickup_rows ) ) {
			$rows = array_values(
				array_filter(
					$this->wpdb->russian_post_pickup_rows,
					function ( array $row ) use ( $match, $city_candidates, $region, $fias, $gar, $location_id ): bool {
						if ( 1 !== (int) ( $row['active'] ?? 1 ) ) {
							return false;
						}
						if ( 'ids' === $match ) {
							return ( $location_id > 0 && $location_id === (int) ( $row['location_id'] ?? 0 ) )
								|| ( '' !== $fias && $this->same_text( $fias, (string) ( $row['fias_location_guid'] ?? '' ) ) )
								|| ( '' !== $gar && $this->same_text( $gar, (string) ( $row['gar_region_id'] ?? '' ) ) );
						}

						$row_city = (string) ( $row['city_name'] ?? '' );
						$city_ok = array() === $city_candidates || $this->matches_any_city( $row_city, $city_candidates );
						if ( ! $city_ok ) {
							return false;
						}
						if ( 'city_region' !== $match || '' === $region ) {
							return true;
						}

						return $this->contains_text( (string) ( $row['region_name'] ?? '' ), $region ) || $this->contains_text( $region, (string) ( $row['region_name'] ?? '' ) );
					}
				)
			);
			usort(
				$rows,
				static fn( array $a, array $b ): int => strcmp( (string) ( $a['city_name'] ?? '' ) . (string) ( $a['address'] ?? '' ), (string) ( $b['city_name'] ?? '' ) . (string) ( $b['address'] ?? '' ) )
			);

			return array_slice( $rows, 0, $limit );
		}

		$where = array( 'active = 1' );
		$args = array();
		if ( 'ids' === $match ) {
			$id_where = array();
			if ( $location_id > 0 ) {
				$id_where[] = 'location_id = %d';
				$args[] = $location_id;
			}
			if ( '' !== $fias ) {
				$id_where[] = 'fias_location_guid = %s';
				$args[] = $fias;
			}
			if ( '' !== $gar ) {
				$id_where[] = 'gar_region_id = %s';
				$args[] = $gar;
			}
			if ( array() === $id_where ) {
				return array();
			}
			$where[] = '(' . implode( ' OR ', $id_where ) . ')';
		} else {
			if ( array() === $city_candidates ) {
				return array();
			}
			$city_where = array();
			foreach ( $city_candidates as $city ) {
				$city_where[] = 'city_name LIKE %s';
				$args[] = '%' . $this->wpdb->esc_like( $city ) . '%';
			}
			$where[] = '(' . implode( ' OR ', $city_where ) . ')';
			if ( 'city_region' === $match && '' !== $region ) {
				$where[] = 'region_name LIKE %s';
				$args[] = '%' . $this->wpdb->esc_like( $region ) . '%';
			}
		}
		$this->append_filters( $where, $args, $filters );

		return $this->select_rows( $where, $args, $limit );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function find_rows_by_postcode( string $postcode, array $filters = array() ): array {
		$postcode = preg_replace( '/\D+/', '', $postcode ) ?? '';
		if ( '' === $postcode ) {
			return array();
		}
		$where = array( 'active = 1', 'postcode = %s' );
		$args = array( $postcode );
		$this->append_filters( $where, $args, $filters );
		$limit = $this->limit_from_filters( $filters, 50, 100 );

		return $this->select_rows( $where, $args, $limit );
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function find_nearest_rows( float $lat, float $lng, array $filters = array() ): array {
		if ( $lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0 ) {
			return array();
		}
		$where = array( 'active = 1' );
		$args = array();
		$this->append_filters( $where, $args, $filters );
		$limit = $this->limit_from_filters( $filters, 50, 100 );
		$rows = $this->select_rows( $where, $args, 1000 );
		foreach ( $rows as &$row ) {
			$row['distance_meters'] = $this->distance_meters( $lat, $lng, (float) ( $row['latitude'] ?? 0 ), (float) ( $row['longitude'] ?? 0 ) );
		}
		unset( $row );
		usort(
			$rows,
			static fn( array $a, array $b ): int => (int) ( $a['distance_meters'] ?? PHP_INT_MAX ) <=> (int) ( $b['distance_meters'] ?? PHP_INT_MAX )
				?: strcmp( (string) ( $a['postcode'] ?? '' ) . (string) ( $a['address'] ?? '' ), (string) ( $b['postcode'] ?? '' ) . (string) ( $b['address'] ?? '' ) )
		);

		return array_slice( $rows, 0, $limit );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find_row_by_id( int $id ): ?array {
		if ( $id <= 0 ) {
			return null;
		}
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->main_table() . ' WHERE id = %d LIMIT 1', $id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function find_row_by_point_code( string $point_code ): ?array {
		$point_code = trim( $point_code );
		if ( '' === $point_code ) {
			return null;
		}

		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->main_table() . ' WHERE point_code = %s LIMIT 1', $point_code ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	public function main_table(): string {
		return $this->wpdb->prefix . 'wdc_pickup_points_russian_post';
	}

	public function staging_table( string $import_id ): string {
		return $this->wpdb->prefix . 'wdc_pickup_points_russian_post_staging_' . substr( preg_replace( '/[^a-z0-9]/', '', strtolower( $import_id ) ) ?? '', 0, 12 );
	}

	public function backup_table( string $import_id ): string {
		return $this->wpdb->prefix . 'wdc_pickup_points_russian_post_backup_' . substr( preg_replace( '/[^a-z0-9]/', '', strtolower( $import_id ) ) ?? '', 0, 12 );
	}

	public function drop_table( string $table ): void {
		$table = $this->sanitize_table_name( $table );
		if ( '' === $table ) {
			return;
		}
		$this->wpdb->query( 'DROP TABLE IF EXISTS ' . $table );
	}

	public function table_exists( string $table ): bool {
		$table = $this->sanitize_table_name( $table );
		if ( '' === $table ) {
			return false;
		}
		$found = $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return (string) $found === $table;
	}

	/**
	 * @return array{success:bool,message:string,recovered:bool}
	 */
	public function swap_staging_to_main( string $staging_table, string $backup_table ): array {
		$staging_table = $this->sanitize_table_name( $staging_table );
		$backup_table = $this->sanitize_table_name( $backup_table );
		$main_table = $this->main_table();
		if ( ! $this->table_exists( $staging_table ) ) {
			return array( 'success' => false, 'message' => 'Russian Post pickup staging table does not exist.', 'recovered' => false );
		}
		$this->drop_table( $backup_table );
		$main_exists = $this->table_exists( $main_table );
		$sql = $main_exists ? "RENAME TABLE {$main_table} TO {$backup_table}, {$staging_table} TO {$main_table}" : "RENAME TABLE {$staging_table} TO {$main_table}";
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) {
			return array( 'success' => false, 'message' => 'Russian Post pickup table rename failed.', 'recovered' => false );
		}
		if ( $this->table_exists( $main_table ) ) {
			$this->drop_table( $backup_table );

			return array( 'success' => true, 'message' => 'Russian Post pickup staging table was swapped into main table.', 'recovered' => false );
		}
		if ( $main_exists && $this->table_exists( $backup_table ) ) {
			$recovery = $this->wpdb->query( "RENAME TABLE {$backup_table} TO {$main_table}" );
			if ( false !== $recovery && $this->table_exists( $main_table ) ) {
				return array( 'success' => false, 'message' => 'Russian Post pickup table swap failed after backup rename; main table was recovered from backup.', 'recovered' => true );
			}

			return array( 'success' => false, 'message' => 'Russian Post pickup table swap failed and backup recovery failed; backup table was kept.', 'recovered' => false );
		}

		return array( 'success' => false, 'message' => 'Russian Post pickup table swap finished without a main table.', 'recovered' => false );
	}

	public function analyze_main_table(): bool {
		return false !== $this->wpdb->query( 'ANALYZE TABLE ' . $this->main_table() );
	}

	private function select_rows( array $where, array $args, int $limit ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				'SELECT * FROM ' . $this->main_table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY city_name ASC, address ASC LIMIT %d',
				...array_merge( $args, array( $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	private function distance_meters( float $from_lat, float $from_lng, float $to_lat, float $to_lng ): int {
		$earth = 6371000;
		$d_lat = deg2rad( $to_lat - $from_lat );
		$d_lng = deg2rad( $to_lng - $from_lng );
		$a = sin( $d_lat / 2 ) ** 2 + cos( deg2rad( $from_lat ) ) * cos( deg2rad( $to_lat ) ) * sin( $d_lng / 2 ) ** 2;

		return (int) round( $earth * 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) ) );
	}

	private function append_filters( array &$where, array &$args, array $filters ): void {
		$types = $filters['point_types'] ?? $filters['type'] ?? $filters['point_type'] ?? array();
		$types = is_array( $types ) ? $types : array( $types );
		$types = array_values( array_filter( array_map( static fn( mixed $type ): string => strtoupper( trim( (string) $type ) ), $types ), static fn( string $type ): bool => in_array( $type, array( 'OPS', 'PVZ', 'APS' ), true ) ) );
		if ( array() !== $types ) {
			$where[] = 'point_type IN (' . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ')';
			array_push( $args, ...$types );
		}
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<int,string>
	 */
	private function location_city_candidates( array $context ): array {
		$values = array(
			(string) ( $context['city_value'] ?? '' ),
			(string) ( $context['city_name'] ?? '' ),
			(string) ( $context['place_name'] ?? '' ),
			(string) ( $context['settlement_name'] ?? '' ),
			(string) ( $context['display_name'] ?? '' ),
			(string) ( $context['label'] ?? '' ),
			(string) ( $context['option_label'] ?? '' ),
		);
		$result = array();
		foreach ( $values as $value ) {
			foreach ( preg_split( '/,/', $value ) ?: array() as $part ) {
				$part = trim( preg_replace( '/\s+/', ' ', (string) $part ) ?? '' );
				$part = preg_replace( '/^(г|город|поселок|посёлок|п|село|с|д|деревня)\.?\s+/iu', '', $part ) ?? $part;
				if ( '' !== $part && ! in_array( $part, $result, true ) ) {
					$result[] = $part;
				}
			}
		}

		return $result;
	}

	/**
	 * @param array<int,string> $candidates
	 */
	private function matches_any_city( string $city, array $candidates ): bool {
		foreach ( $candidates as $candidate ) {
			if ( $this->contains_text( $city, $candidate ) || $this->contains_text( $candidate, $city ) ) {
				return true;
			}
		}

		return false;
	}

	private function contains_text( string $haystack, string $needle ): bool {
		$haystack = $this->normalized_text( $haystack );
		$needle = $this->normalized_text( $needle );
		return '' !== $haystack && '' !== $needle && false !== strpos( $haystack, $needle );
	}

	private function same_text( string $left, string $right ): bool {
		return '' !== $left && '' !== $right && $this->normalized_text( $left ) === $this->normalized_text( $right );
	}

	private function normalized_text( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? '' );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	private function limit_from_filters( array $filters, int $default, int $max ): int {
		$limit = (int) ( $filters['limit'] ?? $default );

		return max( 1, min( $max, $limit > 0 ? $limit : $default ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function normalize_row( array $row, string $now ): array {
		$allowed = array(
			'point_code' => '',
			'point_type' => 'OPS',
			'postcode' => null,
			'country_code' => 'RU',
			'region_name' => null,
			'city_name' => null,
			'street' => null,
			'house' => null,
			'address' => '',
			'fias_location_guid' => null,
			'fias_address_guid' => null,
			'gar_region_id' => null,
			'location_id' => null,
			'latitude' => null,
			'longitude' => null,
			'geohash' => null,
			'description' => null,
			'work_time' => null,
			'active' => 1,
			'source_hash' => '',
			'last_seen_at' => $now,
			'created_at' => $now,
			'updated_at' => $now,
		);
		$row = array_intersect_key( $row, $allowed ) + $allowed;
		$row['point_type'] = strtoupper( trim( (string) $row['point_type'] ) );
		$row['active'] = ! empty( $row['active'] ) ? 1 : 0;
		$row['location_id'] = null === $row['location_id'] || '' === $row['location_id'] ? null : max( 0, (int) $row['location_id'] );
		$row['latitude'] = null === $row['latitude'] || '' === $row['latitude'] ? null : (float) $row['latitude'];
		$row['longitude'] = null === $row['longitude'] || '' === $row['longitude'] ? null : (float) $row['longitude'];

		return $row;
	}

	private function formats( array $row ): array {
		$formats = array(
			'point_code' => '%s', 'point_type' => '%s', 'postcode' => '%s', 'country_code' => '%s',
			'region_name' => '%s', 'city_name' => '%s', 'street' => '%s', 'house' => '%s', 'address' => '%s',
			'fias_location_guid' => '%s', 'fias_address_guid' => '%s', 'gar_region_id' => '%s',
			'location_id' => '%d', 'latitude' => '%f', 'longitude' => '%f', 'geohash' => '%s', 'description' => '%s',
			'work_time' => '%s',
			'active' => '%d', 'source_hash' => '%s', 'last_seen_at' => '%s',
			'created_at' => '%s', 'updated_at' => '%s',
		);

		return array_values( array_intersect_key( $formats, $row ) );
	}

	private function sanitize_table_name( string $table ): string {
		return preg_replace( '/[^A-Za-z0-9_]/', '', $table ) ?? '';
	}
}
