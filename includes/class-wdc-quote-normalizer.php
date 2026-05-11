<?php
/**
 * Normalized service quote structures.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Quote_Normalizer {
	/**
	 * Normalized quote shape for a carrier/service response.
	 *
	 * A single service can return multiple rates: ground, air, courier,
	 * express, pickup/post office, door delivery, economy, standard, or
	 * other carrier-specific variants.
	 *
	 * @return array<string, mixed>
	 */
	public function get_default_quote(): array {
		return array(
			'success' => true,
			'carrier_id' => WDC_Carrier_Registry::CARRIER_RUSSIAN_POST,
			'carrier_title' => 'Почта России',
			'service_id' => WDC_Carrier_Registry::SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL,
			'service_title' => 'Почта России — международная доставка',
			'destination' => array(
				'country' => '',
				'country_code' => '',
				'carrier_country_id' => '',
				'city' => '',
				'carrier_city_id' => '',
				'postal_code' => '',
			),
			'weight' => array(
				'cart_weight_g' => 0,
				'packaging_weight_g' => 0,
				'total_weight_g' => 0,
			),
			'rates' => array(
				$this->get_default_rate(),
			),
			'meta' => array(),
			'raw' => array(),
			'error_code' => '',
			'error_message' => '',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_default_rate(): array {
		return array(
			'rate_id' => 'russian_post_international_parcel_ground',
			'rate_title' => 'Доставка Почтой России',
			'label_template' => 'Ориентировочная цена доставки вашей посылки в {country}',
			'delivery_method' => 'post_office',
			'delivery_method_title' => 'до отделения / пункта выдачи',
			'transport_type' => 'ground',
			'transport_type_title' => 'наземная доставка',
			'tariff_type' => 'standard',
			'tariff_type_title' => 'стандартный тариф',
			'price' => 0,
			'currency' => 'RUB',
			'delivery_days_min' => null,
			'delivery_days_max' => null,
			'delivery_days_text' => '',
			'is_fallback' => false,
			'meta' => array(),
			'raw' => array(),
		);
	}

	/**
	 * @param array<string, mixed> $quote Partial quote.
	 * @return array<string, mixed>
	 */
	public function normalize_quote( array $quote ): array {
		$normalized = array_replace_recursive( $this->get_default_quote(), $quote );
		$rates = isset( $quote['rates'] ) && is_array( $quote['rates'] ) ? $quote['rates'] : $normalized['rates'];

		$normalized['rates'] = array();
		foreach ( $rates as $rate ) {
			if ( is_array( $rate ) ) {
				$normalized['rates'][] = $this->normalize_rate( $rate );
			}
		}

		if ( empty( $normalized['rates'] ) ) {
			$normalized['rates'][] = $this->get_default_rate();
		}

		return $normalized;
	}

	/**
	 * @param array<string, mixed> $rate Partial rate.
	 * @return array<string, mixed>
	 */
	public function normalize_rate( array $rate ): array {
		return array_replace_recursive( $this->get_default_rate(), $rate );
	}

	/**
	 * @param array<string, mixed> $context Optional fallback context.
	 * @return array<string, mixed>
	 */
	public function create_fallback_quote( array $context = array() ): array {
		$fallback_label = $this->get_fallback_label( $context );
		$quote = array(
			'success' => true,
			'carrier_id' => WDC_Carrier_Registry::CARRIER_RUSSIAN_POST,
			'carrier_title' => 'Почта России',
			'service_id' => WDC_Carrier_Registry::SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL,
			'service_title' => 'Почта России — международная доставка',
			'rates' => array(
				array(
					'rate_id' => 'russian_post_international_parcel_fallback',
					'rate_title' => $fallback_label,
					'label_template' => $fallback_label,
					'price' => 0,
					'is_fallback' => true,
				),
			),
		);

		if ( isset( $context['destination'] ) && is_array( $context['destination'] ) ) {
			$quote['destination'] = $context['destination'];
		}

		if ( isset( $context['weight'] ) && is_array( $context['weight'] ) ) {
			$quote['weight'] = $context['weight'];
		}

		if ( isset( $context['meta'] ) && is_array( $context['meta'] ) ) {
			$quote['meta'] = $context['meta'];
		}

		if ( isset( $context['raw'] ) && is_array( $context['raw'] ) ) {
			$quote['raw'] = $context['raw'];
		}

		return $this->normalize_quote( $quote );
	}

	/**
	 * Backward-compatible alias for older internal calls.
	 *
	 * @param array<string, mixed> $quote Partial quote.
	 * @return array<string, mixed>
	 */
	public function normalize( array $quote ): array {
		return $this->normalize_quote( $quote );
	}

	/**
	 * @param array<string, mixed> $context Optional fallback context.
	 */
	private function get_fallback_label( array $context ): string {
		if ( isset( $context['fallback_label'] ) && is_scalar( $context['fallback_label'] ) ) {
			return (string) $context['fallback_label'];
		}

		if (
			isset( $context['settings']['services'][ WDC_Carrier_Registry::SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL ]['fallback_label'] )
			&& is_scalar( $context['settings']['services'][ WDC_Carrier_Registry::SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL ]['fallback_label'] )
		) {
			return (string) $context['settings']['services'][ WDC_Carrier_Registry::SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL ]['fallback_label'];
		}

		if ( class_exists( 'WDC_Settings' ) ) {
			$settings = ( new WDC_Settings() )->get();
			$service = $settings['services'][ WDC_Carrier_Registry::SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL ] ?? array();

			if ( isset( $service['fallback_label'] ) && is_scalar( $service['fallback_label'] ) ) {
				return (string) $service['fallback_label'];
			}
		}

		return 'Прошу менеджера магазина рассчитать доставку в мою страну, оплачу доставку отдельно';
	}
}
