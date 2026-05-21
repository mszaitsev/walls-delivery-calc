<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Fias;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class FiasCredentials {
	public const TOKEN_ENCRYPTED_KEY = 'fias_api_token_encrypted';
	public const TOKEN_MASKED_KEY = 'fias_api_token_masked';

	public function __construct(
		private SettingsRepository $settings,
		private EncryptionService $encryption
	) {
	}

	public function save_token( string $token ): bool {
		$token = trim( $token );
		if ( '' === $token ) {
			$this->clear_token();
			return true;
		}

		if ( ! $this->encryption->has_configured_key() ) {
			return false;
		}

		$encrypted = $this->encryption->encrypt( $token );
		if ( '' === $encrypted ) {
			return false;
		}

		$settings = $this->settings->all();
		$settings[ self::TOKEN_ENCRYPTED_KEY ] = $encrypted;
		$settings[ self::TOKEN_MASKED_KEY ] = $this->mask_token( $token );

		return $this->settings->replace( $settings );
	}

	public function clear_token(): bool {
		$settings = $this->settings->all();
		unset( $settings[ self::TOKEN_ENCRYPTED_KEY ], $settings[ self::TOKEN_MASKED_KEY ] );

		return $this->settings->replace( $settings );
	}

	public function has_token(): bool {
		return '' !== $this->settings->get_string( self::TOKEN_ENCRYPTED_KEY, '' );
	}

	public function masked_token(): string {
		$masked = $this->settings->get_string( self::TOKEN_MASKED_KEY, '' );
		return '' !== $masked ? $masked : ( $this->has_token() ? '********' : '' );
	}

	public function encryption_ready(): bool {
		return $this->encryption->has_configured_key();
	}

	private function mask_token( string $token ): string {
		return '********';
	}
}
