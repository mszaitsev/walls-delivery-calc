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

		if ( null !== $location->id && $location->id > 0 ) {
			unset( $data['created_at'] );
			$this->wpdb->update( $this->table_name(), $data, array( 'id' => $location->id ), $this->formats( false ), array( '%d' ) );
			return $location->id;
		}

		$existing = $location->gar_object_id > 0 ? $this->find_by_gar_object_id( $location->gar_object_id ) : null;
		if ( ! $existing instanceof Location && '' !== trim( $location->fias_id ) ) {
			$existing = $this->find_by_fias_id( $location->fias_id );
		}

		if ( $existing instanceof Location && null !== $existing->id ) {
			unset( $data['created_at'] );
			$this->wpdb->update( $this->table_name(), $data, array( 'id' => $existing->id ), $this->formats( false ), array( '%d' ) );
			return $existing->id;
		}

		$this->wpdb->insert( $this->table_name(), $data, $this->formats() );

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @param array<int, Location> $locations
	 */
	public function bulk_upsert( array $locations ): int {
		$count = 0;
		foreach ( $locations as $location ) {
			if ( $location instanceof Location ) {
				$this->save( $location );
				++$count;
			}
		}

		return $count;
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
		return $this->find_one( 'fias_id', trim( $fias_id ), '%s' );
	}

	public function find_by_kladr_id( string $kladr_id ): ?Location {
		return $this->find_one( 'kladr_id', trim( $kladr_id ), '%s' );
	}

	public function find_by_gar_id( string $gar_id ): ?Location {
		$id = is_numeric( $gar_id ) ? (int) $gar_id : 0;
		return $id > 0 ? $this->find_by_gar_object_id( $id ) : $this->find_one( 'gar_id', trim( $gar_id ), '%s' );
	}

	/**
	 * @return array<int, Location>
	 */
	public function search( string $query, int $limit = 100 ): array {
		$query = $this->normalize_query( $query );
		$limit = max( 1, min( 300, $limit ) );

		if ( '' === $query ) {
			return array();
		}

		$like = '%' . $this->wpdb->esc_like( $query ) . '%';
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
				FROM {$this->table_name()} l
				LEFT JOIN {$this->region_table_name()} r ON r.region_code = l.region_code
				WHERE l.active = 1 AND l.searchable_text LIKE %s
				ORDER BY l.display_name ASC
				LIMIT %d",
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

	/**
	 * @return array{locations_deleted:int|null, aliases_deleted:int|null, regions_deleted:int|null, carrier_codes_deleted:int|null}
	 */
	public function clear_all(): array {
		return array(
			'carrier_codes_deleted' => $this->clear_table( $this->carrier_codes_table_name() ),
			'aliases_deleted'       => $this->clear_table( $this->alias_table_name() ),
			'locations_deleted'     => $this->clear_table( $this->table_name() ),
			'regions_deleted'       => $this->clear_table( $this->region_table_name() ),
		);
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

	public function delete_all(): void {
		$this->wpdb->query( "DELETE FROM {$this->table_name()}" );
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
		$postal_code = '' !== $location->postal_code ? $location->postal_code : $location->postcode;

		return array(
			'gar_object_id'   => $location->gar_object_id,
			'fias_id'         => $location->fias_id,
			'kladr_id'        => $location->kladr_id,
			'gar_id'          => '' !== $location->gar_id ? $location->gar_id : (string) $location->gar_object_id,
			'country_code'    => '' !== $location->country_code ? $location->country_code : 'RU',
			'region_name'     => $location->region_name,
			'region_code'     => $location->region_code,
			'city_name'       => $location->city_name,
			'city_type'       => $location->city_type,
			'city_fias_id'    => $location->city_fias_id,
			'city_kladr_id'   => $location->city_kladr_id,
			'settlement_name' => $place_name,
			'settlement_type' => $place_type,
			'place_name'      => $place_name,
			'place_type'      => $place_type,
			'place_level'     => max( 0, $location->place_level ),
			'display_name'    => $display,
			'postcode'        => $postal_code,
			'postal_code'     => $postal_code,
			'okato'           => $location->okato,
			'oktmo'           => $location->oktmo,
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
	private function formats( bool $with_created_at = true ): array {
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%d' );
		if ( $with_created_at ) {
			$formats[] = '%s';
		}
		$formats[] = '%s';

		return $formats;
	}

	private function normalize_query( string $query ): string {
		return Location::normalize_search_text( $query );
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
