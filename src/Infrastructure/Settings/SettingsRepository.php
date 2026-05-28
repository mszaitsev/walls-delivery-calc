<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsRepository {
	private const OPTION_NAME = 'wdc_core_settings';

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		$settings = get_option( self::OPTION_NAME, array() );

		return array_merge( $this->defaults(), is_array( $settings ) ? $settings : array() );
	}

	/**
	 * @param array<string, mixed> $settings
	 */
	public function replace( array $settings ): bool {
		return update_option( self::OPTION_NAME, $settings, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function defaults(): array {
		return array(
			'shop_processing_days'          => 1,
			'auto_generate_next_year'       => true,
			'enable_new_checkout_shipping' => false,
			'checkout_sort_mode'            => 'cheapest',
			'show_checkout_debug_panel'     => false,
			'include_region_in_checkout_city_picker_query' => true,
			'checkout_location_search_limit' => 100,
			'checkout_location_region_limit' => 10,
			'fias_api_enabled'              => true,
			'fias_api_timeout'              => 3,
			'fias_api_daily_limit'          => 10000,
			'fias_api_minute_limit'         => 100,
			'dadata_suggestions_enabled'    => false,
			'dadata_suggestions_timeout'    => 3,
			'dadata_suggestions_count'      => 10,
			'dadata_suggestions_tokens'     => array(),
			'russian_post_worldwide_parcel' => array(
				'enabled'                 => true,
				'api_endpoint'            => 'https://tariff.pochta.ru/v2/calculate/tariff',
				'country_endpoint'        => 'https://tariff.pochta.ru/v2/dictionary/country',
				'api_token'               => '',
				'origin_postcode'         => '630005',
				'object_code'             => 4031,
				'isavia'                  => 0,
				'timeout'                 => 20,
				'debug'                   => false,
				'max_package_weight_g'    => 19990,
				'formula_divider'         => 0.89,
				'formula_add_rub'         => 200,
				'vat_rate'                => 0.2,
				'fallback_enabled'        => true,
				'fallback_text'           => 'Стоимость доставки рассчитает менеджер',
				'cache_until_end_of_day'  => true,
				'auto_refresh_countries_if_empty' => false,
			),
			'russian_post_domestic' => array(
				'enabled' => true,
				'api_endpoint' => 'https://tariff.pochta.ru/v2/calculate/tariff/delivery',
				'api_token' => '',
				'from_postcodes' => array( '630005' ),
				'default_from_postcode' => '630005',
				'return_postcode' => '630005',
				'insurance_enabled' => false,
				'timeout' => 20,
				'vat_rate' => 0.2,
				'fallback_enabled' => false,
				'fallback_text' => 'Стоимость доставки рассчитает менеджер',
				'cache_until_end_of_day' => true,
				'debug' => false,
			),
			'russian_post_otpravka_access_token' => '',
			'russian_post_otpravka_login' => '',
			'russian_post_otpravka_password_encrypted' => '',
			'russian_post_otpravka_timeout' => 120,
			'russian_post_pickup_unload_type' => 'ALL',
			'russian_post_pickup_schedule_enabled' => false,
			'russian_post_pickup_last_import_result' => array(),
			'russian_post_pickup_last_success_at' => '',
			'packaging_weight_tiers'       => array(),
		);
	}

	public function set( string $key, mixed $value ): bool {
		$settings = $this->all();
		$settings[ $key ] = $value;

		return $this->replace( $settings );
	}

	public function get_string( string $key, string $default = '' ): string {
		$value = $this->all()[ $key ] ?? $default;

		return is_scalar( $value ) ? (string) $value : $default;
	}

	public function get_bool( string $key, bool $default = false ): bool {
		$value = $this->all()[ $key ] ?? $default;

		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_scalar( $value ) ) {
			return in_array( strtolower( (string) $value ), array( '1', 'true', 'yes', 'on' ), true );
		}

		return $default;
	}

	public function get_int( string $key, int $default = 0 ): int {
		$value = $this->all()[ $key ] ?? $default;

		return is_numeric( $value ) ? (int) $value : $default;
	}

	/**
	 * @return array<string|int, mixed>
	 */
	public function get_array( string $key, array $default = array() ): array {
		$value = $this->all()[ $key ] ?? $default;

		return is_array( $value ) ? $value : $default;
	}
}
