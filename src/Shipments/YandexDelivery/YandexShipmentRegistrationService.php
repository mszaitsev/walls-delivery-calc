<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryEarliestOfferSelector;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryOffer;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryRequestInfo;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentClient;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentPayloadBuilder;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentRegistrationResult;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentRegistrationService as CoreYandexShipmentRegistrationService;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentAllocationAdapter;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentRegistrationService {
	private const DUPLICATE_OPERATOR_RETRY_LIMIT = 10;

	public function __construct(
		private CoreYandexShipmentRegistrationService $registration,
		private YandexDeliveryShipmentPayloadBuilder $payload_builder,
		private YandexDeliveryShipmentClient $client,
		private YandexShipmentRepository $repository,
		private YandexShipmentPersistenceMapper $persistence_mapper,
		private YandexShipmentButtonPolicy $button_policy
	) {
	}

	/** @return array<string,mixed> */
	public function build_preview_payload( ShipmentCreateRequest $request ): array {
		return $this->payload_builder->build( $this->allocation( $request ), $this->context( $request ) );
	}

	public function create_for_order( object $order, ShipmentCreateRequest $request ): ShipmentCreateResult {
		$now = $this->now();
		try {
			$attempt = $this->repository->reserve_operator_request_id( $order, (string) ( $request->meta['order_num'] ?? $this->repository->base_operator_request_id( $order ) ), $now );
		} catch ( \RuntimeException $exception ) {
			return new ShipmentCreateResult( false, error_code: 'yandex_registration_locked', error_message: $exception->getMessage() );
		}
		try {
			$base = (string) ( $request->meta['order_num'] ?? $this->repository->base_operator_request_id( $order ) );
			for ( $duplicate_attempt = 0; $duplicate_attempt < self::DUPLICATE_OPERATOR_RETRY_LIMIT; $duplicate_attempt++ ) {
				$operator_request_id = $attempt['operator_request_id'];
				$reserved = $this->request_with_meta(
					$request,
					array(
						'yandex_operator_request_id' => $operator_request_id,
						'yandex_registration_sequence_index' => $attempt['index'],
						'yandex_registration_attempt_started_at' => $attempt['started_at'],
						'yandex_temporary_barcode_prefix' => $this->repository->temporary_barcode_prefix( $operator_request_id ),
					)
				);
				$this->add_order_note( $order, 'Начата регистрация отправления Яндекс. Номер попытки: ' . $operator_request_id . '.' );
				$result = $this->create( $reserved );
				if ( 'yandex_operator_request_id_duplicate' !== $result->error_code ) {
					return $result;
				}
				if ( $duplicate_attempt >= self::DUPLICATE_OPERATOR_RETRY_LIMIT - 1 ) {
					return new ShipmentCreateResult(
						false,
						error_code: 'yandex_operator_request_id_duplicate_limit',
						error_message: 'Не удалось подобрать свободный номер регистрации Яндекс после 10 попыток. Повторите создание позднее.',
						raw_reference: $result->raw_reference
					);
				}
				$attempt = $this->repository->reserve_operator_request_id_under_lock( $order, $base, $this->now(), $attempt['lock_token'] );
			}

			return new ShipmentCreateResult( false, error_code: 'yandex_operator_request_id_duplicate_limit', error_message: 'Не удалось подобрать свободный номер регистрации Яндекс после 10 попыток. Повторите создание позднее.' );
		} finally {
			$this->repository->release_registration_lock( $order, $attempt['lock_token'] );
		}
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		try {
			$result = $this->register_request( $request );
			$fields = $this->fields_from_result( $result );
			$fields['yandex_registration_sequence_index'] = (int) ( $request->meta['yandex_registration_sequence_index'] ?? $fields['yandex_registration_sequence_index'] ?? 0 );
			$fields['yandex_registration_attempt_started_at'] = (string) ( $request->meta['yandex_registration_attempt_started_at'] ?? '' );
			$fields['yandex_temporary_barcode_prefix'] = (string) ( $request->meta['yandex_temporary_barcode_prefix'] ?? '' );
			$request_id = $result->request_info->request_id;
			$tracking = '' !== $result->request_info->courier_order_id ? $result->request_info->courier_order_id : $request_id;

			return new ShipmentCreateResult(
				true,
				external_id: $request_id,
				tracking_number: $tracking,
				backlog_order_id: $request_id,
				raw_reference: array( 'yandex' => $fields )
			);
		} catch ( YandexDeliveryApiException $exception ) {
			$details = $exception->details();
			if ( 'request_info_after_confirm_failed' === (string) ( $details['error_code'] ?? '' ) && '' !== trim( (string) ( $details['confirmed_request_id'] ?? '' ) ) ) {
				return new ShipmentCreateResult(
					false,
					error_code: 'request_info_after_confirm_failed',
					error_message: $exception->getMessage(),
					raw_reference: array(
						'yandex_reconciliation' => array(
							'confirmed_request_id' => trim( (string) $details['confirmed_request_id'] ),
							'selected_offer_id' => trim( (string) ( $details['selected_offer_id'] ?? '' ) ),
							'selected_offer_expires_at' => trim( (string) ( $details['selected_offer_expires_at'] ?? '' ) ),
							'selected_offer_pricing' => trim( (string) ( $details['selected_offer_pricing'] ?? '' ) ),
							'selected_offer_pricing_total' => trim( (string) ( $details['selected_offer_pricing_total'] ?? '' ) ),
							'selected_offer_pricing_total_kopecks' => max( 0, (int) ( $details['selected_offer_pricing_total_kopecks'] ?? 0 ) ),
							'selected_offer_delivery_interval' => is_array( $details['selected_offer_delivery_interval'] ?? null ) ? $details['selected_offer_delivery_interval'] : array(),
							'selected_offer_pickup_interval' => is_array( $details['selected_offer_pickup_interval'] ?? null ) ? $details['selected_offer_pickup_interval'] : array(),
							'selected_offer_snapshot' => is_array( $details['selected_offer_snapshot'] ?? null ) ? $details['selected_offer_snapshot'] : array(),
							'yandex_operator_request_id' => (string) ( $request->meta['yandex_operator_request_id'] ?? '' ),
							'yandex_registration_sequence_index' => (int) ( $request->meta['yandex_registration_sequence_index'] ?? 0 ),
							'yandex_registration_attempt_started_at' => (string) ( $request->meta['yandex_registration_attempt_started_at'] ?? '' ),
							'yandex_temporary_barcode_prefix' => (string) ( $request->meta['yandex_temporary_barcode_prefix'] ?? '' ),
							'registration_phase' => (string) ( $details['registration_phase'] ?? 'request_info' ),
							'error_code' => 'request_info_after_confirm_failed',
							'error_message' => $exception->getMessage(),
							'api_error_details' => $details,
							'reconciliation_required' => true,
						),
					)
				);
			}
			if ( $this->is_duplicate_operator_error( $exception ) ) {
				$operator_request_id = (string) ( $request->meta['yandex_operator_request_id'] ?? '' );
				return new ShipmentCreateResult(
					false,
					error_code: 'yandex_operator_request_id_duplicate',
					error_message: '' !== $operator_request_id
						? 'Яндекс уже использовал номер регистрации ' . $operator_request_id . '. При следующей попытке будет сформирован новый номер.'
						: 'Номер попытки уже использовался в Яндекс.Доставке. Удалите локальную запись и повторите создание — будет сформирован следующий номер.',
					raw_reference: array_merge( $details, array( 'yandex_operator_request_id' => $operator_request_id ) )
				);
			}
			return new ShipmentCreateResult( false, error_code: (string) ( $details['error_code'] ?? 'yandex_api_error' ), error_message: $exception->getMessage(), raw_reference: $details );
		} catch ( Throwable $exception ) {
			return new ShipmentCreateResult( false, error_code: 'yandex_shipment_registration_failed', error_message: $exception->getMessage() );
		}
	}

	/** @return array<string,mixed> */
	public function update_status( object $order ): array {
		$shipment = $this->repository->find( $order );
		$request_id = $this->request_id( $shipment );
		if ( '' === $request_id ) {
			return array( 'success' => false, 'message' => 'request_id Яндекс.Доставки не найден.' );
		}
		try {
			$info = $this->client->request_info( $request_id, $this->temporary_places_for_request_info( $shipment ) );
			$was_reconciliation = ! empty( $shipment['yandex_reconciliation_required'] ) || 'reconciliation_required' === (string) ( $shipment['status'] ?? '' );
			$was_cancel_pending = ! empty( $shipment['yandex_cancel_requested'] ) || 'cancellation_started' === (string) ( $shipment['status'] ?? '' );
			$local_status = $was_cancel_pending && ! $this->is_terminal_status( $info->status ) ? 'cancellation_started' : 'created';
			$updated = $this->merge_info( $shipment, $info, $local_status );
			if ( $was_cancel_pending && ! $this->is_terminal_status( $info->status ) ) {
				$updated['yandex_cancel_requested'] = true;
				$updated['status_title'] = 'Запрос на отмену отправления Яндекс отправлен';
			}
			if ( $was_cancel_pending && $this->is_terminal_status( $info->status ) ) {
				$this->apply_terminal_cancel_resolution( $order, $shipment, $updated, $info->status );
				if ( $this->is_cancelled_status( $info->status ) ) {
					$this->repository->delete( $order );
					return array( 'success' => true, 'message' => 'Отправление Яндекс отменено.', 'status' => $info->status, 'cancelled_and_removed' => true );
				}
			} elseif ( $was_reconciliation ) {
				$this->add_order_note( $order, 'Данные отправления Яндекс восстановлены. Статус: ' . $info->status . '.' );
			} elseif ( (string) ( $shipment['yandex_last_noted_status'] ?? '' ) !== $info->status ) {
				$updated['yandex_last_noted_status'] = $info->status;
				$this->add_order_note( $order, 'Статус отправления Яндекс обновлён: ' . $info->status );
			}
			$this->repository->save( $order, $updated );

			return array( 'success' => true, 'message' => 'Статус Яндекс.Доставки обновлён.', 'status' => $info->status );
		} catch ( YandexDeliveryApiException $exception ) {
			if ( $this->is_reconciliation_pending( $shipment ) && $this->is_temporary_request_info_error( $exception ) ) {
				$was_exhausted = ! empty( $shipment['yandex_reconciliation_poll_exhausted'] );
				$updated = array_merge(
					$shipment,
					array(
						'yandex_reconciliation_required' => true,
						'yandex_registration_phase' => 'request_info',
						'yandex_registration_error_code' => (string) ( $exception->details()['error_code'] ?? '' ),
						'yandex_registration_error_message' => $this->temporary_request_info_message( (string) ( $exception->details()['error_code'] ?? '' ) ),
						'yandex_registration_error_details' => $exception->details(),
						'status' => 'reconciliation_required',
						'status_title' => $was_exhausted ? (string) ( $shipment['status_title'] ?? 'Статус пока не получен. Повторите обновление позднее.' ) : 'Ожидается получение статуса',
						'updated_at' => $this->now(),
					)
				);
				$this->repository->save( $order, $updated );
				return array( 'success' => true, 'pending' => true, 'retryable' => true, 'message' => $updated['yandex_registration_error_message'], 'status' => '' );
			}
			if ( ! empty( $shipment['yandex_cancel_requested'] ) && 'cancellation_started' === (string) ( $shipment['status'] ?? '' ) && $this->is_temporary_request_info_error( $exception ) ) {
				return array( 'success' => true, 'pending' => true, 'retryable' => true, 'message' => 'Статус отмены Яндекс ещё не подготовлен.', 'status' => '' );
			}
			return array( 'success' => false, 'message' => $exception->getMessage(), 'details' => $exception->details() );
		}
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function attach_manual( object $order, array $payload ): array {
		$request_id = $this->manual_request_id( $payload );
		if ( '' === $request_id ) {
			return array( 'success' => false, 'message' => 'Введите Request ID Яндекс.' );
		}
		if ( array() !== $this->repository->find( $order ) ) {
			return array( 'success' => false, 'message' => 'По заказу уже сохранено отправление Яндекс.' );
		}

		try {
			$info = $this->client->request_info( $request_id );
		} catch ( YandexDeliveryApiException $exception ) {
			return array( 'success' => false, 'message' => $this->manual_attach_api_error_message( $exception ), 'details' => $exception->details() );
		}

		$expected_operator_request_id = $this->expected_operator_request_id( $order );
		if ( array() !== $this->repository->find( $order ) ) {
			return array( 'success' => false, 'message' => 'По заказу уже сохранено отправление Яндекс.' );
		}

		$parsed = $this->repository->parse_operator_request_id( $info->operator_request_id, $expected_operator_request_id );
		$shipment = $this->persistence_mapper->build_manual_attach_fields( $order, $info, $this->now() );
		if ( null !== $parsed ) {
			$shipment['yandex_registration_sequence_index'] = $parsed['index'];
		}
		$this->repository->save( $order, $shipment );
		if ( null !== $parsed ) {
			$this->repository->sync_sequence_from_operator_request_id( $order, $info->operator_request_id, $expected_operator_request_id, $this->now() );
		}
		$this->add_order_note( $order, 'Отправление Яндекс добавлено в заказ. Request ID: ' . $info->request_id . '.' );

		return array(
			'success' => true,
			'message' => 'Отправление Яндекс добавлено в заказ.',
			'tracking_number' => '' !== $info->courier_order_id ? $info->courier_order_id : $info->request_id,
			'backlog_order_id' => $info->request_id,
			'shipment' => $shipment,
		);
	}

	/** @return array<string,mixed> */
	public function cancel( object $order ): array {
		$shipment = $this->repository->find( $order );
		$request_id = $this->request_id( $shipment );
		if ( '' === $request_id ) {
			return array( 'success' => false, 'message' => 'request_id Яндекс.Доставки не найден.' );
		}
		try {
			$cancel_state = $this->client->cancel_request( $request_id );
			$cancel_started = $this->mark_cancel_started( $shipment, $cancel_state->raw );
			$this->repository->save( $order, $cancel_started );
			try {
				$info = $this->client->request_info( $request_id, $this->temporary_places_for_request_info( $cancel_started ) );
			} catch ( YandexDeliveryApiException $info_exception ) {
				if ( empty( $shipment['yandex_cancel_started_noted'] ) ) {
					$cancel_started['yandex_cancel_started_noted'] = true;
					$this->repository->save( $order, $cancel_started );
					$this->add_order_note( $order, 'Запрос на отмену отправления Яндекс отправлен. Текущий статус пока не получен.' );
				}
				return array(
					'success' => true,
					'accepted' => true,
					'cancellation_started' => true,
					'request_id' => $request_id,
					'message' => 'Запрос на отмену отправления Яндекс отправлен.',
					'details' => $info_exception->details(),
					'auto_poll' => true,
					'poll_interval_ms' => 5000,
					'poll_max_attempts' => 14,
					'poll_purpose' => 'cancellation',
				);
			}
			$updated = $this->merge_info( $cancel_started, $info, $this->is_terminal_status( $info->status ) ? 'created' : 'cancellation_started' );
			$updated['yandex_cancel_state'] = $cancel_state->raw;
			if ( $this->is_terminal_status( $info->status ) ) {
				$this->apply_terminal_cancel_resolution( $order, $shipment, $updated, $info->status );
				if ( $this->is_cancelled_status( $info->status ) ) {
					$this->repository->save( $order, $updated );
					$this->repository->delete( $order );
					return array( 'success' => true, 'accepted' => false, 'cancelled_and_removed' => true, 'message' => 'Отправление Яндекс отменено.', 'status' => $info->status );
				}
			} else {
				$updated['yandex_cancel_requested'] = true;
				$updated['status'] = 'cancellation_started';
				$updated['status_title'] = 'Запрос на отмену отправления Яндекс отправлен';
				if ( empty( $shipment['yandex_cancel_started_noted'] ) ) {
					$updated['yandex_cancel_started_noted'] = true;
					$this->add_order_note( $order, 'Запрос на отмену отправления Яндекс отправлен. Текущий статус: ' . $info->status . '.' );
				}
			}
			$this->repository->save( $order, $updated );

			return array( 'success' => true, 'accepted' => true, 'cancellation_started' => ! $this->is_terminal_status( $info->status ), 'request_id' => $request_id, 'message' => 'Запрос на отмену отправления Яндекс отправлен.', 'status' => $info->status, 'auto_poll' => true, 'poll_interval_ms' => 5000, 'poll_max_attempts' => 14, 'poll_purpose' => 'cancellation' );
		} catch ( YandexDeliveryApiException $exception ) {
			return array( 'success' => false, 'message' => $exception->getMessage(), 'details' => $exception->details() );
		}
	}

	/** @return array<string,mixed> */
	public function history( object $order ): array {
		$shipment = $this->repository->find( $order );
		$request_id = $this->request_id( $shipment );
		if ( '' === $request_id ) {
			return array( 'success' => false, 'message' => 'request_id Яндекс.Доставки не найден.' );
		}
		try {
			$history = $this->client->request_history( $request_id );
			return array( 'success' => true, 'events' => $history->events );
		} catch ( YandexDeliveryApiException $exception ) {
			return array( 'success' => false, 'message' => $exception->getMessage(), 'details' => $exception->details() );
		}
	}

	/** @return array<string,mixed> */
	public function remove_local( object $order ): array {
		$shipment = $this->repository->find( $order );
		$policy = $this->button_policy->resolve( $shipment );
		if ( empty( $policy['remove'] ) ) {
			return array( 'success' => false, 'message' => 'Текущее отправление Яндекс нельзя удалить из заказа.' );
		}

		$this->repository->delete( $order );
		$this->add_order_note( $order, 'Локальная запись отправления Яндекс удалена.' );

		return array( 'success' => true, 'message' => 'Отправление Яндекс удалено из заказа.' );
	}

	/** @return array<string,mixed> */
	public function mark_polling_exhausted( object $order, int $attempts, string $purpose = 'registration' ): array {
		$shipment = $this->repository->find( $order );
		$request_id = $this->request_id( $shipment );
		if ( 'cancellation' === $purpose ) {
			if ( '' === $request_id || 'cancellation_started' !== (string) ( $shipment['status'] ?? '' ) ) {
				return array( 'success' => false, 'message' => 'Отмена Яндекс не ожидает polling.' );
			}
			$updated = array_merge(
				$shipment,
				array(
					'yandex_cancel_poll_exhausted' => true,
					'yandex_cancel_poll_attempts' => max( 0, $attempts ),
					'yandex_cancel_poll_exhausted_at' => $this->now(),
					'status' => 'cancellation_started',
					'status_title' => 'Статус отмены пока не получен. Повторите обновление позднее.',
					'updated_at' => $this->now(),
				)
			);
			$this->repository->save( $order, $updated );
			return array( 'success' => true, 'message' => 'Статус отмены пока не получен. Повторите обновление позднее.', 'shipment' => $updated );
		}
		if ( '' === $request_id || ! $this->is_reconciliation_pending( $shipment ) ) {
			return array( 'success' => false, 'message' => 'Pending-отправление Яндекс не найдено.' );
		}

		$updated = array_merge(
			$shipment,
			array(
				'yandex_reconciliation_required' => true,
				'yandex_reconciliation_poll_exhausted' => true,
				'yandex_reconciliation_attempts' => max( 0, $attempts ),
				'yandex_reconciliation_poll_exhausted_at' => $this->now(),
				'status' => 'reconciliation_required',
				'status_title' => 'Статус пока не получен. Повторите обновление позднее.',
				'updated_at' => $this->now(),
			)
		);
		$this->repository->save( $order, $updated );

		return array(
			'success' => true,
			'message' => 'Статус отправления пока не получен. Повторите обновление статуса позднее.',
			'shipment' => $updated,
		);
	}

	private function allocation( ShipmentCreateRequest $request ): \WallsShop\WDC\Shipments\Allocation\ShipmentAllocation {
		$rows = is_array( $request->meta['shipment_item_rows'] ?? null ) ? $request->meta['shipment_item_rows'] : array();
		if ( array() === $rows && is_array( $request->meta['yandex_item_rows'] ?? null ) ) {
			$rows = $request->meta['yandex_item_rows'];
		}
		if ( array() === $rows && is_array( $request->meta['cdek_item_rows'] ?? null ) ) {
			$rows = $request->meta['cdek_item_rows'];
		}
		if ( array() === $rows ) {
			$rows = $this->rows_from_places( $request->places );
		}

		return ( new CdekShipmentAllocationAdapter() )->from_cdek_rows( $request->places, $rows );
	}

	private function register_request( ShipmentCreateRequest $request ): YandexDeliveryShipmentRegistrationResult {
		$allocation = $this->allocation( $request );
		$context = $this->context( $request );
		$payload = $this->payload_builder->build( $allocation, $context );
		$offers = $this->client->create_offers( $payload );
		$selected = ( new YandexDeliveryEarliestOfferSelector() )->select( $offers, (string) ( $payload['last_mile_policy'] ?? '' ) );
		if ( ! $selected instanceof YandexDeliveryOffer ) {
			throw new YandexDeliveryApiException(
				'Yandex offers/create returned no matching offers.',
				array( 'error_code' => 'empty_matching_offers', 'last_mile_policy' => (string) ( $payload['last_mile_policy'] ?? '' ) )
			);
		}
		$confirmed = $this->client->confirm_offer( $selected );
		try {
			$info = $this->client->request_info( $confirmed->request_id, is_array( $payload['places'] ?? null ) ? $payload['places'] : array() );
		} catch ( YandexDeliveryApiException $exception ) {
			throw new YandexDeliveryApiException(
				$exception->getMessage(),
				array_merge(
					$exception->details(),
					array(
						'error_code' => 'request_info_after_confirm_failed',
						'registration_phase' => 'request_info',
						'confirmed_request_id' => $confirmed->request_id,
						'selected_offer_id' => $selected->offer_id,
						'selected_offer_expires_at' => $selected->expires_at,
						'selected_offer_pricing' => $selected->pricing,
						'selected_offer_pricing_total' => (string) ( $selected->raw['offer_details']['pricing_total'] ?? $selected->raw['pricing_total'] ?? '' ),
						'selected_offer_pricing_total_kopecks' => $selected->pricing_total_kopecks,
						'selected_offer_delivery_interval' => array(
							'min' => $selected->delivery_interval_min,
							'max' => $selected->delivery_interval_max,
							'policy' => $selected->last_mile_policy,
						),
						'selected_offer_pickup_interval' => array(
							'min' => $selected->pickup_interval_min,
							'max' => $selected->pickup_interval_max,
						),
						'selected_offer_snapshot' => $selected->raw,
					)
				),
				0,
				$exception
			);
		}

		return new YandexDeliveryShipmentRegistrationResult( $payload, $offers, $selected, $confirmed, $info );
	}

	/** @param array<int,ShipmentPlace> $places @return array<int,array<string,mixed>> */
	private function rows_from_places( array $places ): array {
		$rows = array();
		foreach ( $places as $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				continue;
			}
			foreach ( $place->items as $index => $item ) {
				if ( ! $item instanceof PackageItem ) {
					continue;
				}
				$item_key = 'place-' . $place->place_number . '-item-' . ( $index + 1 );
				$rows[] = array(
					'item_key' => $item_key,
					'ordered_quantity' => $item->quantity,
					'place_number' => $place->place_number,
					'name' => $item->name,
					'ware_key' => $item->sku,
					'amount' => $item->quantity,
					'cost' => $item->unit_price->get_rubles(),
					'weight' => max( 1, $item->weight_g ),
				);
			}
		}

		return $rows;
	}

	/** @return array<string,mixed> */
	private function context( ShipmentCreateRequest $request ): array {
		$delivery_type = DeliveryType::PICKUP === $request->delivery_type ? DeliveryType::PICKUP : DeliveryType::COURIER;

		return array(
			'operator_request_id' => (string) ( $request->meta['yandex_operator_request_id'] ?? $request->meta['order_num'] ?? $request->order_id ),
			'temporary_barcode_prefix' => (string) ( $request->meta['yandex_temporary_barcode_prefix'] ?? $this->repository->temporary_barcode_prefix( (string) ( $request->meta['yandex_operator_request_id'] ?? $request->meta['order_num'] ?? $request->order_id ) ) ),
			'source_platform_station_id' => (string) ( $request->meta['yandex_source_platform_station_id'] ?? $request->meta['source_platform_station_id'] ?? '' ),
			'ready_from' => $this->date_time( $request->meta['yandex_ready_from'] ?? null ),
			'ready_to' => $this->date_time( $request->meta['yandex_ready_to'] ?? $request->meta['yandex_ready_from'] ?? null ),
			'recipient' => $this->recipient( $request ),
			'destination' => DeliveryType::PICKUP === $delivery_type
				? array( 'mode' => 'pickup', 'platform_station_id' => (string) ( $request->meta['yandex_pickup_platform_station_id'] ?? $request->meta['pickup_point_code'] ?? $request->pickup_point?->point_code ?? '' ) )
				: array( 'mode' => 'courier', 'details' => $this->courier_details( $request ) ),
		);
	}

	private function date_time( mixed $value ): DateTimeInterface {
		if ( $value instanceof DateTimeInterface ) {
			return $value;
		}
		$text = trim( (string) $value );
		if ( '' !== $text ) {
			return new DateTimeImmutable( $text );
		}

		return new DateTimeImmutable( 'tomorrow 12:00:00', new DateTimeZone( 'Asia/Novosibirsk' ) );
	}

	/** @return array<string,mixed> */
	private function recipient( ShipmentCreateRequest $request ): array {
		$first = trim( (string) ( $request->meta['yandex_recipient_first_name'] ?? '' ) );
		$last = trim( (string) ( $request->meta['yandex_recipient_last_name'] ?? '' ) );
		if ( '' === $first && '' === $last ) {
			$parts = preg_split( '/\s+/', trim( (string) ( $request->recipient['name'] ?? '' ) ) ) ?: array();
			$last = (string) ( $parts[0] ?? '' );
			$first = trim( implode( ' ', array_slice( $parts, 1 ) ) );
		}

		return array(
			'first_name' => $first,
			'last_name' => $last,
			'phone' => (string) ( $request->recipient['phone'] ?? '' ),
			'email' => (string) ( $request->recipient['email'] ?? '' ),
		);
	}

	/** @return array<string,mixed> */
	private function courier_details( ShipmentCreateRequest $request ): array {
		if ( is_array( $request->meta['yandex_courier_details'] ?? null ) ) {
			return $request->meta['yandex_courier_details'];
		}

		return array(
			'country' => 'Россия',
			'region' => $request->recipient_address->region_name,
			'locality' => $request->recipient_address->city,
			'street' => $request->recipient_address->street ?: $request->recipient_address->raw_address,
			'house' => (string) ( $request->meta['yandex_house'] ?? '' ),
			'room' => $request->recipient_address->apartment,
			'full_address' => $request->recipient_address->raw_address,
			'postal_code' => $request->recipient_address->postcode,
		);
	}

	/** @return array<string,mixed> */
	private function fields_from_result( YandexDeliveryShipmentRegistrationResult $result ): array {
		$fields = $this->fields_from_info( $result->request_info );
		$fields['yandex_operator_request_id'] = '' !== $result->request_info->operator_request_id ? $result->request_info->operator_request_id : (string) ( $result->payload['info']['operator_request_id'] ?? '' );
		$fields['operator_request_id'] = $fields['yandex_operator_request_id'];
		$fields['yandex_selected_offer_id'] = $result->selected_offer->offer_id;
		$fields['yandex_offer_expires_at'] = $result->selected_offer->expires_at;
		$fields['yandex_offer_pricing'] = $result->selected_offer->pricing;
		$fields['yandex_offer_pricing_total'] = (string) ( $result->selected_offer->raw['offer_details']['pricing_total'] ?? $result->selected_offer->raw['pricing_total'] ?? '' );
		$fields['yandex_offer_pricing_total_kopecks'] = $result->selected_offer->pricing_total_kopecks;
		$fields['yandex_offer_delivery_interval'] = array(
			'min' => $result->selected_offer->delivery_interval_min,
			'max' => $result->selected_offer->delivery_interval_max,
			'policy' => $result->selected_offer->last_mile_policy,
		);
		$fields['yandex_offer_pickup_interval'] = array(
			'min' => $result->selected_offer->pickup_interval_min,
			'max' => $result->selected_offer->pickup_interval_max,
		);
		$fields['yandex_selected_offer_snapshot'] = $result->selected_offer->raw;

		return $fields;
	}

	/** @return array<string,mixed> */
	private function fields_from_info( YandexDeliveryRequestInfo $info ): array {
		return $this->persistence_mapper->fields_from_info( $info );
	}

	/** @param array<string,mixed> $shipment */
	private function merge_info( array $shipment, YandexDeliveryRequestInfo $info, string $local_status = 'created' ): array {
		$fields = $this->fields_from_info( $info );
		if ( array() === $fields['yandex_place_barcode_map'] && is_array( $shipment['yandex_place_barcode_map'] ?? null ) ) {
			$fields['yandex_place_barcode_map'] = $shipment['yandex_place_barcode_map'];
		}

		return array_merge(
			$shipment,
			$fields,
			array(
				'status' => $local_status,
				'status_title' => $info->status,
				'yandex_reconciliation_required' => false,
				'yandex_reconciliation_poll_exhausted' => false,
				'yandex_reconciliation_attempts' => 0,
				'yandex_reconciliation_poll_exhausted_at' => '',
				'yandex_registration_phase' => '',
				'yandex_registration_error_code' => '',
				'yandex_registration_error_message' => '',
				'yandex_registration_error_details' => array(),
				'updated_at' => $this->now(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @param array<string,mixed> $cancel_state
	 * @return array<string,mixed>
	 */
	private function mark_cancel_started( array $shipment, array $cancel_state ): array {
		$reason = trim( (string) ( $cancel_state['reason'] ?? '' ) );
		$description = trim( (string) ( $cancel_state['description'] ?? '' ) );

		return array_merge(
			$shipment,
			array(
				'yandex_cancel_state' => $cancel_state,
				'yandex_cancel_requested' => true,
				'yandex_cancel_reason' => $reason,
				'yandex_cancel_description' => $description,
				'yandex_cancel_requested_at' => $this->now(),
				'status' => 'cancellation_started',
				'status_title' => 'Запрос на отмену отправления Яндекс отправлен',
				'updated_at' => $this->now(),
			)
		);
	}

	private function is_terminal_status( string $status ): bool {
		return YandexShipmentButtonPolicy::is_terminal_status( $status );
	}

	private function is_cancelled_status( string $status ): bool {
		return 'CANCELLED' === strtoupper( trim( $status ) );
	}

	/** @param array<string,mixed> $shipment */
	private function is_reconciliation_pending( array $shipment ): bool {
		return ! empty( $shipment['yandex_reconciliation_required'] ) || 'reconciliation_required' === (string) ( $shipment['status'] ?? '' );
	}

	private function is_temporary_request_info_error( YandexDeliveryApiException $exception ): bool {
		return in_array(
			(string) ( $exception->details()['error_code'] ?? '' ),
			array( 'request_info_status_missing', 'request_info_request_missing' ),
			true
		);
	}

	private function temporary_request_info_message( string $error_code ): string {
		return match ( $error_code ) {
			'request_info_request_missing' => 'Яндекс ещё не подготовил полные данные созданного отправления.',
			default => 'Яндекс ещё не подготовил статус созданного отправления.',
		};
	}

	/**
	 * @param array<string,mixed> $previous
	 * @param array<string,mixed> $updated
	 */
	private function apply_terminal_cancel_resolution( object $order, array $previous, array &$updated, string $status ): void {
		$status = strtoupper( trim( $status ) );
		$updated['yandex_cancel_requested'] = false;
		$updated['status'] = 'created';
		if ( $this->is_cancelled_status( $status ) ) {
			$updated['yandex_cancel_completed'] = true;
			if ( empty( $previous['yandex_cancel_completed_noted'] ) ) {
				$updated['yandex_cancel_completed_noted'] = true;
				$this->add_order_note( $order, 'Отправление Яндекс отменено.' );
			}
			return;
		}
		$updated['yandex_cancel_terminal_status'] = $status;
		if ( empty( $previous['yandex_cancel_terminal_noted'] ) ) {
			$updated['yandex_cancel_terminal_noted'] = true;
			$this->add_order_note( $order, 'Получен терминальный статус Яндекс: ' . $status . '. Операция отмены более не ожидается.' );
		}
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<int,array<string,string>>
	 */
	private function temporary_places_for_request_info( array $shipment ): array {
		if ( is_array( $shipment['yandex_place_barcode_map'] ?? null ) && array() !== $shipment['yandex_place_barcode_map'] ) {
			$places = array();
			foreach ( array_keys( $shipment['yandex_place_barcode_map'] ) as $barcode ) {
				$barcode = trim( (string) $barcode );
				if ( '' !== $barcode ) {
					$places[] = array( 'barcode' => $barcode );
				}
			}
			if ( array() !== $places ) {
				return $places;
			}
		}
		$operator_request_id = trim( (string) ( $shipment['yandex_operator_request_id'] ?? $shipment['order_num'] ?? '' ) );
		$temporary_prefix = trim( (string) ( $shipment['yandex_temporary_barcode_prefix'] ?? '' ) );
		if ( '' === $temporary_prefix && '' !== $operator_request_id ) {
			$temporary_prefix = $this->repository->temporary_barcode_prefix( $operator_request_id );
		}
		$stored_places = is_array( $shipment['places'] ?? null ) ? $shipment['places'] : array();
		if ( '' === $temporary_prefix || array() === $stored_places ) {
			return array();
		}

		$places = array();
		foreach ( array_values( $stored_places ) as $index => $place ) {
			if ( ! is_array( $place ) ) {
				continue;
			}
			$place_number = max( 1, (int) ( $place['place_number'] ?? $place['number'] ?? ( $index + 1 ) ) );
			$places[] = array( 'barcode' => $temporary_prefix . '-' . (string) $place_number );
		}

		return $places;
	}

	/** @param array<string,mixed> $shipment */
	private function request_id( array $shipment ): string {
		foreach ( array( 'yandex_request_id', 'request_id', 'external_id' ) as $key ) {
			$value = trim( (string) ( $shipment[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function add_order_note( object $order, string $message ): void {
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $message );
		}
	}

	/** @param array<string,mixed> $payload */
	private function manual_request_id( array $payload ): string {
		foreach ( array( 'request_id', 'barcode', 'tracking_number' ) as $key ) {
			$value = trim( (string) ( $payload[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function expected_operator_request_id( object $order ): string {
		if ( method_exists( $order, 'get_order_number' ) ) {
			return trim( (string) $order->get_order_number() );
		}

		return (string) $this->repository->order_id( $order );
	}

	private function operator_request_id_belongs_to_order( string $operator_request_id, string $base ): bool {
		return null !== $this->repository->parse_operator_request_id( $operator_request_id, $base );
	}

	private function is_duplicate_operator_error( YandexDeliveryApiException $exception ): bool {
		$haystack = strtolower(
			$exception->getMessage()
			. ' ' . (string) ( $exception->details()['yandex_error_message'] ?? '' )
			. ' ' . (string) ( $exception->details()['error_body'] ?? '' )
			. ' ' . (string) ( $exception->details()['yandex_error_code'] ?? '' )
		);

		return str_contains( $haystack, 'already was request with such code' )
			|| ( str_contains( $haystack, 'such code' ) && str_contains( $haystack, 'employer' ) )
			|| str_contains( $haystack, 'duplicate' );
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function request_with_meta( ShipmentCreateRequest $request, array $meta ): ShipmentCreateRequest {
		return new ShipmentCreateRequest(
			$request->order_id,
			$request->carrier_key,
			$request->delivery_type,
			$request->rate_id,
			$request->recipient_address,
			$request->pickup_point,
			$request->places,
			$request->declared_value,
			$request->insurance_enabled,
			$request->services,
			$request->recipient,
			array_merge( $request->meta, $meta )
		);
	}

	private function manual_attach_api_error_message( YandexDeliveryApiException $exception ): string {
		if ( 404 === $exception->http_code() ) {
			return 'Отправление Яндекс с указанным Request ID не найдено.';
		}

		return 'Не удалось получить данные отправления Яндекс.';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
