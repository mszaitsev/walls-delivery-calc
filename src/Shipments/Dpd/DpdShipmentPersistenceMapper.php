<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentPersistenceMapperInterface;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentPersistenceMapper implements CarrierShipmentPersistenceMapperInterface {
	public function carrier_key(): string { return DpdSettings::CARRIER_KEY; }

	/** @param array<string,mixed> $existing */
	public function duplicate_error_message( array $existing ): string {
		unset( $existing );
		return 'DPD отправление уже создано для этого заказа.';
	}

	public function build_created_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): array {
		unset( $now );
		$raw = $result->raw_reference;

		return array(
			'request_snapshot' => is_array( $raw['request'] ?? null ) ? $raw['request'] : $preview,
			'response_snapshot' => is_array( $raw['response'] ?? null ) ? $raw : $raw,
			'dpd_order_number' => (string) ( $raw['dpd_order_number'] ?? '' ),
			'dpd_request_number' => (string) ( $raw['dpd_request_number'] ?? '' ),
			'dpd_parcel_numbers' => is_array( $raw['dpd_parcel_numbers'] ?? null ) ? $raw['dpd_parcel_numbers'] : array(),
			'dpd_status' => (string) ( $raw['dpd_status'] ?? '' ),
			'dpd_pickup_date' => (string) ( $raw['dpd_pickup_date'] ?? '' ),
			'dpd_date_flag' => (string) ( $raw['dpd_date_flag'] ?? '' ),
			'dpd_service_code' => (string) ( $request->meta['service_code'] ?? '' ),
			'dpd_sender_terminal_code' => (string) ( $request->meta['pickup_terminal_code'] ?? '' ),
			'dpd_receiver_terminal_code' => (string) ( $request->meta['delivery_terminal_code'] ?? '' ),
			'dpd_date_pickup' => (string) ( $request->meta['date_pickup'] ?? '' ),
			'dpd_cargo_value' => (float) ( $request->meta['declared_value_rub'] ?? 0 ),
			'status' => 'pending_creation_in_carrier',
			'universal_status_code' => 'pending_creation_in_carrier',
			'status_title' => 'Заявка DPD создана',
		);
	}

	public function build_failed_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): ?array {
		unset( $request, $result, $preview, $now );
		return null;
	}

	public function after_persist( object $order, array $shipment ): void {
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note(
				sprintf(
					'DPD отправление создано вручную. Номер: %s. Мест: %d',
					(string) ( $shipment['tracking_number'] ?? '' ),
					count( is_array( $shipment['places'] ?? null ) ? $shipment['places'] : array() )
				)
			);
		}
	}
}
