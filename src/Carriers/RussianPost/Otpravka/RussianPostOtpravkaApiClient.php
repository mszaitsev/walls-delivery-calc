<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost\Otpravka;

defined( 'ABSPATH' ) || exit;

final class RussianPostOtpravkaApiClient {
	private const PASSPORT_ENDPOINT = 'https://otpravka-api.pochta.ru/1.0/unloading-passport/zip';

	public function __construct( private RussianPostOtpravkaApiSettings $settings ) {
	}

	/**
	 * @return array{success:bool,http_code:int,body:string,temp_file:string,error:string,temp_file_size:int}
	 */
	public function download_passport_zip( string $type = 'ALL' ): array {
		$type = strtoupper( trim( $type ) );
		$type = in_array( $type, array( 'ALL', 'OPS', 'PVZ', 'APS' ), true ) ? $type : 'ALL';
		$url  = add_query_arg( array( 'type' => $type ), self::PASSPORT_ENDPOINT );

		$token     = $this->settings->access_token();
		$basic_key = $this->settings->basic_key();
		if ( '' === $token || '' === $basic_key ) {
			return $this->failure( 0, '', '', 'Russian Post Otpravka credentials are incomplete.', '' );
		}

		$temp = wp_tempnam( 'wdc-russian-post-passport.zip' );
		if ( ! is_string( $temp ) || '' === $temp ) {
			return $this->failure( 0, '', '', 'Unable to create temporary file.', '' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $this->settings->timeout(),
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
			return $this->failure( 0, '', '', $error, '', $size );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 || ! is_file( $temp ) || 0 === (int) filesize( $temp ) ) {
			$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
			$message = function_exists( 'wp_remote_retrieve_response_message' ) ? (string) wp_remote_retrieve_response_message( $response ) : '';
			$size = $this->temp_file_size( $temp );
			$this->delete_temp_file( $temp );
			return $this->failure( $code, $message, $this->excerpt( $body ), 'Russian Post Otpravka passport download failed.', '', $size );
		}

		return array( 'success' => true, 'http_code' => $code, 'body' => '', 'temp_file' => $temp, 'error' => '', 'temp_file_size' => $this->temp_file_size( $temp ) );
	}

	private function failure( int $http_code, string $response_message, string $body_excerpt, string $error, string $temp_file = '', int $temp_file_size = 0 ): array {
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
			'http_code' => $http_code,
			'body' => $body_excerpt,
			'temp_file' => $temp_file,
			'error' => implode( ' ', $parts ),
			'temp_file_size' => $temp_file_size,
		);
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
