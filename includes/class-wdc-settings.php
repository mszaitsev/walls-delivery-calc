<?php
/**
 * Plugin settings storage.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Settings {
	public const OPTION_NAME = 'wdc_settings';

	/**
	 * @return array<string, mixed>
	 */
	public function get_defaults(): array {
		return array(
			'debug_enabled' => 'no',
			'fallback_enabled' => 'yes',
			'max_package_weight_g' => 19990,
			'currency' => 'RUB',
			'services' => array(
				'russian_post_international_parcel' => array(
					'enabled' => 'yes',
					'origin_postcode' => '630005',
					'object_code' => 4031,
					'isavia' => 0,
					'formula_divider' => 0.89,
					'formula_add_rub' => 200,
					'cache_until_end_of_day' => 'yes',
					'fallback_label' => 'Прошу менеджера магазина рассчитать доставку в мою страну, оплачу доставку отдельно',
					'calculated_label_template' => 'Ориентировочная цена доставки вашей посылки в {country} (требуется уточнение у менеджера магазина)',
				),
			),
			'packaging_tiers' => array(
				array(
					'from_g' => 0,
					'to_g' => 1000,
					'packaging_weight_g' => 150,
				),
				array(
					'from_g' => 1001,
					'to_g' => 3000,
					'packaging_weight_g' => 250,
				),
				array(
					'from_g' => 3001,
					'to_g' => 7000,
					'packaging_weight_g' => 400,
				),
				array(
					'from_g' => 7001,
					'to_g' => 15000,
					'packaging_weight_g' => 550,
				),
				array(
					'from_g' => 15001,
					'to_g' => 19990,
					'packaging_weight_g' => 700,
				),
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get(): array {
		$settings = get_option( self::OPTION_NAME, array() );
		$settings = is_array( $settings ) ? $settings : array();

		return $this->merge_defaults( $this->get_defaults(), $settings );
	}

	/**
	 * @param array<string, mixed> $settings Settings to save.
	 */
	public function update( array $settings ): bool {
		return update_option( self::OPTION_NAME, $this->sanitize( $settings ) );
	}

	/**
	 * @param array<string, mixed> $settings Raw settings.
	 * @return array<string, mixed>
	 */
	public function sanitize( array $settings ): array {
		$current = $this->merge_defaults( $this->get_defaults(), $settings );
		$service = $current['services']['russian_post_international_parcel'];

		return array(
			'debug_enabled' => $this->sanitize_yes_no( $current['debug_enabled'] ),
			'fallback_enabled' => $this->sanitize_yes_no( $current['fallback_enabled'] ),
			'max_package_weight_g' => max( 0, absint( $current['max_package_weight_g'] ) ),
			'currency' => sanitize_text_field( (string) $current['currency'] ),
			'services' => array(
				'russian_post_international_parcel' => array(
					'enabled' => $this->sanitize_yes_no( $service['enabled'] ),
					'origin_postcode' => sanitize_text_field( (string) $service['origin_postcode'] ),
					'object_code' => absint( $service['object_code'] ),
					'isavia' => absint( $service['isavia'] ),
					'formula_divider' => (float) $service['formula_divider'],
					'formula_add_rub' => (float) $service['formula_add_rub'],
					'cache_until_end_of_day' => $this->sanitize_yes_no( $service['cache_until_end_of_day'] ),
					'fallback_label' => sanitize_text_field( (string) $service['fallback_label'] ),
					'calculated_label_template' => sanitize_text_field( (string) $service['calculated_label_template'] ),
				),
			),
			'packaging_tiers' => $this->sanitize_packaging_tiers( $current['packaging_tiers'] ),
		);
	}

	private function sanitize_yes_no( $value ): string {
		return 'yes' === $value ? 'yes' : 'no';
	}

	/**
	 * @param mixed $tiers Raw tiers.
	 * @return array<int, array<string, int>>
	 */
	private function sanitize_packaging_tiers( $tiers ): array {
		if ( ! is_array( $tiers ) ) {
			return $this->get_defaults()['packaging_tiers'];
		}

		$sanitized = array();
		foreach ( $tiers as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}

			$sanitized[] = array(
				'from_g' => isset( $tier['from_g'] ) ? absint( $tier['from_g'] ) : 0,
				'to_g' => isset( $tier['to_g'] ) ? absint( $tier['to_g'] ) : 0,
				'packaging_weight_g' => isset( $tier['packaging_weight_g'] ) ? absint( $tier['packaging_weight_g'] ) : 0,
			);
		}

		return ! empty( $sanitized ) ? $sanitized : $this->get_defaults()['packaging_tiers'];
	}

	/**
	 * @param array<string, mixed> $defaults Default values.
	 * @param array<string, mixed> $settings Saved values.
	 * @return array<string, mixed>
	 */
	private function merge_defaults( array $defaults, array $settings ): array {
		foreach ( $defaults as $key => $default_value ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				$settings[ $key ] = $default_value;
				continue;
			}

			if ( is_array( $default_value ) ) {
				if ( ! is_array( $settings[ $key ] ) ) {
					$settings[ $key ] = $default_value;
					continue;
				}

				$settings[ $key ] = $this->merge_defaults( $default_value, $settings[ $key ] );
			}
		}

		return $settings;
	}
}
