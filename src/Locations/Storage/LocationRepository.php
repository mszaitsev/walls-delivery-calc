<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Storage;

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

		if ( null !== $location->id && $location->id > 0 ) {
			$this->wpdb->update( $this->table_name(), $data, array( 'id' => $location->id ), $this->formats(), array( '%d' ) );
			return $location->id;
		}

		$this->wpdb->insert( $this->table_name(), $data, $this->formats() );

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @param array<int, Location> $locations
	 */
	public function bulk_insert( array $locations ): void {
		foreach ( $locations as $location ) {
			if ( $location instanceof Location ) {
				$this->save( $location );
			}
		}
	}

	public function find_by_id( int $id ): ?Location {
		return $this->find_one( 'id', $id, '%d' );
	}

	public function find_by_fias_id( string $fias_id ): ?Location {
		return $this->find_one( 'fias_id', trim( $fias_id ), '%s' );
	}

	public function find_by_gar_id( string $gar_id ): ?Location {
		return $this->find_one( 'gar_id', trim( $gar_id ), '%s' );
	}

	/**
	 * @return array<int, Location>
	 */
	public function search( string $query, int $limit = 20 ): array {
		$query = $this->normalize_query( $query );
		$limit = max( 1, min( 300, $limit ) );

		if ( '' === $query ) {
			return array();
		}

		$like = '%' . $this->wpdb->esc_like( $query ) . '%';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name()} WHERE active = 1 AND searchable_text LIKE %s ORDER BY display_name ASC LIMIT %d",
				$like,
				$limit
			),
			ARRAY_A
		);

		return $this->rows_to_locations( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @return array<string, array<int, Location>>
	 */
	public function search_grouped_by_region( string $query, int $limit = 50 ): array {
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

	public function delete_all(): void {
		$this->wpdb->query( "DELETE FROM {$this->table_name()}" );
	}

	private function find_one( string $column, int|string $value, string $format ): ?Location {
		if ( '' === (string) $value ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table_name()} WHERE {$column} = {$format} LIMIT 1",
				$value
			),
			ARRAY_A
		);

		return is_array( $row ) ? Location::from_array( $row ) : null;
	}

	/**
	 * @param array<int, array<string,mixed>> $rows
	 * @return array<int, Location>
	 */
	private function rows_to_locations( array $rows ): array {
		$locations = array();

		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$locations[] = Location::from_array( $row );
			}
		}

		return $locations;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function location_to_row( Location $location, string $now ): array {
		return array(
			'fias_id'         => $location->fias_id,
			'gar_id'          => $location->gar_id,
			'country_code'    => $location->country_code,
			'region_name'     => $location->region_name,
			'region_code'     => $location->region_code,
			'city_name'       => $location->city_name,
			'settlement_name' => $location->settlement_name,
			'settlement_type' => $location->settlement_type,
			'display_name'    => $location->display_name,
			'postcode'        => $location->postcode,
			'latitude'        => $location->latitude,
			'longitude'       => $location->longitude,
			'searchable_text' => $location->get_searchable_text(),
			'active'          => $location->active ? 1 : 0,
			'created_at'      => $now,
			'updated_at'      => $now,
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function formats(): array {
		return array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%d', '%s', '%s' );
	}

	private function normalize_query( string $query ): string {
		return Location::normalize_search_text( $query );
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_locations';
	}
}
