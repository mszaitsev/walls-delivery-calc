<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use Throwable;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentAdapter implements CarrierShipmentAdapterInterface {
	public function __construct(
		private YandexShipmentRegistrationService $registration,
		private YandexShipmentButtonPolicy $buttons
	) {
	}

	public function carrier_key(): string { return YandexDeliverySettings::CARRIER_KEY; }

	public function supports( ShipmentCreateRequest $request ): bool { return YandexDeliverySettings::CARRIER_KEY === $request->carrier_key; }

	/** @return array<string,string> */
	public function presentation(): array {
		return array(
			'carrier_label' => 'Яндекс.Доставка',
			'status_title' => 'Статус Яндекс.Доставки',
			'tracking_label' => 'Request ID Яндекс',
			'create_button_label' => 'Создать отправление Яндекс',
			'manual_attach_button_label' => 'Ввести номер Яндекс вручную',
			'manual_attach_field_label' => 'Request ID Яндекс',
			'manual_attach_placeholder' => '***-udp',
			'manual_attach_help' => 'Введите request_id отправления, созданного напрямую в кабинете Яндекс.Доставки.',
			'cancel_button_label' => 'Отменить отправление в Яндекс',
			'remove_button_label' => 'Удалить из заказа',
			'remove_confirmation_message' => 'Удаление уберёт запись только из заказа WooCommerce и не отменит отправление в Яндекс.Доставке. Продолжить?',
			'update_status_button_label' => 'Обновить статус',
			'created_toast' => 'Отправление Яндекс создано.',
			'polling_timeout_message' => 'Статус отправления пока не получен. Повторите обновление статуса позднее.',
			'error_fallback_message' => 'Не удалось получить статус Яндекс.Доставки.',
			'auto_poll_registration' => '1',
			'registration_poll_interval_ms' => '5000',
			'registration_poll_max_attempts' => '14',
		);
	}

	/** @return array<string,mixed> */
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		try {
			return array( 'method' => 'POST', 'path' => '/api/b2b/platform/offers/create?send_unix=false', 'body' => $this->registration->build_preview_payload( $request ), 'errors' => array() );
		} catch ( Throwable $exception ) {
			return array( 'method' => 'POST', 'path' => '/api/b2b/platform/offers/create?send_unix=false', 'body' => array(), 'errors' => array( $exception->getMessage() ) );
		}
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult { return $this->registration->create( $request ); }

	/** @param array<string,mixed> $shipment @return array<string,mixed> */
	public function status_payload( object $order, array $shipment ): array {
		unset( $order );
		$policy = $this->buttons->resolve( $shipment );
		$status = trim( (string) ( $shipment['yandex_status'] ?? '' ) );

		return array(
			'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
			'has_shipment' => array() !== $shipment,
			'can_create' => ! empty( $policy['create'] ),
			'can_attach_manual' => ! empty( $policy['manual_attach'] ),
			'can_update_status' => ! empty( $policy['update'] ),
			'can_cancel' => ! empty( $policy['cancel'] ),
			'can_remove_from_order' => ! empty( $policy['remove'] ),
			'shipment_status_label' => '' !== $status ? $status : ( array() === $shipment ? 'не создано' : 'зарегистрировано' ),
			'carrier_status_title' => $this->carrier_status_title( $shipment, $status ),
			'tracking_checked_at' => (string) ( $shipment['updated_at'] ?? '' ),
			'updated_at' => (string) ( $shipment['updated_at'] ?? '' ),
			'barcode' => $this->tracking_identifier( $shipment ),
			'yandex_request_id' => (string) ( $shipment['yandex_request_id'] ?? '' ),
			'yandex_courier_order_id' => (string) ( $shipment['yandex_courier_order_id'] ?? '' ),
			'yandex_sharing_url' => (string) ( $shipment['yandex_sharing_url'] ?? '' ),
			'yandex_places' => is_array( $shipment['yandex_places'] ?? null ) ? $shipment['yandex_places'] : array(),
			'yandex_items' => is_array( $shipment['yandex_items'] ?? null ) ? $shipment['yandex_items'] : array(),
			'registration_polling' => array() !== $shipment && ! empty( $shipment['yandex_reconciliation_required'] ),
			'polling_continue' => array() !== $shipment && ! empty( $shipment['yandex_reconciliation_required'] ) && empty( $shipment['yandex_reconciliation_poll_exhausted'] ),
			'registration_terminal' => array() !== $shipment && empty( $shipment['yandex_reconciliation_required'] ),
			'registration_success' => '' !== $status && empty( $shipment['yandex_reconciliation_required'] ),
			'registration_error' => false,
			'registration_poll_interval_ms' => 5000,
			'registration_poll_max_attempts' => 14,
		);
	}

	/** @param array<string,mixed> $shipment */
	private function carrier_status_title( array $shipment, string $status ): string {
		$title = trim( (string) ( $shipment['status_title'] ?? '' ) );
		if ( str_starts_with( $title, 'Статус Яндекс.Доставки:' ) ) {
			$title = trim( substr( $title, strlen( 'Статус Яндекс.Доставки:' ) ) );
		}
		if ( '' !== $title ) {
			return $title;
		}

		return '' !== $status ? $status : ( array() === $shipment ? '' : 'Ожидается получение статуса' );
	}

	/** @return array<string,mixed> */
	public function update_status( object $order, string $shipment_key = '' ): array { unset( $shipment_key ); return $this->registration->update_status( $order ); }

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function attach_manual( object $order, array $payload ): array { return $this->registration->attach_manual( $order, $payload ); }

	/** @return array<string,mixed> */
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array { unset( $shipment_key ); return $this->registration->cancel( $order ); }

	/** @return array<string,mixed> */
	public function remove_from_order( object $order, string $shipment_key = '' ): array { unset( $shipment_key ); return $this->registration->remove_local( $order ); }

	/** @param array<string,mixed> $shipment @return array<int,array<string,mixed>> */
	public function label_actions( object $order, array $shipment ): array { unset( $order, $shipment ); return array(); }

	public function supports_status_auto_sync(): bool { return true; }

	/** @param array<string,mixed> $shipment */
	public function tracking_identifier( array $shipment ): string {
		foreach ( array( 'yandex_request_id', 'yandex_courier_order_id', 'tracking_number', 'barcode', 'external_id' ) as $key ) {
			$value = trim( (string) ( $shipment[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	public function auto_sync_throttle_microseconds(): int { return 0; }
}
