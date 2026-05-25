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
		$this->delete_countries( $service_id );
		$now = current_time( 'mysql' );
		foreach ( $this->normalize_countries( $countries ) as $country ) {
			$this->wpdb->insert(
				$this->table(),
				array( 'service_id' => $service_id, 'country_code' => $country, 'created_at' => $now ),
				array( '%d', '%s', '%s' )
			);
		}
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

	public function delete_countries( int $service_id ): void {
		$this->wpdb->delete( $this->table(), array( 'service_id' => $service_id ), array( '%d' ) );
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

		return array_values( array_unique( $result ) );
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wdc_delivery_service_countries';
	}
}
