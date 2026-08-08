<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Shipments\Application\ShipmentCreationAttemptService;
use WallsShop\WDC\Shipments\Application\ShipmentActualCost;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class PekShipmentService {
	public function __construct(
		private PekApiClient $api,
		private PekShipmentStatusService $statuses,
		private OrderShipmentRepository $repository,
		private PekShipmentButtonPolicy $buttons,
		private ShipmentActualCostService $actual_costs,
		private PekStatusMapping $mapping,
		private PekManualAttachContextResolver $manual_contexts,
		private ?ShipmentCreationAttemptService $attempts = null
	) {
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function attach_manual( object $order, array $payload ): array {
		$code = trim( (string) ( $payload['tracking_number'] ?? $payload['cargo_code'] ?? $payload['manual_value'] ?? '' ) );
		if ( '' === $code || strlen( $code ) > 64 ) {
			return array( 'success' => false, 'message' => 'Некорректный код груза ПЭК.' );
		}
		try {
			$existing = $this->repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
			$context = $this->manual_contexts->resolve( $order, $existing );
			$status = $this->statuses->fetch( $code, (string) $context['delivery_type'] );
			$actual = $status['actual_cost_candidate'] ?? null;
			unset( $status['actual_cost_candidate'] );
			$shipment = array_merge(
				array(
					'carrier_key' => PekSettings::CARRIER_KEY,
					'service_key' => (string) $context['service_key'],
					'service_title' => (string) $context['service_title'],
					'order_id' => (int) $context['order_id'],
					'creation_attempt_id' => (string) $context['creation_attempt_id'],
					'creation_attempt_generation' => (int) $context['creation_attempt_generation'],
					'delivery_type' => (string) $context['delivery_type'],
					'shipment_mode' => (string) $context['shipment_mode'],
					'rate_id' => (string) $context['rate_id'],
					'tracking_number' => $code,
					'barcode' => $code,
					'pek_cargo_code' => $code,
					'manual_attach' => true,
					'pending_creation_in_carrier' => false,
					'pek_correlation' => (string) $context['pek_correlation'],
					'pek_reconciled_pending_correlation' => (string) $context['pek_correlation'],
					'created_at' => '' !== (string) $context['original_created_at'] ? (string) $context['original_created_at'] : $this->now(),
					'reconciled_at' => $this->now(),
					'reconciliation_source' => 'manual_cargo_code',
					'pek_sender_warehouse_id' => (string) $context['pek_sender_warehouse_id'],
					'pek_sender_warehouse_title' => (string) $context['pek_sender_warehouse_title'],
					'pek_sender_warehouse_source' => (string) $context['pek_sender_warehouse_source'],
					'pek_receiver_warehouse_id' => (string) $context['pek_receiver_warehouse_id'],
					'pek_receiver_warehouse_source' => (string) $context['pek_receiver_warehouse_source'],
					'pek_receiver_branch_id' => (string) $context['pek_receiver_branch_id'],
					'recipient_type' => (string) $context['recipient_type'],
					'declared_value_kopecks' => (int) $context['declared_value_kopecks'],
					'sealing_requested' => ! empty( $context['sealing_requested'] ),
					'sms_release_requested' => ! empty( $context['sms_release_requested'] ),
					'sms_release_confirmed' => ! empty( $context['sms_release_confirmed'] ),
					'sms_release_effective_limit_kopecks' => (int) $context['sms_release_effective_limit_kopecks'],
					'places' => $context['places'],
					'order_num' => (string) $context['order_num'],
					'request_snapshot' => $context['request_snapshot'],
					'request_summary' => $context['request_summary'],
					'pek_reconciliation' => array(
						'correlation' => (string) $context['pek_correlation'],
						'original_created_at' => (string) $context['original_created_at'],
						'reconciled_at' => $this->now(),
						'source' => 'manual_cargo_code',
					),
				),
				$status,
				array( 'updated_at' => $this->now() )
			);
			unset( $shipment['failure_stage'] );
			if ( '' === trim( (string) ( $shipment['creation_attempt_id'] ?? '' ) ) ) {
				unset( $shipment['creation_attempt_id'], $shipment['creation_attempt_generation'] );
			}
			if ( is_array( $shipment['response_snapshot'] ?? null ) ) {
				unset( $shipment['response_snapshot']['error_code'], $shipment['response_snapshot']['failure_stage'] );
			}
			$this->repository->save_for_carrier( $order, PekSettings::CARRIER_KEY, $shipment );
			if ( $this->attempts instanceof ShipmentCreationAttemptService ) {
				$this->attempts->mark_active_for_shipment( $order, PekSettings::CARRIER_KEY, $shipment );
			}
			if ( $actual instanceof ShipmentActualCost ) {
				$shipment = $this->actual_costs->apply_carrier_cost( $order, PekSettings::CARRIER_KEY, $actual );
			}

			return array( 'success' => true, 'message' => 'Код груза ПЭК прикреплён.', 'tracking_number' => $code, 'shipment' => $shipment );
		} catch ( \Throwable $e ) {
			if ( str_contains( $e->getMessage(), 'Не удалось восстановить данные отправления ПЭК' ) ) {
				return array( 'success' => false, 'message' => 'Не удалось восстановить данные отправления ПЭК для ручного прикрепления.' );
			}
			return array( 'success' => false, 'message' => 'ПЭК не подтвердил указанный код груза.' );
		}
	}

	/** @return array<string,mixed> */
	public function cancel_in_carrier( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
		$code = $this->tracking_identifier( $shipment );
		if ( '' === $code ) {
			return array( 'success' => false, 'message' => 'Не указан код груза ПЭК.' );
		}
		if ( ! $this->is_old_enough_for_cancel( $shipment ) ) {
			return array( 'success' => false, 'message' => 'Заявку ПЭК можно отменить через несколько минут после создания.' );
		}
		if ( empty( $this->buttons->resolve( $shipment )['cancel'] ) ) {
			return array( 'success' => false, 'message' => 'Принятый груз ПЭК не отменяется через API.' );
		}
		try {
			$fresh = $this->statuses->fetch( $code, (string) ( $shipment['delivery_type'] ?? '' ) );
		} catch ( \Throwable ) {
			return array( 'success' => false, 'message' => 'Не удалось проверить текущий статус ПЭК перед отменой.' );
		}
		$fresh_shipment = array_merge( $shipment, $fresh );
		$external_status = (string) ( $fresh_shipment['pek_cargo_status'] ?? $fresh_shipment['status_title'] ?? '' );
		if (
			'' !== trim( (string) ( $fresh_shipment['pek_take_on_stock_datetime'] ?? '' ) )
			|| ! $this->mapping->is_pre_acceptance_status( $external_status )
			|| empty( $this->buttons->resolve( $fresh_shipment )['cancel'] )
		) {
			return array( 'success' => false, 'message' => 'Принятый груз ПЭК не отменяется через API.' );
		}
		$result = $this->api->order_cancellation( array( $code ) );
		foreach ( $result as $row ) {
			if ( $code === (string) ( $row['code'] ?? '' ) && true === ( $row['success'] ?? false ) ) {
				$this->mark_terminal_before_delete( $order, $shipment, 'cancelled' );
				$this->repository->delete_for_carrier( $order, PekSettings::CARRIER_KEY );
				return array( 'success' => true, 'cancelled_and_removed' => true, 'message' => 'Заявка ПЭК отменена и удалена из заказа.' );
			}
		}

		return array( 'success' => false, 'message' => 'ПЭК не подтвердил отмену заявки.' );
	}

	/** @return array<string,mixed> */
	public function remove_local( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
		$this->mark_terminal_before_delete( $order, $shipment, 'local_removed' );
		$this->repository->delete_for_carrier( $order, PekSettings::CARRIER_KEY );

		return array( 'success' => true, 'message' => 'Отправление ПЭК удалено из заказа локально.', 'removed' => true );
	}

	/** @param array<string,mixed> $shipment */
	private function tracking_identifier( array $shipment ): string {
		return trim( (string) ( $shipment['pek_cargo_code'] ?? $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
	}

	/** @param array<string,mixed> $shipment */
	private function is_old_enough_for_cancel( array $shipment ): bool {
		$created = $this->stored_timestamp( (string) ( $shipment['created_at'] ?? '' ) );
		$now = function_exists( 'current_datetime' ) ? current_datetime()->getTimestamp() : time();

		return null !== $created && $now - $created >= 600;
	}

	private function stored_timestamp( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		foreach ( array( '!Y-m-d H:i:s', \DateTimeInterface::ATOM ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( $format, $value, $timezone );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( $date instanceof \DateTimeImmutable && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $date->getTimestamp();
			}
		}

		return null;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	/** @param array<string,mixed> $shipment */
	private function mark_terminal_before_delete( object $order, array $shipment, string $reason ): void {
		if ( $this->attempts instanceof ShipmentCreationAttemptService ) {
			$this->attempts->mark_terminal_for_shipment( $order, PekSettings::CARRIER_KEY, $shipment, $reason );
		}
	}
}
