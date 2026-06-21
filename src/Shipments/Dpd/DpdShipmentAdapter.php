<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentPayloadBuilder;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Contracts\ShipmentCarrierAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentAdapter implements ShipmentCarrierAdapterInterface {
	public function __construct(
		private DpdShipmentPayloadBuilder $builder,
		private ?DpdApiClient $client = null,
		private ?DpdOrderRegistrationService $registration = null,
		private ?DpdShipmentButtonPolicy $buttons = null,
		private ?DpdShipmentEnrichmentService $enrichment = null
	) {
	}

	public function carrier_key(): string {
		return DpdSettings::CARRIER_KEY;
	}

	public function supports( ShipmentCreateRequest $request ): bool {
		return DpdSettings::CARRIER_KEY === $request->carrier_key;
	}

	/**
	 * @return array<string,string>
	 */
	public function presentation(): array {
		return array(
			'carrier_label' => 'DPD',
			'status_title' => 'Статус DPD',
			'tracking_label' => 'Номер DPD',
			'create_button_label' => 'Создать отправление DPD',
			'manual_attach_button_label' => 'Внести номер DPD вручную',
			'manual_attach_placeholder' => 'Номер DPD',
			'manual_attach_help' => 'Введите номер DPD из личного кабинета DPD.',
			'cancel_button_label' => 'Отменить отправление в DPD',
			'remove_button_label' => 'Удалить из заказа',
			'update_status_button_label' => 'Обновить статус',
			'created_toast' => 'Ждём регистрацию DPD.',
			'error_fallback_message' => 'Не удалось получить статус DPD.',
			'auto_poll_registration' => '1',
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		$errors = $this->builder->validate( $request );

		return array(
			'method' => 'SOAP',
			'path' => 'order2/createOrder2',
			'body' => array() === $errors ? $this->builder->build_preview_body( $request ) : array(),
			'errors' => $errors,
			'warnings' => $this->builder->warnings( $request ),

		);
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		$errors = $this->builder->validate( $request );
		if ( array() !== $errors ) {
			return new ShipmentCreateResult( false, error_code: 'dpd_validation_failed', error_message: implode( "\n", $errors ), raw_reference: array( 'errors' => $errors ) );
		}
		if ( ! $this->client instanceof DpdApiClient ) {
			return new ShipmentCreateResult( false, error_code: 'dpd_api_unavailable', error_message: 'DPD API client is unavailable.' );
		}

		$payload = $this->builder->build( $request );
		$response = $this->client->createOrder2( $payload );
		if ( empty( $response['success'] ) ) {
			return new ShipmentCreateResult(
				false,
				error_code: (string) ( $response['error_code'] ?? 'dpd_order_create_failed' ),
				error_message: (string) ( $response['error_message'] ?? 'DPD не создал отправление.' ),
				raw_reference: array(
					'request' => $this->sanitize_request_snapshot( $request, $payload ),
					'response' => $this->sanitize_response_snapshot( is_array( $response['body'] ?? null ) ? $response['body'] : array() ),
					'error_code' => (string) ( $response['error_code'] ?? '' ),
					'error_message' => (string) ( $response['error_message'] ?? '' ),
				)
			);
		}

		$order_row = is_array( $response['order'] ?? null ) ? $response['order'] : array();
		$order_num = $this->first_non_empty( $order_row['orderNum'] ?? '', $order_row['orderNumber'] ?? '', $response['body']['orderNum'] ?? '' );
		$request_number = $this->first_non_empty( $order_row['requestNumber'] ?? '', $order_row['requestNum'] ?? '', $order_row['orderNumberInternal'] ?? '' );
		$parcel_numbers = $this->parcel_numbers( is_array( $response['body'] ?? null ) ? $response['body'] : array() );
		$tracking = $this->first_non_empty( $order_num, $request_number, $parcel_numbers[0] ?? '' );

		return new ShipmentCreateResult(
			true,
			external_id: $tracking,
			tracking_number: $tracking,
			backlog_order_id: $request_number,
			raw_reference: array(
				'request' => $this->sanitize_request_snapshot( $request, $payload ),
				'response' => $this->sanitize_response_snapshot( is_array( $response['body'] ?? null ) ? $response['body'] : array() ),
				'dpd_order_number' => $order_num,
				'dpd_request_number' => $request_number,
				'dpd_parcel_numbers' => $parcel_numbers,
				'dpd_status' => (string) ( $order_row['status'] ?? '' ),
				'dpd_pickup_date' => (string) ( $order_row['pickupDate'] ?? '' ),
				'dpd_date_flag' => (string) ( $order_row['dateFlag'] ?? '' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	public function status_payload( object $order, array $shipment ): array {
		$has = array() !== $shipment;
		$policy = $this->buttons instanceof DpdShipmentButtonPolicy ? $this->buttons->resolve( $shipment ) : array( 'update' => $has, 'cancel' => false, 'remove' => $has );
		$label = $this->shipment_status_label( $shipment );
		$actual_cost = $this->actual_cost_payload( $shipment, $order );
		$places_summary = $this->places_summary( $shipment );

		return array_merge( array(
			'carrier_key' => DpdSettings::CARRIER_KEY,
			'has_shipment' => $has,
			'can_update_status' => ! empty( $policy['update'] ),
			'can_cancel' => ! empty( $policy['cancel'] ),
			'can_remove_from_order' => ! empty( $policy['remove'] ),
			'shipment_status_label' => $label,
			'universal_status_code' => (string) ( $shipment['universal_status_code'] ?? '' ),
			'universal_status_label' => (string) ( $shipment['universal_status_label'] ?? '' ),
			'carrier_status_title' => (string) ( $shipment['dpd_event_name'] ?? $shipment['status_title'] ?? '' ),
			'carrier_operation_date' => (string) ( $shipment['dpd_event_time'] ?? '' ),
			'carrier_operation_code' => (string) ( $shipment['dpd_event_code'] ?? '' ),
			'carrier_operation_marker' => (string) ( $shipment['dpd_event_marker'] ?? '' ),
			'tracking_checked_at' => (string) ( $shipment['tracking_checked_at'] ?? $shipment['dpd_enrichment_checked_at'] ?? '' ),
			'updated_at' => (string) ( $shipment['updated_at'] ?? '' ),
			'planned_delivery_date' => (string) ( $shipment['planned_delivery_date'] ?? '' ),
			'dpd_sent_places' => is_array( $shipment['dpd_sent_places'] ?? null ) ? $shipment['dpd_sent_places'] : array(),
			'dpd_places_summary' => $places_summary,
			'dpd_places_label' => count( is_array( $shipment['dpd_sent_places'] ?? null ) ? $shipment['dpd_sent_places'] : array() ) > 1 ? 'Грузоместа DPD' : 'Грузоместо DPD',
			'barcode' => $this->tracking_identifier( $shipment ),
			'registration_polling' => $has && '' === trim( (string) ( $shipment['dpd_order_number'] ?? '' ) ) && ! in_array( (string) ( $shipment['dpd_registration_state'] ?? '' ), array( 'duplicate', 'error', 'cancelled', 'transport_error' ), true ),
			'registration_terminal' => in_array( (string) ( $shipment['dpd_registration_state'] ?? '' ), array( 'ok', 'duplicate', 'error', 'cancelled', 'transport_error' ), true ),
			'registration_success' => 'ok' === (string) ( $shipment['dpd_registration_state'] ?? '' ),
			'registration_error' => in_array( (string) ( $shipment['dpd_registration_state'] ?? '' ), array( 'duplicate', 'error', 'transport_error' ), true ),
			'polling_continue' => $has && '' === trim( (string) ( $shipment['dpd_order_number'] ?? '' ) ) && in_array( (string) ( $shipment['dpd_registration_state'] ?? '' ), array( 'submitting', 'pending' ), true ),
		), $actual_cost );
	}

	/** @param array<string,mixed> $shipment */
	private function places_summary( array $shipment ): string {
		$places = is_array( $shipment['dpd_sent_places'] ?? null ) ? $shipment['dpd_sent_places'] : array();
		$rows = array();
		foreach ( $places as $index => $place ) {
			if ( ! is_array( $place ) ) {
				continue;
			}
			$weight = is_numeric( $place['weight_kg'] ?? null ) ? $this->format_decimal( (float) $place['weight_kg'] ) . ' кг' : '';
			$length = (int) ( $place['length_cm'] ?? 0 );
			$width = (int) ( $place['width_cm'] ?? 0 );
			$height = (int) ( $place['height_cm'] ?? 0 );
			$dimensions = $length > 0 && $width > 0 && $height > 0 ? $length . '×' . $width . '×' . $height . ' см' : '';
			$text = trim( implode( ', ', array_filter( array( $weight, $dimensions ), static fn ( string $value ): bool => '' !== $value ) ) );
			if ( '' !== $text ) {
				$rows[] = count( $places ) > 1 ? ( (string) ( $place['number'] ?? ( $index + 1 ) ) ) . ') ' . $text : $text;
			}
		}

		return implode( '; ', $rows );
	}

	private function format_decimal( float $value ): string {
		return rtrim( rtrim( number_format( $value, 3, '.', '' ), '0' ), '.' );
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	private function actual_cost_payload( array $shipment, object $order ): array {
		$actual_kopecks = $this->positive_int_or_null( $shipment['dpd_actual_cost_kopecks'] ?? null );
		if ( null === $actual_kopecks ) {
			return array(
				'actual_cost_kopecks' => null,
				'actual_cost_label' => '',
				'actual_cost_compare_status' => '',
				'actual_cost_compare_message' => '',
				'base_api_cost_kopecks' => null,
			);
		}

		$base_kopecks = $this->base_api_cost_kopecks( $order );
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
	 * @return array<string,mixed>
	 */
	public function update_status( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->registration instanceof DpdOrderRegistrationService ? $this->registration->update_status( $order ) : array( 'success' => false, 'message' => 'Обновление статуса DPD недоступно.' );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function attach_manual( object $order, array $payload ): array {
		return $this->registration instanceof DpdOrderRegistrationService ? $this->registration->attach_manual( $order, $payload ) : array( 'success' => false, 'message' => 'Ручное внесение номера DPD недоступно.' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->registration instanceof DpdOrderRegistrationService ? $this->registration->cancel( $order ) : array( 'success' => false, 'message' => 'Отмена DPD недоступна.' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function remove_from_order( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->registration instanceof DpdOrderRegistrationService ? $this->registration->remove_local( $order ) : array( 'success' => false, 'message' => 'Удаление DPD-отправления недоступно.' );
	}

	public function begin_registration( object $order, ShipmentCreateRequest $request ): array {
		return $this->registration instanceof DpdOrderRegistrationService ? $this->registration->begin( $order, $request ) : array( 'success' => false, 'message' => 'Регистрация DPD недоступна.' );
	}

	public function submit_registration( object $order, ShipmentCreateRequest $request, string $attempt_id ): array {
		return $this->registration instanceof DpdOrderRegistrationService ? $this->registration->submit( $order, $request, $attempt_id ) : array( 'success' => false, 'message' => 'Регистрация DPD недоступна.' );
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<int,array<string,mixed>>
	 */
	public function label_actions( object $order, array $shipment ): array {
		unset( $order, $shipment );

		return array();
	}

	public function supports_status_auto_sync(): bool {
		return false;
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	public function tracking_identifier( array $shipment ): string {
		foreach ( array( 'tracking_number', 'barcode', 'dpd_order_number', 'dpd_request_number' ) as $key ) {
			$value = trim( (string) ( $shipment[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		$parcels = is_array( $shipment['dpd_parcel_numbers'] ?? null ) ? $shipment['dpd_parcel_numbers'] : array();

		return trim( (string) ( $parcels[0] ?? '' ) );
	}

	public function auto_sync_throttle_microseconds(): int {
		return 0;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function sanitize_request_snapshot( ShipmentCreateRequest $request, array $payload ): array {
		return array(
			'method' => 'SOAP',
			'path' => 'order2/createOrder2',
			'body' => $this->sanitize_value( $payload ),
			'summary' => array(
				'order_id' => $request->order_id,
				'serviceCode' => (string) ( $request->meta['service_code'] ?? '' ),
				'delivery_type' => $request->delivery_type,
				'datePickup' => (string) ( $request->meta['date_pickup'] ?? '' ),
				'sender_terminalCode' => (string) ( $request->meta['pickup_terminal_code'] ?? '' ),
				'receiver_terminalCode' => (string) ( $request->meta['delivery_terminal_code'] ?? '' ),
				'cargoValue' => (float) ( $request->meta['declared_value_rub'] ?? 0 ),
				'cargoNumPack' => count( $request->places ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $response
	 * @return array<string,mixed>
	 */
	private function sanitize_response_snapshot( array $response ): array {
		return $this->sanitize_value( $response );
	}

	private function sanitize_value( mixed $value ): mixed {
		if ( is_array( $value ) ) {
			$sanitized = array();
			foreach ( $value as $key => $item ) {
				$key_text = strtolower( (string) $key );
				if ( in_array( $key_text, array( 'clientkey', 'client_key', 'auth', 'phone', 'contactphone', 'contactemail', 'email' ), true ) ) {
					$sanitized[ $key ] = '[redacted]';
					continue;
				}
				$sanitized[ $key ] = $this->sanitize_value( $item );
			}
			return $sanitized;
		}
		if ( is_string( $value ) && strlen( $value ) > 1000 ) {
			return substr( $value, 0, 1000 ) . '...';
		}

		return $value;
	}


	/** @param array<string,mixed> $shipment */
	private function shipment_status_label( array $shipment ): string {
		$state = (string) ( $shipment['dpd_registration_state'] ?? '' );
		if ( array() === $shipment ) { return 'не создано'; }
		if ( in_array( $state, array( 'duplicate', 'error', 'transport_error' ), true ) ) { return 'ошибка регистрации'; }
		if ( 'cancelled' === $state || 'cancelled' === (string) ( $shipment['universal_status_code'] ?? '' ) ) { return 'отменено'; }
		if ( '' === trim( (string) ( $shipment['dpd_order_number'] ?? '' ) ) ) { return 'ждём регистрацию'; }
		$label = trim( (string) ( $shipment['universal_status_label'] ?? '' ) );
		return '' !== $label ? $label : 'зарегистрировано';
	}

	private function first_non_empty( mixed ...$values ): string {
		foreach ( $values as $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<int,string>
	 */
	private function parcel_numbers( array $body ): array {
		$numbers = array();
		$walker = function ( mixed $value ) use ( &$numbers, &$walker ): void {
			if ( is_array( $value ) ) {
				foreach ( $value as $key => $item ) {
					$key_text = strtolower( (string) $key );
					if ( in_array( $key_text, array( 'parcelnumber', 'parcelnum', 'parcel_num', 'number' ), true ) && is_scalar( $item ) ) {
						$number = trim( (string) $item );
						if ( '' !== $number ) {
							$numbers[] = $number;
						}
					}
					$walker( $item );
				}
			}
		};
		$walker( $body );

		return array_values( array_unique( $numbers ) );
	}
}
