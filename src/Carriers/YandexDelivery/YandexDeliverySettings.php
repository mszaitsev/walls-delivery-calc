<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery;

use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class YandexDeliverySettings {
	public const SERVICE_KEY = 'yandex_delivery';
	public const CARRIER_KEY = 'yandex_delivery';
	public const TITLE = 'Яндекс Доставка';
	public const DEFAULT_PICKUP_METHOD_TITLE = 'Яндекс до ПВЗ';
	public const DEFAULT_COURIER_METHOD_TITLE = 'Яндекс до двери';
	public const PICKUP_METHOD_TITLE_KEY = 'pickup_method_title';
	public const COURIER_METHOD_TITLE_KEY = 'courier_method_title';
	public const ENV_TEST = 'test';
	public const ENV_PRODUCTION = 'production';
	public const DEFAULT_REQUEST_TIMEOUT = 20;
	public const ENVIRONMENT_KEY = 'yandex_delivery_environment';
	public const TEST_TOKEN_ENCRYPTED_KEY = 'yandex_delivery_test_bearer_token_encrypted';
	public const PRODUCTION_TOKEN_ENCRYPTED_KEY = 'yandex_delivery_production_bearer_token_encrypted';
	public const TEST_PLATFORM_STATION_ID_KEY = 'yandex_delivery_test_platform_station_id';
	public const PRODUCTION_PLATFORM_STATION_ID_KEY = 'yandex_delivery_production_platform_station_id';
	public const REQUEST_TIMEOUT_KEY = 'yandex_delivery_request_timeout';
	public const DEBUG_KEY = 'yandex_delivery_debug';
	public const LAST_CONNECTION_CHECK_KEY = 'yandex_delivery_last_connection_check';
	public const LAST_CONNECTION_STATUS_KEY = 'yandex_delivery_last_connection_status';
	public const LAST_CONNECTION_MESSAGE_KEY = 'yandex_delivery_last_connection_message';
	public const PICKUP_ACTION_RESULT_KEY = 'yandex_delivery_pickup_action_result';

	public function __construct(
		private SettingsRepository $settings,
		private EncryptionService $encryption,
		private ?DeliveryServiceRepository $services = null,
		private ?DeliveryServiceSettingsRepository $service_settings = null
	) {
	}

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			self::ENVIRONMENT_KEY => self::ENV_TEST,
			self::TEST_TOKEN_ENCRYPTED_KEY => '',
			self::PRODUCTION_TOKEN_ENCRYPTED_KEY => '',
			self::TEST_PLATFORM_STATION_ID_KEY => '',
			self::PRODUCTION_PLATFORM_STATION_ID_KEY => '',
			self::REQUEST_TIMEOUT_KEY => self::DEFAULT_REQUEST_TIMEOUT,
			self::DEBUG_KEY => false,
			self::LAST_CONNECTION_CHECK_KEY => '',
			self::LAST_CONNECTION_STATUS_KEY => '',
			self::LAST_CONNECTION_MESSAGE_KEY => '',
			self::PICKUP_ACTION_RESULT_KEY => array(),
			self::PICKUP_METHOD_TITLE_KEY => self::DEFAULT_PICKUP_METHOD_TITLE,
			self::COURIER_METHOD_TITLE_KEY => self::DEFAULT_COURIER_METHOD_TITLE,
		);
	}

	public function environment(): string {
		return self::ENV_PRODUCTION === $this->settings->get_string( self::ENVIRONMENT_KEY, self::ENV_TEST ) ? self::ENV_PRODUCTION : self::ENV_TEST;
	}

	public function environment_label(): string {
		return self::ENV_PRODUCTION === $this->environment() ? 'Рабочая' : 'Тестовая';
	}

	public function request_timeout(): int {
		return max( 1, min( 120, $this->settings->get_int( self::REQUEST_TIMEOUT_KEY, self::DEFAULT_REQUEST_TIMEOUT ) ) );
	}

	public function debug_enabled(): bool {
		return $this->settings->get_bool( self::DEBUG_KEY, false );
	}

	public function pickup_method_title(): string {
		return $this->service_method_title( self::PICKUP_METHOD_TITLE_KEY, self::DEFAULT_PICKUP_METHOD_TITLE );
	}

	public function courier_method_title(): string {
		return $this->service_method_title( self::COURIER_METHOD_TITLE_KEY, self::DEFAULT_COURIER_METHOD_TITLE );
	}

	/** @param array<string,mixed> $input */
	public function credentials(): YandexDeliveryCredentials {
		return $this->credentials_for_environment( $this->environment() );
	}

	public function credentials_for_environment( string $environment ): YandexDeliveryCredentials {
		$environment = self::ENV_PRODUCTION === $environment ? self::ENV_PRODUCTION : self::ENV_TEST;

		return new YandexDeliveryCredentials(
			$this->bearer_token( $environment ),
			$this->platform_station_id( $environment ),
			$environment
		);
	}

	public function credentials_are_complete(): bool {
		return $this->credentials()->is_complete();
	}

	public function has_bearer_token( string $environment ): bool {
		$key = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_TOKEN_ENCRYPTED_KEY : self::TEST_TOKEN_ENCRYPTED_KEY;

		return '' !== $this->settings->get_string( $key, '' );
	}

	public function last_connection_check(): string {
		return $this->settings->get_string( self::LAST_CONNECTION_CHECK_KEY, '' );
	}

	public function last_connection_status(): string {
		return $this->settings->get_string( self::LAST_CONNECTION_STATUS_KEY, '' );
	}

	public function last_connection_message(): string {
		return $this->settings->get_string( self::LAST_CONNECTION_MESSAGE_KEY, '' );
	}

	/** @return array<string,mixed> */
	public function get_pickup_action_result(): array {
		$value = $this->settings->get_array( self::PICKUP_ACTION_RESULT_KEY, array() );

		return is_array( $value ) ? $value : array();
	}

	/** @param array<string,mixed> $result */
	public function save_pickup_action_result( array $result ): void {
		$this->settings->set( self::PICKUP_ACTION_RESULT_KEY, $this->sanitize_report( $result ) );
	}

	public function clear_pickup_action_result(): void {
		$this->settings->set( self::PICKUP_ACTION_RESULT_KEY, array() );
	}

	/** @param array<string,mixed> $input */
	public function save_from_admin( array $input ): void {
		$environment = $this->sanitize_key( (string) ( $input[ self::ENVIRONMENT_KEY ] ?? self::ENV_TEST ) );
		if ( ! in_array( $environment, array( self::ENV_TEST, self::ENV_PRODUCTION ), true ) ) {
			$environment = self::ENV_TEST;
		}

		$this->settings->set( self::ENVIRONMENT_KEY, $environment );
		$this->save_credentials_for_environment( self::ENV_TEST, $input );
		$this->save_credentials_for_environment( self::ENV_PRODUCTION, $input );
		$this->settings->set( self::REQUEST_TIMEOUT_KEY, max( 1, min( 120, (int) ( $input[ self::REQUEST_TIMEOUT_KEY ] ?? self::DEFAULT_REQUEST_TIMEOUT ) ) ) );
		$this->settings->set( self::DEBUG_KEY, ! empty( $input[ self::DEBUG_KEY ] ) );
	}

	public function save_connection_result( bool $success, string $message ): void {
		$this->settings->set( self::LAST_CONNECTION_CHECK_KEY, $this->now() );
		$this->settings->set( self::LAST_CONNECTION_STATUS_KEY, $success ? 'success' : 'error' );
		$this->settings->set( self::LAST_CONNECTION_MESSAGE_KEY, $this->sanitize_message( 'Среда: ' . $this->environment_label() . '. ' . $this->redact( $message ) ) );
	}

	/** @return array<string,mixed> */
	public function diagnostic_context(): array {
		return array(
			'environment' => $this->environment(),
			'host' => YandexDeliveryEndpoints::host( $this->environment() ),
			'platform_station_id' => $this->credentials()->platform_station_id,
			'credentials_complete' => $this->credentials_are_complete(),
		);
	}

	public function redact( string $message ): string {
		foreach ( array( self::ENV_TEST, self::ENV_PRODUCTION ) as $environment ) {
			$token = $this->bearer_token( $environment );
			if ( '' !== $token ) {
				$message = str_replace( $token, '[redacted]', $message );
			}
		}
		$message = preg_replace( '/Bearer\s+[A-Za-z0-9._~+\-\/]+=*/i', 'Bearer [redacted]', $message ) ?? $message;
		$message = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $message ) ?? $message;
		$message = preg_replace( '/(?:\+?\d[\d\s().-]{8,}\d)/', '[redacted-phone]', $message ) ?? $message;
		$message = preg_replace( '/\b(?:address|адрес)[:\s]+[^.;,]+/iu', '[redacted-address]', $message ) ?? $message;

		return $message;
	}

	public function sanitize_for_diagnostics( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $item ) {
				$key_text = strtolower( str_replace( array( '-', ' ' ), '_', (string) $key ) );
				if ( str_contains( $key_text, 'token' ) || str_contains( $key_text, 'authorization' ) || str_contains( $key_text, 'phone' ) || str_contains( $key_text, 'email' ) ) {
					$sanitized[ $key ] = '[redacted]';
					continue;
				}
				if ( in_array( $key_text, array( 'address', 'full_address', 'formatted_address', 'location', 'comment' ), true ) ) {
					$sanitized[ $key ] = '[redacted-address]';
					continue;
				}
				$sanitized[ $key ] = $this->sanitize_for_diagnostics( $item );
			}

			return $sanitized;
		}

		if ( is_string( $value ) ) {
			$value = $this->redact( $value );
			return strlen( $value ) > 1000 ? substr( $value, 0, 1000 ) . '...' : $value;
		}

		return $value;
	}

	private function service_method_title( string $key, string $default ): string {
		$service = $this->services instanceof DeliveryServiceRepository ? $this->services->find_by_service_key( self::SERVICE_KEY ) : null;
		if ( ! $service instanceof DeliveryService || null === $service->id || ! $this->service_settings instanceof DeliveryServiceSettingsRepository ) {
			$title = trim( $this->settings->get_string( $key, $default ) );

			return '' !== $title ? $title : $default;
		}
		$title = trim( (string) $this->service_settings->get_setting( (int) $service->id, $key, $default ) );

		return '' !== $title ? $title : $default;
	}

	private function bearer_token( string $environment ): string {
		$key = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_TOKEN_ENCRYPTED_KEY : self::TEST_TOKEN_ENCRYPTED_KEY;
		$encrypted = $this->settings->get_string( $key, '' );
		if ( '' === $encrypted ) {
			return '';
		}

		return (string) ( $this->encryption->decrypt( $encrypted ) ?? '' );
	}

	private function platform_station_id( string $environment ): string {
		$key = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_PLATFORM_STATION_ID_KEY : self::TEST_PLATFORM_STATION_ID_KEY;

		return $this->sanitize_station_id( $this->settings->get_string( $key, '' ) );
	}

	/** @param array<string,mixed> $input */
	private function save_credentials_for_environment( string $environment, array $input ): void {
		$station_key = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_PLATFORM_STATION_ID_KEY : self::TEST_PLATFORM_STATION_ID_KEY;
		$token_storage_key = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_TOKEN_ENCRYPTED_KEY : self::TEST_TOKEN_ENCRYPTED_KEY;
		$token_input_key = self::ENV_PRODUCTION === $environment ? 'yandex_delivery_production_bearer_token' : 'yandex_delivery_test_bearer_token';
		$clear_input_key = self::ENV_PRODUCTION === $environment ? 'yandex_delivery_clear_production_bearer_token' : 'yandex_delivery_clear_test_bearer_token';

		$this->settings->set( $station_key, $this->sanitize_station_id( (string) ( $input[ $station_key ] ?? '' ) ) );
		if ( ! empty( $input[ $clear_input_key ] ) ) {
			$this->settings->set( $token_storage_key, '' );
			return;
		}

		$token = trim( $this->unslash( (string) ( $input[ $token_input_key ] ?? '' ) ) );
		if ( '' !== $token && '********' !== $token ) {
			$this->settings->set( $token_storage_key, $this->encryption->encrypt( $token ) );
		}
	}

	private function sanitize_station_id( string $value ): string {
		return substr( preg_replace( '/[^A-Za-z0-9_-]+/', '', trim( $this->unslash( $value ) ) ) ?? '', 0, 80 );
	}

	private function sanitize_message( string $value ): string {
		return substr( $this->sanitize_text( $value ), 0, 500 );
	}

	/** @param array<string,mixed> $report @return array<string,mixed> */
	private function sanitize_report( array $report ): array {
		$sanitized = array();
		foreach ( $report as $key => $value ) {
			$key = $this->sanitize_key( (string) $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = array_map(
					fn( mixed $item ): string => substr( $this->sanitize_text( is_scalar( $item ) ? (string) $item : $this->json( $item ) ), 0, 500 ),
					$value
				);
				continue;
			}
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$sanitized[ $key ] = $value;
				continue;
			}
			$sanitized[ $key ] = substr( $this->sanitize_text( (string) $value ), 0, 500 );
		}

		return $sanitized;
	}

	private function json( mixed $value ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : '';
	}

	private function sanitize_text( string $value ): string {
		$value = $this->unslash( $value );
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
	}

	private function sanitize_key( string $value ): string {
		$value = $this->unslash( $value );
		return function_exists( 'sanitize_key' ) ? sanitize_key( $value ) : strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ?? '' );
	}

	private function unslash( string $value ): string {
		return function_exists( 'wp_unslash' ) ? (string) wp_unslash( $value ) : $value;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
