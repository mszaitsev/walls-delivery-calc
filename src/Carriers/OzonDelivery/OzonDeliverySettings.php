<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery;

use WallsShop\WDC\Domain\Phone\RussianPhoneNormalizer;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class OzonDeliverySettings {
	public const CARRIER_KEY = 'ozon_delivery';
	public const SERVICE_KEY = 'ozon_delivery';
	public const PICKUP_FAMILY = 'ozon_delivery:pickup';
	public const TITLE = 'Ozon Доставка';
	public const PUBLIC_TITLE = 'Ozon Доставка';
	public const API_BASE_URL = 'https://api-delivery.ozon.ru';
	public const TOKEN_URL = 'https://xapi.ozon.ru/oauth/token';
	public const TOKEN_SCOPE = array( 'delivery-api.all' );
	public const CLIENT_ID_KEY = 'ozon_delivery_client_id';
	public const CLIENT_SECRET_ENCRYPTED_KEY = 'ozon_delivery_client_secret_encrypted';
	public const REQUEST_TIMEOUT_KEY = 'ozon_delivery_request_timeout';
	public const LAST_DIAGNOSTIC_KEY = 'ozon_delivery_last_diagnostic';
	public const LAST_QUOTE_DIAGNOSTIC_KEY = 'ozon_delivery_last_quote_diagnostic';
	public const SHIPMENT_METHOD_ID_KEY = 'ozon_delivery_shipment_method_id';
	public const COURIER_SHIPMENT_METHOD_ID_KEY = 'ozon_delivery_courier_shipment_method_id';
	public const QUOTE_FALLBACK_PHONE_KEY = 'ozon_delivery_quote_fallback_phone';
	public const PICKUP_AUTO_SYNC_KEY = 'ozon_delivery_pickup_auto_sync';
	public const PICKUP_SYNC_TIME_KEY = 'ozon_delivery_pickup_sync_time';
	public const SHIPMENT_STATUS_MAPPING_KEY = 'ozon_delivery_shipment_status_mapping';

	private RussianPhoneNormalizer $phones;

	public function __construct( private SettingsRepository $settings, ?RussianPhoneNormalizer $phones = null ) {
		$this->phones = $phones ?? new RussianPhoneNormalizer();
	}

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			self::CLIENT_ID_KEY => '',
			self::CLIENT_SECRET_ENCRYPTED_KEY => '',
			self::REQUEST_TIMEOUT_KEY => 30,
			self::LAST_DIAGNOSTIC_KEY => array(),
			self::LAST_QUOTE_DIAGNOSTIC_KEY => array(),
			self::SHIPMENT_METHOD_ID_KEY => 0,
			self::COURIER_SHIPMENT_METHOD_ID_KEY => 0,
			self::QUOTE_FALLBACK_PHONE_KEY => '',
			self::PICKUP_AUTO_SYNC_KEY => true,
			self::PICKUP_SYNC_TIME_KEY => '02:00',
			self::SHIPMENT_STATUS_MAPPING_KEY => array(),
		);
	}

	public function request_timeout(): int {
		return max( 1, min( 30, $this->settings->get_int( self::REQUEST_TIMEOUT_KEY, 30 ) ) );
	}

	/** @return array<string,mixed> */
	public function last_diagnostic(): array {
		return $this->settings->get_array( self::LAST_DIAGNOSTIC_KEY, array() );
	}

	/** @param array<string,mixed> $result */
	public function save_last_diagnostic( array $result ): void {
		$this->settings->set( self::LAST_DIAGNOSTIC_KEY, $result );
	}

	public function shipment_method_id(): int {
		return max( 0, $this->settings->get_int( self::SHIPMENT_METHOD_ID_KEY, 0 ) );
	}

	public function pickup_shipment_method_id(): int {
		return $this->shipment_method_id();
	}

	public function courier_shipment_method_id(): int {
		return max( 0, $this->settings->get_int( self::COURIER_SHIPMENT_METHOD_ID_KEY, 0 ) );
	}

	public function quote_fallback_phone(): string {
		return $this->phones->normalize( $this->settings->get_string( self::QUOTE_FALLBACK_PHONE_KEY, '' ) );
	}

	/** @param array<string,mixed> $input */
	public function save_pricing_settings( array $input ): void {
		if ( array_key_exists( self::SHIPMENT_METHOD_ID_KEY, $input ) ) {
			$value = preg_replace( '/\D+/', '', (string) $input[ self::SHIPMENT_METHOD_ID_KEY ] ) ?? '';
			$this->settings->set( self::SHIPMENT_METHOD_ID_KEY, '' === $value ? 0 : max( 0, (int) $value ) );
		}
		if ( array_key_exists( self::COURIER_SHIPMENT_METHOD_ID_KEY, $input ) ) {
			$value = preg_replace( '/\D+/', '', (string) $input[ self::COURIER_SHIPMENT_METHOD_ID_KEY ] ) ?? '';
			$this->settings->set( self::COURIER_SHIPMENT_METHOD_ID_KEY, '' === $value ? 0 : max( 0, (int) $value ) );
		}
		if ( array_key_exists( self::QUOTE_FALLBACK_PHONE_KEY, $input ) ) {
			$this->settings->set( self::QUOTE_FALLBACK_PHONE_KEY, $this->phones->normalize( $input[ self::QUOTE_FALLBACK_PHONE_KEY ] ) );
		}
	}

	/** @return array<string,mixed> */
	public function last_quote_diagnostic(): array {
		return $this->settings->get_array( self::LAST_QUOTE_DIAGNOSTIC_KEY, array() );
	}

	/** @param array<string,mixed> $result */
	public function save_last_quote_diagnostic( array $result ): void {
		$this->settings->set( self::LAST_QUOTE_DIAGNOSTIC_KEY, $result );
	}

	public function pricing_live_confirmed(): bool {
		$diagnostic = $this->last_quote_diagnostic();
		return ! empty( $diagnostic['success'] )
			&& 'POST /v1/order/checkout' === (string) ( $diagnostic['endpoint'] ?? '' )
			&& (int) ( $diagnostic['shipment_method_id'] ?? 0 ) === $this->shipment_method_id();
	}

	public function pickup_auto_sync_enabled(): bool { return $this->settings->get_bool( self::PICKUP_AUTO_SYNC_KEY, true ); }
	public function pickup_sync_time(): string { $time = $this->settings->get_string( self::PICKUP_SYNC_TIME_KEY, '02:00' ); return 1 === preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time ) ? $time : '02:00'; }
	/** @param array<string,mixed> $input */
	public function save_pickup_schedule( array $input ): void { $time = isset( $input['ozon_delivery_pickup_sync_time'] ) ? trim( (string) $input['ozon_delivery_pickup_sync_time'] ) : '02:00'; $this->settings->set( self::PICKUP_AUTO_SYNC_KEY, ! empty( $input['ozon_delivery_pickup_auto_sync'] ) ); $this->settings->set( self::PICKUP_SYNC_TIME_KEY, 1 === preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time ) ? $time : '02:00' ); }
	/** @return array<string,string> */
	public function shipment_status_mapping(): array { $mapping = $this->settings->get_array( self::SHIPMENT_STATUS_MAPPING_KEY, array() ); $result = array(); foreach ( $mapping as $status => $universal ) { if ( is_scalar( $status ) && is_scalar( $universal ) ) { $result[ (string) $status ] = (string) $universal; } } return $result; }
	/** @param array<string,string> $mapping */
	public function save_shipment_status_mapping( array $mapping ): void { $this->settings->set( self::SHIPMENT_STATUS_MAPPING_KEY, $mapping ); }
}
