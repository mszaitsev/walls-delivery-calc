<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryTokenCache;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryCredentials {
	public function __construct( private SettingsRepository $settings, private EncryptionService $encryption, private ?OzonDeliveryTokenCache $token_cache = null ) {}

	public function client_id(): string { return $this->sanitize( $this->settings->get_string( OzonDeliverySettings::CLIENT_ID_KEY, '' ) ); }
	public function client_secret(): string {
		$encrypted = $this->settings->get_string( OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY, '' );
		return '' === trim( $encrypted ) ? '' : (string) ( $this->encryption->decrypt( $encrypted ) ?? '' );
	}
	public function has_client_secret(): bool { return '' !== trim( $this->settings->get_string( OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY, '' ) ); }
	public function is_complete(): bool { return '' !== $this->client_id() && '' !== $this->client_secret(); }
	public function encryption_ready(): bool { return $this->encryption->has_configured_key(); }
	public function account_fingerprint(): string {
		$encrypted_secret = $this->settings->get_string( OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY, '' );
		return '' === $this->client_id() || '' === trim( $encrypted_secret ) ? '' : hash( 'sha256', $this->client_id() . "\0" . $encrypted_secret );
	}

	/** @param array<string,mixed> $input */
	public function save_from_admin( array $input ): bool {
		$previous_fingerprint = $this->account_fingerprint();
		$client_id = $this->sanitize( (string) ( $input[ OzonDeliverySettings::CLIENT_ID_KEY ] ?? '' ) );
		if ( ! empty( $input['ozon_delivery_clear_client_secret'] ) ) {
			$this->settings->set( OzonDeliverySettings::CLIENT_ID_KEY, $client_id );
			$this->clear_client_secret( $previous_fingerprint );
			return true;
		}
		$secret = trim( (string) ( $input['ozon_delivery_client_secret'] ?? '' ) );
		if ( function_exists( 'wp_unslash' ) ) { $secret = trim( (string) wp_unslash( $secret ) ); }
		if ( '' === $secret ) { $this->settings->set( OzonDeliverySettings::CLIENT_ID_KEY, $client_id ); $this->invalidate_changed_cache( $previous_fingerprint ); return true; }
		if ( ! $this->encryption_ready() ) { return false; }
		$encrypted = $this->encryption->encrypt( $secret );
		if ( '' === $encrypted ) { return false; }
		$this->settings->set( OzonDeliverySettings::CLIENT_ID_KEY, $client_id );
		$this->settings->set( OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY, $encrypted );
		$this->invalidate_changed_cache( $previous_fingerprint );
		return true;
	}
	public function clear_client_secret( string $previous_fingerprint = '' ): void { $previous_fingerprint = '' !== $previous_fingerprint ? $previous_fingerprint : $this->account_fingerprint(); $this->settings->set( OzonDeliverySettings::CLIENT_SECRET_ENCRYPTED_KEY, '' ); $this->token_cache?->clear( $previous_fingerprint ); }
	private function invalidate_changed_cache( string $previous_fingerprint ): void { if ( $previous_fingerprint !== $this->account_fingerprint() ) { $this->token_cache?->clear( $previous_fingerprint ); } }
	private function sanitize( string $value ): string {
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value ) : trim( $value );
		return trim( $value );
	}
}
