<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost\Otpravka;

defined( 'ABSPATH' ) || exit;

final class RussianPostOtpravkaApiClient {
	private const PASSPORT_ENDPOINT = 'https://otpravka-api.pochta.ru/1.0/unloading-passport/zip';
	private const BACKLOG_ENDPOINT = 'https://otpravka-api.pochta.ru/2.0/user/backlog';
	private const BACKLOG_DELETE_ENDPOINT = 'https://otpravka-api.pochta.ru/1.0/backlog';
	private const BACKLOG_FORMS_ENDPOINT = 'https://otpravka-api.pochta.ru/1.0/forms/backlog';
	private const BACKLOG_SEARCH_ENDPOINT = 'https://otpravka-api.pochta.ru/1.0/backlog/search';
	private const SHIPMENT_SEARCH_ENDPOINT = 'https://otpravka-api.pochta.ru/1.0/shipment/search';
	private const CLEAN_ADDRESS_ENDPOINT = 'https://otpravka-api.pochta.ru/1.0/clean/address';

	public function __construct( private RussianPostOtpravkaApiSettings $settings, private mixed $curl_downloader = null ) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function download_passport_zip( string $type = 'ALL' ): array {
		$type = strtoupper( trim( $type ) );
		$type = in_array( $type, array( 'ALL', 'OPS', 'PVZ', 'APS' ), true ) ? $type : 'ALL';
		$url  = $this->passport_url( $type );
		$started = microtime( true );

		$token     = $this->settings->access_token();
		$basic_key = $this->settings->basic_key();
		if ( '' === $token || '' === $basic_key ) {
			return $this->failure( 0, '', '', 'Russian Post Otpravka credentials are incomplete.', '', 0, $url, $type, $started, '', 'credentials' );
		}

		$timeout = $this->settings->timeout();
		$first_error = '';
		$first_curl_errno = 0;
		$first_curl_error = '';
		if ( is_callable( $this->curl_downloader ) || function_exists( 'curl_init' ) ) {
			$curl = is_callable( $this->curl_downloader )
				? (array) call_user_func( $this->curl_downloader, $url, $type, $token, $basic_key, $timeout )
				: $this->download_with_curl( $url, $type, $token, $basic_key, $timeout );
			$curl = $this->with_download_defaults( $curl, $url, $type, 'curl', false, '' );
			if ( ! empty( $curl['success'] ) ) {
				return $curl;
			}
			$this->delete_temp_file( (string) ( $curl['temp_file'] ?? '' ) );
			$first_error = 'cURL failed: ' . (string) ( $curl['error'] ?? 'unknown error' );
			$first_curl_errno = (int) ( $curl['curl_errno'] ?? 0 );
			$first_curl_error = (string) ( $curl['curl_error'] ?? '' );
		}

		$wp = $this->download_with_wp_http( $url, $type, $token, $basic_key, $timeout );
		$wp = $this->with_download_defaults( $wp, $url, $type, 'wp_http', '' !== $first_error, $first_error );
		if ( '' !== $first_error ) {
			$wp['curl_errno'] = $first_curl_errno;
			$wp['curl_error'] = $first_curl_error;
		}
		if ( empty( $wp['success'] ) && '' !== $first_error ) {
			$wp['error'] = $first_error . '; WP HTTP failed: ' . (string) ( $wp['error'] ?? 'unknown error' );
		}

		return $wp;
	}

