<?php
/**
 * Carrier location mapping placeholder.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Location_Mapper {
	/**
	 * Map a WooCommerce country code to a carrier-specific country record.
	 *
	 * Different carriers can have their own country identifiers and naming.
	 *
	 * @return array<string, mixed>
	 */
	public function map_country( string $carrier_id, string $wc_country_code ): array {
		unset( $carrier_id, $wc_country_code );

		return array();
	}

	/**
	 * Map a city name to a carrier-specific city record.
	 *
	 * Some future services will require carrier city identifiers while others
	 * will not use city mapping at all.
	 *
	 * @return array<string, mixed>
	 */
	public function map_city( string $carrier_id, string $country_code, string $city_name ): array {
		unset( $carrier_id, $country_code, $city_name );

		return array();
	}
}
