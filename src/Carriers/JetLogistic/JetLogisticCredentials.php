<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class JetLogisticCredentials {
	private const ACCESS_TOKEN_KEY = 'jet_logistic_access_token';

	public function __construct(
		private SettingsRepository $settings,
		private EncryptionService $encryption
	) {
	}

	public function access_token(): string {
		$encrypted = $this->settings->get_string( self::ACCESS_TOKEN_KEY, '' );
		if ( '' === trim( $encrypted ) ) {
			return '';
		}

		return (string) $this->encryption->decrypt( $encrypted );
	}

	public function save_access_token( string $token ): void {
		$token = trim( $token );
		$this->settings->set( self::ACCESS_TOKEN_KEY, '' === $token ? '' : $this->encryption->encrypt( $token ) );
	}

	public function has_access_token(): bool {
		return '' !== trim( $this->settings->get_string( self::ACCESS_TOKEN_KEY, '' ) );
	}

	public function clear_access_token(): void {
		$this->settings->set( self::ACCESS_TOKEN_KEY, '' );
	}
}
