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
		private PekShipmentCreateResponseParser $create_responses,
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
			$built = $this->builder->prepare( $this->order_from_request( $request ), $request, true );
			return array( 'method' => 'POST', 'path' => '/preregistration/submit/', 'body' => $built['preview'], 'errors' => array(), 'warnings' => array() );
		} catch ( \Throwable $e ) {
			return array( 'method' => 'POST', 'path' => '/preregistration/submit/', 'body' => array(), 'errors' => array( $this->safe_error_message( $e ) ), 'warnings' => array() );
		}
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		return $this->create_for_order( $this->order_stub(), $request );
	}

	public function create_for_order( object $order, ShipmentCreateRequest $request ): ShipmentCreateResult {
		$built = null;
		$submitted = false;
		try {
			$built = $this->builder->prepare( $order, $request, true );
			$response = $this->api->preregistration_submit( $built['payload'] );
			$submitted = true;
			$parsed = $this->create_responses->parse( $response );
			$safe_summary = $this->safe_summary( $built['summary'] );
			return new ShipmentCreateResult(
				true,
				external_id: $parsed['document_id'],
				tracking_number: $parsed['cargo_code'],
				raw_reference: array_merge( $parsed, array( 'summary' => $safe_summary, 'http_status' => $this->api->last_response_meta()['http_status'] ?? '', 'correlation' => $safe_summary['correlation'] ?? '' ) )
			);
		} catch ( PekApiException $e ) {
			$context = $e->context();
			$stage = (string) ( $context['failure_stage'] ?? '' );
			$uncertain = in_array( $stage, array( 'shipment_create_transport', 'shipment_create_contract' ), true );
			return new ShipmentCreateResult(
				false,
				error_code: $uncertain ? 'pek_uncertain_submit' : (string) ( $context['error_code'] ?? 'pek_create_failed' ),
				error_message: $uncertain ? 'Результат создания заявки ПЭК не определён. Проверьте кабинет ПЭК перед повтором.' : 'ПЭК отклонил создание заявки.',
				raw_reference: array( 'failure_stage' => $stage, 'endpoint' => $context['endpoint'] ?? '/preregistration/submit/', 'method' => 'POST', 'http_status' => $context['http_status'] ?? '', 'correlation' => is_array( $built ) ? (string) ( $built['summary']['correlation'] ?? '' ) : '', 'summary' => is_array( $built ) ? $this->safe_summary( $built['summary'] ) : array() )
			);
		} catch ( \Throwable $e ) {
			$this->log( 'PEK shipment create failed.', $e );
			if ( $submitted ) {
				$meta = $this->api->last_response_meta();
				$safe_summary = is_array( $built ) ? $this->safe_summary( $built['summary'] ) : array();
				return new ShipmentCreateResult(
					false,
					error_code: 'pek_uncertain_submit',
					error_message: 'Результат создания заявки ПЭК не определён. Проверьте кабинет ПЭК перед повтором.',
					raw_reference: array(
						'failure_stage' => 'shipment_create_contract',
						'endpoint' => $meta['endpoint'] ?? '/preregistration/submit/',
						'method' => 'POST',
						'http_status' => $meta['http_status'] ?? '',
						'correlation' => (string) ( $safe_summary['correlation'] ?? '' ),
						'summary' => $safe_summary,
					)
				);
			}
			return new ShipmentCreateResult( false, error_code: 'pek_validation_failed', error_message: $this->safe_error_message( $e ) );
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
		return 1200000;
	}

	private function order_from_request( ShipmentCreateRequest $request ): object {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $request->order_id ) : null;
		if ( ! is_object( $order ) ) {
			throw new \RuntimeException( 'Не удалось загрузить заказ для preview ПЭК.' );
		}

		return $order;
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

	private function safe_error_message( \Throwable $e ): string {
		return $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException
			? $e->getMessage()
			: 'Не удалось подготовить заявку ПЭК.';
	}

	/** @param array<string,mixed> $summary @return array<string,mixed> */
	private function safe_summary( array $summary ): array {
		$sender = is_array( $summary['sender_warehouse'] ?? null ) ? $summary['sender_warehouse'] : array();
		$cargo = is_array( $summary['cargo'] ?? null ) ? $summary['cargo'] : array();
		$sms = is_array( $summary['sms'] ?? null ) ? $summary['sms'] : array();

		return array(
			'correlation' => (string) ( $summary['correlation'] ?? '' ),
			'sender_warehouse' => array(
				'warehouseId' => (string) ( $sender['warehouseId'] ?? '' ),
				'divisionName' => (string) ( $sender['divisionName'] ?? '' ),
				'branchName' => (string) ( $sender['branchName'] ?? '' ),
				'source' => (string) ( $sender['source'] ?? '' ),
			),
			'receiver_warehouse_id' => (string) ( $summary['receiver_warehouse_id'] ?? '' ),
			'receiver_branch_id' => (string) ( $summary['receiver_branch_id'] ?? '' ),
			'shipment_mode' => (string) ( $summary['shipment_mode'] ?? '' ),
			'declared_value_kopecks' => (int) ( $summary['declared_value_kopecks'] ?? 0 ),
			'product_weight_g' => (int) ( $summary['product_weight_g'] ?? 0 ),
			'sealing_requested' => ! empty( $summary['sealing_requested'] ),
			'sms' => array(
				'success' => ! empty( $sms['success'] ),
				'geography_confirmed' => ! empty( $sms['geography_confirmed'] ),
				'counterpart_service_confirmed' => ! empty( $sms['counterpart_service_confirmed'] ),
				'effective_limit_kopecks' => (int) ( $sms['effective_limit_kopecks'] ?? 0 ),
			),
			'cargo' => array(
				'place_count' => (int) ( $cargo['place_count'] ?? 0 ),
				'aggregate_weight_kg' => $cargo['aggregate_weight_kg'] ?? null,
				'aggregate_volume_m3' => $cargo['aggregate_volume_m3'] ?? null,
			),
		);
	}
}
