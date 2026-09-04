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

	/** @param array<int,string> $regions */
	public function replace_regions( int $service_id, array $regions ): void {
		$desired = $this->normalize_regions( $regions );
		$current = $this->regions( $service_id );
		if ( $current === $desired ) {
			return;
		}

		$to_delete = array_values( array_diff( $current, $desired ) );
		$to_insert = array_values( array_diff( $desired, $current ) );
		$this->delete_region_rows( $service_id, $to_delete );
		$this->insert_region_rows( $service_id, $to_insert );
	}

	/**
	 * @param array<int,array{location_name:string,region_name:string}|array<string,string>> $locations
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

	/** @return array<int,string> */
	public function regions( int $service_id ): array {
		$table = $this->regions_table();
		$rows = $this->wpdb->get_col(
			$this->wpdb->prepare( "SELECT region_name FROM {$table} WHERE service_id = %d ORDER BY region_name ASC", $service_id )
		);

		return $this->normalize_regions( is_array( $rows ) ? array_map( 'strval', $rows ) : array() );
	}

	/** @return array<int,array{location_name:string,region_name:string}> */
	public function locations( int $service_id ): array {
		$table = $this->locations_table();
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT location_name, region_name FROM {$table} WHERE service_id = %d ORDER BY region_name ASC, location_name ASC", $service_id ),
			ARRAY_A
		);

		return $this->normalize_locations( is_array( $rows ) ? $rows : array() );
	}

	public function has_restrictions( int $service_id ): bool {
		return array() !== $this->regions( $service_id ) || array() !== $this->locations( $service_id );
	}

	/** @param array<int,string> $regions */
	private function delete_region_rows( int $service_id, array $regions ): void {
		$regions = $this->normalize_regions( $regions );
		if ( array() === $regions ) {
			return;
		}

		foreach ( $regions as $region ) {
			$this->checked_delete( $this->regions_table(), array( 'service_id' => $service_id, 'region_name' => $region ), array( '%d', '%s' ), 'Failed to delete stale manual delivery regions.' );
		}
	}

	/** @param array<int,string> $regions */
	private function insert_region_rows( int $service_id, array $regions ): void {
		$regions = $this->normalize_regions( $regions );
		if ( array() === $regions ) {
			return;
		}

		$now = current_time( 'mysql' );
		$table = $this->regions_table();
		foreach ( $regions as $region ) {
			$result = $this->wpdb->query(
				$this->wpdb->prepare(
					"INSERT INTO {$table} (service_id, region_name, created_at) VALUES (%d, %s, %s) ON DUPLICATE KEY UPDATE region_name = VALUES(region_name)",
					$service_id,
					$region,
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
			$parts = explode( '|', $key, 2 );
			if ( 2 !== count( $parts ) ) {
				continue;
			}
			$this->checked_delete( $this->locations_table(), array( 'service_id' => $service_id, 'location_name' => $parts[0], 'region_name' => $parts[1] ), array( '%d', '%s', '%s' ), 'Failed to delete stale manual delivery locations.' );
		}
	}

	/** @param array<int,array{location_name:string,region_name:string}> $locations */
	private function insert_location_rows( int $service_id, array $locations ): void {
		if ( array() === $locations ) {
			return;
		}

		$now = current_time( 'mysql' );
		$table = $this->locations_table();
		foreach ( $locations as $location ) {
			$result = $this->wpdb->query(
				$this->wpdb->prepare(
					"INSERT INTO {$table} (service_id, location_name, region_name, created_at) VALUES (%d, %s, %s, %s) ON DUPLICATE KEY UPDATE location_name = VALUES(location_name)",
					$service_id,
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

	/** @param array<int,string> $regions @return array<int,string> */
	private function normalize_regions( array $regions ): array {
		$normalized = array();
		foreach ( $regions as $region ) {
			$region = $this->normalize_name( $region );
			if ( '' !== $region ) {
				$normalized[] = $region;
			}
		}
		$normalized = array_values( array_unique( $normalized ) );
		sort( $normalized, SORT_STRING );

		return $normalized;
	}

	/**
	 * @param array<int,array{location_name:string,region_name:string}|array<string,string>> $locations
	 * @return array<int,array{location_name:string,region_name:string}>
	 */
	private function normalize_locations( array $locations ): array {
		$by_key = array();
		foreach ( $locations as $location ) {
			if ( ! is_array( $location ) ) {
				continue;
			}
			$name = $this->normalize_name( (string) ( $location['location_name'] ?? '' ) );
			$region = $this->normalize_name( (string) ( $location['region_name'] ?? '' ) );
			if ( '' === $name || '' === $region ) {
				continue;
			}
			$by_key[ $name . '|' . $region ] = array( 'location_name' => $name, 'region_name' => $region );
		}
		ksort( $by_key, SORT_STRING );

		return array_values( $by_key );
	}

	/** @param array{location_name:string,region_name:string} $location */
	private function location_key( array $location ): string {
		return $location['location_name'] . '|' . $location['region_name'];
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
