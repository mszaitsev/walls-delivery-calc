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
	 * @param array<string,mixed> $query
	 * @return array<string,mixed>
	 */
	public function cities( array $query ): array {
		return $this->authorizedJsonRequest( 'GET', '/v2/location/cities', array(), $query );
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
			$message = (string) ( $data['message'] ?? $data['error_description'] ?? $data['error'] ?? 'CDEK request failed.' );
			throw new CdekApiException( $this->safeMessage( $message, $response->status_code ) );
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
}
