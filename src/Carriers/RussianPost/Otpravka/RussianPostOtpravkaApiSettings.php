<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost\Otpravka;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class RussianPostOtpravkaApiSettings {
	public const ACCESS_TOKEN_KEY = 'russian_post_otpravka_access_token';
	public const LOGIN_KEY = 'russian_post_otpravka_login';
	public const PASSWORD_ENCRYPTED_KEY = 'russian_post_otpravka_password_encrypted';
	public const TIMEOUT_KEY = 'russian_post_otpravka_timeout';
	public const POSTOFFICE_CODES_KEY = 'russian_post_otpravka_postoffice_codes';
	public const TRACKING_LOGIN_KEY = 'russian_post_tracking_login';
	public const TRACKING_PASSWORD_ENCRYPTED_KEY = 'russian_post_tracking_password_encrypted';
	public const PICKUP_UNLOAD_TYPE_KEY = 'russian_post_pickup_unload_type';
	public const PICKUP_SCHEDULE_ENABLED_KEY = 'russian_post_pickup_schedule_enabled';
	public const PICKUP_LAST_IMPORT_RESULT_KEY = 'russian_post_pickup_last_import_result';
	public const PICKUP_LAST_SUCCESS_AT_KEY = 'russian_post_pickup_last_success_at';

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
	public function values(): array {
		return array_merge(
			array(
				self::ACCESS_TOKEN_KEY => '',
				self::LOGIN_KEY => '',
				self::PASSWORD_ENCRYPTED_KEY => '',
				self::TIMEOUT_KEY => 120,
				self::POSTOFFICE_CODES_KEY => array( '630005' ),
				self::TRACKING_LOGIN_KEY => '',
				self::TRACKING_PASSWORD_ENCRYPTED_KEY => '',
				self::PICKUP_UNLOAD_TYPE_KEY => 'ALL',
				self::PICKUP_SCHEDULE_ENABLED_KEY => false,
				self::PICKUP_LAST_IMPORT_RESULT_KEY => array(),
				self::PICKUP_LAST_SUCCESS_AT_KEY => '',
			),
			$this->stored_values()
		);
	}

	public function access_token(): string {
		return trim( (string) ( $this->values()[ self::ACCESS_TOKEN_KEY ] ?? '' ) );
	}

	public function login(): string {
		return trim( (string) ( $this->values()[ self::LOGIN_KEY ] ?? '' ) );
	}

	public function password(): string {
		return $this->decrypt_secret( self::PASSWORD_ENCRYPTED_KEY );
	}

	public function basic_key(): string {
		$login    = $this->login();
		$password = $this->password();

		return '' !== $login && '' !== $password ? base64_encode( $login . ':' . $password ) : '';
	}

	public function timeout(): int {
		return max( 30, min( 300, (int) ( $this->values()[ self::TIMEOUT_KEY ] ?? 120 ) ) );
	}

	/**
	 * @return array<int,string>
	 */
	public function postoffice_codes(): array {
		$raw = $this->values()[ self::POSTOFFICE_CODES_KEY ] ?? array( '630005' );
		$codes = is_array( $raw ) ? $raw : preg_split( '/[\s,;]+/', (string) $raw );
		$codes = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn ( mixed $value ): string => preg_replace( '/\D+/', '', (string) $value ) ?? '',
						is_array( $codes ) ? $codes : array()
					),
					static fn ( string $code ): bool => 1 === preg_match( '/^\d{6}$/', $code )
				)
			)
		);

		return array() !== $codes ? $codes : array( '630005' );
	}

	public function unload_type(): string {
		$type = strtoupper( trim( (string) ( $this->values()[ self::PICKUP_UNLOAD_TYPE_KEY ] ?? 'ALL' ) ) );

		return in_array( $type, array( 'ALL', 'OPS', 'PVZ', 'APS' ), true ) ? $type : 'ALL';
	}

	public function schedule_enabled(): bool {
		return ! empty( $this->values()[ self::PICKUP_SCHEDULE_ENABLED_KEY ] );
	}

	public function has_password(): bool {
		return '' !== (string) ( $this->values()[ self::PASSWORD_ENCRYPTED_KEY ] ?? '' );
	}

	public function has_tracking_password(): bool {
		return '' !== (string) ( $this->values()[ self::TRACKING_PASSWORD_ENCRYPTED_KEY ] ?? '' );
	}

	public function tracking_login(): string {
		return trim( (string) ( $this->values()[ self::TRACKING_LOGIN_KEY ] ?? '' ) );
	}

	public function tracking_password(): string {
		return $this->decrypt_secret( self::TRACKING_PASSWORD_ENCRYPTED_KEY );
	}

	public function has_access_token(): bool {
		return '' !== $this->access_token();
	}

	public function encryption_ready(): bool {
		return $this->encryption->has_configured_key() || true;
	}

	/**
	 * @param array<string,mixed> $input
	 */
	public function save_from_admin( array $input ): void {
		$values = $this->values();
		$access_token = sanitize_text_field( wp_unslash( $input['russian_post_otpravka_access_token'] ?? '' ) );
		if ( '' !== $access_token ) {
			$values[ self::ACCESS_TOKEN_KEY ] = $access_token;
		}
		if ( ! empty( $input['russian_post_otpravka_clear_access_token'] ) ) {
			$values[ self::ACCESS_TOKEN_KEY ] = '';
		}
		if ( array_key_exists( 'russian_post_otpravka_login', $input ) ) {
			$values[ self::LOGIN_KEY ] = sanitize_text_field( wp_unslash( $input['russian_post_otpravka_login'] ?? '' ) );
		}
		if ( array_key_exists( 'russian_post_otpravka_timeout', $input ) ) {
			$values[ self::TIMEOUT_KEY ] = max( 30, min( 300, (int) ( $input['russian_post_otpravka_timeout'] ?? 120 ) ) );
		}
		if ( array_key_exists( self::TRACKING_LOGIN_KEY, $input ) ) {
			$values[ self::TRACKING_LOGIN_KEY ] = sanitize_text_field( wp_unslash( $input[ self::TRACKING_LOGIN_KEY ] ?? '' ) );
		}
		if ( array_key_exists( self::POSTOFFICE_CODES_KEY, $input ) ) {
			$raw_codes = is_array( $input[ self::POSTOFFICE_CODES_KEY ] ) ? wp_unslash( $input[ self::POSTOFFICE_CODES_KEY ] ) : preg_split( '/[\s,;]+/', (string) wp_unslash( $input[ self::POSTOFFICE_CODES_KEY ] ) );
			$codes = array_values(
				array_unique(
					array_filter(
						array_map(
							static fn ( mixed $value ): string => preg_replace( '/\D+/', '', (string) $value ) ?? '',
							is_array( $raw_codes ) ? $raw_codes : array()
						),
						static fn ( string $code ): bool => 1 === preg_match( '/^\d{6}$/', $code )
					)
				)
			);
			$values[ self::POSTOFFICE_CODES_KEY ] = array() !== $codes ? $codes : array( '630005' );
		}
		if ( array_key_exists( 'russian_post_pickup_unload_type', $input ) ) {
			$type = strtoupper( sanitize_key( wp_unslash( $input['russian_post_pickup_unload_type'] ?? 'ALL' ) ) );
			$values[ self::PICKUP_UNLOAD_TYPE_KEY ] = in_array( $type, array( 'ALL', 'OPS', 'PVZ', 'APS' ), true ) ? $type : 'ALL';
		}
		if ( array_key_exists( 'russian_post_pickup_schedule_enabled', $input ) || 'save_russian_post_pickup' === (string) ( $input['wdc_delivery_services_action'] ?? '' ) ) {
			$values[ self::PICKUP_SCHEDULE_ENABLED_KEY ] = ! empty( $input['russian_post_pickup_schedule_enabled'] );
		}

		if ( ! empty( $input['russian_post_otpravka_clear_password'] ) ) {
			$values[ self::PASSWORD_ENCRYPTED_KEY ] = '';
		}
		if ( ! empty( $input['russian_post_tracking_clear_password'] ) ) {
			$values[ self::TRACKING_PASSWORD_ENCRYPTED_KEY ] = '';
		}
		$password = trim( (string) wp_unslash( $input['russian_post_otpravka_password'] ?? '' ) );
		if ( '' !== $password ) {
			$values[ self::PASSWORD_ENCRYPTED_KEY ] = $this->encryption->encrypt( $password );
		}
		$tracking_password = trim( (string) wp_unslash( $input['russian_post_tracking_password'] ?? '' ) );
		if ( '' !== $tracking_password ) {
			$values[ self::TRACKING_PASSWORD_ENCRYPTED_KEY ] = $this->encryption->encrypt( $tracking_password );
		}

		$this->replace_values( $values );
	}

	/**
	 * @param array<string,mixed> $result
	 */
	public function save_import_result( array $result, bool $success ): void {
		$values = $this->values();
		$values[ self::PICKUP_LAST_IMPORT_RESULT_KEY ] = $result;
		if ( $success ) {
			$values[ self::PICKUP_LAST_SUCCESS_AT_KEY ] = (string) ( $result['finished_at'] ?? ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) ) );
		}

		$this->replace_values( $values );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function last_import_result(): array {
		$result = $this->values()[ self::PICKUP_LAST_IMPORT_RESULT_KEY ] ?? array();

		return is_array( $result ) ? $result : array();
	}

	public function last_success_at(): string {
		return (string) ( $this->values()[ self::PICKUP_LAST_SUCCESS_AT_KEY ] ?? '' );
	}

	private function decrypt_secret( string $key ): string {
		$encrypted = (string) ( $this->values()[ $key ] ?? '' );
		if ( '' === $encrypted ) {
			return '';
		}

		return (string) ( $this->encryption->decrypt( $encrypted ) ?? '' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function stored_values(): array {
		$service = $this->service();
		if ( $service instanceof DeliveryService && null !== $service->id && $this->service_settings instanceof DeliveryServiceSettingsRepository ) {
			return $this->service_settings->all_settings( (int) $service->id );
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $values
	 */
	private function replace_values( array $values ): void {
		$service = $this->service();
		if ( $service instanceof DeliveryService && null !== $service->id && $this->service_settings instanceof DeliveryServiceSettingsRepository ) {
			foreach ( $this->setting_formats() as $key => $format ) {
				if ( array_key_exists( $key, $values ) ) {
					$this->service_settings->set_setting( (int) $service->id, $key, $values[ $key ], $format );
				}
			}
		}
	}

	private function service(): ?DeliveryService {
		return $this->services instanceof DeliveryServiceRepository
			? $this->services->find_by_service_key( RussianPostDomesticSettings::SERVICE_KEY )
			: null;
	}

	/**
	 * @return array<string,string>
	 */
	private function setting_formats(): array {
		return array(
			self::ACCESS_TOKEN_KEY => 'string',
			self::LOGIN_KEY => 'string',
			self::PASSWORD_ENCRYPTED_KEY => 'string',
			self::TIMEOUT_KEY => 'number',
			self::POSTOFFICE_CODES_KEY => 'json',
			self::TRACKING_LOGIN_KEY => 'string',
			self::TRACKING_PASSWORD_ENCRYPTED_KEY => 'string',
			self::PICKUP_UNLOAD_TYPE_KEY => 'string',
			self::PICKUP_SCHEDULE_ENABLED_KEY => 'bool',
			self::PICKUP_LAST_IMPORT_RESULT_KEY => 'json',
			self::PICKUP_LAST_SUCCESS_AT_KEY => 'string',
		);
	}
}
