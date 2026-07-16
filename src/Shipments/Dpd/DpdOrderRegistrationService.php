<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentPayloadBuilder;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Lifecycle\ShipmentLifecycleResult;

use function sanitize_text_field;

defined( 'ABSPATH' ) || exit;

final class DpdOrderRegistrationService {
	public function __construct(
		private DpdShipmentPayloadBuilder $builder,
		private DpdApiClient $client,
		private DpdShipmentRepository $repository,
		private ?DpdEventSyncService $events = null,
		private ?DpdShipmentEnrichmentService $enrichment = null,
		private ?Logger $logger = null
	) {}

	/** @return array<string,mixed> */
	public function begin( object $order, ShipmentCreateRequest $request ): array {
		$errors = $this->builder->validate( $request );
		if ( array() !== $errors ) { return array( 'success' => false, 'message' => implode( "\n", $errors ) ); }
		$existing = $this->repository->find( $order );
		if ( array() !== $existing ) { return array( 'success' => false, 'message' => 'DPD-отправление уже есть в заказе.' ); }
		$payload = $this->builder->build( $request );
		$sent_places = $this->sent_places_fields( $payload );
		$now = $this->now();
		$token = sha1( $this->repository->order_id( $order ) . '|' . wp_json_encode( $payload ) . '|' . microtime( true ) );
		$shipment = array(
			'carrier_key' => 'dpd', 'service_key' => (string) ( $request->meta['service_key'] ?? $request->rate_id ), 'order_id' => $request->order_id,
			'service_title' => (string) ( $request->meta['service_title'] ?? '' ), 'delivery_type' => $request->delivery_type,
			'places' => array_map( static fn ( $place ): array => $place->to_array(), $request->places ),
			'request_snapshot' => array( 'method' => 'SOAP', 'path' => 'order2/createOrder2', 'body' => $this->sanitize_value( $payload ) ),
			'barcode' => '', 'tracking_number' => '', 'external_id' => '', 'order_num' => (string) ( $request->meta['order_num'] ?? $request->order_id ),
			'dpd_registration_attempt_id' => $token, 'dpd_registration_state' => 'submitting', 'registration_started_at' => $now,
			'dpd_service_code' => (string) ( $request->meta['service_code'] ?? '' ), 'dpd_date_pickup' => (string) ( $request->meta['date_pickup'] ?? '' ),
			'dpd_sender_terminal_code' => (string) ( $request->meta['pickup_terminal_code'] ?? '' ), 'dpd_receiver_terminal_code' => (string) ( $request->meta['delivery_terminal_code'] ?? '' ),
			'created_by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0, 'created_by_context' => 'admin_manual',
			'status' => DeliveryStatus::PENDING_CREATION_IN_CARRIER, 'universal_status_code' => DeliveryStatus::PENDING_CREATION_IN_CARRIER,
			'universal_status_label' => DeliveryStatus::label( DeliveryStatus::PENDING_CREATION_IN_CARRIER ), 'status_title' => 'Ждём регистрацию',
			'created_at' => $now, 'updated_at' => $now,
		);
		$shipment = array_merge( $shipment, $sent_places );
		$this->repository->save( $order, $shipment );
		$lifecycle = new ShipmentLifecycleResult(
			ShipmentLifecycleResult::PHASE_SUBMISSION_REQUIRED,
			accepted: true,
			submit_required: true,
			poll_required: false,
			attempt_id: $token,
			message: 'Ждём регистрацию DPD.',
			poll_interval_ms: 10000,
			poll_max_attempts: 0,
			stop_on_error: true
		);
		return array( 'success' => true, 'message' => 'Ждём регистрацию DPD.', 'shipment' => $shipment, 'lifecycle' => $lifecycle->to_array(), 'status' => array( 'lifecycle' => $lifecycle->to_array() ) );
	}

