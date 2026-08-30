<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentLifecycleContinuationInterface;
use WallsShop\WDC\Shipments\Lifecycle\ShipmentLifecycleResult;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentAdapter implements CarrierShipmentAdapterInterface, CarrierShipmentLifecycleContinuationInterface {
	public function __construct(
		private OzonDeliveryShipmentService $service,
		private OzonDeliveryShipmentCreateRequestBuilder $builder,
		private OrderShipmentRepository $repository,
		private ShipmentActualCostResolver $actual_cost_resolver
	) {}

	public function carrier_key(): string {
		return OzonDeliverySettings::CARRIER_KEY;
	}

	public function supports( ShipmentCreateRequest $request ): bool {
		return OzonDeliverySettings::CARRIER_KEY === $request->carrier_key;
	}

	/** @return array<string,string> */
	public function presentation(): array {
		return array(
			'carrier_label' => 'Ozon',
			'status_title' => 'Статус Ozon',
			'tracking_label' => 'Номер Ozon',
			'create_button_label' => 'Создать отправление Ozon',
			'manual_attach_button_label' => 'Внести номер Ozon вручную',
			'manual_attach_placeholder' => 'Номер отправления Ozon',
			'manual_attach_help' => 'Введите номер отправления из кабинета Ozon.',
			'cancel_button_label' => 'Отменить отправление в Ozon',
			'remove_button_label' => 'Удалить из заказа',
			'update_status_button_label' => 'Обновить статус',
			'created_toast' => 'Отправление Ozon создано и подтверждено.',
			'error_fallback_message' => 'Не удалось получить статус Ozon.',
		);
	}

	/** @return array<string,mixed> */
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		$errors = $request->validate();
		if ( OzonDeliverySettings::CARRIER_KEY !== $request->carrier_key ) {
			$errors[] = 'Выбран не Ozon Delivery.';
		}
		return array(
			'method' => 'POST',
			'path' => '/v1/order/create + /v1/posting/approve',
			'body' => array(),
			'errors' => $errors,
			'summary' => array(
				'places_count' => count( $request->places ),
				'idempotency_key_present' => '' !== (string) ( $request->meta['creation_attempt_id'] ?? '' ),
				'checkout_packaging_used' => false,
			),
		);
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		return new ShipmentCreateResult( false, error_code: 'ozon_order_context_missing', error_message: 'Для создания Ozon нужен контекст заказа WooCommerce.' );
	}

	public function create_for_order( object $order, ShipmentCreateRequest $request ): ShipmentCreateResult {
		return $this->service->create_and_approve( $order, $request );
	}

	/** @param array<string,mixed> $shipment @return array<string,mixed> */
	public function status_payload( object $order, array $shipment ): array {
		$has = array() !== $shipment;
		$lifecycle = $this->lifecycle_from_shipment( $shipment );
		return array_merge( array(
			'carrier_key' => OzonDeliverySettings::CARRIER_KEY,
			'has_shipment' => $has,
			'can_update_status' => $has,
			'can_cancel' => $has && 'cancellation_started' !== (string) ( $shipment['status'] ?? '' ) && ! in_array( (string) ( $shipment['universal_status_code'] ?? '' ), array( DeliveryStatus::DELIVERED, DeliveryStatus::CANCELLED, DeliveryStatus::REJECTED ), true ),
			'can_remove_from_order' => $has,
			'shipment_status_label' => $has ? (string) ( $shipment['status_title'] ?? $shipment['universal_status_label'] ?? 'создано' ) : 'не создано',
			'universal_status_code' => (string) ( $shipment['universal_status_code'] ?? '' ),
			'universal_status_label' => (string) ( $shipment['universal_status_label'] ?? '' ),
			'tracking_checked_at' => (string) ( $shipment['tracking_checked_at'] ?? '' ),
			'updated_at' => (string) ( $shipment['updated_at'] ?? '' ),
			'barcode' => $this->tracking_identifier( $shipment ),
			'ozon_order_number' => (string) ( $shipment['ozon_order_number'] ?? '' ),
			'ozon_postings' => is_array( $shipment['ozon_postings'] ?? null ) ? $shipment['ozon_postings'] : array(),
			'lifecycle' => $lifecycle->to_array(),
		), $this->actual_cost_resolver->presentation_payload( $shipment, $order ) );
	}

	/** @return array<string,mixed> */
	public function update_status( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->service->status( $order );
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function attach_manual( object $order, array $payload ): array {
		unset( $order, $payload );
		return array( 'success' => false, 'message' => 'Ручное внесение Ozon пока не поддерживается.' );
	}

	/** @return array<string,mixed> */
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->service->cancel( $order );
	}

	/** @return array<string,mixed> */
	public function remove_from_order( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		$shipment = $this->repository->find_by_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
		$this->repository->delete_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
		return array( 'success' => true, 'message' => 'Локальная запись отправления Ozon удалена.', 'shipment' => $shipment );
	}

	public function supports_status_auto_sync(): bool {
		return true;
	}

	/** @param array<string,mixed> $shipment */
	public function tracking_identifier( array $shipment ): string {
		$postings = is_array( $shipment['ozon_postings'] ?? null ) ? $shipment['ozon_postings'] : array();
		foreach ( array( 'tracking_number', 'barcode', 'ozon_order_number' ) as $key ) {
			$value = trim( (string) ( $shipment[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return (string) ( is_array( $postings[0] ?? null ) ? ( $postings[0]['posting_number'] ?? '' ) : '' );
	}

	public function auto_sync_throttle_microseconds(): int {
		return 0;
	}

	/** @return array<string,mixed> */
	public function continue_lifecycle( object $order, string $continuation_token ): array {
		return $this->service->continue_approval( $order, $continuation_token );
	}

	/** @param array<string,mixed> $shipment */
	private function lifecycle_from_shipment( array $shipment ): ShipmentLifecycleResult {
		if ( ! empty( $shipment['pending_creation_in_carrier'] ) ) {
			return new ShipmentLifecycleResult(
				ShipmentLifecycleResult::PHASE_SUBMISSION_REQUIRED,
				accepted: true,
				submit_required: true,
				continuation_token: OzonDeliveryShipmentService::CONTINUATION_TOKEN,
				message: 'Продолжите подтверждение отправлений Ozon.',
				purpose: 'registration'
			);
		}
		return new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_COMPLETED, accepted: array() !== $shipment );
	}
}
