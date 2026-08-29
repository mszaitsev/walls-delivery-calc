<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery;

use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class OzonDeliverySettings {
	public const CARRIER_KEY = 'ozon_delivery';
	public const SERVICE_KEY = 'ozon_delivery';
	public const TITLE = 'Ozon Доставка';
	public const PUBLIC_TITLE = 'Ozon Доставка';
	public const API_BASE_URL = 'https://api-delivery.ozon.ru';
	public const TOKEN_URL = 'https://xapi.ozon.ru/oauth/token';
	public const TOKEN_SCOPE = array( 'delivery-api.all' );
	public const CLIENT_ID_KEY = 'ozon_delivery_client_id';
	public const CLIENT_SECRET_ENCRYPTED_KEY = 'ozon_delivery_client_secret_encrypted';
	public const REQUEST_TIMEOUT_KEY = 'ozon_delivery_request_timeout';
	public const LAST_DIAGNOSTIC_KEY = 'ozon_delivery_last_diagnostic';
	public const PICKUP_AUTO_SYNC_KEY = 'ozon_delivery_pickup_auto_sync';
	public const PICKUP_SYNC_TIME_KEY = 'ozon_delivery_pickup_sync_time';

	public function __construct( private SettingsRepository $settings ) {}

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			self::CLIENT_ID_KEY => '',
			self::CLIENT_SECRET_ENCRYPTED_KEY => '',
			self::REQUEST_TIMEOUT_KEY => 15,
			self::LAST_DIAGNOSTIC_KEY => array(),
			self::PICKUP_AUTO_SYNC_KEY => true,
			self::PICKUP_SYNC_TIME_KEY => '02:00',
		);
	}

	public function request_timeout(): int {
		return max( 1, min( 30, $this->settings->get_int( self::REQUEST_TIMEOUT_KEY, 15 ) ) );
	}

	/** @return array<string,mixed> */
	public function last_diagnostic(): array {
		return $this->settings->get_array( self::LAST_DIAGNOSTIC_KEY, array() );
	}

	/** @param array<string,mixed> $result */
	public function save_last_diagnostic( array $result ): void {
		$this->settings->set( self::LAST_DIAGNOSTIC_KEY, $result );
	}

	public function pickup_auto_sync_enabled(): bool { return $this->settings->get_bool( self::PICKUP_AUTO_SYNC_KEY, true ); }
	public function pickup_sync_time(): string { $time = $this->settings->get_string( self::PICKUP_SYNC_TIME_KEY, '02:00' ); return 1 === preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time ) ? $time : '02:00'; }
	/** @param array<string,mixed> $input */
	public function save_pickup_schedule( array $input ): void { $time = isset( $input['ozon_delivery_pickup_sync_time'] ) ? trim( (string) $input['ozon_delivery_pickup_sync_time'] ) : '02:00'; $this->settings->set( self::PICKUP_AUTO_SYNC_KEY, ! empty( $input['ozon_delivery_pickup_auto_sync'] ) ); $this->settings->set( self::PICKUP_SYNC_TIME_KEY, 1 === preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time ) ? $time : '02:00' ); }
}
