<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\JetLogistic;

use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusService;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Application\ShipmentCreationAttemptService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class JetLogisticShipmentService {
	public function __construct(
		private OrderShipmentRepository $repository,
		private JetLogisticStatusService $statuses,
		private ?ShipmentCreationAttemptService $attempts = null
	) {
	}

	/** @return array<string,mixed> */
	public function update_status( object $order ): array {
		$current = $this->repository->find_by_carrier( $order, JetLogisticSettings::CARRIER_KEY );
		if ( array() === $current ) {
			return array( 'success' => false, 'message' => 'Jet Logistic shipment is not attached.' );
		}
		$result = $this->statuses->update( $current );
		if ( empty( $result['success'] ) ) {
			return $result;
		}
		$patch = is_array( $result['shipment_patch'] ?? null ) ? $result['shipment_patch'] : array();
		$shipment = array_merge( $current, $patch, array( 'updated_at' => current_time( 'mysql' ) ) );
		$this->repository->save_for_carrier( $order, JetLogisticSettings::CARRIER_KEY, $shipment );

		return array_merge( $result, array( 'shipment' => $shipment ) );
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function attach_manual( object $order, array $payload ): array {
		$tracking = sanitize_text_field( wp_unslash( (string) ( $payload['tracking_number'] ?? $payload['external_id'] ?? $payload['number'] ?? '' ) ) );
		if ( '' === trim( $tracking ) ) {
			return array( 'success' => false, 'message' => 'Введите номер груза Jet Logistic.' );
		}
		$now = current_time( 'mysql' );
		$shipment = array(
			'carrier_key' => JetLogisticSettings::CARRIER_KEY,
			'service_key' => JetLogisticSettings::SERVICE_KEY,
			'status' => 'attached_manually',
			'tracking_number' => $tracking,
			'external_id' => $tracking,
			'attached_manually' => true,
			'universal_status_code' => DeliveryStatus::IN_TRANSIT,
			'universal_status_label' => DeliveryStatus::label( DeliveryStatus::IN_TRANSIT ),
			'created_at' => $now,
			'updated_at' => $now,
		);
		$this->repository->save_for_carrier( $order, JetLogisticSettings::CARRIER_KEY, $shipment );

		return array( 'success' => true, 'message' => 'Номер груза Jet Logistic прикреплен.', 'shipment' => $shipment );
	}

	/** @return array<string,mixed> */
	public function remove_local( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, JetLogisticSettings::CARRIER_KEY );
		if ( $this->attempts instanceof ShipmentCreationAttemptService ) {
			$this->attempts->mark_terminal_for_shipment( $order, JetLogisticSettings::CARRIER_KEY, $shipment, 'local_removed' );
		}
		$this->repository->delete_for_carrier( $order, JetLogisticSettings::CARRIER_KEY );

		return array( 'success' => true, 'message' => 'Jet Logistic shipment removed from order.' );
	}
}
