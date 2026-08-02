<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Api;

defined( 'ABSPATH' ) || exit;

final class WpPekHttpClient implements PekHttpClientInterface {
	/** @param array<string,mixed> $args */
	public function request( string $method, string $url, array $args ): array {
		$method = strtoupper( trim( $method ) );
		if ( ! in_array( $method, array( 'GET', 'POST' ), true ) ) {
			return array(
				'error' => true,
				'message' => 'Unsupported PEK HTTP method.',
			);
		}
		$args['method'] = $method;
		$response = wp_remote_request( $url, $args );
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return array(
				'error' => true,
				'message' => method_exists( $response, 'get_error_message' ) ? (string) $response->get_error_message() : 'PEK transport error.',
			);
		}

		return array(
			'status' => (int) wp_remote_retrieve_response_code( $response ),
			'body' => (string) wp_remote_retrieve_body( $response ),
			'headers' => (array) wp_remote_retrieve_headers( $response ),
		);
	}
}
