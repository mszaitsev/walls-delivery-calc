<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class CdekOrderStatusService {
	public function __construct(
		private OrderShipmentRepository $repository,
		private CdekApiClient $client,
		private ?Logger $logger = null
	) {
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
		$status = (string) ( $shipment['status'] ?? 'registration_pending' );
		$message = 'Статус регистрации СДЭК обновлен.';
		if ( 'INVALID' === $request_state ) {
			$status = 'failed';
			$message = $this->errors_message( $request_row ) ?: 'Регистрация СДЭК завершилась ошибкой.';
		} elseif ( 'CREATED' === $status_code || 'SUCCESSFUL' === $request_state ) {
			$status = 'registered';
			$message = 'Регистрация СДЭК завершена успешно.';
		} elseif ( in_array( $request_state, array( 'ACCEPTED', 'PROCESSING' ), true ) || 'ACCEPTED' === $status_code ) {
			$status = 'registration_pending';
			$message = 'СДЭК еще обрабатывает регистрацию заказа.';
		}

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
				'cdek_planned_delivery_date' => $this->planned_delivery_date( $entity ),
				'cdek_actual_cost_kopecks' => $this->delivery_total_kopecks( $entity ),
				'response_snapshot' => $this->sanitize_response_snapshot( $body ),
				'updated_at' => $now,
				'tracking_checked_at' => $now,
			)
		);
		$this->repository->save_for_carrier( $order, CdekSettings::CARRIER_KEY, $updated );
		$this->log( 'info', 'CDEK order status update result.', array( 'status' => $status, 'request_state' => $request_state, 'order_status' => $status_code ) );

		return array(
			'success' => true,
			'message' => $message,
			'status' => $this->status_payload( $updated, $order ),
			'terminal' => in_array( $status, array( 'registered', 'created', 'failed' ), true ),
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

	private function shipment_status_label( string $status ): string {
		return match ( $status ) {
			'registration_pending' => 'регистрация',
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
		$status = 'registration_pending';
		if ( 'CREATED' === $status_code || 'SUCCESSFUL' === $request_state ) {
			$status = 'registered';
		} elseif ( 'INVALID' === $request_state ) {
			$status = 'failed';
		}
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
				'cdek_planned_delivery_date' => $this->planned_delivery_date( $entity ),
				'cdek_actual_cost_kopecks' => $this->delivery_total_kopecks( $entity ),
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
		$actual_kopecks = $this->positive_int_or_null( $shipment['cdek_actual_cost_kopecks'] ?? null );
		if ( null === $actual_kopecks ) {
			return array(
				'actual_cost_kopecks' => null,
				'actual_cost_label' => '',
				'actual_cost_compare_status' => '',
				'actual_cost_compare_message' => '',
				'base_api_cost_kopecks' => null,
			);
		}
		$base_kopecks = null !== $order ? $this->base_api_cost_kopecks( $order ) : null;
		$status = 'neutral';
		$message = 'нет базовой стоимости для сравнения';
		if ( null !== $base_kopecks ) {
			$threshold = (int) floor( $base_kopecks * 1.03 );
			$status = $actual_kopecks <= $threshold ? 'ok' : 'warning';
			$message = 'Базовая стоимость API: ' . $this->format_rubles( $base_kopecks ) . ' руб.';
		}

		return array(
			'actual_cost_kopecks' => $actual_kopecks,
			'actual_cost_label' => $this->format_rubles( $actual_kopecks ) . ' руб.',
			'actual_cost_compare_status' => $status,
			'actual_cost_compare_message' => $message,
			'base_api_cost_kopecks' => $base_kopecks,
		);
	}

	private function base_api_cost_kopecks( object $order ): ?int {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return null;
		}
		$value = $order->get_meta( OrderShippingMetaPersister::CALCULATION_META_KEY, true );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );
			$value = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $value ) ) {
			return null;
		}
		$api = is_array( $value['api'] ?? null ) ? $value['api'] : array();
		foreach ( array( 'api_base_price_kopecks', 'api_base_cost_kopecks', 'base_api_cost_kopecks' ) as $key ) {
			$kopecks = $this->positive_int_or_null( $api[ $key ] ?? $value[ $key ] ?? null );
			if ( null !== $kopecks ) {
				return $kopecks;
			}
		}
		foreach ( array( 'api_base_price_rub', 'api_price_with_vat_rub', 'base_api_cost_rub' ) as $key ) {
			$rubles = $this->numeric_or_null( $api[ $key ] ?? $value[ $key ] ?? null );
			if ( null !== $rubles && $rubles > 0 ) {
				return (int) round( $rubles * 100 );
			}
		}

		return null;
	}

	private function positive_int_or_null( mixed $value ): ?int {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$integer = (int) $value;

		return $integer > 0 ? $integer : null;
	}

	private function numeric_or_null( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	private function format_rubles( int $kopecks ): string {
		return number_format( $kopecks / 100, 2, '.', '' );
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

		return match ( $status ) {
			'registered' => 'Создан',
			'failed' => 'Ошибка регистрации',
			default => '' !== $request_state ? $request_state : 'Регистрация',
		};
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
