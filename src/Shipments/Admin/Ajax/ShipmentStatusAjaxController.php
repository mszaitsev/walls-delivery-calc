<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin\Ajax;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Pickup\Cdek\CdekDeliveryPointService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\ShipmentBacklogService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentMetaboxButtonPolicy;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Cdek\CdekBarcodePrintService;
use WallsShop\WDC\Shipments\Cdek\CdekOrderStatusService;
use WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentLifecycleContinuationInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentDownloadService;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentProviderRegistry;
use WallsShop\WDC\Shipments\Modal\CarrierShipmentModalExtensionInterface;
use WallsShop\WDC\Shipments\Modal\ShipmentModalExtensionRegistry;
use WallsShop\WDC\Shipments\RussianPost\RussianPostAddressNormalizer;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;


defined( 'ABSPATH' ) || exit;


final class ShipmentStatusAjaxController {

	public function __construct(
		private OrderShipmentRepository $repository,
		private ShipmentAdminCarrierUiPayloadBuilder $payloads
	) {
	}

	public function handle_update(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( ShipmentAdminAjaxService::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}

		$shipment_key = sanitize_key( wp_unslash( $_POST['shipment_key'] ?? RussianPostDomesticSettings::CARRIER_KEY ) );
		$adapter = $this->payloads->carrier_adapter( $shipment_key );
		if ( null === $adapter ) {
			wp_send_json_error( array( 'message' => __( 'Для выбранной службы нет адаптера отправлений.', 'walls-delivery-calc' ) ), 400 );
		}
		$result = $adapter->update_status( $order, $shipment_key );
		if ( ! (bool) ( $result['success'] ?? false ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Не удалось получить статус отправления.', 'walls-delivery-calc' ) ) ), 400 );
		}

		wp_send_json_success(
			array_merge(
				$this->payloads->carrier_ui_payload( $order, $shipment_key ),
				array(
					'message' => (string) ( $result['message'] ?? __( 'Статус отправления обновлен.', 'walls-delivery-calc' ) ),
					'pending' => ! empty( $result['pending'] ),
					'retryable' => ! empty( $result['retryable'] ),
					'cancelled_and_removed' => ! empty( $result['cancelled_and_removed'] ),
					'carrier_status_value' => is_scalar( $result['status'] ?? null ) ? (string) $result['status'] : '',
				)
			)
		);
	}

	public function handle_mark_poll_exhausted(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( ShipmentAdminAjaxService::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		try {
			$order_id = (int) ( $_POST['order_id'] ?? 0 );
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! is_object( $order ) || $order_id <= 0 ) {
				wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ), 'error_code' => 'shipment_poll_exhausted_invalid_request' ), 404 );
			}
			$shipment_key = sanitize_key( wp_unslash( $_POST['shipment_key'] ?? RussianPostDomesticSettings::CARRIER_KEY ) );
			$attempts = max( 0, (int) ( $_POST['attempts'] ?? 0 ) );
			$purpose = sanitize_key( wp_unslash( $_POST['purpose'] ?? 'registration' ) );
			$adapter = $this->payloads->carrier_adapter( $shipment_key );
			if ( null === $adapter ) {
				throw new \InvalidArgumentException( __( 'Для выбранной службы нет адаптера отправлений.', 'walls-delivery-calc' ) );
			}
			if ( ! method_exists( $adapter, 'mark_polling_exhausted' ) ) {
				throw new \InvalidArgumentException( __( 'Служба доставки не поддерживает сохранение состояния polling.', 'walls-delivery-calc' ) );
			}
			$result = $adapter->mark_polling_exhausted( $order, $attempts, $purpose );
			if ( ! (bool) ( $result['success'] ?? false ) ) {
				throw new \InvalidArgumentException( (string) ( $result['message'] ?? __( 'Не удалось сохранить состояние polling.', 'walls-delivery-calc' ) ) );
			}

			wp_send_json_success(
				array_merge(
					$this->payloads->carrier_ui_payload( $order, $shipment_key, is_array( $result['shipment'] ?? null ) ? $result['shipment'] : null ),
					array(
						'message' => (string) ( $result['message'] ?? __( 'Автоматическая проверка статуса завершена.', 'walls-delivery-calc' ) ),
						'polling_exhausted' => true,
						'attempts' => $attempts,
					)
				)
			);
		} catch ( \InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $this->public_shipment_error_message( $exception->getMessage() ), 'error_code' => 'shipment_poll_exhausted_validation_failed' ), 400 );
		} catch ( \Throwable $exception ) {
			if ( str_contains( $exception::class, 'AjaxResponse' ) ) {
				throw $exception;
			}
			error_log(
				sprintf(
					'[walls-delivery-calc] shipment poll exhausted failed. class=%s message=%s location=%s:%d',
					$exception::class,
					$exception->getMessage(),
					$exception->getFile(),
					$exception->getLine()
				)
			);
			wp_send_json_error( array( 'message' => __( 'Не удалось сохранить состояние автоматической проверки. Подробности записаны в журнал ошибок.', 'walls-delivery-calc' ), 'error_code' => 'shipment_poll_exhausted_unexpected_error' ), 500 );
		}
	}

}
