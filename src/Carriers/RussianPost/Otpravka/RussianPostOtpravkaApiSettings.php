<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost\Otpravka;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class RussianPostOtpravkaApiSettings {
	public const ACCESS_TOKEN_KEY = 'russian_post_otpravka_access_token';
	public const LOGIN_KEY = 'russian_post_otpravka_login';
	public const PASSWORD_ENCRYPTED_KEY = 'russian_post_otpravka_password_encrypted';
	public const TIMEOUT_KEY = 'russian_post_otpravka_timeout';
	public const PICKUP_UNLOAD_TYPE_KEY = 'russian_post_pickup_unload_type';
	public const PICKUP_SCHEDULE_ENABLED_KEY = 'russian_post_pickup_schedule_enabled';
	public const PICKUP_LAST_IMPORT_RESULT_KEY = 'russian_post_pickup_last_import_result';
	public const PICKUP_LAST_SUCCESS_AT_KEY = 'russian_post_pickup_last_success_at';

	public function __construct(
		private SettingsRepository $settings,
		private EncryptionService $encryption
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function values(): array {
		$values = $this->settings->all();

		return array_merge(
			array(
				self::ACCESS_TOKEN_KEY => '',
				self::LOGIN_KEY => '',
				self::PASSWORD_ENCRYPTED_KEY => '',
				self::TIMEOUT_KEY => 120,
				self::PICKUP_UNLOAD_TYPE_KEY => 'ALL',
				self::PICKUP_SCHEDULE_ENABLED_KEY => false,
				self::PICKUP_LAST_IMPORT_RESULT_KEY => array(),
				self::PICKUP_LAST_SUCCESS_AT_KEY => '',
			),
			$values
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
		$values[ self::LOGIN_KEY ] = sanitize_text_field( wp_unslash( $input['russian_post_otpravka_login'] ?? '' ) );
		$values[ self::TIMEOUT_KEY ] = max( 30, min( 300, (int) ( $input['russian_post_otpravka_timeout'] ?? 120 ) ) );
		$type = strtoupper( sanitize_key( wp_unslash( $input['russian_post_pickup_unload_type'] ?? 'ALL' ) ) );
		$values[ self::PICKUP_UNLOAD_TYPE_KEY ] = in_array( $type, array( 'ALL', 'OPS', 'PVZ', 'APS' ), true ) ? $type : 'ALL';
		$values[ self::PICKUP_SCHEDULE_ENABLED_KEY ] = ! empty( $input['russian_post_pickup_schedule_enabled'] );

		if ( ! empty( $input['russian_post_otpravka_clear_password'] ) ) {
			$values[ self::PASSWORD_ENCRYPTED_KEY ] = '';
		}
		$password = trim( (string) wp_unslash( $input['russian_post_otpravka_password'] ?? '' ) );
		if ( '' !== $password ) {
			$values[ self::PASSWORD_ENCRYPTED_KEY ] = $this->encryption->encrypt( $password );
		}

		$this->settings->replace( $values );
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

		$this->settings->replace( $values );
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
}
