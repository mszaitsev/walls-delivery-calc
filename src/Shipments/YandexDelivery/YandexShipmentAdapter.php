<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use Throwable;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentAdapter implements CarrierShipmentAdapterInterface {
	public function __construct(
		private YandexShipmentRegistrationService $registration,
		private YandexShipmentButtonPolicy $buttons,
		private ?YandexStatusMapping $status_mapping = null
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
			'remove_confirmation_message' => 'Удаление уберёт запись только из заказа WooCommerce. Статус отправления в Яндекс.Доставке останется без изменений. Продолжить?',
			'update_status_button_label' => 'Обновить статус',
			'created_toast' => 'Отправление Яндекс создано.',
			'polling_timeout_message' => 'Статус отправления пока не получен. Повторите обновление статуса позднее.',
			'cancellation_polling_timeout_message' => 'Статус отмены пока не получен. Повторите обновление позднее.',
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

	public function create_for_order( object $order, ShipmentCreateRequest $request ): ShipmentCreateResult { return $this->registration->create_for_order( $order, $request ); }

	/** @param array<string,mixed> $shipment @return array<string,mixed> */
	public function status_payload( object $order, array $shipment ): array {
		$policy = $this->buttons->resolve( $shipment );
		$status = trim( (string) ( $shipment['yandex_status'] ?? '' ) );
		$universal = sanitize_key( (string) ( $shipment['universal_status_code'] ?? '' ) );
		if ( ! DeliveryStatus::is_valid( $universal ) && $this->status_mapping instanceof YandexStatusMapping ) {
			$universal = $this->status_mapping->universal_status_for( $status );
		}
		$universal_label = DeliveryStatus::is_valid( $universal ) ? DeliveryStatus::label( $universal ) : '';
		$cancel_pending = array() !== $shipment && 'cancellation_started' === (string) ( $shipment['status'] ?? '' );
		$reconciliation_pending = array() !== $shipment && ! empty( $shipment['yandex_reconciliation_required'] );

		return array(
			'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
			'has_shipment' => array() !== $shipment,
			'can_create' => ! empty( $policy['create'] ),
			'can_attach_manual' => ! empty( $policy['manual_attach'] ),
			'can_update_status' => ! empty( $policy['update'] ),
			'can_cancel' => ! empty( $policy['cancel'] ),
			'can_remove_from_order' => ! empty( $policy['remove'] ),
			'universal_status_code' => $universal,
			'universal_status_label' => $universal_label,
			'shipment_status_label' => '' !== $universal_label ? $universal_label : ( array() === $shipment ? 'не создано' : 'зарегистрировано' ),
			'carrier_status_title' => $this->carrier_status_title( $shipment, $status ),
			'carrier_status_description' => (string) ( $shipment['yandex_status_description'] ?? '' ),
			'tracking_checked_at' => (string) ( $shipment['updated_at'] ?? '' ),
			'updated_at' => (string) ( $shipment['updated_at'] ?? '' ),
			'barcode' => $this->tracking_identifier( $shipment ),
			'tracking_presentation' => $this->tracking_presentation( $shipment ),
			'yandex_request_id' => (string) ( $shipment['yandex_request_id'] ?? '' ),
			'yandex_courier_order_id' => (string) ( $shipment['yandex_courier_order_id'] ?? '' ),
			'yandex_sharing_url' => (string) ( $shipment['yandex_sharing_url'] ?? '' ),
			'yandex_places' => is_array( $shipment['yandex_places'] ?? null ) ? $shipment['yandex_places'] : array(),
			'yandex_items' => is_array( $shipment['yandex_items'] ?? null ) ? $shipment['yandex_items'] : array(),
			'registration_polling' => $reconciliation_pending || $cancel_pending,
			'polling_continue' => ( $reconciliation_pending && empty( $shipment['yandex_reconciliation_poll_exhausted'] ) ) || ( $cancel_pending && empty( $shipment['yandex_cancel_poll_exhausted'] ) ),
			'poll_purpose' => $cancel_pending ? 'cancellation' : 'registration',
			'cancellation_pending' => $cancel_pending,
			'registration_terminal' => array() !== $shipment && ! $reconciliation_pending && ! $cancel_pending,
			'registration_success' => '' !== $status && ! $reconciliation_pending && ! $cancel_pending,
			'registration_error' => false,
			'registration_poll_interval_ms' => 5000,
			'registration_poll_max_attempts' => 14,
		) + $this->actual_cost_payload( $shipment, $order ) + array(
			'yandex_self_pickup_node_code' => (string) ( $shipment['yandex_self_pickup_node_code'] ?? '' ),
			'yandex_self_pickup_node_type' => (string) ( $shipment['yandex_self_pickup_node_type'] ?? '' ),
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

	/** @return array<string,mixed> */
	public function mark_polling_exhausted( object $order, int $attempts, string $purpose = 'registration' ): array { return $this->registration->mark_polling_exhausted( $order, $attempts, $purpose ); }

	/** @param array<string,mixed> $shipment @return array<int,array<string,mixed>> */
	public function label_actions( object $order, array $shipment ): array {
		unset( $order );
		if ( '' === $this->request_id( $shipment ) || ! $this->can_download_label( $shipment ) ) {
			return array();
		}

		return array(
			array(
				'key' => 'download_yandex_label',
				'label' => 'Скачать ярлык',
				'type' => 'download',
				'visible' => true,
			),
		);
	}

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

	/** @param array<string,mixed> $shipment @return array<string,string> */
	private function tracking_presentation( array $shipment ): array {
		$raw_sharing_url = trim( (string) ( $shipment['sharing_url'] ?? '' ) );
		if ( '' === $raw_sharing_url ) {
			$raw_sharing_url = trim( (string) ( $shipment['yandex_sharing_url'] ?? '' ) );
		}
		$sharing_url = $this->valid_sharing_url( $raw_sharing_url );
		if ( '' !== $sharing_url ) {
			return array(
				'label' => 'Отслеживание посылки',
				'display_text' => 'ссылка',
				'url' => $sharing_url,
				'copy_value' => $sharing_url,
			);
		}

		$request_id = $this->tracking_identifier( $shipment );
		if ( '' === $request_id ) {
			return array();
		}

		return array(
			'label' => 'Request ID Яндекс',
			'display_text' => $request_id,
			'url' => '',
			'copy_value' => $request_id,
		);
	}

	private function valid_sharing_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
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

	/** @param array<string,mixed> $shipment */
	private function can_download_label( array $shipment ): bool {
		if ( array() === $shipment || ! empty( $shipment['yandex_reconciliation_required'] ) ) {
			return false;
		}
		$universal = sanitize_key( (string) ( $shipment['universal_status_code'] ?? '' ) );
		if ( ! DeliveryStatus::is_valid( $universal ) && $this->status_mapping instanceof YandexStatusMapping ) {
			$universal = $this->status_mapping->universal_status_for( (string) ( $shipment['yandex_status'] ?? '' ) );
		}

		return in_array(
			$universal,
			array(
				DeliveryStatus::CREATED_IN_CARRIER,
				DeliveryStatus::IN_TRANSIT,
				DeliveryStatus::READY_FOR_PICKUP,
				DeliveryStatus::HANDED_TO_COURIER,
				DeliveryStatus::DELIVERED,
				DeliveryStatus::RETURNING_TO_SENDER,
				DeliveryStatus::RETURNED_TO_SENDER,
			),
			true
		);
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	private function actual_cost_payload( array $shipment, object $order ): array {
		$actual_kopecks = $this->positive_int_or_null( $shipment['actual_cost_kopecks'] ?? $shipment['yandex_offer_pricing_total_kopecks'] ?? null );
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

	public function auto_sync_throttle_microseconds(): int { return 0; }
}
