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

	public function save_from_admin( array $input ): void {
		if ( array_key_exists( self::REQUEST_TIMEOUT_KEY, $input ) ) {
			$this->settings->set( self::REQUEST_TIMEOUT_KEY, max( 1, min( 60, (int) $input[ self::REQUEST_TIMEOUT_KEY ] ) ) );
		}
		if ( array_key_exists( self::ORIGIN_SOURCE_IDENTITY_KEY, $input ) ) {
			$this->settings->set( self::ORIGIN_SOURCE_IDENTITY_KEY, sanitize_text_field( wp_unslash( (string) $input[ self::ORIGIN_SOURCE_IDENTITY_KEY ] ) ) );
		}
	}
}
