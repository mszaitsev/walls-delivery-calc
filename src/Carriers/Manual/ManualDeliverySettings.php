<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Manual;

use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Common\Money;

defined( 'ABSPATH' ) || exit;

final class ManualDeliverySettings {
	public const CARRIER_KEY = 'manual';
	public const CARRIER_TITLE = 'Ручная доставка';
	public const PRICING_MODE_FLAT = 'flat';
	public const PRICING_SETTING_KEY = 'manual_pricing';
	public const DELIVERY_DAYS_SETTING_KEY = 'manual_delivery_days';

	public function __construct(
		private DeliveryServiceSettingsRepository $settings
	) {
	}

	/**
	 * @return array{pricing_mode:string,flat_price_kopecks:int}
	 */
	public function pricing( int $service_id ): array {
		$value = $this->settings->get_setting( $service_id, self::PRICING_SETTING_KEY, array() );
		if ( ! is_array( $value ) ) {
			return $this->invalid_pricing();
		}

		$mode = (string) ( $value['pricing_mode'] ?? self::PRICING_MODE_FLAT );
		if ( self::PRICING_MODE_FLAT !== $mode || ! array_key_exists( 'flat_price_kopecks', $value ) ) {
			return array( 'pricing_mode' => $mode, 'flat_price_kopecks' => -1 );
		}

		return array(
			'pricing_mode' => self::PRICING_MODE_FLAT,
			'flat_price_kopecks' => max( 0, (int) ( $value['flat_price_kopecks'] ?? 0 ) ),
		);
	}

	public function save_flat_pricing( int $service_id, mixed $price_rub ): void {
		$this->settings->set_setting(
			$service_id,
			self::PRICING_SETTING_KEY,
			array(
				'pricing_mode' => self::PRICING_MODE_FLAT,
				'flat_price_kopecks' => Money::from_rubles( $price_rub )->get_kopecks(),
			),
			'json'
		);
	}

	/**
	 * @return array{min_days:?int,max_days:?int}
	 */
	public function delivery_days( int $service_id ): array {
		$value = $this->settings->get_setting( $service_id, self::DELIVERY_DAYS_SETTING_KEY, array() );
		if ( ! is_array( $value ) ) {
			return array( 'min_days' => null, 'max_days' => null );
		}

		$min = array_key_exists( 'min_days', $value ) && null !== $value['min_days'] ? max( 0, (int) $value['min_days'] ) : null;
		$max = array_key_exists( 'max_days', $value ) && null !== $value['max_days'] ? max( 0, (int) $value['max_days'] ) : null;
		if ( null !== $min && null !== $max && $min > $max ) {
			$max = $min;
		}

		return array( 'min_days' => $min, 'max_days' => $max );
	}

	public function save_delivery_days( int $service_id, mixed $min_days, mixed $max_days ): void {
		$min = '' === trim( (string) $min_days ) ? null : max( 0, (int) $min_days );
		$max = '' === trim( (string) $max_days ) ? null : max( 0, (int) $max_days );
		if ( null !== $min && null !== $max && $min > $max ) {
			$max = $min;
		}

		$this->settings->set_setting(
			$service_id,
			self::DELIVERY_DAYS_SETTING_KEY,
			array( 'min_days' => $min, 'max_days' => $max ),
			'json'
		);
	}

	/**
	 * @return array{pricing_mode:string,flat_price_kopecks:int}
	 */
	private function invalid_pricing(): array {
		return array( 'pricing_mode' => '', 'flat_price_kopecks' => -1 );
	}
}
