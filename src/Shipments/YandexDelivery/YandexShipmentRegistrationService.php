<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Throwable;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
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
	public function __construct(
		private CoreYandexShipmentRegistrationService $registration,
		private YandexDeliveryShipmentPayloadBuilder $payload_builder,
		private YandexDeliveryShipmentClient $client,
		private YandexShipmentRepository $repository
	) {
	}

	/** @return array<string,mixed> */
	public function build_preview_payload( ShipmentCreateRequest $request ): array {
		return $this->payload_builder->build( $this->allocation( $request ), $this->context( $request ) );
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		try {
			$result = $this->registration->register( $this->allocation( $request ), $this->context( $request ) );
			$fields = $this->fields_from_result( $result );
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
							'registration_phase' => (string) ( $details['registration_phase'] ?? 'request_info' ),
							'error_code' => 'request_info_after_confirm_failed',
							'error_message' => $exception->getMessage(),
							'api_error_details' => $details,
							'reconciliation_required' => true,
						),
					)
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
			$local_status = $was_cancel_pending && ! $this->is_terminal_cancel_status( $info->status ) ? 'cancellation_started' : 'created';
			$updated = $this->merge_info( $shipment, $info, $local_status );
			if ( $was_cancel_pending && ! $this->is_terminal_cancel_status( $info->status ) ) {
				$updated['yandex_cancel_requested'] = true;
				$updated['status_title'] = 'Запрос на отмену отправления Яндекс отправлен';
			}
			if ( $was_cancel_pending && $this->is_terminal_cancel_status( $info->status ) ) {
				$updated['yandex_cancel_requested'] = false;
				$updated['yandex_cancel_completed'] = true;
				if ( empty( $shipment['yandex_cancel_completed_noted'] ) ) {
					$updated['yandex_cancel_completed_noted'] = true;
					$this->add_order_note( $order, 'Отправление Яндекс отменено.' );
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
			return array( 'success' => false, 'message' => $exception->getMessage(), 'details' => $exception->details() );
		}
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
				return array( 'success' => true, 'accepted' => true, 'message' => 'Запрос на отмену отправлен, но актуальный статус пока не получен.', 'details' => $info_exception->details() );
			}
			$updated = $this->merge_info( $cancel_started, $info, $this->is_terminal_cancel_status( $info->status ) ? 'created' : 'cancellation_started' );
			$updated['yandex_cancel_state'] = $cancel_state->raw;
			if ( $this->is_terminal_cancel_status( $info->status ) ) {
				$updated['yandex_cancel_requested'] = false;
				$updated['yandex_cancel_completed'] = true;
				if ( empty( $shipment['yandex_cancel_completed_noted'] ) ) {
					$updated['yandex_cancel_completed_noted'] = true;
					$this->add_order_note( $order, 'Отправление Яндекс отменено.' );
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

			return array( 'success' => true, 'accepted' => true, 'message' => 'Отмена Яндекс.Доставки запрошена.', 'status' => $info->status );
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
		$this->repository->delete( $order );
		$this->add_order_note( $order, 'Локальная запись отправления Яндекс удалена.' );

		return array( 'success' => true, 'message' => 'Отправление Яндекс удалено из заказа.' );
	}

	private function allocation( ShipmentCreateRequest $request ): \WallsShop\WDC\Shipments\Allocation\ShipmentAllocation {
		$rows = is_array( $request->meta['yandex_item_rows'] ?? null ) ? $request->meta['yandex_item_rows'] : array();
		if ( array() === $rows && is_array( $request->meta['cdek_item_rows'] ?? null ) ) {
			$rows = $request->meta['cdek_item_rows'];
		}
		if ( array() === $rows ) {
			$rows = $this->rows_from_places( $request->places );
		}

		return ( new CdekShipmentAllocationAdapter() )->from_cdek_rows( $request->places, $rows );
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
		$fields['yandex_selected_offer_id'] = $result->selected_offer->offer_id;
		$fields['yandex_offer_expires_at'] = $result->selected_offer->expires_at;

		return $fields;
	}

	/** @return array<string,mixed> */
	private function fields_from_info( YandexDeliveryRequestInfo $info ): array {
		return array(
			'yandex_request_id' => $info->request_id,
			'request_id' => $info->request_id,
			'yandex_courier_order_id' => $info->courier_order_id,
			'courier_order_id' => $info->courier_order_id,
			'yandex_sharing_url' => $info->sharing_url,
			'sharing_url' => $info->sharing_url,
			'yandex_status' => $info->status,
			'yandex_operator_request_id' => $info->operator_request_id,
			'yandex_delivery_policy' => $info->delivery_policy,
			'yandex_destination' => $info->destination,
			'yandex_recipient' => $info->recipient,
			'yandex_items' => $info->items,
			'yandex_places' => $info->places,
			'yandex_place_barcode_map' => $info->place_barcode_map,
			'yandex_request_info_snapshot' => $info->raw,
			'yandex_available_actions' => $info->available_actions,
			'yandex_full_items_price_kopecks' => $info->full_items_price_kopecks,
		);
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
				'status_title' => 'Статус Яндекс.Доставки: ' . $info->status,
				'yandex_reconciliation_required' => false,
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

	private function is_terminal_cancel_status( string $status ): bool {
		return 'CANCELLED' === strtoupper( trim( $status ) );
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
		$stored_places = is_array( $shipment['places'] ?? null ) ? $shipment['places'] : array();
		if ( '' === $operator_request_id || array() === $stored_places ) {
			return array();
		}

		$places = array();
		foreach ( array_values( $stored_places ) as $index => $place ) {
			if ( ! is_array( $place ) ) {
				continue;
			}
			$place_number = max( 1, (int) ( $place['place_number'] ?? $place['number'] ?? ( $index + 1 ) ) );
			$places[] = array( 'barcode' => $operator_request_id . '-' . (string) $place_number );
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

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
