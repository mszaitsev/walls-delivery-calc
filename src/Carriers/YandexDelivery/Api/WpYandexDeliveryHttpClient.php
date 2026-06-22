<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Api;

defined( 'ABSPATH' ) || exit;

final class WpYandexDeliveryHttpClient implements YandexDeliveryHttpClientInterface {
	public function __construct(
		private int $timeout = 20
	) {
	}

	/** @param array<string,mixed> $args */
	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$request = array_merge(
			array(
				'method' => strtoupper( $method ),
				'timeout' => $this->timeout,
			),
			$args
		);

		$response = wp_remote_request( $url, $request );
		if ( is_wp_error( $response ) ) {
			throw new YandexDeliveryApiException( $response->get_error_message() );
		}

		return new YandexDeliveryApiResponse(
			(int) wp_remote_retrieve_response_code( $response ),
			(string) wp_remote_retrieve_body( $response ),
			(array) wp_remote_retrieve_headers( $response )
		);
	}
}

