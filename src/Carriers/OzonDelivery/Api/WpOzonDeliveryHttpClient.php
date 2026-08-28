<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Api;
defined( 'ABSPATH' ) || exit;
final class WpOzonDeliveryHttpClient implements OzonDeliveryHttpClientInterface {
	private const MAX_REDIRECTS = 3;
	public function __construct( private int $timeout, private OzonDeliveryMessageSanitizer $sanitizer ) {}
	/** @param array<string,mixed> $args */
	public function request( string $method, string $url, array $args = array() ): OzonDeliveryApiResponse {
		$origin_host = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
		if ( ! $this->is_https_ozon_host( $url, $origin_host ) ) { throw new OzonDeliveryApiException( 'http', 'invalid_url', 0, false, 'Недопустимый URL Ozon Delivery.' ); }
		$cookie = '';
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$request = array_merge( array( 'method' => strtoupper( $method ), 'timeout' => $this->timeout, 'redirection' => 0 ), $args );
			if ( '' !== $cookie ) { $request['headers'] = array_merge( is_array( $request['headers'] ?? null ) ? $request['headers'] : array(), array( 'Cookie' => $cookie ) ); }
			$response = wp_remote_request( $url, $request );
			if ( is_wp_error( $response ) ) { throw new OzonDeliveryApiException( 'http', 'transport_error', 0, true, $this->sanitizer->sanitize( $response->get_error_message(), 'Ошибка соединения с Ozon Delivery.' ) ); }
			$status = (int) wp_remote_retrieve_response_code( $response ); $headers = (array) wp_remote_retrieve_headers( $response );
			if ( ! in_array( $status, array( 302, 307 ), true ) ) { return new OzonDeliveryApiResponse( $status, (string) wp_remote_retrieve_body( $response ), $headers ); }
			$location = $this->header( $headers, 'location' );
			if ( self::MAX_REDIRECTS === $hop || '' === $location || ! $this->is_https_ozon_host( $location, $origin_host ) ) { throw new OzonDeliveryApiException( 'http', 'redirect_rejected', $status, false, 'Ozon Delivery вернул недопустимый redirect.' ); }
			$set_cookie = $this->header( $headers, 'set-cookie' ); if ( '' !== $set_cookie ) { $cookie = trim( explode( ';', $set_cookie, 2 )[0] ); }
			$url = $location;
		}
		throw new OzonDeliveryApiException( 'http', 'redirect_limit', 0, false, 'Превышен лимит redirect Ozon Delivery.' );
	}
	/** @param array<string,mixed> $headers */ private function header( array $headers, string $name ): string { foreach ( $headers as $key => $value ) { if ( strtolower( (string) $key ) === $name ) { return is_scalar( $value ) ? (string) $value : ''; } } return ''; }
	private function is_https_ozon_host( string $url, string $required_host ): bool { return 'https' === strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ) && $required_host === strtolower( (string) parse_url( $url, PHP_URL_HOST ) ) && in_array( $required_host, array( 'xapi.ozon.ru', 'api-delivery.ozon.ru' ), true ); }
}
