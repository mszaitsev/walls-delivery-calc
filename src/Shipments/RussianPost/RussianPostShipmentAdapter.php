<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\RussianPost;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Contracts\ShipmentCarrierAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class RussianPostShipmentAdapter implements ShipmentCarrierAdapterInterface {
	public function __construct(
		private RussianPostOtpravkaApiClient $client,
		private ?RussianPostCreateRequestBuilder $builder = null,
		private ?Logger $logger = null
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
