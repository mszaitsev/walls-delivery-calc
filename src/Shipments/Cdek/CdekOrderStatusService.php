<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Presentation\ShipmentActualCostComparisonService;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class CdekOrderStatusService {
	public function __construct(
		private OrderShipmentRepository $repository,
		private CdekApiClient $client,
		private Logger|null $logger = null,
		private CdekStatusMappingService|null $status_mapping = null,
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
	public function update( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, CdekSettings::CARRIER_KEY );
		if ( array() === $shipment ) {
			return array( 'success' => false, 'message' => 'Отправление СДЭК не найдено.' );
		}

		try {
			$response = $this->fetch_order( $shipment );
		} catch ( CdekApiException $exception ) {
			$this->log( 'error', 'CDEK order status update failed.', $exception->details() );
			return array( 'success' => false, 'message' => $exception->getMessage() );
		}

		$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
		$entity = is_array( $body['entity'] ?? null ) ? $body['entity'] : array();
		$request_row = $this->latest_request( $body );
		$order_status = $this->latest_order_status( $entity );
		$request_state = strtoupper( (string) ( $request_row['state'] ?? '' ) );
		$status_code = strtoupper( (string) ( $order_status['code'] ?? '' ) );
		$status = $this->internal_status_from_cdek_order_status( $status_code, $request_state );
		$universal_status = $this->universal_status_for( $status_code, $status );
		$message = $this->status_update_message( $status, $status_code, $request_state, $request_row );

		$now = $this->now();
		$updated = array_merge(
			$shipment,
			array(
				'status' => $status,
				'status_title' => $this->status_title( $status, $order_status, $request_state ),
				'external_id' => (string) ( $entity['uuid'] ?? $shipment['external_id'] ?? '' ),
				'cdek_number' => (string) ( $entity['cdek_number'] ?? $shipment['cdek_number'] ?? $shipment['tracking_number'] ?? '' ),
				'tracking_number' => (string) ( $entity['cdek_number'] ?? $shipment['tracking_number'] ?? '' ),
				'barcode' => (string) ( $entity['cdek_number'] ?? $shipment['barcode'] ?? '' ),
				'cdek_request_state' => $request_state,
				'cdek_order_status_code' => $status_code,
				'cdek_order_status_name' => (string) ( $order_status['name'] ?? '' ),
				'universal_status_code' => $universal_status,
				'universal_status_label' => '' !== $universal_status ? DeliveryStatus::label( $universal_status ) : '',
				'cdek_planned_delivery_date' => $this->planned_delivery_date( $entity ),
				'actual_cost_kopecks' => $this->delivery_total_kopecks( $entity ),
				'actual_cost_currency' => 'RUB',
				'actual_cost_source' => 'carrier_status',
				'actual_cost_source_detail' => 'cdek_order_status',
				'actual_cost_updated_at' => $now,
				'response_snapshot' => $this->sanitize_response_snapshot( $body ),
				'updated_at' => $now,
				'tracking_checked_at' => $now,
			)
		);
		$updated = $this->maybe_add_created_note( $order, $updated, $status_code );
		$this->repository->save_for_carrier( $order, CdekSettings::CARRIER_KEY, $updated );
		$this->log( 'info', 'CDEK order status update result.', array( 'status' => $status, 'request_state' => $request_state, 'order_status' => $status_code ) );

		return array(
			'success' => true,
			'message' => $message,
			'status' => $this->status_payload( $updated, $order ),
			'terminal' => in_array( $status, array( 'registered', 'created', 'failed', 'removed' ), true ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function attach_by_cdek_number( object $order, string $cdek_number ): array {
		$cdek_number = trim( $cdek_number );
		if ( '' === $cdek_number ) {
			return array( 'success' => false, 'message' => 'Введите номер СДЭК.' );
		}

		try {
			$response = $this->client->orderByCdekNumber( $cdek_number );
		} catch ( CdekApiException $exception ) {
			$this->log( 'error', 'CDEK manual attach lookup failed.', $exception->details() );
			return array( 'success' => false, 'message' => $exception->getMessage() );
		}

		$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
		$entity = is_array( $body['entity'] ?? null ) ? $body['entity'] : array();
		if ( array() === $entity ) {
			return array( 'success' => false, 'message' => 'Заказ СДЭК с таким номером не найден.' );
		}

		$shipment = $this->shipment_from_body( $body, array( 'cdek_number' => $cdek_number ) );
		$this->repository->save_for_carrier( $order, CdekSettings::CARRIER_KEY, $shipment );
		$this->log( 'info', 'CDEK shipment manually attached.', array( 'cdek_number' => $shipment['cdek_number'] ?? '', 'entity_uuid' => $shipment['external_id'] ?? '', 'order_status' => $shipment['cdek_order_status_code'] ?? '' ) );

		return array(
			'success' => true,
			'message' => 'Номер СДЭК сохранен.',
			'tracking_number' => (string) ( $shipment['tracking_number'] ?? '' ),
			'status' => $this->status_payload( $shipment, $order ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function cancel_created_order( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, CdekSettings::CARRIER_KEY );
		if ( array() === $shipment ) {
			return array( 'success' => false, 'message' => 'Отправление СДЭК не найдено.' );
		}
		if ( ! $this->can_cancel_in_cdek( $shipment ) ) {
			return array( 'success' => false, 'message' => 'Отменить заказ в СДЭК можно только в статусе CREATED / Создан.' );
		}
		$uuid = trim( (string) ( $shipment['external_id'] ?? $shipment['entity_uuid'] ?? '' ) );
		if ( '' === $uuid ) {
			return array( 'success' => false, 'message' => 'Не найден UUID заказа СДЭК для удаления.' );
		}

		try {
			$response = $this->client->deleteOrder( $uuid );
		} catch ( CdekApiException $exception ) {
			$this->log( 'error', 'CDEK order delete failed.', $exception->details() );
			return array( 'success' => false, 'message' => $exception->getMessage() );
		}

		$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
		$request_row = $this->latest_request( $body );
		if ( 'INVALID' === strtoupper( (string) ( $request_row['state'] ?? '' ) ) ) {
			return array( 'success' => false, 'message' => $this->errors_message( $request_row ) ?: 'СДЭК не удалил заказ.' );
		}

		$this->add_cancelled_note( $order, $shipment );
		$this->repository->delete_for_carrier( $order, CdekSettings::CARRIER_KEY );
		$this->log( 'info', 'CDEK order delete accepted.', array( 'entity_uuid' => $uuid, 'request_uuid' => (string) ( $request_row['request_uuid'] ?? '' ), 'request_state' => (string) ( $request_row['state'] ?? '' ) ) );

		return array(
			'success' => true,
			'message' => 'Отправление СДЭК отменено.',
			'status' => $this->status_payload( array() ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function remove_local_if_allowed( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, CdekSettings::CARRIER_KEY );
		if ( array() === $shipment ) {
			return array( 'success' => false, 'message' => 'Отправление СДЭК не найдено.' );
		}
		if ( ! $this->can_remove_from_order( $shipment ) ) {
			return array( 'success' => false, 'message' => 'Локальное удаление СДЭК-отправления запрещено для текущего статуса.' );
		}

		$this->repository->delete_for_carrier( $order, CdekSettings::CARRIER_KEY );

		return array(
			'success' => true,
			'message' => 'Данные СДЭК-отправления удалены из заказа.',
			'status' => $this->status_payload( array() ),
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	public function status_payload( array $shipment, ?object $order = null ): array {
		$order_status_code = strtoupper( (string) ( $shipment['cdek_order_status_code'] ?? '' ) );
		$order_status_label = (string) ( $shipment['cdek_order_status_name'] ?? '' );
		$number = (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? $shipment['cdek_number'] ?? '' );

		return array_merge(
			array(
				'carrier_key' => CdekSettings::CARRIER_KEY,
				'has_shipment' => array() !== $shipment,
				'universal_status_code' => (string) ( $shipment['universal_status_code'] ?? '' ),
				'universal_status_label' => (string) ( $shipment['universal_status_label'] ?? '' ),
				'shipment_status_label' => $this->shipment_status_label( (string) ( $shipment['status'] ?? '' ) ),
				'carrier_status_title' => (string) ( $shipment['status_title'] ?? '' ),
				'carrier_operation_date' => (string) ( $shipment['tracking_checked_at'] ?? '' ),
				'carrier_operation_address' => $order_status_code,
				'carrier_operation_index' => (string) ( $shipment['cdek_request_state'] ?? '' ),
				'tracking_checked_at' => (string) ( $shipment['tracking_checked_at'] ?? '' ),
				'barcode' => $number,
				'cdek_number' => $number,
				'uuid' => (string) ( $shipment['external_id'] ?? $shipment['entity_uuid'] ?? '' ),
				'order_status_code' => $order_status_code,
				'order_status_label' => $order_status_label,
				'cdek_planned_delivery_date' => (string) ( $shipment['cdek_planned_delivery_date'] ?? '' ),
				'can_cancel' => $this->can_cancel_in_cdek( $shipment ),
				'can_cancel_in_cdek' => $this->can_cancel_in_cdek( $shipment ),
				'can_remove_from_order' => $this->can_remove_from_order( $shipment ),
				'can_update_status' => array() !== $shipment,
				'can_print_barcode' => $this->can_print_barcode( $shipment ),
			),
			$this->actual_cost_payload( $shipment, $order )
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	public function can_cancel_in_cdek( array $shipment ): bool {
		return 'CREATED' === strtoupper( (string) ( $shipment['cdek_order_status_code'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	public function can_remove_from_order( array $shipment ): bool {
		$status = strtoupper( (string) ( $shipment['cdek_order_status_code'] ?? '' ) );

		return '' !== $status && ! in_array( $status, array( 'ACCEPTED', 'CREATED' ), true );
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function can_print_barcode( array $shipment ): bool {
		if ( array() === $shipment ) {
			return false;
		}
		$internal_status = (string) ( $shipment['status'] ?? '' );
		$order_status = strtoupper( (string) ( $shipment['cdek_order_status_code'] ?? '' ) );
		if ( in_array( $internal_status, array( 'registration_pending', 'failed', 'removed' ), true ) || in_array( $order_status, array( 'ACCEPTED', 'INVALID', 'REMOVED' ), true ) ) {
			return false;
		}
		$number = trim( (string) ( $shipment['cdek_number'] ?? $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
		$uuid = trim( (string) ( $shipment['external_id'] ?? $shipment['entity_uuid'] ?? '' ) );

		return '' !== $number || '' !== $uuid;
	}

	private function shipment_status_label( string $status ): string {
		return match ( $status ) {
			'registration_pending' => 'регистрация',
			'created' => 'создано',
			'registered' => 'зарегистрировано',
			'failed' => 'ошибка',
			'removed' => 'удалено',
			'', 'draft' => 'не создано',
			default => 'не определено',
		};
	}

	private function internal_status_from_cdek_order_status( string $order_status_code, string $request_state ): string {
		$order_status_code = strtoupper( trim( $order_status_code ) );
		$request_state = strtoupper( trim( $request_state ) );
		if ( '' !== $order_status_code ) {
			return match ( $order_status_code ) {
				'ACCEPTED' => 'registration_pending',
				'CREATED' => 'registered',
				'INVALID' => 'failed',
				'REMOVED' => 'removed',
				default => 'registered',
			};
		}

		return 'INVALID' === $request_state ? 'failed' : 'registration_pending';
	}

	private function universal_status_for( string $order_status_code, string $internal_status ): string {
		$order_status_code = strtoupper( trim( $order_status_code ) );
		if ( '' !== $order_status_code && $this->status_mapping instanceof CdekStatusMappingService ) {
			return $this->status_mapping->universal_status_for( $order_status_code );
		}
		if ( '' !== $order_status_code ) {
			$mapping = CdekStatusMappingService::default_mapping();
			return (string) ( $mapping[ $order_status_code ] ?? DeliveryStatus::UNKNOWN );
		}

		return match ( $internal_status ) {
			'failed' => DeliveryStatus::REJECTED,
			'removed' => DeliveryStatus::CANCELLED,
			'registered', 'created', 'registration_pending' => DeliveryStatus::CREATED_IN_CARRIER,
			default => DeliveryStatus::UNKNOWN,
		};
	}

	/**
	 * @param array<string,mixed> $request_row
	 */
	private function status_update_message( string $status, string $order_status_code, string $request_state, array $request_row ): string {
		$order_status_code = strtoupper( trim( $order_status_code ) );
		$request_state = strtoupper( trim( $request_state ) );

		return match ( $status ) {
			'registration_pending' => 'СДЭК еще обрабатывает регистрацию заказа.',
			'registered' => 'CREATED' === $order_status_code ? 'Отправление СДЭК создано.' : 'Статус СДЭК обновлен.',
			'failed' => 'INVALID' === $order_status_code ? 'Заказ СДЭК некорректен.' : ( 'INVALID' === $request_state ? ( $this->errors_message( $request_row ) ?: 'Регистрация СДЭК завершилась ошибкой.' ) : 'Регистрация СДЭК завершилась ошибкой.' ),
			'removed' => 'Заказ СДЭК удален.',
			default => 'Статус СДЭК обновлен.',
		};
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	private function maybe_add_created_note( object $order, array $shipment, string $order_status_code ): array {
		if ( 'CREATED' !== strtoupper( trim( $order_status_code ) ) ) {
			return $shipment;
		}
		if ( ! empty( $shipment['cdek_created_note_added'] ) ) {
			return $shipment;
		}

		$this->add_order_note(
			$order,
			sprintf(
				'Зарегистрировано отправление СДЭК %s. Мест: %d.',
				$this->shipment_barcode( $shipment ),
				$this->places_count( $shipment )
			)
		);
		$shipment['cdek_created_note_added'] = true;

		return $shipment;
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function add_cancelled_note( object $order, array $shipment ): void {
		$this->add_order_note(
			$order,
			sprintf(
				'Отменено отправление СДЭК %s. Мест: %d.',
				$this->shipment_barcode( $shipment ),
				$this->places_count( $shipment )
			)
		);
	}

	private function add_order_note( object $order, string $message ): void {
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $message );
		}
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function shipment_barcode( array $shipment ): string {
		$barcode = trim( (string) ( $shipment['cdek_number'] ?? $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );

		return '' !== $barcode ? $barcode : '-';
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function places_count( array $shipment ): int {
		$places = is_array( $shipment['places'] ?? null ) ? $shipment['places'] : array();
		if ( array() !== $places ) {
			return max( 1, count( $places ) );
		}
		$request_snapshot = is_array( $shipment['request_snapshot'] ?? null ) ? $shipment['request_snapshot'] : array();
		$request_body = is_array( $request_snapshot['body'] ?? null ) ? $request_snapshot['body'] : $request_snapshot;
		$packages = is_array( $request_body['packages'] ?? null ) ? $request_body['packages'] : array();
		if ( array() !== $packages ) {
			return max( 1, count( $packages ) );
		}

		return 1;
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	private function fetch_order( array $shipment ): array {
		$uuid = trim( (string) ( $shipment['external_id'] ?? $shipment['entity_uuid'] ?? '' ) );
		if ( '' !== $uuid ) {
			return $this->client->orderByUuid( $uuid );
		}
		$cdek_number = trim( (string) ( $shipment['cdek_number'] ?? $shipment['tracking_number'] ?? '' ) );
		if ( '' !== $cdek_number ) {
			return $this->client->orderByNumber( array( 'cdek_number' => $cdek_number ) );
		}
		$order_num = trim( (string) ( $shipment['order_num'] ?? '' ) );
		if ( '' !== $order_num ) {
			return $this->client->orderByNumber( array( 'im_number' => $order_num ) );
		}

		throw new CdekApiException( 'Не найден UUID или номер заказа СДЭК для обновления статуса.' );
	}

	/**
	 * @param array<string,mixed> $body
	 * @param array<string,mixed> $base
	 * @return array<string,mixed>
	 */
	private function shipment_from_body( array $body, array $base = array() ): array {
		$entity = is_array( $body['entity'] ?? null ) ? $body['entity'] : array();
		$request_row = $this->latest_request( $body );
		$order_status = $this->latest_order_status( $entity );
		$request_state = strtoupper( (string) ( $request_row['state'] ?? '' ) );
		$status_code = strtoupper( (string) ( $order_status['code'] ?? '' ) );
		$cdek_number = (string) ( $entity['cdek_number'] ?? $base['cdek_number'] ?? '' );
		$status = $this->internal_status_from_cdek_order_status( $status_code, $request_state );
		$universal_status = $this->universal_status_for( $status_code, $status );
		$now = $this->now();

		return array_merge(
			$base,
			array(
				'carrier_key' => CdekSettings::CARRIER_KEY,
				'service_key' => CdekSettings::SERVICE_KEY,
				'status' => $status,
				'status_title' => $this->status_title( $status, $order_status, $request_state ),
				'external_id' => (string) ( $entity['uuid'] ?? '' ),
				'cdek_number' => $cdek_number,
				'tracking_number' => $cdek_number,
				'barcode' => $cdek_number,
				'cdek_request_uuid' => (string) ( $request_row['request_uuid'] ?? '' ),
				'cdek_request_state' => $request_state,
				'cdek_order_status_code' => $status_code,
				'cdek_order_status_name' => (string) ( $order_status['name'] ?? '' ),
				'universal_status_code' => $universal_status,
				'universal_status_label' => '' !== $universal_status ? DeliveryStatus::label( $universal_status ) : '',
				'cdek_planned_delivery_date' => $this->planned_delivery_date( $entity ),
				'actual_cost_kopecks' => $this->delivery_total_kopecks( $entity ),
				'actual_cost_currency' => 'RUB',
				'actual_cost_source' => 'carrier_status',
				'actual_cost_source_detail' => 'cdek_order_status',
				'actual_cost_updated_at' => $now,
				'response_snapshot' => $this->sanitize_response_snapshot( $body ),
				'created_at' => $now,
				'updated_at' => $now,
				'tracking_checked_at' => $now,
			)
		);
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	private function latest_request( array $body ): array {
		$requests = is_array( $body['requests'] ?? null ) ? $body['requests'] : array();
		$last = end( $requests );

		return is_array( $last ) ? $last : array();
	}

	/**
	 * @param array<string,mixed> $entity
	 * @return array<string,mixed>
	 */
	private function latest_order_status( array $entity ): array {
		$statuses = is_array( $entity['statuses'] ?? null ) ? $entity['statuses'] : array();
		$active = array_values(
			array_filter(
				$statuses,
				static fn ( mixed $status ): bool => is_array( $status ) && ! in_array( $status['deleted'] ?? false, array( true, 1, '1', 'true', 'TRUE' ), true )
			)
		);
		if ( array() === $active ) {
			return array();
		}
		$latest = null;
		$latest_ts = null;
		foreach ( $active as $status ) {
			$date = trim( (string) ( $status['date_time'] ?? '' ) );
			$timestamp = '' !== $date ? strtotime( $date ) : false;
			if ( false === $timestamp ) {
				continue;
			}
			if ( null === $latest_ts || $timestamp > $latest_ts ) {
				$latest_ts = $timestamp;
				$latest = $status;
			}
		}
		if ( is_array( $latest ) ) {
			return $latest;
		}
		$last = end( $active );

		return is_array( $last ) ? $last : array();
	}

	/**
	 * @param array<string,mixed> $request_row
	 */
	private function errors_message( array $request_row ): string {
		$messages = array();
		foreach ( is_array( $request_row['errors'] ?? null ) ? $request_row['errors'] : array() as $error ) {
			if ( is_array( $error ) ) {
				$messages[] = trim( (string) ( $error['message'] ?? $error['code'] ?? '' ) );
			}
		}

		return implode( "\n", array_filter( $messages ) );
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	private function sanitize_response_snapshot( array $body ): array {
		$entity = is_array( $body['entity'] ?? null ) ? $body['entity'] : array();
		$request_row = $this->latest_request( $body );
		$order_status = $this->latest_order_status( $entity );

		return array(
			'entity_uuid' => (string) ( $entity['uuid'] ?? '' ),
			'cdek_number' => (string) ( $entity['cdek_number'] ?? '' ),
			'request_uuid' => (string) ( $request_row['request_uuid'] ?? '' ),
			'request_state' => (string) ( $request_row['state'] ?? '' ),
			'order_status' => (string) ( $order_status['code'] ?? '' ),
			'planned_delivery_date' => $this->planned_delivery_date( $entity ),
			'actual_cost_kopecks' => $this->delivery_total_kopecks( $entity ),
			'errors' => $this->safe_errors( $request_row ),
		);
	}

	/**
	 * @param array<string,mixed> $entity
	 */
	private function planned_delivery_date( array $entity ): string {
		$date = trim( (string) ( $entity['planned_delivery_date'] ?? '' ) );

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
	}

	/**
	 * @param array<string,mixed> $entity
	 */
	private function delivery_total_kopecks( array $entity ): ?int {
		$detail = is_array( $entity['delivery_detail'] ?? null ) ? $entity['delivery_detail'] : array();
		$total = $detail['total_sum'] ?? null;
		if ( ! is_numeric( $total ) ) {
			return null;
		}
		$kopecks = (int) round( (float) $total * 100 );

		return $kopecks > 0 ? $kopecks : null;
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

	/**
	 * @param array<string,mixed> $request_row
	 * @return array<int,array<string,string>>
	 */
	private function safe_errors( array $request_row ): array {
		$errors = array();
		foreach ( is_array( $request_row['errors'] ?? null ) ? $request_row['errors'] : array() as $error ) {
			if ( is_array( $error ) ) {
				$errors[] = array(
					'code' => (string) ( $error['code'] ?? '' ),
					'message' => (string) ( $error['message'] ?? '' ),
				);
			}
		}

		return $errors;
	}

	/**
	 * @param array<string,mixed> $order_status
	 */
	private function status_title( string $status, array $order_status, string $request_state ): string {
		$name = (string) ( $order_status['name'] ?? '' );
		if ( '' !== $name ) {
			return $name;
		}
		$code = strtoupper( (string) ( $order_status['code'] ?? '' ) );
		if ( '' !== $code ) {
			return $code;
		}

		return '' !== $request_state ? $request_state : 'Регистрация';
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function log( string $level, string $message, array $context ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}
		$method = method_exists( $this->logger, $level ) ? $level : 'debug';
		$this->logger->{$method}( $message, $context );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
