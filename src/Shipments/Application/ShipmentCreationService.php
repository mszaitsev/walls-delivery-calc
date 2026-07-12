<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Contracts\ShipmentCarrierAdapterInterface;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentCreationService {
	/**
	 * @param array<int,ShipmentCarrierAdapterInterface> $adapters
	 */
	public function __construct(
		private OrderShipmentRepository $repository,
		private array $adapters,
		private ?Logger $logger = null,
		private ?RussianPostShipmentActualCostLookupService $actual_cost_lookup = null,
		private ?CarrierShipmentAdapterRegistry $registry = null
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function safe_preview( ShipmentCreateRequest $request ): array {
		$adapter = $this->adapter_for( $request );

		if ( ! $adapter instanceof ShipmentCarrierAdapterInterface ) {
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

		if ( $this->repository->has_created_for_carrier( $order, $request->carrier_key ) ) {
			$existing = $this->repository->find_by_carrier( $order, $request->carrier_key );
			return new ShipmentCreateResult(
				false,
				error_code: 'shipment_already_created',
				error_message: DpdSettings::CARRIER_KEY === $request->carrier_key ? 'DPD отправление уже создано для этого заказа.' : 'По заказу уже создано отправление: ' . (string) ( $existing['tracking_number'] ?? $existing['barcode'] ?? '' ),
				raw_reference: array( 'existing' => $existing )
			);
		}
		$adapter = $this->adapter_for( $request );
		if ( ! $adapter instanceof ShipmentCarrierAdapterInterface ) {
			return new ShipmentCreateResult( false, error_code: 'unsupported_carrier', error_message: 'Для выбранной службы нет адаптера создания отправлений.' );
		}

		$preview = $this->safe_preview( $request );
		$result = $adapter->create( $request );
		$now = $this->now();
		if ( ! $result->success ) {
			if ( $this->is_yandex_reconciliation_result( $request, $result ) ) {
				$this->save_yandex_reconciliation_shipment( $order, $request, $result, $preview, $now );
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

		$raw = $result->raw_reference;
		$backlog_order_id = trim( $result->backlog_order_id );
		$is_cdek = CdekSettings::CARRIER_KEY === $request->carrier_key;
		$is_dpd = DpdSettings::CARRIER_KEY === $request->carrier_key;
		$is_yandex = YandexDeliverySettings::CARRIER_KEY === $request->carrier_key;
		$yandex = is_array( $raw['yandex'] ?? null ) ? $raw['yandex'] : array();
		$request_snapshot = $is_yandex
			? array( 'method' => 'POST', 'path' => '/api/b2b/platform/offers/create?send_unix=false', 'body' => array(), 'errors' => array(), 'note' => 'Canonical Yandex shipment state is request/info; offers/create payload is not persisted.' )
			: ( ( $is_cdek || $is_dpd ) && is_array( $raw['request'] ?? null )
			? ( $is_dpd ? $raw['request'] : array( 'method' => 'POST', 'path' => '/v2/orders', 'body' => $raw['request'], 'errors' => array() ) )
			: $preview );
		$response_snapshot = $is_yandex && is_array( $yandex['yandex_request_info_snapshot'] ?? null ) ? $yandex['yandex_request_info_snapshot'] : ( ( $is_cdek || $is_dpd ) && is_array( $raw['response'] ?? null ) ? $raw : $raw );
		$shipment = array(
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
			'cdek_number' => (string) ( $raw['cdek_number'] ?? $result->tracking_number ),
			'cdek_request_uuid' => $result->backlog_order_id,
			'cdek_request_state' => (string) ( $raw['registration_state'] ?? '' ),
			'cdek_order_status_code' => (string) ( $raw['order_status'] ?? '' ),
			'cdek_order_status_name' => (string) ( $raw['order_status_name'] ?? '' ),
			'cdek_planned_delivery_date' => (string) ( $raw['planned_delivery_date'] ?? '' ),
			'cdek_actual_cost_kopecks' => is_numeric( $raw['actual_cost_kopecks'] ?? null ) ? (int) $raw['actual_cost_kopecks'] : null,
			'dpd_order_number' => (string) ( $raw['dpd_order_number'] ?? '' ),
			'dpd_request_number' => (string) ( $raw['dpd_request_number'] ?? '' ),
			'dpd_parcel_numbers' => is_array( $raw['dpd_parcel_numbers'] ?? null ) ? $raw['dpd_parcel_numbers'] : array(),
			'dpd_status' => (string) ( $raw['dpd_status'] ?? '' ),
			'dpd_pickup_date' => (string) ( $raw['dpd_pickup_date'] ?? '' ),
			'dpd_date_flag' => (string) ( $raw['dpd_date_flag'] ?? '' ),
			'dpd_service_code' => (string) ( $request->meta['service_code'] ?? '' ),
			'dpd_sender_terminal_code' => (string) ( $request->meta['pickup_terminal_code'] ?? '' ),
			'dpd_receiver_terminal_code' => (string) ( $request->meta['delivery_terminal_code'] ?? '' ),
			'dpd_date_pickup' => (string) ( $request->meta['date_pickup'] ?? '' ),
			'dpd_cargo_value' => (float) ( $request->meta['declared_value_rub'] ?? 0 ),
			'created_by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'created_by_context' => 'admin_manual',
			'order_num' => (string) ( $request->meta['order_num'] ?? $request->order_id ),
			'group_name' => (string) ( $raw['group_name'] ?? '' ),
			'status' => $is_dpd ? 'pending_creation_in_carrier' : ( $is_cdek ? 'registration_pending' : 'created' ),
			'universal_status_code' => $is_dpd ? 'pending_creation_in_carrier' : '',
			'status_title' => $is_yandex ? 'Отправление Яндекс создано: ' . (string) ( $yandex['yandex_status'] ?? '' ) : ( $is_dpd ? 'Заявка DPD создана' : ( $is_cdek ? 'Заявка на регистрацию принята' : '' ) ),
			'created_at' => $now,
			'updated_at' => $now,
		);
		if ( $is_yandex ) {
			$shipment = array_merge( $shipment, $yandex );
			foreach ( array( 'cdek_number', 'cdek_request_uuid', 'cdek_request_state', 'cdek_order_status_code', 'cdek_order_status_name', 'cdek_planned_delivery_date', 'cdek_actual_cost_kopecks', 'dpd_order_number', 'dpd_request_number', 'dpd_parcel_numbers', 'dpd_status', 'dpd_pickup_date', 'dpd_date_flag', 'dpd_service_code', 'dpd_sender_terminal_code', 'dpd_receiver_terminal_code', 'dpd_date_pickup', 'dpd_cargo_value' ) as $foreign_key ) {
				unset( $shipment[ $foreign_key ] );
			}
		}
		if ( $is_dpd ) {
			foreach ( array( 'cdek_number', 'cdek_request_uuid', 'cdek_request_state', 'cdek_order_status_code', 'cdek_order_status_name', 'cdek_planned_delivery_date', 'cdek_actual_cost_kopecks' ) as $cdek_key ) {
				unset( $shipment[ $cdek_key ] );
			}
		}
		if ( ! $is_dpd && '' !== $backlog_order_id ) {
			$shipment['backlog_order_id'] = ctype_digit( $backlog_order_id ) ? (int) $backlog_order_id : $backlog_order_id;
		}
		$actual_cost = $this->actual_cost_after_create( $request->carrier_key, $result->tracking_number );
		if ( array() !== $actual_cost ) {
			$shipment = array_merge( $shipment, $actual_cost );
		}
		$this->repository->save_for_carrier( $order, $request->carrier_key, $shipment );
		if ( $is_yandex ) {
			$this->sync_yandex_lookup_meta( $order, $shipment );
		}
		if ( ! $is_cdek ) {
			$this->add_order_note( $order, $this->success_note( $request, $result, $raw ) );
		}

		return $result;
	}

	private function adapter_for( ShipmentCreateRequest $request ): ?ShipmentCarrierAdapterInterface {
		if ( $this->registry instanceof CarrierShipmentAdapterRegistry ) {
			$adapter = $this->registry->get( $request->carrier_key );
			if ( $adapter instanceof ShipmentCarrierAdapterInterface && $adapter->supports( $request ) ) {
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
	private function actual_cost_after_create( string $carrier_key, string $barcode ): array {
		if ( RussianPostDomesticSettings::CARRIER_KEY !== $carrier_key || '' === trim( $barcode ) ) {
			return array();
		}
		if ( ! $this->actual_cost_lookup instanceof RussianPostShipmentActualCostLookupService ) {
			return array();
		}

		try {
			$result = $this->actual_cost_lookup->lookup_after_create( $barcode );
		} catch ( \Throwable ) {
			return array( 'russian_post_actual_cost_lookup_error' => 'exception' );
		}

		$fields = is_array( $result['fields'] ?? null ) ? $result['fields'] : array();
		if ( array() !== $fields ) {
			return $fields;
		}
		$error_code = trim( (string) ( $result['error_code'] ?? '' ) );

		return '' !== $error_code ? array( 'russian_post_actual_cost_lookup_error' => $error_code ) : array();
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function is_yandex_reconciliation_result( ShipmentCreateRequest $request, ShipmentCreateResult $result ): bool {
		if ( YandexDeliverySettings::CARRIER_KEY !== $request->carrier_key || 'request_info_after_confirm_failed' !== $result->error_code ) {
			return false;
		}
		$reconciliation = is_array( $result->raw_reference['yandex_reconciliation'] ?? null ) ? $result->raw_reference['yandex_reconciliation'] : array();

		return '' !== trim( (string) ( $reconciliation['confirmed_request_id'] ?? '' ) );
	}

	/** @param array<string,mixed> $preview */
	private function save_yandex_reconciliation_shipment( object $order, ShipmentCreateRequest $request, ShipmentCreateResult $result, array $preview, string $now ): void {
		$reconciliation = is_array( $result->raw_reference['yandex_reconciliation'] ?? null ) ? $result->raw_reference['yandex_reconciliation'] : array();
		$request_id = trim( (string) ( $reconciliation['confirmed_request_id'] ?? '' ) );
		$shipment = array(
			'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
			'service_key' => (string) ( $request->meta['service_key'] ?? $request->rate_id ),
			'order_id' => $request->order_id,
			'service_title' => (string) ( $request->meta['service_title'] ?? '' ),
			'delivery_type' => $request->delivery_type,
			'places' => array_map( static fn ( $place ): array => $place->to_array(), $request->places ),
			'request_snapshot' => array( 'method' => 'POST', 'path' => '/api/b2b/platform/offers/create?send_unix=false', 'body' => array(), 'errors' => array(), 'note' => 'Canonical Yandex shipment state is request/info; offers/create payload is not persisted.' ),
			'response_snapshot' => $this->sanitize_yandex_diagnostics( $reconciliation ),
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
			'yandex_registration_error_details' => is_array( $reconciliation['api_error_details'] ?? null ) ? $this->sanitize_yandex_diagnostics( $reconciliation['api_error_details'] ) : array(),
			'created_by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'created_by_context' => 'admin_manual',
			'order_num' => (string) ( $request->meta['order_num'] ?? $request->order_id ),
			'created_at' => $now,
			'updated_at' => $now,
		);
		$this->repository->save_for_carrier( $order, YandexDeliverySettings::CARRIER_KEY, $shipment );
		$this->sync_yandex_lookup_meta( $order, $shipment );
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
		$this->add_order_note( $order, sprintf( 'Отправление Яндекс подтверждено, но требуется восстановить данные по request_id: %s.', $request_id ) );
	}

	/**
	 * @param array<string,mixed> $diagnostics
	 * @return array<string,mixed>
	 */
	private function sanitize_yandex_diagnostics( array $diagnostics ): array {
		$sanitized = $diagnostics;
		unset( $sanitized['Authorization'], $sanitized['authorization'], $sanitized['token'], $sanitized['bearer_token'] );

		return $sanitized;
	}

	/** @param array<string,mixed> $shipment */
	private function sync_yandex_lookup_meta( object $order, array $shipment ): void {
		$request_id = trim( (string) ( $shipment['yandex_request_id'] ?? $shipment['request_id'] ?? $shipment['external_id'] ?? '' ) );
		if ( '' !== $request_id && method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'save' ) ) {
			$order->update_meta_data( '_wdc_yandex_delivery_request_id', $request_id );
			$order->save();
		}
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function success_note( ShipmentCreateRequest $request, ShipmentCreateResult $result, array $raw ): string {
		if ( DpdSettings::CARRIER_KEY === $request->carrier_key ) {
			return sprintf(
				'DPD отправление создано вручную. Номер: %s. Мест: %d',
				$result->tracking_number,
				count( $request->places )
			);
		}
		if ( YandexDeliverySettings::CARRIER_KEY === $request->carrier_key ) {
			return sprintf(
				'Создано отправление Яндекс. Request ID: %s. Мест: %d',
				$result->external_id,
				count( $request->places )
			);
		}

		return sprintf(
			'Отправление Почты России создано. Barcode: %s. Мест: %d%s',
			$result->tracking_number,
			count( $request->places ),
			'' !== (string) ( $raw['group_name'] ?? '' ) ? '. ММО group-name: ' . (string) $raw['group_name'] : ''
		);
	}
}
