<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

defined( 'ABSPATH' ) || exit;

final class YandexRegionMappingV2Repository {
	private object $wpdb;

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
			yandex_region varchar(255) NOT NULL DEFAULT '',
			wdc_region_name varchar(255) NOT NULL DEFAULT '',
			needs_review tinyint(1) NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY yandex_region (yandex_region),
			KEY wdc_region_name (wdc_region_name),
			UNIQUE KEY yandex_wdc_region (yandex_region, wdc_region_name)
		) {$charset};";
	}

	public function create_schema_if_needed(): void {
		if ( ! $this->can_create_schema() ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $this->schema() );
	}

	/** @return array{yandex_regions:int,wdc_regions:int,added:int,needs_review:int,stale_review:int} */
	public function sync_from_sources(): array {
		$this->create_schema_if_needed();
		$yandex_regions = $this->list_yandex_regions_from_sources();
		$wdc_regions = $this->list_wdc_regions();
		$added = 0;
		$needs_review = 0;
		$stale_review = 0;
		$wdc_lookup = array_fill_keys( $wdc_regions, true );

		foreach ( $yandex_regions as $yandex_region ) {
			$existing = $this->rows_for_yandex( $yandex_region );
			if ( array() !== $existing ) {
				foreach ( $existing as $row ) {
					$wdc_region = (string) ( $row['wdc_region_name'] ?? '' );
					if ( '' !== $wdc_region && ! isset( $wdc_lookup[ $wdc_region ] ) ) {
						$this->mark_needs_review( (int) ( $row['id'] ?? 0 ) );
						++$stale_review;
					}
				}
				continue;
			}

			$matches = $this->auto_match_wdc_regions( $yandex_region, $wdc_regions );
			if ( array() === $matches ) {
				$this->insert_row( $yandex_region, '', 1 );
				++$added;
				++$needs_review;
				continue;
			}
			$review = count( $matches ) > 1 ? 1 : 0;
			foreach ( $matches as $wdc_region ) {
				$this->insert_row( $yandex_region, $wdc_region, $review );
				++$added;
			}
			if ( 1 === $review ) {
				$needs_review += count( $matches );
			}
		}

		return array(
			'yandex_regions' => count( $yandex_regions ),
			'wdc_regions' => count( $wdc_regions ),
			'added' => $added,
			'needs_review' => $needs_review,
			'stale_review' => $stale_review,
		);
	}

	/** @return array<int,array<string,mixed>> */
	public function list_rows(): array {
		if ( $this->has_test_rows() ) {
			$rows = $this->wpdb->yandex_region_mapping_v2;
			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) ( $a['yandex_region'] ?? '' ), (string) ( $b['yandex_region'] ?? '' ) ) ?: strcmp( (string) ( $a['wdc_region_name'] ?? '' ), (string) ( $b['wdc_region_name'] ?? '' ) ) );
			return $rows;
		}
		$this->create_schema_if_needed();
		$rows = $this->wpdb->get_results( 'SELECT * FROM ' . $this->table_name() . ' ORDER BY yandex_region ASC, wdc_region_name ASC', ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,string> */
	public function list_wdc_regions(): array {
		if ( property_exists( $this->wpdb, 'wdc_locations' ) && is_array( $this->wpdb->wdc_locations ) ) {
			return $this->unique_non_empty( array_map( static fn( array $row ): string => (string) ( $row['region_name'] ?? '' ), $this->wpdb->wdc_locations ) );
		}
		$rows = $this->wpdb->get_col( 'SELECT DISTINCT region_name FROM ' . $this->locations_table_name() . " WHERE region_name <> '' ORDER BY region_name ASC" );

		return is_array( $rows ) ? $this->unique_non_empty( array_map( 'strval', $rows ) ) : array();
	}

	/** @return array<int,string> */
	public function find_wdc_regions_for_yandex( string $yandex_region ): array {
		$regions = array();
		foreach ( $this->rows_for_yandex( $yandex_region ) as $row ) {
			$wdc_region = trim( (string) ( $row['wdc_region_name'] ?? '' ) );
			if ( '' !== $wdc_region ) {
				$regions[ $wdc_region ] = $wdc_region;
			}
		}

		return array_values( $regions );
	}

	/** @param array<int,string> $wdc_region_names @return array{saved:int,needs_review:int} */
	public function save_mapping( string $yandex_region, array $wdc_region_names ): array {
		$this->create_schema_if_needed();
		$yandex_region = trim( $yandex_region );
		if ( '' === $yandex_region ) {
			return array( 'saved' => 0, 'needs_review' => 0 );
		}
		$regions = $this->unique_non_empty( array_map( 'strval', $wdc_region_names ) );
		if ( array() === $regions ) {
			$regions = array( '' );
		}
		$this->delete_yandex_region( $yandex_region );
		$saved = 0;
		$needs_review = 0;
		foreach ( $regions as $wdc_region ) {
			$review = '' === $wdc_region ? 1 : 0;
			$this->insert_row( $yandex_region, $wdc_region, $review );
			++$saved;
			$needs_review += $review;
		}

		return array( 'saved' => $saved, 'needs_review' => $needs_review );
	}

	/** @return array<int,string> */
	private function list_yandex_regions_from_sources(): array {
		$regions = array();
		if ( property_exists( $this->wpdb, 'yandex_delivery_pickup_points_v2' ) && is_array( $this->wpdb->yandex_delivery_pickup_points_v2 ) ) {
			$regions = array_merge( $regions, array_map( static fn( array $row ): string => (string) ( $row['region'] ?? '' ), $this->wpdb->yandex_delivery_pickup_points_v2 ) );
		}
		if ( property_exists( $this->wpdb, 'yandex_delivery_geo_v2' ) && is_array( $this->wpdb->yandex_delivery_geo_v2 ) ) {
			$regions = array_merge( $regions, array_map( static fn( array $row ): string => (string) ( $row['region'] ?? '' ), $this->wpdb->yandex_delivery_geo_v2 ) );
		}
		if ( array() !== $regions ) {
			return $this->unique_non_empty( $regions );
		}
		if ( $this->table_exists( $this->pickup_v2_table_name() ) ) {
			$rows = $this->wpdb->get_col( 'SELECT DISTINCT region FROM ' . $this->pickup_v2_table_name() . " WHERE region <> ''" );
			$regions = array_merge( $regions, is_array( $rows ) ? array_map( 'strval', $rows ) : array() );
		}
		if ( $this->table_exists( $this->geo_v2_table_name() ) ) {
			$rows = $this->wpdb->get_col( 'SELECT DISTINCT region FROM ' . $this->geo_v2_table_name() . " WHERE region <> ''" );
			$regions = array_merge( $regions, is_array( $rows ) ? array_map( 'strval', $rows ) : array() );
		}

		return $this->unique_non_empty( $regions );
	}

	/** @param array<int,string> $wdc_regions @return array<int,string> */
	private function auto_match_wdc_regions( string $yandex_region, array $wdc_regions ): array {
		$yandex_tokens = $this->region_tokens( $yandex_region );
		$matches = array();
		foreach ( $wdc_regions as $wdc_region ) {
			$wdc_tokens = $this->region_tokens( $wdc_region );
			if ( array() !== array_intersect( $yandex_tokens, $wdc_tokens ) ) {
				$matches[ $wdc_region ] = $wdc_region;
			}
		}

		return array_values( $matches );
	}

	/** @return array<int,string> */
	private function region_tokens( string $region ): array {
		$value = str_replace( array( 'ё', '—', '–' ), array( 'е', '-', '-' ), mb_strtolower( trim( $region ), 'UTF-8' ) );
		$value = preg_replace( '/[«»"\'`,.]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/[()]/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\b(автономный\s+округ|федеральная\s+территория|республика|респ|область|обл|край|город|г|ао)\b/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+(и)\s+|-+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		$tokens = array();
		foreach ( explode( ' ', trim( $value ) ) as $token ) {
			$token = trim( $token );
			if ( mb_strlen( $token, 'UTF-8' ) >= 5 ) {
				$tokens[ $token ] = $token;
			}
		}

		return array_values( $tokens );
	}

	/** @return array<int,array<string,mixed>> */
	private function rows_for_yandex( string $yandex_region ): array {
		$yandex_region = trim( $yandex_region );
		if ( $this->has_test_rows() ) {
			return array_values( array_filter( $this->wpdb->yandex_region_mapping_v2, static fn( array $row ): bool => (string) ( $row['yandex_region'] ?? '' ) === $yandex_region ) );
		}
		$this->create_schema_if_needed();
		$rows = $this->wpdb->get_results( $this->wpdb->prepare( 'SELECT * FROM ' . $this->table_name() . ' WHERE yandex_region = %s ORDER BY wdc_region_name ASC', $yandex_region ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	private function insert_row( string $yandex_region, string $wdc_region_name, int $needs_review ): void {
		$now = $this->now();
		$row = array(
			'yandex_region' => trim( $yandex_region ),
			'wdc_region_name' => trim( $wdc_region_name ),
			'needs_review' => $needs_review ? 1 : 0,
			'created_at' => $now,
			'updated_at' => $now,
		);
		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->yandex_region_mapping_v2 as $index => $existing ) {
				if ( (string) ( $existing['yandex_region'] ?? '' ) === $row['yandex_region'] && (string) ( $existing['wdc_region_name'] ?? '' ) === $row['wdc_region_name'] ) {
					$row['id'] = $existing['id'] ?? $index + 1;
					$row['created_at'] = $existing['created_at'] ?? $row['created_at'];
					$this->wpdb->yandex_region_mapping_v2[ $index ] = $row;
					return;
				}
			}
			$row['id'] = count( $this->wpdb->yandex_region_mapping_v2 ) + 1;
			$this->wpdb->yandex_region_mapping_v2[] = $row;
			return;
		}
		$sql = sprintf(
			'INSERT INTO %s (yandex_region, wdc_region_name, needs_review, created_at, updated_at) VALUES (%s, %s, %d, %s, %s) ON DUPLICATE KEY UPDATE needs_review=VALUES(needs_review), updated_at=VALUES(updated_at)',
			$this->table_name(),
			$this->wpdb->prepare( '%s', $row['yandex_region'] ),
			$this->wpdb->prepare( '%s', $row['wdc_region_name'] ),
			$row['needs_review'],
			$this->wpdb->prepare( '%s', $row['created_at'] ),
			$this->wpdb->prepare( '%s', $row['updated_at'] )
		);
		$this->wpdb->query( $sql );
	}

	private function delete_yandex_region( string $yandex_region ): void {
		if ( $this->has_test_rows() ) {
			$this->wpdb->yandex_region_mapping_v2 = array_values( array_filter( $this->wpdb->yandex_region_mapping_v2, static fn( array $row ): bool => (string) ( $row['yandex_region'] ?? '' ) !== $yandex_region ) );
			return;
		}
		$this->wpdb->query( $this->wpdb->prepare( 'DELETE FROM ' . $this->table_name() . ' WHERE yandex_region = %s', $yandex_region ) );
	}

	private function mark_needs_review( int $id ): void {
		if ( $id <= 0 ) {
			return;
		}
		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->yandex_region_mapping_v2 as $index => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === $id ) {
					$this->wpdb->yandex_region_mapping_v2[ $index ]['needs_review'] = 1;
					$this->wpdb->yandex_region_mapping_v2[ $index ]['updated_at'] = $this->now();
				}
			}
			return;
		}
		$this->wpdb->query( $this->wpdb->prepare( 'UPDATE ' . $this->table_name() . ' SET needs_review = 1, updated_at = %s WHERE id = %d', $this->now(), $id ) );
	}

	/** @param array<int,string> $values @return array<int,string> */
	private function unique_non_empty( array $values ): array {
		$result = array();
		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$result[ $value ] = $value;
			}
		}
		ksort( $result, SORT_NATURAL | SORT_FLAG_CASE );

		return array_values( $result );
	}

	private function table_exists( string $table ): bool {
		if ( ! method_exists( $this->wpdb, 'get_var' ) ) {
			return false;
		}
		$like = method_exists( $this->wpdb, 'esc_like' ) ? $this->wpdb->esc_like( $table ) : $table;

		return (string) $this->wpdb->get_var( $this->wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) ) === $table;
	}

	private function has_test_rows(): bool {
		return property_exists( $this->wpdb, 'yandex_region_mapping_v2' ) && is_array( $this->wpdb->yandex_region_mapping_v2 );
	}

	private function can_create_schema(): bool {
		return defined( 'ABSPATH' ) && is_string( ABSPATH ) && '' !== ABSPATH && method_exists( $this->wpdb, 'get_charset_collate' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_region_mapping_v2';
	}

	private function pickup_v2_table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_delivery_pickup_points_v2';
	}

	private function geo_v2_table_name(): string {
		return $this->wpdb->prefix . 'wdc_yandex_delivery_geo_v2';
	}

	private function locations_table_name(): string {
		return $this->wpdb->prefix . 'wdc_locations';
	}
}
