<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class RussianPostDomesticApiClient {
	public function __construct(
		private RussianPostDomesticSettings $settings,
		private Logger $logger
	) {
	}

	/**
	 * @param array<string,scalar> $params
	 * @return array<string,mixed>
	 */
	public function calculate_tariff( array $params, string $service_key = '' ): array {
		$settings = $this->settings->all( $service_key );
		$url = $this->url( (string) $settings['api_endpoint'], $params );
		$this->debug( 'Russian Post domestic tariff request prepared.', array( 'params' => $this->sanitize_params( $params ) ), $service_key );

		if ( ! function_exists( 'wp_remote_get' ) ) {
			return $this->error( 'wp_http_unavailable', 'WordPress HTTP API is unavailable.', $url, $params );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => max( 1, (int) $settings['timeout'] ),
				'headers' => array_filter(
					array(
						'Accept' => 'application/json',
						'Authorization' => '' !== trim( (string) $settings['api_token'] ) ? 'Bearer ' . trim( (string) $settings['api_token'] ) : '',
					)
				),
			)
		);
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return $this->error( 'http_error', $response->get_error_message(), $url, $params );
		}

		$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
		$decoded = json_decode( $body, true );
		if ( $code < 200 || $code >= 300 ) {
			return $this->error( 'http_status_' . $code, 'Russian Post domestic API returned HTTP ' . $code . '.', $url, $params, $code, $this->error_payload( $body, $decoded ) );
		}
		if ( ! is_array( $decoded ) ) {
			return $this->error( 'invalid_json', 'Russian Post domestic API returned invalid JSON.', $url, $params, $code, $this->error_payload( $body, $decoded ) );
		}
		if ( $this->has_api_error( $decoded ) ) {
			return $this->error( 'api_error', $this->extract_error_message( $decoded ), $url, $params, $code, $decoded );
		}

		return array(
			'success' => true,
			'http_code' => $code,
			'url' => $url,
			'params' => $this->sanitize_params( $params ),
			'raw' => $decoded,
			'parsed' => $this->parse_tariff_response( $decoded ),
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public function parse_tariff_response( array $raw ): array {
		$delivery = is_array( $raw['delivery'] ?? null ) ? $raw['delivery'] : array();

		return array(
			'id' => $this->nullable_int( $raw['id'] ?? null ),
			'name' => (string) ( $raw['name'] ?? '' ),
			'pay' => $this->kopecks( $raw['pay'] ?? $raw['paymoney'] ?? null ),
			'nds' => $this->kopecks( $raw['nds'] ?? null ),
			'paynds' => $this->kopecks( $raw['paynds'] ?? $raw['paymoneynds'] ?? null ),
			'delivery_min_days' => $this->nullable_int( $delivery['min'] ?? $raw['delivery-min'] ?? $raw['delivery_min'] ?? null ),
			'delivery_max_days' => $this->nullable_int( $delivery['max'] ?? $raw['delivery-max'] ?? $raw['delivery_max'] ?? null ),
			'transtype' => $this->nullable_int( $raw['transtype'] ?? null ),
			'delivery_to' => (string) ( $raw['delivery-to'] ?? $raw['delivery_to'] ?? '' ),
			'items_summary' => $this->items_summary( $raw['items'] ?? array() ),
		);
	}

	/**
	 * @param array<string,scalar> $params
	 */
	private function url( string $endpoint, array $params ): string {
		return function_exists( 'add_query_arg' ) ? add_query_arg( $params, $endpoint ) : $endpoint . '?' . http_build_query( $params );
	}

	private function kopecks( mixed $value ): ?int {
		return is_numeric( $value ) ? (int) $value : null;
	}

	private function nullable_int( mixed $value ): ?int {
		return is_numeric( $value ) ? (int) $value : null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function items_summary( mixed $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}
		$summary = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$summary[] = array_filter(
				array(
					'name' => (string) ( $item['name'] ?? $item['title'] ?? '' ),
					'serviceon' => $item['serviceon'] ?? null,
					'tariff' => $this->tariff_summary( $item['tariff'] ?? array() ),
					'delivery' => $this->delivery_summary( $item['delivery'] ?? array() ),
					'pay' => $this->kopecks( $item['pay'] ?? null ),
					'nds' => $this->kopecks( $item['nds'] ?? null ),
					'paynds' => $this->kopecks( $item['paynds'] ?? null ),
				),
				static fn ( mixed $value ): bool => null !== $value && '' !== $value
			);
		}

		return $summary;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function tariff_summary( mixed $tariff ): array {
		if ( ! is_array( $tariff ) ) {
			return array();
		}

		return array_filter(
			array(
				'valnds' => $this->kopecks( $tariff['valnds'] ?? null ),
			),
			static fn ( mixed $value ): bool => null !== $value && '' !== $value
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function delivery_summary( mixed $delivery ): array {
		if ( ! is_array( $delivery ) ) {
			return array();
		}

		return array_filter(
			array(
				'min' => $this->nullable_int( $delivery['min'] ?? null ),
				'max' => $this->nullable_int( $delivery['max'] ?? null ),
			),
			static fn ( mixed $value ): bool => null !== $value && '' !== $value
		);
	}

	/**
	 * @param array<string,mixed> $response
	 */
	private function has_api_error( array $response ): bool {
		if ( isset( $response['error'] ) || isset( $response['errors'] ) ) {
			return true;
		}
		$error_code = $response['errorcode'] ?? null;
		$error_message = trim( (string) ( $response['errormsg'] ?? '' ) );

		return ( is_numeric( $error_code ) && 0 !== (int) $error_code ) || '' !== $error_message;
	}

	/**
	 * @param array<string,mixed> $response
	 */
	private function extract_error_message( array $response ): string {
		foreach ( array( 'errormsg', 'error', 'message', 'error_message' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_scalar( $response[ $key ] ) && '' !== trim( (string) $response[ $key ] ) ) {
				return (string) $response[ $key ];
			}
		}

		return 'Russian Post domestic API returned an error.';
	}

	private function extract_error_code( array $response ): string {
		foreach ( array( 'errorcode', 'code', 'error_code' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_scalar( $response[ $key ] ) && '' !== trim( (string) $response[ $key ] ) ) {
				return (string) $response[ $key ];
			}
		}

		return '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function error_payload( string $body, mixed $decoded ): array {
		$decoded_body = is_array( $decoded ) ? $decoded : array();

		return array_filter(
			array(
				'raw_body' => $body,
				'decoded_body' => $decoded_body,
				'errorcode' => array() !== $decoded_body ? $this->extract_error_code( $decoded_body ) : '',
				'errormsg' => array() !== $decoded_body ? $this->extract_error_message( $decoded_body ) : '',
				'body' => $body,
			),
			static fn ( mixed $value ): bool => null !== $value && '' !== $value && array() !== $value
		);
	}

	/**
	 * @param array<string,scalar> $params
	 * @return array<string,scalar>
	 */
	private function sanitize_params( array $params ): array {
		unset( $params['token'], $params['api_token'], $params['password'], $params['Authorization'] );

		return $params;
	}

	/**
	 * @param array<string,scalar> $params
	 * @param array<string,mixed>  $raw
	 * @return array<string,mixed>
	 */
	private function error( string $code, string $message, string $url, array $params, int $http_code = 0, array $raw = array() ): array {
		return array(
			'success' => false,
			'error_code' => $code,
			'error_message' => $message,
			'http_code' => $http_code,
			'url' => $url,
			'params' => $this->sanitize_params( $params ),
			'raw' => $raw,
		);
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function debug( string $message, array $context = array(), string $service_key = '' ): void {
		if ( $this->settings->debug_enabled( $service_key ) ) {
			$this->logger->debug( $message, $context );
		}
	}
}
