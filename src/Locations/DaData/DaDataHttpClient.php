<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\DaData;

defined( 'ABSPATH' ) || exit;

final class DaDataHttpClient {
	private const SUGGEST_ADDRESS_ENDPOINT = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';

	public function __construct(
		private int $timeout,
		private DaDataLogger $logger
	) {
		$this->timeout = max( 1, min( 10, $timeout ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function clean_address( string $address, string $token ): array {
		$this->logger->request_start( array( 'host' => 'suggestions.dadata.ru', 'endpoint' => 'suggest/address' ) );

		if ( ! function_exists( 'wp_remote_post' ) ) {
			return $this->failure( 'wp_remote_post is unavailable.', false );
		}

		$body = function_exists( 'wp_json_encode' )
			? wp_json_encode( array( 'query' => $address, 'count' => 1 ), JSON_UNESCAPED_UNICODE )
			: json_encode( array( 'query' => $address, 'count' => 1 ), JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $body ) ) {
			return $this->failure( 'DaData JSON encode failed.', false );
		}

		try {
			$response = wp_remote_post(
				self::SUGGEST_ADDRESS_ENDPOINT,
				array(
					'timeout' => $this->timeout,
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
						'Authorization' => 'Token ' . $token,
					),
					'body' => $body,
				)
			);
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
		$parsed      = $this->parse_json( $raw_body );

		$this->logger->response_status( $status_code );

		if ( null === $parsed && '' !== trim( $raw_body ) ) {
			$this->logger->parse_error( array( 'status_code' => $status_code ) );
		}

		$suggestions = is_array( $parsed ) && is_array( $parsed['suggestions'] ?? null ) ? $parsed['suggestions'] : array();
		$suggestions_count = count( $suggestions );
		$first_suggestion = is_array( $suggestions[0] ?? null ) ? $suggestions[0] : null;
		$success = $status_code >= 200 && $status_code < 300 && is_array( $first_suggestion );

		if ( $status_code >= 200 && $status_code < 300 && ! $success ) {
			return array(
				'success'             => false,
				'status_code'         => $status_code,
				'body'                => null,
				'error_message'       => 'DaData returned no suggestions.',
				'error_code'          => 'dadata_no_suggestions',
				'timeout'             => false,
				'endpoint'            => 'suggest/address',
				'suggestions_count'   => $suggestions_count,
				'first_suggestion_value' => '',
			);
		}

		return array(
			'success'       => $success,
			'status_code'   => $status_code,
			'body'          => $success ? $first_suggestion : null,
			'error_message' => $success ? '' : 'DaData HTTP status ' . $status_code,
			'error_code'    => $success ? '' : 'dadata_api_failed',
			'timeout'       => false,
			'endpoint'      => 'suggest/address',
			'suggestions_count' => $suggestions_count,
			'first_suggestion_value' => is_array( $first_suggestion ) ? (string) ( $first_suggestion['value'] ?? '' ) : '',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function failure( string $message, bool $timeout ): array {
		if ( $timeout ) {
			$this->logger->timeout( array( 'error' => $message ) );
		} else {
			$this->logger->failure( array( 'error' => $message ) );
		}

		return array(
			'success'       => false,
			'status_code'   => 0,
			'body'          => null,
			'error_message' => $message,
			'error_code'    => $timeout ? 'dadata_timeout' : 'dadata_api_failed',
			'timeout'       => $timeout,
			'endpoint'      => 'suggest/address',
			'suggestions_count' => 0,
			'first_suggestion_value' => '',
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
