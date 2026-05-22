<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\AddressSuggestions;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class DaDataSuggestionClient implements AddressSuggestionClientInterface {
	private const ENDPOINT = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';

	public function __construct(
		private AddressSuggestionSettings $settings,
		private Logger $logger
	) {
	}

	/**
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	public function suggest( string $stage, string $query, array $context = array() ): array {
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return $this->failure( 'dadata_http_unavailable', 'wp_remote_post is unavailable.', 0 );
		}

		$body = $this->body( $stage, $query, $context );
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $body, JSON_UNESCAPED_UNICODE ) : json_encode( $body, JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $json ) ) {
			return $this->failure( 'dadata_encode_failed', 'DaData JSON encode failed.', 0 );
		}

		$this->logger->debug( 'DaData suggestions request started.', array( 'host' => 'suggestions.dadata.ru', 'endpoint' => 'suggest/address', 'stage' => $stage ) );

		try {
			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout' => $this->settings->timeout(),
					'headers' => array(
						'Authorization' => 'Token ' . $this->settings->api_key(),
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
					),
					'body' => $json,
				)
			);
		} catch ( \Throwable $exception ) {
			return $this->failure( 'dadata_timeout', $exception->getMessage(), 0 );
		}

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			$message = method_exists( $response, 'get_error_message' ) ? $response->get_error_message() : 'WordPress HTTP error.';
			return $this->failure( 'dadata_api_failed', $message, 0 );
		}

		if ( ! is_array( $response ) ) {
			return $this->failure( 'dadata_api_failed', 'Unexpected HTTP response.', 0 );
		}

		$status_code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : (int) ( $response['response']['code'] ?? 0 );
		$raw_body    = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : (string) ( $response['body'] ?? '' );
		$decoded     = '' !== trim( $raw_body ) ? json_decode( $raw_body, true ) : null;

		$this->logger->debug( 'DaData suggestions response received.', array( 'status_code' => $status_code, 'stage' => $stage ) );

		if ( ! is_array( $decoded ) ) {
			return $this->failure( 'dadata_parse_failed', 'DaData response parse failed.', $status_code );
		}

		if ( $status_code < 200 || $status_code >= 300 ) {
			return $this->failure( 'dadata_api_failed', 'DaData HTTP status ' . $status_code, $status_code );
		}

		$suggestions = is_array( $decoded['suggestions'] ?? null ) ? $decoded['suggestions'] : array();

		return array(
			'success'       => true,
			'stage'         => $stage,
			'status_code'   => $status_code,
			'body'          => $body,
			'suggestions'   => $suggestions,
			'error_code'    => '',
			'error_message' => '',
		);
	}

	/**
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	public function body( string $stage, string $query, array $context = array() ): array {
		$count = $this->settings->count();
		$body = array(
			'query' => $query,
			'count' => 'resolve' === $stage ? 1 : $count,
		);

		if ( 'city' === $stage ) {
			$body['locations'] = array( array( 'country_iso_code' => 'RU' ) );
			$body['from_bound'] = array( 'value' => 'city' );
			$body['to_bound'] = array( 'value' => 'settlement' );
			return $body;
		}

		if ( 'house_after_street' === $stage && '' !== (string) ( $context['street_fias_id'] ?? '' ) ) {
			$body['count'] = 20;
			$body['locations'] = array( array( 'fias_id' => (string) $context['street_fias_id'] ) );
			$body['from_bound'] = array( 'value' => 'house' );
			$body['to_bound'] = array( 'value' => 'house' );
			$body['restrict_value'] = true;
			return $body;
		}

		if ( 'address' === $stage ) {
			$body['locations'] = array( array( 'country_iso_code' => 'RU' ) );
			$body['from_bound'] = array( 'value' => 'street' );
			$body['to_bound'] = array( 'value' => 'house' );
		}

		return $body;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function failure( string $code, string $message, int $status_code ): array {
		return array(
			'success'       => false,
			'status_code'   => $status_code,
			'body'          => array(),
			'suggestions'   => array(),
			'error_code'    => $code,
			'error_message' => $message,
		);
	}
}
