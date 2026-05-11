<?php
/**
 * Russian Post tariff API client.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Russian_Post_API {
	private const BASE_URL = 'https://tariff.pochta.ru/v2/calculate/tariff';

	private WDC_Logger $logger;

	public function __construct( ?WDC_Logger $logger = null ) {
		$this->logger = $logger ?? new WDC_Logger();
	}

	/**
	 * @param array<string, scalar> $params Request query params.
	 * @param bool                 $debug_enabled Whether debug logging is enabled.
	 * @return array<string, mixed>
	 */
	public function calculate_tariff( array $params, bool $debug_enabled = false ): array {
		$url = add_query_arg( $params, self::BASE_URL );
		$this->debug_log( $debug_enabled, 'Russian Post request prepared.', array( 'url' => $url, 'params' => $params ) );

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error_code' => 'http_error',
				'error_message' => $response->get_error_message(),
				'url' => $url,
				'params' => $params,
				'raw' => array(),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );

		$this->debug_log( $debug_enabled, 'Russian Post response received.', array( 'code' => $code, 'body' => $body ) );

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'success' => false,
				'error_code' => 'http_status_' . $code,
				'error_message' => 'Russian Post API returned HTTP ' . $code . '.',
				'url' => $url,
				'params' => $params,
				'raw' => is_array( $decoded ) ? $decoded : array( 'body' => $body ),
			);
		}

		if ( ! is_array( $decoded ) ) {
			return array(
				'success' => false,
				'error_code' => 'invalid_json',
				'error_message' => 'Russian Post API returned invalid JSON.',
				'url' => $url,
				'params' => $params,
				'raw' => array( 'body' => $body ),
			);
		}

		if ( isset( $decoded['error'] ) || isset( $decoded['errors'] ) ) {
			return array(
				'success' => false,
				'error_code' => 'api_error',
				'error_message' => $this->extract_api_error_message( $decoded ),
				'url' => $url,
				'params' => $params,
				'raw' => $decoded,
			);
		}

		return array(
			'success' => true,
			'url' => $url,
			'params' => $params,
			'raw' => $decoded,
		);
	}

	/**
	 * @param array<string, mixed> $response API response.
	 */
	private function extract_api_error_message( array $response ): string {
		foreach ( array( 'error', 'message', 'error_message' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_scalar( $response[ $key ] ) ) {
				return (string) $response[ $key ];
			}
		}

		return 'Russian Post API returned an error.';
	}

	/**
	 * @param array<string, mixed> $context Debug context.
	 */
	private function debug_log( bool $debug_enabled, string $message, array $context = array() ): void {
		if ( $debug_enabled ) {
			$this->logger->log( 'debug', $message, $context );
		}
	}
}
