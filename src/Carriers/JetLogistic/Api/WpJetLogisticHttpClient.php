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
				'headers' => array( 'Content-Type' => 'application/json; charset=UTF-8' ),
				'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			)
		);
		if ( is_wp_error( $response ) ) {
			throw new JetLogisticApiException( 'Jet Logistic API transport failed.', array( 'error_code' => 'jet_transport_error' ) );
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
		);
	}
}
