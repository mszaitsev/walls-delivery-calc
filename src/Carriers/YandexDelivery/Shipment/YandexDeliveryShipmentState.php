<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryShipmentState {
	/** @param array<string,mixed> $raw */
	public function __construct(
		public readonly string $request_id,
		public readonly string $status,
		public readonly array $raw = array()
	) {
	}

	/** @param array<string,mixed> $response */
	public static function from_api_response( array $response, string $fallback_request_id = '' ): self {
		$body = is_array( $response['body'] ?? null ) ? $response['body'] : $response;
		$request = is_array( $body['request'] ?? null ) ? $body['request'] : $body;

		return new self(
			trim( (string) ( $request['request_id'] ?? $request['id'] ?? $fallback_request_id ) ),
			trim( (string) ( $request['status'] ?? $request['state'] ?? $body['status'] ?? '' ) ),
			$body
		);
	}
}
