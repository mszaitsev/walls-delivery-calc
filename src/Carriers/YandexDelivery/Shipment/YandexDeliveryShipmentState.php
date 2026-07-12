<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryShipmentState {
	/** @param array<string,mixed> $raw */
	public function __construct(
		public readonly string $request_id,
		public readonly string $status,
		public readonly string $description = '',
		public readonly string $reason = '',
		public readonly string $timestamp = '',
		public readonly array $raw = array()
	) {
	}

	/** @param array<string,mixed> $response */
	public static function from_api_response( array $response, string $fallback_request_id = '' ): self {
		$body = is_array( $response['body'] ?? null ) ? $response['body'] : $response;
		$request = is_array( $body['request'] ?? null ) ? $body['request'] : array();
		$state = is_array( $body['state'] ?? null ) ? $body['state'] : ( is_array( $request['state'] ?? null ) ? $request['state'] : $body );

		return new self(
			trim( (string) ( $body['request_id'] ?? $request['request_id'] ?? $request['id'] ?? $fallback_request_id ) ),
			trim( (string) ( $state['status'] ?? $body['status'] ?? '' ) ),
			trim( (string) ( $state['description'] ?? $body['description'] ?? '' ) ),
			trim( (string) ( $state['reason'] ?? $body['reason'] ?? '' ) ),
			trim( (string) ( $state['timestamp'] ?? $state['timestamp_utc'] ?? $body['timestamp'] ?? '' ) ),
			$body
		);
	}
}
