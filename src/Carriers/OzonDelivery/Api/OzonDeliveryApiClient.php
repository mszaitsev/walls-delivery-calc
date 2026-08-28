<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Api;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryApiClient {
	public function __construct( private OzonDeliveryHttpClientInterface $http, private OzonDeliveryAccessTokenService $tokens ) {}
	/** @return array<string,mixed> */ public function pickup_list( string $cursor = '' ): array { return $this->authorized_json_request( 'POST', '/v1/delivery-point/list', array( 'pagination' => array( 'cursor' => $cursor, 'limit' => 100 ) ) ); }
	/** @param array<int,int> $ids @return array<string,mixed> */ public function pickup_info( array $ids ): array { if ( array() === $ids || count( $ids ) > 100 ) { throw new OzonDeliveryApiException( 'pickup_info', 'request_invalid', 0, false, 'Некорректный запрос ПВЗ Ozon Delivery.' ); } return $this->authorized_json_request( 'POST', '/v1/delivery-point/info', array( 'delivery_point_ids' => array_values( $ids ) ) ); }
	/** @return array<string,mixed> */ public function authorized_json_request( string $method, string $path, array $body = array() ): array {
		$token = $this->tokens->get_token(); $json = wp_json_encode( $body );
		if ( ! is_string( $json ) || ! str_starts_with( $path, '/' ) ) { throw new OzonDeliveryApiException( 'api', 'request_invalid', 0, false, 'Некорректный запрос Ozon Delivery.' ); }
		$response = $this->http->request( $method, OzonDeliverySettings::API_BASE_URL . $path, array( 'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json', 'Accept' => 'application/json' ), 'body' => $json ) );
		$data = json_decode( $response->body, true );
		if ( $response->status_code < 200 || $response->status_code >= 300 || ! is_array( $data ) || array_is_list( $data ) ) { throw new OzonDeliveryApiException( 'api', 'api_response_invalid', $response->status_code, $response->status_code >= 500, 'Ozon Delivery вернул некорректный ответ API.' ); }
		return $data;
	}
}
