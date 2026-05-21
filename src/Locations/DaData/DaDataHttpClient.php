<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\DaData;

defined( 'ABSPATH' ) || exit;

final class DaDataHttpClient {
	private const CLEAN_ADDRESS_ENDPOINT = 'https://cleaner.dadata.ru/api/v1/clean/address';

	public function __construct(
		private int $timeout,
		private DaDataLogger $logger
	) {
		$this->timeout = max( 1, min( 10, $timeout ) );
	}

	/**
	 * @return array{success:bool,status_code:int,body:mixed,error_message:string,timeout:bool}
	 */
	public function clean_address( string $address, string $token ): array {
		$this->logger->request_start( array( 'host' => 'cleaner.dadata.ru' ) );

		if ( ! function_exists( 'wp_remote_post' ) ) {
			return $this->failure( 'wp_remote_post is unavailable.', false );
		}

		$body = function_exists( 'wp_json_encode' )
			? wp_json_encode( array( $address ), JSON_UNESCAPED_UNICODE )
			: json_encode( array( $address ), JSON_UNESCAPED_UNICODE );

		if ( ! is_string( $body ) ) {
			return $this->failure( 'DaData JSON encode failed.', false );
		}

		try {
			$response = wp_remote_post(
				self::CLEAN_ADDRESS_ENDPOINT,
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

		$success = $status_code >= 200 && $status_code < 300 && is_array( $parsed ) && isset( $parsed[0] ) && is_array( $parsed[0] );

		return array(
			'success'       => $success,
			'status_code'   => $status_code,
			'body'          => $success ? $parsed[0] : null,
			'error_message' => $success ? '' : 'DaData HTTP status ' . $status_code,
			'timeout'       => false,
		);
	}

	/**
	 * @return array{success:bool,status_code:int,body:mixed,error_message:string,timeout:bool}
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
