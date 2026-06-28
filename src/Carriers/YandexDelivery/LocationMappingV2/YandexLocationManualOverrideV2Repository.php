<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

defined( 'ABSPATH' ) || exit;

final class YandexLocationManualOverrideV2Repository {
	private object $wpdb;
	private YandexLocationMappingV2NameNormalizer $normalizer;

	public function __construct( ?object $wpdb = null, ?YandexLocationMappingV2NameNormalizer $normalizer = null ) {
		$db = $wpdb;
		if ( null === $db ) {
			global $wpdb;
			$db = $wpdb;
		}
		$this->wpdb = $db;
		$this->normalizer = $normalizer ?? new YandexLocationMappingV2NameNormalizer();
	}

	public function schema(): string {
		$charset = method_exists( $this->wpdb, 'get_charset_collate' ) ? $this->wpdb->get_charset_collate() : '';
		$table = $this->table_name();

		return "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			yandex_geo_id bigint(20) unsigned NOT NULL,
			yandex_region varchar(255) NOT NULL,
			yandex_region_norm varchar(255) NOT NULL,
			yandex_locality varchar(255) NOT NULL,
			yandex_locality_norm varchar(255) NOT NULL,
			location_id bigint(20) unsigned NOT NULL,
			wdc_region_name varchar(255) NOT NULL DEFAULT '',
			wdc_display_name text NULL,
			status varchar(32) NOT NULL DEFAULT 'active',
			note text NULL,
			created_by bigint(20) unsigned NULL,
			updated_by bigint(20) unsigned NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY yandex_geo_id (yandex_geo_id),
			KEY yandex_identity (yandex_region_norm, yandex_locality_norm),
			KEY location_id (location_id),
			KEY status (status)
		) {$charset};";
	}

	public function create_schema_if_needed(): void {
		if ( ! $this->can_create_schema() ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema() );
	}

	/** @return array<int,array<string,mixed>> */
	public function find_active_for_geo_identity( int $yandex_geo_id, string $yandex_region, string $yandex_locality ): array {
		$region_norm = $this->normalize_region( $yandex_region );
		$locality_norm = $this->normalize_locality( $yandex_locality );
		if ( $yandex_geo_id <= 0 || '' === $region_norm || '' === $locality_norm ) {
			return array();
		}
		return $this->find_active_rows( array( 'yandex_geo_id' => $yandex_geo_id, 'yandex_region_norm' => $region_norm, 'yandex_locality_norm' => $locality_norm ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function find_active_for_identity( string $yandex_region, string $yandex_locality ): array {
		$region_norm = $this->normalize_region( $yandex_region );
		$locality_norm = $this->normalize_locality( $yandex_locality );
		if ( '' === $region_norm || '' === $locality_norm ) {
			return array();
		}
		return $this->find_active_rows( array( 'yandex_region_norm' => $region_norm, 'yandex_locality_norm' => $locality_norm ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function find_active_for_geo_id( int $yandex_geo_id ): array {
		if ( $yandex_geo_id <= 0 ) {
			return array();
		}
		return $this->find_active_rows( array( 'yandex_geo_id' => $yandex_geo_id ) );
	}
	/** @return array{by_geo_id:array<int,array<int,array<string,mixed>>>,by_identity:array<string,array<int,array<string,mixed>>>,ambiguous_identity_keys:array<string,bool>,rows:array<int,array<string,mixed>>} */
	public function load_active_overrides_cache(): array {
		$rows = $this->active_rows_for_cache();
		$cache = array( 'by_geo_id' => array(), 'by_identity' => array(), 'ambiguous_identity_keys' => array(), 'rows' => $rows );
		foreach ( $rows as $row ) {
			$geo_id = (int) ( $row['yandex_geo_id'] ?? 0 );
			if ( $geo_id > 0 ) {
				$cache['by_geo_id'][ $geo_id ][] = $row;
			}
			$key = $this->identity_key_from_norms( (string) ( $row['yandex_region_norm'] ?? '' ), (string) ( $row['yandex_locality_norm'] ?? '' ) );
			if ( '' !== $key ) {
				$cache['by_identity'][ $key ][] = $row;
			}
		}
		foreach ( $cache['by_identity'] as $key => $identity_rows ) {
			if ( count( $identity_rows ) > 1 ) {
				$cache['ambiguous_identity_keys'][ $key ] = true;
			}
		}
		return $cache;
	}


	/** @return array{saved:int,id:int} */
	public function upsert_active_override( int $yandex_geo_id, string $yandex_region, string $yandex_locality, int $location_id, string $note = '' ): array {
		$this->create_schema_if_needed();
		$yandex_region = trim( $yandex_region );
		$yandex_locality = trim( $yandex_locality );
		$region_norm = $this->normalize_region( $yandex_region );
		$locality_norm = $this->normalize_locality( $yandex_locality );
		if ( $yandex_geo_id <= 0 || $location_id <= 0 || '' === $region_norm || '' === $locality_norm ) {
			return array( 'saved' => 0, 'id' => 0 );
		}
		$location = $this->find_location( $location_id );
		$now = $this->now();
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : null;
		$row = array(
			'yandex_geo_id' => $yandex_geo_id,
			'yandex_region' => $yandex_region,
			'yandex_region_norm' => $region_norm,
			'yandex_locality' => $yandex_locality,
			'yandex_locality_norm' => $locality_norm,
			'location_id' => $location_id,
			'wdc_region_name' => (string) ( $location['region_name'] ?? '' ),
			'wdc_display_name' => $this->location_display_name( $location ),
			'status' => 'active',
			'note' => $note,
			'created_by' => $user_id,
			'updated_by' => $user_id,
			'created_at' => $now,
			'updated_at' => $now,
		);
		$this->deactivate_active_identity( $region_norm, $locality_norm );

		if ( $this->has_test_rows() ) {
			$row['id'] = count( $this->wpdb->yandex_location_manual_overrides_v2 ) + 1;
			$this->wpdb->yandex_location_manual_overrides_v2[] = $row;
			return array( 'saved' => 1, 'id' => (int) $row['id'] );
		}
		$columns = array( 'yandex_geo_id', 'yandex_region', 'yandex_region_norm', 'yandex_locality', 'yandex_locality_norm', 'location_id', 'wdc_region_name', 'wdc_display_name', 'status', 'note', 'created_by', 'updated_by', 'created_at', 'updated_at' );
		$values = array_map( fn( string $column ): string => $this->sql_literal( $row[ $column ] ?? null, in_array( $column, array( 'yandex_geo_id', 'location_id', 'created_by', 'updated_by' ), true ) ? 'int' : 'string' ), $columns );
		$ok = false !== $this->wpdb->query( sprintf( 'INSERT INTO %s (%s) VALUES (%s)', $this->table_name(), implode( ',', $columns ), implode( ',', $values ) ) );
		$id = property_exists( $this->wpdb, 'insert_id' ) ? (int) $this->wpdb->insert_id : 0;
		return array( 'saved' => $ok ? 1 : 0, 'id' => $id );
	}

	public function deactivate_override( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}
		$now = $this->now();
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : null;
		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->yandex_location_manual_overrides_v2 as $index => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $id ) {
					$this->wpdb->yandex_location_manual_overrides_v2[ $index ]['status'] = 'inactive';
					$this->wpdb->yandex_location_manual_overrides_v2[ $index ]['updated_at'] = $now;
					$this->wpdb->yandex_location_manual_overrides_v2[ $index ]['updated_by'] = $user_id;
					return true;
				}
			}
			return false;
		}
		$this->create_schema_if_needed();
		return false !== $this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name() . ' SET status = %s, updated_at = %s, updated_by = %d WHERE id = %d', 'inactive', $now, (int) $user_id, $id ) );
	}

	/** @return array<int,array<string,mixed>> */
	public function list_active( int $limit = 100 ): array {
		$limit = max( 1, min( 500, $limit ) );
		if ( $this->has_test_rows() ) {
			$rows = array_values( array_filter( $this->wpdb->yandex_location_manual_overrides_v2, static fn( array $row ): bool => 'active' === (string) ( $row['status'] ?? '' ) ) );
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) ) );
			return array_slice( $rows, 0, $limit );
		}
		$this->create_schema_if_needed();
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE status = %s ORDER BY updated_at DESC, id DESC LIMIT %d', 'active', $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	public function normalize_region( string $region ): string {
		$value = str_replace( array( 'ё', '—', '–' ), array( 'е', '-', '-' ), mb_strtolower( trim( $region ), 'UTF-8' ) );
		$value = preg_replace( '/[«»"\'`,.()]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		return trim( $value );
	}

	public function normalize_locality( string $locality ): string {
		return $this->normalizer->normalize_place( $locality );
	}

	/** @return array<int,array<string,mixed>> */
	private function active_rows_for_cache(): array {
		if ( $this->has_test_rows() ) {
			$rows = array_values( array_filter( $this->wpdb->yandex_location_manual_overrides_v2, static fn( array $row ): bool => 'active' === (string) ( $row['status'] ?? '' ) ) );
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $b['updated_at'] ?? '' ), (string) ( $a['updated_at'] ?? '' ) ) ?: (int) ( $b['id'] ?? 0 ) <=> (int) ( $a['id'] ?? 0 ) );
			return $rows;
		}
		$this->create_schema_if_needed();
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE status = %s ORDER BY updated_at DESC, id DESC', 'active' ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private function identity_key_from_norms( string $region_norm, string $locality_norm ): string {
		$region_norm = trim( $region_norm );
		$locality_norm = trim( $locality_norm );
		if ( '' === $region_norm || '' === $locality_norm ) {
			return '';
		}
		return $region_norm . '|' . $locality_norm;
	}

	/** @param array<string,mixed> $filters @return array<int,array<string,mixed>> */
	private function find_active_rows( array $filters ): array {
		if ( $this->has_test_rows() ) {
			return array_values( array_filter( $this->wpdb->yandex_location_manual_overrides_v2, static function ( array $row ) use ( $filters ): bool {
				if ( 'active' !== (string) ( $row['status'] ?? '' ) ) {
					return false;
				}
				foreach ( $filters as $key => $value ) {
					if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
						return false;
					}
				}
				return true;
			} ) );
		}
		$this->create_schema_if_needed();
		$where = array( 'status = %s' );
		$params = array( 'active' );
		foreach ( $filters as $key => $value ) {
			$where[] = $key . ' = ' . ( 'yandex_geo_id' === $key ? '%d' : '%s' );
			$params[] = 'yandex_geo_id' === $key ? (int) $value : (string) $value;
		}
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY updated_at DESC, id DESC', ...$params ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	private function deactivate_active_identity( string $region_norm, string $locality_norm ): void {
		$now = $this->now();
		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->yandex_location_manual_overrides_v2 as $index => $row ) {
				if ( 'active' === (string) ( $row['status'] ?? '' ) && (string) ( $row['yandex_region_norm'] ?? '' ) === $region_norm && (string) ( $row['yandex_locality_norm'] ?? '' ) === $locality_norm ) {
					$this->wpdb->yandex_location_manual_overrides_v2[ $index ]['status'] = 'inactive';
					$this->wpdb->yandex_location_manual_overrides_v2[ $index ]['updated_at'] = $now;
				}
			}
			return;
		}
		$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name() . ' SET status = %s, updated_at = %s WHERE status = %s AND yandex_region_norm = %s AND yandex_locality_norm = %s', 'inactive', $now, 'active', $region_norm, $locality_norm ) );
	}

	/** @return array<string,mixed> */
	private function find_location( int $location_id ): array {
		if ( property_exists( $this->wpdb, 'wdc_locations' ) && is_array( $this->wpdb->wdc_locations ) ) {
			foreach ( $this->wpdb->wdc_locations as $location ) {
				if ( (int) ( $location['id'] ?? 0 ) === $location_id ) {
					return $location;
				}
			}
			return array();
		}
		$row = $this->wpdb->get_row( $this->wpdb->prepare( 'SELECT * FROM ' . $this->locations_table_name() . ' WHERE id = %d LIMIT 1', $location_id ), ARRAY_A );
		return is_array( $row ) ? $row : array();
	}

	/** @param array<string,mixed> $location */
	private function location_display_name( array $location ): string {
		$display = trim( (string) ( $location['display_name'] ?? '' ) );
		if ( '' !== $display ) {
			return $display;
		}
		return trim( implode( ' ', array_filter( array_map( 'strval', array( $location['region_name'] ?? '', $location['city_name'] ?? '', $location['settlement_name'] ?? '', $location['place_name'] ?? '' ) ) ) ) );
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

	private function has_test_rows(): bool {
		return property_exists( $this->wpdb, 'yandex_location_manual_overrides_v2' ) && is_array( $this->wpdb->yandex_location_manual_overrides_v2 );
	}

	private function can_create_schema(): bool {
		return defined( 'ABSPATH' ) && is_string( ABSPATH ) && '' !== ABSPATH && method_exists( $this->wpdb, 'get_charset_collate' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_location_manual_overrides_v2';
	}

	private function locations_table_name(): string {
		return $this->wpdb->prefix . 'wdc_locations';
	}
}
