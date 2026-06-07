<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class RussianPostCourierTariffProbeService {
	private const ENDPOINT = 'https://tariff.pochta.ru/v2/calculate/tariff';
	private const FROM_POSTCODE = '630005';
	private const MIN_REQUEST_INTERVAL_MICROSECONDS = 150000;
	private const UNAVAILABLE_ERROR_CODES = array( '2005', '2007', '2008', '2009', '2010' );

	private float $last_request_at = 0.0;

	public function __construct( private Logger $logger ) {
	}

	/**
	 * @return array{success:bool,unavailable:bool,api_error:bool,failed:bool,http_code:int|null,postal_code:string,paynds:int|null,error_code:string,error_message:string,raw:array|string|null}
	 */
	public function probe( string $to_postal_code ): array {
		$postal_code = $this->valid_postcode( $to_postal_code );
		if ( '' === $postal_code ) {
			return $this->result( false, true, null, $to_postal_code, null, 'invalid_postal_code', 'Postal code must contain exactly 6 digits.', null );
		}
		if ( ! function_exists( 'wp_remote_get' ) ) {
			return $this->result( false, true, null, $postal_code, null, 'wp_http_unavailable', 'WordPress HTTP API is unavailable.', null );
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
		$url = $this->build_url( $params );
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
			return $this->result( false, true, null, $postal_code, null, 'http_error', $message, null );
		}

		$code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return $this->result( false, true, $code, $postal_code, null, 'invalid_json', 'Russian Post tariff API returned invalid JSON.', $body );
		}
		if ( 400 === $code ) {
			if ( $this->has_api_error( $decoded ) ) {
				$error_code = $this->extract_error_code( $decoded ) ?: 'api_error';
				$message = $this->extract_error_message( $decoded );
				if ( in_array( $error_code, self::UNAVAILABLE_ERROR_CODES, true ) ) {
					return $this->result( false, false, $code, $postal_code, null, $error_code, $message, $decoded, true );
				}

				return $this->result( false, true, $code, $postal_code, null, $error_code, $message, $decoded );
			}

			return $this->result( false, true, $code, $postal_code, null, 'http_status_400', 'Russian Post tariff API returned HTTP 400 without a recognized API error.', $decoded );
		}
		if ( 200 !== $code ) {
			return $this->result( false, true, $code, $postal_code, null, 'http_status_' . $code, 'Russian Post tariff API returned HTTP ' . $code . '.', $decoded );
		}
		if ( $this->has_api_error( $decoded ) ) {
			return $this->result( false, true, $code, $postal_code, null, $this->extract_error_code( $decoded ) ?: 'api_error', $this->extract_error_message( $decoded ), $decoded );
		}
		$paynds = $this->paynds( $decoded );
		if ( null === $paynds ) {
			return $this->result( false, true, $code, $postal_code, null, 'empty_price', 'Russian Post tariff API returned no paynds price.', $decoded );
		}

		return $this->result( true, false, $code, $postal_code, $paynds, '', '', $decoded );
	}

	/**
	 * @param array<string,int|string> $params
	 */
	private function build_url( array $params ): string {
		return self::ENDPOINT . '?json&' . http_build_query( $params );
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
	private function paynds( array $response ): ?int {
		if ( isset( $response['paynds'] ) && is_numeric( $response['paynds'] ) && (int) $response['paynds'] > 0 ) {
			return (int) $response['paynds'];
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $response
	 */
	private function extract_error_code( array $response ): string {
		$nested = $this->first_error_item( $response );
		if ( array() !== $nested && isset( $nested['code'] ) && is_scalar( $nested['code'] ) ) {
			return (string) $nested['code'];
		}
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
		$nested = $this->first_error_item( $response );
		if ( array() !== $nested ) {
			foreach ( array( 'msg', 'message', 'error_message', 'error' ) as $key ) {
				if ( isset( $nested[ $key ] ) && is_scalar( $nested[ $key ] ) && '' !== trim( (string) $nested[ $key ] ) ) {
					return (string) $nested[ $key ];
				}
			}
		}
		foreach ( array( 'errormsg', 'message', 'error_message', 'error' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_scalar( $response[ $key ] ) && '' !== trim( (string) $response[ $key ] ) ) {
				return (string) $response[ $key ];
			}
		}

		return 'Russian Post tariff API returned an error.';
	}

	/**
	 * @param array<string,mixed> $response
	 * @return array<string,mixed>
	 */
	private function first_error_item( array $response ): array {
		foreach ( array( 'errors', 'error' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
				$first = reset( $response[ $key ] );
				if ( is_array( $first ) ) {
					return $first;
				}
			}
		}

		return array();
	}

	/**
	 * @return array{success:bool,unavailable:bool,api_error:bool,failed:bool,http_code:int|null,postal_code:string,paynds:int|null,error_code:string,error_message:string,raw:array|string|null}
	 */
	private function result( bool $success, bool $api_error, ?int $http_code, string $postal_code, ?int $paynds, string $error_code, string $error_message, array|string|null $raw, bool $unavailable = false ): array {
		if ( ! $success && $api_error ) {
			$this->logger->debug( 'Russian Post courier tariff probe failed.', array( 'postal_code' => $postal_code, 'error_code' => $error_code, 'error_message' => $error_message ) );
		}

		return array(
			'success' => $success,
			'unavailable' => $unavailable,
			'api_error' => $api_error,
			'failed' => $api_error,
			'http_code' => $http_code,
			'postal_code' => $this->valid_postcode( $postal_code ),
			'paynds' => $paynds,
			'error_code' => $error_code,
			'error_message' => $error_message,
			'raw' => $raw,
		);
	}
}
