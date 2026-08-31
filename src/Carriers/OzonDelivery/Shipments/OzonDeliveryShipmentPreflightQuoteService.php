<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteParser;
use WallsShop\WDC\Shipments\Application\ShipmentActualCost;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentPreflightQuoteService {
	public const SOURCE_DETAIL = 'ozon_order_checkout_pre_create';

	public function __construct(
		private OzonDeliveryApiClient $api,
		private OzonDeliveryQuoteParser $parser
	) {}

	/**
	 * @param array<string,mixed> $create_body
	 * @return array{actual_cost_candidate:ShipmentActualCost,summary:array<string,mixed>}
	 */
	public function quote( array $create_body, string $now ): array {
		$checkout_body = $this->checkout_body( $create_body );
		$request_ids = array_map( 'intval', array_column( $checkout_body['postings'], 'request_id' ) );
		$shipment_method_id = (int) ( $checkout_body['postings'][0]['shipment_method_id'] ?? 0 );
		$point_id = (string) ( $checkout_body['delivery']['delivery_point']['delivery_point_id'] ?? '' );
		$response = $this->api->order_checkout( $checkout_body );
		$quote = $this->parser->parse( $response, $request_ids, $point_id, $shipment_method_id );

		return array(
			'actual_cost_candidate' => new ShipmentActualCost(
				$quote->price->get_kopecks(),
				'RUB',
				'carrier_api',
				self::SOURCE_DETAIL,
				$now
			),
			'summary' => array(
				'method' => 'POST',
				'path' => '/v1/order/checkout',
				'postings_count' => count( $request_ids ),
				'delivery_total_rub' => (string) ( $quote->meta['delivery_total_rub'] ?? '' ),
				'insurance_total_rub' => (string) ( $quote->meta['insurance_total_rub'] ?? '' ),
				'total_rub' => (string) ( $quote->meta['total_rub'] ?? '' ),
				'postings' => is_array( $quote->meta['postings'] ?? null ) ? $quote->meta['postings'] : array(),
			),
		);
	}

	/**
	 * @param array<string,mixed> $create_body
	 * @return array{recipient:array<string,mixed>,delivery:array<string,mixed>,postings:array<int,array<string,mixed>>}
	 */
	public function checkout_body( array $create_body ): array {
		$postings = array();
		foreach ( is_array( $create_body['postings'] ?? null ) ? $create_body['postings'] : array() as $posting ) {
			if ( ! is_array( $posting ) ) {
				continue;
			}
			$postings[] = array(
				'request_id' => (int) ( $posting['request_id'] ?? 0 ),
				'shipment_method_id' => (int) ( $posting['shipment_method_id'] ?? 0 ),
				'declared_value' => is_array( $posting['declared_value'] ?? null ) ? $posting['declared_value'] : array(),
				'dimensions' => is_array( $posting['dimensions'] ?? null ) ? $posting['dimensions'] : array(),
			);
		}

		return array(
			'recipient' => array(
				'phone_number' => (string) ( $create_body['recipient']['phone_number'] ?? '' ),
			),
			'delivery' => is_array( $create_body['delivery'] ?? null ) ? $create_body['delivery'] : array(),
			'postings' => $postings,
		);
	}
}
