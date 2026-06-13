<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiException;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
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
			'status' => $this->status_payload( $updated ),
			'terminal' => in_array( $status, array( 'registered', 'created', 'failed' ), true ),
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	public function status_payload( array $shipment ): array {
		return array(
			'carrier_key' => CdekSettings::CARRIER_KEY,
			'shipment_status_label' => $this->shipment_status_label( (string) ( $shipment['status'] ?? '' ) ),
			'carrier_status_title' => (string) ( $shipment['status_title'] ?? '' ),
			'carrier_operation_date' => (string) ( $shipment['tracking_checked_at'] ?? '' ),
			'carrier_operation_address' => (string) ( $shipment['cdek_order_status_code'] ?? '' ),
			'carrier_operation_index' => (string) ( $shipment['cdek_request_state'] ?? '' ),
			'tracking_checked_at' => (string) ( $shipment['tracking_checked_at'] ?? '' ),
			'barcode' => (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? $shipment['cdek_number'] ?? '' ),
			'can_cancel' => false,
		);
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
		$last = end( $statuses );

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
			'errors' => $this->safe_errors( $request_row ),
		);
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
