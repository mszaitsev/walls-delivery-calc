<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class RussianPostSettings {
	public const CARRIER_KEY = 'russian_post';
	public const SERVICE_KEY = 'russian_post_worldwide_parcel';
	public const TITLE       = 'Почта России — международная доставка';

	public function __construct(
		private SettingsRepository $settings
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$settings = $this->settings->all();
		$service  = is_array( $settings['russian_post_worldwide_parcel'] ?? null ) ? $settings['russian_post_worldwide_parcel'] : array();

		return array_merge(
			$this->defaults(),
			$service,
			array(
				'packaging_tiers' => is_array( $settings['packaging_tiers'] ?? null ) ? $settings['packaging_tiers'] : array(),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return array(
			'enabled'              => true,
			'api_endpoint'         => 'https://tariff.pochta.ru/v2/calculate/tariff',
			'country_endpoint'     => 'https://tariff.pochta.ru/v2/dictionary/country',
			'api_token'            => '',
			'origin_postcode'      => '630005',
			'object_code'          => 4031,
			'isavia'               => 0,
			'timeout'              => 20,
			'debug'                => false,
			'max_package_weight_g' => 19990,
			'formula_divider'      => 0.89,
			'formula_add_rub'      => 200,
			'vat_rate'             => 0.2,
			'fallback_enabled'     => true,
			'fallback_text'        => 'Стоимость доставки рассчитает менеджер',
			'cache_until_end_of_day' => true,
			'auto_refresh_countries_if_empty' => false,
		);
	}

	public function enabled(): bool {
		return ! empty( $this->all()['enabled'] );
	}

	public function debug_enabled(): bool {
		return ! empty( $this->all()['debug'] );
	}
}
