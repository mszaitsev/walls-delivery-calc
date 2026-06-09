<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\AddressSuggestions;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class DaDataSuggestionClient implements AddressSuggestionClientInterface {
	private const ENDPOINT = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address';

	public function __construct(
		private AddressSuggestionSettings $settings,
		private DaDataTokenPool $token_pool,
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

		$active_count = count( $this->token_pool->active_tokens() );
		if ( 0 === $active_count ) {
			return $this->failure( 'no_available_dadata_token', 'No configured DaData token.', 0 );
		}

		$attempts = max( 1, $active_count );
		for ( $attempt = 0; $attempt < $attempts; ++$attempt ) {
			$token = $this->token_pool->next_available_token();
			if ( null === $token ) {
				return $this->failure( 'dadata_daily_limit_exhausted', 'All DaData tokens are exhausted for today.', 0 );
			}

			$this->logger->debug( 'DaData suggestions request started.', array( 'host' => 'suggestions.dadata.ru', 'endpoint' => 'suggest/address', 'stage' => $stage, 'token_id' => (string) $token['id'] ) );
			$this->token_pool->set_last_used_token_id( (string) $token['id'] );

			try {
				$response = wp_remote_post(
					self::ENDPOINT,
					array(
						'timeout' => $this->settings->timeout(),
						'headers' => array(
							'Authorization' => 'Token ' . (string) $token['token'],
							'Content-Type'  => 'application/json',
							'Accept'        => 'application/json',
						),
						'body' => $json,
					)
				);
				$this->token_pool->increment_usage( (string) $token['id'] );
			} catch ( \Throwable $exception ) {
				$this->token_pool->increment_usage( (string) $token['id'] );
				$this->token_pool->record_request_attempt( (string) $token['id'], $stage, $query, true, true, 0, 'dadata_timeout' );
				return $this->failure( 'dadata_timeout', $exception->getMessage(), 0 );
			}

			if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
				$message = method_exists( $response, 'get_error_message' ) ? $response->get_error_message() : 'WordPress HTTP error.';
				$this->token_pool->record_request_attempt( (string) $token['id'], $stage, $query, true, true, 0, 'dadata_api_failed' );
				return $this->failure( 'dadata_api_failed', $message, 0 );
			}

			if ( ! is_array( $response ) ) {
				$this->token_pool->record_request_attempt( (string) $token['id'], $stage, $query, true, true, 0, 'dadata_api_failed' );
				return $this->failure( 'dadata_api_failed', 'Unexpected HTTP response.', 0 );
			}

			$status_code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : (int) ( $response['response']['code'] ?? 0 );
			$raw_body    = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : (string) ( $response['body'] ?? '' );
			$decoded     = '' !== trim( $raw_body ) ? json_decode( $raw_body, true ) : null;

			$this->logger->debug( 'DaData suggestions response received.', array( 'status_code' => $status_code, 'stage' => $stage, 'token_id' => (string) $token['id'] ) );

			if ( $this->is_limit_response( $status_code, $raw_body, $decoded ) ) {
				$this->token_pool->record_request_attempt( (string) $token['id'], $stage, $query, true, true, $status_code, 'dadata_daily_limit_exhausted' );
				$this->token_pool->mark_exhausted( $token );
				continue;
			}

			if ( ! is_array( $decoded ) ) {
				$this->token_pool->record_request_attempt( (string) $token['id'], $stage, $query, true, true, $status_code, 'dadata_parse_failed' );
				return $this->failure( 'dadata_parse_failed', 'DaData response parse failed.', $status_code );
			}

			if ( $status_code < 200 || $status_code >= 300 ) {
				$this->token_pool->record_request_attempt( (string) $token['id'], $stage, $query, true, true, $status_code, 'dadata_api_failed' );
				return $this->failure( 'dadata_api_failed', 'DaData HTTP status ' . $status_code, $status_code );
			}

			$suggestions = is_array( $decoded['suggestions'] ?? null ) ? $decoded['suggestions'] : array();
			$this->token_pool->record_request_attempt( (string) $token['id'], $stage, $query, true, true, $status_code, '' );

			return array(
				'success'       => true,
				'stage'         => $stage,
				'status_code'   => $status_code,
				'body'          => $body,
				'suggestions'   => $suggestions,
				'error_code'    => '',
				'error_message' => '',
				'token_id'      => (string) $token['id'],
			);
		}

		return $this->failure( 'dadata_daily_limit_exhausted', 'All DaData tokens are exhausted for today.', 0 );
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

		if ( 'address_next' === $stage ) {
			$body['count'] = 20;
			$boost = array( 'country_iso_code' => (string) ( $context['country_code'] ?? 'RU' ) );
			foreach ( array( 'city_fias_id', 'city_kladr_id', 'settlement_fias_id', 'settlement_kladr_id' ) as $key ) {
				if ( '' !== (string) ( $context[ $key ] ?? '' ) ) {
					$boost[ str_replace( array( 'city_', 'settlement_' ), '', $key ) ] = (string) $context[ $key ];
					break;
				}
			}
			if ( count( $boost ) > 1 ) {
				$body['locations_boost'] = array( $boost );
			}
			return $body;
		}

		if ( 'address' === $stage ) {
			$location = array( 'country_iso_code' => (string) ( $context['country_code'] ?? 'RU' ) );
			foreach ( array( 'location_fias_id', 'location_kladr_id', 'location_city_fias_id', 'location_city_kladr_id' ) as $key ) {
				if ( '' !== (string) ( $context[ $key ] ?? '' ) ) {
					$location[ str_replace( 'location_', '', $key ) ] = (string) $context[ $key ];
					break;
				}
			}
			$body['locations'] = array( $location );
			$body['from_bound'] = array( 'value' => 'street' );
			$body['to_bound'] = array( 'value' => 'house' );
			if ( count( $location ) > 1 ) {
				$body['restrict_value'] = true;
			}
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

	/**
	 * @param mixed $decoded
	 */
	private function is_limit_response( int $status_code, string $raw_body, mixed $decoded ): bool {
		$text = strtolower( $raw_body );
		if ( is_array( $decoded ) ) {
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $decoded ) : json_encode( $decoded );
			$text .= ' ' . strtolower( is_string( $encoded ) ? $encoded : '' );
		}

		return in_array( $status_code, array( 402, 403, 429 ), true )
			&& ( str_contains( $text, 'limit' ) || str_contains( $text, 'quota' ) || str_contains( $text, 'daily' ) || str_contains( $text, 'exceeded' ) );
	}
}