	/** @return array<string,mixed> */
	public function submit( object $order, string $attempt_id ): array {
		$shipment = $this->repository->find( $order );
		if ( array() === $shipment || $attempt_id !== (string) ( $shipment['dpd_registration_attempt_id'] ?? '' ) ) { return $this->failed_lifecycle_result( 'Локальная попытка регистрации DPD не найдена.' ); }
		if ( '' !== trim( (string) ( $shipment['dpd_order_number'] ?? '' ) ) || 'ok' === (string) ( $shipment['dpd_registration_state'] ?? '' ) ) {
			return array_merge( array( 'success' => true, 'message' => 'Номер DPD уже сохранен.', 'shipment' => $shipment ), $this->lifecycle_payload( new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_COMPLETED, accepted: true, message: 'Номер DPD уже сохранен.' ) ) );
		}
		if ( in_array( (string) ( $shipment['dpd_registration_state'] ?? '' ), array( 'duplicate', 'error', 'cancelled', 'transport_error' ), true ) ) {
			return $this->failed_lifecycle_result( (string) ( $shipment['dpd_registration_error'] ?? $shipment['status_title'] ?? 'Регистрация DPD уже завершена ошибкой.' ), $shipment );
		}
		$payload = is_array( $shipment['request_snapshot']['body'] ?? null ) ? $shipment['request_snapshot']['body'] : array();
		if ( array() === $payload ) {
			return $this->failed_lifecycle_result( 'Локальная попытка регистрации DPD не содержит payload.' );
		}
		$shipment = array_merge( $shipment, $this->sent_places_fields( $payload ), array( 'request_snapshot' => array( 'method' => 'SOAP', 'path' => 'order2/createOrder2', 'body' => $this->sanitize_value( $payload ) ) ) );
		$this->repository->save( $order, $shipment );
		$response = $this->client->createOrder2( $payload );
		$updated = $this->apply_registration_response( $order, $shipment, $response, 'createOrder2' );
		return array_merge( array( 'success' => true ), $updated, $this->lifecycle_payload( $this->lifecycle_from_registration_result( $updated ) ) );
	}

	/** @return array<string,mixed> */
	public function refresh_registration( object $order ): array {
		$shipment = $this->repository->find( $order );
		if ( array() === $shipment ) { return array( 'success' => false, 'message' => 'Сначала создайте отправление DPD.' ); }
		$dpd_order = trim( (string) ( $shipment['dpd_order_number'] ?? '' ) );
		if ( '' !== $dpd_order ) { return array( 'success' => true, 'message' => 'Номер DPD уже сохранен.', 'shipment' => $shipment ); }
		$payload = array( 'order' => array( array_filter( array( 'orderNumberInternal' => (string) ( $shipment['order_num'] ?? $this->repository->order_id( $order ) ), 'datePickup' => (string) ( $shipment['dpd_date_pickup'] ?? '' ) ) ) ) );
		$response = $this->client->getOrderStatus( $payload );
		$updated = $this->apply_registration_response( $order, $shipment, $response, 'getOrderStatus' );
		return array_merge( array( 'success' => true ), $updated, $this->lifecycle_payload( $this->lifecycle_from_registration_result( $updated ) ) );
	}

	/** @return array<string,mixed> */
	public function update_status( object $order ): array {
		$shipment = $this->repository->find( $order );
		if ( array() === $shipment ) { return array( 'success' => false, 'message' => 'Сначала создайте отправление DPD.' ); }
		if ( '' === trim( (string) ( $shipment['dpd_order_number'] ?? '' ) ) ) { return $this->refresh_registration( $order ); }
		$refresh = $this->refresh_created_shipment( $order );
		$sync = $refresh['sync'];
		return array( 'success' => $sync->success, 'message' => $sync->message ?: 'Статус DPD обновлен.', 'event_sync' => $sync->to_array(), 'enrichment' => $refresh['enrichment'], 'shipment' => $refresh['shipment'] );
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function attach_manual( object $order, array $payload ): array {
		if ( array() !== $this->repository->find( $order ) ) { return array( 'success' => false, 'message' => 'DPD-отправление уже есть в заказе.' ); }
		$number = trim( sanitize_text_field( (string) ( $payload['barcode'] ?? $payload['tracking_number'] ?? '' ) ) );
		if ( '' === $number ) { return array( 'success' => false, 'message' => 'Введите номер DPD.' ); }
		$now = $this->now();
		$shipment = array( 'carrier_key' => 'dpd', 'order_id' => $this->repository->order_id( $order ), 'barcode' => $number, 'tracking_number' => $number, 'external_id' => $number, 'dpd_order_number' => $number, 'status' => 'created', 'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER, 'universal_status_label' => DeliveryStatus::label( DeliveryStatus::CREATED_IN_CARRIER ), 'status_title' => 'Номер DPD внесён вручную', 'created_by' => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0, 'created_by_context' => 'admin_manual_attach', 'created_at' => $now, 'updated_at' => $now );
		$this->repository->save( $order, $shipment );
		return array( 'success' => true, 'message' => 'Номер DPD сохранен.', 'shipment' => $shipment );
	}

	/** @return array<string,mixed> */
	public function cancel( object $order ): array {
		$shipment = $this->repository->find( $order );
		$dpd_order = trim( (string) ( $shipment['dpd_order_number'] ?? '' ) );
		if ( '' === $dpd_order ) { return array( 'success' => false, 'message' => 'У отправления нет номера DPD.', 'temporary_can_remove' => true ); }
		$response = $this->client->cancelOrder( array( 'cancel' => array( array_filter( array( 'orderNum' => $dpd_order, 'pickupdate' => (string) ( $shipment['dpd_date_pickup'] ?? '' ) ) ) ) ) );
		$row = $this->first_row( is_array( $response['body'] ?? null ) ? $response['body'] : array() );
		$status = (string) ( $row['status'] ?? '' );
		if ( in_array( $status, array( 'Canceled', 'CanceledPreviously' ), true ) ) { $this->repository->delete( $order ); return array( 'success' => true, 'message' => 'Отправление DPD отменено.' ); }
		$message = (string) ( $row['errorMessage'] ?? $response['error_message'] ?? 'DPD не отменил отправление.' );
		return array( 'success' => false, 'message' => $message, 'temporary_can_remove' => true );
	}

	/** @return array<string,mixed> */
	public function remove_local( object $order ): array { $this->repository->delete( $order ); return array( 'success' => true, 'message' => 'DPD-отправление удалено из заказа.' ); }

	/** @param array<string,mixed> $response @return array<string,mixed> */
	private function apply_registration_response( object $order, array $shipment, array $response, string $source ): array {
		$row = $this->first_row( is_array( $response['body'] ?? null ) ? $response['body'] : array() );
		$status = (string) ( $row['status'] ?? '' );
		$order_num = trim( (string) ( $row['orderNum'] ?? $row['orderNumber'] ?? '' ) );
		$now = $this->now();
		$shipment['response_snapshot'] = $this->sanitize_value( is_array( $response['body'] ?? null ) ? $response['body'] : array() );
		$shipment['updated_at'] = $now;
		if ( empty( $response['success'] ) && 'dpd_order_create_uncertain' !== (string) ( $response['error_code'] ?? '' ) && 'dpd_uncertain_timeout' !== (string) ( $response['error_code'] ?? '' ) ) {
			$shipment['dpd_registration_state'] = 'transport_error'; $shipment['dpd_registration_error'] = (string) ( $response['error_message'] ?? '' ); $shipment['status_title'] = 'Ошибка регистрации'; $this->repository->save( $order, $shipment );
			return array( 'message' => $shipment['dpd_registration_error'], 'shipment' => $shipment, 'registration_error' => true, 'registration_terminal' => true, 'polling_continue' => false );
		}
		if ( '' === $status && '' === $order_num ) { $status = 'OrderPending'; }
		if ( 'OK' === $status && '' !== $order_num ) {
			$shipment['dpd_order_number'] = $order_num; $shipment['tracking_number'] = $order_num; $shipment['barcode'] = $order_num; $shipment['external_id'] = $order_num; $shipment['dpd_registration_state'] = 'ok'; $shipment['status'] = 'created'; $shipment['universal_status_code'] = DeliveryStatus::CREATED_IN_CARRIER; $shipment['universal_status_label'] = DeliveryStatus::label( DeliveryStatus::CREATED_IN_CARRIER ); $shipment['status_title'] = 'Зарегистрировано';
			$this->repository->save( $order, $shipment );
			$refresh = $this->refresh_created_shipment( $order );
			return array( 'message' => 'DPD зарегистрировал отправление.', 'shipment' => $refresh['shipment'], 'event_sync' => $refresh['sync']->to_array(), 'enrichment' => $refresh['enrichment'], 'registration_success' => true, 'registration_terminal' => true, 'polling_continue' => false );
		}
		if ( in_array( $status, array( 'OrderDuplicate', 'OrderError', 'OrderCancelled' ), true ) ) {
			$shipment['dpd_registration_state'] = 'OrderCancelled' === $status ? 'cancelled' : ( 'OrderDuplicate' === $status ? 'duplicate' : 'error' ); $shipment['dpd_registration_error'] = (string) ( $row['errorMessage'] ?? $status ); $shipment['status_title'] = 'OrderCancelled' === $status ? 'Заказ отменён' : 'Ошибка регистрации'; $this->repository->save( $order, $shipment );
			return array( 'message' => $shipment['dpd_registration_error'], 'shipment' => $shipment, 'registration_error' => true, 'registration_terminal' => true, 'polling_continue' => false );
		}
		$shipment['dpd_registration_state'] = 'pending'; $shipment['status'] = DeliveryStatus::PENDING_CREATION_IN_CARRIER; $shipment['universal_status_code'] = DeliveryStatus::PENDING_CREATION_IN_CARRIER; $shipment['universal_status_label'] = DeliveryStatus::label( DeliveryStatus::PENDING_CREATION_IN_CARRIER ); $shipment['status_title'] = 'OrderPending' === $status ? 'Ждёт ручной доработки DPD' : 'Ждём регистрацию'; $this->repository->save( $order, $shipment );
		return array( 'message' => $shipment['status_title'], 'shipment' => $shipment, 'registration_polling' => true, 'polling_continue' => true );
	}

	/** @return array{sync:DpdEventSyncResult,enrichment:array<string,mixed>,shipment:array<string,mixed>} */
	private function refresh_created_shipment( object $order ): array {
		$sync = $this->events instanceof DpdEventSyncService ? $this->events->sync() : new DpdEventSyncResult( true, 'Синхронизация событий DPD недоступна.' );
		$enrichment = $this->enrichment instanceof DpdShipmentEnrichmentService ? $this->enrichment->enrich_current_order( $order ) : array();
		$shipment = $this->touch_tracking_checked( $order );

		return array( 'sync' => $sync, 'enrichment' => $enrichment, 'shipment' => $shipment );
	}

	/** @return array<string,mixed> */
	private function touch_tracking_checked( object $order ): array {
		$shipment = $this->repository->find( $order );
		if ( array() === $shipment ) {
			return $shipment;
		}
		$now = $this->now();
		$shipment['tracking_checked_at'] = $now;
		$shipment['updated_at'] = $now;
		$this->repository->save( $order, $shipment );

		return $this->repository->find( $order );
	}

	/** @param array<string,mixed> $result */
	private function lifecycle_from_registration_result( array $result ): ShipmentLifecycleResult {
		$message = (string) ( $result['message'] ?? '' );
		if ( ! empty( $result['registration_success'] ) ) {
			return new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_COMPLETED, accepted: true, message: $message );
		}
		if ( ! empty( $result['registration_error'] ) ) {
			return new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_FAILED, accepted: false, message: $message );
		}
		if ( ! empty( $result['registration_polling'] ) || ! empty( $result['polling_continue'] ) ) {
			return new ShipmentLifecycleResult(
				ShipmentLifecycleResult::PHASE_POLLING_REQUIRED,
				accepted: true,
				submit_required: false,
				poll_required: true,
				message: $message,
				poll_interval_ms: 10000,
				poll_max_attempts: 0,
				stop_on_error: true
			);
		}

		return new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_TERMINAL, accepted: true, message: $message );
	}

	/** @return array<string,mixed> */
	private function lifecycle_payload( ShipmentLifecycleResult $lifecycle ): array {
		return array( 'lifecycle' => $lifecycle->to_array() );
	}

	/** @return array<string,mixed> */
	private function failed_lifecycle_result( string $message, array $shipment = array() ): array {
		return array(
			'success' => false,
			'message' => $message,
			'shipment' => $shipment,
			'lifecycle' => ( new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_FAILED, accepted: false, message: $message ) )->to_array(),
		);
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	private function sent_places_fields( array $payload ): array {
		$order = is_array( $payload['order'] ?? null ) ? $payload['order'] : array();
		$parcels = is_array( $order['parcel'] ?? null ) ? $order['parcel'] : array();
		$places = array();
		foreach ( $parcels as $index => $parcel ) {
			if ( ! is_array( $parcel ) ) {
				continue;
			}
			$weight_kg = is_numeric( $parcel['weight'] ?? null ) ? (float) $parcel['weight'] : 0.0;
			$length = is_numeric( $parcel['length'] ?? null ) ? (int) $parcel['length'] : 0;
			$width = is_numeric( $parcel['width'] ?? null ) ? (int) $parcel['width'] : 0;
			$height = is_numeric( $parcel['height'] ?? null ) ? (int) $parcel['height'] : 0;
			$places[] = array(
				'number' => (string) ( $parcel['number'] ?? ( $index + 1 ) ),
				'place_number' => (int) ( is_numeric( $parcel['number'] ?? null ) ? $parcel['number'] : ( $index + 1 ) ),
				'weight_kg' => $weight_kg,
				'weight_g' => (int) round( $weight_kg * 1000 ),
				'length_cm' => $length,
				'width_cm' => $width,
				'height_cm' => $height,
				'volume_m3' => round( max( 0, $length ) * max( 0, $width ) * max( 0, $height ) / 1000000, 4 ),
			);
		}

		return array(
			'dpd_sent_places' => $places,
			'dpd_cargo_num_pack' => is_numeric( $order['cargoNumPack'] ?? null ) ? (int) $order['cargoNumPack'] : count( $places ),
			'dpd_cargo_weight' => is_numeric( $order['cargoWeight'] ?? null ) ? (float) $order['cargoWeight'] : 0.0,
			'dpd_cargo_volume' => is_numeric( $order['cargoVolume'] ?? null ) ? (float) $order['cargoVolume'] : 0.0,
		);
	}

	/** @param array<string,mixed> $body @return array<string,mixed> */
	private function first_row( array $body ): array { foreach ( array( 'return', 'order', 'orders' ) as $key ) { $value = $body[ $key ] ?? null; if ( is_array( $value ) ) { return is_array( $value[0] ?? null ) ? $value[0] : $value; } } return $body; }
	private function now(): string { return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ); }
	private function sanitize_value( mixed $value ): mixed { if ( is_array( $value ) ) { $out = array(); foreach ( $value as $key => $item ) { $k = strtolower( (string) $key ); $out[ $key ] = in_array( $k, array( 'clientkey', 'client_key', 'auth', 'phone', 'contactphone', 'contactemail', 'email', 'street', 'house', 'flat' ), true ) ? '[redacted]' : $this->sanitize_value( $item ); } return $out; } return is_string( $value ) && strlen( $value ) > 1000 ? substr( $value, 0, 1000 ) . '...' : $value; }
}
