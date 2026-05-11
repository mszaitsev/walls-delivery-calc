<?php
/**
 * Carrier and service definitions.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Carrier_Registry {
	public const CARRIER_RUSSIAN_POST = 'russian_post';
	public const SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL = 'russian_post_international_parcel';
	public const DIRECTION_INTERNATIONAL_EXPORT = 'international_export';

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public function get_definitions(): array {
		return array(
			self::CARRIER_RUSSIAN_POST => array(
				'title' => 'Почта России',
				'services' => array(
					self::SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL => array(
						'title' => 'Почта России — международная доставка',
						'direction' => self::DIRECTION_INTERNATIONAL_EXPORT,
						'excluded_destination_countries' => array( 'RU' ),
						'supports' => array(
							'country_mapper' => true,
							'city_mapper' => false,
							'pickup_point' => false,
							'post_office' => true,
							'courier_door' => false,
							'delivery_terms' => true,
							'multiple_rates' => true,
						),
					),
				),
			),
		);
	}
}
