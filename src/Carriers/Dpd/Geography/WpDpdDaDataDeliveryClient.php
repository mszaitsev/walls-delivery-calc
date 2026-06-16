<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use Throwable;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class WpDpdDaDataDeliveryClient implements DpdDaDataDeliveryClientInterface {
	private const ENDPOINT = 'https://suggestions.dadata.ru/suggestions/api/4_1/rs/findById/delivery';

	public function __construct(
		private AddressSuggestionSettings $settings,
		private DaDataTokenPool $token_pool,
		private Logger $logger
	) {
	}

	public function find_dpd_id_by_kladr( string $kladr_id ): array {
		$kladr_id = $this->normalize_kladr( $kladr_id );
		if ( '' === $kladr_id ) {
			return $this->failure( 'KLADR ID is empty.', 0, '' );
		}
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return $this->failure( 'wp_remote_post is unavailable.', 0, '' );
		}

		$token = $this->token_pool->next_available_token();
		if ( null === $token ) {
			return $this->failure( 'No available DaData token.', 0, '' );
		}

		$token_id = (string) ( $token['id'] ?? '' );
		$this->token_pool->set_last_used_token_id( $token_id );
		$body = function_exists( 'wp_json_encode' ) ? wp_json_encode( array( 'query' => $kladr_id ), JSON_UNESCAPED_UNICODE ) : json_encode( array( 'query' => $kladr_id ), JSON_UNESCAPED_UNICODE );
		if ( ! is_string( $body ) ) {
			return $this->failure( 'DaData JSON encode failed.', 0, $token_id );
		}

		try {
			$this->logger->debug( 'DaData delivery lookup request started.', array( 'endpoint' => 'findById/delivery', 'token_id' => $token_id ) );
			$response = wp_remote_post(
				self::ENDPOINT,
				array(
					'timeout' => $this->settings->timeout(),
					'headers' => array(
						'Authorization' => 'Token ' . (string) $token['token'],
						'Content-Type'  => 'application/json',
						'Accept'        => 'application/json',
					),
					'body' => $body,
				)
			);
			$this->token_pool->increment_usage( $token_id );
		} catch ( Throwable $throwable ) {
			$this->token_pool->increment_usage( $token_id );
			$this->token_pool->record_request_attempt( $token_id, 'dpd_delivery', $kladr_id, true, true, 0, 'dadata_timeout' );
			return $this->failure( $throwable->getMessage(), 0, $token_id );
		}

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			$message = method_exists( $response, 'get_error_message' ) ? $response->get_error_message() : 'WordPress HTTP error.';
			$this->token_pool->record_request_attempt( $token_id, 'dpd_delivery', $kladr_id, true, true, 0, 'dadata_api_failed' );
			return $this->failure( $message, 0, $token_id );
		}

		$status_code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : (int) ( is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0 );
		$raw_body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : (string) ( is_array( $response ) ? ( $response['body'] ?? '' ) : '' );
		$decoded = '' !== trim( $raw_body ) ? json_decode( $raw_body, true ) : null;
		if ( ! is_array( $decoded ) ) {
			$this->token_pool->record_request_attempt( $token_id, 'dpd_delivery', $kladr_id, true, true, $status_code, 'dadata_parse_failed' );
			return $this->failure( 'DaData response parse failed.', $status_code, $token_id );
		}
		if ( $status_code < 200 || $status_code >= 300 ) {
			$this->token_pool->record_request_attempt( $token_id, 'dpd_delivery', $kladr_id, true, true, $status_code, 'dadata_api_failed' );
			return $this->failure( 'DaData HTTP status ' . $status_code, $status_code, $token_id );
		}

		$suggestions = is_array( $decoded['suggestions'] ?? null ) ? $decoded['suggestions'] : array();
		$dpd_id = '';
		foreach ( $suggestions as $suggestion ) {
			if ( is_array( $suggestion ) && is_array( $suggestion['data'] ?? null ) && '' !== (string) ( $suggestion['data']['dpd_id'] ?? '' ) ) {
				$dpd_id = preg_replace( '/\D+/', '', (string) $suggestion['data']['dpd_id'] ) ?? '';
				break;
			}
		}

		$this->token_pool->record_request_attempt( $token_id, 'dpd_delivery', $kladr_id, true, true, $status_code, '' );
		if ( '' === $dpd_id ) {
			return $this->failure( 'DaData delivery response does not contain data.dpd_id.', $status_code, $token_id );
		}

		return array( 'success' => true, 'dpd_id' => $dpd_id, 'message' => 'DPD cityId found by DaData delivery API.', 'status_code' => $status_code, 'token_id' => $token_id );
	}

	private function normalize_kladr( string $kladr_id ): string {
		return preg_replace( '/\D+/', '', strtoupper( preg_replace( '/^RU/i', '', trim( $kladr_id ) ) ) ) ?? '';
	}

	/**
	 * @return array{success:bool,dpd_id:string,message:string,status_code:int,token_id:string}
	 */
	private function failure( string $message, int $status_code, string $token_id ): array {
		return array( 'success' => false, 'dpd_id' => '', 'message' => $message, 'status_code' => $status_code, 'token_id' => $token_id );
	}
}
