<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Postcodes;

use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class DaDataPostcodeClient {
	private const ENDPOINT = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/address';

	public function __construct(
		private DaDataTokenPool $token_pool,
		private Logger $logger,
		private int $timeout = 3
	) {
	}

	/**
	 * @param array<string,mixed> $location
	 * @return array{success:bool,postal_code:string,matched:bool,tokens_exhausted:bool,error_code:string,error_message:string,status_code:int,raw:array<string,mixed>}
	 */
	public function find_postal_code( array $location ): array {
		$fias_id = trim( (string) ( $location['fias_id'] ?? '' ) );
		if ( '' === $fias_id ) {
			return $this->failure( 'missing_fias_id', 'Location fias_id is empty.', 0 );
		}

		if ( ! function_exists( 'wp_remote_post' ) ) {
			return $this->failure( 'dadata_http_unavailable', 'wp_remote_post is unavailable.', 0 );
		}

		$json = $this->encode_body( $fias_id );
		if ( '' === $json ) {
			return $this->failure( 'dadata_encode_failed', 'DaData JSON encode failed.', 0 );
		}

		$active_count = count( $this->token_pool->active_tokens() );
		if ( 0 === $active_count ) {
			return $this->failure( 'no_available_dadata_token', 'No configured DaData token.', 0, true );
		}

		for ( $attempt = 0; $attempt < $active_count; ++$attempt ) {
			$token = $this->token_pool->next_available_token();
			if ( null === $token ) {
				return $this->failure( 'dadata_daily_limit_exhausted', 'All DaData tokens are exhausted for today.', 0, true );
			}

			$token_id = (string) ( $token['id'] ?? '' );
			$this->logger->debug( 'DaData postcode request started.', array( 'endpoint' => 'findById/address', 'token_id' => $token_id ) );
			$this->token_pool->set_last_used_token_id( $token_id );

			try {
				$response = wp_remote_post(
					self::ENDPOINT,
					array(
						'timeout' => $this->timeout,
						'headers' => array(
							'Authorization' => 'Token ' . (string) $token['token'],
							'Content-Type'  => 'application/json',
							'Accept'        => 'application/json',
						),
						'body' => $json,
					)
				);
				$this->token_pool->increment_usage( $token_id );
			} catch ( \Throwable $exception ) {
				$this->token_pool->increment_usage( $token_id );
				$this->token_pool->record_request_attempt( $token_id, 'postcode_fill', $fias_id, true, true, 0, 'dadata_timeout' );
				return $this->failure( 'dadata_timeout', $exception->getMessage(), 0 );
			}

			if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
				$message = method_exists( $response, 'get_error_message' ) ? $response->get_error_message() : 'WordPress HTTP error.';
				$this->token_pool->record_request_attempt( $token_id, 'postcode_fill', $fias_id, true, true, 0, 'dadata_api_failed' );
				return $this->failure( 'dadata_api_failed', $message, 0 );
			}

			if ( ! is_array( $response ) ) {
				$this->token_pool->record_request_attempt( $token_id, 'postcode_fill', $fias_id, true, true, 0, 'dadata_api_failed' );
				return $this->failure( 'dadata_api_failed', 'Unexpected HTTP response.', 0 );
			}

			$status_code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : (int) ( $response['response']['code'] ?? 0 );
			$raw_body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : (string) ( $response['body'] ?? '' );
			$decoded = '' !== trim( $raw_body ) ? json_decode( $raw_body, true ) : null;

			if ( $this->is_limit_response( $status_code, $raw_body, $decoded ) ) {
				$this->token_pool->record_request_attempt( $token_id, 'postcode_fill', $fias_id, true, true, $status_code, 'dadata_daily_limit_exhausted' );
				$this->token_pool->mark_exhausted( $token );
				continue;
			}

			if ( ! is_array( $decoded ) ) {
				$this->token_pool->record_request_attempt( $token_id, 'postcode_fill', $fias_id, true, true, $status_code, 'dadata_parse_failed' );
				return $this->failure( 'dadata_parse_failed', 'DaData response parse failed.', $status_code );
			}

			if ( $status_code < 200 || $status_code >= 300 ) {
				$this->token_pool->record_request_attempt( $token_id, 'postcode_fill', $fias_id, true, true, $status_code, 'dadata_api_failed' );
				return $this->failure( 'dadata_api_failed', 'DaData HTTP status ' . $status_code, $status_code, false, $decoded );
			}

			$suggestions = is_array( $decoded['suggestions'] ?? null ) ? $decoded['suggestions'] : array();
			if ( array() === $suggestions || ! is_array( $suggestions[0] ?? null ) ) {
				$this->token_pool->record_request_attempt( $token_id, 'postcode_fill', $fias_id, true, true, $status_code, 'dadata_empty_result' );
				return $this->failure( 'dadata_empty_result', 'DaData returned no address object.', $status_code, false, $decoded );
			}

			$suggestion = $suggestions[0];
			$data = is_array( $suggestion['data'] ?? null ) ? $suggestion['data'] : array();
			$matched_fias = (string) ( $data['fias_id'] ?? $data['fias_guid'] ?? '' );
			if ( '' === $matched_fias ) {
				$this->token_pool->record_request_attempt( $token_id, 'postcode_fill', $fias_id, true, true, $status_code, 'dadata_fias_missing' );
				return $this->failure( 'dadata_fias_missing', 'DaData response does not contain fias_id.', $status_code, false, $decoded );
			}

			if ( 0 !== strcasecmp( $matched_fias, $fias_id ) ) {
				$this->token_pool->record_request_attempt( $token_id, 'postcode_fill', $fias_id, true, true, $status_code, 'dadata_fias_mismatch' );
				return $this->failure( 'dadata_fias_mismatch', 'DaData returned another fias_id.', $status_code, false, $decoded );
			}

			if ( ! $this->matches_location_name( $location, $data ) ) {
				$this->token_pool->record_request_attempt( $token_id, 'postcode_fill', $fias_id, true, true, $status_code, 'dadata_name_mismatch' );
				return $this->failure( 'dadata_name_mismatch', 'DaData address object name does not match location.', $status_code, false, $decoded );
			}

			$this->token_pool->record_request_attempt( $token_id, 'postcode_fill', $fias_id, true, true, $status_code, '' );
			return array(
				'success'          => true,
				'postal_code'      => trim( (string) ( $data['postal_code'] ?? '' ) ),
				'matched'          => true,
				'tokens_exhausted' => false,
				'error_code'       => '',
				'error_message'    => '',
				'status_code'      => $status_code,
				'raw'              => $decoded,
			);
		}

		return $this->failure( 'dadata_daily_limit_exhausted', 'All DaData tokens are exhausted for today.', 0, true );
	}

	private function encode_body( string $fias_id ): string {
		$body = array( 'query' => $fias_id, 'count' => 1 );
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $body, JSON_UNESCAPED_UNICODE ) : json_encode( $body, JSON_UNESCAPED_UNICODE );
		return is_string( $json ) ? $json : '';
	}

	/**
	 * @param array<string,mixed> $location
	 * @param array<string,mixed> $data
	 */
	private function matches_location_name( array $location, array $data ): bool {
		$expected = $this->normalized_candidates(
			array(
				(string) ( $location['place_name'] ?? '' ),
				(string) ( $location['settlement_name'] ?? '' ),
				(string) ( $location['city_name'] ?? '' ),
				(string) ( $location['display_name'] ?? '' ),
			)
		);
		$actual = $this->normalized_candidates(
			array(
				(string) ( $data['settlement'] ?? '' ),
				(string) ( $data['city'] ?? '' ),
				(string) ( $data['area'] ?? '' ),
				(string) ( $data['value'] ?? '' ),
				(string) ( $data['unrestricted_value'] ?? '' ),
			)
		);

		foreach ( $expected as $name ) {
			foreach ( $actual as $candidate ) {
				if ( $candidate === $name || ( '' !== $name && str_contains( $candidate, $name ) ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * @param array<int,string> $values
	 * @return array<int,string>
	 */
	private function normalized_candidates( array $values ): array {
		$result = array();
		foreach ( $values as $value ) {
			$normalized = $this->normalize_name( $value );
			if ( '' !== $normalized ) {
				$result[ $normalized ] = $normalized;
			}
		}

		return array_values( $result );
	}

	private function normalize_name( string $value ): string {
		$value = str_replace( array( 'ё', 'Ё' ), array( 'е', 'е' ), $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		return trim( preg_replace( '/\s+/u', ' ', $value ) ?? '' );
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

	/**
	 * @param array<string,mixed> $raw
	 * @return array{success:bool,postal_code:string,matched:bool,tokens_exhausted:bool,error_code:string,error_message:string,status_code:int,raw:array<string,mixed>}
	 */
	private function failure( string $code, string $message, int $status_code, bool $tokens_exhausted = false, array $raw = array() ): array {
		return array(
			'success'          => false,
			'postal_code'      => '',
			'matched'          => false,
			'tokens_exhausted' => $tokens_exhausted,
			'error_code'       => $code,
			'error_message'    => $message,
			'status_code'      => $status_code,
			'raw'              => $raw,
		);
	}
}
