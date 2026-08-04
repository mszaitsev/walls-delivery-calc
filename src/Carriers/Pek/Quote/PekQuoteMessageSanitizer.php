<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Quote;

use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekQuoteMessageSanitizer {
	private const DEFAULT_FALLBACK = 'ПЭК вернул ошибку без безопасного описания.';
	private const FIELD_FALLBACK = 'ПЭК вернул ошибку поля без безопасного описания.';

	public function __construct(
		private PekCredentials $credentials,
		private PekSettings $settings
	) {
	}

	public function sanitize( string $message ): string {
		return $this->sanitize_with_fallback( $message, self::DEFAULT_FALLBACK );
	}

	public function sanitize_field_message( string $message ): string {
		return $this->sanitize_with_fallback( $message, self::FIELD_FALLBACK );
	}

	private function sanitize_with_fallback( string $message, string $fallback ): string {
		$message = $this->redact_exact_values( $message );
		$message = $this->redact_key_value_fragments( $message );
		$message = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $message ) ?? $message;
		$message = preg_replace( '/\s+/u', ' ', $message ) ?? $message;
		$message = trim( $message );
		if ( '' === $message || $this->contains_only_redactions( $message ) ) {
			return $fallback;
		}
		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, 500 );
		} else {
			$message = substr( $message, 0, 500 );
		}
		$message = trim( $message );

		return '' !== $message ? $message : $fallback;
	}

	private function contains_only_redactions( string $message ): bool {
		$without_redactions = preg_replace( '/(?:Basic\s+)?\[redacted\]/i', '', $message ) ?? $message;
		$without_redactions = preg_replace( '/[\s,.;:()\[\]\-_=]+/', '', $without_redactions ) ?? $without_redactions;

		return '' === trim( $without_redactions );
	}

	private function redact_exact_values( string $message ): string {
		$login = trim( $this->credentials->login() );
		$api_key = trim( $this->credentials->api_key() );
		if ( '' !== $login && '' !== $api_key ) {
			$message = str_replace( $login . ':' . $api_key, '[redacted]', $message );
			$message = str_replace( base64_encode( $login . ':' . $api_key ), '[redacted]', $message );
		}
		$message = preg_replace( '/Basic\s+[A-Za-z0-9+\/=]+/i', 'Basic [redacted]', $message ) ?? $message;
		foreach ( $this->exact_sensitive_values( $login, $api_key ) as $value ) {
			$message = str_replace( $value, '[redacted]', $message );
		}

		return $message;
	}

	/** @return array<int,string> */
	private function exact_sensitive_values( string $login, string $api_key ): array {
		$values = array();
		if ( '' !== $api_key ) {
			$values[] = $api_key;
		}
		if ( strlen( $login ) >= 3 ) {
			$values[] = $login;
		}
		$client_card = trim( $this->settings->client_card() );
		if ( strlen( $client_card ) >= 4 ) {
			$values[] = $client_card;
		}
		$inn = trim( $this->settings->sender_inn() );
		if ( 1 === preg_match( '/^\d{10,}$/', $inn ) ) {
			$values[] = $inn;
		}
		$kpp = trim( $this->settings->sender_kpp() );
		if ( 1 === preg_match( '/^\d{9,}$/', $kpp ) ) {
			$values[] = $kpp;
		}

		return array_values( array_unique( array_filter( $values, static fn( string $value ): bool => '' !== $value ) ) );
	}

	private function redact_key_value_fragments( string $message ): string {
		$key = '(?:api_key|apikey|api-key|token|password|authorization|login|client_card|clientcard|counterpartClientCard|inn|kpp)';
		$message = preg_replace( '/([?&])' . $key . '=[^&\s]+/i', '$1[redacted]=[redacted]', $message ) ?? $message;
		$message = preg_replace( '/["\']?' . $key . '["\']?\s*[:=]\s*["\'][^"\']*["\']/i', '[redacted]', $message ) ?? $message;
		$message = preg_replace( '/\b' . $key . '\b\s*[:=]\s*[^,\s;&]+/i', '[redacted]', $message ) ?? $message;

		return $message;
	}
}
