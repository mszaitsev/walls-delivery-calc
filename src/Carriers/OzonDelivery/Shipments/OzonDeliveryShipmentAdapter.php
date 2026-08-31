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
			'cancel_button_label' => 'Отменить заказ',
			'remove_button_label' => 'Удалить заказ',
			'update_status_button_label' => 'Обновить статус',
			'created_toast' => 'Отправление Ozon создано и подтверждено.',
			'cancel_success_toast' => 'Запрос на отмену заказа Ozon отправлен.',
			'polling_timeout_message' => 'Ozon пока не подтвердил отмену заказа. Отправление сохранено; статус можно обновить позже.',
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
		$policy = OzonDeliveryShipmentActionPolicy::for_shipment( $shipment );
		return array_merge( array(
			'carrier_key' => OzonDeliverySettings::CARRIER_KEY,
			'has_shipment' => $has,
			'can_update_status' => $has && $policy['can_update'],
			'can_cancel' => $has && $policy['can_cancel'],
			'can_remove_from_order' => $has && $policy['can_remove'],
			'cancellation_pending' => $has && 'cancellation_started' === (string) ( $shipment['status'] ?? '' ),
			'polling_continue' => $has && in_array( (string) ( $shipment['status'] ?? '' ), array( 'cancellation_started', OzonDeliveryShipmentCreationStatusPolicy::STATUS_STARTED ), true ),
			'registration_poll_interval_ms' => 5000,
			'registration_poll_max_attempts' => 14,
			'shipment_status_label' => $has ? $this->shipment_status_label( $shipment ) : 'не создано',
			'carrier_status_title' => $this->ozon_status_label( $shipment ),
			'universal_status_code' => (string) ( $shipment['universal_status_code'] ?? '' ),
			'universal_status_label' => (string) ( $shipment['universal_status_label'] ?? '' ),
			'tracking_checked_at' => (string) ( $shipment['tracking_checked_at'] ?? '' ),
			'updated_at' => (string) ( $shipment['updated_at'] ?? '' ),
			'barcode' => $this->tracking_identifier( $shipment ),
			'tracking_presentation' => $this->tracking_presentation( $shipment ),
			'return_tracking_presentation' => $this->return_tracking_presentation( $shipment ),
			'ozon_order_number' => (string) ( $shipment['ozon_order_number'] ?? '' ),
			'ozon_postings' => is_array( $shipment['ozon_postings'] ?? null ) ? $shipment['ozon_postings'] : array(),
			'ozon_returns' => is_array( $shipment['ozon_returns'] ?? null ) ? $shipment['ozon_returns'] : array(),
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
		$this->service->terminalize_attempt( $order, $shipment );
		$this->repository->delete_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
		return array( 'success' => true, 'message' => 'Локальные данные Ozon удалены из заказа. Отправление или возврат в Ozon не отменялись.', 'shipment' => $shipment );
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

	/** @param array<string,mixed> $shipment */
	private function shipment_status_label( array $shipment ): string {
		$universal = (string) ( $shipment['universal_status_code'] ?? '' );
		if ( '' !== $universal && DeliveryStatus::is_valid( $universal ) ) {
			return DeliveryStatus::label( $universal );
		}

		return (string) ( $shipment['status_title'] ?? 'создано' );
	}

	/** @param array<string,mixed> $shipment */
	private function ozon_status_label( array $shipment ): string {
		$search = is_array( $shipment['ozon_return_search'] ?? null ) ? $shipment['ozon_return_search'] : array();
		$state = (string) ( $search['search_state'] ?? '' );
		if ( in_array( $state, array( 'not_found', 'incomplete', 'error', 'info_error' ), true ) && $this->has_cancelled_outbound_status( $shipment ) ) {
			return 'not_found' === $state ? 'исходное отправление отменено' : 'не удалось проверить возврат Ozon';
		}
		$statuses = OzonDeliveryShipmentActionPolicy::raw_statuses_from_shipment( $shipment );
		return array() === $statuses ? '-' : implode( ', ', $statuses );
	}

	/** @param array<string,mixed> $shipment @return array<string,mixed> */
	private function tracking_presentation( array $shipment ): array {
		$postings = is_array( $shipment['ozon_postings'] ?? null ) ? array_values( array_filter( $shipment['ozon_postings'], 'is_array' ) ) : array();
		usort( $postings, static fn( array $a, array $b ): int => (int) ( $a['place_number'] ?? 0 ) <=> (int) ( $b['place_number'] ?? 0 ) );
		$items = array();
		foreach ( $postings as $posting ) {
			$number = trim( (string) ( $posting['posting_number'] ?? '' ) );
			if ( '' === $number ) {
				continue;
			}
			$place = max( 1, (int) ( $posting['place_number'] ?? count( $items ) + 1 ) );
			$items[] = array(
				'label' => sprintf( 'Коробка %d', $place ),
				'display_text' => $number,
				'copy_value' => $number,
			);
		}
		if ( count( $items ) <= 1 ) {
			$value = (string) ( $items[0]['display_text'] ?? $this->tracking_identifier( $shipment ) );
			return array( 'label' => 'Номер Ozon', 'display_text' => $value, 'copy_value' => $value, 'items' => $items );
		}

		return array(
			'label' => 'Номера Ozon',
			'display_text' => implode( "\n", array_map( static fn( array $item ): string => (string) $item['display_text'], $items ) ),
			'copy_value' => '',
			'items' => $items,
		);
	}

	/** @param array<string,mixed> $shipment @return array<string,mixed> */
	private function return_tracking_presentation( array $shipment ): array {
		$returns = is_array( $shipment['ozon_returns'] ?? null ) ? array_values( array_filter( $shipment['ozon_returns'], 'is_array' ) ) : array();
		usort( $returns, static fn( array $a, array $b ): int => (int) ( $a['place_number'] ?? 0 ) <=> (int) ( $b['place_number'] ?? 0 ) );
		$items = array();
		foreach ( $returns as $return ) {
			$number = trim( (string) ( $return['return_number'] ?? '' ) );
			if ( '' === $number ) {
				continue;
			}
			$place = max( 1, (int) ( $return['place_number'] ?? count( $items ) + 1 ) );
			$status = trim( (string) ( $return['status'] ?? '' ) );
			$items[] = array(
				'label' => sprintf( 'Возврат коробки %d', $place ),
				'display_text' => '' !== $status ? $number . ' (' . $status . ')' : $number,
				'copy_value' => $number,
			);
		}
		if ( count( $items ) <= 1 && array() !== $items ) {
			return array( 'label' => 'Возврат Ozon', 'display_text' => (string) $items[0]['display_text'], 'copy_value' => (string) $items[0]['copy_value'], 'items' => array() );
		}
		if ( array() !== $items ) {
			return array( 'label' => 'Возвраты Ozon', 'display_text' => implode( "\n", array_map( static fn( array $item ): string => (string) $item['display_text'], $items ) ), 'copy_value' => '', 'items' => $items );
		}
		$search = is_array( $shipment['ozon_return_search'] ?? null ) ? $shipment['ozon_return_search'] : array();
		$state = (string) ( $search['search_state'] ?? '' );
		if ( in_array( $state, array( 'not_found', 'incomplete', 'error', 'info_error' ), true ) ) {
			$checked = trim( (string) ( $search['checked_at'] ?? '' ) );
			$text = match ( $state ) {
				'not_found' => 'не найден',
				'incomplete' => 'поиск не завершён',
				default => 'не удалось проверить',
			};
			return array( 'label' => 'Возврат Ozon', 'display_text' => $text . ( '' !== $checked ? ' (проверено: ' . $checked . ')' : '' ), 'copy_value' => '', 'items' => array() );
		}

		return array( 'label' => 'Возврат Ozon', 'display_text' => '', 'copy_value' => '', 'items' => array() );
	}

	/** @param array<string,mixed> $shipment */
	private function has_cancelled_outbound_status( array $shipment ): bool {
		foreach ( OzonDeliveryShipmentActionPolicy::raw_statuses_from_shipment( $shipment ) as $status ) {
			if ( 'canceled' === OzonDeliveryShipmentStatusMapping::normalize( $status ) ) {
				return true;
			}
		}
		return false;
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
		if ( 'cancellation_started' === (string) ( $shipment['status'] ?? '' ) ) {
			return new ShipmentLifecycleResult(
				ShipmentLifecycleResult::PHASE_POLLING_REQUIRED,
				accepted: true,
				poll_required: true,
				message: 'Ожидаем подтверждение отмены Ozon…',
				poll_interval_ms: 5000,
				poll_max_attempts: 14,
				purpose: 'cancellation'
			);
		}
		if ( OzonDeliveryShipmentCreationStatusPolicy::STATUS_STARTED === (string) ( $shipment['status'] ?? '' ) ) {
			return new ShipmentLifecycleResult(
				ShipmentLifecycleResult::PHASE_POLLING_REQUIRED,
				accepted: true,
				poll_required: true,
				message: 'Ожидаем готовность отправления Ozon…',
				poll_interval_ms: 5000,
				poll_max_attempts: 14,
				purpose: OzonDeliveryShipmentCreationStatusPolicy::PURPOSE
			);
		}
		return new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_COMPLETED, accepted: array() !== $shipment );
	}

	/** @return array<string,mixed> */
	public function mark_polling_exhausted( object $order, int $attempts, string $purpose = 'registration' ): array {
		unset( $attempts );
		if ( OzonDeliveryShipmentCreationStatusPolicy::PURPOSE === $purpose ) {
			$shipment = $this->repository->find_by_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
			if ( array() !== $shipment ) {
				$shipment['status'] = OzonDeliveryShipmentCreationStatusPolicy::STATUS_EXHAUSTED;
				$shipment['status_title'] = DeliveryStatus::label( DeliveryStatus::CREATED_IN_CARRIER );
				$shipment['universal_status_code'] = DeliveryStatus::CREATED_IN_CARRIER;
				$shipment['universal_status_label'] = DeliveryStatus::label( DeliveryStatus::CREATED_IN_CARRIER );
				$shipment['tracking_checked_at'] = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
				$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );
			}

			return array(
				'success' => true,
				'message' => 'Ozon ещё формирует отправление. Статус можно обновить позже.',
				'shipment' => $shipment,
			);
		}
		if ( 'cancellation' !== $purpose ) {
			return array( 'success' => true, 'message' => 'Автоматическая проверка статуса Ozon завершена.', 'shipment' => $this->repository->find_by_carrier( $order, OzonDeliverySettings::CARRIER_KEY ) );
		}
		$shipment = $this->repository->find_by_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
		if ( array() !== $shipment ) {
			$shipment['status'] = 'cancellation_exhausted';
			$shipment['status_title'] = 'Ozon пока не подтвердил отмену заказа.';
			$shipment['universal_status_code'] = DeliveryStatus::UNKNOWN;
			$shipment['universal_status_label'] = DeliveryStatus::label( DeliveryStatus::UNKNOWN );
			$shipment['tracking_checked_at'] = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
			$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );
		}

		return array(
			'success' => true,
			'message' => 'Ozon пока не подтвердил отмену заказа. Отправление сохранено; статус можно обновить позже.',
			'shipment' => $shipment,
		);
	}
}
