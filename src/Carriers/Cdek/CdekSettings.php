<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Cdek;

use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class CdekSettings {
	public const SERVICE_KEY = 'cdek';
	public const CARRIER_KEY = 'cdek';
	public const TITLE = 'СДЭК';
	public const DEFAULT_PICKUP_METHOD_TITLE = 'СДЭК до пункта выдачи';
	public const DEFAULT_COURIER_METHOD_TITLE = 'СДЭК курьер';
	public const ENV_TEST = 'test';
	public const ENV_PRODUCTION = 'production';

	public const ENVIRONMENT_KEY = 'cdek_environment';
	public const TEST_ACCOUNT_KEY = 'cdek_test_account';
	public const TEST_SECURE_PASSWORD_ENCRYPTED_KEY = 'cdek_test_secure_password_encrypted';
	public const PRODUCTION_ACCOUNT_KEY = 'cdek_production_account';
	public const PRODUCTION_SECURE_PASSWORD_ENCRYPTED_KEY = 'cdek_production_secure_password_encrypted';
	public const LEGACY_ACCOUNT_KEY = 'cdek_account';
	public const LEGACY_SECURE_PASSWORD_ENCRYPTED_KEY = 'cdek_secure_password_encrypted';
	public const LAST_CONNECTION_CHECK_KEY = 'cdek_last_connection_check';
	public const LAST_CONNECTION_STATUS_KEY = 'cdek_last_connection_status';
	public const LAST_CONNECTION_MESSAGE_KEY = 'cdek_last_connection_message';
	public const SENDER_CITY_CODE_KEY = 'cdek_sender_city_code';
	public const SENDER_POSTAL_CODE_KEY = 'cdek_sender_postal_code';
	public const SENDER_CITY_NAME_KEY = 'cdek_sender_city_name';
	public const SHIPMENT_POINT_KEY = 'cdek_shipment_point';
	public const DEFAULT_PACKAGE_LENGTH_CM_KEY = 'cdek_default_package_length_cm';
	public const DEFAULT_PACKAGE_WIDTH_CM_KEY = 'cdek_default_package_width_cm';
	public const DEFAULT_PACKAGE_HEIGHT_CM_KEY = 'cdek_default_package_height_cm';

	public function __construct(
		private SettingsRepository $settings,
		private EncryptionService $encryption,
		private ?DeliveryServiceRepository $services = null,
		private ?DeliveryServiceSettingsRepository $service_settings = null
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			self::ENVIRONMENT_KEY => self::ENV_TEST,
			self::TEST_ACCOUNT_KEY => '',
			self::TEST_SECURE_PASSWORD_ENCRYPTED_KEY => '',
			self::PRODUCTION_ACCOUNT_KEY => '',
			self::PRODUCTION_SECURE_PASSWORD_ENCRYPTED_KEY => '',
			self::LAST_CONNECTION_CHECK_KEY => '',
			self::LAST_CONNECTION_STATUS_KEY => '',
			self::LAST_CONNECTION_MESSAGE_KEY => '',
			self::SENDER_CITY_CODE_KEY => '',
			self::SENDER_POSTAL_CODE_KEY => '',
			self::SENDER_CITY_NAME_KEY => '',
			self::SHIPMENT_POINT_KEY => 'NSK69',
			self::DEFAULT_PACKAGE_LENGTH_CM_KEY => 20,
			self::DEFAULT_PACKAGE_WIDTH_CM_KEY => 20,
			self::DEFAULT_PACKAGE_HEIGHT_CM_KEY => 10,
		);
	}

	public function environment(): string {
		$environment = $this->settings->get_string( self::ENVIRONMENT_KEY, self::ENV_TEST );

		return in_array( $environment, array( self::ENV_TEST, self::ENV_PRODUCTION ), true ) ? $environment : self::ENV_TEST;
	}

	public function base_url(): string {
		return self::ENV_PRODUCTION === $this->environment() ? 'https://api.cdek.ru' : 'https://api.edu.cdek.ru';
	}

	public function credentials(): CdekCredentials {
		return $this->credentials_for_environment( $this->environment() );
	}

	public function credentials_for_environment( string $environment ): CdekCredentials {
		$environment = $this->normalize_environment( $environment );
		$account_key = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_ACCOUNT_KEY : self::TEST_ACCOUNT_KEY;

		return new CdekCredentials(
			$this->account( $environment, $account_key ),
			$this->secure_password( $environment ),
			$environment
		);
	}

	public function has_secure_password( string $environment ): bool {
		$key = self::ENV_PRODUCTION === $this->normalize_environment( $environment )
			? self::PRODUCTION_SECURE_PASSWORD_ENCRYPTED_KEY
			: self::TEST_SECURE_PASSWORD_ENCRYPTED_KEY;

		if ( '' !== $this->settings->get_string( $key, '' ) ) {
			return true;
		}

		return self::ENV_TEST === $this->normalize_environment( $environment )
			&& '' !== $this->settings->get_string( self::LEGACY_SECURE_PASSWORD_ENCRYPTED_KEY, '' );
	}

	public function credentials_are_complete(): bool {
		return $this->credentials()->is_complete();
	}

	public function sender_city_code(): int {
		return max( 0, $this->settings->get_int( self::SENDER_CITY_CODE_KEY, 0 ) );
	}

	public function sender_postal_code(): string {
		return $this->valid_postal_code( $this->settings->get_string( self::SENDER_POSTAL_CODE_KEY, '' ) );
	}

	public function sender_city_name(): string {
		return trim( $this->settings->get_string( self::SENDER_CITY_NAME_KEY, '' ) );
	}

	public function shipment_point(): string {
		$point = $this->normalize_shipment_point( $this->settings->get_string( self::SHIPMENT_POINT_KEY, 'NSK69' ) );

		return '' !== $point ? $point : 'NSK69';
	}

	/**
	 * @return array{length:int,width:int,height:int}
	 */
	public function default_package_dimensions_cm(): array {
		return array(
			'length' => max( 1, $this->settings->get_int( self::DEFAULT_PACKAGE_LENGTH_CM_KEY, 20 ) ),
			'width' => max( 1, $this->settings->get_int( self::DEFAULT_PACKAGE_WIDTH_CM_KEY, 20 ) ),
			'height' => max( 1, $this->settings->get_int( self::DEFAULT_PACKAGE_HEIGHT_CM_KEY, 10 ) ),
		);
	}

	public function pickup_method_title(): string {
		return $this->service_method_title( 'pickup_method_title', self::DEFAULT_PICKUP_METHOD_TITLE );
	}

	public function courier_method_title(): string {
		return $this->service_method_title( 'courier_method_title', self::DEFAULT_COURIER_METHOD_TITLE );
	}

	public function method_title( string $delivery_type ): string {
		return DeliveryType::COURIER === $delivery_type ? $this->courier_method_title() : $this->pickup_method_title();
	}

	public function token_cache_key(): string {
		return $this->token_cache_key_for_environment( $this->environment() );
	}

	public function token_cache_key_for_environment( string $environment ): string {
		$environment = $this->normalize_environment( $environment );
		$credentials = $this->credentials_for_environment( $environment );
		$hash = hash( 'sha256', $environment . '|' . $credentials->account );

		return 'wdc_cdek_oauth_' . substr( $hash, 0, 32 );
	}

	public function environment_label(): string {
		return self::ENV_PRODUCTION === $this->environment() ? 'Рабочая' : 'Тестовая';
	}

	/**
	 * @param array<string,mixed> $input
	 */
	public function save_from_admin( array $input ): void {
		$environment = sanitize_key( wp_unslash( $input[ self::ENVIRONMENT_KEY ] ?? self::ENV_TEST ) );
		if ( ! in_array( $environment, array( self::ENV_TEST, self::ENV_PRODUCTION ), true ) ) {
			$environment = self::ENV_TEST;
		}

		$this->settings->set( self::ENVIRONMENT_KEY, $environment );
		$this->save_credentials_for_environment( self::ENV_TEST, $input );
		$this->save_credentials_for_environment( self::ENV_PRODUCTION, $input );
		$this->settings->set( self::SENDER_CITY_CODE_KEY, max( 0, (int) ( $input[ self::SENDER_CITY_CODE_KEY ] ?? 0 ) ) );
		$this->settings->set( self::SENDER_POSTAL_CODE_KEY, $this->valid_postal_code( (string) wp_unslash( $input[ self::SENDER_POSTAL_CODE_KEY ] ?? '' ) ) );
		$this->settings->set( self::SENDER_CITY_NAME_KEY, sanitize_text_field( wp_unslash( $input[ self::SENDER_CITY_NAME_KEY ] ?? '' ) ) );
		$this->settings->set( self::SHIPMENT_POINT_KEY, $this->normalize_shipment_point( (string) wp_unslash( $input[ self::SHIPMENT_POINT_KEY ] ?? 'NSK69' ) ) );
		$this->settings->set( self::DEFAULT_PACKAGE_LENGTH_CM_KEY, max( 1, (int) ( $input[ self::DEFAULT_PACKAGE_LENGTH_CM_KEY ] ?? 20 ) ) );
		$this->settings->set( self::DEFAULT_PACKAGE_WIDTH_CM_KEY, max( 1, (int) ( $input[ self::DEFAULT_PACKAGE_WIDTH_CM_KEY ] ?? 20 ) ) );
		$this->settings->set( self::DEFAULT_PACKAGE_HEIGHT_CM_KEY, max( 1, (int) ( $input[ self::DEFAULT_PACKAGE_HEIGHT_CM_KEY ] ?? 10 ) ) );
	}

	public function save_connection_result( bool $success, string $message ): void {
		$this->settings->set( self::LAST_CONNECTION_CHECK_KEY, function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) );
		$this->settings->set( self::LAST_CONNECTION_STATUS_KEY, $success ? 'success' : 'error' );
		$this->settings->set( self::LAST_CONNECTION_MESSAGE_KEY, $this->redact( 'Среда: ' . $this->environment_label() . '. ' . $message ) );
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

	private function account( string $environment, string $key ): string {
		$account = trim( $this->settings->get_string( $key, '' ) );
		if ( '' === $account && self::ENV_TEST === $environment ) {
			return trim( $this->settings->get_string( self::LEGACY_ACCOUNT_KEY, '' ) );
		}

		return $account;
	}

	private function secure_password( string $environment ): string {
		$key = self::ENV_PRODUCTION === $this->normalize_environment( $environment )
			? self::PRODUCTION_SECURE_PASSWORD_ENCRYPTED_KEY
			: self::TEST_SECURE_PASSWORD_ENCRYPTED_KEY;
		$encrypted = $this->settings->get_string( $key, '' );
		if ( '' === $encrypted && self::ENV_TEST === $environment ) {
			$encrypted = $this->settings->get_string( self::LEGACY_SECURE_PASSWORD_ENCRYPTED_KEY, '' );
		}
		if ( '' === $encrypted ) {
			return '';
		}

		return (string) ( $this->encryption->decrypt( $encrypted ) ?? '' );
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private function save_credentials_for_environment( string $environment, array $input ): void {
		$account_key = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_ACCOUNT_KEY : self::TEST_ACCOUNT_KEY;
		$password_key = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_SECURE_PASSWORD_ENCRYPTED_KEY : self::TEST_SECURE_PASSWORD_ENCRYPTED_KEY;
		$password_input = self::ENV_PRODUCTION === $environment ? 'cdek_production_secure_password' : 'cdek_test_secure_password';
		$clear_input = self::ENV_PRODUCTION === $environment ? 'cdek_clear_production_secure_password' : 'cdek_clear_test_secure_password';

		$this->settings->set( $account_key, sanitize_text_field( wp_unslash( $input[ $account_key ] ?? '' ) ) );
		if ( ! empty( $input[ $clear_input ] ) ) {
			$this->settings->set( $password_key, '' );
			return;
		}

		$password = trim( (string) wp_unslash( $input[ $password_input ] ?? '' ) );
		if ( '' !== $password && '********' !== $password ) {
			$this->settings->set( $password_key, $this->encryption->encrypt( $password ) );
		}
	}

	private function redact( string $message ): string {
		foreach ( array( self::ENV_TEST, self::ENV_PRODUCTION ) as $environment ) {
			$credentials = $this->credentials_for_environment( $environment );
			foreach ( array( $credentials->account, $credentials->secure_password ) as $secret ) {
				if ( '' !== $secret ) {
					$message = str_replace( $secret, '[redacted]', $message );
				}
			}
		}
		$message = preg_replace( '/\b(?:bearer\s+)?[A-Za-z0-9._\-]*token[A-Za-z0-9._\-]*\b/i', '[redacted]', $message ) ?? $message;

		return $message;
	}

	private function normalize_shipment_point( string $value ): string {
		$value = strtoupper( trim( $value ) );
		$value = preg_replace( '/[^A-Z0-9_\-]/', '', $value ) ?? '';

		return $value;
	}

	private function normalize_environment( string $environment ): string {
		return self::ENV_PRODUCTION === $environment ? self::ENV_PRODUCTION : self::ENV_TEST;
	}

	private function valid_postal_code( string $postal_code ): string {
		$postal_code = preg_replace( '/\D+/', '', $postal_code ) ?? '';

		return preg_match( '/^\d{6}$/', $postal_code ) ? $postal_code : '';
	}

	private function service_method_title( string $key, string $default ): string {
		$service = $this->services instanceof DeliveryServiceRepository ? $this->services->find_by_service_key( self::SERVICE_KEY ) : null;
		if ( ! $service instanceof DeliveryService || null === $service->id || ! $this->service_settings instanceof DeliveryServiceSettingsRepository ) {
			return $default;
		}
		$title = trim( (string) $this->service_settings->get_setting( (int) $service->id, $key, $default ) );

		return '' !== $title ? $title : $default;
	}
}
