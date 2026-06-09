<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Cdek;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class CdekSettings {
	public const SERVICE_KEY = 'cdek';
	public const CARRIER_KEY = 'cdek';
	public const TITLE = 'СДЭК';
	public const ENV_TEST = 'test';
	public const ENV_PRODUCTION = 'production';

	public const ENABLED_KEY = 'cdek_enabled';
	public const ENVIRONMENT_KEY = 'cdek_environment';
	public const ACCOUNT_KEY = 'cdek_account';
	public const SECURE_PASSWORD_ENCRYPTED_KEY = 'cdek_secure_password_encrypted';
	public const LAST_CONNECTION_CHECK_KEY = 'cdek_last_connection_check';
	public const LAST_CONNECTION_STATUS_KEY = 'cdek_last_connection_status';
	public const LAST_CONNECTION_MESSAGE_KEY = 'cdek_last_connection_message';

	public function __construct(
		private SettingsRepository $settings,
		private EncryptionService $encryption
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function defaults(): array {
		return array(
			self::ENABLED_KEY => false,
			self::ENVIRONMENT_KEY => self::ENV_TEST,
			self::ACCOUNT_KEY => '',
			self::SECURE_PASSWORD_ENCRYPTED_KEY => '',
			self::LAST_CONNECTION_CHECK_KEY => '',
			self::LAST_CONNECTION_STATUS_KEY => '',
			self::LAST_CONNECTION_MESSAGE_KEY => '',
		);
	}

	public function enabled(): bool {
		return $this->settings->get_bool( self::ENABLED_KEY, false );
	}

	public function environment(): string {
		$environment = $this->settings->get_string( self::ENVIRONMENT_KEY, self::ENV_TEST );

		return in_array( $environment, array( self::ENV_TEST, self::ENV_PRODUCTION ), true ) ? $environment : self::ENV_TEST;
	}

	public function base_url(): string {
		return self::ENV_PRODUCTION === $this->environment() ? 'https://api.cdek.ru' : 'https://api.edu.cdek.ru';
	}

	public function credentials(): CdekCredentials {
		return new CdekCredentials(
			trim( $this->settings->get_string( self::ACCOUNT_KEY, '' ) ),
			$this->secure_password()
		);
	}

	public function has_secure_password(): bool {
		return '' !== $this->settings->get_string( self::SECURE_PASSWORD_ENCRYPTED_KEY, '' );
	}

	public function credentials_are_complete(): bool {
		return $this->credentials()->is_complete();
	}

	public function token_cache_key(): string {
		$credentials = $this->credentials();
		$hash = hash( 'sha256', $this->environment() . '|' . $credentials->account );

		return 'wdc_cdek_oauth_' . substr( $hash, 0, 32 );
	}

	/**
	 * @param array<string,mixed> $input
	 */
	public function save_from_admin( array $input ): void {
		$environment = sanitize_key( wp_unslash( $input[ self::ENVIRONMENT_KEY ] ?? self::ENV_TEST ) );
		if ( ! in_array( $environment, array( self::ENV_TEST, self::ENV_PRODUCTION ), true ) ) {
			$environment = self::ENV_TEST;
		}

		$this->settings->set( self::ENABLED_KEY, ! empty( $input[ self::ENABLED_KEY ] ) );
		$this->settings->set( self::ENVIRONMENT_KEY, $environment );
		$this->settings->set( self::ACCOUNT_KEY, sanitize_text_field( wp_unslash( $input[ self::ACCOUNT_KEY ] ?? '' ) ) );

		if ( ! empty( $input['cdek_clear_secure_password'] ) ) {
			$this->settings->set( self::SECURE_PASSWORD_ENCRYPTED_KEY, '' );
			return;
		}

		$password = trim( (string) wp_unslash( $input['cdek_secure_password'] ?? '' ) );
		if ( '' !== $password && '********' !== $password ) {
			$this->settings->set( self::SECURE_PASSWORD_ENCRYPTED_KEY, $this->encryption->encrypt( $password ) );
		}
	}

	public function save_connection_result( bool $success, string $message ): void {
		$this->settings->set( self::LAST_CONNECTION_CHECK_KEY, function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) );
		$this->settings->set( self::LAST_CONNECTION_STATUS_KEY, $success ? 'success' : 'error' );
		$this->settings->set( self::LAST_CONNECTION_MESSAGE_KEY, $this->redact( $message ) );
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

	private function secure_password(): string {
		$encrypted = $this->settings->get_string( self::SECURE_PASSWORD_ENCRYPTED_KEY, '' );
		if ( '' === $encrypted ) {
			return '';
		}

		return (string) ( $this->encryption->decrypt( $encrypted ) ?? '' );
	}

	private function redact( string $message ): string {
		$credentials = $this->credentials();
		foreach ( array( $credentials->account, $credentials->secure_password ) as $secret ) {
			if ( '' !== $secret ) {
				$message = str_replace( $secret, '[redacted]', $message );
			}
		}
		$message = preg_replace( '/\b(?:bearer\s+)?[A-Za-z0-9._\-]*token[A-Za-z0-9._\-]*\b/i', '[redacted]', $message ) ?? $message;

		return $message;
	}
}
