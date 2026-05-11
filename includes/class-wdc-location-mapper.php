<?php
/**
 * Carrier location mapping.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Location_Mapper {
	private WDC_Russian_Post_Countries $russian_post_countries;

	private WDC_Logger $logger;

	private WDC_Settings $settings;

	public function __construct(
		?WDC_Russian_Post_Countries $russian_post_countries = null,
		?WDC_Logger $logger = null,
		?WDC_Settings $settings = null
	) {
		$this->logger = $logger ?? new WDC_Logger();
		$this->settings = $settings ?? new WDC_Settings();
		$this->russian_post_countries = $russian_post_countries ?? new WDC_Russian_Post_Countries( null, $this->logger, $this->settings );
	}

	/**
	 * Map a WooCommerce country code to a carrier-specific country record.
	 *
	 * Different carriers can have their own country identifiers and naming.
	 *
	 * @return array<string, mixed>
	 */
	public function map_country( string $carrier_id, string $wc_country_code ): array {
		$carrier_id = sanitize_key( $carrier_id );
		$wc_country_code = strtoupper( sanitize_text_field( $wc_country_code ) );

		if ( WDC_Carrier_Registry::CARRIER_RUSSIAN_POST !== $carrier_id ) {
			$this->debug_log(
				'Country mapping failed.',
				array(
					'carrier_id' => $carrier_id,
					'wc_country_code' => $wc_country_code,
					'reason' => 'unsupported_carrier',
				)
			);

			return array(
				'success' => false,
				'error_code' => 'unsupported_carrier',
				'carrier_id' => $carrier_id,
				'iso2' => $wc_country_code,
			);
		}

		$country = $this->russian_post_countries->get_country_by_wc_code( $wc_country_code );
		if ( empty( $country ) || empty( $country['enabled'] ) ) {
			$this->debug_log(
				'Russian Post country mapping failed.',
				array(
					'carrier_id' => $carrier_id,
					'wc_country_code' => $wc_country_code,
					'reason' => 'country_not_found',
				)
			);

			return array(
				'success' => false,
				'error_code' => 'country_not_found',
				'carrier_id' => $carrier_id,
				'iso2' => $wc_country_code,
			);
		}

		$mapping = array(
			'success' => true,
			'carrier_country_id' => (string) $country['carrier_country_id'],
			'country_name' => (string) $country['name'],
			'iso2' => (string) $country['iso2'],
			'raw' => $country,
		);

		$this->debug_log(
			'Russian Post country mapping succeeded.',
			array(
				'carrier_id' => $carrier_id,
				'wc_country_code' => $wc_country_code,
				'mapping' => $mapping,
			)
		);

		return $mapping;
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

	/**
	 * @param array<string, mixed> $context Debug context.
	 */
	private function debug_log( string $message, array $context = array() ): void {
		$settings = $this->settings->get();
		if ( isset( $settings['debug_enabled'] ) && 'yes' === $settings['debug_enabled'] ) {
			$this->logger->log( 'debug', $message, $context );
		}
	}
}
