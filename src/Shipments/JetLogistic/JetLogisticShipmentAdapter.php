<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\JetLogistic;

use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;

defined( 'ABSPATH' ) || exit;

final class JetLogisticShipmentAdapter implements CarrierShipmentAdapterInterface {
	public function __construct(
		private JetLogisticShipmentService $shipments,
		private ShipmentActualCostResolver $actual_cost_resolver
	) {
	}

	public function carrier_key(): string {
		return JetLogisticSettings::CARRIER_KEY;
	}

	public function supports( ShipmentCreateRequest $request ): bool {
		return JetLogisticSettings::CARRIER_KEY === $request->carrier_key;
	}

	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		unset( $request );
		return array( 'errors' => array( 'Jet Logistic does not support API shipment creation.' ), 'warnings' => array(), 'body' => array() );
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		unset( $request );
		return new ShipmentCreateResult( false, error_code: 'jet_create_unsupported', error_message: 'Jet Logistic does not support API shipment creation.' );
	}

	public function presentation(): array {
		return array(
			'title' => 'Jet Logistic',
			'manual_attach_label' => 'Номер груза Jet Logistic',
			'manual_attach_placeholder' => 'Введите номер груза',
			'manual_attach_button_label' => 'Прикрепить номер Jet',
			'cancel_button_label' => 'Отмена в Jet недоступна',
			'remove_button_label' => 'Удалить из заказа',
			'update_status_button_label' => 'Обновить статус',
			'created_toast' => 'Номер Jet Logistic прикреплен.',
			'error_fallback_message' => 'Не удалось получить статус Jet Logistic.',
			'auto_update_status_after_manual_attach' => '1',
		);
	}

	/** @param array<string,mixed> $shipment @return array<string,mixed> */
	public function status_payload( object $order, array $shipment ): array {
		$has = array() !== $shipment;
		$actual_cost = $this->actual_cost_resolver->presentation_payload( $shipment, $order );
		return array_merge(
			array(
				'carrier_key' => JetLogisticSettings::CARRIER_KEY,
				'has_shipment' => $has,
				'can_create' => false,
				'can_attach_manual' => ! $has,
				'can_update_status' => $has,
				'can_cancel' => false,
				'can_remove_from_order' => $has,
				'shipment_status_label' => $has ? DeliveryStatus::label( (string) ( $shipment['universal_status_code'] ?? DeliveryStatus::IN_TRANSIT ) ) : 'не создано',
				'universal_status_code' => (string) ( $shipment['universal_status_code'] ?? '' ),
				'universal_status_label' => (string) ( $shipment['universal_status_label'] ?? '' ),
				'carrier_status_title' => (string) ( $shipment['carrier_status_message'] ?? '' ),
				'carrier_operation_date' => (string) ( $shipment['carrier_status_date'] ?? '' ),
				'tracking_checked_at' => (string) ( $shipment['status_updated_at'] ?? '' ),
				'updated_at' => (string) ( $shipment['updated_at'] ?? '' ),
				'barcode' => $this->tracking_identifier( $shipment ),
				'status_events' => is_array( $shipment['status_events'] ?? null ) ? $shipment['status_events'] : array(),
			),
			$actual_cost
		);
	}

	public function update_status( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->shipments->update_status( $order );
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function attach_manual( object $order, array $payload ): array {
		return $this->shipments->attach_manual( $order, $payload );
	}

	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array {
		unset( $order, $shipment_key );
		return array( 'success' => false, 'message' => 'Jet Logistic does not support API cancellation.', 'unsupported' => true );
	}

	public function remove_from_order( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->shipments->remove_local( $order );
	}

	public function supports_status_auto_sync(): bool {
		return true;
	}

	/** @param array<string,mixed> $shipment */
	public function tracking_identifier( array $shipment ): string {
		return trim( (string) ( $shipment['tracking_number'] ?? $shipment['external_id'] ?? '' ) );
	}

	public function auto_sync_throttle_microseconds(): int {
		return 200000;
	}
}
