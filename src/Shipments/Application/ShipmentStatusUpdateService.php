<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\Tracking\RussianPostTrackingApiClient;
use WallsShop\WDC\Shipments\RussianPost\RussianPostTrackingStatusMapper;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentStatusUpdateService {
	public function __construct(
		private OrderShipmentRepository $repository,
		private RussianPostTrackingApiClient $tracking_client,
		private RussianPostTrackingStatusMapper $mapper
	) {
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
		$this->add_order_note(
			$order,
			sprintf(
				'Статус отправления Почты России обновлен. Barcode: %s. Статус: %s. Почта России: %s.',
				$barcode,
				(string) $status_fields['universal_status_label'],
				(string) $status_fields['carrier_status_title']
			)
		);

		return array(
			'success' => true,
			'message' => 'Статус отправления обновлен.',
			'shipment' => $updated,
			'status' => $this->status_payload( $updated ),
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
	public function status_payload( array $shipment ): array {
		return array(
			'barcode' => (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ),
			'universal_status_code' => (string) ( $shipment['universal_status_code'] ?? '' ),
			'universal_status_label' => (string) ( $shipment['universal_status_label'] ?? '' ),
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
		);
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

	private function add_order_note( object $order, string $message ): void {
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $message );
		}
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
