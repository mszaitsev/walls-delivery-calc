<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class RussianPostApiClient {
	public function __construct(
		private RussianPostSettings $settings,
		private Logger $logger
	) {
	}

	/**
	 * @param array<string,scalar> $params
	 * @return array<string,mixed>
	 */
	public function calculate_tariff( array $params ): array {
		$settings = $this->settings->all();
		$url      = $this->url( (string) $settings['api_endpoint'], $params );

		$this->debug( 'Russian Post tariff request prepared.', array( 'endpoint' => (string) $settings['api_endpoint'], 'params' => $this->sanitize_params( $params ) ) );

		if ( ! function_exists( 'wp_remote_get' ) ) {
			return $this->error( 'wp_http_unavailable', 'WordPress HTTP API is unavailable.', $url, $params );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => max( 1, (int) $settings['timeout'] ),
				'headers' => array_filter(
					array(
						'Accept' => 'application/json',
						'Authorization' => '' !== trim( (string) $settings['api_token'] ) ? 'Bearer ' . trim( (string) $settings['api_token'] ) : '',
					)
				),
			)
		);

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return $this->error( 'http_error', $response->get_error_message(), $url, $params );
		}

		$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
		$decoded = json_decode( $body, true );

		$this->debug( 'Russian Post tariff response received.', array( 'http_code' => $code, 'raw_response' => $body, 'parsed_response' => is_array( $decoded ) ? $decoded : array() ) );

		if ( $code < 200 || $code >= 300 ) {
			return $this->error( 'http_status_' . $code, 'Russian Post API returned HTTP ' . $code . '.', $url, $params, $code, is_array( $decoded ) ? $decoded : array( 'body' => $body ) );
		}

		if ( ! is_array( $decoded ) ) {
			return $this->error( 'invalid_json', 'Russian Post API returned invalid JSON.', $url, $params, $code, array( 'body' => $body ) );
		}

		if ( isset( $decoded['error'] ) || isset( $decoded['errors'] ) ) {
			return $this->error( 'api_error', $this->extract_error_message( $decoded ), $url, $params, $code, $decoded );
		}

		return array(
			'success'   => true,
			'http_code' => $code,
			'url'       => $url,
			'params'    => $this->sanitize_params( $params ),
			'raw'       => $decoded,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function fetch_countries(): array {
		$settings = $this->settings->all();
		$url      = (string) $settings['country_endpoint'];
		$this->debug( 'Russian Post countries request prepared.', array( 'endpoint' => $url ) );

		if ( ! function_exists( 'wp_remote_get' ) ) {
			return $this->error( 'wp_http_unavailable', 'WordPress HTTP API is unavailable.', $url, array() );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => max( 1, (int) $settings['timeout'] ),
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return $this->error( 'http_error', $response->get_error_message(), $url, array() );
		}

		$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
		$decoded = json_decode( $body, true );
		$this->debug( 'Russian Post countries response received.', array( 'http_code' => $code, 'raw_response' => $body, 'parsed_response' => is_array( $decoded ) ? $decoded : array() ) );

		if ( $code < 200 || $code >= 300 ) {
			return $this->error( 'http_status_' . $code, 'Russian Post countries API returned HTTP ' . $code . '.', $url, array(), $code, is_array( $decoded ) ? $decoded : array( 'body' => $body ) );
		}

		if ( ! is_array( $decoded ) ) {
			return $this->error( 'invalid_json', 'Russian Post countries API returned invalid JSON.', $url, array(), $code, array( 'body' => $body ) );
		}

		return array( 'success' => true, 'http_code' => $code, 'url' => $url, 'raw' => $decoded );
	}

	/**
	 * @param array<string,scalar> $params
	 */
	private function url( string $endpoint, array $params ): string {
		return function_exists( 'add_query_arg' ) ? add_query_arg( $params, $endpoint ) : $endpoint . '?' . http_build_query( $params );
	}

	/**
	 * @param array<string,scalar> $params
	 * @return array<string,scalar>
	 */
	private function sanitize_params( array $params ): array {
		unset( $params['token'], $params['api_token'], $params['password'], $params['Authorization'] );

		return $params;
	}

	/**
	 * @param array<string,scalar> $params
	 * @param array<string,mixed>  $raw
	 * @return array<string,mixed>
	 */
	private function error( string $code, string $message, string $url, array $params, int $http_code = 0, array $raw = array() ): array {
		$result = array(
			'success'       => false,
			'error_code'    => $code,
			'error_message' => $message,
			'http_code'     => $http_code,
			'url'           => $url,
			'params'        => $this->sanitize_params( $params ),
			'raw'           => $raw,
		);
		$this->debug( 'Russian Post API error.', $result );

		return $result;
	}

	/**
	 * @param array<string,mixed> $response
	 */
	private function extract_error_message( array $response ): string {
		foreach ( array( 'error', 'message', 'error_message' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_scalar( $response[ $key ] ) ) {
				return (string) $response[ $key ];
			}
		}

		return 'Russian Post API returned an error.';
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function debug( string $message, array $context = array() ): void {
		if ( $this->settings->debug_enabled() ) {
			$this->logger->debug( $message, $context );
		}
	}
}
