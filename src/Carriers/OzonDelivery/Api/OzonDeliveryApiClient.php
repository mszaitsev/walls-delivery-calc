<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Api;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryApiClient {
	public function __construct( private OzonDeliveryHttpClientInterface $http, private OzonDeliveryAccessTokenService $tokens ) {}
	/** @return array<string,mixed> */ public function pickup_list( ?string $cursor = null ): array { return $this->authorized_json_request( 'POST', '/v1/delivery-point/list', $this->pickup_list_body( $cursor ) ); }
	/** @return array<string,array<string,string|int>> */ private function pickup_list_body( ?string $cursor ): array { $pagination = array( 'limit' => 100 ); $cursor = is_string( $cursor ) ? trim( $cursor ) : ''; if ( '' !== $cursor ) { $pagination['cursor'] = $cursor; } return array( 'pagination' => $pagination ); }
	/** @param array<int,int> $ids @return array<string,mixed> */ public function pickup_info( array $ids ): array { if ( array() === $ids || count( $ids ) > 100 ) { throw new OzonDeliveryApiException( 'pickup_info', 'request_invalid', 0, false, 'Некорректный запрос ПВЗ Ozon Delivery.' ); } return $this->authorized_json_request( 'POST', '/v1/delivery-point/info', array( 'delivery_point_ids' => array_values( $ids ) ) ); }
	/** @param array<string,mixed> $body @return array<string,mixed> */ public function order_checkout( array $body ): array { return $this->authorized_json_request( 'POST', '/v1/order/checkout', $body ); }
	/** @return array<string,mixed> */ public function authorized_json_request( string $method, string $path, array $body = array() ): array {
		$token = $this->tokens->get_token(); $json = wp_json_encode( $body );
		if ( ! is_string( $json ) || ! str_starts_with( $path, '/' ) ) { throw new OzonDeliveryApiException( 'api', 'request_invalid', 0, false, 'Некорректный запрос Ozon Delivery.' ); }
		$response = $this->http->request( $method, OzonDeliverySettings::API_BASE_URL . $path, array( 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), 'body' => $json ) );
		$data = json_decode( $response->body, true );
		if ( $response->status_code < 200 || $response->status_code >= 300 ) { throw $this->api_error( $path, $response, $data ); }
		if ( ! is_array( $data ) || array_is_list( $data ) ) { throw new OzonDeliveryApiException( 'api', 'api_response_invalid', $response->status_code, $response->status_code >= 500, 'Ozon Delivery вернул некорректный ответ API.', $this->response_metadata( $response, $data ) ); }
		return $data;
	}
	private function api_error( string $path, OzonDeliveryApiResponse $response, mixed $data ): OzonDeliveryApiException {
		$error = is_array( $data ) && isset( $data['error'] ) && is_array( $data['error'] ) ? $data['error'] : ( is_array( $data ) && ! array_is_list( $data ) ? $data : array() );
		$code = is_scalar( $error['code'] ?? null ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,80}$/', (string) $error['code'] ) ? (string) $error['code'] : 'api_http_error';
		$message = is_scalar( $error['message'] ?? null ) ? preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $error['message'] ) : '';
		$message = preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/=-]+\b/i', 'Bearer [redacted]', (string) $message ) ?? '';
		$message = preg_replace( '/(client[_ -]?secret|access[_ -]?token|cookie)\s*[:=]\s*[^,\s]+/iu', '$1=[redacted]', (string) $message ) ?? '';
		$message = preg_replace( '/\+7\d{10}\b/u', '+7[redacted]', (string) $message ) ?? '';
		$message = trim( substr( preg_replace( '/\s+/u', ' ', (string) $message ) ?? '', 0, 300 ) );
		return new OzonDeliveryApiException( trim( $path, '/' ), $code, $response->status_code, 429 === $response->status_code || $response->status_code >= 500, '' !== $message ? $message : 'Ozon Delivery вернул ошибку API.', $this->response_metadata( $response, $data ) );
	}
	private function response_metadata( OzonDeliveryApiResponse $response, mixed $data ): array {
		$keys = is_array( $data ) && ! array_is_list( $data ) ? array_slice( array_values( array_filter( array_keys( $data ), static fn( mixed $key ): bool => is_string( $key ) && 1 === preg_match( '/^[A-Za-z0-9_.-]{1,80}$/', $key ) ) ), 0, 10 ) : array();
		return array( 'response_json' => null !== json_decode( $response->body, true ) || 'null' === trim( $response->body ), 'response_root' => is_array( $data ) ? ( array_is_list( $data ) ? 'list' : 'object' ) : ( json_last_error() === JSON_ERROR_NONE ? 'scalar' : 'invalid_json' ), 'response_keys' => $keys, 'response_size_bytes' => min( strlen( $response->body ), 1048576 ) );
	}
}
