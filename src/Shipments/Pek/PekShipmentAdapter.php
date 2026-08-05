<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class PekShipmentAdapter implements CarrierShipmentAdapterInterface {
	public function __construct(
		private PekApiClient $api,
		private PekShipmentRequestBuilder $builder,
		private PekShipmentStatusService $statuses,
		private PekShipmentService $shipments,
		private PekShipmentButtonPolicy $buttons,
		private ShipmentActualCostResolver $actual_cost_resolver,
		private ?Logger $logger = null
	) {
	}

	public function carrier_key(): string {
		return PekSettings::CARRIER_KEY;
	}

	public function supports( ShipmentCreateRequest $request ): bool {
		return PekSettings::CARRIER_KEY === $request->carrier_key;
	}

	/** @return array<string,mixed> */
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		try {
			$errors = $this->builder->validate( $request );
			return array(
				'method' => 'POST',
				'path' => '/preregistration/submit/',
				'body' => array() === $errors ? array(
					'orderType' => 0,
					'type' => PekSettings::LTL_PRODUCT_TYPE,
					'order_number' => (string) ( $request->meta['order_num'] ?? $request->order_id ),
					'receiver_mode' => $request->delivery_type,
					'place_count' => count( $request->places ),
					'insurance_enabled' => true,
					'sms_release_requested' => true,
					'payers' => array( 'transporting' => 'sender', 'insurance' => 'sender', 'delivery' => 'sender', 'smsRelease' => 'sender' ),
					'sealing' => false,
					'client_card_present' => false,
				) : array(),
				'errors' => $errors,
				'warnings' => array(),
			);
		} catch ( \Throwable $e ) {
			return array( 'method' => 'POST', 'path' => '/preregistration/submit/', 'body' => array(), 'errors' => array( $e->getMessage() ), 'warnings' => array() );
		}
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		return $this->create_for_order( $this->order_stub(), $request );
	}

	public function create_for_order( object $order, ShipmentCreateRequest $request ): ShipmentCreateResult {
		try {
			$built = $this->builder->build( $order, $request, true );
			$response = $this->api->preregistration_submit( $built['payload'] );
			$parsed = $this->parse_create_response( $response );
			return new ShipmentCreateResult(
				true,
				external_id: $parsed['document_id'],
				tracking_number: $parsed['cargo_code'],
				raw_reference: array_merge( $parsed, array( 'summary' => $built['summary'], 'http_status' => $this->api->last_response_meta()['http_status'] ?? '', 'correlation' => $built['summary']['correlation'] ?? '' ) )
			);
		} catch ( PekApiException $e ) {
			$context = $e->context();
			$stage = (string) ( $context['failure_stage'] ?? '' );
			$uncertain = in_array( $stage, array( 'shipment_create_transport', 'shipment_create_contract' ), true );
			return new ShipmentCreateResult(
				false,
				error_code: $uncertain ? 'pek_uncertain_submit' : (string) ( $context['error_code'] ?? 'pek_create_failed' ),
				error_message: $uncertain ? 'Результат создания заявки ПЭК не определён. Проверьте кабинет ПЭК перед повтором.' : $e->getMessage(),
				raw_reference: array( 'failure_stage' => $stage, 'endpoint' => $context['endpoint'] ?? '/preregistration/submit/' )
			);
		} catch ( \Throwable $e ) {
			$this->log( 'PEK shipment create failed.', $e );
			return new ShipmentCreateResult( false, error_code: 'pek_validation_failed', error_message: $e->getMessage() );
		}
	}

	/** @return array<string,string> */
	public function presentation(): array {
		return array(
			'carrier_label' => 'ПЭК',
			'tracking_label' => 'Код груза ПЭК',
			'create_button_label' => 'Создать отправление ПЭК',
			'manual_attach_button_label' => 'Внести код груза ПЭК вручную',
			'manual_attach_placeholder' => 'Код груза ПЭК',
			'manual_attach_help' => 'Введите код груза из кабинета ПЭК.',
			'cancel_button_label' => 'Отменить заявку ПЭК',
			'remove_button_label' => 'Удалить из заказа',
			'update_status_button_label' => 'Обновить статус',
			'created_toast' => 'Заявка ПЭК создана.',
			'error_fallback_message' => 'Не удалось получить статус ПЭК.',
		);
	}

	/** @param array<string,mixed> $shipment @return array<string,mixed> */
	public function status_payload( object $order, array $shipment ): array {
		$policy = $this->buttons->resolve( $shipment );
		$actual = $this->actual_cost_resolver->presentation_payload( $shipment, $order );

		return array_merge(
			array(
				'carrier_key' => PekSettings::CARRIER_KEY,
				'has_shipment' => array() !== $shipment,
				'external_status' => (string) ( $shipment['pek_cargo_status'] ?? $shipment['status_title'] ?? '' ),
				'external_status_id' => (string) ( $shipment['pek_cargo_status_id'] ?? '' ),
				'status_title' => (string) ( $shipment['status_title'] ?? '' ),
				'universal_status_code' => (string) ( $shipment['universal_status_code'] ?? '' ),
				'universal_status_label' => (string) ( $shipment['universal_status_label'] ?? '' ),
				'barcode' => $this->tracking_identifier( $shipment ),
				'can_update_status' => ! empty( $policy['update'] ),
				'can_cancel' => ! empty( $policy['cancel'] ) && $this->is_old_enough_for_cancel( $shipment ),
				'can_remove_from_order' => ! empty( $policy['remove'] ),
				'sms_release_requested' => ! empty( $shipment['sms_release_requested'] ),
				'sms_release_confirmed' => ! empty( $shipment['sms_release_confirmed'] ),
				'destination_mode' => (string) ( $shipment['shipment_mode'] ?? $shipment['delivery_type'] ?? '' ),
				'tracking_checked_at' => (string) ( $shipment['tracking_checked_at'] ?? '' ),
			),
			$actual
		);
	}

	/** @return array<string,mixed> */
	public function update_status( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->statuses->update( $order );
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function attach_manual( object $order, array $payload ): array {
		return $this->shipments->attach_manual( $order, $payload );
	}

	/** @return array<string,mixed> */
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->shipments->cancel_in_carrier( $order );
	}

	/** @return array<string,mixed> */
	public function remove_from_order( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->shipments->remove_local( $order );
	}

	public function supports_status_auto_sync(): bool {
		return true;
	}

	/** @param array<string,mixed> $shipment */
	public function tracking_identifier( array $shipment ): string {
		return trim( (string) ( $shipment['pek_cargo_code'] ?? $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
	}

	public function auto_sync_throttle_microseconds(): int {
		return 600000;
	}

	/** @param array<string,mixed> $response @return array<string,string|array<int,string>> */
	private function parse_create_response( array $response ): array {
		$document_id = trim( (string) ( $response['documentId'] ?? $response['document_id'] ?? '' ) );
		$cargos = is_array( $response['cargos'] ?? null ) ? $response['cargos'] : array();
		$cargo = is_array( $cargos[0] ?? null ) ? $cargos[0] : array();
		$code = trim( (string) ( $cargo['code'] ?? $cargo['cargoCode'] ?? $response['cargoCode'] ?? '' ) );
		if ( '' === $document_id || '' === $code ) {
			throw new \RuntimeException( 'ПЭК не вернул обязательные идентификаторы заявки и груза.' );
		}

		return array(
			'document_id' => $document_id,
			'cargo_code' => $code,
			'cargo_barcode' => (string) ( $cargo['cargoBarCode'] ?? $cargo['barcode'] ?? '' ),
			'position_barcodes' => is_array( $cargo['positionBarCodes'] ?? null ) ? array_values( array_filter( $cargo['positionBarCodes'], 'is_string' ) ) : array(),
		);
	}

	private function order_stub(): object {
		return new class() {
			public function get_billing_phone(): string { return ''; }
			public function get_billing_email(): string { return ''; }
		};
	}

	/** @param array<string,mixed> $shipment */
	private function is_old_enough_for_cancel( array $shipment ): bool {
		$created = strtotime( (string) ( $shipment['created_at'] ?? '' ) );

		return false !== $created && time() - $created >= 600;
	}

	private function log( string $message, \Throwable $e ): void {
		if ( $this->logger instanceof Logger ) {
			$this->logger->warning( $message, array( 'error' => $e->getMessage(), 'carrier_key' => PekSettings::CARRIER_KEY ) );
		}
	}
}
