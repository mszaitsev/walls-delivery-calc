<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentCreateResponseParser {
	/**
	 * @param array<string,mixed> $response
	 * @param array<int,int> $expected_request_ids
	 * @return array{order_number:string,order_external_id:string,postings:array<int,array<string,mixed>>}
	 */
	public function parse( array $response, array $expected_request_ids ): array {
		$order_number = trim( (string) ( $response['order_number'] ?? '' ) );
		$postings = is_array( $response['postings'] ?? null ) ? $response['postings'] : array();
		if ( '' === $order_number || count( $postings ) !== count( $expected_request_ids ) ) {
			throw new \RuntimeException( 'Ozon Delivery вернул некорректный ответ создания заказа.' );
		}
		$expected = array_fill_keys( array_map( 'intval', $expected_request_ids ), true );
		$seen = array();
		$normalized = array();
		foreach ( $postings as $posting ) {
			if ( ! is_array( $posting ) ) {
				throw new \RuntimeException( 'Ozon Delivery вернул некорректный список отправлений.' );
			}
			$request_id = (int) ( $posting['request_id'] ?? 0 );
			$posting_number = trim( (string) ( $posting['posting_number'] ?? '' ) );
			if ( ! isset( $expected[ $request_id ] ) || isset( $seen[ $request_id ] ) || '' === $posting_number ) {
				throw new \RuntimeException( 'Ozon Delivery вернул некорректные номера отправлений.' );
			}
			$seen[ $request_id ] = true;
			$normalized[] = array(
				'request_id' => $request_id,
				'place_number' => $request_id,
				'posting_number' => $posting_number,
				'posting_external_id' => trim( (string) ( $posting['posting_external_id'] ?? '' ) ),
				'estimated_delivery_days' => is_numeric( $posting['estimated_delivery_days'] ?? null ) ? (int) $posting['estimated_delivery_days'] : null,
				'cutoff_at' => trim( (string) ( $posting['cutoff_at'] ?? '' ) ),
				'approved' => false,
			);
		}
		if ( count( $seen ) !== count( $expected ) ) {
			throw new \RuntimeException( 'Ozon Delivery вернул неполный список отправлений.' );
		}

		return array(
			'order_number' => $order_number,
			'order_external_id' => trim( (string) ( $response['order_external_id'] ?? '' ) ),
			'postings' => $normalized,
		);
	}
}
