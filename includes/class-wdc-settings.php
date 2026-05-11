<?php
/**
 * Plugin settings storage.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Settings {
	public const OPTION_NAME = 'wdc_settings';
	public const SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL = 'russian_post_worldwide_parcel';
	private const LEGACY_SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL = 'russian_post_international_parcel';

	/**
	 * @return array<string, mixed>
	 */
	public function get_defaults(): array {
		return array(
			'debug_enabled' => 'no',
			'fallback_enabled' => 'yes',
			'currency' => 'RUB',
			'services' => array(
				self::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL => array(
					'enabled' => 'yes',
					'origin_postcode' => '630005',
					'object_code' => 4031,
					'isavia' => 0,
					'max_package_weight_g' => 19990,
					'formula_divider' => 0.89,
					'formula_add_rub' => 200,
					'cache_until_end_of_day' => 'yes',
					'fallback_label' => 'Прошу менеджера магазина рассчитать доставку в мою страну, оплачу доставку отдельно',
					'calculated_label_template' => 'Ориентировочная цена доставки вашей посылки в {country} (требуется уточнение у менеджера магазина)',
				),
			),
			'packaging_tiers' => array(
				array(
					'from_weight_g' => 0,
					'to_weight_g' => 1000,
					'packaging_weight_g' => 150,
				),
				array(
					'from_weight_g' => 1001,
					'to_weight_g' => 3000,
					'packaging_weight_g' => 250,
				),
				array(
					'from_weight_g' => 3001,
					'to_weight_g' => 7000,
					'packaging_weight_g' => 400,
				),
				array(
					'from_weight_g' => 7001,
					'to_weight_g' => 15000,
					'packaging_weight_g' => 550,
				),
				array(
					'from_weight_g' => 15001,
					'to_weight_g' => 19990,
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
		$settings = $this->migrate_legacy_settings( $settings );

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
		$current = $this->merge_defaults( $this->get_defaults(), $this->migrate_legacy_settings( $settings ) );
		$service = $current['services'][ self::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ];

		return array(
			'debug_enabled' => $this->sanitize_yes_no( $current['debug_enabled'] ),
			'fallback_enabled' => $this->sanitize_yes_no( $current['fallback_enabled'] ),
			'currency' => sanitize_text_field( (string) $current['currency'] ),
			'services' => array(
				self::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL => array(
					'enabled' => $this->sanitize_yes_no( $service['enabled'] ),
					'origin_postcode' => sanitize_text_field( (string) $service['origin_postcode'] ),
					'object_code' => absint( $service['object_code'] ),
					'isavia' => absint( $service['isavia'] ),
					'max_package_weight_g' => max( 0, absint( $service['max_package_weight_g'] ) ),
					'formula_divider' => max( 0.01, (float) $service['formula_divider'] ),
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
				'from_weight_g' => isset( $tier['from_weight_g'] ) ? absint( $tier['from_weight_g'] ) : absint( $tier['from_g'] ?? 0 ),
				'to_weight_g' => isset( $tier['to_weight_g'] ) ? absint( $tier['to_weight_g'] ) : absint( $tier['to_g'] ?? 0 ),
				'packaging_weight_g' => isset( $tier['packaging_weight_g'] ) ? absint( $tier['packaging_weight_g'] ) : 0,
			);
		}

		return ! empty( $sanitized ) ? $sanitized : $this->get_defaults()['packaging_tiers'];
	}

	/**
	 * @param array<string, mixed> $settings Saved or submitted settings.
	 * @return array<string, mixed>
	 */
	private function migrate_legacy_settings( array $settings ): array {
		if ( ! isset( $settings['services'] ) || ! is_array( $settings['services'] ) ) {
			$settings['services'] = array();
		}

		if (
			isset( $settings['services'][ self::LEGACY_SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL ] )
			&& is_array( $settings['services'][ self::LEGACY_SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL ] )
			&& ! isset( $settings['services'][ self::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ] )
		) {
			$settings['services'][ self::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ] = $settings['services'][ self::LEGACY_SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL ];
		}

		if ( isset( $settings['max_package_weight_g'] ) ) {
			if ( ! isset( $settings['services'][ self::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ] ) || ! is_array( $settings['services'][ self::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ] ) ) {
				$settings['services'][ self::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ] = array();
			}

			if ( ! isset( $settings['services'][ self::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ]['max_package_weight_g'] ) ) {
				$settings['services'][ self::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ]['max_package_weight_g'] = $settings['max_package_weight_g'];
			}

			unset( $settings['max_package_weight_g'] );
		}

		unset( $settings['services'][ self::LEGACY_SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL ] );

		return $settings;
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
