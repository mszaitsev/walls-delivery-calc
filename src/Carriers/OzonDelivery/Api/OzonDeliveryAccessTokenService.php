<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Api;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliveryCredentials;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryAccessTokenService {
	public function __construct( private OzonDeliveryCredentials $credentials, private OzonDeliveryHttpClientInterface $http, private OzonDeliveryMessageSanitizer $sanitizer ) {}
	public function obtain(): string {
		if ( ! $this->credentials->is_complete() ) { throw new OzonDeliveryApiException( 'oauth_token', 'credentials_missing', 0, false, 'Не заполнены Client ID или Client Secret.' ); }
		$body = wp_json_encode( array( 'client_id' => $this->credentials->client_id(), 'client_secret' => $this->credentials->client_secret(), 'grant_type' => 'client_credentials', 'scope' => OzonDeliverySettings::TOKEN_SCOPE ) );
		if ( ! is_string( $body ) ) { throw new OzonDeliveryApiException( 'oauth_token', 'request_encoding_failed', 0, false, 'Не удалось подготовить OAuth-запрос.' ); }
		$response = $this->http->request( 'POST', OzonDeliverySettings::TOKEN_URL, array( 'headers' => array( 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), 'body' => $body ) );
		$data = json_decode( $response->body, true );
		if ( $response->status_code < 200 || $response->status_code >= 300 ) { throw $this->http_error( $response, $data ); }
		if ( ! is_array( $data ) || array_is_list( $data ) || ! isset( $data['access_token'] ) || ! is_string( $data['access_token'] ) || '' === trim( $data['access_token'] ) ) { throw new OzonDeliveryApiException( 'oauth_token', 'token_response_invalid', $response->status_code, false, 'Ozon Delivery вернул некорректный OAuth-ответ.', $this->response_metadata( $response, $data ) ); }
		return $data['access_token'];
	}
	private function http_error( OzonDeliveryApiResponse $response, mixed $data ): OzonDeliveryApiException { $error = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array(); $code = $this->sanitizer->code( $error['code'] ?? '' ); return new OzonDeliveryApiException( 'oauth_token', '' !== $code ? $code : 'oauth_http_error', $response->status_code, 429 === $response->status_code || $response->status_code >= 500, $this->sanitizer->sanitize( $error['message'] ?? null, 'OAuth-запрос Ozon Delivery завершился ошибкой.' ), $this->response_metadata( $response, $data ) ); }
	/** @return array<string,scalar|array<int,string>> */ private function response_metadata( OzonDeliveryApiResponse $response, mixed $data ): array { $keys = is_array( $data ) && ! array_is_list( $data ) ? array_slice( array_values( array_filter( array_keys( $data ), static fn( mixed $key ): bool => is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,80}$/', $key ) ) ), 0, 10 ) : array(); $content_type = ''; foreach ( $response->headers as $name => $value ) { if ( 'content-type' === strtolower( (string) $name ) && is_scalar( $value ) ) { $candidate = strtolower( trim( explode( ';', (string) $value, 2 )[0] ) ); $content_type = 1 === preg_match( '/^[a-z0-9!#$&^_.+-]+\/[a-z0-9!#$&^_.+-]+$/', $candidate ) ? $candidate : ''; } } return array( 'response_content_type' => $content_type, 'response_json' => null !== json_decode( $response->body, true ) || 'null' === trim( $response->body ), 'response_root' => ! is_array( $data ) ? ( json_last_error() === JSON_ERROR_NONE ? 'scalar' : 'invalid_json' ) : ( array_is_list( $data ) ? 'list' : 'object' ), 'response_keys' => $keys, 'response_size_bytes' => min( strlen( $response->body ), 1048576 ) ); }
}
