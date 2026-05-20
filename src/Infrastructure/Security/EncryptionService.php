<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Security;

defined( 'ABSPATH' ) || exit;

final class EncryptionService {
	private const CIPHER = 'aes-256-gcm';

	public function encrypt( string $plain_text ): string {
		$iv  = random_bytes( 12 );
		$tag = '';
		$key = $this->key();

		$cipher_text = openssl_encrypt( $plain_text, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher_text ) {
			return '';
		}

		$payload = array(
			'iv'   => base64_encode( $iv ),
			'tag'  => base64_encode( $tag ),
			'data' => base64_encode( $cipher_text ),
		);
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload );

		return is_string( $json ) ? base64_encode( $json ) : '';
	}

	public function decrypt( string $payload ): ?string {
		$decoded = base64_decode( $payload, true );
		if ( false === $decoded ) {
			return null;
		}

		$data = json_decode( $decoded, true );
		if ( ! is_array( $data ) || ! isset( $data['iv'], $data['tag'], $data['data'] ) ) {
			return null;
		}

		$iv          = base64_decode( (string) $data['iv'], true );
		$tag         = base64_decode( (string) $data['tag'], true );
		$cipher_text = base64_decode( (string) $data['data'], true );

		if ( false === $iv || false === $tag || false === $cipher_text ) {
			return null;
		}

		$plain_text = openssl_decrypt( $cipher_text, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plain_text ? null : $plain_text;
	}

	private function key(): string {
		if ( defined( 'WDC_SECRET_KEY' ) && is_string( WDC_SECRET_KEY ) && '' !== WDC_SECRET_KEY ) {
			return hash( 'sha256', WDC_SECRET_KEY, true );
		}

		$material = '';
		foreach ( array( 'auth', 'secure_auth', 'logged_in', 'nonce' ) as $scheme ) {
			if ( function_exists( 'wp_salt' ) ) {
				$material .= wp_salt( $scheme );
			}
		}

		if ( '' === $material ) {
			$material = ( defined( 'AUTH_KEY' ) ? (string) AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? (string) SECURE_AUTH_KEY : '' );
		}

		return hash( 'sha256', $material, true );
	}
}
