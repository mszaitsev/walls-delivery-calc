<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryRequestInfo;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Application\ShipmentOrderStatusMappingService;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentPersistenceMapperInterface;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentPersistenceMapper implements CarrierShipmentPersistenceMapperInterface {
	public function __construct(
		private YandexShipmentRepository $repository,
		private ?YandexStatusMapping $status_mapping = null,
		private ?ShipmentOrderStatusMappingService $order_status_mapping = null
	) {
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
				'status_title' => (string) ( $fields['yandex_status'] ?? '' ),
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
			'yandex_operator_request_id' => (string) ( $reconciliation['yandex_operator_request_id'] ?? $request->meta['yandex_operator_request_id'] ?? $request->meta['order_num'] ?? $request->order_id ),
			'operator_request_id' => (string) ( $reconciliation['yandex_operator_request_id'] ?? $request->meta['yandex_operator_request_id'] ?? $request->meta['order_num'] ?? $request->order_id ),
			'yandex_registration_sequence_index' => (int) ( $reconciliation['yandex_registration_sequence_index'] ?? $request->meta['yandex_registration_sequence_index'] ?? 0 ),
			'yandex_registration_attempt_started_at' => (string) ( $reconciliation['yandex_registration_attempt_started_at'] ?? $request->meta['yandex_registration_attempt_started_at'] ?? '' ),
			'yandex_temporary_barcode_prefix' => (string) ( $reconciliation['yandex_temporary_barcode_prefix'] ?? $request->meta['yandex_temporary_barcode_prefix'] ?? '' ),
			'yandex_selected_offer_id' => (string) ( $reconciliation['selected_offer_id'] ?? '' ),
			'yandex_offer_expires_at' => (string) ( $reconciliation['selected_offer_expires_at'] ?? '' ),
			'yandex_offer_pricing' => (string) ( $reconciliation['selected_offer_pricing'] ?? '' ),
			'yandex_offer_pricing_total' => (string) ( $reconciliation['selected_offer_pricing_total'] ?? '' ),
			'yandex_offer_pricing_total_kopecks' => max( 0, (int) ( $reconciliation['selected_offer_pricing_total_kopecks'] ?? 0 ) ),
			'yandex_offer_delivery_interval' => is_array( $reconciliation['selected_offer_delivery_interval'] ?? null ) ? $reconciliation['selected_offer_delivery_interval'] : array(),
			'yandex_offer_pickup_interval' => is_array( $reconciliation['selected_offer_pickup_interval'] ?? null ) ? $reconciliation['selected_offer_pickup_interval'] : array(),
			'yandex_selected_offer_snapshot' => is_array( $reconciliation['selected_offer_snapshot'] ?? null ) ? $reconciliation['selected_offer_snapshot'] : array(),
			'status' => 'reconciliation_required',
			'yandex_status' => '',
			'universal_status_code' => DeliveryStatus::PENDING_CREATION_IN_CARRIER,
			'universal_status_label' => DeliveryStatus::label( DeliveryStatus::PENDING_CREATION_IN_CARRIER ),
			'status_title' => 'Ожидается получение статуса',
			'yandex_reconciliation_required' => true,
			'yandex_registration_phase' => (string) ( $reconciliation['registration_phase'] ?? 'request_info' ),
			'yandex_registration_error_code' => (string) ( $reconciliation['error_code'] ?? $result->error_code ),
			'yandex_registration_error_message' => (string) ( $reconciliation['error_message'] ?? $result->error_message ),
			'yandex_registration_error_details' => is_array( $reconciliation['api_error_details'] ?? null ) ? $this->sanitize_diagnostics( $reconciliation['api_error_details'] ) : array(),
			'created_at' => $now,
			'updated_at' => $now,
		);
	}

	public function build_manual_attach_fields( object $order, YandexDeliveryRequestInfo $info, string $now ): array {
		$request_id = $info->request_id;
		$tracking = '' !== $info->courier_order_id ? $info->courier_order_id : $request_id;

		return array_merge(
			array(
				'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
				'service_key' => YandexDeliverySettings::SERVICE_KEY,
				'order_id' => method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
				'service_title' => YandexDeliverySettings::TITLE,
				'delivery_type' => $this->delivery_type_from_info( $info ),
				'places' => $this->generic_places_from_info( $info ),
				'request_snapshot' => $this->canonical_request_snapshot(),
				'response_snapshot' => $info->raw,
				'barcode' => $tracking,
				'tracking_number' => $tracking,
				'external_id' => $request_id,
				'created_by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
				'created_by_context' => 'admin_manual_attach',
				'order_num' => method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) ( method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0 ),
				'status' => 'created',
				'status_title' => $info->status,
				'created_at' => $now,
				'updated_at' => $now,
			),
			$this->fields_from_info( $info )
		);
	}

	/** @return array<string,mixed> */
	public function fields_from_info( YandexDeliveryRequestInfo $info ): array {
		$universal_status = $this->universal_status_for( $info->status );
		return array(
			'yandex_request_id' => $info->request_id,
			'request_id' => $info->request_id,
			'yandex_courier_order_id' => $info->courier_order_id,
			'courier_order_id' => $info->courier_order_id,
			'yandex_sharing_url' => $info->sharing_url,
			'sharing_url' => $info->sharing_url,
			'yandex_status' => $info->status,
			'yandex_status_description' => '' !== $info->state->description ? $info->state->description : $this->yandex_status_description( $info->status ),
			'yandex_status_reason' => $info->state->reason,
			'yandex_status_timestamp' => $info->state->timestamp,
			'universal_status_code' => $universal_status,
			'universal_status_label' => DeliveryStatus::label( $universal_status ),
			'yandex_status_mapping_source' => $this->status_mapping instanceof YandexStatusMapping && $this->status_mapping->known( $info->status ) ? 'mapping' : 'unknown_fallback',
			'yandex_operator_request_id' => $info->operator_request_id,
			'operator_request_id' => $info->operator_request_id,
			'yandex_delivery_policy' => $info->delivery_policy,
			'yandex_destination' => $info->destination,
			'yandex_recipient' => $info->recipient,
			'yandex_items' => $info->items,
			'yandex_places' => $info->places,
			'yandex_place_barcode_map' => $info->place_barcode_map,
			'yandex_request_info_snapshot' => $info->raw,
			'yandex_available_actions' => $info->available_actions,
			'yandex_full_items_price_kopecks' => $info->full_items_price_kopecks,
		);
	}

	public function after_persist( object $order, array $shipment ): void {
		$this->repository->sync_lookup_meta( $order, $shipment );
		$this->note_unknown_status( $order, $shipment );
		if ( $this->order_status_mapping instanceof ShipmentOrderStatusMappingService && '' !== (string) ( $shipment['yandex_status'] ?? '' ) ) {
			$this->order_status_mapping->apply( $order, $shipment, (string) ( $shipment['universal_status_code'] ?? '' ) );
		}
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}
	}

	private function universal_status_for( string $status ): string {
		if ( $this->status_mapping instanceof YandexStatusMapping ) {
			return $this->status_mapping->universal_status_for( $status );
		}

		return DeliveryStatus::UNKNOWN;
	}

	private function yandex_status_description( string $status ): string {
		if ( $this->status_mapping instanceof YandexStatusMapping ) {
			return $this->status_mapping->description_for( $status );
		}

		return '';
	}

	/** @param array<string,mixed> $shipment */
	private function note_unknown_status( object $order, array $shipment ): void {
		$code = strtoupper( trim( (string) ( $shipment['yandex_status'] ?? '' ) ) );
		if ( '' === $code || DeliveryStatus::UNKNOWN !== (string) ( $shipment['universal_status_code'] ?? '' ) ) {
			return;
		}
		if ( $this->status_mapping instanceof YandexStatusMapping && $this->status_mapping->known( $code ) ) {
			return;
		}
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( 'Яндекс вернул неизвестный статус: ' . $code . '.' );
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

	private function delivery_type_from_info( YandexDeliveryRequestInfo $info ): string {
		$type = (string) ( $info->destination['type'] ?? '' );
		if ( 'custom_location' === $type ) {
			return 'courier';
		}

		return 'pickup';
	}

	/** @return array<int,array<string,mixed>> */
	private function generic_places_from_info( YandexDeliveryRequestInfo $info ): array {
		$places = array();
		foreach ( array_values( $info->places ) as $index => $place ) {
			if ( ! is_array( $place ) ) {
				continue;
			}
			$dims = is_array( $place['physical_dims'] ?? null ) ? $place['physical_dims'] : array();
			$places[] = array(
				'place_number' => $index + 1,
				'weight_g' => (int) ( $dims['weight_gross'] ?? 0 ),
				'length_cm' => (int) ( $dims['dx'] ?? 0 ),
				'width_cm' => (int) ( $dims['dy'] ?? 0 ),
				'height_cm' => (int) ( $dims['dz'] ?? 0 ),
				'barcode' => (string) ( $place['barcode'] ?? '' ),
			);
		}

		return $places;
	}
}
