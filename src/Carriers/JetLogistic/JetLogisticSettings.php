<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic;

use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class JetLogisticSettings {
	public const CARRIER_KEY = 'jet_logistic';
	public const SERVICE_KEY = 'jet_logistic';
	public const PICKUP_RATE_KEY = 'jet_logistic_pickup';
	public const COURIER_RATE_KEY = 'jet_logistic_courier';
	public const TITLE = 'Jet Logistic';
	public const PUBLIC_TITLE = 'Джет Логистик';
	public const ORIGIN_SOURCE_IDENTITY_KEY = 'jet_logistic_origin_source_identity';
	public const REQUEST_TIMEOUT_KEY = 'jet_logistic_http_timeout';
	public const INSURANCE_PERCENT_KEY = 'jet_logistic_insurance_percent';
	public const INSURANCE_MIN_RUB_KEY = 'jet_logistic_insurance_min_rub';
	public const ALMATY_FREE_COURIER_KEY = 'jet_logistic_almaty_free_courier';

	public function __construct(
		private SettingsRepository $settings,
		private ?DeliveryServiceRepository $services = null,
		private ?DeliveryServiceSettingsRepository $service_settings = null
	) {
	}

	public function request_timeout(): int {
		return max( 1, min( 60, $this->settings->get_int( self::REQUEST_TIMEOUT_KEY, 15 ) ) );
	}

	public function origin_source_identity(): string {
		return trim( $this->settings->get_string( self::ORIGIN_SOURCE_IDENTITY_KEY, '' ) );
	}

	public function insurance_percent(): float {
		return $this->decimal_setting( self::INSURANCE_PERCENT_KEY, 0.25, 0.0, 100.0 );
	}

	public function insurance_min_rub(): float {
		return $this->decimal_setting( self::INSURANCE_MIN_RUB_KEY, 65.0, 0.0, 100000.0 );
	}

	public function almaty_free_courier(): bool {
		return $this->settings->get_bool( self::ALMATY_FREE_COURIER_KEY, false );
	}

	public function save_from_admin( array $input ): void {
		if ( array_key_exists( self::REQUEST_TIMEOUT_KEY, $input ) ) {
			$this->settings->set( self::REQUEST_TIMEOUT_KEY, max( 1, min( 60, (int) $input[ self::REQUEST_TIMEOUT_KEY ] ) ) );
		}
		if ( array_key_exists( self::ORIGIN_SOURCE_IDENTITY_KEY, $input ) ) {
			$this->settings->set( self::ORIGIN_SOURCE_IDENTITY_KEY, sanitize_text_field( wp_unslash( (string) $input[ self::ORIGIN_SOURCE_IDENTITY_KEY ] ) ) );
		}
		if ( array_key_exists( self::INSURANCE_PERCENT_KEY, $input ) ) {
			$this->settings->set( self::INSURANCE_PERCENT_KEY, $this->sanitize_decimal( $input[ self::INSURANCE_PERCENT_KEY ], 0.25, 0.0, 100.0 ) );
		}
		if ( array_key_exists( self::INSURANCE_MIN_RUB_KEY, $input ) ) {
			$this->settings->set( self::INSURANCE_MIN_RUB_KEY, $this->sanitize_decimal( $input[ self::INSURANCE_MIN_RUB_KEY ], 65.0, 0.0, 100000.0 ) );
		}
		$this->settings->set( self::ALMATY_FREE_COURIER_KEY, ! empty( $input[ self::ALMATY_FREE_COURIER_KEY ] ) );
	}

	private function decimal_setting( string $key, float $default, float $min, float $max ): float {
		return $this->sanitize_decimal( $this->settings->get_string( $key, (string) $default ), $default, $min, $max );
	}

	private function sanitize_decimal( mixed $value, float $default, float $min, float $max ): float {
		$value = str_replace( ',', '.', trim( (string) wp_unslash( $value ) ) );
		$number = is_numeric( $value ) ? (float) $value : $default;

		return max( $min, min( $max, $number ) );
	}
}
