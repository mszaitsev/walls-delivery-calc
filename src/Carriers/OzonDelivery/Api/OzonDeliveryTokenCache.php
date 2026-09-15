<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Api;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryTokenCache {
	private const SAFETY_MARGIN_SECONDS = 60;

	public function __construct( private EncryptionService $encryption ) {}

	public function get( string $credential_fingerprint ): ?OzonDeliveryToken {
		if ( '' === $credential_fingerprint || ! $this->encryption->has_configured_key() || ! function_exists( 'get_transient' ) ) {
			return null;
		}

		$value = get_transient( $this->key( $credential_fingerprint ) );
		if ( ! is_array( $value ) ) {
			return null;
		}

		$expires_at = isset( $value['expires_at'] ) ? (int) $value['expires_at'] : 0;
		$encrypted = isset( $value['encrypted_access_token'] ) && is_string( $value['encrypted_access_token'] ) ? $value['encrypted_access_token'] : '';
		$token = '' === $encrypted ? null : $this->encryption->decrypt( $encrypted );
		if ( $expires_at <= time() + self::SAFETY_MARGIN_SECONDS || ! is_string( $token ) || '' === trim( $token ) ) {
			$this->clear( $credential_fingerprint );
			return null;
		}

		$scope = is_array( $value['scope'] ?? null ) ? $this->scope( $value['scope'] ) : array();
		$token_type = $this->token_type( $value['token_type'] ?? '' );
		return new OzonDeliveryToken( $token, $expires_at, $scope, $token_type );
	}

	public function store( string $credential_fingerprint, OzonDeliveryToken $token ): bool {
		if ( '' === $credential_fingerprint || null === $token->expires_at || $token->expires_at <= time() + self::SAFETY_MARGIN_SECONDS || ! $this->encryption->has_configured_key() || ! function_exists( 'set_transient' ) ) {
			$this->clear( $credential_fingerprint );
			return false;
		}

		$encrypted = $this->encryption->encrypt( $token->access_token );
		if ( '' === $encrypted ) {
			return false;
		}

		return (bool) set_transient(
			$this->key( $credential_fingerprint ),
			array(
				'encrypted_access_token' => $encrypted,
				'expires_at' => $token->expires_at,
				'scope' => $this->scope( $token->scope ),
				'token_type' => $this->token_type( $token->token_type ),
			),
			$token->expires_at - time()
		);
	}

	public function clear( string $credential_fingerprint ): void {
		if ( '' !== $credential_fingerprint && function_exists( 'delete_transient' ) ) {
			delete_transient( $this->key( $credential_fingerprint ) );
		}
	}

	private function key( string $credential_fingerprint ): string {
		return 'wdc_ozon_delivery_token_' . substr( hash( 'sha256', $credential_fingerprint ), 0, 32 );
	}

	/** @param array<mixed> $scope @return array<int,string> */
	private function scope( array $scope ): array {
		$result = array();
		foreach ( array_slice( $scope, 0, 10 ) as $value ) {
			$value = is_string( $value ) ? trim( $value ) : '';
			if ( '' !== $value && 1 === preg_match( '/^[A-Za-z0-9._-]{1,100}$/', $value ) ) {
				$result[] = $value;
			}
		}
		return array_values( array_unique( $result ) );
	}

	private function token_type( mixed $value ): string {
		$value = is_string( $value ) ? strtolower( trim( $value ) ) : '';
		return 1 === preg_match( '/^[a-z0-9._-]{1,60}$/', $value ) ? $value : '';
	}
}
