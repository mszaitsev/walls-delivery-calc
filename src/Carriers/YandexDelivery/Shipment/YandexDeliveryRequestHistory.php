<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryRequestHistory {
	/**
	 * @param array<int,array<string,mixed>> $events
	 * @param array<string,mixed> $raw
	 */
	public function __construct(
		public readonly string $request_id,
		public readonly array $events,
		public readonly array $raw = array()
	) {
	}

	/** @param array<string,mixed> $response */
	public static function from_api_response( array $response, string $request_id ): self {
		$body = is_array( $response['body'] ?? null ) ? $response['body'] : $response;
		$events = is_array( $body['state_history'] ?? null ) ? $body['state_history'] : ( is_array( $body['history'] ?? null ) ? $body['history'] : ( is_array( $body['events'] ?? null ) ? $body['events'] : array() ) );
		$events = array_values( array_filter( $events, 'is_array' ) );

		return new self( $request_id, $events, $body );
	}
}
