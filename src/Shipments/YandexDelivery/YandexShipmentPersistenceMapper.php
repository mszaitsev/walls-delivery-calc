<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentPersistenceMapperInterface;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentPersistenceMapper implements CarrierShipmentPersistenceMapperInterface {
	public function __construct( private YandexShipmentRepository $repository ) {
	}

	public function carrier_key(): string { return YandexDeliverySettings::CARRIER_KEY; }

	public function build_created_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): array {
		unset( $preview, $now );
		$fields = is_array( $result->raw_reference['yandex'] ?? null ) ? $result->raw_reference['yandex'] : array();

		return array_merge(
			array(
				'request_snapshot' => $this->canonical_request_snapshot(),
				'response_snapshot' => is_array( $fields['yandex_request_info_snapshot'] ?? null ) ? $fields['yandex_request_info_snapshot'] : $result->raw_reference,
				'status' => 'created',
				'status_title' => 'Отправление Яндекс создано: ' . (string) ( $fields['yandex_status'] ?? '' ),
			),
			$fields
		);
	}

	public function build_failed_fields( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): ?array {
		unset( $preview );
		if ( 'request_info_after_confirm_failed' !== $result->error_code ) {
			return null;
		}
		$reconciliation = is_array( $result->raw_reference['yandex_reconciliation'] ?? null ) ? $result->raw_reference['yandex_reconciliation'] : array();
		$request_id = trim( (string) ( $reconciliation['confirmed_request_id'] ?? '' ) );
		if ( '' === $request_id ) {
			return null;
		}

		return array(
			'request_snapshot' => $this->canonical_request_snapshot(),
			'response_snapshot' => $this->sanitize_diagnostics( $reconciliation ),
			'barcode' => $request_id,
			'tracking_number' => $request_id,
			'external_id' => $request_id,
			'request_id' => $request_id,
			'yandex_request_id' => $request_id,
			'yandex_operator_request_id' => (string) ( $request->meta['yandex_operator_request_id'] ?? $request->meta['order_num'] ?? $request->order_id ),
			'yandex_selected_offer_id' => (string) ( $reconciliation['selected_offer_id'] ?? '' ),
			'yandex_offer_expires_at' => (string) ( $reconciliation['selected_offer_expires_at'] ?? '' ),
			'status' => 'reconciliation_required',
			'yandex_status' => 'reconciliation_required',
			'status_title' => 'Отправление Яндекс создано, требуется получение статуса',
			'yandex_reconciliation_required' => true,
			'yandex_registration_phase' => (string) ( $reconciliation['registration_phase'] ?? 'request_info' ),
			'yandex_registration_error_code' => (string) ( $reconciliation['error_code'] ?? $result->error_code ),
			'yandex_registration_error_message' => (string) ( $reconciliation['error_message'] ?? $result->error_message ),
			'yandex_registration_error_details' => is_array( $reconciliation['api_error_details'] ?? null ) ? $this->sanitize_diagnostics( $reconciliation['api_error_details'] ) : array(),
			'created_at' => $now,
			'updated_at' => $now,
		);
	}

	public function after_persist( object $order, array $shipment ): void {
		$this->repository->sync_lookup_meta( $order, $shipment );
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	/** @return array<string,mixed> */
	private function canonical_request_snapshot(): array {
		return array(
			'method' => 'POST',
			'path' => '/api/b2b/platform/offers/create?send_unix=false',
			'body' => array(),
			'errors' => array(),
			'note' => 'Canonical Yandex shipment state is request/info; offers/create payload is not persisted.',
		);
	}

	/**
	 * @param array<string,mixed> $diagnostics
	 * @return array<string,mixed>
	 */
	private function sanitize_diagnostics( array $diagnostics ): array {
		$sanitized = $diagnostics;
		unset( $sanitized['Authorization'], $sanitized['authorization'], $sanitized['token'], $sanitized['bearer_token'] );

		return $sanitized;
	}
}
