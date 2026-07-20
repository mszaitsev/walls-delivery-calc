<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\Tracking\RussianPostTrackingApiClient;
use WallsShop\WDC\Shipments\Presentation\ShipmentActualCostComparisonService;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\RussianPost\RussianPostTrackingStatusMapper;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentStatusUpdateService {
	public function __construct(
		private OrderShipmentRepository $repository,
		private RussianPostTrackingApiClient $tracking_client,
		private RussianPostTrackingStatusMapper $mapper,
		private ShipmentOrderStatusMappingService|null $order_status_mapping = null,
		private ShipmentActualCostComparisonService|null $actual_costs = null,
		private ShipmentBaseApiCostResolver|null $base_costs = null,
		private ShipmentActualCostResolver|null $actual_cost_resolver = null
	) {
		if ( ! $this->actual_costs instanceof ShipmentActualCostComparisonService ) {
			$this->actual_costs = new ShipmentActualCostComparisonService();
		}
		if ( ! $this->base_costs instanceof ShipmentBaseApiCostResolver ) {
			$this->base_costs = new ShipmentBaseApiCostResolver();
		}
		if ( ! $this->actual_cost_resolver instanceof ShipmentActualCostResolver ) {
			$this->actual_cost_resolver = new ShipmentActualCostResolver( $this->actual_costs, $this->base_costs );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function update_russian_post( object $order, string $shipment_key = RussianPostDomesticSettings::CARRIER_KEY ): array {
		if ( RussianPostDomesticSettings::CARRIER_KEY !== $shipment_key ) {
			return $this->failure( 'Сначала создайте отправление.' );
		}

		$shipment = $this->repository->find_by_carrier( $order, $shipment_key );
		if ( array() === $shipment || ! in_array( (string) ( $shipment['status'] ?? '' ), array( 'created', 'registered' ), true ) ) {
			return $this->failure( 'Сначала создайте отправление.' );
		}

		$barcode = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
		if ( '' === $barcode ) {
			return $this->failure( 'У отправления нет ШПИ.' );
		}

		$response = $this->tracking_client->get_operation_history( $barcode );
		if ( ! (bool) ( $response['success'] ?? false ) ) {
			$message = (string) ( $response['error_message'] ?? '' );
			return $this->failure( '' !== $message ? $message : 'Не удалось получить статус Почты России.', $response );
		}

		$latest = is_array( $response['latest_record'] ?? null ) ? $response['latest_record'] : array();
		if ( array() === $latest ) {
			return $this->failure( 'Почта России вернула пустую историю операций.', $response );
		}

		$status_fields = $this->mapper->map_record( $latest );
		$checked_at = $this->now();
		$updated = array_merge(
			$shipment,
			$status_fields,
			array(
				'tracking_checked_at' => $checked_at,
				'tracking_raw_snapshot' => (string) ( $response['raw_response_snapshot'] ?? '' ),
				'updated_at' => $checked_at,
			)
		);
		if ( empty( $status_fields['carrier_status_is_terminal'] ) ) {
			$updated['carrier_status_is_terminal'] = false;
		}

		$this->repository->save_for_carrier( $order, $shipment_key, $updated );
		$order_status_mapping = $this->order_status_mapping instanceof ShipmentOrderStatusMappingService
			? $this->order_status_mapping->apply( $order, $updated, (string) ( $status_fields['universal_status_code'] ?? '' ) )
			: array( 'status' => 'skipped', 'changed' => false, 'reason' => 'service_unavailable' );

		return array(
			'success' => true,
			'message' => 'Статус отправления обновлен.',
			'shipment' => $updated,
			'status' => $this->status_payload( $updated, $order ),
			'order_status_mapping' => $order_status_mapping,
			'tracking_response' => array(
				'http_code' => (int) ( $response['http_code'] ?? 0 ),
				'barcode' => $barcode,
			),
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	public function status_payload( array $shipment, ?object $order = null ): array {
		return array_merge(
			array(
				'barcode' => (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ),
				'universal_status_code' => (string) ( $shipment['universal_status_code'] ?? '' ),
				'universal_status_label' => (string) ( $shipment['universal_status_label'] ?? '' ),
				'shipment_status_label' => $this->shipment_status_label( $shipment ),
				'carrier_status_title' => (string) ( $shipment['carrier_status_title'] ?? '' ),
				'carrier_status_description' => (string) ( $shipment['carrier_status_description'] ?? '' ),
				'carrier_operation_type_id' => (string) ( $shipment['carrier_operation_type_id'] ?? '' ),
				'carrier_operation_type_name' => (string) ( $shipment['carrier_operation_type_name'] ?? '' ),
				'carrier_operation_attr_id' => (string) ( $shipment['carrier_operation_attr_id'] ?? '' ),
				'carrier_operation_attr_name' => (string) ( $shipment['carrier_operation_attr_name'] ?? '' ),
				'carrier_operation_date' => (string) ( $shipment['carrier_operation_date'] ?? '' ),
				'carrier_operation_address' => (string) ( $shipment['carrier_operation_address'] ?? '' ),
				'carrier_operation_index' => (string) ( $shipment['carrier_operation_index'] ?? '' ),
				'tracking_checked_at' => (string) ( $shipment['tracking_checked_at'] ?? '' ),
				'carrier_status_is_terminal' => ! empty( $shipment['carrier_status_is_terminal'] ),
				'can_cancel' => $this->can_cancel( $shipment ),
			),
			$this->actual_cost_payload( $shipment, $order )
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function can_cancel( array $shipment ): bool {
		$barcode = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );

		return '' !== $barcode
			&& (int) ( $shipment['backlog_order_id'] ?? 0 ) > 0
			&& in_array( (string) ( $shipment['status'] ?? '' ), array( 'created', 'registered' ), true )
			&& ( '28' === (string) ( $shipment['carrier_operation_type_id'] ?? '' ) || 'Присвоение идентификатора' === (string) ( $shipment['carrier_operation_type_name'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function failure( string $message, array $context = array() ): array {
		return array(
			'success' => false,
			'message' => $message,
			'context' => $context,
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function shipment_status_label( array $shipment ): string {
		$universal_label = trim( (string) ( $shipment['universal_status_label'] ?? '' ) );
		if ( '' !== $universal_label ) {
			return $universal_label;
		}

		return match ( (string) ( $shipment['status'] ?? '' ) ) {
			'created' => 'создано',
			'registered' => 'зарегистрировано',
			'failed' => 'ошибка',
			'', 'draft' => 'не создано',
			default => 'не определено',
		};
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	private function actual_cost_payload( array $shipment, ?object $order ): array {
		return $this->actual_cost_resolver()->presentation_payload( $shipment, $order );
	}

	private function positive_int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) {
			$integer = (int) $value;

			return $integer > 0 ? $integer : null;
		}

		return null;
	}

	private function actual_costs(): ShipmentActualCostComparisonService {
		if ( ! isset( $this->actual_costs ) || ! $this->actual_costs instanceof ShipmentActualCostComparisonService ) {
			$this->actual_costs = new ShipmentActualCostComparisonService();
		}

		return $this->actual_costs;
	}

	private function base_costs(): ShipmentBaseApiCostResolver {
		if ( ! isset( $this->base_costs ) || ! $this->base_costs instanceof ShipmentBaseApiCostResolver ) {
			$this->base_costs = new ShipmentBaseApiCostResolver();
		}

		return $this->base_costs;
	}

	private function actual_cost_resolver(): ShipmentActualCostResolver {
		if ( ! isset( $this->actual_cost_resolver ) || ! $this->actual_cost_resolver instanceof ShipmentActualCostResolver ) {
			$this->actual_cost_resolver = new ShipmentActualCostResolver( $this->actual_costs(), $this->base_costs() );
		}

		return $this->actual_cost_resolver;
	}

	private function now(): string {
		return $this->novosibirsk_now();
	}

	private function novosibirsk_now(): string {
		try {
			return ( new \DateTimeImmutable( 'now', new \DateTimeZone( 'Asia/Novosibirsk' ) ) )->format( 'Y-m-d H:i:s' );
		} catch ( \Throwable ) {
			$hour = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
			return gmdate( 'Y-m-d H:i:s', time() + 7 * $hour );
		}
	}
}
