<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class PekCredentials {
	public function __construct(
		private SettingsRepository $settings,
		private EncryptionService $encryption
	) {
	}

	public function login(): string {
		return $this->sanitize_login( $this->settings->get_string( PekSettings::LOGIN_KEY, '' ) );
	}

	public function api_key(): string {
		$encrypted = $this->settings->get_string( PekSettings::API_KEY_ENCRYPTED_KEY, '' );
		if ( '' === trim( $encrypted ) ) {
			return '';
		}

		$plain = $this->encryption->decrypt( $encrypted );

		return is_string( $plain ) ? $plain : '';
	}

	public function is_complete(): bool {
		return '' !== $this->login() && '' !== $this->api_key();
	}

	public function has_api_key(): bool {
		return '' !== trim( $this->settings->get_string( PekSettings::API_KEY_ENCRYPTED_KEY, '' ) );
	}

	public function encryption_ready(): bool {
		return $this->encryption->has_configured_key();
	}

	/** @param array<string,mixed> $input */
	public function save_from_admin( array $input ): bool {
		$this->settings->set( PekSettings::LOGIN_KEY, $this->sanitize_login( (string) ( $input[ PekSettings::LOGIN_KEY ] ?? '' ) ) );
		if ( ! empty( $input['pek_clear_api_key'] ) ) {
			$this->clear_api_key();
			return true;
		}

		$key = trim( (string) ( $input['pek_api_key'] ?? '' ) );
		if ( '' === $key ) {
			return true;
		}
		if ( ! $this->encryption_ready() ) {
			return false;
		}

		$this->settings->set( PekSettings::API_KEY_ENCRYPTED_KEY, $this->encryption->encrypt( $key ) );

		return true;
	}

	public function clear_api_key(): void {
		$this->settings->set( PekSettings::API_KEY_ENCRYPTED_KEY, '' );
	}

	private function sanitize_login( string $value ): string {
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value ) : trim( $value );

		return trim( $value );
	}
}
