<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost\Otpravka;

defined( 'ABSPATH' ) || exit;

final class RussianPostOtpravkaApiClient {
	private const PASSPORT_ENDPOINT = 'https://otpravka-api.pochta.ru/1.0/unloading-passport/zip';

	public function __construct( private RussianPostOtpravkaApiSettings $settings ) {
	}

	/**
	 * @return array{success:bool,http_code:int,body:string,temp_file:string,error:string}
	 */
	public function download_passport_zip( string $type = 'ALL' ): array {
		$type = strtoupper( trim( $type ) );
		$type = in_array( $type, array( 'ALL', 'OPS', 'PVZ', 'APS' ), true ) ? $type : 'ALL';
		$url  = add_query_arg( array( 'type' => $type ), self::PASSPORT_ENDPOINT );

		$token     = $this->settings->access_token();
		$basic_key = $this->settings->basic_key();
		if ( '' === $token || '' === $basic_key ) {
			return array( 'success' => false, 'http_code' => 0, 'body' => '', 'temp_file' => '', 'error' => 'Russian Post Otpravka credentials are incomplete.' );
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => $this->settings->timeout(),
				'headers' => array(
					'Authorization' => 'AccessToken ' . $token,
					'X-User-Authorization' => 'Basic ' . $basic_key,
					'Accept' => 'application/octet-stream',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array( 'success' => false, 'http_code' => 0, 'body' => '', 'temp_file' => '', 'error' => $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( $code < 200 || $code >= 300 || '' === $body ) {
			return array( 'success' => false, 'http_code' => $code, 'body' => $body, 'temp_file' => '', 'error' => 'Russian Post Otpravka passport download failed.' );
		}

		$temp = wp_tempnam( 'wdc-russian-post-passport.zip' );
		if ( ! is_string( $temp ) || '' === $temp ) {
			return array( 'success' => false, 'http_code' => $code, 'body' => '', 'temp_file' => '', 'error' => 'Unable to create temporary file.' );
		}

		$written = file_put_contents( $temp, $body );
		if ( false === $written ) {
			return array( 'success' => false, 'http_code' => $code, 'body' => '', 'temp_file' => '', 'error' => 'Unable to write temporary ZIP file.' );
		}

		return array( 'success' => true, 'http_code' => $code, 'body' => '', 'temp_file' => $temp, 'error' => '' );
	}
}
