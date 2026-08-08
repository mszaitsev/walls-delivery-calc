<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentPersistenceMapperInterface;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentCreationService {
	/**
	 * @param array<int,CarrierShipmentAdapterInterface> $adapters
	 * @param array<int,CarrierShipmentPersistenceMapperInterface> $persistence_mappers
	 */
	public function __construct(
		private OrderShipmentRepository $repository,
		private array $adapters,
		private ShipmentActualCostService $actual_cost_service,
		private ?Logger $logger = null,
		private ?CarrierShipmentAdapterRegistry $registry = null,
		private array $persistence_mappers = array(),
		private ?ShipmentCreationAttemptService $attempts = null
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function safe_preview( ShipmentCreateRequest $request, ?object $order = null ): array {
		if ( $this->attempts instanceof ShipmentCreationAttemptService && null !== $order ) {
			try {
				$request = $this->attempts->reserve_for_request( $order, $request );
			} catch ( \Throwable $e ) {
				return array(
					'method' => '',
					'path' => '',
					'body' => array(),
					'errors' => array( $e->getMessage() ),
				);
			}
		}
		$adapter = $this->adapter_for( $request );

		if ( ! $adapter instanceof CarrierShipmentAdapterInterface ) {
			return array();
		}
		try {
			return $adapter->build_safe_payload_preview( $request );
		} catch ( \Throwable $e ) {
			return array(
				'method' => '',
				'path' => '',
				'body' => array(),
				'errors' => array( $e->getMessage() ),
			);
		}
	}

	public function create( object $order, ShipmentCreateRequest $request ): ShipmentCreateResult {
		$order_id = $this->order_id( $order );
		if ( $order_id > 0 && $request->order_id !== $order_id ) {
			$this->log_order_mismatch( $order_id, $request );
			return new ShipmentCreateResult(
				false,
				error_code: 'shipment_order_mismatch',
				error_message: 'ID заказа в запросе отправления не совпадает с текущим заказом WooCommerce.'
			);
		}

		$release_create_lock = null;
		if ( $this->attempts instanceof ShipmentCreationAttemptService ) {
			$release_create_lock = $this->attempts->acquire_create_lock( $order, $request );
			if ( null === $release_create_lock ) {
				return new ShipmentCreateResult(
					false,
					error_code: 'shipment_create_in_progress',
					error_message: 'Создание отправления уже выполняется. Дождитесь завершения и обновите статус заказа.'
				);
			}
		}

		try {
		$adapter = $this->adapter_for( $request );
		if ( ! $adapter instanceof CarrierShipmentAdapterInterface ) {
			return new ShipmentCreateResult( false, error_code: 'unsupported_carrier', error_message: 'Для выбранной службы нет адаптера создания отправлений.' );
		}
		$mapper = $this->persistence_mapper_for( $request->carrier_key );
		if ( ! $mapper instanceof CarrierShipmentPersistenceMapperInterface ) {
			return new ShipmentCreateResult(
				false,
				error_code: 'shipment_persistence_mapper_missing',
				error_message: 'Для выбранной службы не настроено сохранение отправления.'
			);
		}

		if ( $this->repository->has_created_for_carrier( $order, $request->carrier_key ) ) {
			$existing = $this->repository->find_by_carrier( $order, $request->carrier_key );
			$message = method_exists( $mapper, 'duplicate_error_message' )
				? (string) $mapper->duplicate_error_message( $existing )
				: 'По заказу уже создано отправление: ' . (string) ( $existing['tracking_number'] ?? $existing['barcode'] ?? '' );
			return new ShipmentCreateResult(
				false,
				error_code: 'shipment_already_created',
				error_message: $message,
				raw_reference: array( 'existing' => $existing )
			);
		}

		if ( $this->attempts instanceof ShipmentCreationAttemptService ) {
			try {
				$request = $this->attempts->reserve_for_request( $order, $request );
			} catch ( \Throwable $e ) {
				return new ShipmentCreateResult(
					false,
					error_code: 'shipment_creation_attempt_state_invalid',
					error_message: $e->getMessage()
				);
			}
		}

		$preview = $this->safe_preview( $request );
		$result = method_exists( $adapter, 'create_for_order' ) ? $adapter->create_for_order( $order, $request ) : $adapter->create( $request );
		$now = $this->now();
		if ( ! $result->success ) {
			$failed_fields = $mapper->build_failed_fields( $request, $result, $preview, $now );
			if ( is_array( $failed_fields ) && array() !== $failed_fields ) {
				$actual_cost_candidate = $this->actual_cost_candidate_from_fields( $failed_fields );
				$shipment = $this->common_shipment_envelope( $request, $result, $preview, $failed_fields, $now );
				$this->repository->save_for_carrier( $order, $request->carrier_key, $shipment );
				if ( $this->attempts instanceof ShipmentCreationAttemptService && ! empty( $shipment['pending_creation_in_carrier'] ) ) {
					$this->attempts->mark_pending( $order, $request );
				}
				$shipment = $this->apply_actual_cost_candidate( $order, $request->carrier_key, $actual_cost_candidate, $shipment );
				$mapper->after_persist( $order, $shipment );
				if ( method_exists( $mapper, 'result_after_failed_persist' ) ) {
					$mapped_result = $mapper->result_after_failed_persist( $request, $result, $shipment );
					if ( $mapped_result instanceof ShipmentCreateResult ) {
						return $mapped_result;
					}
				}
				$this->repository->save_last_error(
					$order,
					array(
						'carrier_key' => $request->carrier_key,
						'service_key' => (string) ( $request->meta['service_key'] ?? $request->rate_id ),
						'error_code' => $result->error_code,
						'error_message' => $result->error_message,
						'updated_at' => $now,
					)
				);
				return $result;
			}
			$this->repository->save_last_error(
				$order,
				array(
					'carrier_key' => $request->carrier_key,
					'service_key' => (string) ( $request->meta['service_key'] ?? $request->rate_id ),
					'error_code' => $result->error_code,
					'error_message' => $result->error_message,
					'updated_at' => $now,
				)
			);
			$this->add_order_note( $order, 'Не удалось создать отправление: ' . $result->error_message );

			return $result;
		}

		$mapped_fields = $mapper->build_created_fields( $request, $result, $preview, $now );
		$actual_cost_candidate = $this->actual_cost_candidate_from_fields( $mapped_fields );
		$shipment = $this->common_shipment_envelope( $request, $result, $preview, $mapped_fields, $now );
		$this->repository->save_for_carrier( $order, $request->carrier_key, $shipment );
		if ( $this->attempts instanceof ShipmentCreationAttemptService ) {
			$this->attempts->mark_active( $order, $request );
		}
		$shipment = $this->apply_actual_cost_candidate( $order, $request->carrier_key, $actual_cost_candidate, $shipment );
		$mapper->after_persist( $order, $shipment );

		return $result;
		} finally {
			if ( is_callable( $release_create_lock ) ) {
				$release_create_lock();
			}
		}
	}

	private function adapter_for( ShipmentCreateRequest $request ): ?CarrierShipmentAdapterInterface {
		if ( $this->registry instanceof CarrierShipmentAdapterRegistry ) {
			$adapter = $this->registry->get( $request->carrier_key );
			if ( $adapter instanceof CarrierShipmentAdapterInterface && $adapter->supports( $request ) ) {
				return $adapter;
			}
		}

		foreach ( $this->adapters as $adapter ) {
			if ( $adapter->supports( $request ) ) {
				return $adapter;
			}
		}

		return null;
	}

	private function persistence_mapper_for( string $carrier_key ): ?CarrierShipmentPersistenceMapperInterface {
		foreach ( $this->persistence_mappers as $mapper ) {
			if ( $mapper instanceof CarrierShipmentPersistenceMapperInterface && $mapper->carrier_key() === $carrier_key ) {
				return $mapper;
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $preview
	 * @param array<string,mixed> $carrier_fields
	 * @return array<string,mixed>
	 */
	private function common_shipment_envelope( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, array $carrier_fields, string $now ): array {
		$request_snapshot = is_array( $carrier_fields['request_snapshot'] ?? null ) ? $carrier_fields['request_snapshot'] : $preview;
		$response_snapshot = array_key_exists( 'response_snapshot', $carrier_fields ) ? $carrier_fields['response_snapshot'] : $result->raw_reference;
		unset( $carrier_fields['actual_cost_candidate'] );

		$common = array(
				'carrier_key' => $request->carrier_key,
				'service_key' => (string) ( $request->meta['service_key'] ?? $request->rate_id ),
				'order_id' => $request->order_id,
				'service_title' => (string) ( $request->meta['service_title'] ?? '' ),
				'delivery_type' => $request->delivery_type,
				'places' => array_map( static fn ( $place ): array => $place->to_array(), $request->places ),
				'request_snapshot' => $request_snapshot,
				'response_snapshot' => $response_snapshot,
				'barcode' => $result->tracking_number,
				'tracking_number' => $result->tracking_number,
				'external_id' => $result->external_id,
				'created_by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'created_by_context' => 'admin_manual',
				'order_num' => (string) ( $request->meta['order_num'] ?? $request->order_id ),
				'status' => (string) ( $carrier_fields['status'] ?? 'created' ),
				'status_title' => (string) ( $carrier_fields['status_title'] ?? '' ),
				'created_at' => $now,
				'updated_at' => $now,
			);
		if ( is_string( $request->meta['creation_attempt_id'] ?? null ) && '' !== trim( (string) $request->meta['creation_attempt_id'] ) ) {
			$common['creation_attempt_id'] = (string) $request->meta['creation_attempt_id'];
			$common['creation_attempt_generation'] = (int) ( $request->meta['creation_attempt_generation'] ?? 0 );
		}

		return array_merge(
			$common,
			$carrier_fields
		);
	}

	/**
	 * @param array<string,mixed> $fields
	 */
	private function actual_cost_candidate_from_fields( array $fields ): ?ShipmentActualCost {
		$candidate = $fields['actual_cost_candidate'] ?? null;

		return $candidate instanceof ShipmentActualCost ? $candidate : null;
	}

	/**
	 * @param array<string,mixed> $fallback
	 * @return array<string,mixed>
	 */
	private function apply_actual_cost_candidate( object $order, string $carrier_key, ?ShipmentActualCost $candidate, array $fallback ): array {
		if ( ! $candidate instanceof ShipmentActualCost ) {
			return $fallback;
		}

		return $this->actual_cost_service->apply_carrier_cost( $order, $carrier_key, $candidate );
	}

	private function order_id( object $order ): int {
		return method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
	}

	private function log_order_mismatch( int $order_id, ShipmentCreateRequest $request ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}
		$this->logger->error(
			'Shipment create blocked because WooCommerce order id and request order_id differ.',
			array(
				'order_id' => $order_id,
				'request_order_id' => $request->order_id,
				'order_num' => (string) ( $request->meta['order_num'] ?? $request->order_id ),
				'carrier_key' => $request->carrier_key,
				'service_key' => (string) ( $request->meta['service_key'] ?? $request->rate_id ),
			)
		);
	}

	private function add_order_note( object $order, string $message ): void {
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $message );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