	/**
	 * @param array<int,array<string,mixed>> $orders
	 * @return array<string,mixed>
	 */
	public function create_backlog_orders( array $orders ): array {
		$started = microtime( true );
		$token = $this->settings->access_token();
		$basic_key = $this->settings->basic_key();
		if ( '' === $token || '' === $basic_key ) {
			return array(
				'success' => false,
				'http_code' => 0,
				'orders' => array(),
				'errors' => array(),
				'error_code' => 'credentials',
				'error_message' => 'Russian Post Otpravka credentials are incomplete.',
				'duration_ms' => $this->duration_ms( $started ),
			);
		}

		$response = wp_remote_request(
			self::BACKLOG_ENDPOINT,
			array(
				'method' => 'PUT',
				'timeout' => $this->settings->timeout(),
				'headers' => array(
					'Authorization' => 'AccessToken ' . $token,
					'X-User-Authorization' => 'Basic ' . $basic_key,
					'Content-Type' => 'application/json;charset=UTF-8',
				),
				'body' => wp_json_encode( array_values( $orders ), JSON_UNESCAPED_UNICODE ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'http_code' => 0,
				'orders' => array(),
				'errors' => array(),
				'error_code' => 'wp_http_error',
				'error_message' => $response->get_error_message(),
				'duration_ms' => $this->duration_ms( $started ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		$data = is_array( $decoded ) ? $decoded : array();
		$errors = is_array( $data['errors'] ?? null ) ? $data['errors'] : array();
		$orders_result = is_array( $data['orders'] ?? null ) ? $data['orders'] : array();

		return array(
			'success' => $code >= 200 && $code < 300 && array() === $errors,
			'http_code' => $code,
			'orders' => $orders_result,
			'errors' => $errors,
			'error_code' => $code >= 200 && $code < 300 ? '' : 'http_' . $code,
			'error_message' => $code >= 200 && $code < 300 ? '' : $this->excerpt( $body ),
			'duration_ms' => $this->duration_ms( $started ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function download_backlog_forms( int|string $backlog_id ): array {
		$id = trim( (string) $backlog_id );
		if ( '' === $id ) {
			return array( 'success' => false, 'http_code' => 0, 'body' => '', 'content_type' => '', 'error_code' => 'missing_backlog_id', 'error_message' => 'Для отправления не найден идентификатор Почты России.' );
		}
		$started = microtime( true );
		if ( '' === $this->settings->access_token() || '' === $this->settings->basic_key() ) {
			return $this->credentials_result( $started );
		}

		$url = self::BACKLOG_FORMS_ENDPOINT . '/' . rawurlencode( $id ) . '/forms';
		if ( is_callable( $this->curl_downloader ) ) {
			$response = (array) call_user_func( $this->curl_downloader, $url, 'BACKLOG_FORMS', $this->settings->access_token(), $this->settings->basic_key(), $this->settings->timeout() );
			return $this->backlog_forms_result( $response, $started, $url );
		}

		$response = wp_remote_request(
			$url,
			array(
				'method' => 'GET',
				'timeout' => $this->settings->timeout(),
				'headers' => array(
					'Authorization' => 'AccessToken ' . $this->settings->access_token(),
					'X-User-Authorization' => 'Basic ' . $this->settings->basic_key(),
					'Content-Type' => 'application/json;charset=UTF-8',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'http_code' => 0,
				'body' => '',
				'content_type' => '',
				'error_code' => 'wp_http_error',
				'error_message' => $response->get_error_message(),
				'url' => $url,
				'duration_ms' => $this->duration_ms( $started ),
			);
		}

		return $this->backlog_forms_result(
			array(
				'http_code' => (int) wp_remote_retrieve_response_code( $response ),
				'body' => (string) wp_remote_retrieve_body( $response ),
				'content_type' => (string) wp_remote_retrieve_header( $response, 'content-type' ),
			),
			$started,
			$url
		);
	}

	/**
	 * @param array<int,int|string> $ids
	 * @return array<string,mixed>
	 */
	public function delete_backlog_orders( array $ids ): array {
		$started = microtime( true );
		$ids = array_values(
			array_filter(
				array_map( static fn ( mixed $id ): int => (int) $id, $ids ),
				static fn ( int $id ): bool => $id > 0
			)
		);
		if ( array() === $ids ) {
			return array(
				'success' => false,
				'http_code' => 0,
				'result_ids' => array(),
				'errors' => array(),
				'error_code' => 'empty_ids',
				'error_message' => 'Не указан внутренний ID отправления Почты России.',
				'duration_ms' => $this->duration_ms( $started ),
				'request_body' => '[]',
			);
		}

		$credentials = $this->credentials_result( $started );
		if ( array() !== $credentials ) {
			return $credentials + array( 'result_ids' => array(), 'errors' => array(), 'request_body' => wp_json_encode( $ids ) ?: '[]' );
		}

		$body = wp_json_encode( $ids, JSON_UNESCAPED_UNICODE );
		$body = is_string( $body ) ? $body : '[' . implode( ',', $ids ) . ']';
		$response = wp_remote_request(
			self::BACKLOG_DELETE_ENDPOINT,
			array(
				'method' => 'DELETE',
				'timeout' => $this->settings->timeout(),
				'headers' => $this->json_headers(),
				'body' => $body,
			)
		);

		return $this->backlog_mutation_result( $response, $started, $ids, $body );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function search_backlog_by_barcode( string $barcode ): array {
		return $this->search_by_barcode( $barcode, self::BACKLOG_SEARCH_ENDPOINT );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function search_shipment_by_barcode( string $barcode ): array {
		return $this->search_by_barcode( $barcode, self::SHIPMENT_SEARCH_ENDPOINT );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function search_by_barcode( string $barcode, string $endpoint ): array {
		$started = microtime( true );
		$barcode = strtoupper( preg_replace( '/\s+/', '', trim( $barcode ) ) ?? '' );
		if ( '' === $barcode ) {
			return array(
				'success' => false,
				'http_code' => 0,
				'orders' => array(),
				'error_code' => 'empty_barcode',
				'error_message' => 'Укажите номер отслеживания.',
				'duration_ms' => $this->duration_ms( $started ),
			);
		}

		$credentials = $this->credentials_result( $started );
		if ( array() !== $credentials ) {
			return $credentials + array( 'orders' => array() );
		}

		$url = add_query_arg( array( 'query' => $barcode ), $endpoint );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $this->settings->timeout(),
				'headers' => $this->json_headers(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'http_code' => 0,
				'orders' => array(),
				'error_code' => 'wp_http_error',
				'error_message' => $response->get_error_message(),
				'duration_ms' => $this->duration_ms( $started ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'success' => false,
				'http_code' => $code,
				'orders' => array(),
				'error_code' => 'json_parse_error',
				'error_message' => 'Не удалось разобрать ответ Почты России.',
				'body_excerpt' => $this->excerpt( $body ),
				'duration_ms' => $this->duration_ms( $started ),
			);
		}

		return array(
			'success' => $code >= 200 && $code < 300,
			'http_code' => $code,
			'orders' => array_values( $decoded ),
			'error_code' => $code >= 200 && $code < 300 ? '' : 'http_' . $code,
			'error_message' => $code >= 200 && $code < 300 ? '' : $this->excerpt( $body ),
			'duration_ms' => $this->duration_ms( $started ),
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $addresses
	 * @return array<string,mixed>
	 */
	public function clean_address( array $addresses ): array {
		$started = microtime( true );
		$token = $this->settings->access_token();
		$basic_key = $this->settings->basic_key();
		if ( '' === $token || '' === $basic_key ) {
			return array(
				'success' => false,
				'http_code' => 0,
				'addresses' => array(),
				'error_code' => 'credentials',
				'error_message' => 'Russian Post Otpravka credentials are incomplete.',
				'duration_ms' => $this->duration_ms( $started ),
			);
		}

		$response = wp_remote_post(
			self::CLEAN_ADDRESS_ENDPOINT,
			array(
				'timeout' => $this->settings->timeout(),
				'headers' => array(
					'Authorization' => 'AccessToken ' . $token,
					'X-User-Authorization' => 'Basic ' . $basic_key,
					'Content-Type' => 'application/json;charset=UTF-8',
				),
				'body' => wp_json_encode( array_values( $addresses ), JSON_UNESCAPED_UNICODE ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'http_code' => 0,
				'addresses' => array(),
				'error_code' => 'wp_http_error',
				'error_message' => $response->get_error_message(),
				'duration_ms' => $this->duration_ms( $started ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		$addresses_result = is_array( $decoded ) ? $decoded : array();

		return array(
			'success' => $code >= 200 && $code < 300 && is_array( $decoded ),
			'http_code' => $code,
			'addresses' => $addresses_result,
			'error_code' => $code >= 200 && $code < 300 ? '' : 'http_' . $code,
			'error_message' => $code >= 200 && $code < 300 ? '' : $this->excerpt( $body ),
			'duration_ms' => $this->duration_ms( $started ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function download_with_wp_http( string $url, string $type, string $token, string $basic_key, int $timeout ): array {
		$started = microtime( true );
		$temp = wp_tempnam( 'wdc-russian-post-passport.zip' );
		if ( ! is_string( $temp ) || '' === $temp ) {
			return $this->failure( 0, '', '', 'Unable to create temporary file.', '', 0, $url, $type, $started, '', 'wp_http' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $timeout,
				'connect_timeout' => min( 15, $timeout ),
				'stream' => true,
				'filename' => $temp,
				'headers' => array(
					'Authorization' => 'AccessToken ' . $token,
					'X-User-Authorization' => 'Basic ' . $basic_key,
					'Accept' => 'application/octet-stream',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$size = $this->temp_file_size( $temp );
			$error = $response->get_error_message();
			$this->delete_temp_file( $temp );
			return $this->failure( 0, '', '', $error, '', $size, $url, $type, $started, $error, 'wp_http' );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$message = function_exists( 'wp_remote_retrieve_response_message' ) ? (string) wp_remote_retrieve_response_message( $response ) : '';
		if ( $code < 200 || $code >= 300 || ! is_file( $temp ) || 0 === (int) filesize( $temp ) ) {
			$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
			$size = $this->temp_file_size( $temp );
			$this->delete_temp_file( $temp );
			return $this->failure( $code, $message, $this->excerpt( $body ), 'Russian Post Otpravka passport download failed.', '', $size, $url, $type, $started, '', 'wp_http' );
		}

		return array(
			'success' => true,
			'url' => $url,
			'type' => $type,
			'http_code' => $code,
			'response_message' => $message,
			'body' => '',
			'body_excerpt' => '',
			'temp_file' => $temp,
			'error' => '',
			'wp_error_message' => '',
			'temp_file_size' => $this->temp_file_size( $temp ),
			'duration_ms' => $this->duration_ms( $started ),
			'download_backend' => 'wp_http',
			'fallback_used' => false,
			'first_backend_error' => '',
			'curl_errno' => 0,
			'curl_error' => '',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function download_with_curl( string $url, string $type, string $token, string $basic_key, int $timeout ): array {
		$started = microtime( true );
		$temp = wp_tempnam( 'wdc-russian-post-passport.zip' );
		if ( ! is_string( $temp ) || '' === $temp ) {
			return $this->failure( 0, '', '', 'Unable to create temporary file.', '', 0, $url, $type, $started, '', 'curl' );
		}
		$handle = fopen( $temp, 'wb' );
		if ( ! is_resource( $handle ) ) {
			$this->delete_temp_file( $temp );
			return $this->failure( 0, '', '', 'Unable to open temporary file for cURL download.', '', 0, $url, $type, $started, '', 'curl' );
		}

		$curl = curl_init( $url );
		if ( false === $curl ) {
			fclose( $handle );
			$this->delete_temp_file( $temp );
			return $this->failure( 0, '', '', 'Unable to initialize cURL.', '', 0, $url, $type, $started, '', 'curl' );
		}
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_FILE => $handle,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_CONNECTTIMEOUT => min( 15, $timeout ),
				CURLOPT_TIMEOUT => $timeout,
				CURLOPT_HTTPHEADER => array(
					'Authorization: AccessToken ' . $token,
					'X-User-Authorization: Basic ' . $basic_key,
					'Accept: application/octet-stream',
				),
			)
		);
		$ok = curl_exec( $curl );
		$errno = (int) curl_errno( $curl );
		$error = (string) curl_error( $curl );
		$code = (int) curl_getinfo( $curl, CURLINFO_RESPONSE_CODE );
		$duration = defined( 'CURLINFO_TOTAL_TIME_T' ) ? (int) round( (int) curl_getinfo( $curl, CURLINFO_TOTAL_TIME_T ) / 1000 ) : $this->duration_ms( $started );
		curl_close( $curl );
		fclose( $handle );
		$size = $this->temp_file_size( $temp );

		if ( true !== $ok || 0 !== $errno || $code < 200 || $code >= 300 || $size <= 0 ) {
			$this->delete_temp_file( $temp );
			$message = 0 !== $errno ? $error : 'Russian Post Otpravka passport cURL download failed.';
			$result = $this->failure( $code, '', '', $message, '', $size, $url, $type, $started, '', 'curl' );
			$result['duration_ms'] = $duration;
			$result['curl_errno'] = $errno;
			$result['curl_error'] = $error;

			return $result;
		}

		return array(
			'success' => true,
			'url' => $url,
			'type' => $type,
			'http_code' => $code,
			'response_message' => '',
			'body' => '',
			'body_excerpt' => '',
			'temp_file' => $temp,
			'error' => '',
			'wp_error_message' => '',
			'temp_file_size' => $size,
			'duration_ms' => $duration,
			'download_backend' => 'curl',
			'fallback_used' => false,
			'first_backend_error' => '',
			'curl_errno' => 0,
			'curl_error' => '',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function probe_passport_download( string $type = 'ALL' ): array {
		$result = $this->download_passport_zip( $type );
		$this->delete_temp_file( (string) ( $result['temp_file'] ?? '' ) );
		$result['temp_file'] = '';

		return $result;
	}

	public function passport_url( string $type = 'ALL' ): string {
		$type = strtoupper( trim( $type ) );
		$type = in_array( $type, array( 'ALL', 'OPS', 'PVZ', 'APS' ), true ) ? $type : 'ALL';

		return add_query_arg( array( 'type' => $type ), self::PASSPORT_ENDPOINT );
	}

	private function failure( int $http_code, string $response_message, string $body_excerpt, string $error, string $temp_file = '', int $temp_file_size = 0, string $url = '', string $type = 'ALL', float $started = 0.0, string $wp_error_message = '', string $backend = '' ): array {
		$parts = array( $error );
		if ( '' !== $response_message ) {
			$parts[] = 'Response: ' . $response_message;
		}
		if ( '' !== $body_excerpt ) {
			$parts[] = 'Body excerpt: ' . $body_excerpt;
		}
		if ( $temp_file_size > 0 ) {
			$parts[] = 'Temp file size: ' . $temp_file_size . ' bytes';
		}

		return array(
			'success' => false,
			'url' => $url,
			'type' => $type,
			'http_code' => $http_code,
			'response_message' => $response_message,
			'body' => $body_excerpt,
			'body_excerpt' => $body_excerpt,
			'temp_file' => $temp_file,
			'error' => implode( ' ', $parts ),
			'wp_error_message' => $wp_error_message,
			'temp_file_size' => $temp_file_size,
			'duration_ms' => $this->duration_ms( $started ),
			'download_backend' => $backend,
			'fallback_used' => false,
			'first_backend_error' => '',
			'curl_errno' => 0,
			'curl_error' => '',
		);
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private function with_download_defaults( array $result, string $url, string $type, string $backend, bool $fallback_used, string $first_error ): array {
		$result['url'] = (string) ( $result['url'] ?? $url );
		$result['type'] = (string) ( $result['type'] ?? $type );
		$result['download_backend'] = (string) ( $result['download_backend'] ?? $backend );
		$result['fallback_used'] = $fallback_used;
		$result['first_backend_error'] = $first_error;
		$result['curl_errno'] = (int) ( $result['curl_errno'] ?? 0 );
		$result['curl_error'] = (string) ( $result['curl_error'] ?? '' );

		return $result;
	}

	private function excerpt( string $body ): string {
		$body = trim( $body );
		if ( strlen( $body ) <= 1000 ) {
			return $body;
		}

		return substr( $body, 0, 1000 );
	}

	/**
	 * @param array<string,mixed> $response
	 * @return array<string,mixed>
	 */
	private function backlog_forms_result( array $response, float $started, string $url ): array {
		$code = (int) ( $response['http_code'] ?? 0 );
		$body = (string) ( $response['body'] ?? '' );
		$content_type = (string) ( $response['content_type'] ?? '' );
		$is_pdf = '' !== $body && str_starts_with( ltrim( $body ), '%PDF-' );
		if ( $code >= 200 && $code < 300 && $is_pdf ) {
			return array(
				'success' => true,
				'http_code' => $code,
				'body' => $body,
				'content_type' => '' !== $content_type ? $content_type : 'application/pdf',
				'url' => $url,
				'duration_ms' => $this->duration_ms( $started ),
			);
		}

		$error_message = 'Почта России не вернула PDF печатной формы.';
		$decoded = '' !== trim( $body ) && ( str_contains( strtolower( $content_type ), 'json' ) || str_starts_with( ltrim( $body ), '{' ) || str_starts_with( ltrim( $body ), '[' ) )
			? json_decode( $body, true )
			: null;
		if ( is_array( $decoded ) ) {
			$error_message = $this->api_error_message( $decoded, $error_message );
		}

		return array(
			'success' => false,
			'http_code' => $code,
			'body' => '',
			'content_type' => $content_type,
			'error_code' => $code >= 200 && $code < 300 ? 'invalid_pdf' : 'http_' . $code,
			'error_message' => $error_message,
			'url' => $url,
			'response_excerpt' => $this->excerpt( $body ),
			'duration_ms' => $this->duration_ms( $started ),
		);
	}

	/** @param array<string,mixed> $data */
	private function api_error_message( array $data, string $fallback ): string {
		foreach ( array( 'message', 'errorMessage', 'error', 'desc', 'description' ) as $key ) {
			if ( isset( $data[ $key ] ) && '' !== trim( (string) $data[ $key ] ) ) {
				return trim( (string) $data[ $key ] );
			}
		}
		foreach ( $data as $value ) {
			if ( is_array( $value ) ) {
				$found = $this->api_error_message( $value, '' );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}

		return $fallback;
	}

	/**
	 * @return array<string,string>
	 */
	private function json_headers(): array {
		return array(
			'Authorization' => 'AccessToken ' . $this->settings->access_token(),
			'X-User-Authorization' => 'Basic ' . $this->settings->basic_key(),
			'Content-Type' => 'application/json;charset=UTF-8',
			'Accept' => 'application/json',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function credentials_result( float $started ): array {
		if ( '' !== $this->settings->access_token() && '' !== $this->settings->basic_key() ) {
			return array();
		}

		return array(
			'success' => false,
			'http_code' => 0,
			'error_code' => 'credentials',
			'error_message' => 'Не заполнены учетные данные Почты России.',
			'duration_ms' => $this->duration_ms( $started ),
		);
	}

	/**
	 * @param array<int,int> $requested_ids
	 * @return array<string,mixed>
	 */
	private function backlog_mutation_result( mixed $response, float $started, array $requested_ids, string $request_body ): array {
		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'http_code' => 0,
				'result_ids' => array(),
				'errors' => array(),
				'error_code' => 'wp_http_error',
				'error_message' => $response->get_error_message(),
				'duration_ms' => $this->duration_ms( $started ),
				'request_body' => $request_body,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return array(
				'success' => false,
				'http_code' => $code,
				'result_ids' => array(),
				'errors' => array(),
				'error_code' => 'json_parse_error',
				'error_message' => 'Не удалось разобрать ответ Почты России.',
				'body_excerpt' => $this->excerpt( $body ),
				'duration_ms' => $this->duration_ms( $started ),
				'request_body' => $request_body,
			);
		}

		$result_ids = array_values( array_map( static fn ( mixed $id ): int => (int) $id, is_array( $decoded['result-ids'] ?? null ) ? $decoded['result-ids'] : array() ) );
		$errors = is_array( $decoded['errors'] ?? null ) ? $decoded['errors'] : array();
		$missing = array_values( array_diff( $requested_ids, $result_ids ) );
		$success = $code >= 200 && $code < 300 && array() === $errors && array() === $missing;

		return array(
			'success' => $success,
			'http_code' => $code,
			'result_ids' => $result_ids,
			'errors' => $errors,
			'error_code' => $success ? '' : ( array() !== $errors ? 'russian_post_backlog_error' : ( $code >= 200 && $code < 300 ? 'missing_result_id' : 'http_' . $code ) ),
			'error_message' => $success ? '' : $this->backlog_error_message( $errors, $body, $missing ),
			'duration_ms' => $this->duration_ms( $started ),
			'request_body' => $request_body,
		);
	}

	/**
	 * @param array<int,mixed> $errors
	 * @param array<int,int> $missing
	 */
	private function backlog_error_message( array $errors, string $body, array $missing ): string {
		if ( array() !== $errors ) {
			return implode(
				'; ',
				array_map(
					static fn ( mixed $error ): string => is_array( $error )
						? trim( (string) ( $error['error-code'] ?? $error['code'] ?? '' ) . ' ' . (string) ( $error['error-details'] ?? $error['details'] ?? $error['message'] ?? '' ) )
						: (string) $error,
					$errors
				)
			);
		}
		if ( array() !== $missing ) {
			return 'Почта России не подтвердила удаление отправления.';
		}

		return $this->excerpt( $body );
	}

	private function temp_file_size( string $temp_file ): int {
		return '' !== $temp_file && is_file( $temp_file ) ? (int) filesize( $temp_file ) : 0;
	}

	private function duration_ms( float $started ): int {
		return $started > 0 ? max( 0, (int) round( ( microtime( true ) - $started ) * 1000 ) ) : 0;
	}

	private function delete_temp_file( string $temp_file ): void {
		if ( '' === $temp_file || ! is_file( $temp_file ) ) {
			return;
		}
		if ( function_exists( 'wp_delete_file' ) ) {
			wp_delete_file( $temp_file );
			return;
		}
		@unlink( $temp_file );
	}
}
