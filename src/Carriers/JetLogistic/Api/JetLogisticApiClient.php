<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Api;

use WallsShop\WDC\Carriers\JetLogistic\JetLogisticCredentials;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;

defined( 'ABSPATH' ) || exit;

final class JetLogisticApiClient {
	public const BASE_URL = 'https://jet7777.ru/cabinet/api/';
	public const METHOD_CALC_TRANSPORT = 'calc_transport';
	public const METHOD_STATUS = 'status';

	public function __construct(
		private JetLogisticHttpClientInterface $http,
		private JetLogisticSettings $settings,
		private ?JetLogisticCredentials $credentials = null
	) {
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function calc_transport( array $payload ): array {
		return $this->request( self::METHOD_CALC_TRANSPORT, $payload );
	}

	/** @return array<string,mixed> */
	public function status( string $cargo_id ): array {
		return $this->request(
			self::METHOD_STATUS,
			array(
				'access_token' => $this->credentials instanceof JetLogisticCredentials ? $this->credentials->access_token() : '',
				'id' => $cargo_id,
			)
		);
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	private function request( string $method, array $payload ): array {
		$response = $this->http->post_json( self::BASE_URL . $method, $payload, $this->settings->request_timeout() );
		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			throw new JetLogisticApiException(
				'Jet Logistic API HTTP failure.',
				array(
					'error_code' => $this->http_error_code( (int) $response['status'] ),
					'endpoint' => $method,
					'method' => 'POST',
					'http_status' => (int) $response['status'],
				)
			);
		}
		$decoded = json_decode( $response['body'], true );
		if ( ! is_array( $decoded ) ) {
			throw new JetLogisticApiException( 'Jet Logistic API returned malformed JSON.', array( 'error_code' => 'jet_invalid_json', 'endpoint' => $method, 'method' => 'POST', 'http_status' => (int) $response['status'] ) );
		}
		if ( array_key_exists( 'success', $decoded ) && ! (bool) $decoded['success'] ) {
			throw new JetLogisticApiException( 'Jet Logistic API returned an error.', array( 'error_code' => 'jet_api_error', 'endpoint' => $method, 'method' => 'POST', 'http_status' => (int) $response['status'], 'api_error' => $this->safe_error( $decoded['error'] ?? $decoded['message'] ?? '' ) ) );
		}
		$result = $decoded['result'] ?? $decoded;
		if ( self::METHOD_STATUS === $method && is_string( $result ) ) {
			return array( 'logs' => $this->logs_from_text( $result ) );
		}

		return is_array( $result ) ? $result : array();
	}

	/** @return array<int,array{date:string,message:string}> */
	private function logs_from_text( string $body ): array {
		$logs = array();
		$current_date = '';
		foreach ( preg_split( '/\R/u', $body ) ?: array() as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line ) {
				continue;
			}
			if ( preg_match( '/^(\d{2}\.\d{2}\.\d{4}(?:\s+\d{2}:\d{2}(?::\d{2})?)?|\d{4}-\d{2}-\d{2}(?:\s+\d{2}:\d{2}(?::\d{2})?)?)\s*:?\s*(.*)$/u', $line, $matches ) ) {
				$current_date = trim( $matches[1] );
				$message = trim( $matches[2] );
				if ( '' === $message ) {
					continue;
				}
				$logs[] = array( 'date' => $current_date, 'message' => $message );
				continue;
			}
			$logs[] = array( 'date' => $current_date, 'message' => $line );
		}

		return $logs;
	}

	private function http_error_code( int $status ): string {
		return match ( $status ) {
			401 => 'jet_http_401',
			403 => 'jet_http_403',
			default => 'jet_http_' . $status,
		};
	}

	private function safe_error( mixed $error ): string {
		if ( is_scalar( $error ) ) {
			return str_replace( array( "\r", "\n" ), ' ', (string) $error );
		}

		return '';
	}
}
