<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\PekSettings;
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
		private PekStatusMapping $mapping
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
			$status = $this->statuses->fetch( $code, (string) ( $payload['delivery_type'] ?? '' ) );
			$shipment = array_merge(
				array(
					'carrier_key' => PekSettings::CARRIER_KEY,
					'service_key' => PekSettings::SERVICE_KEY,
					'tracking_number' => $code,
					'barcode' => $code,
					'pek_cargo_code' => $code,
					'manual_attach' => true,
					'pek_reconciled_pending_correlation' => (string) ( $existing['pek_correlation'] ?? $existing['request_summary']['correlation'] ?? '' ),
					'created_at' => $this->now(),
				),
				$status,
				array( 'updated_at' => $this->now() )
			);
			$actual = $shipment['actual_cost_candidate'] ?? null;
			unset( $shipment['actual_cost_candidate'] );
			$this->repository->save_for_carrier( $order, PekSettings::CARRIER_KEY, $shipment );
			if ( $actual instanceof ShipmentActualCost ) {
				$shipment = $this->actual_costs->apply_carrier_cost( $order, PekSettings::CARRIER_KEY, $actual );
			}

			return array( 'success' => true, 'message' => 'Код груза ПЭК прикреплён.', 'shipment' => $shipment );
		} catch ( \Throwable $e ) {
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
				$this->repository->delete_for_carrier( $order, PekSettings::CARRIER_KEY );
				return array( 'success' => true, 'cancelled_and_removed' => true, 'message' => 'Заявка ПЭК отменена и удалена из заказа.' );
			}
		}

		return array( 'success' => false, 'message' => 'ПЭК не подтвердил отмену заявки.' );
	}

	/** @return array<string,mixed> */
	public function remove_local( object $order ): array {
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
}
