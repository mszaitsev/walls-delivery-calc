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
	public const DEFAULT_PICKUP_METHOD_TITLE = 'DPD до пункта выдачи';
	public const DEFAULT_COURIER_METHOD_TITLE = 'DPD курьером';

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
	public const LAST_GEOGRAPHY_ACTION_RESULT_KEY = 'dpd_last_geography_action_result';
	public const TARIFF_SENDER_LOCATION_ID_KEY = 'dpd_tariff_sender_location_id';
	public const TARIFF_SENDER_DPD_CITY_ID_KEY = 'dpd_tariff_sender_dpd_city_id';
	public const TARIFF_DEFAULT_WEIGHT_G_KEY = 'dpd_tariff_default_weight_g';
	public const TARIFF_DEFAULT_LENGTH_CM_KEY = 'dpd_tariff_default_length_cm';
	public const TARIFF_DEFAULT_WIDTH_CM_KEY = 'dpd_tariff_default_width_cm';
	public const TARIFF_DEFAULT_HEIGHT_CM_KEY = 'dpd_tariff_default_height_cm';
	public const TARIFF_DEFAULT_DECLARED_VALUE_RUB_KEY = 'dpd_tariff_default_declared_value_rub';
	public const TARIFF_DEFAULT_SENDER_TERMINAL_CODE_KEY = 'dpd_tariff_default_sender_terminal_code';
	public const RUNTIME_PICKUP_TITLE_KEY = 'dpd_runtime_pickup_title';
	public const RUNTIME_COURIER_TITLE_KEY = 'dpd_runtime_courier_title';
	public const RUNTIME_ENABLED_SERVICE_CODES_KEY = 'dpd_runtime_enabled_service_codes';
	public const RUNTIME_TARIFF_TITLES_KEY = 'dpd_runtime_tariff_titles';
	public const RUNTIME_ENABLE_COURIER_RATES_KEY = 'dpd_runtime_enable_courier_rates';
	public const LAST_PICKUP_IMPORT_REPORT_KEY = 'dpd_last_pickup_import_report';
	public const LAST_PICKUP_ACTION_RESULT_KEY = 'dpd_last_pickup_action_result';

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
			self::LAST_GEOGRAPHY_ACTION_RESULT_KEY => array(),
			self::TARIFF_SENDER_LOCATION_ID_KEY => 0,
			self::TARIFF_SENDER_DPD_CITY_ID_KEY => '',
			self::TARIFF_DEFAULT_WEIGHT_G_KEY => 1000,
			self::TARIFF_DEFAULT_LENGTH_CM_KEY => 20,
			self::TARIFF_DEFAULT_WIDTH_CM_KEY => 20,
			self::TARIFF_DEFAULT_HEIGHT_CM_KEY => 20,
			self::TARIFF_DEFAULT_DECLARED_VALUE_RUB_KEY => 1000,
			self::TARIFF_DEFAULT_SENDER_TERMINAL_CODE_KEY => '',
			self::RUNTIME_PICKUP_TITLE_KEY => self::DEFAULT_PICKUP_METHOD_TITLE,
			self::RUNTIME_COURIER_TITLE_KEY => self::DEFAULT_COURIER_METHOD_TITLE,
			self::RUNTIME_ENABLED_SERVICE_CODES_KEY => 'ECN,CSM,MXO',
			self::RUNTIME_TARIFF_TITLES_KEY => array(),
			self::RUNTIME_ENABLE_COURIER_RATES_KEY => false,
			self::LAST_PICKUP_IMPORT_REPORT_KEY => array(),
			self::LAST_PICKUP_ACTION_RESULT_KEY => array(),
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function known_service_codes(): array {
		return array(
			'MAX' => 'DPD Максимум',
			'NDY' => 'DPD Экспресс',
			'IND' => 'DPD Экспресс-13',
			'PCL' => 'DPD Оптимум',
			'CUR' => 'DPD Classic',
			'MXO' => 'DPD Стандарт',
			'ECN' => 'DPD Эконом',
			'ECU' => 'DPD Эконом ЕАЭС',
			'BZP' => 'DPD 18:00',
			'CSM' => 'DPD Онлайн-экспресс',
			'PUP' => 'DPD Коробка',
			'PKT' => 'DPD Пакет',
			'DPI' => 'DPD Импорт Классик',
			'DPE' => 'DPD Экспорт Классик',
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

	/**
	 * @param array<string,mixed> $result
	 */
	public function save_geography_action_result( array $result ): void {
		$type = (string) ( $result['type'] ?? 'info' );
		if ( ! in_array( $type, array( 'success', 'warning', 'error', 'info' ), true ) ) {
			$type = 'info';
		}
		$details = is_array( $result['details'] ?? null ) ? $result['details'] : array();
		$this->settings->set(
			self::LAST_GEOGRAPHY_ACTION_RESULT_KEY,
			array(
				'type' => $type,
				'title' => $this->sanitize_text( (string) ( $result['title'] ?? 'DPD География' ) ),
				'message' => $this->redact( $this->sanitize_text( (string) ( $result['message'] ?? '' ) ) ),
				'details' => $this->redact_details( $details ),
				'created_at' => (string) ( $result['created_at'] ?? ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) ) ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_geography_action_result(): array {
		$result = $this->settings->get_array( self::LAST_GEOGRAPHY_ACTION_RESULT_KEY, array() );
		if ( array() === $result ) {
			return array();
		}

		return array(
			'type' => (string) ( $result['type'] ?? 'info' ),
			'title' => (string) ( $result['title'] ?? '' ),
			'message' => (string) ( $result['message'] ?? '' ),
			'details' => is_array( $result['details'] ?? null ) ? $result['details'] : array(),
			'created_at' => (string) ( $result['created_at'] ?? '' ),
		);
	}

	public function clear_geography_action_result(): void {
		$this->settings->set( self::LAST_GEOGRAPHY_ACTION_RESULT_KEY, array() );
	}

	/**
	 * @param array<string,mixed> $report
	 */
	public function save_pickup_import_report( array $report ): void {
		$this->settings->set( self::LAST_PICKUP_IMPORT_REPORT_KEY, $report );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function last_pickup_import_report(): array {
		return $this->settings->get_array( self::LAST_PICKUP_IMPORT_REPORT_KEY, array() );
	}

	/**
	 * @param array<string,mixed> $result
	 */
	public function save_pickup_action_result( array $result ): void {
		$type = (string) ( $result['type'] ?? 'info' );
		if ( ! in_array( $type, array( 'success', 'warning', 'error', 'info' ), true ) ) {
			$type = 'info';
		}
		$this->settings->set(
			self::LAST_PICKUP_ACTION_RESULT_KEY,
			array(
				'type' => $type,
				'title' => $this->sanitize_text( (string) ( $result['title'] ?? 'DPD ПВЗ' ) ),
				'message' => $this->redact( $this->sanitize_text( (string) ( $result['message'] ?? '' ) ) ),
				'details' => $this->redact_details( is_array( $result['details'] ?? null ) ? $result['details'] : array() ),
				'created_at' => (string) ( $result['created_at'] ?? ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) ) ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_pickup_action_result(): array {
		$result = $this->settings->get_array( self::LAST_PICKUP_ACTION_RESULT_KEY, array() );
		if ( array() === $result ) {
			return array();
		}

		return array(
			'type' => (string) ( $result['type'] ?? 'info' ),
			'title' => (string) ( $result['title'] ?? '' ),
			'message' => (string) ( $result['message'] ?? '' ),
			'details' => is_array( $result['details'] ?? null ) ? $result['details'] : array(),
			'created_at' => (string) ( $result['created_at'] ?? '' ),
		);
	}

	public function clear_pickup_action_result(): void {
		$this->settings->set( self::LAST_PICKUP_ACTION_RESULT_KEY, array() );
	}

	/**
	 * @param array<string,mixed> $input
	 */
	public function save_tariff_settings_from_admin( array $input ): void {
		$this->settings->set( self::TARIFF_SENDER_LOCATION_ID_KEY, max( 0, (int) ( $input[ self::TARIFF_SENDER_LOCATION_ID_KEY ] ?? 0 ) ) );
		$this->settings->set( self::TARIFF_SENDER_DPD_CITY_ID_KEY, $this->digits( (string) ( $input[ self::TARIFF_SENDER_DPD_CITY_ID_KEY ] ?? '' ) ) );
		$this->settings->set( self::TARIFF_DEFAULT_SENDER_TERMINAL_CODE_KEY, $this->terminal_code( (string) ( $input[ self::TARIFF_DEFAULT_SENDER_TERMINAL_CODE_KEY ] ?? '' ) ) );
		$this->settings->set( self::TARIFF_DEFAULT_WEIGHT_G_KEY, max( 1, (int) ( $input[ self::TARIFF_DEFAULT_WEIGHT_G_KEY ] ?? 1000 ) ) );
		$this->settings->set( self::TARIFF_DEFAULT_LENGTH_CM_KEY, max( 0.1, (float) ( $input[ self::TARIFF_DEFAULT_LENGTH_CM_KEY ] ?? 20 ) ) );
		$this->settings->set( self::TARIFF_DEFAULT_WIDTH_CM_KEY, max( 0.1, (float) ( $input[ self::TARIFF_DEFAULT_WIDTH_CM_KEY ] ?? 20 ) ) );
		$this->settings->set( self::TARIFF_DEFAULT_HEIGHT_CM_KEY, max( 0.1, (float) ( $input[ self::TARIFF_DEFAULT_HEIGHT_CM_KEY ] ?? 20 ) ) );
		$this->settings->set( self::TARIFF_DEFAULT_DECLARED_VALUE_RUB_KEY, max( 0.0, (float) ( $input[ self::TARIFF_DEFAULT_DECLARED_VALUE_RUB_KEY ] ?? 1000 ) ) );
	}

	/**
	 * @param array<string,mixed> $input
	 */
	public function save_runtime_titles_from_admin( array $input ): void {
		$pickup = $this->sanitize_text( (string) ( $input[ self::RUNTIME_PICKUP_TITLE_KEY ] ?? self::DEFAULT_PICKUP_METHOD_TITLE ) );
		$courier = $this->sanitize_text( (string) ( $input[ self::RUNTIME_COURIER_TITLE_KEY ] ?? self::DEFAULT_COURIER_METHOD_TITLE ) );
		$this->settings->set( self::RUNTIME_PICKUP_TITLE_KEY, '' !== $pickup ? $pickup : self::DEFAULT_PICKUP_METHOD_TITLE );
		$this->settings->set( self::RUNTIME_COURIER_TITLE_KEY, '' !== $courier ? $courier : self::DEFAULT_COURIER_METHOD_TITLE );
	}

	/**
	 * @param array<string,mixed> $input
	 */
	public function save_runtime_tariffs_from_admin( array $input ): void {
		$enabled_input = is_array( $input['dpd_runtime_service_enabled'] ?? null ) ? wp_unslash( $input['dpd_runtime_service_enabled'] ) : array();
		$enabled = array();
		foreach ( array_keys( self::known_service_codes() ) as $code ) {
			if ( ! empty( $enabled_input[ $code ] ) ) {
				$enabled[] = $code;
			}
		}
		$this->settings->set( self::RUNTIME_ENABLED_SERVICE_CODES_KEY, implode( ',', $enabled ) );

		$title_input = is_array( $input['dpd_runtime_tariff_title'] ?? null ) ? wp_unslash( $input['dpd_runtime_tariff_title'] ) : array();
		$titles = array();
		foreach ( self::known_service_codes() as $code => $default_title ) {
			$title = $this->sanitize_text( (string) ( $title_input[ $code ] ?? $default_title ) );
			$titles[ $code ] = '' !== $title ? $title : $default_title;
		}
		$this->settings->set( self::RUNTIME_TARIFF_TITLES_KEY, $titles );
		$this->settings->set( self::RUNTIME_ENABLE_COURIER_RATES_KEY, ! empty( $input[ self::RUNTIME_ENABLE_COURIER_RATES_KEY ] ) );
	}

	public function tariff_sender_location_id(): int {
		return max( 0, $this->settings->get_int( self::TARIFF_SENDER_LOCATION_ID_KEY, 0 ) );
	}

	public function tariff_sender_dpd_city_id(): string {
		return $this->digits( $this->settings->get_string( self::TARIFF_SENDER_DPD_CITY_ID_KEY, '' ) );
	}

	public function tariff_default_weight_g(): int {
		return max( 1, $this->settings->get_int( self::TARIFF_DEFAULT_WEIGHT_G_KEY, 1000 ) );
	}

	public function tariff_default_length_cm(): float {
		return max( 0.1, (float) $this->settings->get_string( self::TARIFF_DEFAULT_LENGTH_CM_KEY, '20' ) );
	}

	public function tariff_default_width_cm(): float {
		return max( 0.1, (float) $this->settings->get_string( self::TARIFF_DEFAULT_WIDTH_CM_KEY, '20' ) );
	}

	public function tariff_default_height_cm(): float {
		return max( 0.1, (float) $this->settings->get_string( self::TARIFF_DEFAULT_HEIGHT_CM_KEY, '20' ) );
	}

	public function tariff_default_declared_value_rub(): float {
		return max( 0.0, (float) $this->settings->get_string( self::TARIFF_DEFAULT_DECLARED_VALUE_RUB_KEY, '1000' ) );
	}

	public function tariff_default_sender_terminal_code(): string {
		return $this->terminal_code( $this->settings->get_string( self::TARIFF_DEFAULT_SENDER_TERMINAL_CODE_KEY, '' ) );
	}

	/**
	 * @return array<int,string>
	 */
	public function runtime_enabled_service_codes(): array {
		$codes = $this->sanitize_service_codes( $this->settings->get_string( self::RUNTIME_ENABLED_SERVICE_CODES_KEY, 'ECN,CSM,MXO' ) );
		if ( '' === $codes ) {
			return array();
		}

		return array_values( array_filter( array_map( 'trim', explode( ',', $codes ) ), static fn( string $code ): bool => '' !== $code ) );
	}

	public function runtime_enabled_service_codes_raw(): string {
		return $this->sanitize_service_codes( $this->settings->get_string( self::RUNTIME_ENABLED_SERVICE_CODES_KEY, 'ECN,CSM,MXO' ) );
	}

	public function runtime_allowed_service_codes(): array {
		return $this->runtime_enabled_service_codes();
	}

	public function runtime_allowed_service_codes_raw(): string {
		return $this->runtime_enabled_service_codes_raw();
	}

	public function runtime_courier_rates_enabled(): bool {
		return $this->settings->get_bool( self::RUNTIME_ENABLE_COURIER_RATES_KEY, false );
	}

	public function runtime_pickup_title(): string {
		$title = trim( $this->settings->get_string( self::RUNTIME_PICKUP_TITLE_KEY, self::DEFAULT_PICKUP_METHOD_TITLE ) );

		return '' !== $title ? $title : self::DEFAULT_PICKUP_METHOD_TITLE;
	}

	public function runtime_courier_title(): string {
		$title = trim( $this->settings->get_string( self::RUNTIME_COURIER_TITLE_KEY, self::DEFAULT_COURIER_METHOD_TITLE ) );

		return '' !== $title ? $title : self::DEFAULT_COURIER_METHOD_TITLE;
	}

	/**
	 * @return array<string,string>
	 */
	public function runtime_tariff_titles(): array {
		$saved = $this->settings->get_array( self::RUNTIME_TARIFF_TITLES_KEY, array() );
		$titles = self::known_service_codes();
		foreach ( $saved as $code => $title ) {
			$code = strtoupper( trim( (string) $code ) );
			if ( '' === $code ) {
				continue;
			}
			$title = trim( (string) $title );
			if ( '' !== $title ) {
				$titles[ $code ] = $title;
			}
		}

		return $titles;
	}

	public function runtime_tariff_title( string $code, string $fallback = '' ): string {
		$code = strtoupper( trim( $code ) );
		$titles = $this->runtime_tariff_titles();
		$title = trim( (string) ( $titles[ $code ] ?? '' ) );

		return '' !== $title ? $title : trim( $fallback );
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

	/**
	 * @param array<string,mixed> $details
	 * @return array<string,mixed>
	 */
	private function redact_details( array $details ): array {
		$redacted = array();
		foreach ( $details as $key => $value ) {
			if ( is_array( $value ) ) {
				$redacted[ $key ] = implode( ',', array_map( 'strval', $value ) );
				continue;
			}
			$redacted[ $key ] = is_scalar( $value ) ? $this->redact( (string) $value ) : '';
		}

		return $redacted;
	}

	private function redact_value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$redacted = array();
			foreach ( $value as $key => $item ) {
				$redacted[ $key ] = $this->redact_value( $item );
			}
			return $redacted;
		}

		return is_scalar( $value ) ? $this->redact( (string) $value ) : '';
	}

	private function digits( string $value ): string {
		return preg_replace( '/\D+/', '', $value ) ?? '';
	}

	private function terminal_code( string $value ): string {
		return substr( preg_replace( '/[^A-Za-z0-9_\-]+/', '', strtoupper( trim( $value ) ) ) ?? '', 0, 64 );
	}

	private function sanitize_service_codes( string $value ): string {
		$codes = preg_split( '/[\s,;]+/', strtoupper( $value ) ) ?: array();
		$codes = array_values(
			array_unique(
				array_filter(
					array_map(
						static fn( string $code ): string => preg_replace( '/[^A-Z0-9_\-]+/', '', $code ) ?? '',
						$codes
					),
					static fn( string $code ): bool => '' !== $code
				)
			)
		);

		return implode( ',', $codes );
	}

	private function sanitize_text( string $value ): string {
		$value = function_exists( 'wp_unslash' ) ? (string) wp_unslash( $value ) : $value;
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
	}
}
