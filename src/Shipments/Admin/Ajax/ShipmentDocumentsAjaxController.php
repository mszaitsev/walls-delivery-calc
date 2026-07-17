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


final class ShipmentDocumentsAjaxController {

	public function __construct(
		private OrderShipmentRepository $repository,
		private ShipmentAdminCarrierUiPayloadBuilder $payloads,
		private ?CdekBarcodePrintService $cdek_barcode_print = null
	) {
	}

	public function handle_cdek_barcode_prepare(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( ShipmentAdminAjaxService::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) || $order_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}
		if ( ! $this->cdek_barcode_print instanceof CdekBarcodePrintService ) {
			wp_send_json_error( array( 'message' => __( 'Печать этикетки СДЭК недоступна.', 'walls-delivery-calc' ) ), 500 );
		}

		$result = $this->cdek_barcode_print->prepare_for_order( $order );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Не удалось подготовить этикетку СДЭК.', 'walls-delivery-calc' ) ) ), 400 );
		}

		if ( 'READY' === (string) ( $result['status'] ?? '' ) ) {
			$result['download_url'] = $this->payloads->document_download_url( $order_id, CdekSettings::CARRIER_KEY, 'download_label' );
		}

		wp_send_json_success( $result );
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
