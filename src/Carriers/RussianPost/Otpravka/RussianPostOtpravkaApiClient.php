<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost\Otpravka;

defined( 'ABSPATH' ) || exit;

final class RussianPostOtpravkaApiClient {
	private const PASSPORT_ENDPOINT = 'https://otpravka-api.pochta.ru/1.0/unloading-passport/zip';

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
