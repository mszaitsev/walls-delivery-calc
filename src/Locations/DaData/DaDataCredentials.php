<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\DaData;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class DaDataCredentials {
	public const TOKEN_ENCRYPTED_KEY = 'dadata_api_token_encrypted';
	public const TOKEN_MASKED_KEY = 'dadata_api_token_masked';

	public function __construct(
		private SettingsRepository $settings,
		private EncryptionService $encryption
	) {
	}

	public function has_token(): bool {
		return '' !== $this->settings->get_string( self::TOKEN_ENCRYPTED_KEY, '' ) && $this->encryption_ready();
	}

	public function token(): string {
		if ( ! $this->encryption_ready() ) {
			return '';
		}

		$plain = $this->encryption->decrypt( $this->settings->get_string( self::TOKEN_ENCRYPTED_KEY, '' ) );
		return is_string( $plain ) ? $plain : '';
	}

	public function masked_token(): string {
		$masked = $this->settings->get_string( self::TOKEN_MASKED_KEY, '' );
		return '' !== $masked ? $masked : ( '' !== $this->settings->get_string( self::TOKEN_ENCRYPTED_KEY, '' ) ? '********' : '' );
	}

	public function save_token( string $plain ): void {
		$this->save_token_value( trim( $plain ) );
	}

	public function clear_token(): void {
		$this->clear_keys( self::TOKEN_ENCRYPTED_KEY, self::TOKEN_MASKED_KEY );
	}

	public function encryption_ready(): bool {
		return $this->encryption->has_configured_key();
	}

	private function save_token_value( string $plain ): void {
		if ( '' === $plain ) {
			$this->clear_token();
			return;
		}

		if ( ! $this->encryption_ready() ) {
			return;
		}

		$encrypted = $this->encryption->encrypt( $plain );
		if ( '' === $encrypted ) {
			return;
		}

		$settings = $this->settings->all();
		$settings[ self::TOKEN_ENCRYPTED_KEY ] = $encrypted;
		$settings[ self::TOKEN_MASKED_KEY ] = '********';
		unset( $settings['dadata_secret_key_encrypted'], $settings['dadata_secret_key_masked'] );

		$this->settings->replace( $settings );
	}

	private function clear_keys( string $encrypted_key, string $masked_key ): void {
		$settings = $this->settings->all();
		unset( $settings[ $encrypted_key ], $settings[ $masked_key ] );
		unset( $settings['dadata_secret_key_encrypted'], $settings['dadata_secret_key_masked'] );

		$this->settings->replace( $settings );
	}
}
