<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Api;

use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;

defined( 'ABSPATH' ) || exit;

final class JetLogisticApiClient {
	private const BASE_URL = 'https://jet7777.ru/cabinet/api/';

	public function __construct(
		private JetLogisticHttpClientInterface $http,
		private JetLogisticSettings $settings
	) {
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function calc_transport( array $payload ): array {
		return $this->request( 'calc_transport', $payload );
	}

	/** @return array<string,mixed> */
	public function status( string $cargo_id ): array {
		return $this->request( 'status', array( 'id' => $cargo_id ) );
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	private function request( string $method, array $payload ): array {
		$response = $this->http->post_json( self::BASE_URL . $method, $payload, $this->settings->request_timeout() );
		if ( $response['status'] < 200 || $response['status'] >= 300 ) {
			throw new JetLogisticApiException( 'Jet Logistic API HTTP failure.', array( 'error_code' => 'jet_http_failure', 'status' => $response['status'] ) );
		}
		$decoded = json_decode( $response['body'], true );
		if ( ! is_array( $decoded ) ) {
			throw new JetLogisticApiException( 'Jet Logistic API returned malformed JSON.', array( 'error_code' => 'jet_malformed_json' ) );
		}
		if ( array_key_exists( 'success', $decoded ) && ! (bool) $decoded['success'] ) {
			throw new JetLogisticApiException( 'Jet Logistic API returned an error.', array( 'error_code' => 'jet_api_error', 'api_error' => $this->safe_error( $decoded['error'] ?? '' ) ) );
		}
		$result = $decoded['result'] ?? $decoded;

		return is_array( $result ) ? $result : array();
	}

	private function safe_error( mixed $error ): string {
		if ( is_scalar( $error ) ) {
			return str_replace( array( "\r", "\n" ), ' ', (string) $error );
		}

		return '';
	}
}
