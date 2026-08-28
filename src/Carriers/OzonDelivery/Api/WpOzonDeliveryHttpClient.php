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
		$cookie_jar = array();
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$request = array_merge( array( 'method' => strtoupper( $method ), 'timeout' => $this->timeout, 'redirection' => 0 ), $args );
			$cookie_header = $this->cookie_header( $cookie_jar ); if ( '' !== $cookie_header ) { $request['headers'] = array_merge( is_array( $request['headers'] ?? null ) ? $request['headers'] : array(), array( 'Cookie' => $cookie_header ) ); }
			$response = wp_remote_request( $url, $request );
			if ( is_wp_error( $response ) ) { throw new OzonDeliveryApiException( 'http', 'transport_error', 0, true, $this->sanitizer->sanitize( $response->get_error_message(), 'Ошибка соединения с Ozon Delivery.' ) ); }
			$status = (int) wp_remote_retrieve_response_code( $response ); $headers = wp_remote_retrieve_headers( $response );
			if ( ! in_array( $status, array( 302, 307 ), true ) ) { return new OzonDeliveryApiResponse( $status, (string) wp_remote_retrieve_body( $response ), $this->response_headers( $headers ) ); }
			$location = $this->header_value( $headers, 'location' );
			if ( self::MAX_REDIRECTS === $hop || '' === $location || ! $this->is_https_ozon_host( $location, $origin_host ) ) { throw new OzonDeliveryApiException( 'http', 'redirect_rejected', $status, false, 'Ozon Delivery вернул недопустимый redirect.' ); }
			$this->store_cookies( $cookie_jar, $this->header_values( $headers, 'set-cookie' ) );
			$url = $location;
		}
		throw new OzonDeliveryApiException( 'http', 'redirect_limit', 0, false, 'Превышен лимит redirect Ozon Delivery.' );
	}
	/** @param array<string,string> $jar */ private function cookie_header( array $jar ): string { ksort( $jar, SORT_STRING ); $pairs = array(); foreach ( $jar as $name => $value ) { $pairs[] = $name . '=' . $value; } return implode( '; ', $pairs ); }
	/** @param array<string,string> $jar @param array<int,string> $set_cookies */ private function store_cookies( array &$jar, array $set_cookies ): void { foreach ( $set_cookies as $set_cookie ) { $pair = trim( explode( ';', $set_cookie, 2 )[0] ); $position = strpos( $pair, '=' ); if ( false === $position ) { continue; } $name = trim( substr( $pair, 0, $position ) ); $value = trim( substr( $pair, $position + 1 ) ); if ( '' !== $name && preg_match( '/^[^=;\\s]+$/', $name ) ) { $jar[$name] = $value; } } }
	private function header_value( mixed $headers, string $name ): string { $values = $this->header_values( $headers, $name ); return $values[0] ?? ''; }
	/** @return array<int,string> */ private function header_values( mixed $headers, string $name ): array { if ( is_object( $headers ) ) { foreach ( array( 'getValues', 'get_values' ) as $method ) { if ( method_exists( $headers, $method ) ) { return $this->scalar_header_values( $headers->{$method}( $name ) ); } } if ( method_exists( $headers, 'getAll' ) ) { $headers = $headers->getAll(); } elseif ( $headers instanceof \Traversable ) { $headers = iterator_to_array( $headers ); } } if ( ! is_array( $headers ) ) { return array(); } foreach ( $headers as $key => $value ) { if ( strtolower( (string) $key ) === strtolower( $name ) ) { return $this->scalar_header_values( $value ); } } return array(); }
	/** @return array<int,string> */ private function scalar_header_values( mixed $value ): array { if ( is_scalar( $value ) ) { return array( (string) $value ); } if ( ! is_array( $value ) ) { return array(); } $values = array(); foreach ( $value as $item ) { if ( is_scalar( $item ) ) { $values[] = (string) $item; } } return $values; }
	/** @return array<string,string> */ private function response_headers( mixed $headers ): array { if ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) { $headers = $headers->getAll(); } elseif ( $headers instanceof \Traversable ) { $headers = iterator_to_array( $headers ); } if ( ! is_array( $headers ) ) { return array(); } $normalized = array(); foreach ( $headers as $key => $value ) { $values = $this->scalar_header_values( $value ); if ( array() !== $values ) { $normalized[(string) $key] = $values[0]; } } return $normalized; }
	private function is_https_ozon_host( string $url, string $required_host ): bool { return 'https' === strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) ) && $required_host === strtolower( (string) parse_url( $url, PHP_URL_HOST ) ) && in_array( $required_host, array( 'xapi.ozon.ru', 'api-delivery.ozon.ru' ), true ); }
}
