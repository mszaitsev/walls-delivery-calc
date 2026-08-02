<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek;

use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class PekSettings {
	public const CARRIER_KEY = 'pek';
	public const SERVICE_KEY = 'pek';
	public const TITLE = 'ПЭК';
	public const PUBLIC_TITLE = 'ПЭК';
	public const LTL_PRODUCT_TYPE = 3;
	public const PLANNED_COUNTRIES = array( 'RU', 'AM', 'BY', 'KG', 'KZ' );
	public const INITIAL_COUNTRIES = array( 'RU' );
	public const COUNTRY_CLASSIFIER_CODES = array(
		'RU' => '643',
		'AM' => '051',
		'BY' => '112',
		'KG' => '417',
		'KZ' => '398',
	);
	public const LEGAL_FORM_LEGAL_ENTITY = 1;
	public const LEGAL_FORM_INDIVIDUAL_ENTREPRENEUR = 2;
	public const DEFAULT_SMS_RELEASE_LIMIT_RUB = 500000;
	public const DEFAULT_CARGO_DESCRIPTION = 'Товары';
	public const BASE_URL = 'https://kabinet.pecom.ru/api/v1';

	public const LOGIN_KEY = 'pek_login';
	public const API_KEY_ENCRYPTED_KEY = 'pek_api_key_encrypted';
	public const REQUEST_TIMEOUT_KEY = 'pek_http_timeout';
	public const REQUESTS_PER_MINUTE_KEY = 'pek_requests_per_minute';
	public const SENDER_WAREHOUSE_KEY = 'pek_sender_warehouse';
	public const SENDER_LEGAL_FORM_KEY = 'pek_sender_legal_form';
	public const SENDER_FS_KEY = 'pek_sender_fs';
	public const SENDER_FULL_NAME_KEY = 'pek_sender_full_name';
	public const SENDER_INN_KEY = 'pek_sender_inn';
	public const SENDER_KPP_KEY = 'pek_sender_kpp';
	public const SENDER_REGISTRATION_COUNTRY_KEY = 'pek_sender_registration_country';
	public const SENDER_CONTACT_NAME_KEY = 'pek_sender_contact_name';
	public const SENDER_PHONE_KEY = 'pek_sender_phone';
	public const SENDER_EMAIL_KEY = 'pek_sender_email';
	public const CLIENT_CARD_KEY = 'pek_client_card';
	public const DEFAULT_CARGO_DESCRIPTION_KEY = 'pek_default_cargo_description';
	public const WAREHOUSE_SEARCH_RADIUS_KEY = 'pek_warehouse_search_radius';
	public const WAREHOUSE_SEARCH_LIMIT_KEY = 'pek_warehouse_search_limit';
	public const SMS_RELEASE_LIMIT_RUB_KEY = 'pek_sms_release_limit_rub';
	public const LAST_DIAGNOSTIC_KEY = 'pek_last_diagnostic';

	public function __construct( private SettingsRepository $settings ) {
	}

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			self::LOGIN_KEY => '',
			self::API_KEY_ENCRYPTED_KEY => '',
			self::REQUEST_TIMEOUT_KEY => 15,
			self::REQUESTS_PER_MINUTE_KEY => 90,
			self::SENDER_WAREHOUSE_KEY => array(),
			self::SENDER_LEGAL_FORM_KEY => self::LEGAL_FORM_LEGAL_ENTITY,
			self::SENDER_FS_KEY => '',
			self::SENDER_FULL_NAME_KEY => '',
			self::SENDER_INN_KEY => '',
			self::SENDER_KPP_KEY => '',
			self::SENDER_REGISTRATION_COUNTRY_KEY => 'RU',
			self::SENDER_CONTACT_NAME_KEY => '',
			self::SENDER_PHONE_KEY => '',
			self::SENDER_EMAIL_KEY => '',
			self::CLIENT_CARD_KEY => '',
			self::DEFAULT_CARGO_DESCRIPTION_KEY => self::DEFAULT_CARGO_DESCRIPTION,
			self::WAREHOUSE_SEARCH_RADIUS_KEY => 50,
			self::WAREHOUSE_SEARCH_LIMIT_KEY => 5,
			self::SMS_RELEASE_LIMIT_RUB_KEY => self::DEFAULT_SMS_RELEASE_LIMIT_RUB,
			self::LAST_DIAGNOSTIC_KEY => array(),
		);
	}

	public function request_timeout(): int {
		return $this->clamp_int( self::REQUEST_TIMEOUT_KEY, 15, 1, 60 );
	}

	public function request_soft_limit_per_minute(): int {
		return $this->clamp_int( self::REQUESTS_PER_MINUTE_KEY, 90, 1, 100 );
	}

	public function sender_legal_form(): int {
		$value = $this->settings->get_int( self::SENDER_LEGAL_FORM_KEY, self::LEGAL_FORM_LEGAL_ENTITY );

		return self::LEGAL_FORM_INDIVIDUAL_ENTREPRENEUR === $value ? self::LEGAL_FORM_INDIVIDUAL_ENTREPRENEUR : self::LEGAL_FORM_LEGAL_ENTITY;
	}

	public function sender_fs(): string {
		return $this->sanitize_text( $this->settings->get_string( self::SENDER_FS_KEY, '' ) );
	}

	public function sender_full_name(): string {
		return $this->sanitize_text( $this->settings->get_string( self::SENDER_FULL_NAME_KEY, '' ) );
	}

	public function sender_inn(): string {
		return preg_replace( '/\D+/', '', $this->settings->get_string( self::SENDER_INN_KEY, '' ) ) ?? '';
	}

	public function sender_kpp(): string {
		return preg_replace( '/\D+/', '', $this->settings->get_string( self::SENDER_KPP_KEY, '' ) ) ?? '';
	}

	public function sender_registration_country(): string {
		$country = strtoupper( $this->sanitize_key( $this->settings->get_string( self::SENDER_REGISTRATION_COUNTRY_KEY, 'RU' ) ) );

		return array_key_exists( $country, self::COUNTRY_CLASSIFIER_CODES ) ? $country : 'RU';
	}

	public function sender_registration_classifier_code(): string {
		return self::COUNTRY_CLASSIFIER_CODES[ $this->sender_registration_country() ];
	}

	public function sender_contact_name(): string {
		return $this->sanitize_text( $this->settings->get_string( self::SENDER_CONTACT_NAME_KEY, '' ) );
	}

	public function sender_phone(): string {
		return trim( preg_replace( '/[^\d+]/', '', $this->settings->get_string( self::SENDER_PHONE_KEY, '' ) ) ?? '' );
	}

	public function sender_email(): string {
		$email = $this->settings->get_string( self::SENDER_EMAIL_KEY, '' );

		return function_exists( 'sanitize_email' ) ? sanitize_email( $email ) : trim( $email );
	}

	public function client_card(): string {
		return $this->sanitize_text( $this->settings->get_string( self::CLIENT_CARD_KEY, '' ) );
	}

	public function default_cargo_description(): string {
		$value = $this->sanitize_text( $this->settings->get_string( self::DEFAULT_CARGO_DESCRIPTION_KEY, self::DEFAULT_CARGO_DESCRIPTION ) );

		return '' !== $value ? $value : self::DEFAULT_CARGO_DESCRIPTION;
	}

	public function warehouse_search_radius(): int {
		return $this->clamp_int( self::WAREHOUSE_SEARCH_RADIUS_KEY, 50, 1, 500 );
	}

	public function warehouse_search_limit(): int {
		return $this->clamp_int( self::WAREHOUSE_SEARCH_LIMIT_KEY, 5, 1, 50 );
	}

	public function sms_release_limit_rub(): int {
		return $this->clamp_int( self::SMS_RELEASE_LIMIT_RUB_KEY, self::DEFAULT_SMS_RELEASE_LIMIT_RUB, 1, 999999999 );
	}

	/** @return array<string,mixed> */
	public function sender_warehouse(): array {
		return $this->sanitize_snapshot( $this->settings->get_array( self::SENDER_WAREHOUSE_KEY, array() ) );
	}

	/** @param array<string,mixed> $snapshot */
	public function save_sender_warehouse( array $snapshot ): void {
		$this->settings->set( self::SENDER_WAREHOUSE_KEY, $this->sanitize_snapshot( $snapshot ) );
	}

	/** @return array<string,mixed> */
	public function last_diagnostic(): array {
		return $this->settings->get_array( self::LAST_DIAGNOSTIC_KEY, array() );
	}

	/** @param array<string,mixed> $result */
	public function save_diagnostic_result( array $result ): void {
		$this->settings->set( self::LAST_DIAGNOSTIC_KEY, $this->sanitize_report( $result ) );
	}

	/** @param array<string,mixed> $input */
	public function save_from_admin( array $input ): void {
		$this->settings->set( self::REQUEST_TIMEOUT_KEY, $this->clamp_raw_int( $input[ self::REQUEST_TIMEOUT_KEY ] ?? 15, 1, 60 ) );
		$this->settings->set( self::REQUESTS_PER_MINUTE_KEY, $this->clamp_raw_int( $input[ self::REQUESTS_PER_MINUTE_KEY ] ?? 90, 1, 100 ) );
		$this->settings->set( self::SENDER_LEGAL_FORM_KEY, self::LEGAL_FORM_INDIVIDUAL_ENTREPRENEUR === (int) ( $input[ self::SENDER_LEGAL_FORM_KEY ] ?? 1 ) ? 2 : 1 );
		foreach ( array( self::SENDER_FS_KEY, self::SENDER_FULL_NAME_KEY, self::SENDER_CONTACT_NAME_KEY, self::CLIENT_CARD_KEY, self::DEFAULT_CARGO_DESCRIPTION_KEY ) as $key ) {
			$this->settings->set( $key, $this->sanitize_text( (string) ( $input[ $key ] ?? '' ) ) );
		}
		$this->settings->set( self::SENDER_INN_KEY, preg_replace( '/\D+/', '', (string) ( $input[ self::SENDER_INN_KEY ] ?? '' ) ) ?? '' );
		$this->settings->set( self::SENDER_KPP_KEY, preg_replace( '/\D+/', '', (string) ( $input[ self::SENDER_KPP_KEY ] ?? '' ) ) ?? '' );
		$country = strtoupper( $this->sanitize_key( (string) ( $input[ self::SENDER_REGISTRATION_COUNTRY_KEY ] ?? 'RU' ) ) );
		$this->settings->set( self::SENDER_REGISTRATION_COUNTRY_KEY, array_key_exists( $country, self::COUNTRY_CLASSIFIER_CODES ) ? $country : 'RU' );
		$this->settings->set( self::SENDER_PHONE_KEY, trim( preg_replace( '/[^\d+]/', '', (string) ( $input[ self::SENDER_PHONE_KEY ] ?? '' ) ) ?? '' ) );
		$email = (string) ( $input[ self::SENDER_EMAIL_KEY ] ?? '' );
		$this->settings->set( self::SENDER_EMAIL_KEY, function_exists( 'sanitize_email' ) ? sanitize_email( $email ) : trim( $email ) );
		$this->settings->set( self::WAREHOUSE_SEARCH_RADIUS_KEY, $this->clamp_raw_int( $input[ self::WAREHOUSE_SEARCH_RADIUS_KEY ] ?? 50, 1, 500 ) );
		$this->settings->set( self::WAREHOUSE_SEARCH_LIMIT_KEY, $this->clamp_raw_int( $input[ self::WAREHOUSE_SEARCH_LIMIT_KEY ] ?? 5, 1, 50 ) );
		$this->settings->set( self::SMS_RELEASE_LIMIT_RUB_KEY, $this->clamp_raw_int( $input[ self::SMS_RELEASE_LIMIT_RUB_KEY ] ?? self::DEFAULT_SMS_RELEASE_LIMIT_RUB, 1, 999999999 ) );
	}

	/** @param array<string,mixed> $value */
	private function sanitize_snapshot( array $value ): array {
		if ( '' === trim( (string) ( $value['warehouseId'] ?? '' ) ) ) {
			return array();
		}
		$limits = is_array( $value['limits'] ?? null ) ? $value['limits'] : array();
		$availability = is_array( $value['availability'] ?? null ) ? $value['availability'] : array();

		return array(
			'warehouseId' => $this->sanitize_text( (string) $value['warehouseId'] ),
			'branchId' => $this->sanitize_text( (string) ( $value['branchId'] ?? '' ) ),
			'branchName' => $this->sanitize_text( (string) ( $value['branchName'] ?? '' ) ),
			'divisionName' => $this->sanitize_text( (string) ( $value['divisionName'] ?? '' ) ),
			'departmentTypeId' => (int) ( $value['departmentTypeId'] ?? 0 ),
			'departmentType' => $this->sanitize_text( (string) ( $value['departmentType'] ?? '' ) ),
			'address' => $this->sanitize_text( (string) ( $value['address'] ?? '' ) ),
			'coordinates' => array(
				'latitude' => (string) ( $value['coordinates']['latitude'] ?? '' ),
				'longitude' => (string) ( $value['coordinates']['longitude'] ?? '' ),
			),
			'branchTimezone' => $this->snapshot_nullable_string( $value['branchTimezone'] ?? null ),
			'limits' => array(
				'maxWeight' => $this->numeric_or_null( $limits['maxWeight'] ?? null ),
				'maxVolume' => $this->numeric_or_null( $limits['maxVolume'] ?? null ),
				'maxDimension' => $this->numeric_or_null( $limits['maxDimension'] ?? null ),
				'maxWeightOnePlace' => $this->numeric_or_null( $limits['maxWeightOnePlace'] ?? null ),
				'maxCount' => $this->numeric_or_null( $limits['maxCount'] ?? null ),
			),
			'availability' => array(
				'endOfAvailabilityBeforeClosing' => $this->snapshot_nullable_string( $availability['endOfAvailabilityBeforeClosing'] ?? null ),
				'endOfCostCalculationAvailability' => $this->snapshot_nullable_string( $availability['endOfCostCalculationAvailability'] ?? null ),
				'departmentClosingDate' => $this->snapshot_nullable_string( $availability['departmentClosingDate'] ?? null ),
			),
			'checked_at' => $this->sanitize_text( (string) ( $value['checked_at'] ?? '' ) ),
		);
	}

	private function clamp_int( string $key, int $default, int $min, int $max ): int {
		return max( $min, min( $max, $this->settings->get_int( $key, $default ) ) );
	}

	private function clamp_raw_int( mixed $value, int $min, int $max ): int {
		return max( $min, min( $max, is_numeric( $value ) ? (int) $value : $min ) );
	}

	private function sanitize_text( string $value ): string {
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;

		return trim( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : $value );
	}

	private function snapshot_nullable_string( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( (string) $value ) : (string) $value;
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value ) ?? $value;

		return substr( trim( $value ), 0, 120 );
	}

	private function sanitize_key( string $value ): string {
		return function_exists( 'sanitize_key' ) ? sanitize_key( $value ) : strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' );
	}

	/** @param array<string,mixed> $result */
	private function sanitize_report( array $result ): array {
		return $this->sanitize_report_value( $result );
	}

	private function sanitize_report_value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				$key_text = strtolower( (string) $key );
				if ( str_contains( $key_text, 'authorization' ) || str_contains( $key_text, 'api_key' ) || str_contains( $key_text, 'password' ) || str_contains( $key_text, 'login' ) ) {
					$out[ $key ] = '[redacted]';
					continue;
				}
				$out[ $key ] = $this->sanitize_report_value( $item );
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			$value = preg_replace( '/Basic\s+[A-Za-z0-9+\/=]+/i', 'Basic [redacted]', $value ) ?? $value;
			$value = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted-email]', $value ) ?? $value;
			$value = preg_replace( '/(?:\+?\d[\d\s().-]{8,}\d)/', '[redacted-phone]', $value ) ?? $value;
			return strlen( $value ) > 1000 ? substr( $value, 0, 1000 ) . '...' : $value;
		}

		return $value;
	}

	private function numeric_or_null( mixed $value ): int|float|null {
		return is_numeric( $value ) ? ( $value + 0 ) : null;
	}
}
