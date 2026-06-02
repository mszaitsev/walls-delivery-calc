<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class RussianPostCourierTariffProbeService {
	private const ENDPOINT = 'https://tariff.pochta.ru/v2/calculate/tariff';
	private const FROM_POSTCODE = '630005';
	private const MIN_REQUEST_INTERVAL_MICROSECONDS = 250000;

	private float $last_request_at = 0.0;

	public function __construct( private Logger $logger ) {
	}

	/**
	 * @return array{success:bool,postal_code:string,error_code:string,error_message:string,raw:array|string|null}
	 */
	public function probe( string $to_postal_code ): array {
		$postal_code = $this->valid_postcode( $to_postal_code );
		if ( '' === $postal_code ) {
			return $this->result( false, $to_postal_code, 'invalid_postal_code', 'Postal code must contain exactly 6 digits.', null );
		}
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return $this->result( false, $postal_code, 'wp_http_unavailable', 'WordPress HTTP API is unavailable.', null );
		}

		$this->throttle();
		$params = array(
			'mailtype' => 24,
			'mailctg' => 3,
			'directctg' => 1,
			'weight' => 1000,
			'weightpay' => 1000,
			'date' => $this->date_value(),
			'time' => $this->time_value(),
			'from' => self::FROM_POSTCODE,
			'to' => $postal_code,
		);
		$url = function_exists( 'add_query_arg' ) ? add_query_arg( $params, self::ENDPOINT ) : self::ENDPOINT . '?' . http_build_query( $params );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'user-agent' => 'WDC',
				'headers' => array( 'Accept' => 'application/json' ),
			)
		);

		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			$message = method_exists( $response, 'get_error_message' ) ? $response->get_error_message() : 'HTTP request failed.';
			return $this->result( false, $postal_code, 'http_error', $message, null );
		}

		$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
		$decoded = json_decode( $body, true );
		if ( $code < 200 || $code >= 300 ) {
			return $this->result( false, $postal_code, 'http_status_' . $code, 'Russian Post tariff API returned HTTP ' . $code . '.', is_array( $decoded ) ? $decoded : $body );
		}
		if ( ! is_array( $decoded ) ) {
			return $this->result( false, $postal_code, 'invalid_json', 'Russian Post tariff API returned invalid JSON.', $body );
		}
		if ( $this->has_api_error( $decoded ) ) {
			return $this->result( false, $postal_code, $this->extract_error_code( $decoded ) ?: 'api_error', $this->extract_error_message( $decoded ), $decoded );
		}
		if ( ! $this->has_price( $decoded ) ) {
			return $this->result( false, $postal_code, 'empty_price', 'Russian Post tariff API returned no price.', $decoded );
		}

		return $this->result( true, $postal_code, '', '', $decoded );
	}

	private function throttle(): void {
		$now = microtime( true );
		$elapsed = $this->last_request_at > 0 ? (int) round( ( $now - $this->last_request_at ) * 1000000 ) : self::MIN_REQUEST_INTERVAL_MICROSECONDS;
		if ( $elapsed < self::MIN_REQUEST_INTERVAL_MICROSECONDS && function_exists( 'usleep' ) ) {
			usleep( self::MIN_REQUEST_INTERVAL_MICROSECONDS - $elapsed );
		}
		$this->last_request_at = microtime( true );
	}

	private function date_value(): string {
		if ( function_exists( 'current_time' ) ) {
			$timestamp = current_time( 'timestamp' );
			if ( is_numeric( $timestamp ) ) {
				return gmdate( 'Ymd', (int) $timestamp );
			}
		}

		return function_exists( 'wp_date' ) ? wp_date( 'Ymd' ) : gmdate( 'Ymd' );
	}

	private function time_value(): string {
		if ( function_exists( 'current_time' ) ) {
			$timestamp = current_time( 'timestamp' );
			if ( is_numeric( $timestamp ) ) {
				return gmdate( 'Hi', (int) $timestamp );
			}
		}

		return function_exists( 'wp_date' ) ? wp_date( 'Hi' ) : gmdate( 'Hi' );
	}

	private function valid_postcode( string $postcode ): string {
		$postcode = preg_replace( '/\D+/', '', $postcode ) ?? '';
		return preg_match( '/^\d{6}$/', $postcode ) ? $postcode : '';
	}

	/**
	 * @param array<string,mixed> $response
	 */
	private function has_api_error( array $response ): bool {
		if ( isset( $response['error'] ) || isset( $response['errors'] ) ) {
			return true;
		}
		$error_code = $response['errorcode'] ?? $response['code'] ?? $response['error_code'] ?? null;
		$error_message = trim( (string) ( $response['errormsg'] ?? $response['message'] ?? $response['error_message'] ?? '' ) );

		return '2007' === (string) $error_code || ( is_numeric( $error_code ) && 0 !== (int) $error_code ) || '' !== $error_message;
	}

	/**
	 * @param array<string,mixed> $response
	 */
	private function has_price( array $response ): bool {
		foreach ( array( 'paynds', 'pay', 'paymoneynds', 'paymoney' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_numeric( $response[ $key ] ) && (int) $response[ $key ] > 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $response
	 */
	private function extract_error_code( array $response ): string {
		foreach ( array( 'errorcode', 'code', 'error_code' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_scalar( $response[ $key ] ) ) {
				return (string) $response[ $key ];
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $response
	 */
	private function extract_error_message( array $response ): string {
		foreach ( array( 'errormsg', 'message', 'error_message', 'error' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_scalar( $response[ $key ] ) && '' !== trim( (string) $response[ $key ] ) ) {
				return (string) $response[ $key ];
			}
		}

		return 'Russian Post tariff API returned an error.';
	}

	/**
	 * @return array{success:bool,postal_code:string,error_code:string,error_message:string,raw:array|string|null}
	 */
	private function result( bool $success, string $postal_code, string $error_code, string $error_message, array|string|null $raw ): array {
		if ( ! $success ) {
			$this->logger->debug( 'Russian Post courier tariff probe failed.', array( 'postal_code' => $postal_code, 'error_code' => $error_code, 'error_message' => $error_message ) );
		}

		return array(
			'success' => $success,
			'postal_code' => $this->valid_postcode( $postal_code ),
			'error_code' => $error_code,
			'error_message' => $error_message,
			'raw' => $raw,
		);
	}
}
