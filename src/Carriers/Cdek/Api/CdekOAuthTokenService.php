<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Cdek\Api;

use WallsShop\WDC\Carriers\Cdek\CdekSettings;

defined( 'ABSPATH' ) || exit;

final class CdekOAuthTokenService {
	private const SAFETY_MARGIN_SECONDS = 60;

	public function __construct(
		private CdekSettings $settings,
		private CdekHttpClientInterface $http
	) {
	}

	public function getToken(): string {
		$cached = $this->cached_token();
		if ( '' !== $cached ) {
			return $cached;
		}

		$credentials = $this->settings->credentials();
		if ( ! $credentials->is_complete() ) {
			throw new CdekApiException( 'Заполните Account и Secure password СДЭК.' );
		}

		$response = $this->http->request(
			'POST',
			$this->settings->base_url() . '/v2/oauth/token',
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body' => http_build_query(
					array(
						'grant_type' => 'client_credentials',
						'client_id' => $credentials->account,
						'client_secret' => $credentials->secure_password,
					),
					'',
					'&'
				),
			)
		);

		$data = $response->json();
		if ( $response->status_code < 200 || $response->status_code >= 300 ) {
			$message = (string) ( $data['message'] ?? $data['error_description'] ?? $data['error'] ?? 'OAuth request failed.' );
			throw new CdekApiException( $this->safe_message( $message, $response->status_code ) );
		}

		$token = trim( (string) ( $data['access_token'] ?? '' ) );
		if ( '' === $token ) {
			throw new CdekApiException( 'СДЭК OAuth не вернул access_token.' );
		}

		$expires_in = max( 0, (int) ( $data['expires_in'] ?? 0 ) );
		$this->store_token( $token, $expires_in );

		return $token;
	}

	public function clearTokenCache(): void {
		$key = $this->settings->token_cache_key();
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $key );
			return;
		}

		if ( function_exists( 'delete_option' ) ) {
			delete_option( $key );
		}
	}

	/**
	 * @return array{success:bool,message:string}
	 */
	public function checkConnection(): array {
		$this->clearTokenCache();
		$this->getToken();

		return array(
			'success' => true,
			'message' => 'Подключение к СДЭК успешно проверено.',
		);
	}

	private function cached_token(): string {
		$key = $this->settings->token_cache_key();
		$payload = function_exists( 'get_transient' ) ? get_transient( $key ) : ( function_exists( 'get_option' ) ? get_option( $key, null ) : null );
		if ( ! is_array( $payload ) ) {
			return '';
		}

		$expires_at = (int) ( $payload['expires_at'] ?? 0 );
		$token = trim( (string) ( $payload['access_token'] ?? '' ) );

		return '' !== $token && $expires_at > time() ? $token : '';
	}

	private function store_token( string $token, int $expires_in ): void {
		$ttl = $expires_in - self::SAFETY_MARGIN_SECONDS;
		if ( $ttl <= 0 ) {
			$this->clearTokenCache();
			return;
		}
		$payload = array(
			'access_token' => $token,
			'expires_at' => time() + $ttl,
		);
		$key = $this->settings->token_cache_key();

		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $payload, $ttl );
			return;
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( $key, $payload, false );
		}
	}

	private function safe_message( string $message, int $status_code ): string {
		$message = trim( preg_replace( '/\s+/', ' ', $message ) ?? $message );
		if ( '' === $message ) {
			$message = 'HTTP ' . $status_code;
		}

		return substr( $message, 0, 180 );
	}
}
