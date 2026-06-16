<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class DpdSettings {
	public const SERVICE_KEY = 'dpd';
	public const CARRIER_KEY = 'dpd';
	public const TITLE = 'DPD';
	public const ENV_TEST = 'test';
	public const ENV_PRODUCTION = 'production';
	public const DEFAULT_REQUEST_TIMEOUT = 20;

	public const ENVIRONMENT_KEY = 'dpd_environment';
	public const TEST_CLIENT_NUMBER_KEY = 'dpd_test_client_number';
	public const TEST_CLIENT_KEY_ENCRYPTED_KEY = 'dpd_test_client_key_encrypted';
	public const PRODUCTION_CLIENT_NUMBER_KEY = 'dpd_production_client_number';
	public const PRODUCTION_CLIENT_KEY_ENCRYPTED_KEY = 'dpd_production_client_key_encrypted';
	public const REQUEST_TIMEOUT_KEY = 'dpd_request_timeout';
	public const DEBUG_KEY = 'dpd_debug';
	public const LAST_CONNECTION_CHECK_KEY = 'dpd_last_connection_check';
	public const LAST_CONNECTION_STATUS_KEY = 'dpd_last_connection_status';
	public const LAST_CONNECTION_MESSAGE_KEY = 'dpd_last_connection_message';
	public const GEOGRAPHY_FTP_HOST_KEY = 'dpd_geography_ftp_host';
	public const GEOGRAPHY_FTP_PORT_KEY = 'dpd_geography_ftp_port';
	public const GEOGRAPHY_FTP_USERNAME_KEY = 'dpd_geography_ftp_username';
	public const GEOGRAPHY_FTP_PASSWORD_ENCRYPTED_KEY = 'dpd_geography_ftp_password_encrypted';
	public const GEOGRAPHY_FTP_REMOTE_DIRECTORY_KEY = 'dpd_geography_ftp_remote_directory';
	public const LAST_GEOGRAPHY_IMPORT_REPORT_KEY = 'dpd_last_geography_import_report';

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
			self::ENVIRONMENT_KEY => self::ENV_TEST,
			self::TEST_CLIENT_NUMBER_KEY => '',
			self::TEST_CLIENT_KEY_ENCRYPTED_KEY => '',
			self::PRODUCTION_CLIENT_NUMBER_KEY => '',
			self::PRODUCTION_CLIENT_KEY_ENCRYPTED_KEY => '',
			self::REQUEST_TIMEOUT_KEY => self::DEFAULT_REQUEST_TIMEOUT,
			self::DEBUG_KEY => false,
			self::LAST_CONNECTION_CHECK_KEY => '',
			self::LAST_CONNECTION_STATUS_KEY => '',
			self::LAST_CONNECTION_MESSAGE_KEY => '',
			self::GEOGRAPHY_FTP_HOST_KEY => 'ftp.dpd.ru',
			self::GEOGRAPHY_FTP_PORT_KEY => 22,
			self::GEOGRAPHY_FTP_USERNAME_KEY => 'integration',
			self::GEOGRAPHY_FTP_PASSWORD_ENCRYPTED_KEY => '',
			self::GEOGRAPHY_FTP_REMOTE_DIRECTORY_KEY => '/integration',
			self::LAST_GEOGRAPHY_IMPORT_REPORT_KEY => array(),
		);
	}

	public function environment(): string {
		$environment = $this->settings->get_string( self::ENVIRONMENT_KEY, self::ENV_TEST );

		return self::ENV_PRODUCTION === $environment ? self::ENV_PRODUCTION : self::ENV_TEST;
	}

	public function request_timeout(): int {
		return max( 1, min( 120, $this->settings->get_int( self::REQUEST_TIMEOUT_KEY, self::DEFAULT_REQUEST_TIMEOUT ) ) );
	}

	public function debug_enabled(): bool {
		return $this->settings->get_bool( self::DEBUG_KEY, false );
	}

	public function credentials(): DpdCredentials {
		return $this->credentials_for_environment( $this->environment() );
	}

	public function credentials_for_environment( string $environment ): DpdCredentials {
		$environment = self::ENV_PRODUCTION === $environment ? self::ENV_PRODUCTION : self::ENV_TEST;

		return new DpdCredentials(
			$this->client_number( $environment ),
			$this->client_key( $environment ),
			$environment
		);
	}

	public function has_client_key( string $environment ): bool {
		$key = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_CLIENT_KEY_ENCRYPTED_KEY : self::TEST_CLIENT_KEY_ENCRYPTED_KEY;

		return '' !== $this->settings->get_string( $key, '' );
	}

	public function credentials_are_complete(): bool {
		return $this->credentials()->is_complete();
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
		$this->settings->set( self::REQUEST_TIMEOUT_KEY, max( 1, min( 120, (int) ( $input[ self::REQUEST_TIMEOUT_KEY ] ?? self::DEFAULT_REQUEST_TIMEOUT ) ) ) );
		$this->settings->set( self::DEBUG_KEY, ! empty( $input[ self::DEBUG_KEY ] ) );
	}

	public function save_connection_result( bool $success, string $message ): void {
		$this->settings->set( self::LAST_CONNECTION_CHECK_KEY, function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) );
		$this->settings->set( self::LAST_CONNECTION_STATUS_KEY, $success ? 'success' : 'error' );
		$this->settings->set( self::LAST_CONNECTION_MESSAGE_KEY, $this->redact( 'Среда: ' . $this->environment_label() . '. ' . $message ) );
	}

	/**
	 * @param array<string,mixed> $input
	 */
	public function save_geography_settings_from_admin( array $input ): void {
		$this->settings->set( self::GEOGRAPHY_FTP_HOST_KEY, $this->sanitize_text( (string) ( $input[ self::GEOGRAPHY_FTP_HOST_KEY ] ?? 'ftp.dpd.ru' ) ) );
		$this->settings->set( self::GEOGRAPHY_FTP_PORT_KEY, max( 1, min( 65535, (int) ( $input[ self::GEOGRAPHY_FTP_PORT_KEY ] ?? 22 ) ) ) );
		$this->settings->set( self::GEOGRAPHY_FTP_USERNAME_KEY, $this->sanitize_text( (string) ( $input[ self::GEOGRAPHY_FTP_USERNAME_KEY ] ?? 'integration' ) ) );
		$directory = '/' . trim( $this->sanitize_text( (string) ( $input[ self::GEOGRAPHY_FTP_REMOTE_DIRECTORY_KEY ] ?? '/integration' ) ), '/' );
		$this->settings->set( self::GEOGRAPHY_FTP_REMOTE_DIRECTORY_KEY, '/' === $directory ? '/integration' : $directory );

		if ( ! empty( $input['dpd_clear_geography_ftp_password'] ) ) {
			$this->settings->set( self::GEOGRAPHY_FTP_PASSWORD_ENCRYPTED_KEY, '' );
			return;
		}

		$password = trim( (string) wp_unslash( $input['dpd_geography_ftp_password'] ?? '' ) );
		if ( '' !== $password && '********' !== $password ) {
			$this->settings->set( self::GEOGRAPHY_FTP_PASSWORD_ENCRYPTED_KEY, $this->encryption->encrypt( $password ) );
		}
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

	public function geography_ftp_host(): string {
		$host = trim( $this->settings->get_string( self::GEOGRAPHY_FTP_HOST_KEY, 'ftp.dpd.ru' ) );
		return '' !== $host ? $host : 'ftp.dpd.ru';
	}

	public function geography_ftp_port(): int {
		return max( 1, min( 65535, $this->settings->get_int( self::GEOGRAPHY_FTP_PORT_KEY, 22 ) ) );
	}

	public function geography_ftp_username(): string {
		$username = trim( $this->settings->get_string( self::GEOGRAPHY_FTP_USERNAME_KEY, 'integration' ) );
		return '' !== $username ? $username : 'integration';
	}

	public function geography_ftp_remote_directory(): string {
		$directory = '/' . trim( $this->settings->get_string( self::GEOGRAPHY_FTP_REMOTE_DIRECTORY_KEY, '/integration' ), '/' );
		return '/' === $directory ? '/integration' : $directory;
	}

	public function has_geography_ftp_password(): bool {
		return '' !== $this->settings->get_string( self::GEOGRAPHY_FTP_PASSWORD_ENCRYPTED_KEY, '' );
	}

	public function geography_ftp_password(): string {
		$encrypted = $this->settings->get_string( self::GEOGRAPHY_FTP_PASSWORD_ENCRYPTED_KEY, '' );
		if ( '' === $encrypted ) {
			return '';
		}

		return (string) ( $this->encryption->decrypt( $encrypted ) ?? '' );
	}

	/**
	 * @param array<string,mixed> $report
	 */
	public function save_geography_import_report( array $report ): void {
		$this->settings->set( self::LAST_GEOGRAPHY_IMPORT_REPORT_KEY, $report );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function last_geography_import_report(): array {
		return $this->settings->get_array( self::LAST_GEOGRAPHY_IMPORT_REPORT_KEY, array() );
	}

	private function client_number( string $environment ): string {
		$key = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_CLIENT_NUMBER_KEY : self::TEST_CLIENT_NUMBER_KEY;

		return trim( $this->settings->get_string( $key, '' ) );
	}

	private function client_key( string $environment ): string {
		$key = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_CLIENT_KEY_ENCRYPTED_KEY : self::TEST_CLIENT_KEY_ENCRYPTED_KEY;
		$encrypted = $this->settings->get_string( $key, '' );
		if ( '' === $encrypted ) {
			return '';
		}

		return (string) ( $this->encryption->decrypt( $encrypted ) ?? '' );
	}

	/**
	 * @param array<string,mixed> $input
	 */
	private function save_credentials_for_environment( string $environment, array $input ): void {
		$number_key = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_CLIENT_NUMBER_KEY : self::TEST_CLIENT_NUMBER_KEY;
		$key_storage = self::ENV_PRODUCTION === $environment ? self::PRODUCTION_CLIENT_KEY_ENCRYPTED_KEY : self::TEST_CLIENT_KEY_ENCRYPTED_KEY;
		$key_input = self::ENV_PRODUCTION === $environment ? 'dpd_production_client_key' : 'dpd_test_client_key';
		$clear_input = self::ENV_PRODUCTION === $environment ? 'dpd_clear_production_client_key' : 'dpd_clear_test_client_key';

		$this->settings->set( $number_key, sanitize_text_field( wp_unslash( $input[ $number_key ] ?? '' ) ) );
		if ( ! empty( $input[ $clear_input ] ) ) {
			$this->settings->set( $key_storage, '' );
			return;
		}

		$client_key = trim( (string) wp_unslash( $input[ $key_input ] ?? '' ) );
		if ( '' !== $client_key && '********' !== $client_key ) {
			$this->settings->set( $key_storage, $this->encryption->encrypt( $client_key ) );
		}
	}

	private function redact( string $message ): string {
		foreach ( array( self::ENV_TEST, self::ENV_PRODUCTION ) as $environment ) {
			$credentials = $this->credentials_for_environment( $environment );
			foreach ( array( $credentials->client_number, $credentials->client_key ) as $secret ) {
				if ( '' !== $secret ) {
					$message = str_replace( $secret, '[redacted]', $message );
				}
			}
		}
		$password = $this->geography_ftp_password();
		if ( '' !== $password ) {
			$message = str_replace( $password, '[redacted]', $message );
		}
		$message = preg_replace( '/\b(?:clientKey|client_key|token|secret)[A-Za-z0-9._\-:=]*\b/i', '[redacted]', $message ) ?? $message;

		return $message;
	}

	private function sanitize_text( string $value ): string {
		$value = function_exists( 'wp_unslash' ) ? (string) wp_unslash( $value ) : $value;
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
	}
}
