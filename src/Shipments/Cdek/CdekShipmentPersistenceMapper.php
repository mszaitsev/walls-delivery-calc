<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentPersistenceMapperInterface;

defined( 'ABSPATH' ) || exit;

final class CdekShipmentPersistenceMapper implements CarrierShipmentPersistenceMapperInterface {
	public function carrier_key(): string { return CdekSettings::CARRIER_KEY; }

	public function build_created_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): array {
		unset( $request, $now );
		$raw = $result->raw_reference;
		$backlog_order_id = trim( $result->backlog_order_id );
		$fields = array(
			'request_snapshot' => is_array( $raw['request'] ?? null ) ? array( 'method' => 'POST', 'path' => '/v2/orders', 'body' => $raw['request'], 'errors' => array() ) : $preview,
			'response_snapshot' => is_array( $raw['response'] ?? null ) ? $raw : $raw,
			'cdek_number' => (string) ( $raw['cdek_number'] ?? $result->tracking_number ),
			'cdek_request_uuid' => $result->backlog_order_id,
			'cdek_request_state' => (string) ( $raw['registration_state'] ?? '' ),
			'cdek_order_status_code' => (string) ( $raw['order_status'] ?? '' ),
			'cdek_order_status_name' => (string) ( $raw['order_status_name'] ?? '' ),
			'cdek_planned_delivery_date' => (string) ( $raw['planned_delivery_date'] ?? '' ),
			'cdek_actual_cost_kopecks' => is_numeric( $raw['actual_cost_kopecks'] ?? null ) ? (int) $raw['actual_cost_kopecks'] : null,
			'status' => 'registration_pending',
			'status_title' => 'Заявка на регистрацию принята',
		);
		if ( '' !== $backlog_order_id ) {
			$fields['backlog_order_id'] = ctype_digit( $backlog_order_id ) ? (int) $backlog_order_id : $backlog_order_id;
		}

		return $fields;
	}

	public function build_failed_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): ?array {
		unset( $request, $result, $preview, $now );
		return null;
	}

	public function after_persist( object $order, array $shipment ): void {
		unset( $order, $shipment );
	}
}
