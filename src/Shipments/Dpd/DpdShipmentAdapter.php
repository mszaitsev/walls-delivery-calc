<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentPayloadBuilder;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Contracts\ShipmentCarrierAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentAdapter implements ShipmentCarrierAdapterInterface {
	public function __construct(
		private DpdShipmentPayloadBuilder $builder,
		private ?DpdApiClient $client = null
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
			'manual_attach_help' => 'Ручное внесение номера DPD будет добавлено позже.',
			'created_toast' => 'Отправление DPD создано.',
			'error_fallback_message' => 'Не удалось получить статус DPD.',
			'auto_poll_registration' => '0',
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
		unset( $order );
		$has = array() !== $shipment;
		$status = (string) ( $shipment['status'] ?? '' );

		return array(
			'carrier_key' => DpdSettings::CARRIER_KEY,
			'has_shipment' => $has,
			'can_update_status' => false,
			'can_cancel' => false,
			'can_remove_from_order' => false,
			'shipment_status_label' => $has ? ( 'pending_creation_in_carrier' === $status ? 'попытка создания в ТК' : 'создано' ) : 'не создано',
			'carrier_status_title' => $has ? (string) ( $shipment['dpd_status'] ?? 'OK' ) : '-',
			'tracking_checked_at' => '',
			'barcode' => $this->tracking_identifier( $shipment ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function update_status( object $order, string $shipment_key = '' ): array {
		unset( $order, $shipment_key );

		return array( 'success' => false, 'message' => 'Обновление статуса DPD будет добавлено позже.' );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function attach_manual( object $order, array $payload ): array {
		unset( $order, $payload );

		return array( 'success' => false, 'message' => 'Ручное внесение номера DPD будет добавлено позже.' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array {
		unset( $order, $shipment_key );

		return array( 'success' => false, 'message' => 'Отмена DPD будет добавлена позже.' );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function remove_from_order( object $order, string $shipment_key = '' ): array {
		unset( $order, $shipment_key );

		return array( 'success' => false, 'message' => 'Удаление DPD-отправления недоступно.' );
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
