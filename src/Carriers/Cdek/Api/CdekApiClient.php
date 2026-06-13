<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Cdek\Api;

use WallsShop\WDC\Carriers\Cdek\CdekSettings;

defined( 'ABSPATH' ) || exit;

final class CdekApiClient {
	public function __construct(
		private CdekOAuthTokenService $tokens,
		private ?CdekSettings $settings = null,
		private ?CdekHttpClientInterface $http = null
	) {
	}

	public function getToken(): string {
		return $this->tokens->getToken();
	}

	public function clearTokenCache(): void {
		$this->tokens->clearTokenCache();
	}

	public function clearAllTokenCaches(): void {
		$this->tokens->clearAllTokenCaches();
	}

	/**
	 * @return array{success:bool,message:string}
	 */
	public function checkConnection(): array {
		return $this->tokens->checkConnection();
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function tariffList( array $payload ): array {
		return $this->authorizedJsonRequest( 'POST', '/v2/calculator/tarifflist', $payload );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function allTariffs(): array {
		return $this->authorizedJsonRequest( 'GET', '/v2/calculator/alltariffs' );
	}

	/**
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 */
	public function cities( array $query ): array {
		return $this->authorizedJsonRequest( 'GET', '/v2/location/cities', array(), $query );
	}

	/**
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 */
	public function deliveryPoints( array $query ): array {
		return $this->authorizedJsonRequest( 'GET', '/v2/deliverypoints', array(), $query );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function registerOrder( array $payload ): array {
		return $this->authorizedJsonRequest( 'POST', '/v2/orders', $payload );
	}

	/**
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 */
	public function orderByNumber( array $query ): array {
		return $this->authorizedJsonRequest( 'GET', '/v2/orders', array(), $query );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function orderByUuid( string $uuid ): array {
		return $this->authorizedJsonRequest( 'GET', '/v2/orders/' . rawurlencode( $uuid ) );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 */
	private function authorizedJsonRequest( string $method, string $path, array $payload = array(), array $query = array() ): array {
		if ( ! $this->settings instanceof CdekSettings || ! $this->http instanceof CdekHttpClientInterface ) {
			throw new CdekApiException( 'CDEK API client is not configured for runtime requests.' );
		}

		$url = rtrim( $this->settings->base_url(), '/' ) . $path;
		if ( array() !== $query ) {
			$url .= '?' . http_build_query( $query, '', '&' );
		}

		$args = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->tokens->getToken(),
				'Accept' => 'application/json',
			),
		);
		if ( 'GET' !== strtoupper( $method ) ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body'] = ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload ) ) ?: '{}';
		}

		$response = $this->http->request( $method, $url, $args );
		$data = $response->json();
		if ( $response->status_code < 200 || $response->status_code >= 300 ) {
			$message = $this->extractErrorMessage( $data, $response->status_code );
			throw new CdekApiException(
				$this->safeMessage( $message, $response->status_code ),
				array(
					'http_code' => $response->status_code,
					'endpoint' => $path,
					'request' => $this->sanitizeForDiagnostics( array() !== $payload ? $payload : $query ),
					'response' => $this->sanitizeForDiagnostics( array() !== $data ? $data : array( '_raw' => $this->safeMessage( $response->body, $response->status_code ) ) ),
					'cdek_error_code' => $this->extractErrorCode( $data ),
					'cdek_error_message' => $this->safeMessage( $message, $response->status_code ),
				)
			);
		}

		return array(
			'http_code' => $response->status_code,
			'body' => $data,
		);
	}

	private function safeMessage( string $message, int $statusCode ): string {
		$message = trim( preg_replace( '/\s+/', ' ', $message ) ?? $message );
		if ( '' === $message ) {
			$message = 'HTTP ' . $statusCode;
		}

		return substr( $message, 0, 180 );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function extractErrorMessage( array $data, int $statusCode ): string {
		$errors = is_array( $data['errors'] ?? null ) ? $data['errors'] : array();
		$first_error = is_array( $errors[0] ?? null ) ? $errors[0] : array();
		$message = (string) ( $data['message'] ?? $data['error_description'] ?? $first_error['message'] ?? $data['error'] ?? '' );

		return '' !== trim( $message ) ? $message : 'HTTP ' . $statusCode;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function extractErrorCode( array $data ): string {
		$errors = is_array( $data['errors'] ?? null ) ? $data['errors'] : array();
		$first_error = is_array( $errors[0] ?? null ) ? $errors[0] : array();

		return (string) ( $data['code'] ?? $data['error_code'] ?? $first_error['code'] ?? $data['error'] ?? '' );
	}

	/**
	 * @param mixed $value
	 * @return mixed
	 */
	private function sanitizeForDiagnostics( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $item ) {
				$key_text = strtolower( (string) $key );
				if ( in_array( $key_text, array( 'access_token', 'authorization', 'client_secret', 'secure_password', 'account' ), true ) ) {
					$sanitized[ $key ] = '[redacted]';
					continue;
				}
				$sanitized[ $key ] = $this->sanitizeForDiagnostics( $item );
			}

			return $sanitized;
		}

		if ( is_string( $value ) && strlen( $value ) > 1000 ) {
			return substr( $value, 0, 1000 ) . '...';
		}

		return $value;
	}
}
