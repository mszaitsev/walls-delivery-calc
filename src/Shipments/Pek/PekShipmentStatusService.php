<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Application\ShipmentActualCost;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationAttemptService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class PekShipmentStatusService {
	public function __construct(
		private PekApiClient $api,
		private PekStatusMapping $mapping,
		private OrderShipmentRepository $repository,
		private ShipmentActualCostService $actual_costs,
		private PekShipmentStatusResponseNormalizer $normalizer,
		private ShipmentCreationAttemptService $attempts
	) {
	}

	/** @return array<string,mixed> */
	public function update( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, PekSettings::CARRIER_KEY );
		$code = $this->cargo_code( $shipment );
		if ( '' === $code ) {
			return array( 'success' => false, 'message' => 'Не указан код груза ПЭК.' );
		}
		$status = $this->fetch( $code, (string) ( $shipment['delivery_type'] ?? $shipment['shipment_mode'] ?? '' ) );
		$candidate = $status['actual_cost_candidate'] ?? null;
		unset( $status['actual_cost_candidate'] );
		$clear_status_id = array_key_exists( 'pek_cargo_status_id', $status ) && null === $status['pek_cargo_status_id'];
		if ( $clear_status_id ) {
			unset( $status['pek_cargo_status_id'] );
		}
		if ( DeliveryStatus::CANCELLED === (string) ( $status['universal_status_code'] ?? '' ) ) {
			$this->mark_terminal_before_delete( $order, $shipment, 'cancelled' );
			$this->repository->delete_for_carrier( $order, PekSettings::CARRIER_KEY );

			return array(
				'success' => true,
				'message' => 'Статус ПЭК обновлён. Отменённое отправление удалено из заказа.',
				'shipment' => array(),
				'status' => $status,
				'terminal' => true,
				'removed' => true,
				'cancelled_and_removed' => true,
				'terminal_reason' => 'cancelled',
				'terminal_source' => 'status_update',
			);
		}
		$shipment = array_merge( $shipment, $status, array( 'updated_at' => $this->now() ) );
		if ( $clear_status_id ) {
			unset( $shipment['pek_cargo_status_id'] );
		}
		$this->repository->save_for_carrier( $order, PekSettings::CARRIER_KEY, $shipment );
		if ( $candidate instanceof ShipmentActualCost ) {
			$shipment = $this->actual_costs->apply_carrier_cost( $order, PekSettings::CARRIER_KEY, $candidate );
		}

		return array( 'success' => true, 'message' => 'Статус ПЭК обновлён.', 'shipment' => $shipment, 'status' => $status );
	}

	/** @return array<string,mixed> */
	public function fetch( string $cargo_code, string $delivery_type ): array {
		$source = 'expanded';
		try {
			$response = $this->api->cargo_status( array( $cargo_code ) );
		} catch ( PekApiException $exception ) {
			if ( ! $this->can_fallback_to_basic( $exception ) ) {
				throw new \RuntimeException( 'Не удалось получить статус ПЭК.' );
			}
			$response = $this->api->cargo_basic_status( array( $cargo_code ) );
			$source = 'basic';
		}
		$normalized = $this->normalizer->normalize( $response, $cargo_code, $this->now() );
		$status_title = (string) $normalized['status_title'];
		$universal = $this->mapping->map( $status_title, $delivery_type );

		return array_merge(
			$normalized,
			array(
			'status' => 'created',
			'pek_status_source' => $source,
			'universal_status_code' => $universal,
			'universal_status_label' => DeliveryStatus::label( $universal ),
			)
		);
	}

	/** @param array<string,mixed> $shipment */
	public function cargo_code( array $shipment ): string {
		return trim( (string) ( $shipment['pek_cargo_code'] ?? $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
	}

	private function can_fallback_to_basic( PekApiException $exception ): bool {
		$context = $exception->context();
		$status = (int) ( $context['http_status'] ?? $context['status'] ?? 0 );

		return in_array( $status, array( 403, 404 ), true );
	}

	/** @param array<string,mixed> $shipment */
	private function mark_terminal_before_delete( object $order, array $shipment, string $reason ): void {
		$this->attempts->mark_terminal_for_shipment( $order, PekSettings::CARRIER_KEY, $shipment, $reason );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
