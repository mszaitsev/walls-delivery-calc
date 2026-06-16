<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Storage;

use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class LocationCarrierCodeRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	/**
	 * @return array{id:int,location_id:int|null,gar_object_id:int,fias_id:string,carrier_key:string,external_code:string,meta:array<string,mixed>}|null
	 */
	public function find_best( string $carrier_key, Location $location ): ?array {
		$carrier_key = $this->normalize_key( $carrier_key );
		if ( '' === $carrier_key ) {
			return null;
		}

		if ( $this->has_test_rows() ) {
			foreach ( $this->test_rows() as $row ) {
				if ( $this->row_matches_location( $row, $carrier_key, $location ) ) {
					return $this->normalize_row( $row );
				}
			}

			return null;
		}

		$identity_where = array();
		$identity_args  = array();
		if ( null !== $location->id && $location->id > 0 ) {
			$identity_where[] = 'location_id = %d';
			$identity_args[]  = $location->id;
		}
		if ( $location->gar_object_id > 0 ) {
			$identity_where[] = 'gar_object_id = %d';
			$identity_args[]  = $location->gar_object_id;
		}
		if ( '' !== trim( $location->fias_id ) ) {
			$identity_where[] = 'fias_id = %s';
			$identity_args[]  = trim( $location->fias_id );
		}
		if ( array() === $identity_where ) {
			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM ' . $this->table_name() . ' WHERE carrier_key = %s AND (' . implode( ' OR ', $identity_where ) . ') ORDER BY updated_at DESC, id DESC LIMIT 1',
				$carrier_key,
				...$identity_args
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/**
	 * @param array<string,mixed> $meta
	 * @return array{id:int,location_id:int|null,gar_object_id:int,fias_id:string,carrier_key:string,external_code:string,meta:array<string,mixed>}
	 */
	public function save( Location $location, string $carrier_key, string $external_code, array $meta = array() ): array {
		$carrier_key = $this->normalize_key( $carrier_key );
		$external_code = trim( $external_code );
		if ( '' === $carrier_key || '' === $external_code ) {
			return array(
				'id' => 0,
				'location_id' => $location->id,
				'gar_object_id' => max( 0, $location->gar_object_id ),
				'fias_id' => trim( $location->fias_id ),
				'carrier_key' => $carrier_key,
				'external_code' => $external_code,
				'meta' => $meta,
			);
		}

		$now = current_time( 'mysql' );
		$row = array(
			'location_id' => null !== $location->id && $location->id > 0 ? $location->id : null,
			'gar_object_id' => max( 0, $location->gar_object_id ),
			'fias_id' => '' !== trim( $location->fias_id ) ? trim( $location->fias_id ) : null,
			'carrier_key' => $carrier_key,
			'external_code' => $external_code,
			'meta' => $this->encode_meta( $meta ),
			'created_at' => $now,
			'updated_at' => $now,
		);

		if ( $this->has_test_rows() ) {
			$id = $this->upsert_test_row( $row );
			$row['id'] = $id;
			return $this->normalize_row( $row );
		}

		$existing = $this->find_best( $carrier_key, $location );
		if ( null !== $existing ) {
			unset( $row['created_at'] );
			$this->wpdb->update(
				$this->table_name(),
				$row,
				array( 'id' => $existing['id'] ),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);
			$row['id'] = $existing['id'];
			return $this->normalize_row( $row );
		}

		$this->wpdb->insert(
			$this->table_name(),
			$row,
			array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$row['id'] = (int) $this->wpdb->insert_id;

		return $this->normalize_row( $row );
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_location_carrier_codes';
	}

	private function normalize_key( string $key ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]+/i', '', trim( $key ) ) ?? '' );
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function encode_meta( array $meta ): string {
		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $meta ) : json_encode( $meta );

		return is_string( $encoded ) ? $encoded : '{}';
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function test_rows(): array {
		return is_array( $this->wpdb->carrier_codes ) ? $this->wpdb->carrier_codes : array();
	}

	private function has_test_rows(): bool {
		return property_exists( $this->wpdb, 'carrier_codes' );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function row_matches_location( array $row, string $carrier_key, Location $location ): bool {
		if ( $carrier_key !== $this->normalize_key( (string) ( $row['carrier_key'] ?? '' ) ) ) {
			return false;
		}
		if ( null !== $location->id && $location->id > 0 && (int) ( $row['location_id'] ?? 0 ) === $location->id ) {
			return true;
		}
		if ( $location->gar_object_id > 0 && (int) ( $row['gar_object_id'] ?? 0 ) === $location->gar_object_id ) {
			return true;
		}

		return '' !== trim( $location->fias_id ) && $this->normalize_guid( (string) ( $row['fias_id'] ?? '' ) ) === $this->normalize_guid( $location->fias_id );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function upsert_test_row( array $row ): int {
		foreach ( $this->wpdb->carrier_codes as $index => $existing ) {
			if (
				(string) ( $existing['carrier_key'] ?? '' ) === (string) $row['carrier_key']
				&& (
					( null !== $row['location_id'] && (int) ( $existing['location_id'] ?? 0 ) === (int) $row['location_id'] )
					|| ( (int) ( $existing['gar_object_id'] ?? -1 ) === (int) $row['gar_object_id'] && (string) ( $existing['external_code'] ?? '' ) === (string) $row['external_code'] )
				)
			) {
				$id = (int) ( $existing['id'] ?? ( $index + 1 ) );
				$this->wpdb->carrier_codes[ $index ] = array_merge( $existing, $row, array( 'id' => $id ) );
				return $id;
			}
		}

		$id = count( $this->wpdb->carrier_codes ) + 1;
		$this->wpdb->carrier_codes[] = array_merge( $row, array( 'id' => $id ) );

		return $id;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{id:int,location_id:int|null,gar_object_id:int,fias_id:string,carrier_key:string,external_code:string,meta:array<string,mixed>}
	 */
	private function normalize_row( array $row ): array {
		$meta = $row['meta'] ?? array();
		if ( is_string( $meta ) ) {
			$decoded = json_decode( $meta, true );
			$meta = is_array( $decoded ) ? $decoded : array();
		}

		return array(
			'id' => (int) ( $row['id'] ?? 0 ),
			'location_id' => null === ( $row['location_id'] ?? null ) ? null : (int) $row['location_id'],
			'gar_object_id' => (int) ( $row['gar_object_id'] ?? 0 ),
			'fias_id' => (string) ( $row['fias_id'] ?? '' ),
			'carrier_key' => (string) ( $row['carrier_key'] ?? '' ),
			'external_code' => (string) ( $row['external_code'] ?? '' ),
			'meta' => is_array( $meta ) ? $meta : array(),
		);
	}

	private function normalize_guid( string $value ): string {
		return strtolower( preg_replace( '/[^a-f0-9]+/i', '', trim( $value ) ) ?? '' );
	}
}
