<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Manual;

use WallsShop\WDC\Carriers\Manual\ManualDeliverySettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class ManualShipmentAdapter implements CarrierShipmentAdapterInterface {
	public function __construct(
		private ManualShipmentService $shipments,
		private ShipmentActualCostResolver $actual_cost_resolver
	) {
	}

	public function carrier_key(): string {
		return ManualDeliverySettings::CARRIER_KEY;
	}

	public function supports( ShipmentCreateRequest $request ): bool {
		return ManualDeliverySettings::CARRIER_KEY === $request->carrier_key;
	}

	/** @return array<string,mixed> */
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		unset( $request );
		return array( 'errors' => array( 'Manual delivery does not support API shipment creation.' ), 'warnings' => array(), 'body' => array() );
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		unset( $request );
		return new ShipmentCreateResult( false, error_code: 'manual_create_unsupported', error_message: 'Manual delivery does not support API shipment creation.' );
	}

	/** @return array<string,string> */
	public function presentation(): array {
		return array(
			'carrier_label' => 'Ручная доставка',
			'status_title' => 'Статус ручного отправления',
			'tracking_label' => 'Номер отправления',
			'create_button_label' => 'Создание через API недоступно',
			'manual_attach_button_label' => 'Добавить отправление вручную',
			'manual_attach_field_label' => 'Номер отправления',
			'manual_attach_placeholder' => 'Например: ABC-123',
			'manual_attach_help' => 'Введите номер отправления, который будет храниться в заказе без обращения к внешней службе.',
			'manual_attach_actual_cost_enabled' => '1',
			'manual_attach_actual_cost_label' => 'Фактическая стоимость, ₽',
			'manual_attach_actual_cost_placeholder' => 'Например: 550.50',
			'cancel_button_label' => 'Отмена в службе недоступна',
			'remove_button_label' => 'Удалить из заказа',
			'update_status_button_label' => 'Обновление статуса недоступно',
			'created_toast' => 'Ручное отправление добавлено.',
			'error_fallback_message' => 'Не удалось получить статус ручного отправления.',
			'auto_update_status_after_manual_attach' => '0',
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	public function status_payload( object $order, array $shipment ): array {
		$has = array() !== $shipment;
		$actual_cost = $this->actual_cost_resolver->presentation_payload( $shipment, $order );
		$status_label = $has ? (string) ( $shipment['status_title'] ?? 'Отправление добавлено вручную' ) : 'не создано';

		return array_merge(
			array(
				'carrier_key' => ManualDeliverySettings::CARRIER_KEY,
				'service_key' => (string) ( $shipment['service_key'] ?? '' ),
				'service_title' => (string) ( $shipment['service_title'] ?? '' ),
				'has_shipment' => $has,
				'can_create' => false,
				'can_attach_manual' => ! $has && $this->order_is_manual( $order ),
				'can_update_status' => false,
				'can_cancel' => false,
				'can_remove_from_order' => $has,
				'shipment_status_label' => $status_label,
				'carrier_status_title' => $has ? 'Отправление добавлено вручную' : '',
				'universal_status_code' => (string) ( $shipment['universal_status_code'] ?? ( $has ? DeliveryStatus::UNKNOWN : '' ) ),
				'universal_status_label' => (string) ( $shipment['universal_status_label'] ?? ( $has ? DeliveryStatus::label( DeliveryStatus::UNKNOWN ) : '' ) ),
				'tracking_checked_at' => '',
				'updated_at' => (string) ( $shipment['updated_at'] ?? '' ),
				'barcode' => $this->tracking_identifier( $shipment ),
				'tracking_presentation' => array(
					'label' => 'Номер отправления',
					'display_text' => $this->tracking_identifier( $shipment ),
					'copy_value' => $this->tracking_identifier( $shipment ),
					'url' => '',
					'items' => array(),
				),
			),
			$actual_cost
		);
	}

	/** @return array<string,mixed> */
	public function update_status( object $order, string $shipment_key = '' ): array {
		unset( $order, $shipment_key );
		return array( 'success' => false, 'message' => 'У ручной доставки нет внешнего статуса.', 'unsupported' => true );
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function attach_manual( object $order, array $payload ): array {
		return $this->shipments->attach_manual( $order, $payload );
	}

	/** @return array<string,mixed> */
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array {
		unset( $order, $shipment_key );
		return array( 'success' => false, 'message' => 'Ручные отправления не отменяются через внешнюю службу.', 'unsupported' => true );
	}

	/** @return array<string,mixed> */
	public function remove_from_order( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->shipments->remove_local( $order );
	}

	public function supports_status_auto_sync(): bool {
		return false;
	}

	/** @param array<string,mixed> $shipment */
	public function tracking_identifier( array $shipment ): string {
		return trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? $shipment['external_id'] ?? '' ) );
	}

	public function auto_sync_throttle_microseconds(): int {
		return 0;
	}

	private function order_is_manual( object $order ): bool {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return false;
		}
		$carrier = $order->get_meta( '_wdc_platform_carrier_key', true );
		return ManualDeliverySettings::CARRIER_KEY === ( function_exists( 'sanitize_key' ) ? sanitize_key( (string) $carrier ) : (string) $carrier );
	}
}
