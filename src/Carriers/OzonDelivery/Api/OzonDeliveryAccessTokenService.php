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
		if ( $response->status_code < 200 || $response->status_code >= 300 ) { throw $this->http_error( $response->status_code, $data ); }
		if ( ! is_array( $data ) || array_is_list( $data ) || ! isset( $data['access_token'] ) || ! is_string( $data['access_token'] ) || '' === trim( $data['access_token'] ) ) { throw new OzonDeliveryApiException( 'oauth_token', 'token_response_invalid', $response->status_code, false, 'Ozon Delivery вернул некорректный OAuth-ответ.' ); }
		return $data['access_token'];
	}
	private function http_error( int $status, mixed $data ): OzonDeliveryApiException { $error = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : array(); $code = $this->sanitizer->code( $error['code'] ?? '' ); return new OzonDeliveryApiException( 'oauth_token', '' !== $code ? $code : 'oauth_http_error', $status, 429 === $status || $status >= 500, $this->sanitizer->sanitize( $error['message'] ?? null, 'OAuth-запрос Ozon Delivery завершился ошибкой.' ) ); }
}
