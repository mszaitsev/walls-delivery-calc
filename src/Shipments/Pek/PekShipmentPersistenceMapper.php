<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentPersistenceMapperInterface;

defined( 'ABSPATH' ) || exit;

final class PekShipmentPersistenceMapper implements CarrierShipmentPersistenceMapperInterface {
	public function carrier_key(): string {
		return PekSettings::CARRIER_KEY;
	}

	/** @param array<string,mixed> $preview @return array<string,mixed> */
	public function build_created_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): array {
		$ref = is_array( $result->raw_reference ) ? $result->raw_reference : array();
		$summary = is_array( $ref['summary'] ?? null ) ? $ref['summary'] : array();
		$sender = is_array( $summary['sender_warehouse'] ?? null ) ? $summary['sender_warehouse'] : array();
		$sms = is_array( $summary['sms'] ?? null ) ? $summary['sms'] : array();

		return array(
			'pek_document_id' => $result->external_id,
			'pek_cargo_code' => $result->tracking_number,
			'tracking_number' => $result->tracking_number,
			'pek_cargo_barcode' => (string) ( $ref['cargo_barcode'] ?? '' ),
			'pek_position_barcodes' => is_array( $ref['position_barcodes'] ?? null ) ? $ref['position_barcodes'] : array(),
			'pek_sender_warehouse_id' => (string) ( $sender['warehouseId'] ?? '' ),
			'pek_sender_warehouse_title' => (string) ( $sender['divisionName'] ?? $sender['branchName'] ?? '' ),
			'pek_sender_warehouse_source' => (string) ( $sender['source'] ?? '' ),
			'pek_receiver_warehouse_id' => (string) ( $summary['receiver_warehouse_id'] ?? '' ),
			'pek_receiver_warehouse_source' => '' !== (string) ( $summary['receiver_warehouse_id'] ?? '' ) ? 'checkout_selection' : '',
			'pek_receiver_branch_id' => (string) ( $summary['receiver_branch_id'] ?? '' ),
			'shipment_mode' => (string) ( $summary['shipment_mode'] ?? $request->delivery_type ),
			'recipient_type' => 'physical',
			'sms_release_requested' => true,
			'sms_release_confirmed' => ! empty( $sms['success'] ),
			'sms_release_effective_limit_kopecks' => (int) ( $sms['effective_limit_kopecks'] ?? 0 ),
			'declared_value_kopecks' => (int) ( $summary['declared_value_kopecks'] ?? 0 ),
			'pek_product_type' => PekSettings::LTL_PRODUCT_TYPE,
			'pek_order_type' => 0,
			'pek_correlation' => (string) ( $summary['correlation'] ?? '' ),
			'sealing_requested' => ! empty( $summary['sealing_requested'] ),
			'status' => 'created',
			'status_title' => 'Создано в ПЭК',
			'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER,
			'universal_status_label' => DeliveryStatus::label( DeliveryStatus::CREATED_IN_CARRIER ),
			'request_snapshot' => $preview,
			'response_snapshot' => $this->safe_response( $ref ),
			'created_at' => $now,
		);
	}

	/** @param array<string,mixed> $preview @return array<string,mixed>|null */
	public function build_failed_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): ?array {
		if ( 'pek_uncertain_submit' !== $result->error_code ) {
			return null;
		}
		$ref = is_array( $result->raw_reference ) ? $result->raw_reference : array();
		$summary = is_array( $ref['summary'] ?? null ) ? $ref['summary'] : array();
		$sender = is_array( $summary['sender_warehouse'] ?? null ) ? $summary['sender_warehouse'] : array();
		$sms = is_array( $summary['sms'] ?? null ) ? $summary['sms'] : array();

		return array(
			'status' => DeliveryStatus::PENDING_CREATION_IN_CARRIER,
			'status_title' => 'Результат создания заявки не определён. Проверьте кабинет ПЭК перед повторной попыткой.',
			'universal_status_code' => DeliveryStatus::PENDING_CREATION_IN_CARRIER,
			'universal_status_label' => DeliveryStatus::label( DeliveryStatus::PENDING_CREATION_IN_CARRIER ),
			'pending_creation_in_carrier' => true,
			'pek_correlation' => (string) ( $ref['correlation'] ?? '' ),
			'rate_id' => $request->rate_id,
			'shipment_mode' => (string) ( $summary['shipment_mode'] ?? $request->delivery_type ),
			'recipient_type' => 'physical',
			'pek_sender_warehouse_id' => (string) ( $sender['warehouseId'] ?? '' ),
			'pek_sender_warehouse_title' => (string) ( $sender['divisionName'] ?? $sender['branchName'] ?? '' ),
			'pek_sender_warehouse_source' => (string) ( $sender['source'] ?? '' ),
			'pek_receiver_warehouse_id' => (string) ( $summary['receiver_warehouse_id'] ?? '' ),
			'pek_receiver_warehouse_source' => '' !== (string) ( $summary['receiver_warehouse_id'] ?? '' ) ? 'checkout_selection' : '',
			'pek_receiver_branch_id' => (string) ( $summary['receiver_branch_id'] ?? '' ),
			'declared_value_kopecks' => (int) ( $summary['declared_value_kopecks'] ?? 0 ),
			'sealing_requested' => ! empty( $summary['sealing_requested'] ),
			'sms_release_requested' => true,
			'sms_release_confirmed' => ! empty( $sms['success'] ),
			'sms_release_effective_limit_kopecks' => (int) ( $sms['effective_limit_kopecks'] ?? 0 ),
			'failure_stage' => (string) ( $ref['failure_stage'] ?? '' ),
			'request_snapshot' => $preview,
			'request_summary' => $summary,
			'response_snapshot' => array(
				'error_code' => $result->error_code,
				'endpoint' => (string) ( $ref['endpoint'] ?? '/preregistration/submit/' ),
				'method' => (string) ( $ref['method'] ?? 'POST' ),
				'http_status' => $ref['http_status'] ?? '',
				'failure_stage' => (string) ( $ref['failure_stage'] ?? '' ),
				'correlation' => (string) ( $ref['correlation'] ?? '' ),
				'checked_at' => $now,
			),
		);
	}

	/** @param array<string,mixed> $shipment */
	public function after_persist( object $order, array $shipment ): void {
		unset( $order, $shipment );
	}

	/** @param array<string,mixed> $ref @return array<string,mixed> */
	private function safe_response( array $ref ): array {
		return array(
			'endpoint' => '/preregistration/submit/',
			'http_status' => $ref['http_status'] ?? '',
			'document_id' => (string) ( $ref['document_id'] ?? '' ),
			'cargo_code' => (string) ( $ref['cargo_code'] ?? '' ),
			'cargo_barcode' => (string) ( $ref['cargo_barcode'] ?? '' ),
			'position_barcode_count' => count( is_array( $ref['position_barcodes'] ?? null ) ? $ref['position_barcodes'] : array() ),
			'correlation' => (string) ( $ref['correlation'] ?? '' ),
		);
	}
}
