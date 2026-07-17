<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\RussianPost;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Application\ShipmentBacklogService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class RussianPostShipmentAdapter implements CarrierShipmentAdapterInterface {
	public function __construct(
		private RussianPostOtpravkaApiClient $client,
		private ?RussianPostCreateRequestBuilder $builder = null,
		private ?Logger $logger = null,
		private ?ShipmentStatusUpdateService $status_updates = null,
		private ?ShipmentBacklogService $backlog = null
	) {
		$this->builder ??= new RussianPostCreateRequestBuilder();
	}

	public function carrier_key(): string {
		return RussianPostDomesticSettings::CARRIER_KEY;
	}

	public function supports( ShipmentCreateRequest $request ): bool {
		return RussianPostDomesticSettings::CARRIER_KEY === $request->carrier_key;
	}

	/**
	 * @return array<string,string>
	 */
	public function presentation(): array {
		return array(
			'carrier_label' => 'Почта России',
			'status_title' => 'Статус Почты России',
			'tracking_label' => 'Отслеживание',
			'create_button_label' => 'Подготовить отправление',
			'manual_attach_button_label' => 'Внести отслеживание вручную',
			'cancel_button_label' => 'Отменить отправление',
			'remove_button_label' => 'Удалить из заказа',
			'update_status_button_label' => 'Обновить статус',
			'manual_attach_placeholder' => 'Номер отслеживания',
			'manual_attach_help' => 'Введите номер отслеживания для поиска и привязки отправления.',
			'created_toast' => 'Отправление создано.',
			'updated_toast' => 'Статус отправления обновлен.',
			'cancel_success_toast' => 'Отправление отменено.',
			'remove_success_toast' => 'Данные отправления удалены из заказа.',
			'error_fallback_message' => 'Не удалось получить статус отправления.',
			'polling_timeout_message' => 'Автоматическая проверка завершена. Если статус еще не обновился, воспользуйтесь кнопкой «Обновить статус».',
			'registration_error_toast' => 'Регистрация завершилась ошибкой.',
			'registration_success_toast' => 'Регистрация завершена успешно.',
			'auto_poll_registration' => '0',
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	public function status_payload( object $order, array $shipment ): array {
		$can_cancel = array() !== $shipment && $this->backlog instanceof ShipmentBacklogService && $this->backlog->can_cancel( $shipment );
		if ( $this->status_updates instanceof ShipmentStatusUpdateService ) {
			$status = $this->status_updates->status_payload( $shipment, $order );
			return array_merge(
				$status,
				array(
					'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
					'has_shipment' => array() !== $shipment,
					'can_update_status' => array() !== $shipment,
					'can_cancel' => $can_cancel,
					'can_remove_from_order' => array() !== $shipment && ! $can_cancel,
				)
			);
		}

		return array(
			'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
			'has_shipment' => array() !== $shipment,
			'barcode' => $this->tracking_identifier( $shipment ),
			'can_update_status' => array() !== $shipment,
			'can_cancel' => $can_cancel,
			'can_remove_from_order' => array() !== $shipment && ! $can_cancel,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function update_status( object $order, string $shipment_key = '' ): array {
		if ( ! $this->status_updates instanceof ShipmentStatusUpdateService ) {
			return array( 'success' => false, 'message' => 'Russian Post status service is unavailable.' );
		}

		return $this->status_updates->update_russian_post( $order, $shipment_key ?: RussianPostDomesticSettings::CARRIER_KEY );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function attach_manual( object $order, array $payload ): array {
		if ( ! $this->backlog instanceof ShipmentBacklogService ) {
			return array( 'success' => false, 'message' => 'Ручное внесение ШПИ недоступно.' );
		}

		return $this->backlog->attach_tracking_number( $order, (string) ( $payload['barcode'] ?? $payload['tracking_number'] ?? '' ), RussianPostDomesticSettings::CARRIER_KEY );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array {
		if ( ! $this->backlog instanceof ShipmentBacklogService ) {
			return array( 'success' => false, 'message' => 'Отмена отправлений недоступна.' );
		}

		return $this->backlog->cancel_russian_post( $order, $shipment_key ?: RussianPostDomesticSettings::CARRIER_KEY );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function remove_from_order( object $order, string $shipment_key = '' ): array {
		if ( ! $this->backlog instanceof ShipmentBacklogService ) {
			return array( 'success' => false, 'message' => 'Не удалось удалить данные отправления.' );
		}

		return $this->backlog->remove_from_order( $order, $shipment_key ?: RussianPostDomesticSettings::CARRIER_KEY );
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<int,array<string,mixed>>
	 */
	public function document_actions( object $order, array $shipment ): array {
		return array();
	}

	public function supports_status_auto_sync(): bool {
		return true;
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	public function tracking_identifier( array $shipment ): string {
		foreach ( array( 'tracking_number', 'barcode' ) as $key ) {
			$value = trim( (string) ( $shipment[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	public function auto_sync_throttle_microseconds(): int {
		return 0;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		return array(
			'method' => 'PUT',
			'path' => '/2.0/user/backlog',
			'body' => $this->builder->build( $request ),
		);
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		try {
			$orders = $this->builder->build( $request );
		} catch ( \Throwable $e ) {
			return new ShipmentCreateResult( false, error_code: 'validation_error', error_message: $e->getMessage() );
		}

		$order_nums = $this->payload_order_nums( $orders );
		$this->log_create_attempt( $request, $orders, $order_nums );
		$unexpected_order_nums = $this->unexpected_order_nums( $request, $order_nums );
		if ( array() !== $unexpected_order_nums ) {
			$this->log_blocked_order_nums( $request, $orders, $order_nums, $unexpected_order_nums );
			return new ShipmentCreateResult(
				false,
				error_code: 'shipment_order_num_mismatch',
				error_message: 'Payload содержит order-num другого заказа. Создание отправления заблокировано.'
			);
		}

		$response = $this->client->create_backlog_orders( $orders );
		$errors = is_array( $response['errors'] ?? null ) ? $response['errors'] : array();
		$normalized_errors = $this->normalized_errors( $errors );
		$this->log_result( $request, $response, array() === $errors && ! empty( $response['success'] ), $normalized_errors );
		if ( empty( $response['success'] ) || array() !== $errors ) {
			return new ShipmentCreateResult(
				false,
				error_code: (string) ( $response['error_code'] ?? 'russian_post_backlog_error' ),
				error_message: $this->error_message( $response, $errors, $normalized_errors ),
				raw_reference: $this->safe_response( $response )
			);
		}

		$orders_result = is_array( $response['orders'] ?? null ) ? array_values( $response['orders'] ) : array();
		$first = is_array( $orders_result[0] ?? null ) ? $orders_result[0] : array();
		$safe_orders_result = array_map( array( $this, 'safe_success_order_result' ), $orders_result );

		return new ShipmentCreateResult(
			true,
			external_id: (string) ( $first['result-id'] ?? $first['result_id'] ?? '' ),
			tracking_number: (string) ( $first['barcode'] ?? '' ),
			backlog_order_id: (string) ( $first['result-id'] ?? $first['result_id'] ?? '' ),
			raw_reference: array(
				'orders' => $safe_orders_result,
				'barcodes' => array_values( array_filter( array_map( static fn ( mixed $row ): string => is_array( $row ) ? (string) ( $row['barcode'] ?? '' ) : '', $orders_result ) ) ),
				'group_name' => (string) ( $first['group-name'] ?? $first['group_name'] ?? '' ),
				'http_code' => (int) ( $response['http_code'] ?? 0 ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function safe_success_order_result( mixed $row ): array {
		if ( ! is_array( $row ) ) {
			return array();
		}
		unset( $row['result-id'], $row['result_id'] );

		return $row;
	}

	/**
	 * @param array<string,mixed> $response
	 * @param array<int,mixed> $errors
	 */
	private function error_message( array $response, array $errors, array $normalized_errors = array() ): string {
		if ( array() !== $normalized_errors ) {
			return implode( '; ', array_map( static fn ( array $error ): string => trim( (string) ( $error['code'] ?? '' ) . ' ' . (string) ( $error['description'] ?? '' ) ), $normalized_errors ) );
		}
		if ( array() !== $errors ) {
			return implode( '; ', array_map( static fn ( mixed $error ): string => is_array( $error ) ? (string) ( $error['msg'] ?? $error['message'] ?? $error['description'] ?? ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $error ) : json_encode( $error ) ) ) : (string) $error, $errors ) );
		}

		return (string) ( $response['error_message'] ?? 'Не удалось создать отправление в Почте России.' );
	}

	/**
	 * @param array<int,mixed> $errors
	 * @return array<int,array{code:string,description:string}>
	 */
	private function normalized_errors( array $errors ): array {
		$result = array();
		foreach ( $errors as $error ) {
			if ( ! is_array( $error ) ) {
				$result[] = array( 'code' => '', 'description' => (string) $error );
				continue;
			}
			$error_codes = is_array( $error['error-codes'] ?? null ) ? $error['error-codes'] : ( is_array( $error['error_codes'] ?? null ) ? $error['error_codes'] : array() );
			foreach ( $error_codes as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$result[] = array(
					'code' => (string) ( $row['code'] ?? '' ),
					'description' => (string) ( $row['description'] ?? $row['message'] ?? '' ),
				);
			}
			if ( array() === $error_codes ) {
				$result[] = array(
					'code' => (string) ( $error['code'] ?? '' ),
					'description' => (string) ( $error['description'] ?? $error['message'] ?? $error['msg'] ?? '' ),
				);
			}
		}

		return array_values( array_filter( $result, static fn ( array $row ): bool => '' !== trim( $row['code'] . $row['description'] ) ) );
	}

	/**
	 * @param array<string,mixed> $response
	 * @param array<int,array{code:string,description:string}> $errors
	 */
	private function log_result( ShipmentCreateRequest $request, array $response, bool $success, array $errors ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}
		$this->logger->{ $success ? 'info' : 'error' }(
			'Russian Post shipment create result',
			array(
				'order_id' => $request->order_id,
				'method' => 'PUT',
				'path' => '/2.0/user/backlog',
				'http_code' => (int) ( $response['http_code'] ?? 0 ),
				'success' => $success,
				'errors' => $errors,
				'duration_ms' => (int) ( $response['duration_ms'] ?? 0 ),
			)
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $orders
	 * @return array<int,string>
	 */
	private function payload_order_nums( array $orders ): array {
		return array_values(
			array_map(
				static fn ( array $row ): string => (string) ( $row['order-num'] ?? '' ),
				$orders
			)
		);
	}

	/**
	 * @param array<int,string> $order_nums
	 * @return array<int,string>
	 */
	private function unexpected_order_nums( ShipmentCreateRequest $request, array $order_nums ): array {
		$expected = $this->request_order_num( $request );

		return array_values(
			array_filter(
				$order_nums,
				static fn ( string $order_num ): bool => $order_num !== $expected && 1 !== preg_match( '/^' . preg_quote( $expected, '/' ) . '-\d+$/', $order_num )
			)
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $orders
	 * @param array<int,string> $order_nums
	 */
	private function log_create_attempt( ShipmentCreateRequest $request, array $orders, array $order_nums ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}
		$this->logger->info(
			'Russian Post shipment create payload prepared',
			array(
				'order_id' => $request->order_id,
				'request_order_id' => $request->order_id,
				'order_num' => $this->request_order_num( $request ),
				'payload_rows' => count( $orders ),
				'order_nums' => $order_nums,
				'method' => 'PUT',
				'path' => '/2.0/user/backlog',
			)
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $orders
	 * @param array<int,string> $order_nums
	 * @param array<int,string> $unexpected_order_nums
	 */
	private function log_blocked_order_nums( ShipmentCreateRequest $request, array $orders, array $order_nums, array $unexpected_order_nums ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}
		$this->logger->error(
			'Russian Post shipment create blocked because payload contains unexpected order-num values',
			array(
				'order_id' => $request->order_id,
				'request_order_id' => $request->order_id,
				'order_num' => $this->request_order_num( $request ),
				'payload_rows' => count( $orders ),
				'order_nums' => $order_nums,
				'unexpected_order_nums' => $unexpected_order_nums,
				'method' => 'PUT',
				'path' => '/2.0/user/backlog',
			)
		);
	}

	private function request_order_num( ShipmentCreateRequest $request ): string {
		$order_num = trim( (string) ( $request->meta['order_num'] ?? '' ) );

		return '' !== $order_num ? $order_num : (string) $request->order_id;
	}

	/**
	 * @param array<string,mixed> $response
	 * @return array<string,mixed>
	 */
	private function safe_response( array $response ): array {
		unset( $response['headers'], $response['raw_body'] );

		return $response;
	}
}
