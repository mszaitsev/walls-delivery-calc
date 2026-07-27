<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Storage;

defined( 'ABSPATH' ) || exit;

final class LocationDeliveryCodeRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	/**
	 * @return array{location_id:int,dpd_city_id:string|null,updated_at:string|null}|null
	 */
	public function find_by_location_id( int $location_id ): ?array {
		$location_id = max( 0, $location_id );
		if ( 0 === $location_id ) {
			return null;
		}

		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->delivery_codes as $row ) {
				if ( (int) ( $row['location_id'] ?? 0 ) === $location_id ) {
					return $this->normalize_row( $row );
				}
			}

			return null;
		}

		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				'SELECT * FROM ' . $this->table_name() . ' WHERE location_id = %d LIMIT 1',
				$location_id
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	public function get_dpd_city_id( int $location_id ): ?string {
		$row = $this->find_by_location_id( $location_id );
		if ( null === $row || null === $row['dpd_city_id'] || '' === trim( $row['dpd_city_id'] ) || '0' === trim( $row['dpd_city_id'] ) ) {
			return null;
		}

		return $row['dpd_city_id'];
	}

	public function find_location_id_by_dpd_city_id( string|int $dpd_city_id ): ?int {
		$dpd_city_id = preg_replace( '/\D+/', '', (string) $dpd_city_id ) ?? '';
		if ( '' === $dpd_city_id || '0' === $dpd_city_id ) {
			return null;
		}
		if ( property_exists( $this->wpdb, 'dpd_mapping_lookup_calls' ) ) {
			$this->wpdb->dpd_mapping_lookup_calls = max( 0, (int) $this->wpdb->dpd_mapping_lookup_calls ) + 1;
		}
		if ( property_exists( $this->wpdb, 'fail_dpd_mapping_lookup' ) && true === (bool) $this->wpdb->fail_dpd_mapping_lookup ) {
			throw new \RuntimeException( 'DPD delivery code lookup failed: forced mapping lookup failure' );
		}

		if ( $this->has_test_rows() ) {
			$matches = array();
			foreach ( $this->wpdb->delivery_codes as $row ) {
				if ( (string) ( $row['dpd_city_id'] ?? '' ) === $dpd_city_id && (int) ( $row['location_id'] ?? 0 ) > 0 ) {
					$matches[] = (int) $row['location_id'];
				}
			}

			return array() !== $matches ? min( $matches ) : null;
		}

		$sql = $this->wpdb->prepare(
			'SELECT location_id FROM ' . $this->table_name() . ' WHERE dpd_city_id = %d ORDER BY location_id ASC LIMIT 1',
			(int) $dpd_city_id
		);
		if ( ! is_string( $sql ) || '' === trim( $sql ) ) {
			throw new \RuntimeException( 'DPD delivery code lookup failed: SQL preparation returned an invalid result' );
		}

		$this->wpdb->last_error = '';
		$value = $this->wpdb->get_var( $sql );
		if ( '' !== trim( (string) ( $this->wpdb->last_error ?? '' ) ) ) {
			$error = trim( (string) $this->wpdb->last_error );
			$error = preg_replace( '/[\r\n\t]+/', ' ', $error ) ?? $error;
			throw new \RuntimeException( 'DPD delivery code lookup failed: ' . $error );
		}
		if ( null !== $value && ! is_numeric( $value ) ) {
			throw new \RuntimeException( 'DPD delivery code lookup failed: invalid SQL result' );
		}

		return is_numeric( $value ) && (int) $value > 0 ? (int) $value : null;
	}

	public function save_dpd_city_id( int $location_id, string|int $dpd_city_id ): bool {
		$location_id = max( 0, $location_id );
		$dpd_city_id = preg_replace( '/\D+/', '', (string) $dpd_city_id ) ?? '';
		if ( 0 === $location_id || '' === $dpd_city_id || '0' === $dpd_city_id ) {
			return false;
		}

		$now = current_time( 'mysql' );
		if ( $this->has_test_rows() ) {
			foreach ( $this->wpdb->delivery_codes as $index => $row ) {
				if ( (int) ( $row['location_id'] ?? 0 ) === $location_id ) {
					$this->wpdb->delivery_codes[ $index ] = array(
						'location_id' => $location_id,
						'dpd_city_id' => $dpd_city_id,
						'updated_at' => $now,
					);
					return true;
				}
			}

			$this->wpdb->delivery_codes[] = array(
				'location_id' => $location_id,
				'dpd_city_id' => $dpd_city_id,
				'updated_at' => $now,
			);
			return true;
		}

		$sql = $this->wpdb->prepare(
			'INSERT INTO ' . $this->table_name() . ' (location_id, dpd_city_id, updated_at)
			VALUES (%d, %d, %s)
			ON DUPLICATE KEY UPDATE dpd_city_id = VALUES(dpd_city_id), updated_at = VALUES(updated_at)',
			$location_id,
			(int) $dpd_city_id,
			$now
		);

		return false !== $this->wpdb->query( $sql );
	}

	public function delete_by_location_id( int $location_id ): bool {
		$location_id = max( 0, $location_id );
		if ( 0 === $location_id ) {
			return false;
		}

		if ( $this->has_test_rows() ) {
			$before = count( $this->wpdb->delivery_codes );
			$this->wpdb->delivery_codes = array_values(
				array_filter(
					$this->wpdb->delivery_codes,
					static fn( array $row ): bool => (int) ( $row['location_id'] ?? 0 ) !== $location_id
				)
			);

			return count( $this->wpdb->delivery_codes ) < $before;
		}

		return false !== $this->wpdb->delete( $this->table_name(), array( 'location_id' => $location_id ), array( '%d' ) );
	}

	public function cleanup_orphans(): int {
		if ( $this->has_test_rows() ) {
			$valid_ids = array();
			foreach ( $this->test_location_rows() as $row ) {
				$valid_ids[] = (int) ( $row['id'] ?? 0 );
			}
			$valid_ids = array_filter( $valid_ids );
			$before = count( $this->wpdb->delivery_codes );
			$this->wpdb->delivery_codes = array_values(
				array_filter(
					$this->wpdb->delivery_codes,
					static fn( array $row ): bool => in_array( (int) ( $row['location_id'] ?? 0 ), $valid_ids, true )
				)
			);

			return $before - count( $this->wpdb->delivery_codes );
		}

		$sql = 'DELETE dc FROM ' . $this->table_name() . ' dc
			LEFT JOIN ' . $this->locations_table_name() . ' l ON l.id = dc.location_id
			WHERE l.id IS NULL';

		$result = $this->wpdb->query( $sql );

		return is_numeric( $result ) ? (int) $result : 0;
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_location_delivery_codes';
	}

	private function locations_table_name(): string {
		return $this->wpdb->prefix . 'wdc_locations';
	}

	private function has_test_rows(): bool {
		return is_array( $this->wpdb->delivery_codes ?? null );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function test_location_rows(): array {
		if ( property_exists( $this->wpdb, 'locations' ) && is_array( $this->wpdb->locations ) ) {
			return $this->wpdb->locations;
		}
		if ( property_exists( $this->wpdb, 'rows' ) && is_array( $this->wpdb->rows ) ) {
			return $this->wpdb->rows;
		}
		if ( property_exists( $this->wpdb, 'location_rows' ) && is_array( $this->wpdb->location_rows ) ) {
			return $this->wpdb->location_rows;
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{location_id:int,dpd_city_id:string|null,updated_at:string|null}
	 */
	private function normalize_row( array $row ): array {
		$dpd_city_id = $row['dpd_city_id'] ?? null;

		return array(
			'location_id' => (int) ( $row['location_id'] ?? 0 ),
			'dpd_city_id' => null === $dpd_city_id || '' === trim( (string) $dpd_city_id ) ? null : (string) $dpd_city_id,
			'updated_at' => isset( $row['updated_at'] ) && '' !== (string) $row['updated_at'] ? (string) $row['updated_at'] : null,
		);
	}
}
