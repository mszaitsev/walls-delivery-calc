<?php
/**
 * Normalized service quote structure.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Quote_Normalizer {
	/**
	 * Normalized quote shape for any future carrier/service rate.
	 *
	 * Supported future dimensions include ground, air, courier, express,
	 * pickup point, door delivery, economy, standard, express tariffs,
	 * and delivery time ranges.
	 *
	 * @phpstan-type WDCServiceQuote array{
	 *   success: bool,
	 *   carrier_id: string,
	 *   carrier_title: string,
	 *   service_id: string,
	 *   service_title: string,
	 *   rate_id: string,
	 *   rate_title: string,
	 *   delivery_method: string,
	 *   transport_type: string,
	 *   tariff_type: string,
	 *   price: float|int,
	 *   currency: string,
	 *   delivery_days_min: int|null,
	 *   delivery_days_max: int|null,
	 *   delivery_days_text: string,
	 *   destination: array{country: string, country_code: string, city: string, postal_code: string},
	 *   weight: array{cart_weight_g: int, packaging_weight_g: int, total_weight_g: int},
	 *   meta: array<string, mixed>,
	 *   raw: array<string, mixed>,
	 *   error_code: string,
	 *   error_message: string
	 * }
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_quote(): array {
		return array(
			'success' => true,
			'carrier_id' => 'russian_post',
			'carrier_title' => 'Почта России',
			'service_id' => 'russian_post_international_parcel',
			'service_title' => 'Почта России — международная доставка',
			'rate_id' => 'russian_post_international_parcel_ground',
			'rate_title' => 'Доставка Почтой России',
			'delivery_method' => 'pickup_point_or_post_office',
			'transport_type' => 'ground',
			'tariff_type' => 'standard',
			'price' => 0,
			'currency' => 'RUB',
			'delivery_days_min' => null,
			'delivery_days_max' => null,
			'delivery_days_text' => '',
			'destination' => array(
				'country' => '',
				'country_code' => '',
				'city' => '',
				'postal_code' => '',
			),
			'weight' => array(
				'cart_weight_g' => 0,
				'packaging_weight_g' => 0,
				'total_weight_g' => 0,
			),
			'meta' => array(),
			'raw' => array(),
			'error_code' => '',
			'error_message' => '',
		);
	}

	/**
	 * @param array<string, mixed> $quote Partial quote.
	 * @return array<string, mixed>
	 */
	public function normalize( array $quote ): array {
		return array_replace_recursive( $this->get_default_quote(), $quote );
	}
}
