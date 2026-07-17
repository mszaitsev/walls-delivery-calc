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


final class ShipmentLifecycleAjaxController {

	public function __construct(
		private ShipmentAdminCarrierUiPayloadBuilder $payloads
	) {
	}

	public function handle(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( ShipmentAdminAjaxService::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		try {
			$order_id = (int) ( $_POST['order_id'] ?? 0 );
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! is_object( $order ) || $order_id <= 0 ) {
				wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ), 'error_code' => 'shipment_lifecycle_invalid_request' ), 404 );
			}
			$carrier_key = sanitize_key( wp_unslash( $_POST['carrier_key'] ?? $_POST['shipment_key'] ?? '' ) );
			$continuation_token = sanitize_text_field( wp_unslash( $_POST['continuation_token'] ?? '' ) );
			if ( '' === $carrier_key || '' === $continuation_token ) {
				throw new \InvalidArgumentException( __( 'Не найден контекст продолжения регистрации отправления.', 'walls-delivery-calc' ) );
			}
			$adapter = $this->payloads->carrier_adapter( $carrier_key );
			if ( ! $adapter instanceof CarrierShipmentLifecycleContinuationInterface ) {
				throw new \InvalidArgumentException( __( 'Выбранная служба не поддерживает продолжение регистрации отправления.', 'walls-delivery-calc' ) );
			}
			$result = $adapter->continue_lifecycle( $order, $continuation_token );
			if ( empty( $result['success'] ) ) {
				wp_send_json_error(
					array_merge(
						$this->payloads->carrier_ui_payload( $order, $carrier_key, is_array( $result['shipment'] ?? null ) ? $result['shipment'] : null ),
						array(
							'message' => (string) ( $result['message'] ?? __( 'Не удалось продолжить регистрацию отправления.', 'walls-delivery-calc' ) ),
							'lifecycle' => is_array( $result['lifecycle'] ?? null ) ? $result['lifecycle'] : array(),
						)
					),
					400
				);
			}
			wp_send_json_success(
				array_merge(
					$this->payloads->carrier_ui_payload( $order, $carrier_key, is_array( $result['shipment'] ?? null ) ? $result['shipment'] : null ),
					$result,
					array(
						'message' => (string) ( $result['message'] ?? __( 'Регистрация отправления продолжена.', 'walls-delivery-calc' ) ),
						'lifecycle' => is_array( $result['lifecycle'] ?? null ) ? $result['lifecycle'] : array(),
					)
				)
			);
		} catch ( \InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $this->public_shipment_error_message( $exception->getMessage() ), 'error_code' => 'shipment_lifecycle_validation_failed' ), 400 );
		} catch ( \Throwable $exception ) {
			if ( str_contains( $exception::class, 'AjaxResponse' ) ) {
				throw $exception;
			}
			error_log(
				sprintf(
					'[walls-delivery-calc] shipment lifecycle continuation failed. class=%s message=%s location=%s:%d',
					$exception::class,
					$exception->getMessage(),
					$exception->getFile(),
					$exception->getLine()
				)
			);
			wp_send_json_error( array( 'message' => __( 'Не удалось продолжить регистрацию отправления. Подробности записаны в журнал ошибок.', 'walls-delivery-calc' ), 'error_code' => 'shipment_lifecycle_unexpected_error' ), 500 );
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
