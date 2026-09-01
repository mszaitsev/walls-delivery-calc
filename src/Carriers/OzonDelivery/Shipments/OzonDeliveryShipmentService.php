<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiException;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupPointProvider;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteException;
use WallsShop\WDC\Carriers\OzonDelivery\Returns\OzonDeliveryReturnService;
use WallsShop\WDC\Domain\Common\MoneyParser;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Application\ShipmentActualCost;
use WallsShop\WDC\Shipments\Application\ShipmentCreationAttemptService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentService {
	public const CONTINUATION_TOKEN = 'ozon_delivery_approve_pending';
	public const MANUAL_ATTACH_ACTUAL_COST_SOURCE_DETAIL = 'ozon_posting_info_estimated_delivery_plus_insurance';

	public function __construct(
		private OzonDeliveryApiClient $api,
		private OzonDeliveryShipmentCreateRequestBuilder $builder,
		private OzonDeliveryShipmentCreateResponseParser $parser,
		private OzonDeliveryShipmentPreflightQuoteService $preflight,
		private OzonDeliveryPickupPointProvider $pickup_provider,
		private OrderShipmentRepository $repository,
		private ShipmentCreationAttemptService $attempts,
		private OzonDeliveryShipmentStatusMapper $status_mapper,
		private OzonDeliveryShipmentInfoParser $info_parser,
		private OzonDeliveryReturnService $returns,
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
		if ( empty( $approval['errors'] ) && ! empty( $status_snapshot['creation_confirmation_required'] ) ) {
			$raw['creation_confirmation_required'] = true;
			$raw['auto_poll'] = true;
			$raw['poll_required'] = true;
			$raw['poll_interval_ms'] = 5000;
			$raw['poll_max_attempts'] = 14;
			$raw['purpose'] = OzonDeliveryShipmentCreationStatusPolicy::PURPOSE;
			$raw['message'] = 'Ozon формирует отправление…';
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
			$shipment['status_title'] = DeliveryStatus::label( (string) ( $status_snapshot['universal_status_code'] ?? DeliveryStatus::CREATED_IN_CARRIER ) );
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
		try {
			$parsed_info = $this->info_parser->parse( $response, is_array( $shipment['ozon_postings'] ?? null ) ? $shipment['ozon_postings'] : array() );
		} catch ( OzonDeliveryShipmentInfoParseException $exception ) {
			$shipment['ozon_status_read_error'] = array_merge( $exception->diagnostics, array( 'checked_at' => $this->now() ) );
			$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );

			return array(
				'success' => false,
				'retryable' => true,
				'error_code' => $exception->safe_code,
				'message' => 'Ozon вернул неполный статус отправлений. Повторите обновление позже.',
				'shipment' => $shipment,
			);
		}
		$statuses = $parsed_info['statuses'];
		$normalized = $parsed_info['normalized'];
		$outbound_statuses = $parsed_info['outbound_statuses'];
		$universal = $this->status_mapper()->aggregate( $statuses );
		$previous_statuses = is_array( $shipment['ozon_statuses'] ?? null ) ? $shipment['ozon_statuses'] : array();
		$shipment['ozon_postings'] = $this->merge_outbound_posting_lifecycle( is_array( $shipment['ozon_postings'] ?? null ) ? $shipment['ozon_postings'] : array(), $normalized, $previous_statuses );
		$shipment['ozon_statuses'] = $normalized;
		unset( $shipment['ozon_status_read_error'] );
		$cancellation_status = (string) ( $shipment['status'] ?? '' );
		if ( in_array( $cancellation_status, array( 'cancellation_started', 'cancellation_exhausted' ), true ) && OzonDeliveryShipmentActionPolicy::all_cancelled( $statuses ) ) {
			$this->terminalize_attempt( $order, $shipment );
			$this->repository->delete_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY );
			return array(
				'success' => true,
				'message' => 'Заказ Ozon отменён и удалён из блока отправлений.',
				'status' => 'CANCELED',
				'cancelled_and_removed' => true,
			);
		}
		if ( in_array( $cancellation_status, array( 'cancellation_started', 'cancellation_exhausted' ), true ) ) {
			$active = 'cancellation_started' === $cancellation_status;
			$shipment['status'] = $cancellation_status;
			$shipment['status_title'] = $active ? 'Ожидаем подтверждение отмены Ozon…' : 'Ozon пока не подтвердил отмену всех отправлений.';
			$shipment['tracking_checked_at'] = $this->now();
			$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );

			return array(
				'success' => true,
				'message' => $active ? 'Ожидаем подтверждение отмены Ozon…' : 'Ozon пока не подтвердил отмену всех отправлений.',
				'pending' => $active,
				'retryable' => true,
				'status' => implode( ',', $statuses ),
				'shipment' => $shipment,
			);
		}
		if ( OzonDeliveryShipmentCreationStatusPolicy::STATUS_STARTED === $cancellation_status ) {
			if ( OzonDeliveryShipmentCreationStatusPolicy::any_failure( $statuses ) ) {
				$shipment['status'] = $universal;
				$shipment['status_title'] = DeliveryStatus::label( $universal );
				$shipment['universal_status_code'] = $universal;
				$shipment['universal_status_label'] = DeliveryStatus::label( $universal );
				$shipment['tracking_checked_at'] = $this->now();
				$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );

				return array(
					'success' => true,
					'message' => 'Ozon не смог сформировать отправление.',
					'status' => implode( ',', $statuses ),
					'shipment' => $shipment,
				);
			}
			if ( OzonDeliveryShipmentCreationStatusPolicy::all_ready( $statuses ) ) {
				$shipment['status'] = DeliveryStatus::CREATED_IN_CARRIER === $universal ? 'created' : $universal;
				$shipment['status_title'] = DeliveryStatus::label( $universal );
				$shipment['universal_status_code'] = $universal;
				$shipment['universal_status_label'] = DeliveryStatus::label( $universal );
				$shipment['tracking_checked_at'] = $this->now();
				$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );

				return array( 'success' => true, 'message' => 'Статус Ozon обновлён.', 'status' => implode( ',', $statuses ), 'shipment' => $shipment );
			}
			$shipment['status_title'] = DeliveryStatus::label( DeliveryStatus::CREATED_IN_CARRIER );
			$shipment['universal_status_code'] = DeliveryStatus::CREATED_IN_CARRIER;
			$shipment['universal_status_label'] = DeliveryStatus::label( DeliveryStatus::CREATED_IN_CARRIER );
			$shipment['tracking_checked_at'] = $this->now();
			$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );

			return array(
				'success' => true,
				'message' => 'Ozon формирует отправление…',
				'pending' => true,
				'retryable' => true,
				'status' => implode( ',', $statuses ),
				'shipment' => $shipment,
			);
		}
		if ( $this->returns->should_reconcile( $shipment, $outbound_statuses ) ) {
			$return_result = $this->returns->reconcile( $order, $shipment, $outbound_statuses );
			$shipment = $return_result['shipment'];
			$universal = $return_result['universal_status'];
			$shipment['status'] = $universal;
			$shipment['status_title'] = DeliveryStatus::label( $universal );
			$shipment['universal_status_code'] = $universal;
			$shipment['universal_status_label'] = DeliveryStatus::label( $universal );
			$shipment['tracking_checked_at'] = $this->now();
			$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );

			return array(
				'success' => (bool) ( $return_result['success'] ?? true ),
				'message' => $return_result['message'],
				'retryable' => (bool) ( $return_result['retryable'] ?? false ),
				'error_code' => (string) ( $return_result['error_code'] ?? '' ),
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

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function attach_manual( object $order, array $payload ): array {
		$posting_number = $this->manual_posting_number( $payload );
		if ( '' === $posting_number ) {
			return array( 'success' => false, 'message' => 'Введите номер отправления Ozon.' );
		}
		if ( array() !== $this->repository->find_by_carrier( $order, OzonDeliverySettings::CARRIER_KEY ) ) {
			return array( 'success' => false, 'message' => 'Для заказа уже сохранено отправление Ozon.' );
		}

		$expected_postings = array( array( 'place_number' => 1, 'posting_number' => $posting_number ) );
		try {
			$response = $this->api->posting_info( array( $posting_number ) );
			$parsed_info = $this->info_parser->parse( $response, $expected_postings );
		} catch ( OzonDeliveryShipmentInfoParseException ) {
			return array( 'success' => false, 'message' => 'Ozon не подтвердил указанный номер отправления.' );
		} catch ( OzonDeliveryApiException $exception ) {
			$message = 404 === $exception->http_status
				? 'Отправление Ozon с таким номером не найдено.'
				: 'Не удалось проверить номер отправления Ozon: ' . $exception->getMessage();
			return array( 'success' => false, 'message' => $message );
		} catch ( \Throwable $exception ) {
			return array( 'success' => false, 'message' => 'Не удалось проверить номер отправления Ozon: ' . $exception->getMessage() );
		}

		$statuses = $parsed_info['statuses'];
		$normalized = $parsed_info['normalized'];
		$raw_status = (string) ( $normalized[0]['status'] ?? '' );
		$universal = $this->status_mapper()->aggregate( $statuses );
		$listing = is_array( $response['postings'][0] ?? null ) ? $response['postings'][0] : array();
		try {
			$manual_cost = $this->manual_attach_actual_cost( $listing );
		} catch ( \InvalidArgumentException ) {
			return array( 'success' => false, 'message' => 'Ozon вернул некорректную стоимость отправления.' );
		}
		$posting = array(
			'place_number' => 1,
			'posting_number' => $posting_number,
			'posting_external_id' => (string) ( $listing['posting_external_id'] ?? '' ),
			'last_raw_status' => $raw_status,
			'last_universal_status' => $this->status_mapper()->universal( $raw_status ),
			'last_status_changed_at' => (string) ( $normalized[0]['status_changed_at'] ?? '' ),
			'manual_attach' => true,
		);
		$posting = $this->apply_manual_handover_state( $posting, $raw_status );
		$shipment = array(
			'carrier_key' => OzonDeliverySettings::CARRIER_KEY,
			'service_key' => OzonDeliverySettings::SERVICE_KEY,
			'rate_id' => OzonDeliverySettings::PICKUP_FAMILY,
			'ozon_order_number' => (string) ( $listing['order_number'] ?? '' ),
			'ozon_postings' => array( $posting ),
			'tracking_number' => $posting_number,
			'barcode' => $posting_number,
			'status' => DeliveryStatus::CREATED_IN_CARRIER === $universal ? 'created' : $universal,
			'status_title' => DeliveryStatus::label( $universal ),
			'universal_status_code' => $universal,
			'universal_status_label' => DeliveryStatus::label( $universal ),
			'ozon_statuses' => $normalized,
			'tracking_checked_at' => $this->now(),
			'created_at' => $this->now(),
			'updated_at' => $this->now(),
			'manual_attach' => true,
			'request_snapshot' => array( 'method' => 'POST', 'path' => '/v1/posting/info', 'posting_count' => 1 ),
			'response_snapshot' => array(
				'operation' => 'POST /v1/posting/info',
				'posting_number' => $posting_number,
				'order_number_present' => '' !== (string) ( $listing['order_number'] ?? '' ),
				'status' => $raw_status,
				'delivery_cost_kopecks' => $manual_cost['delivery_cost_kopecks'],
				'insurance_cost_kopecks' => $manual_cost['insurance_cost_kopecks'],
				'total_cost_kopecks' => $manual_cost['actual_cost']->amount_kopecks,
				'actual_cost_source_detail' => self::MANUAL_ATTACH_ACTUAL_COST_SOURCE_DETAIL,
			),
		);
		$shipment = array_merge( $shipment, $manual_cost['actual_cost']->to_fields( $this->now() ) );
		$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );
		$this->attempts->mark_active_for_shipment( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );

		return array(
			'success' => true,
			'message' => 'Номер Ozon прикреплён, статус обновлён.',
			'tracking_number' => $posting_number,
			'shipment' => $shipment,
		);
	}

	/**
	 * @param array<string,mixed> $posting
	 * @return array{actual_cost:ShipmentActualCost,delivery_cost_kopecks:int,insurance_cost_kopecks:int}
	 */
	private function manual_attach_actual_cost( array $posting ): array {
		$delivery = $this->posting_money_kopecks( $posting['estimated_delivery_cost'] ?? null );
		$insurance = $this->posting_money_kopecks( $posting['estimated_insurance_cost'] ?? null );

		return array(
			'actual_cost' => new ShipmentActualCost(
				$delivery + $insurance,
				'RUB',
				'carrier_api',
				self::MANUAL_ATTACH_ACTUAL_COST_SOURCE_DETAIL,
				$this->now()
			),
			'delivery_cost_kopecks' => $delivery,
			'insurance_cost_kopecks' => $insurance,
		);
	}

	private function posting_money_kopecks( mixed $money ): int {
		if ( ! is_array( $money ) || 'RUB' !== strtoupper( trim( (string) ( $money['currency_code'] ?? '' ) ) ) ) {
			throw new \InvalidArgumentException( 'Ozon posting money must be RUB.' );
		}
		if ( ! array_key_exists( 'amount', $money ) || ! is_scalar( $money['amount'] ) ) {
			throw new \InvalidArgumentException( 'Ozon posting money amount is missing.' );
		}
		$amount = trim( str_replace( array( "\xc2\xa0", ' ' ), '', (string) $money['amount'] ) );
		$amount = str_replace( ',', '.', $amount );
		if ( 1 !== preg_match( '/^\d+(?:\.\d{1,2})?$/', $amount ) ) {
			throw new \InvalidArgumentException( 'Ozon posting money amount is malformed.' );
		}
		$kopecks = MoneyParser::numeric_to_kopecks( $amount );
		if ( null === $kopecks || $kopecks < 0 ) {
			throw new \InvalidArgumentException( 'Ozon posting money amount is invalid.' );
		}

		return $kopecks;
	}

	/** @param array<int,array<string,mixed>> $postings @param array<int,array<string,string>> $statuses @param array<int,mixed> $previous_statuses @return array<int,array<string,mixed>> */
	private function merge_outbound_posting_lifecycle( array $postings, array $statuses, array $previous_statuses ): array {
		$by_number = array();
		foreach ( $statuses as $status ) {
			$number = (string) ( $status['posting_number'] ?? '' );
			if ( '' !== $number ) {
				$by_number[ $number ] = $status;
			}
		}
		$previous_by_number = array();
		foreach ( $previous_statuses as $status ) {
			if ( ! is_array( $status ) ) {
				continue;
			}
			$number = (string) ( $status['posting_number'] ?? '' );
			if ( '' !== $number ) {
				$previous_by_number[ $number ] = (string) ( $status['status'] ?? '' );
			}
		}
		foreach ( $postings as $index => $posting ) {
			if ( ! is_array( $posting ) ) {
				continue;
			}
			$number = (string) ( $posting['posting_number'] ?? '' );
			if ( ! isset( $by_number[ $number ] ) ) {
				continue;
			}
			$raw = (string) ( $by_number[ $number ]['status'] ?? '' );
			$universal = $this->status_mapper()->universal( $raw );
			$posting['last_raw_status'] = $raw;
			$posting['last_universal_status'] = $universal;
			$posting['last_status_changed_at'] = (string) ( $by_number[ $number ]['status_changed_at'] ?? '' );
			$normalized_raw = OzonDeliveryShipmentStatusMapping::normalize( $raw );
			if ( ! empty( $posting['handover_seen'] ) ) {
				$posting['handover_seen'] = true;
				unset( $posting['handover_unknown'] );
			} elseif ( $this->is_handover_universal( $universal, $raw ) ) {
				$posting['handover_seen'] = true;
				unset( $posting['handover_unknown'] );
			} elseif ( DeliveryStatus::UNKNOWN === $universal ) {
				unset( $posting['handover_seen'] );
				$posting['handover_unknown'] = true;
			} elseif ( 'canceled' === $normalized_raw && ! array_key_exists( 'handover_seen', $posting ) ) {
				$previous_universal = isset( $previous_by_number[ $number ] ) ? $this->status_mapper()->universal( $previous_by_number[ $number ] ) : '';
				if ( in_array( $previous_universal, array( DeliveryStatus::PENDING_CREATION_IN_CARRIER, DeliveryStatus::CREATED_IN_CARRIER ), true ) ) {
					$posting['handover_seen'] = false;
					unset( $posting['handover_unknown'] );
				} elseif ( DeliveryStatus::is_valid( $previous_universal ) && ! in_array( $previous_universal, array( DeliveryStatus::UNKNOWN, DeliveryStatus::CANCELLED ), true ) ) {
					$posting['handover_seen'] = true;
					unset( $posting['handover_unknown'] );
				} else {
					$posting['handover_unknown'] = true;
				}
			} elseif ( in_array( $universal, array( DeliveryStatus::PENDING_CREATION_IN_CARRIER, DeliveryStatus::CREATED_IN_CARRIER ), true ) && ! array_key_exists( 'handover_seen', $posting ) ) {
				$posting['handover_seen'] = false;
				unset( $posting['handover_unknown'] );
			}
			$postings[ $index ] = $posting;
		}
		return array_values( $postings );
	}

	private function is_handover_universal( string $universal, string $raw ): bool {
		if ( 'canceled' === OzonDeliveryShipmentStatusMapping::normalize( $raw ) ) {
			return false;
		}
		return ! in_array( $universal, array( DeliveryStatus::PENDING_CREATION_IN_CARRIER, DeliveryStatus::CREATED_IN_CARRIER, DeliveryStatus::UNKNOWN ), true );
	}

	/** @param array<string,mixed> $posting @return array<string,mixed> */
	private function apply_manual_handover_state( array $posting, string $raw_status ): array {
		$universal = (string) ( $posting['last_universal_status'] ?? $this->status_mapper()->universal( $raw_status ) );
		if ( $this->is_handover_universal( $universal, $raw_status ) ) {
			$posting['handover_seen'] = true;
			unset( $posting['handover_unknown'] );
		} elseif ( DeliveryStatus::UNKNOWN === $universal || 'canceled' === OzonDeliveryShipmentStatusMapping::normalize( $raw_status ) ) {
			unset( $posting['handover_seen'] );
			$posting['handover_unknown'] = true;
		} else {
			$posting['handover_seen'] = false;
			unset( $posting['handover_unknown'] );
		}

		return $posting;
	}

	/** @param array<int,string> $statuses */
	private function has_external_canceled_posting( array $statuses ): bool {
		foreach ( $statuses as $status ) {
			if ( 'canceled' === OzonDeliveryShipmentStatusMapping::normalize( $status ) ) {
				return true;
			}
		}
		return false;
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
		$accepted_postings = array();
		$failed_postings = array();
		foreach ( $numbers as $number ) {
			try {
				$this->api->posting_cancel( $number );
				$accepted_postings[] = $number;
			} catch ( \Throwable $exception ) {
				$failed_postings[] = $number;
			}
		}
		if ( array() === $accepted_postings ) {
			return array( 'success' => false, 'message' => $this->cancel_failure_message( $failed_postings ) );
		}
		$shipment['status'] = 'cancellation_started';
		$shipment['status_title'] = 'Ожидаем подтверждение отмены Ozon…';
		$shipment['universal_status_code'] = DeliveryStatus::UNKNOWN;
		$shipment['universal_status_label'] = DeliveryStatus::label( DeliveryStatus::UNKNOWN );
		$shipment['cancel_attempt'] = array(
			'accepted_count' => count( $accepted_postings ),
			'failed_count' => count( $failed_postings ),
			'accepted_posting_numbers' => $accepted_postings,
			'failed_posting_numbers' => $failed_postings,
		);
		$this->repository->save_for_carrier( $order, OzonDeliverySettings::CARRIER_KEY, $shipment );
		$partial = array() !== $failed_postings;

		return array(
			'success' => true,
			'accepted' => true,
			'cancellation_started' => true,
			'partial' => $partial,
			'auto_poll' => true,
			'poll_interval_ms' => 5000,
			'poll_max_attempts' => 14,
			'purpose' => 'cancellation',
			'message' => $partial ? 'Ozon принял отмену части грузомест. Проверяем итоговый статус всех отправлений…' : 'Запрос на отмену заказа Ozon отправлен. Ожидаем подтверждение отмены Ozon…',
		);
	}

	/** @param array<int,string> $failed_postings */
	private function cancel_failure_message( array $failed_postings ): string {
		if ( array() === $failed_postings ) {
			return 'Не удалось отменить заказ Ozon.';
		}
		return implode(
			"\n",
			array_map(
				static fn( string $number ): string => 'Не удалось отменить отправление Ozon ' . $number . '.',
				$failed_postings
			)
		);
	}

	/** @param array<string,mixed> $payload */
	private function manual_posting_number( array $payload ): string {
		foreach ( array( 'posting_number', 'tracking_number', 'barcode', 'request_id', 'manual_value' ) as $key ) {
			$value = $payload[ $key ] ?? '';
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return substr( trim( (string) $value ), 0, 128 );
			}
		}

		return '';
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
		if ( \WallsShop\WDC\Domain\Quote\DeliveryType::PICKUP !== $request->delivery_type ) {
			return array();
		}
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
				'creation_confirmation_required' => true,
			);
		}
		try {
			$parsed_info = $this->info_parser->parse( $response, $postings );
		} catch ( OzonDeliveryShipmentInfoParseException $exception ) {
			return array(
				'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER,
				'ozon_statuses' => array(),
				'ozon_status_read_error' => array_merge( $exception->diagnostics, array( 'checked_at' => $this->now() ) ),
				'creation_confirmation_required' => true,
			);
		}
		$statuses = $parsed_info['statuses'];
		$normalized = $parsed_info['normalized'];
		$universal = $this->status_mapper()->aggregate( $statuses );
		if ( in_array( $universal, array( DeliveryStatus::PENDING_CREATION_IN_CARRIER, DeliveryStatus::UNKNOWN ), true ) ) {
			$universal = DeliveryStatus::CREATED_IN_CARRIER;
		}

		return array( 'universal_status_code' => $universal, 'ozon_statuses' => $normalized, 'creation_confirmation_required' => OzonDeliveryShipmentCreationStatusPolicy::any_waiting( $statuses ) && ! OzonDeliveryShipmentCreationStatusPolicy::any_failure( $statuses ) );
	}

	private function status_mapper(): OzonDeliveryShipmentStatusMapper {
		return $this->status_mapper;
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
