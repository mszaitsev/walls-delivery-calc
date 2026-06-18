<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentPayloadBuilder;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Contracts\ShipmentCarrierAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentAdapter implements ShipmentCarrierAdapterInterface {
	public function __construct(
		private DpdShipmentPayloadBuilder $builder
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
			'create_button_label' => 'Подготовить DPD payload',
			'manual_attach_button_label' => 'Внести номер DPD вручную',
			'manual_attach_help' => 'Ручное внесение номера DPD будет добавлено позже.',
			'created_toast' => 'DPD payload подготовлен.',
			'error_fallback_message' => 'DPD отправления пока доступны только в режиме предпросмотра.',
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
			'path' => 'order2/createOrder',
			'body' => array() === $errors ? $this->builder->build( $request ) : array(),
			'errors' => $errors,
			'warnings' => $this->builder->warnings( $request ),
			'dry_run' => true,
			'live_api_call' => false,
		);
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		return new ShipmentCreateResult(
			false,
			error_code: 'dpd_create_disabled',
			error_message: 'Создание отправления DPD отключено в версии 0.63.0. Доступен только dry-run preview.',
			raw_reference: array( 'live_api_call' => false )
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	public function status_payload( object $order, array $shipment ): array {
		unset( $order );

		return array(
			'carrier_key' => DpdSettings::CARRIER_KEY,
			'has_shipment' => array() !== $shipment,
			'can_update_status' => false,
			'can_cancel' => false,
			'can_remove_from_order' => false,
			'shipment_status_label' => array() === $shipment ? 'не создано' : 'создано локально',
			'carrier_status_title' => 'Dry-run only',
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

		return array( 'success' => false, 'message' => 'Удаление DPD-отправления недоступно: live отправление не создается.' );
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
		return trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
	}

	public function auto_sync_throttle_microseconds(): int {
		return 0;
	}
}
