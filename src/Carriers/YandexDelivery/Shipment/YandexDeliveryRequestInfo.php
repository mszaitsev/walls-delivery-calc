<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryRequestInfo {
	/**
	 * @param array<string,mixed> $destination
	 * @param array<string,mixed> $recipient
	 * @param array<int,array<string,mixed>> $items
	 * @param array<int,array<string,mixed>> $places
	 * @param array<string,string> $place_barcode_map temporary => real
	 * @param array<string,mixed> $raw
	 */
	public function __construct(
		public readonly string $request_id,
		public readonly string $courier_order_id,
		public readonly string $sharing_url,
		public readonly string $status,
		public readonly array $destination,
		public readonly array $recipient,
		public readonly array $items,
		public readonly array $places,
		public readonly array $place_barcode_map,
		public readonly array $raw = array()
	) {
	}

	/**
	 * @param array<string,mixed> $response
	 * @param array<int,array<string,mixed>> $temporary_places
	 */
	public static function from_api_response( array $response, array $temporary_places = array() ): self {
		$body = is_array( $response['body'] ?? null ) ? $response['body'] : $response;
		$request = is_array( $body['request'] ?? null ) ? $body['request'] : ( is_array( $body['info'] ?? null ) ? $body['info'] : $body );
		$places = array_values( array_filter( is_array( $request['places'] ?? null ) ? $request['places'] : array(), 'is_array' ) );
		$items = array_values( array_filter( is_array( $request['items'] ?? null ) ? $request['items'] : array(), 'is_array' ) );

		return new self(
			trim( (string) ( $request['request_id'] ?? $request['id'] ?? '' ) ),
			trim( (string) ( $request['courier_order_id'] ?? $request['order_id'] ?? '' ) ),
			trim( (string) ( $request['sharing_url'] ?? $request['share_url'] ?? '' ) ),
			trim( (string) ( $request['status'] ?? $request['state'] ?? '' ) ),
			is_array( $request['destination'] ?? null ) ? $request['destination'] : array(),
			is_array( $request['recipient_info'] ?? null ) ? $request['recipient_info'] : ( is_array( $request['recipient'] ?? null ) ? $request['recipient'] : array() ),
			$items,
			$places,
			self::barcode_map( $temporary_places, $places ),
			$body
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $temporary_places
	 * @param array<int,array<string,mixed>> $real_places
	 * @return array<string,string>
	 */
	private static function barcode_map( array $temporary_places, array $real_places ): array {
		$map = array();
		foreach ( array_values( $temporary_places ) as $index => $temporary_place ) {
			$temporary = is_array( $temporary_place ) ? trim( (string) ( $temporary_place['barcode'] ?? '' ) ) : '';
			$real_place = $real_places[ $index ] ?? array();
			$real = is_array( $real_place ) ? trim( (string) ( $real_place['barcode'] ?? '' ) ) : '';
			if ( '' !== $temporary && '' !== $real ) {
				$map[ $temporary ] = $real;
			}
		}

		return $map;
	}
}
