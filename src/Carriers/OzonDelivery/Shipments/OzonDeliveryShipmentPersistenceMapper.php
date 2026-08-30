<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentPersistenceMapperInterface;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentPersistenceMapper implements CarrierShipmentPersistenceMapperInterface {
	public function carrier_key(): string {
		return OzonDeliverySettings::CARRIER_KEY;
	}

	/** @param array<string,mixed> $existing */
	public function duplicate_error_message( array $existing ): string {
		return 'Отправление Ozon уже создано для этого заказа: ' . (string) ( $existing['ozon_order_number'] ?? $existing['tracking_number'] ?? '' );
	}

	public function build_created_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): array {
		$raw = $result->raw_reference;
		return array(
			'ozon_order_number' => (string) ( $raw['ozon_order_number'] ?? $result->external_id ),
			'ozon_order_external_id' => (string) ( $raw['ozon_order_external_id'] ?? '' ),
			'ozon_postings' => is_array( $raw['ozon_postings'] ?? null ) ? $raw['ozon_postings'] : array(),
			'ozon_idempotency_key' => (string) ( $raw['ozon_idempotency_key'] ?? $request->meta['creation_attempt_id'] ?? '' ),
			'tracking_number' => $result->tracking_number,
			'barcode' => $result->tracking_number,
			'status' => 'created',
			'status_title' => 'Отправление Ozon создано и подтверждено.',
			'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER,
			'universal_status_label' => DeliveryStatus::label( DeliveryStatus::CREATED_IN_CARRIER ),
			'service_key' => OzonDeliverySettings::SERVICE_KEY,
			'rate_id' => $request->rate_id,
			'pickup_point_code' => (string) ( $request->pickup_point?->point_code ?? $request->meta['pickup_point_code'] ?? '' ),
			'request_snapshot' => is_array( $raw['request'] ?? null ) ? $raw['request'] : $preview,
			'response_snapshot' => is_array( $raw['response'] ?? null ) ? $raw['response'] : array(),
			'created_at' => $now,
		);
	}

	public function build_failed_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): ?array {
		if ( 'ozon_posting_approve_partial' !== $result->error_code ) {
			return null;
		}
		$raw = $result->raw_reference;
		return array(
			'ozon_order_number' => (string) ( $raw['ozon_order_number'] ?? '' ),
			'ozon_order_external_id' => (string) ( $raw['ozon_order_external_id'] ?? '' ),
			'ozon_postings' => is_array( $raw['ozon_postings'] ?? null ) ? $raw['ozon_postings'] : array(),
			'ozon_idempotency_key' => (string) ( $raw['ozon_idempotency_key'] ?? $request->meta['creation_attempt_id'] ?? '' ),
			'tracking_number' => (string) ( $raw['ozon_postings'][0]['posting_number'] ?? $raw['ozon_order_number'] ?? '' ),
			'barcode' => (string) ( $raw['ozon_postings'][0]['posting_number'] ?? '' ),
			'status' => DeliveryStatus::PENDING_CREATION_IN_CARRIER,
			'status_title' => 'Заказ Ozon создан, но не все отправления подтверждены. Продолжите создание.',
			'universal_status_code' => DeliveryStatus::PENDING_CREATION_IN_CARRIER,
			'universal_status_label' => DeliveryStatus::label( DeliveryStatus::PENDING_CREATION_IN_CARRIER ),
			'pending_creation_in_carrier' => true,
			'lifecycle_token' => OzonDeliveryShipmentService::CONTINUATION_TOKEN,
			'service_key' => OzonDeliverySettings::SERVICE_KEY,
			'rate_id' => $request->rate_id,
			'pickup_point_code' => (string) ( $request->pickup_point?->point_code ?? $request->meta['pickup_point_code'] ?? '' ),
			'request_snapshot' => is_array( $raw['request'] ?? null ) ? $raw['request'] : $preview,
			'response_snapshot' => array(
				'error_code' => $result->error_code,
				'approval' => is_array( $raw['approval'] ?? null ) ? $raw['approval'] : array(),
				'checked_at' => $now,
			),
		);
	}

	/** @param array<string,mixed> $shipment */
	public function after_persist( object $order, array $shipment ): void {
		unset( $order, $shipment );
	}
}
