<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiException;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupPointProvider;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteException;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Application\ShipmentCreationAttemptService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentService {
	public const CONTINUATION_TOKEN = 'ozon_delivery_approve_pending';

	public function __construct(
		private OzonDeliveryApiClient $api,
		private OzonDeliveryShipmentCreateRequestBuilder $builder,
		private OzonDeliveryShipmentCreateResponseParser $parser,
		private OzonDeliveryShipmentPreflightQuoteService $preflight,
		private OzonDeliveryPickupPointProvider $pickup_provider,
		private OrderShipmentRepository $repository,
		private ShipmentCreationAttemptService $attempts,
		private ?Logger $logger = null
	) {}

	public function create_and_approve( object $order, ShipmentCreateRequest $request ): ShipmentCreateResult {
		$prepared = $this->builder->prepare( $order, $request );
		$errors = array_merge( $prepared['errors'], $this->point_errors( $request ) );
		if ( array() !== $errors ) {
			return new ShipmentCreateResult( false, error_code: 'ozon_shipment_validation_failed', error_message: implode( "\n", $errors ), raw_reference: array( 'summary' => $prepared['summary'], 'errors' => $errors ) );
		}
		$idempotency_key = $this->idempotency_key( $request );
		try {
			$preflight = $this->preflight->quote( $prepared['body'], $this->now() );
		} catch ( OzonDeliveryQuoteException $exception ) {
			return new ShipmentCreateResult( false, error_code: 'ozon_shipment_preflight_failed', error_message: 'Не удалось проверить фактическую стоимость отправления Ozon. Отправление не создано.', raw_reference: array( 'summary' => $prepared['summary'], 'preflight' => array( 'safe_code' => $exception->safe_code, 'operation' => $exception->operation, 'http_status' => $exception->http_status ) ) );
		} catch ( OzonDeliveryApiException $exception ) {
			return new ShipmentCreateResult( false, error_code: 'ozon_shipment_preflight_failed', error_message: 'Не удалось проверить фактическую стоимость отправления Ozon. Отправление не создано.', raw_reference: array( 'summary' => $prepared['summary'], 'preflight' => $exception->metadata ) );
		} catch ( \Throwable $exception ) {
			return new ShipmentCreateResult( false, error_code: 'ozon_shipment_preflight_failed', error_message: 'Не удалось проверить фактическую стоимость отправления Ozon. Отправление не создано.', raw_reference: array( 'summary' => $prepared['summary'], 'preflight' => array( 'error' => $exception->getMessage() ) ) );
		}
		try {
			$response = $this->api->order_create( $prepared['body'], $idempotency_key );
			$parsed = $this->parser->parse( $response, array_column( $prepared['body']['postings'], 'request_id' ) );
		} catch ( OzonDeliveryApiException $exception ) {
			return new ShipmentCreateResult( false, error_code: 'ozon_order_create_failed', error_message: $exception->getMessage(), raw_reference: array( 'summary' => $prepared['summary'], 'api' => $exception->metadata ) );
		} catch ( \Throwable $exception ) {
			return new ShipmentCreateResult( false, error_code: 'ozon_order_create_malformed', error_message: $exception->getMessage(), raw_reference: array( 'summary' => $prepared['summary'] ) );
		}
		$approval = $this->approve_postings( $parsed['postings'] );
		$status_snapshot = empty( $approval['errors'] ) ? $this->post_approve_status_snapshot( $approval['postings'] ) : array();
		$raw = array(
			'request' => $this->safe_request_snapshot( $prepared ),
			'response' => $this->safe_response_snapshot( $parsed ),
			'preflight' => is_array( $preflight['summary'] ?? null ) ? $preflight['summary'] : array(),
			'actual_cost_candidate' => $preflight['actual_cost_candidate'] ?? null,
			'ozon_order_number' => $parsed['order_number'],
			'ozon_order_external_id' => $parsed['order_external_id'],
			'ozon_postings' => $approval['postings'],
			'ozon_idempotency_key' => $idempotency_key,
			'summary' => $prepared['summary'],
			'approval' => array( 'approved_count' => $approval['approved_count'], 'total_count' => count( $approval['postings'] ) ),
		);
		if ( array() !== $status_snapshot ) {
			$raw = array_merge( $raw, $status_snapshot );
		}
		if ( ! empty( $approval['errors'] ) ) {
			return new ShipmentCreateResult( false, error_code: 'ozon_posting_approve_partial', error_message: implode( "\n", $approval['errors'] ), raw_reference: $raw );
		}
		$tracking = (string) ( $approval['postings'][0]['posting_number'] ?? $parsed['order_number'] );
		$this->log_success( $request, $parsed, $prepared['summary'] );

		return new ShipmentCreateResult( true, external_id: $parsed['order_number'], tracking_number: $tracking, backlog_order_id: $parsed['order_number'], raw_reference: $raw );
	}

	/** @return array<string,mixed> */
	public function continue_approval( object $order, string $token ): array {
		if ( self::CONTINUATION_TOKEN !== $token ) {
			return array( 'success' => false, 'message' => 'Неизвестное продолжение создания Ozon.' );
		}
		$shipment = $this->repository->find_by_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
		$postings = is_array( $shipment['ozon_postings'] ?? null ) ? $shipment['ozon_postings'] : array();
		if ( array() === $shipment || array() === $postings ) {
			return array( 'success' => false, 'message' => 'Не найдены созданные отправления Ozon для продолжения.' );
		}
		$approval = $this->approve_postings( $postings );
		$shipment['ozon_postings'] = $approval['postings'];
		$shipment['response_snapshot']['approval'] = array( 'approved_count' => $approval['approved_count'], 'total_count' => count( $approval['postings'] ) );
		if ( empty( $approval['errors'] ) ) {
			$status_snapshot = $this->post_approve_status_snapshot( $approval['postings'] );
			$shipment['pending_creation_in_carrier'] = false;
			$shipment['status'] = 'created';
			$shipment['status_title'] = 'Отправление Ozon создано и подтверждено.';
			$shipment['universal_status_code'] = (string) ( $status_snapshot['universal_status_code'] ?? DeliveryStatus::CREATED_IN_CARRIER );
			$shipment['universal_status_label'] = DeliveryStatus::label( $shipment['universal_status_code'] );
			if ( isset( $status_snapshot['ozon_statuses'] ) ) {
				$shipment['ozon_statuses'] = $status_snapshot['ozon_statuses'];
			}
			if ( isset( $status_snapshot['ozon_status_read_error'] ) ) {
				$shipment['ozon_status_read_error'] = $status_snapshot['ozon_status_read_error'];
			}
			$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );
			$this->attempts->mark_active_for_shipment( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );
			return array( 'success' => true, 'message' => 'Отправления Ozon подтверждены.' );
		}
		$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );
		return array( 'success' => false, 'message' => implode( "\n", $approval['errors'] ) );
	}

	/** @return array<string,mixed> */
	public function status( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
		$numbers = $this->posting_numbers( $shipment );
		if ( array() === $numbers ) {
			return array( 'success' => false, 'message' => 'Не найдены номера отправлений Ozon.' );
		}
		try {
			$response = $this->api->posting_info( $numbers );
		} catch ( \Throwable $exception ) {
			return array( 'success' => false, 'message' => 'Не удалось получить статус Ozon: ' . $exception->getMessage() );
		}
		$postings = is_array( $response['postings'] ?? null ) ? $response['postings'] : array();
		$statuses = array();
		$normalized = array();
		foreach ( $postings as $posting ) {
			if ( ! is_array( $posting ) ) { continue; }
			$status = (string) ( $posting['status'] ?? 'unknown' );
			$statuses[] = $status;
			$normalized[] = array(
				'posting_number' => (string) ( $posting['posting_number'] ?? '' ),
				'status' => $status,
				'normalized_status' => OzonDeliveryShipmentStatusMapping::normalize( $status ),
				'status_changed_at' => (string) ( $posting['status_changed_at'] ?? '' ),
			);
		}
		$universal = OzonDeliveryShipmentStatusMapping::aggregate( $statuses );
		$shipment['ozon_statuses'] = $normalized;
		if ( 'cancellation_started' === (string) ( $shipment['status'] ?? '' ) && OzonDeliveryShipmentActionPolicy::all_cancelled( $statuses ) ) {
			$this->terminalize_attempt( $order, $shipment );
			$this->repository->delete_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
			return array(
				'success' => true,
				'message' => 'Заказ Ozon отменён и удалён из блока отправлений.',
				'status' => 'CANCELED',
				'cancelled_and_removed' => true,
			);
		}
		if ( 'cancellation_started' === (string) ( $shipment['status'] ?? '' ) ) {
			$shipment['status_title'] = 'Ожидаем подтверждение отмены Ozon…';
			$shipment['tracking_checked_at'] = $this->now();
			$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );

			return array(
				'success' => true,
				'message' => 'Ожидаем подтверждение отмены Ozon…',
				'pending' => true,
				'retryable' => true,
				'status' => implode( ',', $statuses ),
				'shipment' => $shipment,
			);
		}
		$shipment['status'] = $universal;
		$shipment['status_title'] = DeliveryStatus::label( $universal );
		$shipment['universal_status_code'] = $universal;
		$shipment['universal_status_label'] = DeliveryStatus::label( $universal );
		$shipment['tracking_checked_at'] = $this->now();
		$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );

		return array( 'success' => true, 'message' => 'Статус Ozon обновлён.', 'shipment' => $shipment );
	}

	/** @return array<string,mixed> */
	public function cancel( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
		$numbers = $this->posting_numbers( $shipment );
		if ( array() === $numbers ) {
			return array( 'success' => false, 'message' => 'Не найдены номера отправлений Ozon.' );
		}
		$policy = OzonDeliveryShipmentActionPolicy::for_shipment( $shipment );
		if ( empty( $policy['can_cancel'] ) ) {
			return array( 'success' => false, 'message' => 'Текущий статус Ozon не позволяет отменить заказ из плагина. Обновите статус или удалите локальную запись.', 'temporary_can_remove' => true );
		}
		$errors = array();
		foreach ( $numbers as $number ) {
			try {
				$this->api->posting_cancel( $number );
			} catch ( \Throwable $exception ) {
				$errors[] = 'Не удалось отменить отправление Ozon ' . $number . '.';
			}
		}
		if ( array() !== $errors ) {
			return array( 'success' => false, 'message' => implode( "\n", $errors ) );
		}
		$shipment['status'] = 'cancellation_started';
		$shipment['status_title'] = 'Ожидаем подтверждение отмены Ozon…';
		$shipment['universal_status_code'] = DeliveryStatus::UNKNOWN;
		$shipment['universal_status_label'] = DeliveryStatus::label( DeliveryStatus::UNKNOWN );
		$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );

		return array(
			'success' => true,
			'accepted' => true,
			'cancellation_started' => true,
			'auto_poll' => true,
			'poll_interval_ms' => 5000,
			'poll_max_attempts' => 14,
			'purpose' => 'cancellation',
			'message' => 'Запрос на отмену заказа Ozon отправлен. Ожидаем подтверждение отмены Ozon…',
		);
	}

	/** @param array<string,mixed> $shipment */
	public function terminalize_attempt( object $order, array $shipment ): void {
		if ( array() === $shipment ) {
			return;
		}
		$this->attempts->mark_terminal_for_shipment( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );
	}

	/** @return array<int,string> */
	private function point_errors( ShipmentCreateRequest $request ): array {
		$point_id = (int) preg_replace( '/\D+/', '', $request->pickup_point?->point_code ?? (string) ( $request->meta['pickup_point_code'] ?? '' ) );
		$places = array_map( static fn( $place ): array => array( 'weight_g' => $place->weight_g, 'length_cm' => $place->length_cm, 'width_cm' => $place->width_cm, 'height_cm' => $place->height_cm ), $request->places );
		$rejection = $point_id > 0 ? $this->pickup_provider->first_place_rejection( $point_id, $places ) : array( 'reason' => 'point_unavailable', 'place_index' => 0 );
		if ( null === $rejection ) {
			return array();
		}
		$place = max( 1, (int) ( $rejection['place_index'] ?? 1 ) );
		return match ( (string) ( $rejection['reason'] ?? '' ) ) {
			'max_weight' => array( sprintf( 'Грузоместо %d превышает допустимый вес ПВЗ Ozon.', $place ) ),
			'min_weight' => array( sprintf( 'Грузоместо %d меньше минимального веса ПВЗ Ozon.', $place ) ),
			'dimensions' => array( sprintf( 'Грузоместо %d превышает допустимые размеры выбранного ПВЗ Ozon.', $place ) ),
			default => array( 'Выбранный ПВЗ Ozon недоступен для создания отправления.' ),
		};
	}

	/** @param array<int,array<string,mixed>> $postings @return array{postings:array<int,array<string,mixed>>,approved_count:int,errors:array<int,string>} */
	private function approve_postings( array $postings ): array {
		$errors = array();
		$approved_count = 0;
		foreach ( $postings as $index => $posting ) {
			$number = (string) ( is_array( $posting ) ? ( $posting['posting_number'] ?? '' ) : '' );
			if ( '' === $number ) {
				$errors[] = 'Ozon вернул пустой номер отправления.';
				continue;
			}
			if ( ! empty( $posting['approved'] ) ) {
				++$approved_count;
				continue;
			}
			try {
				$this->api->posting_approve( $number );
				$postings[ $index ]['approved'] = true;
				++$approved_count;
			} catch ( OzonDeliveryApiException $exception ) {
				try {
					$info = $this->api->posting_info( array( $number ) );
					$status = (string) ( $info['postings'][0]['status'] ?? '' );
					if ( 'ready_for_shipping' === OzonDeliveryShipmentStatusMapping::normalize( $status ) ) {
						$postings[ $index ]['approved'] = true;
						++$approved_count;
						continue;
					}
				} catch ( \Throwable ) {
				}
				$errors[] = 'Не удалось подтвердить отправление Ozon ' . $number . ': ' . $exception->getMessage();
			}
		}
		return array( 'postings' => array_values( $postings ), 'approved_count' => $approved_count, 'errors' => $errors );
	}

	private function idempotency_key( ShipmentCreateRequest $request ): string {
		$key = (string) ( $request->meta['creation_attempt_id'] ?? '' );
		if ( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key ) ) {
			return strtolower( $key );
		}
		throw new \RuntimeException( 'Не найден ключ идемпотентности Ozon.' );
	}

	/** @param array<string,mixed> $prepared @return array<string,mixed> */
	private function safe_request_snapshot( array $prepared ): array {
		return array( 'method' => 'POST', 'path' => '/v1/order/create', 'summary' => $prepared['summary'] );
	}

	/** @param array<string,mixed> $parsed @return array<string,mixed> */
	private function safe_response_snapshot( array $parsed ): array {
		return array( 'order_number' => $parsed['order_number'], 'postings' => $parsed['postings'] );
	}

	/** @param array<int,array<string,mixed>> $postings @return array<string,mixed> */
	private function post_approve_status_snapshot( array $postings ): array {
		$numbers = array_values( array_filter( array_map( static fn( array $posting ): string => trim( (string) ( $posting['posting_number'] ?? '' ) ), $postings ) ) );
		if ( array() === $numbers ) {
			return array( 'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER, 'ozon_statuses' => array() );
		}
		try {
			$response = $this->api->posting_info( $numbers );
		} catch ( \Throwable $exception ) {
			return array(
				'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER,
				'ozon_statuses' => array(),
				'ozon_status_read_error' => $exception->getMessage(),
			);
		}
		$statuses = array();
		$normalized = array();
		foreach ( is_array( $response['postings'] ?? null ) ? $response['postings'] : array() as $posting ) {
			if ( ! is_array( $posting ) ) {
				continue;
			}
			$status = (string) ( $posting['status'] ?? 'unknown' );
			$statuses[] = $status;
			$normalized[] = array(
				'posting_number' => (string) ( $posting['posting_number'] ?? '' ),
				'status' => $status,
				'normalized_status' => OzonDeliveryShipmentStatusMapping::normalize( $status ),
				'status_changed_at' => (string) ( $posting['status_changed_at'] ?? '' ),
			);
		}
		$universal = OzonDeliveryShipmentStatusMapping::aggregate( $statuses );
		if ( in_array( $universal, array( DeliveryStatus::PENDING_CREATION_IN_CARRIER, DeliveryStatus::UNKNOWN ), true ) ) {
			$universal = DeliveryStatus::CREATED_IN_CARRIER;
		}

		return array( 'universal_status_code' => $universal, 'ozon_statuses' => $normalized );
	}

	/** @param array<string,mixed> $shipment @return array<int,string> */
	private function posting_numbers( array $shipment ): array {
		$postings = is_array( $shipment['ozon_postings'] ?? null ) ? $shipment['ozon_postings'] : array();
		$numbers = array();
		foreach ( $postings as $posting ) {
			if ( is_array( $posting ) && '' !== trim( (string) ( $posting['posting_number'] ?? '' ) ) ) {
				$numbers[] = trim( (string) $posting['posting_number'] );
			}
		}
		return array_values( array_unique( $numbers ) );
	}

	private function log_success( ShipmentCreateRequest $request, array $parsed, array $summary ): void {
		if ( ! $this->logger instanceof Logger ) {
			return;
		}
		$this->logger->info( 'Ozon Delivery shipment created and approved.', array(
			'carrier' => OzonDeliverySettings::CARRIER_KEY,
			'order_id' => $request->order_id,
			'places_count' => (int) ( $summary['places_count'] ?? 0 ),
			'external_postings_count' => count( is_array( $parsed['postings'] ?? null ) ? $parsed['postings'] : array() ),
			'ozon_order_number_present' => '' !== (string) ( $parsed['order_number'] ?? '' ),
		) );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
