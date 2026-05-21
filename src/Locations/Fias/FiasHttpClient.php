<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Fias;

defined( 'ABSPATH' ) || exit;

final class FiasHttpClient {
	public function __construct(
		private int $timeout,
		private FiasLogger $logger
	) {
		$this->timeout = max( 1, $timeout );
	}

	public function get( string $url, array $args = array() ): array {
		return $this->request( 'GET', $url, null, $args );
	}

	public function post( string $url, array $body = array(), array $args = array() ): array {
		return $this->request( 'POST', $url, $body, $args );
	}

	private function request( string $method, string $url, ?array $body, array $args ): array {
		$this->logger->request_start( strtolower( $method ), array( 'host' => (string) ( function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : parse_url( $url, PHP_URL_HOST ) ) ) );

		if ( 'GET' === $method && ! function_exists( 'wp_remote_get' ) ) {
			return $this->failure( 'wp_remote_get is unavailable.', true );
		}

		if ( 'POST' === $method && ! function_exists( 'wp_remote_post' ) ) {
			return $this->failure( 'wp_remote_post is unavailable.', true );
		}

		$request_args = array_merge(
			array(
				'timeout' => $this->timeout,
				'headers' => array( 'Accept' => 'application/json' ),
			),
			$args
		);

		if ( null !== $body ) {
			$request_args['headers']['Content-Type'] = 'application/json; charset=utf-8';
			$request_args['body'] = function_exists( 'wp_json_encode' ) ? wp_json_encode( $body ) : json_encode( $body );
		}

		try {
			$response = 'POST' === $method ? wp_remote_post( $url, $request_args ) : wp_remote_get( $url, $request_args );
		} catch ( \Throwable $exception ) {
			return $this->failure( $exception->getMessage(), $this->is_timeout_message( $exception->getMessage() ) );
		}

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			$message = method_exists( $response, 'get_error_message' ) ? $response->get_error_message() : 'WordPress HTTP error.';
			return $this->failure( $message, $this->is_timeout_message( $message ) );
		}

		if ( ! is_array( $response ) ) {
			return $this->failure( 'Unexpected HTTP response.', false );
		}

		$status_code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : (int) ( $response['response']['code'] ?? 0 );
		$raw_body    = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : (string) ( $response['body'] ?? '' );
		$parsed_body = $this->parse_json( $raw_body );

		$this->logger->response_status( strtolower( $method ), $status_code );

		if ( null === $parsed_body && '' !== trim( $raw_body ) ) {
			$this->logger->parse_error( strtolower( $method ), array( 'status_code' => $status_code ) );
		}

		return array(
			'success'       => $status_code >= 200 && $status_code < 300,
			'status_code'   => $status_code,
			'body'          => null !== $parsed_body ? $parsed_body : $raw_body,
			'error_message' => $status_code >= 200 && $status_code < 300 ? '' : 'FIAS HTTP status ' . $status_code,
			'timeout'       => false,
		);
	}

	private function failure( string $message, bool $timeout ): array {
		if ( $timeout ) {
			$this->logger->timeout( 'http', array( 'error' => $message ) );
		}

		return array(
			'success'       => false,
			'status_code'   => 0,
			'body'          => null,
			'error_message' => $message,
			'timeout'       => $timeout,
		);
	}

	private function parse_json( string $body ): mixed {
		if ( '' === trim( $body ) ) {
			return null;
		}

		$decoded = json_decode( $body, true );
		return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
	}

	private function is_timeout_message( string $message ): bool {
		$message = strtolower( $message );
		return str_contains( $message, 'timeout' ) || str_contains( $message, 'timed out' ) || str_contains( $message, 'cURL error 28' );
	}
}
