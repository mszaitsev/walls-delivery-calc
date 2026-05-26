<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class RussianPostDomesticSettings {
	public const CARRIER_KEY = 'russian_post_domestic';
	public const PICKUP_SERVICE_KEY = 'russian_post_domestic_pickup';
	public const COURIER_SERVICE_KEY = 'russian_post_domestic_courier';
	public const TITLE = 'Почта России — по России';

	public function __construct(
		private SettingsRepository $settings,
		private ?DeliveryServiceRepository $services = null,
		private ?DeliveryServiceSettingsRepository $service_settings = null
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function defaults(): array {
		return array(
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
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all( string $service_key = '' ): array {
		$settings = $this->settings->all();
		$stored = is_array( $settings[ self::CARRIER_KEY ] ?? null ) ? $settings[ self::CARRIER_KEY ] : array();
		$service = $this->service_for_key( $service_key );
		if ( $service instanceof DeliveryService && null !== $service->id && $this->service_settings instanceof DeliveryServiceSettingsRepository ) {
			$stored = array_merge( $stored, $this->service_settings->all_settings( (int) $service->id ) );
			$stored['enabled'] = $service->enabled;
		}

		return array_merge( $this->defaults(), $stored );
	}

	public function enabled( string $service_key = '' ): bool {
		return ! empty( $this->all( $service_key )['enabled'] );
	}

	public function debug_enabled( string $service_key = '' ): bool {
		return ! empty( $this->all( $service_key )['debug'] );
	}

	public static function service_delivery_type( string $service_key ): string {
		return self::COURIER_SERVICE_KEY === $service_key ? \WallsShop\WDC\Domain\Quote\DeliveryType::COURIER : \WallsShop\WDC\Domain\Quote\DeliveryType::PICKUP;
	}

	private function service_for_key( string $service_key ): ?DeliveryService {
		if ( ! $this->services instanceof DeliveryServiceRepository || '' === trim( $service_key ) ) {
			return null;
		}

		return $this->services->find_by_service_key( $service_key );
	}
}
