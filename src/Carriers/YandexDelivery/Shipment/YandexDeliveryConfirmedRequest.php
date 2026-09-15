<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryConfirmedRequest {
	/** @param array<string,mixed> $raw */
	public function __construct(
		public readonly string $request_id,
		public readonly string $offer_id,
		public readonly array $raw = array()
	) {
	}

	/** @param array<string,mixed> $response */
	public static function from_api_response( array $response, string $offer_id ): self {
		$body = is_array( $response['body'] ?? null ) ? $response['body'] : $response;
		$request = is_array( $body['request'] ?? null ) ? $body['request'] : array();
		$request_id = trim( (string) ( $body['request_id'] ?? $body['id'] ?? $request['id'] ?? $request['request_id'] ?? '' ) );

		return new self( $request_id, $offer_id, $body );
	}
}
