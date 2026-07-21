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


final class ShipmentManualAttachAjaxController {

	public function __construct(
		private ShipmentAdminCarrierUiPayloadBuilder $payloads
	) {
	}

	public function handle(): void {
		$buffer_level = ob_get_level();
		ob_start();
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( ShipmentAdminAjaxService::NONCE_ACTION, 'nonce', false ) ) {
			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		try {
			$order_id = (int) ( $_POST['order_id'] ?? 0 );
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! is_object( $order ) || $order_id <= 0 ) {
				$this->discard_preview_buffer( $buffer_level );
				wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ), 'error_code' => 'shipment_attach_invalid_request' ), 404 );
			}
			$shipment_key = sanitize_key( wp_unslash( $_POST['shipment_key'] ?? RussianPostDomesticSettings::CARRIER_KEY ) );
			$barcode = sanitize_text_field( wp_unslash( $_POST['barcode'] ?? '' ) );
			$adapter = $this->payloads->carrier_adapter( $shipment_key );
			if ( null === $adapter ) {
				throw new \InvalidArgumentException( __( 'Для выбранной службы нет адаптера отправлений.', 'walls-delivery-calc' ) );
			}
			$result = $adapter->attach_manual( $order, array( 'barcode' => $barcode, 'request_id' => $barcode, 'tracking_number' => $barcode ) );
			if ( ! (bool) ( $result['success'] ?? false ) ) {
				throw new \InvalidArgumentException( (string) ( $result['message'] ?? __( 'Не удалось сохранить номер отслеживания.', 'walls-delivery-calc' ) ) );
			}

			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_success(
				array_merge(
					$this->payloads->carrier_ui_payload( $order, $shipment_key ),
					array(
					'message' => (string) ( $result['message'] ?? __( 'Номер отслеживания сохранен.', 'walls-delivery-calc' ) ),
					'warning' => (string) ( $result['warning'] ?? '' ),
					'tracking_number' => (string) ( $result['tracking_number'] ?? '' ),
					'backlog_order_id' => (string) ( $result['backlog_order_id'] ?? '' ),
					)
				)
			);
		} catch ( \InvalidArgumentException $exception ) {
			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_error( array( 'message' => $this->public_shipment_error_message( $exception->getMessage() ), 'error_code' => 'shipment_attach_validation_failed' ), 400 );
		} catch ( \Throwable $exception ) {
			if ( str_contains( $exception::class, 'AjaxResponse' ) ) {
				throw $exception;
			}
			error_log(
				sprintf(
					'[walls-delivery-calc] shipment attach failed. class=%s message=%s location=%s:%d',
					$exception::class,
					$exception->getMessage(),
					$exception->getFile(),
					$exception->getLine()
				)
			);
			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_error( array( 'message' => __( 'Не удалось прикрепить отправление. Подробности записаны в журнал ошибок.', 'walls-delivery-calc' ), 'error_code' => 'shipment_attach_unexpected_error' ), 500 );
		}
	}

	private function discard_preview_buffer( int $buffer_level ): void {
		while ( ob_get_level() > $buffer_level ) {
			ob_end_clean();
		}
	}

	private function public_shipment_error_message( string $message ): string {
		$message = trim( $message );
		if ( '' === $message ) {
			return __( 'Проверьте данные отправления.', 'walls-delivery-calc' );
		}
		if ( str_contains( $message, "\n" ) ) {
			$messages = array();
			foreach ( preg_split( '/\R+/', $message ) ?: array() as $line ) {
				$translated = $this->public_shipment_error_message( (string) $line );
				if ( '' !== $translated && ! in_array( $translated, $messages, true ) ) {
					$messages[] = $translated;
				}
			}

			return array() !== $messages ? implode( "\n", $messages ) : __( 'Проверьте данные отправления.', 'walls-delivery-calc' );
		}

		$translations = array(
			'amount must be greater than 0' => __( 'Укажите количество товара больше 0.', 'walls-delivery-calc' ),
			'ordered_quantity must be greater than 0' => __( 'Укажите исходное количество товара больше 0.', 'walls-delivery-calc' ),
			'weight must be greater than 0' => __( 'Укажите вес товара больше 0.', 'walls-delivery-calc' ),
			'weight_g must be greater than 0' => __( 'Укажите вес грузоместа.', 'walls-delivery-calc' ),
			'length_cm must be greater than 0' => __( 'Укажите длину грузоместа.', 'walls-delivery-calc' ),
			'width_cm must be greater than 0' => __( 'Укажите ширину грузоместа.', 'walls-delivery-calc' ),
			'height_cm must be greater than 0' => __( 'Укажите высоту грузоместа.', 'walls-delivery-calc' ),
			'cost must be greater than or equal to 0' => __( 'Укажите стоимость товара.', 'walls-delivery-calc' ),
			'must contain item_key' => __( 'Не удалось определить товар в строке распределения.', 'walls-delivery-calc' ),
			'references an unknown shipment place' => __( 'Строка товара ссылается на несуществующее грузоместо.', 'walls-delivery-calc' ),
			'must contain at least one allocation row' => __( 'Каждое грузоместо должно содержать хотя бы один товар.', 'walls-delivery-calc' ),
			'CDEK allocation rows must not be empty' => __( 'Добавьте товары в грузоместа.', 'walls-delivery-calc' ),
			'allocation must contain at least one item' => __( 'Добавьте хотя бы один товар в отправление.', 'walls-delivery-calc' ),
			'shipment place must contain at least one item' => __( 'Каждое грузоместо должно содержать хотя бы один товар.', 'walls-delivery-calc' ),
		);
		foreach ( $translations as $needle => $translation ) {
			if ( str_contains( $message, $needle ) ) {
				return $translation;
			}
		}
		if ( preg_match( '/\b(must|failed|invalid|unknown|error|missing|required)\b/i', $message ) && ! preg_match( '/[А-Яа-яЁё]/u', $message ) ) {
			return __( 'Проверьте данные отправления.', 'walls-delivery-calc' );
		}

		return $message;
	}

}
