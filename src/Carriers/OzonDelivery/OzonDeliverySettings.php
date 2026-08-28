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

	public function __construct( private SettingsRepository $settings ) {}

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			self::CLIENT_ID_KEY => '',
			self::CLIENT_SECRET_ENCRYPTED_KEY => '',
			self::REQUEST_TIMEOUT_KEY => 15,
			self::LAST_DIAGNOSTIC_KEY => array(),
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
}
