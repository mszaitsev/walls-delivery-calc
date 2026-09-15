<?php
declare(strict_types=1);

namespace WallsShop\WDC\DeliveryServices;

defined( 'ABSPATH' ) || exit;

final class DeliveryServiceCountryRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	/**
	 * @param array<int,string> $countries
	 */
	public function replace_countries( int $service_id, array $countries ): void {
		$desired = $this->normalize_countries( $countries );
		$current = $this->countries( $service_id );

		if ( $current === $desired ) {
			return;
		}

		$to_delete = array_values( array_diff( $current, $desired ) );
		$to_insert = array_values( array_diff( $desired, $current ) );

		if ( array() !== $to_delete ) {
			$this->delete_selected_countries( $service_id, $to_delete );
		}

		if ( array() === $desired ) {
			return;
		}

		if ( array() !== $to_insert ) {
			$this->upsert_countries( $service_id, $to_insert );
		}
	}

	public function delete_countries( int $service_id ): void {
		$result = $this->wpdb->delete( $this->table(), array( 'service_id' => $service_id ), array( '%d' ) );
		if ( false === $result ) {
			throw new \RuntimeException( 'Failed to delete delivery service countries.' );
		}
	}

	/**
	 * @param array<int,string> $countries
	 */
	private function delete_selected_countries( int $service_id, array $countries ): void {
		$countries = $this->normalize_countries( $countries );
		if ( array() === $countries ) {
			return;
		}

		if ( ! method_exists( $this->wpdb, 'query' ) ) {
			foreach ( $countries as $country ) {
				$result = $this->wpdb->delete( $this->table(), array( 'service_id' => $service_id, 'country_code' => $country ), array( '%d', '%s' ) );
				if ( false === $result ) {
					throw new \RuntimeException( 'Failed to delete stale delivery service countries.' );
				}
			}
			return;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $countries ), '%s' ) );
		$args = array_merge( array( $service_id ), $countries );
		$sql = $this->wpdb->prepare(
			"DELETE FROM {$this->table()} WHERE service_id = %d AND country_code IN ({$placeholders})",
			...$args
		);

		$this->checked_query( $sql, 'Failed to delete stale delivery service countries.' );
	}

	/**
	 * @param array<int,string> $countries
	 */
	private function upsert_countries( int $service_id, array $countries ): void {
		$countries = $this->normalize_countries( $countries );
		if ( array() === $countries ) {
			return;
		}

		$now = current_time( 'mysql' );
		if ( ! method_exists( $this->wpdb, 'query' ) ) {
			$current = $this->countries( $service_id );
			foreach ( $countries as $country ) {
				if ( in_array( $country, $current, true ) ) {
					continue;
				}
				$result = $this->wpdb->insert(
					$this->table(),
					array( 'service_id' => $service_id, 'country_code' => $country, 'created_at' => $now ),
					array( '%d', '%s', '%s' )
				);
				if ( false === $result ) {
					throw new \RuntimeException( 'Failed to upsert delivery service countries.' );
				}
			}
			return;
		}

		$values = array();
		$args = array();
		foreach ( $countries as $country ) {
			$values[] = '(%d, %s, %s)';
			$args[] = $service_id;
			$args[] = $country;
			$args[] = $now;
		}

		$sql = $this->wpdb->prepare(
			'INSERT INTO ' . $this->table() . ' (service_id, country_code, created_at) VALUES ' . implode( ', ', $values ) . ' ON DUPLICATE KEY UPDATE service_id = VALUES(service_id)',
			...$args
		);

		$this->checked_query( $sql, 'Failed to upsert delivery service countries.' );
	}

	/**
	 * @return array<int,string>
	 */
	public function countries( int $service_id ): array {
		$rows = $this->wpdb->get_col(
			$this->wpdb->prepare( "SELECT country_code FROM {$this->table()} WHERE service_id = %d ORDER BY country_code ASC", $service_id )
		);

		return $this->normalize_countries( is_array( $rows ) ? array_map( 'strval', $rows ) : array() );
	}

	/**
	 * @param array<int,string> $countries
	 * @return array<int,string>
	 */
	private function normalize_countries( array $countries ): array {
		$result = array();
		foreach ( $countries as $country ) {
			$country = strtoupper( trim( $country ) );
			if ( 2 === strlen( $country ) && preg_match( '/^[A-Z]{2}$/', $country ) ) {
				$result[] = $country;
			}
		}

		$result = array_values( array_unique( $result ) );
		sort( $result );

		return $result;
	}

	private function checked_query( string $sql, string $message ): void {
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) {
			throw new \RuntimeException( $message );
		}
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wdc_delivery_service_countries';
	}
}
