<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Storage;

use RuntimeException;
use WallsShop\WDC\Locations\Postcodes\RussianPostCourierCalcPostcodeFillStateService;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class LocationRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function save( Location $location ): int {
		$now  = current_time( 'mysql' );
		$data = $this->location_to_row( $location, $now );
		$country_changed = true;

		if ( null !== $location->id && $location->id > 0 ) {
			$existing = $this->find_by_id( $location->id );
			$country_changed = ! $existing instanceof Location || $this->normalize_country_code( $existing->country_code ) !== $this->normalize_country_code( $location->country_code );
			unset( $data['created_at'] );
			$result = $this->wpdb->update( $this->table_name(), $data, array( 'id' => $location->id ), $this->formats( false ), array( '%d' ) );
			if ( false === $result ) {
				$this->throw_sql_error( 'Location update failed' );
			}
			if ( $country_changed ) {
				$this->mark_country_index_stale();
			}
			return $location->id;
		}

		$existing = $location->gar_object_id > 0 ? $this->find_by_gar_object_id( $location->gar_object_id ) : null;
		if ( ! $existing instanceof Location && '' !== trim( $location->fias_id ) ) {
			$existing = $this->find_by_fias_id( $location->fias_id );
		}

		if ( $existing instanceof Location && null !== $existing->id ) {
			$country_changed = $this->normalize_country_code( $existing->country_code ) !== $this->normalize_country_code( $location->country_code );
			unset( $data['created_at'] );
			$result = $this->wpdb->update( $this->table_name(), $data, array( 'id' => $existing->id ), $this->formats( false ), array( '%d' ) );
			if ( false === $result ) {
				$this->throw_sql_error( 'Location update failed' );
			}
			if ( $country_changed ) {
				$this->mark_country_index_stale();
			}
			return $existing->id;
		}

		$result = $this->wpdb->insert( $this->table_name(), $data, $this->formats() );
		if ( false === $result ) {
			$this->throw_sql_error( 'Location insert failed' );
		}
		if ( (int) $this->wpdb->insert_id <= 0 ) {
			throw new RuntimeException( 'Location insert failed: insert_id is missing after successful insert' );
		}
		$this->mark_country_index_stale();

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @param array<int, Location> $locations
	 */
	public function bulk_upsert( array $locations ): int {
		return (int) $this->bulk_upsert_locations( $locations )['count'];
	}

	/**
	 * @param array<int, Location> $locations
	 * @return array{count:int, ids:array<int,int>}
	 */
	public function bulk_upsert_locations( array $locations ): array {
		$locations = array_values( array_filter( $locations, static fn( mixed $location ): bool => $location instanceof Location ) );
		if ( array() === $locations ) {
			return array( 'count' => 0, 'ids' => array() );
		}

		// Test doubles used by smoke tests expose in-memory row storage instead of SQL execution.
		if ( $this->has_test_location_rows() ) {
			$ids = array();
			foreach ( $locations as $location ) {
				$id = $this->save( $location );
				$ids[ $location->gar_object_id ] = $id;
			}

			return array( 'count' => count( $locations ), 'ids' => $ids );
		}

		$now = current_time( 'mysql' );
		$rows = array_map( fn( Location $location ): array => $this->location_to_row( $location, $now ), $locations );
		$this->bulk_insert_rows(
			$this->table_name(),
			$rows,
			array_map( fn( string $column ): string => "{$column} = VALUES({$column})", array_diff( array_keys( $rows[0] ), array( 'created_at' ) ) ),
			$this->formats_for_row( $rows[0] )
		);
		$this->mark_country_index_stale();

		return array(
			'count' => count( $locations ),
			'ids'   => $this->location_ids_by_gar_object_ids( array_map( static fn( Location $location ): int => $location->gar_object_id, $locations ) ),
		);
	}

	/**
	 * @param array<int, Location> $locations
	 */
	public function bulk_insert( array $locations ): void {
		$this->bulk_upsert( $locations );
	}

	public function find_by_id( int $id ): ?Location {
		return $this->find_one( 'id', $id, '%d' );
	}

	/**
	 * @param array<int,int> $ids
	 * @return array<int,Location>
	 */
	public function find_locations_by_ids( array $ids ): array {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $ids ),
					static fn( int $id ): bool => $id > 0
				)
			)
		);
		sort( $ids, SORT_NUMERIC );
		if ( array() === $ids ) {
			return array();
		}

		if ( $this->has_test_location_rows() ) {
			$rows = array_values(
				array_filter(
					$this->test_location_rows(),
					static fn( array $row ): bool => in_array( (int) ( $row['id'] ?? 0 ), $ids, true )
				)
			);
			usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );

			return $this->rows_to_locations( array_map( fn( array $row ): array => $this->join_region_for_test_double( $row ), $rows ) );
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$sql = $this->wpdb->prepare(
			"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
			FROM {$this->table_name()} l
			LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
			WHERE l.id IN ({$placeholders})
			ORDER BY l.id ASC",
			...$ids
		);
		if ( ! is_string( $sql ) || '' === trim( $sql ) ) {
			throw new RuntimeException( 'Location lookup by ids failed: SQL preparation returned an invalid result' );
		}

		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== (string) $this->wpdb->last_error ) {
			$this->throw_sql_error( 'Location lookup by ids failed' );
		}
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'Location lookup by ids failed: invalid SQL result' );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( 'Location lookup by ids failed: invalid row structure' );
			}
		}

		return $this->rows_to_locations( $rows );
	}

	public function find_foreign_by_place_identity( string $country_code, string $place_name, string $region_name = '', string $district_name = '', string $place_type = '' ): ?Location {
		$matches = $this->find_foreign_by_place_identity_matches( $country_code, $place_name, $region_name, $district_name, $place_type );

		return 1 === count( $matches ) ? $matches[0] : null;
	}

	/**
	 * @return array<int,Location>
	 */
	public function find_foreign_by_place_identity_matches( string $country_code, string $place_name, string $region_name = '', string $district_name = '', string $place_type = '' ): array {
		$country_code = $this->normalize_country_code( $country_code );
		$place_name = trim( $place_name );
		$region_name = trim( $region_name );
		$district_name = trim( $district_name );
		$place_type = trim( $place_type );
		if ( '' === $country_code || 'RU' === $country_code || '' === $place_name ) {
			return array();
		}

		$place_key = $this->normalize_foreign_identity_value( $place_name );
		$region_key = $this->normalize_foreign_identity_value( $region_name );
		$district_key = $this->normalize_foreign_identity_value( $district_name );
		$place_type_key = $this->normalize_foreign_identity_type( $place_type );
		if ( $this->has_test_location_rows() ) {
			$matches = array();
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 !== (int) ( $row['active'] ?? 1 ) || $country_code !== $this->normalize_country_code( (string) ( $row['country_code'] ?? '' ) ) ) {
					continue;
				}
				if ( ! $this->foreign_identity_row_matches( $row, $place_key, $region_key, $district_key, $place_type_key ) ) {
					continue;
				}
				$matches[] = $this->row_to_location( $this->join_region_for_test_double( $row ) );
			}

			return $this->deduplicate_locations_by_id( $matches );
		}

		$where = array( 'l.active = 1', 'l.country_code = %s' );
		$args = array( $country_code );
		foreach ( $this->foreign_identity_prefilter_tokens( $place_name, $region_name, $district_name ) as $token ) {
			if ( '' === $token ) {
				continue;
			}
			$where[] = "REPLACE(LOWER(l.searchable_text), 'ё', 'е') LIKE %s";
			$args[] = '%' . $this->wpdb->esc_like( $token ) . '%';
		}

		$sql = $this->wpdb->prepare(
			"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
			FROM {$this->table_name()} l
			LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
			WHERE " . implode( ' AND ', $where ),
			...$args
		);
		if ( ! is_string( $sql ) || '' === trim( $sql ) ) {
			throw new RuntimeException( 'Foreign location identity lookup failed: SQL preparation returned an invalid result' );
		}

		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== trim( (string) ( $this->wpdb->last_error ?? '' ) ) ) {
			$this->throw_sql_error( 'Foreign location identity lookup failed' );
		}
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'Foreign location identity lookup failed: invalid SQL result' );
		}

		$matches = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( 'Foreign location identity lookup failed: invalid row structure' );
			}
			if ( $this->foreign_identity_row_matches( $row, $place_key, $region_key, $district_key, $place_type_key ) ) {
				$matches[] = $this->row_to_location( $row );
			}
		}

		return $this->deduplicate_locations_by_id( $matches );
	}

	/**
	 * @return array<int,Location>
	 */
	public function find_active_by_place_and_region_matches( string $place_name, string $region_name, string $place_type = '' ): array {
		return $this->resolve_active_by_place_and_region( $place_name, $region_name, $place_type )->matches;
	}

	public function resolve_active_by_place_and_region( string $place_name, string $region_name, string $place_type = '', string $country_code = '' ): PlaceRegionMatchResult {
		$place_name = trim( $place_name );
		$region_name = trim( $region_name );
		$place_type = trim( $place_type );
		$country_code = $this->normalize_country_code( $country_code );
		if ( '' === $place_name || '' === $region_name ) {
			return new PlaceRegionMatchResult( array(), PlaceRegionMatchResult::NOT_FOUND );
		}

		$place_key = $this->normalize_foreign_identity_value( $place_name );
		$region_key = $this->normalize_foreign_identity_value( $region_name );
		$place_type_key = $this->normalize_foreign_identity_type( $place_type );
		if ( '' === $place_key || '' === $region_key ) {
			return new PlaceRegionMatchResult( array(), PlaceRegionMatchResult::NOT_FOUND );
		}

		if ( $this->has_test_location_rows() ) {
			return $this->resolve_place_region_base_matches( $this->active_place_region_base_matches_for_rows( $this->test_location_rows(), $place_key, $region_key, $country_code ), $place_type_key );
		}

		$where = array( 'l.active = 1' );
		$args = array();
		if ( '' !== $country_code ) {
			$where[] = 'l.country_code = %s';
			$args[] = $country_code;
		}
		foreach ( $this->foreign_identity_prefilter_tokens( $place_name, $region_name ) as $token ) {
			if ( '' === $token ) {
				continue;
			}
			$where[] = "REPLACE(LOWER(l.searchable_text), 'ё', 'е') LIKE %s";
			$args[] = '%' . $this->wpdb->esc_like( $token ) . '%';
		}

		$sql = $this->wpdb->prepare(
			"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
			FROM {$this->table_name()} l
			LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
			WHERE " . implode( ' AND ', $where ),
			...$args
		);
		if ( ! is_string( $sql ) || '' === trim( $sql ) ) {
			throw new RuntimeException( 'Active location place and region lookup failed: SQL preparation returned an invalid result' );
		}

		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== trim( (string) ( $this->wpdb->last_error ?? '' ) ) ) {
			$this->throw_sql_error( 'Active location place and region lookup failed' );
		}
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'Active location place and region lookup failed: invalid SQL result' );
		}

		$base_matches = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( 'Active location place and region lookup failed: invalid row structure' );
			}
			if ( $this->active_place_region_row_matches( $row, $place_key, $region_key, '' ) ) {
				$base_matches[] = $this->row_to_location( $row );
			}
		}

		return $this->resolve_place_region_base_matches( $this->deduplicate_locations_by_id( $base_matches ), $place_type_key );
	}

	public function find_legacy_foreign_by_place_identity( string $country_code, string $place_name, string $region_name = '' ): ?Location {
		$country_code = $this->normalize_country_code( $country_code );
		$place_name = trim( $place_name );
		$region_name = trim( $region_name );
		if ( '' === $country_code || 'RU' === $country_code || '' === $place_name ) {
			return null;
		}

		$place_key = $this->normalize_foreign_identity_value( $place_name );
		$region_key = $this->normalize_foreign_identity_value( $region_name );
		if ( $this->has_test_location_rows() ) {
			$matches = array();
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 !== (int) ( $row['active'] ?? 1 ) || $country_code !== $this->normalize_country_code( (string) ( $row['country_code'] ?? '' ) ) ) {
					continue;
				}
				if ( (int) ( $row['gar_object_id'] ?? 0 ) > 0 || '' !== trim( (string) ( $row['fias_id'] ?? '' ) ) ) {
					continue;
				}
				if ( '' !== trim( (string) ( $row['district_name'] ?? '' ) ) ) {
					continue;
				}
				$row_place = $this->normalize_foreign_identity_value( (string) ( $row['place_name'] ?? $row['settlement_name'] ?? $row['city_name'] ?? '' ) );
				$row_region = $this->normalize_foreign_identity_value( (string) ( $row['region_name'] ?? '' ) );
				if ( $row_place !== $place_key || $row_region !== $region_key ) {
					continue;
				}
				$matches[] = $this->row_to_location( $this->join_region_for_test_double( $row ) );
			}

			return 1 === count( $matches ) ? $matches[0] : null;
		}

		$where = array(
			'l.active = 1',
			'l.country_code = %s',
			"TRIM(l.district_name) = ''",
			'(l.gar_object_id IS NULL OR l.gar_object_id = 0)',
			"(l.fias_id IS NULL OR TRIM(l.fias_id) = '')",
		);
		$args = array( $country_code );
		foreach ( $this->foreign_identity_prefilter_tokens( $place_name, $region_name ) as $token ) {
			if ( '' === $token ) {
				continue;
			}
			$where[] = "REPLACE(LOWER(l.searchable_text), 'ё', 'е') LIKE %s";
			$args[] = '%' . $this->wpdb->esc_like( $token ) . '%';
		}

		$sql = $this->wpdb->prepare(
			"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
			FROM {$this->table_name()} l
			LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
			WHERE " . implode( ' AND ', $where ),
			...$args
		);
		if ( ! is_string( $sql ) || '' === trim( $sql ) ) {
			throw new RuntimeException( 'Legacy foreign location identity lookup failed: SQL preparation returned an invalid result' );
		}

		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== trim( (string) ( $this->wpdb->last_error ?? '' ) ) ) {
			$this->throw_sql_error( 'Legacy foreign location identity lookup failed' );
		}
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'Legacy foreign location identity lookup failed: invalid SQL result' );
		}

		$matches = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( 'Legacy foreign location identity lookup failed: invalid row structure' );
			}
			$row_place = $this->normalize_foreign_identity_value( (string) ( $row['place_name'] ?? $row['settlement_name'] ?? $row['city_name'] ?? '' ) );
			$row_region = $this->normalize_foreign_identity_value( (string) ( $row['region_name'] ?? '' ) );
			if ( $row_place === $place_key && $row_region === $region_key ) {
				$matches[] = $this->row_to_location( $row );
			}
		}

		return 1 === count( $matches ) ? $matches[0] : null;
	}

	public function find_by_gar_object_id( int $gar_object_id ): ?Location {
		return $this->find_one( 'gar_object_id', $gar_object_id, '%d' );
	}

	public function find_by_fias_id( string $fias_id ): ?Location {
		$fias_id = trim( $fias_id );
		$normalized = $this->normalize_guid( $fias_id );
		if ( '' === $normalized ) {
			return null;
		}

		$exact = $this->find_one( 'fias_id', $fias_id, '%s' );
		if ( $exact instanceof Location ) {
			return $exact;
		}
		if ( $normalized !== $fias_id ) {
			$exact = $this->find_one( 'fias_id', $normalized, '%s' );
			if ( $exact instanceof Location ) {
				return $exact;
			}
		}

		if ( $this->has_test_location_rows() ) {
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 === (int) ( $row['active'] ?? 1 ) && $normalized === $this->normalize_guid( (string) ( $row['fias_id'] ?? '' ) ) ) {
					return $this->row_to_location( $this->join_region_for_test_double( $row ) );
				}
			}
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
				FROM {$this->table_name()} l
				LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
				WHERE l.active = 1
					AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(l.fias_id, '-', ''), '{', ''), '}', ''), ' ', '')) = %s
				LIMIT 1",
				$normalized
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->row_to_location( $row ) : null;
	}

	public function find_by_fias_or_city_fias_id( string $fias_id ): ?Location {
		$normalized = $this->normalize_guid( $fias_id );
		if ( '' === $normalized ) {
			return null;
		}

		if ( $this->has_test_location_rows() ) {
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 !== (int) ( $row['active'] ?? 1 ) ) {
					continue;
				}
				if ( in_array( $normalized, array( $this->normalize_guid( (string) ( $row['fias_id'] ?? '' ) ), $this->normalize_guid( (string) ( $row['city_fias_id'] ?? '' ) ) ), true ) ) {
					return $this->row_to_location( $this->join_region_for_test_double( $row ) );
				}
			}
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
				FROM {$this->table_name()} l
				LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
				WHERE l.active = 1
					AND (
						LOWER(REPLACE(REPLACE(REPLACE(REPLACE(l.fias_id, '-', ''), '{', ''), '}', ''), ' ', '')) = %s
						OR LOWER(REPLACE(REPLACE(REPLACE(REPLACE(l.city_fias_id, '-', ''), '{', ''), '}', ''), ' ', '')) = %s
					)
				LIMIT 1",
				$normalized,
				$normalized
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->row_to_location( $row ) : null;
	}

	public function find_unique_by_city_fias_id( string $city_fias_id ): ?Location {
		$normalized = $this->normalize_guid( $city_fias_id );
		if ( '' === $normalized ) { return null; }
		if ( $this->has_test_location_rows() ) {
			$rows = array_values( array_filter( $this->test_location_rows(), fn( array $row ): bool => 1 === (int) ( $row['active'] ?? 1 ) && $normalized === $this->normalize_guid( (string) ( $row['city_fias_id'] ?? '' ) ) ) );
			return 1 === count( $rows ) ? $this->row_to_location( $this->join_region_for_test_double( $rows[0] ) ) : null;
		}
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type FROM {$this->table_name()} l LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code WHERE l.active = 1 AND LOWER(REPLACE(REPLACE(REPLACE(REPLACE(l.city_fias_id, '-', ''), '{', ''), '}', ''), ' ', '')) = %s LIMIT 2", $normalized ), ARRAY_A );
		return is_array( $rows ) && 1 === count( $rows ) ? $this->row_to_location( $rows[0] ) : null;
	}

	public function find_by_kladr_id( string $kladr_id ): ?Location {
		return $this->find_one( 'kladr_id', trim( $kladr_id ), '%s' );
	}

	public function find_unique_by_kladr_variant( string $kladr_id ): ?Location {
		$normalized = preg_replace( '/\D+/', '', strtoupper( preg_replace( '/^RU/i', '', trim( $kladr_id ) ) ) ) ?? '';
		if ( '' === $normalized ) {
			return null;
		}

		if ( $this->has_test_location_rows() ) {
			$matches = array();
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 !== (int) ( $row['active'] ?? 1 ) ) {
					continue;
				}
				foreach ( array( 'kladr_id', 'city_kladr_id' ) as $column ) {
					$row_kladr = preg_replace( '/\D+/', '', strtoupper( (string) ( $row[ $column ] ?? '' ) ) ) ?? '';
					if ( '' !== $row_kladr && ( $row_kladr === $normalized || rtrim( $row_kladr, '0' ) === rtrim( $normalized, '0' ) ) ) {
						$matches[ (int) ( $row['id'] ?? 0 ) ] = $this->row_to_location( $this->join_region_for_test_double( $row ) );
					}
				}
			}
			return 1 === count( $matches ) ? reset( $matches ) : null;
		}

		$variants = array_values( array_unique( array_filter( array( $normalized, rtrim( $normalized, '0' ), str_pad( $normalized, 13, '0' ) ) ) ) );
		$placeholders = implode( ',', array_fill( 0, count( $variants ), '%s' ) );
		$args = array_merge( $variants, $variants );
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
				FROM {$this->table_name()} l
				LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
				WHERE l.active = 1
					AND (
						l.kladr_id IN ({$placeholders})
						OR l.city_kladr_id IN ({$placeholders})
					)
				LIMIT 2",
				...$args
			),
			ARRAY_A
		);

		return is_array( $rows ) && 1 === count( $rows ) ? $this->row_to_location( $rows[0] ) : null;
	}

	/**
	 * @return array<int,Location>
	 */
	public function find_conservative_name_matches( string $name, string $region, string $district, string $type ): array {
		$name = $this->normalize_query( $name );
		$region = $this->normalize_query( $region );
		$district = $this->normalize_query( $district );
		$type = $this->normalize_query( $type );
		if ( '' === $name ) {
			return array();
		}

		if ( $this->has_test_location_rows() ) {
			$matches = array();
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 !== (int) ( $row['active'] ?? 1 ) || 'RU' !== strtoupper( (string) ( $row['country_code'] ?? 'RU' ) ) ) {
					continue;
				}
				$row_name = $this->normalize_query( (string) ( $row['place_name'] ?? $row['settlement_name'] ?? $row['city_name'] ?? '' ) );
				if ( $row_name !== $name ) {
					continue;
				}
				$row_region = $this->normalize_query( (string) ( $row['region_name'] ?? '' ) );
				if ( '' !== $region && '' !== $row_region && ! str_starts_with( $row_region, $region ) && ! str_starts_with( $region, $row_region ) ) {
					continue;
				}
				$row_district = $this->normalize_query( (string) ( $row['district_name'] ?? '' ) );
				if ( '' !== $district && $row_district !== $district ) {
					continue;
				}
				$row_type = $this->normalize_query( (string) ( $row['place_type'] ?? $row['settlement_type'] ?? $row['city_type'] ?? '' ) );
				if ( '' !== $type && $row_type !== $type ) {
					continue;
				}
				$matches[ (int) ( $row['id'] ?? 0 ) ] = $this->row_to_location( $this->join_region_for_test_double( $row ) );
			}
			return array_values( $matches );
		}

		$where = array( 'l.active = 1', 'l.country_code = %s', '(LOWER(l.place_name) = %s OR LOWER(l.settlement_name) = %s OR LOWER(l.city_name) = %s)' );
		$args = array( 'RU', $name, $name, $name );
		if ( '' !== $region ) {
			$where[] = '(LOWER(l.region_name) LIKE %s OR %s LIKE CONCAT(LOWER(l.region_name), %s))';
			$args[] = $region . '%';
			$args[] = $region;
			$args[] = '%';
		}
		if ( '' !== $district ) {
			$where[] = 'LOWER(l.district_name) = %s';
			$args[] = $district;
		}
		if ( '' !== $type ) {
			$where[] = '(LOWER(l.place_type) = %s OR LOWER(l.settlement_type) = %s OR LOWER(l.city_type) = %s)';
			$args[] = $type;
			$args[] = $type;
			$args[] = $type;
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
				FROM {$this->table_name()} l
				LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
				WHERE " . implode( ' AND ', $where ) . '
				LIMIT 2',
				...$args
			),
			ARRAY_A
		);

		return $this->rows_to_locations( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function dpd_location_index_rows( int $limit = 5000, int $offset = 0 ): array {
		$limit = max( 100, min( 20000, $limit ) );
		$offset = max( 0, $offset );
		$columns = array( 'id', 'country_code', 'active', 'fias_id', 'city_fias_id', 'kladr_id', 'city_kladr_id', 'region_name', 'district_name', 'place_name', 'settlement_name', 'city_name', 'place_type', 'settlement_type', 'city_type' );

		if ( $this->has_test_location_rows() ) {
			return array_slice(
				array_values(
					array_map(
						static function ( array $row ) use ( $columns ): array {
							$filtered = array();
							foreach ( $columns as $column ) {
								$filtered[ $column ] = $row[ $column ] ?? '';
							}
							return $filtered;
						},
						array_filter(
							$this->test_location_rows(),
							static fn( array $row ): bool => 1 === (int) ( $row['active'] ?? 1 ) && 'RU' === strtoupper( (string) ( $row['country_code'] ?? 'RU' ) )
						)
					)
				),
				$offset,
				$limit
			);
		}

		$this->wpdb->last_error = '';
		$sql = $this->wpdb->prepare(
			'SELECT id, country_code, active, fias_id, city_fias_id, kladr_id, city_kladr_id, region_name, district_name, place_name, settlement_name, city_name, place_type, settlement_type, city_type
			FROM ' . $this->table_name() . '
			WHERE active = 1 AND country_code = %s
			ORDER BY id ASC
			LIMIT %d OFFSET %d',
			'RU',
			$limit,
			$offset
		);
		if ( ! is_string( $sql ) || '' === trim( $sql ) ) {
			throw new RuntimeException( 'DPD location index page query failed: SQL preparation returned an invalid result' );
		}
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== trim( (string) ( $this->wpdb->last_error ?? '' ) ) ) {
			$this->throw_sql_error( 'DPD location index page query failed' );
		}
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( 'DPD location index page query failed: invalid SQL result' );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( 'DPD location index page query failed: invalid row structure' );
			}
		}

		return $rows;
	}

	/**
	 * @param array<int,string> $guids
	 * @return array<int,array<string,mixed>>
	 */
	public function dpd_find_own_fias_candidates( array $guids ): array {
		return $this->dpd_candidate_rows_by_guid_column( 'fias_id', $guids, 'DPD own FIAS candidate lookup failed' );
	}

	/**
	 * @param array<int,string> $guids
	 * @return array<int,array<string,mixed>>
	 */
	public function dpd_find_city_fias_candidates( array $guids ): array {
		return $this->dpd_candidate_rows_by_guid_column( 'city_fias_id', $guids, 'DPD city FIAS candidate lookup failed' );
	}

	/**
	 * @param array<int,string> $keys
	 * @return array<int,array<string,mixed>>
	 */
	public function dpd_find_kladr_candidates( array $keys ): array {
		$values = array();
		foreach ( $keys as $key ) {
			$key = preg_replace( '/\D+/', '', strtoupper( preg_replace( '/^RU/i', '', trim( (string) $key ) ) ) ) ?? '';
			if ( '' === $key ) {
				continue;
			}
			$values[ $key ] = $key;
			$values[ 'RU' . $key ] = 'RU' . $key;
		}

		return $this->dpd_candidate_rows_by_columns( array( 'kladr_id', 'city_kladr_id' ), array_values( $values ), 'DPD KLADR candidate lookup failed' );
	}

	/**
	 * @param array<int,string> $names
	 * @return array<int,array<string,mixed>>
	 */
	public function dpd_find_name_candidates( array $names ): array {
		$values = array();
		foreach ( $names as $name ) {
			$name = $this->normalize_query( (string) $name );
			if ( '' !== $name ) {
				$values[ $name ] = $name;
			}
		}
		if ( array() === $values ) {
			return array();
		}

		if ( $this->has_test_location_rows() ) {
			return $this->dpd_candidate_rows_from_test(
				static function ( array $row ) use ( $values ): bool {
					foreach ( array( 'place_name', 'settlement_name', 'city_name' ) as $column ) {
						$value = trim( (string) ( $row[ $column ] ?? '' ) );
						$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), $value ), 'UTF-8' ) : strtolower( $value );
						$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
						if ( isset( $values[ $value ] ) ) {
							return true;
						}
					}

					return false;
				}
			);
		}

		$chunks = array_chunk( array_values( $values ), 500 );
		$rows = array();
		foreach ( $chunks as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$args = array_merge( $chunk, $chunk, $chunk );
			$sql = $this->wpdb->prepare(
				'SELECT ' . $this->dpd_candidate_columns_sql() . '
				FROM ' . $this->table_name() . " l
				WHERE l.active = 1 AND l.country_code = 'RU'
				  AND (LOWER(l.place_name) IN ({$placeholders}) OR LOWER(l.settlement_name) IN ({$placeholders}) OR LOWER(l.city_name) IN ({$placeholders}))
				ORDER BY l.id ASC",
				...$args
			);
			$rows = array_merge( $rows, $this->dpd_get_candidate_rows_or_throw( $sql, 'DPD name candidate lookup failed' ) );
		}

		return $this->deduplicate_candidate_rows_by_id( $rows );
	}

	/**
	 * @param array<int,string> $guids
	 * @return array<int,array<string,mixed>>
	 */
	private function dpd_candidate_rows_by_guid_column( string $column, array $guids, string $error_message ): array {
		if ( ! in_array( $column, array( 'fias_id', 'city_fias_id' ), true ) ) {
			throw new RuntimeException( 'DPD candidate lookup failed: invalid GUID column' );
		}
		$values = array();
		foreach ( $guids as $guid ) {
			foreach ( $this->dpd_guid_variants( (string) $guid ) as $variant ) {
				$values[ $variant ] = $variant;
			}
		}

		return $this->dpd_candidate_rows_by_columns( array( $column ), array_values( $values ), $error_message );
	}

	/**
	 * @param array<int,string> $columns
	 * @param array<int,string> $values
	 * @return array<int,array<string,mixed>>
	 */
	private function dpd_candidate_rows_by_columns( array $columns, array $values, string $error_message ): array {
		$columns = array_values( array_intersect( $columns, array( 'fias_id', 'city_fias_id', 'kladr_id', 'city_kladr_id' ) ) );
		$values = array_values( array_unique( array_filter( array_map( 'strval', $values ), static fn( string $value ): bool => '' !== trim( $value ) ) ) );
		if ( array() === $columns || array() === $values ) {
			return array();
		}

		if ( $this->has_test_location_rows() ) {
			return $this->dpd_candidate_rows_from_test(
				static function ( array $row ) use ( $columns, $values ): bool {
					foreach ( $columns as $column ) {
						if ( in_array( (string) ( $row[ $column ] ?? '' ), $values, true ) ) {
							return true;
						}
						if ( in_array( strtoupper( (string) ( $row[ $column ] ?? '' ) ), $values, true ) ) {
							return true;
						}
					}

					return false;
				}
			);
		}

		$rows = array();
		foreach ( array_chunk( $values, 500 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			$where = array();
			$args = array();
			foreach ( $columns as $column ) {
				$where[] = "l.{$column} IN ({$placeholders})";
				$args = array_merge( $args, $chunk );
			}
			$sql = $this->wpdb->prepare(
				'SELECT ' . $this->dpd_candidate_columns_sql() . '
				FROM ' . $this->table_name() . " l
				WHERE l.active = 1 AND l.country_code = 'RU'
				  AND (" . implode( ' OR ', $where ) . ')
				ORDER BY l.id ASC',
				...$args
			);
			$rows = array_merge( $rows, $this->dpd_get_candidate_rows_or_throw( $sql, $error_message ) );
		}

		return $this->deduplicate_candidate_rows_by_id( $rows );
	}

	/**
	 * @return array<int,string>
	 */
	private function dpd_guid_variants( string $value ): array {
		$normalized = strtolower( preg_replace( '/[^a-f0-9]/i', '', $value ) ?? '' );
		if ( 32 !== strlen( $normalized ) ) {
			return array();
		}
		$canonical = substr( $normalized, 0, 8 ) . '-' . substr( $normalized, 8, 4 ) . '-' . substr( $normalized, 12, 4 ) . '-' . substr( $normalized, 16, 4 ) . '-' . substr( $normalized, 20 );

		return array_values( array_unique( array( $canonical, strtoupper( $canonical ), $normalized, strtoupper( $normalized ) ) ) );
	}

	private function dpd_candidate_columns_sql(): string {
		return 'l.id, l.country_code, l.active, l.fias_id, l.city_fias_id, l.kladr_id, l.city_kladr_id, l.region_name, l.district_name, l.place_name, l.settlement_name, l.city_name, l.place_type, l.settlement_type, l.city_type';
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function dpd_get_candidate_rows_or_throw( mixed $sql, string $message ): array {
		if ( ! is_string( $sql ) || '' === trim( $sql ) ) {
			throw new RuntimeException( $message . ': SQL preparation returned an invalid result' );
		}
		$this->wpdb->last_error = '';
		$rows = $this->wpdb->get_results( $sql, ARRAY_A );
		if ( '' !== trim( (string) ( $this->wpdb->last_error ?? '' ) ) ) {
			$this->throw_sql_error( $message );
		}
		if ( ! is_array( $rows ) ) {
			throw new RuntimeException( $message . ': invalid SQL result' );
		}
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				throw new RuntimeException( $message . ': invalid row structure' );
			}
		}

		return $rows;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function dpd_candidate_rows_from_test( callable $predicate ): array {
		$columns = array( 'id', 'country_code', 'active', 'fias_id', 'city_fias_id', 'kladr_id', 'city_kladr_id', 'region_name', 'district_name', 'place_name', 'settlement_name', 'city_name', 'place_type', 'settlement_type', 'city_type' );
		$rows = array();
		foreach ( $this->test_location_rows() as $row ) {
			if ( 1 !== (int) ( $row['active'] ?? 1 ) || 'RU' !== strtoupper( (string) ( $row['country_code'] ?? 'RU' ) ) || ! $predicate( $row ) ) {
				continue;
			}
			$filtered = array();
			foreach ( $columns as $column ) {
				$filtered[ $column ] = $row[ $column ] ?? '';
			}
			$rows[] = $filtered;
		}
		usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );

		return $this->deduplicate_candidate_rows_by_id( $rows );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function deduplicate_candidate_rows_by_id( array $rows ): array {
		$deduped = array();
		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id > 0 ) {
				$deduped[ $id ] = $row;
			}
		}
		ksort( $deduped, SORT_NUMERIC );

		return array_values( $deduped );
	}

	public function find_first_by_postal_code( string $postal_code ): ?Location {
		$postal_code = preg_replace( '/\D+/', '', $postal_code ) ?? '';
		if ( '' === $postal_code ) {
			return null;
		}

		if ( $this->has_test_location_rows() ) {
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 === (int) ( $row['active'] ?? 1 ) && $postal_code === (string) ( $row['postal_code'] ?? '' ) ) {
					return $this->row_to_location( $this->join_region_for_test_double( $row ) );
				}
			}
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
				FROM {$this->table_name()} l
				LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
				WHERE l.active = 1 AND l.postal_code = %s
				ORDER BY CASE WHEN l.latitude IS NOT NULL AND l.longitude IS NOT NULL AND l.latitude != 0 AND l.longitude != 0 THEN 0 ELSE 1 END ASC, l.display_name ASC
				LIMIT 1",
				$postal_code
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->row_to_location( $row ) : null;
	}

	public function find_by_gar_id( string $gar_id ): ?Location {
		$id = is_numeric( $gar_id ) ? (int) $gar_id : 0;
		return $id > 0 ? $this->find_by_gar_object_id( $id ) : $this->find_one( 'gar_id', trim( $gar_id ), '%s' );
	}

	/**
	 * @return array<int, Location>
	 */
	public function search( string $query, int $limit = 100, string $country_code = '' ): array {
		$limit = max( 10, min( 100, $limit ) );
		return $this->search_paginated( $query, 1, $limit, $country_code )['items'];
	}

	/**
	 * @return array<int,Location>
	 */
	public function find_exact_admin_identifier_matches( string $query ): array {
		$query = trim( $query );
		if ( '' === $query ) {
			return array();
		}

		if ( $this->has_test_location_rows() ) {
			$rows = array_values(
				array_filter(
					$this->test_location_rows(),
					static fn( array $row ): bool =>
						1 === (int) ( $row['active'] ?? 1 )
						&& (
							$query === (string) ( $row['fias_id'] ?? '' )
							|| $query === (string) ( $row['gar_id'] ?? '' )
							|| $query === (string) ( $row['gar_object_id'] ?? '' )
							|| $query === (string) ( $row['kladr_id'] ?? '' )
							|| $query === (string) ( $row['postal_code'] ?? '' )
						)
				)
			);
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $a['display_name'] ?? '' ), (string) ( $b['display_name'] ?? '' ) ) );
			return $this->rows_to_locations( array_map( fn( array $row ): array => $this->join_region_for_test_double( $row ), $rows ) );
		}

		$where = array( 'l.fias_id = %s', 'l.gar_id = %s', 'l.kladr_id = %s', 'l.postal_code = %s' );
		$args = array( $query, $query, $query, $query );
		if ( is_numeric( $query ) ) {
			$where[] = 'l.gar_object_id = %d';
			$args[] = (int) $query;
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
				FROM {$this->table_name()} l
				LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
				WHERE l.active = 1 AND (" . implode( ' OR ', $where ) . ')
				ORDER BY l.display_name ASC',
				...$args
			),
			ARRAY_A
		);

		return $this->rows_to_locations( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @param array<int,string> $tokens
	 * @return array<int, Location>
	 */
	public function search_by_tokens( array $tokens, int $limit = 300, bool $require_all = false, string $force_region_code = '', string $country_code = '' ): array {
		$tokens = array_values( array_unique( array_filter( array_map( fn( string $token ): string => $this->normalize_query( $token ), $tokens ) ) ) );
		$limit = max( 10, min( 300, $limit ) );
		$force_region_code = trim( $force_region_code );
		$country_code = $this->normalize_country_code( $country_code );

		if ( array() === $tokens ) {
			return array();
		}

		if ( $this->has_test_location_rows() ) {
			$rows = array();
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 !== (int) ( $row['active'] ?? 1 ) ) {
					continue;
				}
				if ( ! $this->row_country_matches( $row, $country_code ) ) {
					continue;
				}
				if ( '' !== $force_region_code && $force_region_code !== (string) ( $row['region_code'] ?? '' ) ) {
					continue;
				}
				$haystack = $this->normalize_query( (string) ( $row['searchable_text'] ?? '' ) . ' ' . implode( ' ', array_values( $row ) ) );
				$matches = 0;
				foreach ( $tokens as $token ) {
					if ( str_contains( $haystack, $token ) ) {
						++$matches;
					}
				}
				if ( ( $require_all && $matches === count( $tokens ) ) || ( ! $require_all && $matches > 0 ) ) {
					$rows[] = $this->join_region_for_test_double( $row );
				}
			}

			return $this->rows_to_locations( array_slice( $rows, 0, $limit ) );
		}

		$where = array( 'l.active = 1' );
		$args = array();
		if ( '' !== $country_code ) {
			$where[] = 'l.country_code = %s';
			$args[] = $country_code;
		}
		if ( '' !== $force_region_code ) {
			$where[] = 'l.region_code = %s';
			$args[] = $force_region_code;
		}

		$parts = array();
		foreach ( $tokens as $token ) {
			$parts[] = 'l.searchable_text LIKE %s';
			$args[] = '%' . $this->wpdb->esc_like( $token ) . '%';
		}
		$where[] = '(' . implode( $require_all ? ' AND ' : ' OR ', $parts ) . ')';
		$args[] = $limit;

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
				FROM {$this->table_name()} l
				LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
				WHERE " . implode( ' AND ', $where ) . '
				ORDER BY l.display_name ASC
				LIMIT %d',
				...$args
			),
			ARRAY_A
		);

		return $this->rows_to_locations( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @param array<int,string> $tokens
	 * @return array<int,Location>
	 */
	public function checkout_hierarchy_candidates( array $tokens, int $limit = 1000, string $force_region_code = '', string $country_code = '' ): array {
		$tokens = array_values( array_unique( array_filter( array_map( fn( string $token ): string => $this->normalize_query( $token ), $tokens ) ) ) );
		$limit = max( 10, min( 2000, $limit ) );
		$force_region_code = trim( $force_region_code );
		$country_code = $this->normalize_country_code( $country_code );
		if ( array() === $tokens && '' === $force_region_code ) {
			return array();
		}

		if ( $this->has_test_location_rows() ) {
			$direct_rows = array();
			$broad_rows = array();
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 !== (int) ( $row['active'] ?? 1 ) ) {
					continue;
				}
				if ( ! $this->row_country_matches( $row, $country_code ) ) {
					continue;
				}
				if ( '' !== $force_region_code && $force_region_code !== (string) ( $row['region_code'] ?? '' ) ) {
					continue;
				}
				if ( array() === $tokens || $this->row_has_checkout_direct_prefix_match( $row, $tokens ) ) {
					$direct_rows[] = $this->join_region_for_test_double( $row );
				}
				if ( array() === $tokens || $this->row_has_checkout_prefix_match( $row, $tokens ) ) {
					$broad_rows[] = $this->join_region_for_test_double( $row );
				}
			}
			usort(
				$direct_rows,
				fn( array $a, array $b ): int => $this->checkout_direct_row_rank( $a, $tokens ) <=> $this->checkout_direct_row_rank( $b, $tokens )
			);

			return $this->rows_to_locations( $this->merge_location_rows_by_id( $direct_rows, $broad_rows, $limit ) );
		}

		$base_where = array( 'l.active = 1' );
		$base_args = array();
		if ( '' !== $country_code ) {
			$base_where[] = 'l.country_code = %s';
			$base_args[] = $country_code;
		}
		if ( '' !== $force_region_code ) {
			$base_where[] = 'l.region_code = %s';
			$base_args[] = $force_region_code;
		}

		$direct_limit = min( $limit, max( 100, (int) ceil( $limit / 4 ) ) );
		$broad_limit = $limit;
		$direct_rows = $this->checkout_hierarchy_query_rows( $base_where, $base_args, $tokens, array( 'place_name', 'city_name', 'settlement_name' ), false, $direct_limit );
		$broad_rows = $this->checkout_hierarchy_query_rows( $base_where, $base_args, $tokens, array( 'region_name', 'district_name', 'city_name', 'place_name', 'settlement_name' ), true, $broad_limit );

		return $this->rows_to_locations( $this->merge_location_rows_by_id( $direct_rows, $broad_rows, $limit ) );
	}

	/**
	 * @return array{items:array<int,Location>, total:int, page:int, per_page:int, total_pages:int}
	 */
	public function search_paginated( string $query, int $page = 1, int $per_page = 20, string $country_code = '' ): array {
		$query = $this->normalize_query( $query );
		$per_page = in_array( $per_page, array( 10, 20, 50, 100 ), true ) ? $per_page : 20;
		$page = max( 1, $page );
		$country_code = $this->normalize_country_code( $country_code );

		if ( '' === $query ) {
			return array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => $per_page, 'total_pages' => 0 );
		}

		if ( $this->has_test_location_rows() ) {
			$rows = array();
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 === (int) ( $row['active'] ?? 1 ) && $this->row_country_matches( $row, $country_code ) && str_contains( (string) ( $row['searchable_text'] ?? '' ), $query ) ) {
					$rows[] = $row;
				}
			}
			usort(
				$rows,
				fn( array $a, array $b ): int => $this->rank_row( $a, $query ) <=> $this->rank_row( $b, $query )
					?: strcmp( (string) ( $a['display_name'] ?? '' ), (string) ( $b['display_name'] ?? '' ) )
			);
			$total = count( $rows );
			$total_pages = (int) ceil( $total / $per_page );
			$page = min( $page, max( 1, $total_pages ) );
			$offset = ( $page - 1 ) * $per_page;
			return array(
				'items'       => $this->rows_to_locations( array_map( fn( array $row ): array => $this->join_region_for_test_double( $row ), array_slice( $rows, $offset, $per_page ) ) ),
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
			);
		}

		$like = '%' . $this->wpdb->esc_like( $query ) . '%';
		$prefix = $this->wpdb->esc_like( $query ) . '%';
		$where = array( 'l.active = 1', 'l.searchable_text LIKE %s' );
		$count_args = array( $like );
		if ( '' !== $country_code ) {
			$where[] = 'l.country_code = %s';
			$count_args[] = $country_code;
		}
		$total = (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$this->table_name()} l
				LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
				WHERE " . implode( ' AND ', $where ),
				...$count_args
			)
		);
		$total_pages = (int) ceil( $total / $per_page );
		$page = min( $page, max( 1, $total_pages ) );
		$offset = ( $page - 1 ) * $per_page;

		$args = array(
			$query,
			$prefix,
			$like,
			$query,
			$prefix,
			$like,
			$like,
			$like,
			$like,
		);
		if ( '' !== $country_code ) {
			$args[] = $country_code;
		}
		$args[] = $per_page;
		$args[] = $offset;

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type,
					CASE
						WHEN l.place_name = %s THEN 1
						WHEN l.place_name LIKE %s THEN 2
						WHEN l.place_name LIKE %s THEN 3
						WHEN l.city_name = %s THEN 4
						WHEN l.city_name LIKE %s THEN 5
						WHEN l.city_name LIKE %s THEN 6
						WHEN l.district_name LIKE %s THEN 7
						WHEN COALESCE(r.region_name, l.region_name) LIKE %s THEN 8
						ELSE 9
					END AS rank_score
				FROM {$this->table_name()} l
				LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
				WHERE " . implode( ' AND ', $where ) . "
				ORDER BY rank_score ASC, l.display_name ASC
				LIMIT %d OFFSET %d",
				...$args
			),
			ARRAY_A
		);

		return array(
			'items'       => $this->rows_to_locations( is_array( $rows ) ? $rows : array() ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * @return array<string, array<int, Location>>
	 */
	public function search_grouped_by_region( string $query, int $limit = 100 ): array {
		$locations = $this->search( $query, $limit );
		$grouped   = array();

		foreach ( $locations as $location ) {
			$region = '' !== $location->region_name ? $location->region_name : __( 'Регион не указан', 'walls-delivery-calc' );
			$grouped[ $region ][] = $location;
		}

		ksort( $grouped );

		return $grouped;
	}

	public function count_all(): int {
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name()}" );
	}

	public function count_regions(): int {
		if ( $this->table_exists( $this->region_table_name() ) ) {
			return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->region_table_name()}" );
		}

		return (int) $this->wpdb->get_var( "SELECT COUNT(DISTINCT region_name) FROM {$this->table_name()} WHERE active = 1 AND region_name != ''" );
	}

	public function count_aliases(): int {
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->alias_table_name()}" );
	}

	public function count_with_postal_code(): int {
		if ( $this->has_test_location_rows() ) {
			return count(
				array_filter(
					$this->test_location_rows(),
					static fn( array $row ): bool => '' !== trim( (string) ( $row['postal_code'] ?? '' ) )
				)
			);
		}

		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name()} WHERE postal_code IS NOT NULL AND postal_code != ''" );
	}

	public function count_without_postal_code(): int {
		if ( $this->has_test_location_rows() ) {
			return count(
				array_filter(
					$this->test_location_rows(),
					static fn( array $row ): bool => '' === trim( (string) ( $row['postal_code'] ?? '' ) )
				)
			);
		}

		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name()} WHERE postal_code IS NULL OR postal_code = ''" );
	}

	public function count_with_russianpost_courier_calc_postal_code(): int {
		if ( $this->has_test_location_rows() ) {
			return count(
				array_filter(
					$this->test_location_rows(),
					static fn( array $row ): bool =>
						'' !== trim( (string) ( $row['russianpost_courier_calc_postal_code'] ?? '' ) )
						&& RussianPostCourierCalcPostcodeFillStateService::COURIER_POSTCODE_TECHNICAL_ERROR !== trim( (string) ( $row['russianpost_courier_calc_postal_code'] ?? '' ) )
				)
			);
		}

		return (int) $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name()} WHERE russianpost_courier_calc_postal_code IS NOT NULL AND russianpost_courier_calc_postal_code != '' AND russianpost_courier_calc_postal_code != %s",
				RussianPostCourierCalcPostcodeFillStateService::COURIER_POSTCODE_TECHNICAL_ERROR
			)
		);
	}

	public function count_locations_with_coordinates(): int {
		if ( $this->has_test_location_rows() ) {
			return count(
				array_filter(
					$this->test_location_rows(),
					fn( array $row ): bool => $this->is_ru_location_row( $row ) && ! $this->location_missing_coordinates_row( $row )
				)
			);
		}

		return (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name()}
			WHERE active = 1
				AND (country_code = 'RU' OR country_code IS NULL OR country_code = '')
				AND latitude IS NOT NULL
				AND longitude IS NOT NULL
				AND latitude != 0
				AND longitude != 0"
		);
	}

	public function count_locations_missing_coordinates(): int {
		if ( $this->has_test_location_rows() ) {
			return count(
				array_filter(
					$this->test_location_rows(),
					fn( array $row ): bool => $this->is_ru_location_row( $row ) && $this->location_missing_coordinates_row( $row )
				)
			);
		}

		return (int) $this->wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table_name()}
			WHERE active = 1
				AND (country_code = 'RU' OR country_code IS NULL OR country_code = '')
				AND (latitude IS NULL OR longitude IS NULL OR latitude = 0 OR longitude = 0)"
		);
	}

	public function count_technical_no_index_marker(): int {
		if ( $this->has_test_location_rows() ) {
			return count(
				array_filter(
					$this->test_location_rows(),
					static fn( array $row ): bool => '999999999' === (string) ( $row['postal_code'] ?? '' )
				)
			);
		}

		return (int) $this->wpdb->get_var( $this->wpdb->prepare( "SELECT COUNT(*) FROM {$this->table_name()} WHERE postal_code = %s", '999999999' ) );
	}

	/**
	 * @return array<int,string>
	 */
	public function distinct_country_codes(): array {
		if ( property_exists( $this->wpdb, 'distinct_country_codes_calls' ) ) {
			++$this->wpdb->distinct_country_codes_calls;
		}

		if ( $this->has_test_location_rows() ) {
			$countries = array();
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 !== (int) ( $row['active'] ?? 1 ) ) {
					continue;
				}
				$country = $this->normalize_country_code( (string) ( $row['country_code'] ?? '' ) );
				if ( '' !== $country ) {
					$countries[] = $country;
				}
			}
			$countries = array_values( array_unique( $countries ) );
			sort( $countries );
			return $countries;
		}

		$rows = $this->wpdb->get_col( "SELECT DISTINCT country_code FROM {$this->table_name()} WHERE active = 1 AND country_code IS NOT NULL AND country_code != '' ORDER BY country_code ASC" );
		$countries = is_array( $rows ) ? array_map( fn( mixed $code ): string => $this->normalize_country_code( (string) $code ), $rows ) : array();
		return array_values( array_unique( array_filter( $countries ) ) );
	}

	/**
	 * @return array<string,int>
	 */
	public function country_counts(): array {
		if ( property_exists( $this->wpdb, 'country_counts_calls' ) ) {
			++$this->wpdb->country_counts_calls;
		}

		if ( $this->has_test_location_rows() ) {
			$counts = array();
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 !== (int) ( $row['active'] ?? 1 ) ) {
					continue;
				}
				$country = $this->normalize_country_code( (string) ( $row['country_code'] ?? '' ) );
				if ( '' === $country ) {
					continue;
				}
				$counts[ $country ] = ( $counts[ $country ] ?? 0 ) + 1;
			}
			ksort( $counts );
			return $counts;
		}

		$rows = $this->wpdb->get_results( "SELECT country_code, COUNT(*) AS location_count FROM {$this->table_name()} WHERE active = 1 AND country_code IS NOT NULL AND country_code != '' GROUP BY country_code ORDER BY country_code ASC", ARRAY_A );
		$counts = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$country = $this->normalize_country_code( (string) ( $row['country_code'] ?? '' ) );
			if ( '' !== $country ) {
				$counts[ $country ] = max( 0, (int) ( $row['location_count'] ?? 0 ) );
			}
		}
		ksort( $counts );
		return $counts;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function next_postcode_batch( bool $cities_first, int $limit, int $last_id ): array {
		$limit = max( 1, min( 20, $limit ) );
		if ( ! $cities_first ) {
			return $this->random_postcode_batch_for_non_cities( $limit );
		}

		if ( $this->has_test_location_rows() ) {
			$rows = array_values(
				array_filter(
					$this->test_location_rows(),
					static fn( array $row ): bool =>
						(int) ( $row['id'] ?? 0 ) > $last_id
						&& in_array( (string) ( $row['place_type'] ?? '' ), array( 'г', 'г.' ), true )
						&& '' === trim( (string) ( $row['postal_code'] ?? '' ) )
						&& '' !== trim( (string) ( $row['fias_id'] ?? '' ) )
				)
			);
			usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
			return array_slice( $rows, 0, $limit );
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name()}
				WHERE id > %d
					AND place_type IN ('г', 'г.')
					AND (postal_code IS NULL OR postal_code = '')
					AND fias_id IS NOT NULL
					AND fias_id != ''
				ORDER BY id ASC
				LIMIT %d",
				$last_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function random_postcode_batch_for_non_cities( int $limit ): array {
		$limit = max( 1, min( 20, $limit ) );
		if ( $this->has_test_location_rows() ) {
			$rows = array_values(
				array_filter(
					$this->test_location_rows(),
					static fn( array $row ): bool =>
						! in_array( (string) ( $row['place_type'] ?? '' ), array( 'г', 'г.' ), true )
						&& '' === trim( (string) ( $row['postal_code'] ?? '' ) )
						&& '999999999' !== (string) ( $row['postal_code'] ?? '' )
						&& '' !== trim( (string) ( $row['fias_id'] ?? '' ) )
				)
			);
			shuffle( $rows );
			return array_slice( $rows, 0, $limit );
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name()}
				WHERE place_type NOT IN ('г', 'г.')
					AND (postal_code IS NULL OR postal_code = '')
					AND COALESCE(postal_code, '') != '999999999'
					AND fias_id IS NOT NULL
					AND fias_id != ''
				ORDER BY RAND()
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function find_locations_missing_coordinates( int $limit, int $after_id = 0, string $priority = 'all' ): array {
		$limit = max( 1, min( 20, $limit ) );
		$priority = in_array( $priority, array( 'cities', 'others', 'all' ), true ) ? $priority : 'all';

		if ( $this->has_test_location_rows() ) {
			$rows = array_values(
				array_filter(
					$this->test_location_rows(),
					function ( array $row ) use ( $after_id, $priority ): bool {
						if ( (int) ( $row['id'] ?? 0 ) <= $after_id || ! $this->is_ru_location_row( $row ) || ! $this->location_missing_coordinates_row( $row ) ) {
							return false;
						}
						if ( 'cities' === $priority ) {
							return $this->is_city_location_row( $row );
						}
						if ( 'others' === $priority ) {
							return ! $this->is_city_location_row( $row );
						}
						return true;
					}
				)
			);
			usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
			return array_slice( $rows, 0, $limit );
		}

		$priority_sql = '';
		if ( 'cities' === $priority ) {
			$priority_sql = " AND (place_type IN ('Рі', 'Рі.', 'г', 'г.') OR city_type IN ('Рі', 'Рі.', 'г', 'г.'))";
		} elseif ( 'others' === $priority ) {
			$priority_sql = " AND (place_type IS NULL OR place_type NOT IN ('Рі', 'Рі.', 'г', 'г.')) AND (city_type IS NULL OR city_type NOT IN ('Рі', 'Рі.', 'г', 'г.'))";
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name()}
				WHERE id > %d
					AND active = 1
					AND (country_code = 'RU' OR country_code IS NULL OR country_code = '')
					AND (latitude IS NULL OR longitude IS NULL OR latitude = 0 OR longitude = 0)
					{$priority_sql}
				ORDER BY id ASC
				LIMIT %d",
				$after_id,
				$limit
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function update_postal_code( int $location_id, string $postal_code ): bool {
		if ( $location_id <= 0 ) {
			return false;
		}

		$data = array(
			'postal_code' => $postal_code,
			'updated_at'  => current_time( 'mysql' ),
		);
		if ( $this->has_test_location_rows() ) {
			$property = $this->test_location_rows_property();
			if ( ! isset( $this->wpdb->{$property}[ $location_id ] ) ) {
				return false;
			}
			$this->wpdb->{$property}[ $location_id ] = array_merge( $this->wpdb->{$property}[ $location_id ], $data );
			return true;
		}

		$result = $this->wpdb->update( $this->table_name(), $data, array( 'id' => $location_id ), array( '%s', '%s' ), array( '%d' ) );
		if ( false === $result ) {
			$this->throw_sql_error( 'Location postal_code update failed' );
		}

		return true;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function next_russianpost_courier_calc_postcode_location( int $after_id = 0, string $priority = 'cities' ): ?array {
		$priority = in_array( $priority, array( 'technical_retry', 'cities', 'others' ), true ) ? $priority : 'cities';
		if ( $this->has_test_location_rows() ) {
			$rows = array_values(
				array_filter(
					$this->test_location_rows(),
					function ( array $row ) use ( $after_id, $priority ): bool {
						$courier_postcode = trim( (string) ( $row['russianpost_courier_calc_postal_code'] ?? '' ) );
						if (
							(int) ( $row['id'] ?? 0 ) <= $after_id
							|| ! $this->is_ru_location_row( $row )
							|| '' === trim( (string) ( $row['postal_code'] ?? '' ) )
							|| RussianPostCourierCalcPostcodeFillStateService::COURIER_POSTCODE_TECHNICAL_ERROR === trim( (string) ( $row['postal_code'] ?? '' ) )
						) {
							return false;
						}

						if ( 'technical_retry' === $priority ) {
							return RussianPostCourierCalcPostcodeFillStateService::COURIER_POSTCODE_TECHNICAL_ERROR === $courier_postcode;
						}
						if ( '' !== $courier_postcode ) {
							return false;
						}

						return 'cities' === $priority ? $this->is_city_location_row( $row ) : ! $this->is_city_location_row( $row );
					}
				)
			);
			usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
			return $rows[0] ?? null;
		}

		$type_sql = 'cities' === $priority
			? "AND (place_type IN ('г', 'г.') OR city_type IN ('г', 'г.') OR settlement_type IN ('г', 'г.'))"
			: "AND (place_type IS NULL OR place_type NOT IN ('г', 'г.')) AND (city_type IS NULL OR city_type NOT IN ('г', 'г.')) AND (settlement_type IS NULL OR settlement_type NOT IN ('г', 'г.'))";

		if ( 'technical_retry' === $priority ) {
			$type_sql = '';
		}
		$courier_sql = 'technical_retry' === $priority
			? 'AND russianpost_courier_calc_postal_code = %s'
			: "AND (russianpost_courier_calc_postal_code IS NULL OR russianpost_courier_calc_postal_code = '')";
		$args = 'technical_retry' === $priority
			? array( $after_id, RussianPostCourierCalcPostcodeFillStateService::COURIER_POSTCODE_TECHNICAL_ERROR, RussianPostCourierCalcPostcodeFillStateService::COURIER_POSTCODE_TECHNICAL_ERROR )
			: array( $after_id, RussianPostCourierCalcPostcodeFillStateService::COURIER_POSTCODE_TECHNICAL_ERROR );

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT *
				FROM {$this->table_name()}
				WHERE id > %d
					AND active = 1
					AND country_code = 'RU'
					AND postal_code IS NOT NULL
					AND postal_code != ''
					AND postal_code != %s
					{$courier_sql}
					{$type_sql}
				ORDER BY id ASC
				LIMIT 1",
				...$args
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	public function update_russianpost_courier_calc_postal_code_for_postal_code( string $postal_code, string $calc_postal_code, bool $only_empty = true ): int {
		$postal_code = $this->valid_six_digit_postcode( $postal_code );
		$calc_postal_code = $this->valid_six_digit_postcode( $calc_postal_code );
		if ( '' === $postal_code || '' === $calc_postal_code ) {
			return 0;
		}

		$data = array(
			'russianpost_courier_calc_postal_code' => $calc_postal_code,
			'updated_at' => current_time( 'mysql' ),
		);
		if ( $this->has_test_location_rows() ) {
			$property = $this->test_location_rows_property();
			$count = 0;
			foreach ( $this->wpdb->{$property} as $id => $row ) {
				if ( $postal_code !== (string) ( $row['postal_code'] ?? '' ) ) {
					continue;
				}
				if ( $only_empty && '' !== trim( (string) ( $row['russianpost_courier_calc_postal_code'] ?? '' ) ) ) {
					continue;
				}
				$this->wpdb->{$property}[ $id ] = array_merge( $this->wpdb->{$property}[ $id ], $data );
				++$count;
			}
			return $count;
		}

		$where = "postal_code = %s";
		$args = array( current_time( 'mysql' ), $calc_postal_code, $postal_code );
		if ( $only_empty ) {
			$where .= " AND (russianpost_courier_calc_postal_code IS NULL OR russianpost_courier_calc_postal_code = '')";
		}
		$result = $this->wpdb->query(
			$this->wpdb->prepare(
				"UPDATE {$this->table_name()} SET updated_at = %s, russianpost_courier_calc_postal_code = %s WHERE {$where}",
				...$args
			)
		);
		if ( false === $result ) {
			$this->throw_sql_error( 'Russian Post courier calc postal_code update failed' );
		}

		return (int) $result;
	}

	public function update_russianpost_courier_calc_postal_code_for_location_id( int $location_id, string $calc_postal_code ): bool {
		if ( $location_id <= 0 ) {
			return false;
		}
		$calc_postal_code = $this->valid_courier_calc_postal_code( $calc_postal_code );
		if ( '' === $calc_postal_code ) {
			return false;
		}

		$data = array(
			'russianpost_courier_calc_postal_code' => $calc_postal_code,
			'updated_at' => current_time( 'mysql' ),
		);
		if ( $this->has_test_location_rows() ) {
			$property = $this->test_location_rows_property();
			if ( ! isset( $this->wpdb->{$property}[ $location_id ] ) ) {
				return false;
			}
			$this->wpdb->{$property}[ $location_id ] = array_merge( $this->wpdb->{$property}[ $location_id ], $data );
			return true;
		}

		$result = $this->wpdb->update( $this->table_name(), $data, array( 'id' => $location_id ), array( '%s', '%s' ), array( '%d' ) );
		if ( false === $result ) {
			$this->throw_sql_error( 'Russian Post courier calc postal_code location update failed' );
		}

		return (int) $result > 0;
	}

	public function clear_russianpost_courier_calc_postal_code_for_location_id( int $location_id ): bool {
		if ( $location_id <= 0 ) {
			return false;
		}

		$data = array(
			'russianpost_courier_calc_postal_code' => '',
			'updated_at' => current_time( 'mysql' ),
		);
		if ( $this->has_test_location_rows() ) {
			$property = $this->test_location_rows_property();
			if ( ! isset( $this->wpdb->{$property}[ $location_id ] ) ) {
				return false;
			}
			$this->wpdb->{$property}[ $location_id ] = array_merge( $this->wpdb->{$property}[ $location_id ], $data );
			return true;
		}

		$result = $this->wpdb->update( $this->table_name(), $data, array( 'id' => $location_id ), array( '%s', '%s' ), array( '%d' ) );
		if ( false === $result ) {
			$this->throw_sql_error( 'Russian Post courier calc postal_code location clear failed' );
		}

		return (int) $result > 0;
	}

	public function clear_russianpost_courier_calc_postal_codes(): int {
		if ( $this->has_test_location_rows() ) {
			$property = $this->test_location_rows_property();
			$count = 0;
			foreach ( $this->wpdb->{$property} as $id => $row ) {
				if ( '' === trim( (string) ( $row['russianpost_courier_calc_postal_code'] ?? '' ) ) ) {
					continue;
				}
				$this->wpdb->{$property}[ $id ]['russianpost_courier_calc_postal_code'] = '';
				$this->wpdb->{$property}[ $id ]['updated_at'] = current_time( 'mysql' );
				++$count;
			}
			return $count;
		}

		$result = $this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->table_name()} SET russianpost_courier_calc_postal_code = '', updated_at = %s WHERE russianpost_courier_calc_postal_code IS NOT NULL AND russianpost_courier_calc_postal_code != ''", current_time( 'mysql' ) ) );
		if ( false === $result ) {
			$this->throw_sql_error( 'Russian Post courier calc postal_code clear failed' );
		}

		return (int) $result;
	}

	public function resolve_russianpost_courier_calc_postal_code_for_checkout_postcode( string $postal_code ): string {
		$postal_code = $this->valid_six_digit_postcode( $postal_code );
		if ( '' !== $postal_code ) {
			$row = $this->find_russianpost_courier_calc_postcode_row_by_postal_code( $postal_code );
			$value = $this->valid_six_digit_postcode( (string) ( $row['russianpost_courier_calc_postal_code'] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return $postal_code;
	}

	public function update_coordinates( int $location_id, float $latitude, float $longitude ): bool {
		if ( $location_id <= 0 || $latitude < -90.0 || $latitude > 90.0 || $longitude < -180.0 || $longitude > 180.0 ) {
			return false;
		}

		$data = array(
			'latitude'   => $latitude,
			'longitude'  => $longitude,
			'updated_at' => current_time( 'mysql' ),
		);
		if ( $this->has_test_location_rows() ) {
			$property = $this->test_location_rows_property();
			if ( ! isset( $this->wpdb->{$property}[ $location_id ] ) ) {
				return false;
			}
			$this->wpdb->{$property}[ $location_id ] = array_merge( $this->wpdb->{$property}[ $location_id ], $data );
			return true;
		}

		$result = $this->wpdb->update( $this->table_name(), $data, array( 'id' => $location_id ), array( '%f', '%f', '%s' ), array( '%d' ) );
		if ( false === $result ) {
			$this->throw_sql_error( 'Location coordinates update failed' );
		}

		return true;
	}

	public function clear_postal_code_marker( string $marker = '999999999' ): int {
		if ( $this->has_test_location_rows() ) {
			$property = $this->test_location_rows_property();
			$count = 0;
			foreach ( $this->wpdb->{$property} as $id => $row ) {
				if ( $marker === (string) ( $row['postal_code'] ?? '' ) ) {
					$this->wpdb->{$property}[ $id ]['postal_code'] = '';
					$this->wpdb->{$property}[ $id ]['updated_at'] = current_time( 'mysql' );
					++$count;
				}
			}
			return $count;
		}

		$result = $this->wpdb->query( $this->wpdb->prepare( "UPDATE {$this->table_name()} SET postal_code = '', updated_at = %s WHERE postal_code = %s", current_time( 'mysql' ), $marker ) );
		if ( false === $result ) {
			$this->throw_sql_error( 'Location postal_code marker cleanup failed' );
		}

		return (int) $result;
	}

	/**
	 * @return array{region:array<int,string>, city:array<int,string>, place:array<int,string>}
	 */
	public function distinct_location_types(): array {
		if ( $this->has_test_location_rows() ) {
			$types = array( 'region' => array(), 'city' => array(), 'place' => array() );
			foreach ( $this->test_location_rows() as $row ) {
				foreach ( array( 'region' => 'region_type', 'city' => 'city_type', 'place' => 'place_type' ) as $scope => $column ) {
					$value = trim( (string) ( $row[ $column ] ?? '' ) );
					if ( '' !== $value ) {
						$types[ $scope ][ $value ] = $value;
					}
				}
			}
			foreach ( $types as $scope => $values ) {
				$types[ $scope ] = array_values( $values );
				sort( $types[ $scope ] );
			}
			return $types;
		}

		return array(
			'region' => $this->distinct_column_values( 'region_type' ),
			'city'   => $this->distinct_column_values( 'city_type' ),
			'place'  => $this->distinct_column_values( 'place_type' ),
		);
	}

	/**
	 * @return array{locations_deleted:int|null, aliases_deleted:int|null, regions_deleted:int|null, delivery_codes_deleted:int|null}
	 */
	public function clear_all(): array {
		$result = array(
			'delivery_codes_deleted' => $this->clear_table( $this->delivery_codes_table_name() ),
			'aliases_deleted'       => $this->clear_table( $this->alias_table_name() ),
			'locations_deleted'     => $this->clear_table( $this->table_name() ),
			'regions_deleted'       => $this->clear_table( $this->region_table_name() ),
		);
		$this->mark_country_index_stale();
		return $result;
	}

	/**
	 * @param array<int,string> $aliases
	 */
	public function save_aliases( int $location_id, array $aliases, string $source = 'generated' ): void {
		$location_id = max( 0, $location_id );
		if ( 0 === $location_id ) {
			return;
		}

		$now = current_time( 'mysql' );
		foreach ( array_values( array_unique( array_filter( array_map( 'trim', $aliases ) ) ) ) as $alias ) {
			$this->wpdb->insert(
				$this->alias_table_name(),
				array(
					'location_id'      => $location_id,
					'alias'            => $alias,
					'alias_normalized' => Location::normalize_search_text( $alias ),
					'source'           => $source,
					'created_at'       => $now,
				),
				array( '%d', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * @param array<int,array<int,string>> $location_id_to_aliases
	 */
	public function bulk_save_aliases( array $location_id_to_aliases, string $source = 'generated' ): int {
		$location_ids = array_values( array_filter( array_map( 'intval', array_keys( $location_id_to_aliases ) ) ) );
		if ( array() === $location_ids ) {
			return 0;
		}

		if ( property_exists( $this->wpdb, 'aliases' ) ) {
			$count = 0;
			foreach ( $location_id_to_aliases as $location_id => $aliases ) {
				$this->save_aliases( (int) $location_id, $aliases, $source );
				$count += count( array_unique( array_filter( array_map( 'trim', $aliases ) ) ) );
			}

			return $count;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $location_ids ), '%d' ) );
		$args = $location_ids;
		array_unshift( $args, $source );
		$result = $this->wpdb->query( $this->wpdb->prepare( "DELETE FROM {$this->alias_table_name()} WHERE source = %s AND location_id IN ({$placeholders})", ...$args ) );
		if ( false === $result ) {
			$this->throw_sql_error( 'Location aliases cleanup failed' );
		}

		$now = current_time( 'mysql' );
		$rows = array();
		foreach ( $location_id_to_aliases as $location_id => $aliases ) {
			foreach ( array_values( array_unique( array_filter( array_map( 'trim', $aliases ) ) ) ) as $alias ) {
				$rows[] = array(
					'location_id'      => (int) $location_id,
					'alias'            => $alias,
					'alias_normalized' => Location::normalize_search_text( $alias ),
					'source'           => $source,
					'created_at'       => $now,
				);
			}
		}

		if ( array() === $rows ) {
			return 0;
		}

		$this->bulk_insert_rows( $this->alias_table_name(), $rows, array( 'alias = VALUES(alias)' ), array( '%d', '%s', '%s', '%s', '%s' ), true );

		return count( $rows );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function find_raw_by_id( int $id ): array {
		if ( $id <= 0 ) {
			return array();
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table_name()} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $row : array();
	}

	/**
	 * @return array<int,Location>
	 */
	public function find_batch_after_id( int $after_id, int $limit, string $country_code = 'RU', bool $require_display_name = true ): array {
		$after_id = max( 0, $after_id );
		$limit = max( 1, min( 1000, $limit ) );
		$country_code = strtoupper( trim( $country_code ) );
		if ( $this->has_test_location_rows() ) {
			$rows = array_values(
				array_filter(
					$this->test_location_rows(),
					static function ( array $row ) use ( $after_id, $country_code, $require_display_name ): bool {
						if ( (int) ( $row['id'] ?? 0 ) <= $after_id || 1 !== (int) ( $row['active'] ?? 1 ) ) {
							return false;
						}
						if ( '' !== $country_code && strtoupper( (string) ( $row['country_code'] ?? '' ) ) !== $country_code ) {
							return false;
						}
						if ( $require_display_name && '' === (string) ( $row['display_name'] ?? '' ) ) {
							return false;
						}
						return true;
					}
				)
			);
			usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
			return $this->rows_to_locations( array_slice( $rows, 0, $limit ) );
		}

		$where = array( 'active = 1', 'id > %d' );
		$args = array( $after_id );
		if ( '' !== $country_code ) {
			$where[] = 'country_code = %s';
			$args[] = $country_code;
		}
		if ( $require_display_name ) {
			$where[] = "display_name IS NOT NULL AND display_name != ''";
		}
		$args[] = $limit;

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id ASC LIMIT %d', ...$args ),
			ARRAY_A
		);
		return $this->rows_to_locations( is_array( $rows ) ? $rows : array() );
	}
	public function count_batch_locations( string $country_code = 'RU', bool $require_display_name = true ): int {
		$country_code = strtoupper( trim( $country_code ) );
		if ( $this->has_test_location_rows() ) {
			$count = 0;
			foreach ( $this->test_location_rows() as $row ) {
				if ( 1 !== (int) ( $row['active'] ?? 1 ) ) {
					continue;
				}
				if ( '' !== $country_code && strtoupper( (string) ( $row['country_code'] ?? '' ) ) !== $country_code ) {
					continue;
				}
				if ( $require_display_name && '' === (string) ( $row['display_name'] ?? '' ) ) {
					continue;
				}
				++$count;
			}
			return $count;
		}

		$where = array( 'active = 1' );
		$args = array();
		if ( '' !== $country_code ) {
			$where[] = 'country_code = %s';
			$args[] = $country_code;
		}
		if ( $require_display_name ) {
			$where[] = "display_name IS NOT NULL AND display_name != ''";
		}
		$sql = 'SELECT COUNT(*) FROM ' . $this->table_name() . ' WHERE ' . implode( ' AND ', $where );
		$value = array() === $args ? $this->wpdb->get_var( $sql ) : $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$args ) );

		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}
	public function update_display_fields( Location $location, string $display_name ): bool {
		if ( null === $location->id || $location->id <= 0 ) {
			return false;
		}
		$updated = Location::from_array( array_merge( $location->to_array(), array( 'display_name' => $display_name ) ) );
		$data = array(
			'display_name'    => $display_name,
			'searchable_text' => $updated->get_searchable_text(),
			'updated_at'      => current_time( 'mysql' ),
		);
		if ( $this->has_test_location_rows() ) {
			$id = (int) $location->id;
			$property = $this->test_location_rows_property();
			if ( ! isset( $this->wpdb->{$property}[ $id ] ) ) {
				return false;
			}
			$this->wpdb->{$property}[ $id ] = array_merge( $this->wpdb->{$property}[ $id ], $data );
			return true;
		}

		$result = $this->wpdb->update( $this->table_name(), $data, array( 'id' => $location->id ), array( '%s', '%s', '%s' ), array( '%d' ) );
		if ( false === $result ) {
			$this->throw_sql_error( 'Location display_name update failed' );
		}
		return true;
	}

	public function delete_all(): void {
		$this->wpdb->query( "DELETE FROM {$this->table_name()}" );
		$this->mark_country_index_stale();
	}

	private function clear_table( string $table ): ?int {
		if ( ! $this->table_exists( $table ) ) {
			return null;
		}

		$count = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$result = $this->wpdb->query( "TRUNCATE TABLE {$table}" );

		if ( false === $result ) {
			$result = $this->wpdb->query( "DELETE FROM {$table}" );
		}

		if ( false === $result ) {
			throw new RuntimeException( 'Unable to clear locations table.' );
		}

		return $count;
	}

	private function table_exists( string $table ): bool {
		$prepared = $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $table );
		$result = $this->wpdb->get_var( $prepared );
		return ! in_array( $result, array( null, '', 0, '0' ), true );
	}

	private function find_one( string $column, int|string $value, string $format ): ?Location {
		if ( '' === (string) $value || ! preg_match( '/^[a-z0-9_]+$/i', $column ) ) {
			return null;
		}

		if ( $this->has_test_location_rows() ) {
			foreach ( $this->test_location_rows() as $row ) {
				if ( (string) ( $row[ $column ] ?? '' ) === (string) $value ) {
					return $this->row_to_location( $this->join_region_for_test_double( $row ) );
				}
			}
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
				FROM {$this->table_name()} l
				LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
				WHERE l.{$column} = {$format}
				LIMIT 1",
				$value
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->row_to_location( $row ) : null;
	}

	/**
	 * @param array<int, array<string,mixed>> $rows
	 * @return array<int, Location>
	 */
	private function rows_to_locations( array $rows ): array {
		$locations = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$locations[] = $this->row_to_location( $row );
			}
		}

		return $locations;
	}

	/**
	 * @return array<int,string>
	 */
	private function distinct_column_values( string $column ): array {
		if ( ! preg_match( '/^[a-z0-9_]+$/i', $column ) ) {
			return array();
		}
		$rows = $this->wpdb->get_results( "SELECT DISTINCT {$column} AS value FROM {$this->table_name()} WHERE {$column} IS NOT NULL AND {$column} != '' ORDER BY {$column} ASC", ARRAY_A );
		return array_values( array_filter( array_map( static fn( array $row ): string => (string) ( $row['value'] ?? '' ), is_array( $rows ) ? $rows : array() ) ) );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function rank_row( array $row, string $query ): int {
		$place = $this->normalize_query( (string) ( $row['place_name'] ?? $row['settlement_name'] ?? '' ) );
		$city = $this->normalize_query( (string) ( $row['city_name'] ?? '' ) );
		$district = $this->normalize_query( (string) ( $row['district_name'] ?? '' ) );
		$region = $this->normalize_query( (string) ( $row['region_name'] ?? '' ) );
		return match ( true ) {
			$place === $query => 1,
			'' !== $place && str_starts_with( $place, $query ) => 2,
			'' !== $place && str_contains( $place, $query ) => 3,
			$city === $query => 4,
			'' !== $city && str_starts_with( $city, $query ) => 5,
			'' !== $city && str_contains( $city, $query ) => 6,
			'' !== $district && str_contains( $district, $query ) => 7,
			'' !== $region && str_contains( $region, $query ) => 8,
			default => 9,
		};
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function join_region_for_test_double( array $row ): array {
		if ( ! property_exists( $this->wpdb, 'regions' ) ) {
			return $row;
		}
		$region = $this->wpdb->regions[ (string) ( $row['region_code'] ?? '' ) ] ?? array();
		$row['joined_region_name'] = $region['region_name'] ?? $row['region_name'] ?? '';
		$row['joined_region_type'] = $region['region_type'] ?? '';
		return $row;
	}

	/**
	 * @param array<int,array<string,mixed>> $direct_rows
	 * @param array<int,array<string,mixed>> $broad_rows
	 * @return array<int,array<string,mixed>>
	 */
	private function merge_location_rows_by_id( array $direct_rows, array $broad_rows, int $limit ): array {
		$merged = array();
		foreach ( array_merge( $direct_rows, $broad_rows ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = (int) ( $row['id'] ?? 0 );
			$key = $id > 0 ? 'id:' . $id : md5( json_encode( $row ) ?: serialize( $row ) );
			if ( isset( $merged[ $key ] ) ) {
				continue;
			}
			$merged[ $key ] = $row;
			if ( count( $merged ) >= $limit ) {
				break;
			}
		}

		return array_values( $merged );
	}

	/**
	 * @param array<int,Location> $locations
	 * @return array<int,Location>
	 */
	private function deduplicate_locations_by_id( array $locations ): array {
		$deduplicated = array();
		foreach ( $locations as $location ) {
			$key = null !== $location->id && $location->id > 0 ? 'id:' . $location->id : spl_object_hash( $location );
			$deduplicated[ $key ] = $location;
		}

		return array_values( $deduplicated );
	}

	/**
	 * @param array<int,string> $base_where
	 * @param array<int,mixed> $base_args
	 * @param array<int,string> $tokens
	 * @param array<int,string> $columns
	 * @return array<int,array<string,mixed>>
	 */
	private function checkout_hierarchy_query_rows( array $base_where, array $base_args, array $tokens, array $columns, bool $include_identifiers, int $limit ): array {
		$where = $base_where;
		$args = $base_args;
		$exact_args = array();
		$prefix_args = array();

		if ( array() !== $tokens ) {
			$token_where = array();
			$exact_parts = array();
			$prefix_parts = array();
			foreach ( $tokens as $token ) {
				foreach ( $columns as $column ) {
					$token_where[] = "l.{$column} LIKE %s";
					$args[] = $this->wpdb->esc_like( $token ) . '%';
					$exact_parts[] = "LOWER(l.{$column}) = %s";
					$exact_args[] = $token;
					$prefix_parts[] = "l.{$column} LIKE %s";
					$prefix_args[] = $this->wpdb->esc_like( $token ) . '%';
				}
				if ( $include_identifiers ) {
					$token_where[] = 'l.fias_id = %s';
					$args[] = $token;
					$token_where[] = 'l.kladr_id = %s';
					$args[] = $token;
					if ( is_numeric( $token ) ) {
						$token_where[] = 'l.gar_object_id = %d';
						$args[] = (int) $token;
					}
				}
			}
			$where[] = '(' . implode( ' OR ', $token_where ) . ')';
		}

		$order_sql = '';
		if ( array() !== $tokens ) {
			$order_sql = 'CASE
					WHEN ' . implode( ' OR ', $exact_parts ) . ' THEN 0
					WHEN ' . implode( ' OR ', $prefix_parts ) . ' THEN 1
					ELSE 2
				END, ';
		}

		$args = array_merge( $args, $exact_args, $prefix_args, array( $limit ) );
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
				FROM {$this->table_name()} l
				LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
				WHERE " . implode( ' AND ', $where ) . "
				ORDER BY {$order_sql}l.display_name ASC
				LIMIT %d",
				...$args
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<int,string> $tokens
	 */
	private function row_has_checkout_direct_prefix_match( array $row, array $tokens ): bool {
		foreach ( $tokens as $token ) {
			foreach ( array( 'place_name', 'city_name', 'settlement_name' ) as $column ) {
				$value = $this->normalize_query( (string) ( $row[ $column ] ?? '' ) );
				if ( '' !== $value && ( $value === $token || str_starts_with( $value, $token ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<int,string> $tokens
	 * @return array{0:int,1:string,2:int}
	 */
	private function checkout_direct_row_rank( array $row, array $tokens ): array {
		$rank = 2;
		foreach ( $tokens as $token ) {
			foreach ( array( 'place_name', 'city_name', 'settlement_name' ) as $column ) {
				$value = $this->normalize_query( (string) ( $row[ $column ] ?? '' ) );
				if ( '' === $value ) {
					continue;
				}
				if ( $value === $token ) {
					$rank = min( $rank, 0 );
					continue;
				}
				if ( str_starts_with( $value, $token ) ) {
					$rank = min( $rank, 1 );
				}
			}
		}

		return array(
			$rank,
			$this->normalize_query( (string) ( $row['display_name'] ?? '' ) ),
			max( 0, (int) ( $row['id'] ?? 0 ) ),
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @param array<int,string> $tokens
	 */
	private function row_has_checkout_prefix_match( array $row, array $tokens ): bool {
		foreach ( $tokens as $token ) {
			foreach ( array( 'region_name', 'district_name', 'city_name', 'place_name', 'settlement_name', 'fias_id', 'kladr_id', 'gar_object_id' ) as $column ) {
				$value = $this->normalize_query( (string) ( $row[ $column ] ?? '' ) );
				if ( '' !== $value && ( $value === $token || str_starts_with( $value, $token ) || ( is_numeric( $token ) && $value === $token ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function row_to_location( array $row ): Location {
		if ( isset( $row['joined_region_name'] ) && '' !== (string) $row['joined_region_name'] ) {
			$row['region_name'] = $row['joined_region_name'];
		}

		if ( isset( $row['joined_region_type'] ) && '' !== (string) $row['joined_region_type'] ) {
			$row['region_type'] = $row['joined_region_type'];
		}

		return Location::from_array( $row );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function location_to_row( Location $location, string $now ): array {
		$display     = $location->resolved_display_name();
		$place_name  = $location->resolved_place_name();
		$place_type  = $location->resolved_place_type();
		$gar_object_id = $location->gar_object_id > 0 ? $location->gar_object_id : null;
		$fias_id = trim( $location->fias_id );
		$gar_id = trim( $location->gar_id );

		return array(
			'gar_object_id'          => $gar_object_id,
			'fias_id'                => '' !== $fias_id ? $fias_id : null,
			'kladr_id'               => $location->kladr_id,
			'gar_id'                 => '' !== $gar_id ? $gar_id : ( null !== $gar_object_id ? (string) $gar_object_id : '' ),
			'country_code'           => '' !== $location->country_code ? $location->country_code : 'RU',
			'region_name'            => $location->region_name,
			'region_code'            => $location->region_code,
			'region_type'            => $location->region_type,
			'district_name'          => $location->district_name,
			'district_type'          => $location->district_type,
			'district_fias_id'       => $location->district_fias_id,
			'district_kladr_id'      => $location->district_kladr_id,
			'district_gar_object_id' => $location->district_gar_object_id > 0 ? $location->district_gar_object_id : null,
			'district_level'         => $location->district_level,
			'city_name'              => $location->city_name,
			'city_type'              => $location->city_type,
			'city_fias_id'           => $location->city_fias_id,
			'city_kladr_id'          => $location->city_kladr_id,
			'settlement_name'        => $place_name,
			'settlement_type'        => $place_type,
			'place_name'             => $place_name,
			'place_type'             => $place_type,
			'place_level'            => max( 0, $location->place_level ),
			'display_name'           => $display,
			'postal_code'            => $location->postal_code,
			'russianpost_courier_calc_postal_code' => $location->russianpost_courier_calc_postal_code,
			'okato'                  => $location->okato,
			'oktmo'                  => $location->oktmo,
			'latitude'               => $location->latitude,
			'longitude'              => $location->longitude,
			'searchable_text'        => $location->get_searchable_text(),
			'active'                 => $location->active ? 1 : 0,
			'created_at'             => $now,
			'updated_at'             => $now,
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function formats( bool $with_created_at = true ): array {
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%d' );
		if ( $with_created_at ) {
			$formats[] = '%s';
		}
		$formats[] = '%s';

		return $formats;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @param array<int,string> $update_assignments
	 * @param array<int,string> $formats
	 */
	private function bulk_insert_rows( string $table, array $rows, array $update_assignments, array $formats, bool $ignore = false ): void {
		if ( array() === $rows ) {
			return;
		}

		$columns = array_keys( $rows[0] );
		$values = array();
		$args = array();
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $columns as $index => $column ) {
				$value = $row[ $column ] ?? null;
				if ( null === $value ) {
					$cells[] = 'NULL';
					continue;
				}
				$cells[] = $formats[ $index ] ?? '%s';
				$args[] = $value;
			}
			$values[] = '(' . implode( ', ', $cells ) . ')';
		}
		$values_sql = implode( ', ', $values );
		$sql = sprintf(
			'INSERT %sINTO %s (%s) VALUES %s',
			$ignore ? 'IGNORE ' : '',
			$table,
			implode( ', ', $columns ),
			$values_sql
		);

		if ( ! $ignore && array() !== $update_assignments ) {
			$sql .= ' ON DUPLICATE KEY UPDATE ' . implode( ', ', $update_assignments );
		}

		$result = $this->wpdb->query( array() !== $args ? $this->wpdb->prepare( $sql, ...$args ) : $sql );
		if ( false === $result ) {
			$this->throw_sql_error( str_contains( $table, 'wdc_locations' ) ? 'Location bulk upsert failed' : 'Location bulk insert failed' );
		}
	}

	private function throw_sql_error( string $message ): never {
		$error = trim( (string) ( $this->wpdb->last_error ?? '' ) );
		$error = preg_replace( '/[\r\n\t]+/', ' ', $error ) ?? $error;
		throw new RuntimeException( trim( $message . ': ' . ( '' !== $error ? $error : 'unknown SQL error' ) ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<int,string>
	 */
	private function formats_for_row( array $row ): array {
		$formats = array();
		foreach ( array_keys( $row ) as $column ) {
			$formats[] = match ( $column ) {
				'gar_object_id', 'district_gar_object_id', 'district_level', 'place_level', 'active' => '%d',
				'latitude', 'longitude' => '%f',
				default => '%s',
			};
		}

		return $formats;
	}

	/**
	 * @param array<int,int> $gar_object_ids
	 * @return array<int,int>
	 */
	private function location_ids_by_gar_object_ids( array $gar_object_ids ): array {
		$gar_object_ids = array_values( array_unique( array_filter( array_map( 'intval', $gar_object_ids ) ) ) );
		if ( array() === $gar_object_ids ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $gar_object_ids ), '%d' ) );
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT id, gar_object_id FROM {$this->table_name()} WHERE gar_object_id IN ({$placeholders})", ...$gar_object_ids ),
			ARRAY_A
		);
		$ids = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$ids[ (int) $row['gar_object_id'] ] = (int) $row['id'];
		}

		return $ids;
	}

	private function normalize_query( string $query ): string {
		return Location::normalize_search_text( $query );
	}

	private function normalize_foreign_identity_value( string $value ): string {
		$value = strtr( trim( $value ), array( 'Ё' => 'Е', 'ё' => 'е' ) );
		$value = mb_strtolower( $value, 'UTF-8' );
		$value = preg_replace( '/[.]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		$value = trim( $value );

		return $this->normalize_query( $value );
	}

	private function normalize_foreign_identity_type( string $value ): string {
		$value = $this->normalize_foreign_identity_value( $value );
		if ( in_array( $value, array( 'd', 'д', 'деревня', 'derevnya' ), true ) ) {
			return 'д';
		}
		return match ( $value ) {
			'g', 'г', 'город' => 'г',
			'p', 'п', 'пос', 'поселок', 'посёлок' => 'п',
			's', 'с', 'село' => 'с',
			'рп', 'рабочий поселок', 'рабочий посёлок' => 'рп',
			'пгт' => 'пгт',
			'ст' => 'ст',
			'аул' => 'аул',
			default => $value,
		};
	}

	/**
	 * @return array<int,string>
	 */
	private function foreign_identity_prefilter_tokens( string ...$values ): array {
		$tokens = array();
		foreach ( $values as $value ) {
			$normalized = $this->normalize_foreign_identity_value( $value );
			if ( '' === $normalized ) {
				continue;
			}
			foreach ( preg_split( '/\s+/u', $normalized ) ?: array() as $token ) {
				$token = trim( (string) $token );
				if ( '' === $token || ! preg_match( '/[\p{L}\p{N}]/u', $token ) ) {
					continue;
				}
				$tokens[ $token ] = true;
				if ( count( $tokens ) >= 12 ) {
					break 2;
				}
			}
		}

		return array_keys( $tokens );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function foreign_identity_row_matches( array $row, string $place_key, string $region_key, string $district_key, string $place_type_key ): bool {
		$row_place = $this->normalize_foreign_identity_value( (string) ( $row['place_name'] ?? $row['settlement_name'] ?? $row['city_name'] ?? '' ) );
		$row_region = $this->normalize_foreign_identity_value( (string) ( $row['region_name'] ?? '' ) );
		$row_district = $this->normalize_foreign_identity_value( (string) ( $row['district_name'] ?? '' ) );
		$row_place_type = $this->normalize_foreign_identity_type( (string) ( $row['place_type'] ?? $row['settlement_type'] ?? $row['city_type'] ?? '' ) );

		return $row_place === $place_key
			&& $row_region === $region_key
			&& $row_district === $district_key
			&& $row_place_type === $place_type_key;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,Location>
	 */
	private function active_place_region_base_matches_for_rows( array $rows, string $place_key, string $region_key, string $country_code ): array {
		$matches = array();
		foreach ( $rows as $row ) {
			if ( 1 !== (int) ( $row['active'] ?? 1 ) ) {
				continue;
			}
			if ( '' !== $country_code && $country_code !== $this->normalize_country_code( (string) ( $row['country_code'] ?? '' ) ) ) {
				continue;
			}
			if ( $this->active_place_region_row_matches( $row, $place_key, $region_key, '' ) ) {
				$matches[] = $this->row_to_location( $this->join_region_for_test_double( $row ) );
			}
		}

		return $this->deduplicate_locations_by_id( $matches );
	}

	/**
	 * @param array<int,Location> $base_matches
	 */
	private function resolve_place_region_base_matches( array $base_matches, string $place_type_key ): PlaceRegionMatchResult {
		if ( array() === $base_matches ) {
			return new PlaceRegionMatchResult( array(), PlaceRegionMatchResult::NOT_FOUND );
		}
		if ( '' === $place_type_key ) {
			return new PlaceRegionMatchResult( $base_matches, PlaceRegionMatchResult::EXACT );
		}

		$exact_type_matches = array();
		$empty_type_matches = array();
		foreach ( $base_matches as $location ) {
			$location_type = $this->normalize_foreign_identity_type( $location->resolved_place_type() );
			if ( $location_type === $place_type_key ) {
				$exact_type_matches[] = $location;
				continue;
			}
			if ( '' === $location_type ) {
				$empty_type_matches[] = $location;
			}
		}
		if ( array() !== $exact_type_matches ) {
			return new PlaceRegionMatchResult( $exact_type_matches, PlaceRegionMatchResult::EXACT );
		}
		if ( array() !== $empty_type_matches ) {
			return new PlaceRegionMatchResult( $empty_type_matches, PlaceRegionMatchResult::EMPTY_TYPE_FALLBACK );
		}

		return new PlaceRegionMatchResult( array(), PlaceRegionMatchResult::TYPE_MISMATCH );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,Location>
	 */
	private function active_place_region_matches_for_rows( array $rows, string $place_key, string $region_key, string $place_type_key, bool $require_empty_place_type = false ): array {
		$matches = array();
		foreach ( $rows as $row ) {
			if ( 1 !== (int) ( $row['active'] ?? 1 ) ) {
				continue;
			}
			if ( $this->active_place_region_row_matches( $row, $place_key, $region_key, $place_type_key, $require_empty_place_type ) ) {
				$matches[] = $this->row_to_location( $this->join_region_for_test_double( $row ) );
			}
		}

		return $matches;
	}

	private function active_place_region_row_matches( array $row, string $place_key, string $region_key, string $place_type_key, bool $require_empty_place_type = false ): bool {
		$row_region = $this->normalize_foreign_identity_value( (string) ( $row['joined_region_name'] ?? $row['region_name'] ?? '' ) );
		if ( $row_region !== $region_key ) {
			return false;
		}
		if ( '' !== $place_type_key || $require_empty_place_type ) {
			$row_place_type = $this->normalize_foreign_identity_type( (string) ( $row['place_type'] ?? $row['settlement_type'] ?? $row['city_type'] ?? '' ) );
			if ( $require_empty_place_type && '' !== $row_place_type ) {
				return false;
			}
			if ( $row_place_type !== $place_type_key ) {
				return false;
			}
		}

		foreach ( array( 'place_name', 'settlement_name', 'city_name' ) as $column ) {
			$row_place = $this->normalize_foreign_identity_value( (string) ( $row[ $column ] ?? '' ) );
			if ( '' !== $row_place && $row_place === $place_key ) {
				return true;
			}
		}

		return false;
	}

	private function normalize_guid( string $value ): string {
		$normalized = strtolower( preg_replace( '/[^a-f0-9]/i', '', $value ) ?? '' );
		return 32 === strlen( $normalized ) ? $normalized : '';
	}

	private function normalize_country_code( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );
		return preg_match( '/^[A-Z]{2}$/', $country_code ) ? $country_code : '';
	}

	private function valid_six_digit_postcode( string $postcode ): string {
		$postcode = preg_replace( '/\D+/', '', $postcode ) ?? '';
		if ( '' === $postcode || '999999999' === $postcode ) {
			return '';
		}

		return preg_match( '/^\d{6}$/', $postcode ) ? $postcode : '';
	}

	private function valid_courier_calc_postal_code( string $postcode ): string {
		$postcode = preg_replace( '/\D+/', '', $postcode ) ?? '';
		if ( RussianPostCourierCalcPostcodeFillStateService::COURIER_POSTCODE_TECHNICAL_ERROR === $postcode ) {
			return $postcode;
		}

		return $this->valid_six_digit_postcode( $postcode );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function find_russianpost_courier_calc_postcode_row_by_postal_code( string $postal_code ): ?array {
		$postal_code = $this->valid_six_digit_postcode( $postal_code );
		if ( '' === $postal_code ) {
			return null;
		}
		if ( $this->has_test_location_rows() ) {
			foreach ( $this->test_location_rows() as $row ) {
				if (
					1 === (int) ( $row['active'] ?? 1 )
					&& $postal_code === (string) ( $row['postal_code'] ?? '' )
					&& '' !== trim( (string) ( $row['russianpost_courier_calc_postal_code'] ?? '' ) )
				) {
					return $row;
				}
			}
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT russianpost_courier_calc_postal_code FROM {$this->table_name()} WHERE active = 1 AND postal_code = %s AND russianpost_courier_calc_postal_code IS NOT NULL AND russianpost_courier_calc_postal_code != '' LIMIT 1",
				$postal_code
			),
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function row_country_matches( array $row, string $country_code ): bool {
		return '' === $country_code || $this->normalize_country_code( (string) ( $row['country_code'] ?? '' ) ) === $country_code;
	}

	private function mark_country_index_stale(): void {
		if ( class_exists( \WallsShop\WDC\Locations\Services\LocationCountryIndexService::class ) ) {
			\WallsShop\WDC\Locations\Services\LocationCountryIndexService::mark_option_stale();
		}
	}

	private function has_test_location_rows(): bool {
		return is_array( $this->wpdb->locations ?? null ) || is_array( $this->wpdb->rows ?? null ) || is_array( $this->wpdb->location_rows ?? null );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function location_missing_coordinates_row( array $row ): bool {
		$latitude = $row['latitude'] ?? null;
		$longitude = $row['longitude'] ?? null;
		return ! is_numeric( $latitude )
			|| ! is_numeric( $longitude )
			|| 0.0 === (float) $latitude
			|| 0.0 === (float) $longitude;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function is_ru_location_row( array $row ): bool {
		if ( 1 !== (int) ( $row['active'] ?? 1 ) ) {
			return false;
		}
		$country = $this->normalize_country_code( (string) ( $row['country_code'] ?? '' ) );
		return '' === $country || 'RU' === $country;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function is_city_location_row( array $row ): bool {
		if (
			in_array( (string) ( $row['place_type'] ?? '' ), array( 'г', 'г.' ), true )
			|| in_array( (string) ( $row['city_type'] ?? '' ), array( 'г', 'г.' ), true )
			|| in_array( (string) ( $row['settlement_type'] ?? '' ), array( 'г', 'г.' ), true )
		) {
			return true;
		}
		return in_array( (string) ( $row['place_type'] ?? '' ), array( 'Рі', 'Рі.', 'г', 'г.' ), true )
			|| in_array( (string) ( $row['city_type'] ?? '' ), array( 'Рі', 'Рі.', 'г', 'г.' ), true );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function test_location_rows(): array {
		if ( property_exists( $this->wpdb, 'locations' ) ) {
			return is_array( $this->wpdb->locations ) ? $this->wpdb->locations : array();
		}
		if ( property_exists( $this->wpdb, 'location_rows' ) ) {
			return is_array( $this->wpdb->location_rows ) ? $this->wpdb->location_rows : array();
		}
		return property_exists( $this->wpdb, 'rows' ) && is_array( $this->wpdb->rows ) ? $this->wpdb->rows : array();
	}

	private function test_location_rows_property(): string {
		if ( property_exists( $this->wpdb, 'locations' ) ) {
			return 'locations';
		}
		if ( property_exists( $this->wpdb, 'location_rows' ) ) {
			return 'location_rows';
		}
		return 'rows';
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_locations';
	}

	private function alias_table_name(): string {
		return $this->wpdb->prefix . 'wdc_location_aliases';
	}

	private function region_table_name(): string {
		return $this->wpdb->prefix . 'wdc_regions';
	}

	private function delivery_codes_table_name(): string {
		return $this->wpdb->prefix . 'wdc_location_delivery_codes';
	}
}
