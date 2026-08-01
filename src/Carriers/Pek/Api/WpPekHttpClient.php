<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Api;

defined( 'ABSPATH' ) || exit;

final class WpPekHttpClient implements PekHttpClientInterface {
	/** @param array<string,mixed> $args */
	public function post( string $url, array $args ): array {
		$response = wp_remote_post( $url, $args );
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
