<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Storage;

use RuntimeException;
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
			$this->wpdb->update( $this->table_name(), $data, array( 'id' => $location->id ), $this->formats( false ), array( '%d' ) );
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
			$this->wpdb->update( $this->table_name(), $data, array( 'id' => $existing->id ), $this->formats( false ), array( '%d' ) );
			if ( $country_changed ) {
				$this->mark_country_index_stale();
			}
			return $existing->id;
		}

		$this->wpdb->insert( $this->table_name(), $data, $this->formats() );
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
		if ( property_exists( $this->wpdb, 'locations' ) ) {
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

	public function find_by_kladr_id( string $kladr_id ): ?Location {
		return $this->find_one( 'kladr_id', trim( $kladr_id ), '%s' );
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
				if ( array() === $tokens || $this->row_has_checkout_prefix_match( $row, $tokens ) ) {
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

		if ( array() !== $tokens ) {
			$token_where = array();
			foreach ( $tokens as $token ) {
				foreach ( array( 'region_name', 'district_name', 'city_name', 'place_name', 'settlement_name' ) as $column ) {
					$token_where[] = "l.{$column} LIKE %s";
					$args[] = $this->wpdb->esc_like( $token ) . '%';
				}
				$token_where[] = 'l.fias_id = %s';
				$args[] = $token;
				$token_where[] = 'l.kladr_id = %s';
				$args[] = $token;
				if ( is_numeric( $token ) ) {
					$token_where[] = 'l.gar_object_id = %d';
					$args[] = (int) $token;
				}
			}
			$where[] = '(' . implode( ' OR ', $token_where ) . ')';
		}
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
					static fn( array $row ): bool => '' !== trim( (string) ( $row['russianpost_courier_calc_postal_code'] ?? '' ) )
				)
			);
		}

		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table_name()} WHERE russianpost_courier_calc_postal_code IS NOT NULL AND russianpost_courier_calc_postal_code != ''" );
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
		$priority = 'others' === $priority ? 'others' : 'cities';
		if ( $this->has_test_location_rows() ) {
			$rows = array_values(
				array_filter(
					$this->test_location_rows(),
					function ( array $row ) use ( $after_id, $priority ): bool {
						if (
							(int) ( $row['id'] ?? 0 ) <= $after_id
							|| ! $this->is_ru_location_row( $row )
							|| '' === trim( (string) ( $row['postal_code'] ?? '' ) )
							|| '999999999' === trim( (string) ( $row['postal_code'] ?? '' ) )
							|| '' !== trim( (string) ( $row['russianpost_courier_calc_postal_code'] ?? '' ) )
						) {
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

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT *
				FROM {$this->table_name()}
				WHERE id > %d
					AND active = 1
					AND country_code = 'RU'
					AND postal_code IS NOT NULL
					AND postal_code != ''
					AND postal_code != '999999999'
					AND (russianpost_courier_calc_postal_code IS NULL OR russianpost_courier_calc_postal_code = '')
					{$type_sql}
				ORDER BY id ASC
				LIMIT 1",
				$after_id
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
	 * @return array{locations_deleted:int|null, aliases_deleted:int|null, regions_deleted:int|null, carrier_codes_deleted:int|null}
	 */
	public function clear_all(): array {
		$result = array(
			'carrier_codes_deleted' => $this->clear_table( $this->carrier_codes_table_name() ),
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
	public function find_batch_after_id( int $last_id, int $limit = 500 ): array {
		$limit = max( 1, min( 1000, $limit ) );
		if ( $this->has_test_location_rows() ) {
			$rows = array_values( array_filter( $this->test_location_rows(), static fn( array $row ): bool => (int) ( $row['id'] ?? 0 ) > $last_id ) );
			usort( $rows, static fn( array $a, array $b ): int => (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) );
			return $this->rows_to_locations( array_slice( $rows, 0, $limit ) );
		}

		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->table_name()} WHERE id > %d ORDER BY id ASC LIMIT %d", $last_id, $limit ),
			ARRAY_A
		);
		return $this->rows_to_locations( is_array( $rows ) ? $rows : array() );
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

		return array(
			'gar_object_id'          => $location->gar_object_id,
			'fias_id'                => $location->fias_id,
			'kladr_id'               => $location->kladr_id,
			'gar_id'                 => '' !== $location->gar_id ? $location->gar_id : (string) $location->gar_object_id,
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
		$row_placeholder = '(' . implode( ', ', $formats ) . ')';
		$values_sql = implode( ', ', array_fill( 0, count( $rows ), $row_placeholder ) );
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

		$args = array();
		foreach ( $rows as $row ) {
			foreach ( $columns as $column ) {
				$args[] = $row[ $column ] ?? null;
			}
		}

		$result = $this->wpdb->query( $this->wpdb->prepare( $sql, ...$args ) );
		if ( false === $result ) {
			$this->throw_sql_error( str_contains( $table, 'wdc_locations' ) ? 'Location bulk upsert failed' : 'Location bulk insert failed' );
		}
	}

	private function throw_sql_error( string $message ): never {
		$error = trim( (string) ( $this->wpdb->last_error ?? '' ) );
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
		return property_exists( $this->wpdb, 'locations' ) || property_exists( $this->wpdb, 'rows' ) || property_exists( $this->wpdb, 'location_rows' );
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

	private function carrier_codes_table_name(): string {
		return $this->wpdb->prefix . 'wdc_location_carrier_codes';
	}
}
