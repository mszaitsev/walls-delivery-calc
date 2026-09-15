<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Api;

defined( 'ABSPATH' ) || exit;

final class WpJetLogisticHttpClient implements JetLogisticHttpClientInterface {
	public function post_json( string $url, array $payload, int $timeout ): array {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => $timeout,
				'redirection' => 0,
				'sslverify' => true,
				'headers' => array( 'Content-Type' => 'application/json; charset=UTF-8' ),
				'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			)
		);
		if ( is_wp_error( $response ) ) {
			$error_code = method_exists( $response, 'get_error_code' ) ? (string) $response->get_error_code() : '';
			throw new JetLogisticApiException(
				'Jet Logistic API transport failed.',
				array( 'error_code' => $this->transport_error_code( $error_code ) )
			);
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}

	private function transport_error_code( string $wp_error_code ): string {
		return str_contains( strtolower( $wp_error_code ), 'timeout' ) ? 'jet_http_timeout' : 'jet_http_network_error';
	}
}
