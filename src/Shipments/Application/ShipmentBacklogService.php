<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentBacklogService {
	public function __construct(
		private OrderShipmentRepository $repository,
		private RussianPostOtpravkaApiClient $otpravka_client,
		private ShipmentStatusUpdateService $status_updates,
		private ?RussianPostShipmentActualCostExtractor $actual_cost_extractor = null
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function cancel_russian_post( object $order, string $shipment_key = RussianPostDomesticSettings::CARRIER_KEY ): array {
		if ( RussianPostDomesticSettings::CARRIER_KEY !== $shipment_key ) {
			return $this->failure( 'Можно отменить только отправление Почты России.' );
		}

		$shipment = $this->repository->find_by_carrier( $order, $shipment_key );
		$validation = $this->cancel_validation_message( $shipment );
		if ( '' !== $validation ) {
			return $this->failure( $validation );
		}

		$backlog_order_id = (int) ( $shipment['backlog_order_id'] ?? 0 );
		$response = $this->otpravka_client->delete_backlog_orders( array( $backlog_order_id ) );
		if ( ! (bool) ( $response['success'] ?? false ) ) {
			$message = (string) ( $response['error_message'] ?? '' );
			return $this->failure( '' !== $message ? $message : 'Не удалось отменить отправление Почты России.', $response );
		}

		$this->repository->delete_for_carrier( $order, $shipment_key );
		$this->add_order_note( $order, 'Отправление Почты России отменено и удалено из backlog. ШПИ: ' . (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) . '.' );

		return array(
			'success' => true,
			'message' => 'Отправление отменено.',
			'status' => $this->status_updates->status_payload( array() ),
			'cancel_response' => array(
				'http_code' => (int) ( $response['http_code'] ?? 0 ),
				'result_ids' => is_array( $response['result_ids'] ?? null ) ? $response['result_ids'] : array(),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function remove_from_order( object $order, string $shipment_key = RussianPostDomesticSettings::CARRIER_KEY ): array {
		if ( RussianPostDomesticSettings::CARRIER_KEY !== $shipment_key ) {
			return $this->failure( 'Можно удалить только отправление Почты России.' );
		}

		$shipment = $this->repository->find_by_carrier( $order, $shipment_key );
		$barcode = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
		if ( array() === $shipment || '' === $barcode ) {
			return $this->failure( 'В заказе нет отправления для удаления.' );
		}

		$this->repository->delete_for_carrier( $order, $shipment_key );
		$this->add_order_note( $order, 'Данные отправления Почты России удалены из заказа без отмены в Почте России.' );

		return array(
			'success' => true,
			'message' => 'Данные отправления удалены из заказа.',
			'status' => $this->status_updates->status_payload( array() ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function attach_tracking_number( object $order, string $barcode, string $shipment_key = RussianPostDomesticSettings::CARRIER_KEY ): array {
		if ( RussianPostDomesticSettings::CARRIER_KEY !== $shipment_key ) {
			return $this->failure( 'Можно внести только номер отслеживания Почты России.' );
		}

		$barcode = $this->normalize_barcode( $barcode );
		if ( '' === $barcode ) {
			return $this->failure( 'Укажите номер отслеживания.' );
		}

		$existing = $this->repository->find_by_carrier( $order, $shipment_key );
		if ( $this->has_active_shipment( $existing ) ) {
			return $this->failure( 'По заказу уже создано отправление.' );
		}

		$lookup = $this->lookup_tracking_number( $barcode );
		if ( ! (bool) ( $lookup['success'] ?? false ) ) {
			return $this->failure( (string) $lookup['message'], is_array( $lookup['raw'] ?? null ) ? $lookup['raw'] : array() );
		}

		$selected = is_array( $lookup['selected'] ?? null ) ? $lookup['selected'] : array();
		$source_lookup = (string) ( $lookup['source_lookup'] ?? '' );
		$backlog_order_id = (int) ( $selected['id'] ?? $selected['result-id'] ?? $selected['result_id'] ?? 0 );

		$now = $this->now();
		$shipment = array(
			'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
			'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
			'order_id' => $this->order_id( $order ),
			'service_title' => 'Почта России',
			'barcode' => $barcode,
			'tracking_number' => $barcode,
			'status' => 'created',
			'source' => 'manual_tracking_attach',
			'source_lookup' => $source_lookup,
			'response_snapshot' => $this->safe_search_snapshot( $selected ),
			'created_at' => $now,
			'updated_at' => $now,
		);
		$actual_cost = 'backlog_search' === $source_lookup
			? $this->actual_cost_extractor()->fields_from_row( $selected, 'backlog_search' )
			: array();
		if ( array() !== $actual_cost ) {
			$shipment = array_merge( $shipment, $actual_cost );
		}
		if ( $backlog_order_id > 0 ) {
			$shipment['backlog_order_id'] = $backlog_order_id;
		}

		$this->repository->save_for_carrier( $order, $shipment_key, $shipment );
		$this->add_order_note( $order, 'Номер отслеживания Почты России внесен вручную: ' . $barcode . '.' );

		$status_update = $this->status_updates->update_russian_post( $order, $shipment_key );
		if ( ! (bool) ( $status_update['success'] ?? false ) ) {
			return array(
				'success' => true,
				'message' => 'Номер отслеживания сохранен.',
				'warning' => 'Номер отслеживания сохранен, но статус пока не обновлен: ' . (string) ( $status_update['message'] ?? 'не удалось получить статус.' ),
				'tracking_number' => $barcode,
				'backlog_order_id' => $backlog_order_id > 0 ? (string) $backlog_order_id : '',
				'status' => $this->status_updates->status_payload( $this->repository->find_by_carrier( $order, $shipment_key ), $order ),
			);
		}

		return array(
			'success' => true,
			'message' => 'Номер отслеживания сохранен, статус обновлен.',
			'tracking_number' => $barcode,
			'backlog_order_id' => $backlog_order_id > 0 ? (string) $backlog_order_id : '',
			'status' => is_array( $status_update['status'] ?? null ) ? $status_update['status'] : $this->status_updates->status_payload( $this->repository->find_by_carrier( $order, $shipment_key ), $order ),
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	public function can_cancel( array $shipment ): bool {
		return '' === $this->cancel_validation_message( $shipment );
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function cancel_validation_message( array $shipment ): string {
		$barcode = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
		$backlog_order_id = (int) ( $shipment['backlog_order_id'] ?? 0 );
		if ( array() === $shipment || '' === $barcode || $backlog_order_id <= 0 ) {
			return 'У отправления нет номера отслеживания или внутреннего ID Почты России.';
		}
		if ( ! in_array( (string) ( $shipment['status'] ?? '' ), array( 'created', 'registered' ), true ) ) {
			return 'Отправление уже не находится в созданном состоянии.';
		}
		if ( '28' !== (string) ( $shipment['carrier_operation_type_id'] ?? '' ) && 'Присвоение идентификатора' !== (string) ( $shipment['carrier_operation_type_name'] ?? '' ) ) {
			return 'Отменить можно только отправление в статусе Почты России «Присвоение идентификатора».';
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function has_active_shipment( array $shipment ): bool {
		$barcode = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );

		return '' !== $barcode && in_array( (string) ( $shipment['status'] ?? '' ), array( 'created', 'registered' ), true );
	}

	private function normalize_barcode( string $barcode ): string {
		return strtoupper( preg_replace( '/\s+/', '', trim( $barcode ) ) ?? '' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function lookup_tracking_number( string $barcode ): array {
		$backlog_search = $this->otpravka_client->search_backlog_by_barcode( $barcode );
		if ( ! (bool) ( $backlog_search['success'] ?? false ) ) {
			$message = (string) ( $backlog_search['error_message'] ?? '' );
			return array(
				'success' => false,
				'message' => '' !== $message ? $message : 'Не удалось найти отправление в Почте России.',
				'raw' => $backlog_search,
			);
		}

		$backlog_orders = is_array( $backlog_search['orders'] ?? null ) ? $backlog_search['orders'] : array();
		$backlog_selected = $this->actual_cost_extractor()->select_search_result( $backlog_orders, $barcode );
		if ( null !== $backlog_selected ) {
			return array(
				'success' => true,
				'selected' => $backlog_selected,
				'source_lookup' => 'backlog_search',
				'raw' => $backlog_search,
			);
		}
		if ( array() !== $backlog_orders ) {
			return array(
				'success' => false,
				'message' => 'Найдено несколько отправлений, уточните номер отслеживания.',
				'raw' => $backlog_search,
			);
		}

		$shipment_search = $this->otpravka_client->search_shipment_by_barcode( $barcode );
		if ( ! (bool) ( $shipment_search['success'] ?? false ) ) {
			$message = (string) ( $shipment_search['error_message'] ?? '' );
			return array(
				'success' => false,
				'message' => '' !== $message ? $message : 'Не удалось найти отправление в Почте России.',
				'raw' => $shipment_search,
			);
		}

		$shipment_orders = is_array( $shipment_search['orders'] ?? null ) ? $shipment_search['orders'] : array();
		$shipment_selected = $this->actual_cost_extractor()->select_search_result( $shipment_orders, $barcode );
		if ( null !== $shipment_selected ) {
			return array(
				'success' => true,
				'selected' => $shipment_selected,
				'source_lookup' => 'shipment_search',
				'raw' => $shipment_search,
			);
		}

		return array(
			'success' => false,
			'message' => array() === $shipment_orders ? 'Отправление с таким номером отслеживания не найдено в Почте России.' : 'Найдено несколько отправлений, уточните номер отслеживания.',
			'raw' => $shipment_search,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function safe_search_snapshot( array $row ): array {
		unset( $row['Authorization'], $row['X-User-Authorization'], $row['headers'], $row['raw_body'] );

		return $row;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function failure( string $message, array $raw = array() ): array {
		return array(
			'success' => false,
			'message' => $message,
			'raw' => $raw,
		);
	}

	private function add_order_note( object $order, string $message ): void {
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $message );
		}
	}

	private function actual_cost_extractor(): RussianPostShipmentActualCostExtractor {
		if ( ! $this->actual_cost_extractor instanceof RussianPostShipmentActualCostExtractor ) {
			$this->actual_cost_extractor = new RussianPostShipmentActualCostExtractor();
		}

		return $this->actual_cost_extractor;
	}

	private function order_id( object $order ): int {
		return method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
