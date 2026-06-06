<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class RussianPostDomesticSettings {
	public const CARRIER_KEY = 'russian_post_domestic';
	public const SERVICE_KEY = 'russian_post_domestic';
	public const TITLE = 'Почта России — по России';
	public const PICKUP_SERVICE_TITLE = 'Почта России — до отделения';
	public const COURIER_SERVICE_TITLE = 'Почта России — курьером';

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
		$stored = array();
		$service = $this->service_for_key( $service_key ?: self::SERVICE_KEY );
		if ( $service instanceof DeliveryService && null !== $service->id && $this->service_settings instanceof DeliveryServiceSettingsRepository ) {
			$stored = $this->service_settings->all_settings( (int) $service->id );
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

	public static function normalize_delivery_type( string $delivery_type ): string {
		return DeliveryType::COURIER === $delivery_type ? DeliveryType::COURIER : DeliveryType::PICKUP;
	}

	public static function checkout_group_id( string $delivery_type ): string {
		return self::SERVICE_KEY . ':' . self::normalize_delivery_type( $delivery_type );
	}

	public static function rate_id( string $delivery_type, int|string $object_code ): string {
		return self::checkout_group_id( $delivery_type ) . ':' . preg_replace( '/\D+/', '', (string) $object_code );
	}

	public static function delivery_type_from_rate_id( string $rate_id ): string {
		$rate_id = self::strip_wc_method_prefix( $rate_id );
		foreach ( array( DeliveryType::PICKUP, DeliveryType::COURIER ) as $delivery_type ) {
			$group_id = self::checkout_group_id( $delivery_type );
			if ( $rate_id === $group_id || str_starts_with( $rate_id, $group_id . ':' ) ) {
				return $delivery_type;
			}
		}

		return DeliveryType::PICKUP;
	}

	public static function is_pickup_rate_id( string $rate_id ): bool {
		$rate_id = self::strip_wc_method_prefix( $rate_id );
		$group_id = self::checkout_group_id( DeliveryType::PICKUP );

		return $rate_id === $group_id || str_starts_with( $rate_id, $group_id . ':' );
	}

	public static function strip_wc_method_prefix( string $rate_id ): string {
		$rate_id = trim( $rate_id );
		foreach ( array( 'wdc_platform_delivery:', 'wdc_platform:' ) as $prefix ) {
			if ( str_starts_with( $rate_id, $prefix ) ) {
				return substr( $rate_id, strlen( $prefix ) );
			}
		}

		return $rate_id;
	}

	private function service_for_key( string $service_key ): ?DeliveryService {
		if ( ! $this->services instanceof DeliveryServiceRepository || '' === trim( $service_key ) ) {
			return null;
		}

		return $this->services->find_by_service_key( $service_key );
	}
}
