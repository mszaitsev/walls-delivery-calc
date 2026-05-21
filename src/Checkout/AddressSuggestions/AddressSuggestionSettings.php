<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\AddressSuggestions;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class AddressSuggestionSettings {
	public const API_KEY_ENCRYPTED = 'dadata_api_key_encrypted';
	public const API_KEY_MASKED = 'dadata_api_key_masked';

	public function __construct(
		private SettingsRepository $settings,
		private EncryptionService $encryption
	) {
	}

	public function enabled(): bool {
		return $this->settings->get_bool( 'dadata_suggestions_enabled', false );
	}

	public function has_api_key(): bool {
		return '' !== $this->settings->get_string( self::API_KEY_ENCRYPTED, '' ) && $this->encryption->has_configured_key();
	}

	public function api_key(): string {
		if ( ! $this->encryption->has_configured_key() ) {
			return '';
		}

		$plain = $this->encryption->decrypt( $this->settings->get_string( self::API_KEY_ENCRYPTED, '' ) );
		return is_string( $plain ) ? $plain : '';
	}

	public function masked_api_key(): string {
		$masked = $this->settings->get_string( self::API_KEY_MASKED, '' );
		return '' !== $masked ? $masked : ( $this->has_api_key() ? '********' : '' );
	}

	public function save_api_key( string $plain ): void {
		$plain = trim( $plain );
		if ( '' === $plain || ! $this->encryption->has_configured_key() ) {
			return;
		}

		$encrypted = $this->encryption->encrypt( $plain );
		if ( '' === $encrypted ) {
			return;
		}

		$settings = $this->settings->all();
		$settings[ self::API_KEY_ENCRYPTED ] = $encrypted;
		$settings[ self::API_KEY_MASKED ] = '********';
		$this->settings->replace( $settings );
	}

	public function clear_api_key(): void {
		$settings = $this->settings->all();
		unset( $settings[ self::API_KEY_ENCRYPTED ], $settings[ self::API_KEY_MASKED ] );
		$this->settings->replace( $settings );
	}

	public function timeout(): int {
		return max( 1, min( 10, $this->settings->get_int( 'dadata_api_timeout', 3 ) ) );
	}

	public function count(): int {
		return max( 3, min( 20, $this->settings->get_int( 'dadata_suggestions_count', 10 ) ) );
	}

	public function encryption_ready(): bool {
		return $this->encryption->has_configured_key();
	}
}
