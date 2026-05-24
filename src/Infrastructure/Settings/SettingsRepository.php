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
			'enable_demo_carrier'           => true,
			'location_search_limit'          => 100,
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
