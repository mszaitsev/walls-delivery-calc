<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryCredentials {
	public function __construct( private SettingsRepository $settings, private EncryptionService $encryption ) {}

	public function client_id(): string { return $this->sanitize( $this->settings->get_string( OzonDeliverySettings::CLIENT_ID_KEY, '' ) ); }
	public function client_secret(): string {
		$encrypted = $this->settings->get_string( OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY, '' );
		return '' === trim( $encrypted ) ? '' : (string) ( $this->encryption->decrypt( $encrypted ) ?? '' );
	}
	public function has_client_secret(): bool { return '' !== trim( $this->settings->get_string( OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY, '' ) ); }
	public function is_complete(): bool { return '' !== $this->client_id() && '' !== $this->client_secret(); }
	public function encryption_ready(): bool { return $this->encryption->has_configured_key(); }
	public function account_fingerprint(): string { return '' === $this->client_id() ? '' : hash( 'sha256', $this->client_id() ); }

	/** @param array<string,mixed> $input */
	public function save_from_admin( array $input ): bool {
		$client_id = $this->sanitize( (string) ( $input[ OzonDeliverySettings::CLIENT_ID_KEY ] ?? '' ) );
		if ( ! empty( $input['ozon_delivery_clear_client_secret'] ) ) {
			$this->settings->set( OzonDeliverySettings::CLIENT_ID_KEY, $client_id );
			$this->clear_client_secret();
			return true;
		}
		$secret = trim( (string) ( $input['ozon_delivery_client_secret'] ?? '' ) );
		if ( function_exists( 'wp_unslash' ) ) { $secret = trim( (string) wp_unslash( $secret ) ); }
		if ( '' === $secret ) { $this->settings->set( OzonDeliverySettings::CLIENT_ID_KEY, $client_id ); return true; }
		if ( ! $this->encryption_ready() ) { return false; }
		$encrypted = $this->encryption->encrypt( $secret );
		if ( '' === $encrypted ) { return false; }
		$this->settings->set( OzonDeliverySettings::CLIENT_ID_KEY, $client_id );
		$this->settings->set( OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY, $encrypted );
		return true;
	}
	public function clear_client_secret(): void { $this->settings->set( OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY, '' ); }
	private function sanitize( string $value ): string {
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value ) : trim( $value );
		return trim( $value );
	}
}
