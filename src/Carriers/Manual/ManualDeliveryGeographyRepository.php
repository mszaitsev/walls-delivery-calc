<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Manual;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class ManualDeliveryGeographyRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	/** @param array<int,string|array{country_code?:string,region_name?:string}|array<string,string>> $regions */
	public function replace_regions( int $service_id, array $regions ): void {
		$desired = $this->normalize_regions( $regions );
		$current = $this->regions( $service_id );
		if ( $current === $desired ) {
			return;
		}

		$to_delete = array_values( array_diff( array_map( array( $this, 'region_key' ), $current ), array_map( array( $this, 'region_key' ), $desired ) ) );
		$to_insert = array_values( array_diff( array_map( array( $this, 'region_key' ), $desired ), array_map( array( $this, 'region_key' ), $current ) ) );
		$desired_by_key = array_combine( array_map( array( $this, 'region_key' ), $desired ), $desired ) ?: array();
		$this->delete_region_rows( $service_id, $to_delete );
		$this->insert_region_rows( $service_id, array_values( array_intersect_key( $desired_by_key, array_flip( $to_insert ) ) ) );
	}

	/**
	 * @param array<int,array{country_code?:string,location_name:string,region_name:string}|array<string,string>> $locations
	 */
	public function replace_locations( int $service_id, array $locations ): void {
		$desired = $this->normalize_locations( $locations );
		$current = $this->locations( $service_id );
		if ( $current === $desired ) {
			return;
		}

		$current_keys = array_map( array( $this, 'location_key' ), $current );
		$desired_keys = array_map( array( $this, 'location_key' ), $desired );
		$delete_keys = array_values( array_diff( $current_keys, $desired_keys ) );
		$insert_keys = array_values( array_diff( $desired_keys, $current_keys ) );
		$desired_by_key = array_combine( $desired_keys, $desired ) ?: array();

		$this->delete_location_rows( $service_id, $delete_keys );
		$this->insert_location_rows( $service_id, array_values( array_intersect_key( $desired_by_key, array_flip( $insert_keys ) ) ) );
	}

	public function clear( int $service_id ): void {
		$this->checked_delete( $this->regions_table(), array( 'service_id' => $service_id ), array( '%d' ), 'Failed to clear manual delivery regions.' );
		$this->checked_delete( $this->locations_table(), array( 'service_id' => $service_id ), array( '%d' ), 'Failed to clear manual delivery locations.' );
	}

	/** @return array<int,array{country_code:string,region_name:string}> */
	public function regions( int $service_id, string $country_code = '' ): array {
		$table = $this->regions_table();
		$country_code = $this->normalize_country_code( $country_code, false );
		$where = 'service_id = %d';
		$args = array( $service_id );
		if ( '' !== $country_code ) {
			$where .= ' AND country_code = %s';
			$args[] = $country_code;
		}
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT country_code, region_name FROM {$table} WHERE {$where} ORDER BY country_code ASC, region_name ASC", ...$args ),
			ARRAY_A
		);

		return $this->normalize_regions( is_array( $rows ) ? $rows : array() );
	}

	/** @return array<int,array{country_code:string,location_name:string,region_name:string}> */
	public function locations( int $service_id, string $country_code = '' ): array {
		$table = $this->locations_table();
		$country_code = $this->normalize_country_code( $country_code, false );
		$where = 'service_id = %d';
		$args = array( $service_id );
		if ( '' !== $country_code ) {
			$where .= ' AND country_code = %s';
			$args[] = $country_code;
		}
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT country_code, location_name, region_name FROM {$table} WHERE {$where} ORDER BY country_code ASC, region_name ASC, location_name ASC", ...$args ),
			ARRAY_A
		);

		return $this->normalize_locations( is_array( $rows ) ? $rows : array() );
	}

	public function has_restrictions( int $service_id, string $country_code = '' ): bool {
		return array() !== $this->regions( $service_id, $country_code ) || array() !== $this->locations( $service_id, $country_code );
	}

	/** @param array<int,string> $region_keys */
	private function delete_region_rows( int $service_id, array $region_keys ): void {
		if ( array() === $region_keys ) {
			return;
		}

		foreach ( $region_keys as $key ) {
			$parts = explode( '|', $key, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$this->checked_delete( $this->regions_table(), array( 'service_id' => $service_id, 'country_code' => $parts[0], 'region_name' => $parts[1] ), array( '%d', '%s', '%s' ), 'Failed to delete stale manual delivery regions.' );
		}
	}

	/** @param array<int,array{country_code:string,region_name:string}> $regions */
	private function insert_region_rows( int $service_id, array $regions ): void {
		if ( array() === $regions ) {
			return;
		}

		$now = current_time( 'mysql' );
		$table = $this->regions_table();
		foreach ( $regions as $region ) {
			$result = $this->wpdb->query(
				$this->wpdb->prepare(
					"INSERT INTO {$table} (service_id, country_code, region_name, created_at) VALUES (%d, %s, %s, %s) ON DUPLICATE KEY UPDATE region_name = VALUES(region_name)",
					$service_id,
					$region['country_code'],
					$region['region_name'],
					$now
				)
			);
			if ( false === $result ) {
				throw new RuntimeException( 'Failed to upsert manual delivery regions.' );
			}
		}
	}

	/** @param array<int,string> $location_keys */
	private function delete_location_rows( int $service_id, array $location_keys ): void {
		foreach ( $location_keys as $key ) {
			$parts = explode( '|', $key, 3 );
			if ( 3 !== count( $parts ) ) {
				continue;
			}
			$this->checked_delete( $this->locations_table(), array( 'service_id' => $service_id, 'country_code' => $parts[0], 'location_name' => $parts[1], 'region_name' => $parts[2] ), array( '%d', '%s', '%s', '%s' ), 'Failed to delete stale manual delivery locations.' );
		}
	}

	/** @param array<int,array{country_code:string,location_name:string,region_name:string}> $locations */
	private function insert_location_rows( int $service_id, array $locations ): void {
		if ( array() === $locations ) {
			return;
		}

		$now = current_time( 'mysql' );
		$table = $this->locations_table();
		foreach ( $locations as $location ) {
			$result = $this->wpdb->query(
				$this->wpdb->prepare(
					"INSERT INTO {$table} (service_id, country_code, location_name, region_name, created_at) VALUES (%d, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE location_name = VALUES(location_name)",
					$service_id,
					$location['country_code'],
					$location['location_name'],
					$location['region_name'],
					$now
				)
			);
			if ( false === $result ) {
				throw new RuntimeException( 'Failed to upsert manual delivery locations.' );
			}
		}
	}

	/** @param array<int,string|array{country_code?:string,region_name?:string}|array<string,string>> $regions @return array<int,array{country_code:string,region_name:string}> */
	private function normalize_regions( array $regions ): array {
		$by_key = array();
		foreach ( $regions as $region ) {
			$row = is_array( $region ) ? $region : array( 'country_code' => 'RU', 'region_name' => (string) $region );
			$country = $this->normalize_country_code( (string) ( $row['country_code'] ?? 'RU' ) );
			$name = $this->normalize_name( (string) ( $row['region_name'] ?? '' ) );
			if ( '' !== $country && '' !== $name ) {
				$by_key[ $country . '|' . $name ] = array( 'country_code' => $country, 'region_name' => $name );
			}
		}
		ksort( $by_key, SORT_STRING );

		return array_values( $by_key );
	}

	/**
	 * @param array<int,array{country_code?:string,location_name:string,region_name:string}|array<string,string>> $locations
	 * @return array<int,array{country_code:string,location_name:string,region_name:string}>
	 */
	private function normalize_locations( array $locations ): array {
		$by_key = array();
		foreach ( $locations as $location ) {
			if ( ! is_array( $location ) ) {
				continue;
			}
			$country = $this->normalize_country_code( (string) ( $location['country_code'] ?? 'RU' ) );
			$name = $this->normalize_name( (string) ( $location['location_name'] ?? '' ) );
			$region = $this->normalize_name( (string) ( $location['region_name'] ?? '' ) );
			if ( '' === $country || '' === $name || '' === $region ) {
				continue;
			}
			$by_key[ $country . '|' . $name . '|' . $region ] = array( 'country_code' => $country, 'location_name' => $name, 'region_name' => $region );
		}
		ksort( $by_key, SORT_STRING );

		return array_values( $by_key );
	}

	/** @param array{country_code:string,location_name:string,region_name:string} $location */
	private function location_key( array $location ): string {
		return $location['country_code'] . '|' . $location['location_name'] . '|' . $location['region_name'];
	}

	/** @param array{country_code:string,region_name:string} $region */
	private function region_key( array $region ): string {
		return $region['country_code'] . '|' . $region['region_name'];
	}

	private function normalize_country_code( string $country_code, bool $default_ru = true ): string {
		$country_code = strtoupper( trim( $country_code ) );
		$country_code = preg_replace( '/[^A-Z]/', '', $country_code ) ?? '';
		if ( '' === $country_code && $default_ru ) {
			return 'RU';
		}

		return preg_match( '/^[A-Z]{2}$/', $country_code ) ? $country_code : '';
	}

	private function normalize_name( string $value ): string {
		$value = trim( sanitize_text_field( wp_unslash( $value ) ) );
		$value = preg_replace( '/\s+/u', ' ', $value );

		return is_string( $value ) ? $value : '';
	}

	/** @param array<string,mixed> $where @param array<int,string> $where_format */
	private function checked_delete( string $table, array $where, array $where_format, string $message ): void {
		$result = $this->wpdb->delete( $table, $where, $where_format );
		if ( false === $result ) {
			throw new RuntimeException( $message );
		}
	}

	private function regions_table(): string {
		return $this->wpdb->prefix . 'wdc_manual_delivery_regions';
	}

	private function locations_table(): string {
		return $this->wpdb->prefix . 'wdc_manual_delivery_locations';
	}
}
