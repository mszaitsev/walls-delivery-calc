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


final class ShipmentAdminCarrierUiPayloadBuilder {

	public function __construct(
		private OrderShipmentRepository $repository,
		private DeliveryServiceRepository $services,
		private ShipmentStatusUpdateService $status_updates,
		private ?CdekOrderStatusService $cdek_status_updates = null,
		private ?ShipmentBacklogService $backlog = null,
		private ?CarrierShipmentAdapterRegistry $carrier_adapters = null,
		private ?ShipmentMetaboxButtonPolicy $button_policy = null,
		private ?ShipmentDocumentProviderRegistry $document_providers = null,
		private ?ShipmentDocumentDownloadService $document_downloads = null
	) {
	}

	private function can_cancel_shipment( array $shipment ): bool {
		if ( $this->backlog instanceof ShipmentBacklogService ) {
			return $this->backlog->can_cancel( $shipment );
		}
		$barcode = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
		$backlog_order_id = (int) ( $shipment['backlog_order_id'] ?? 0 );

		return '' !== $barcode
			&& $backlog_order_id > 0
			&& in_array( (string) ( $shipment['status'] ?? '' ), array( 'created', 'registered' ), true )
			&& ( '28' === (string) ( $shipment['carrier_operation_type_id'] ?? '' ) || 'Присвоение идентификатора' === (string) ( $shipment['carrier_operation_type_name'] ?? '' ) );
	}

	private function button_policy(): ShipmentMetaboxButtonPolicy {
		if ( ! $this->button_policy instanceof ShipmentMetaboxButtonPolicy ) {
			$this->button_policy = new ShipmentMetaboxButtonPolicy();
		}

		return $this->button_policy;
	}

	private function status_payload_for_carrier( object $order, string $carrier_key ): array {
		$shipment = $this->repository->find_by_carrier( $order, $carrier_key );
		$adapter = $this->carrier_adapter( $carrier_key );
		if ( null !== $adapter ) {
			return array_merge(
				$adapter->status_payload( $order, $shipment ),
				array(
					'carrier_key' => $carrier_key,
					'presentation' => $this->carrier_presentation( $carrier_key ),
				)
			);
		}
		if ( CdekSettings::CARRIER_KEY === $carrier_key && $this->cdek_status_updates instanceof CdekOrderStatusService ) {
			return array_merge( $this->cdek_status_updates->status_payload( $shipment, $order ), array( 'presentation' => $this->carrier_presentation( $carrier_key ) ) );
		}
		if ( RussianPostDomesticSettings::CARRIER_KEY === $carrier_key ) {
			return array_merge( $this->status_updates->status_payload( $shipment, $order ), array( 'carrier_key' => $carrier_key, 'has_shipment' => array() !== $shipment, 'can_update_status' => array() !== $shipment, 'can_remove_from_order' => array() !== $shipment && ! $this->can_cancel_shipment( $shipment ), 'presentation' => $this->carrier_presentation( $carrier_key ) ) );
		}

		return array_merge(
			array( 'carrier_key' => $carrier_key, 'presentation' => $this->carrier_presentation( $carrier_key ) ),
			$shipment
		);
	}

	public function carrier_ui_payload( object $order, string $carrier_key, ?array $shipment_override = null ): array {
		$shipment = null === $shipment_override ? $this->repository->find_by_carrier( $order, $carrier_key ) : $shipment_override;
		$adapter = $this->carrier_adapter( $carrier_key );
		$presentation = $this->carrier_presentation( $carrier_key );
		$status = null !== $adapter
			? $adapter->status_payload( $order, $shipment )
			: $this->status_payload_for_carrier( $order, $carrier_key );
		$status = array_merge(
			$status,
			array(
				'carrier_key' => $carrier_key,
				'presentation' => $presentation,
			)
		);
		$status = $this->with_actual_cost_defaults( $status, $shipment );
		$document_actions = $this->document_actions_for_carrier( $order, $carrier_key, $shipment );
		if ( array() !== $document_actions ) {
			$status['document_actions'] = $document_actions;
		}

		return array(
			'carrier_key' => $carrier_key,
			'shipment' => $shipment,
			'status' => $status,
			'presentation' => $presentation,
			'document_actions' => $document_actions,
			'has_shipment' => ! empty( $status['has_shipment'] ),
			'can_create' => ! empty( $status['can_create'] ),
			'can_attach_manual' => ! empty( $status['can_attach_manual'] ),
			'can_update_status' => ! empty( $status['can_update_status'] ),
			'can_cancel' => ! empty( $status['can_cancel'] ),
			'can_remove_from_order' => ! empty( $status['can_remove_from_order'] ),
		);
	}

	/**
	 * @param array<string,mixed> $status
	 * @param array<string,mixed> $shipment
	 * @return array<string,mixed>
	 */
	private function with_actual_cost_defaults( array $status, array $shipment ): array {
		$actual = $this->positive_int_or_null( $status['actual_cost_kopecks'] ?? $shipment['actual_cost_kopecks'] ?? null );
		$status['actual_cost_kopecks'] = $actual;
		$status['has_actual_cost'] = null !== $actual && $actual > 0;
		foreach ( array(
			'actual_cost_label' => '',
			'actual_cost_source' => (string) ( $shipment['actual_cost_source'] ?? '' ),
			'actual_cost_source_detail' => (string) ( $shipment['actual_cost_source_detail'] ?? '' ),
			'actual_cost_updated_at' => (string) ( $shipment['actual_cost_updated_at'] ?? '' ),
			'actual_cost_compare_status' => '',
			'actual_cost_compare_message' => '',
		) as $key => $default ) {
			if ( ! array_key_exists( $key, $status ) ) {
				$status[ $key ] = $default;
			}
		}

		return $status;
	}

	private function positive_int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) {
			$integer = (int) $value;

			return $integer > 0 ? $integer : null;
		}

		return null;
	}

	private function tracking_presentation( array $status, array $presentation, string $fallback_value ): array {
		$tracking = is_array( $status['tracking_presentation'] ?? null ) ? $status['tracking_presentation'] : array();
		$label = trim( (string) ( $tracking['label'] ?? $presentation['tracking_label'] ?? __( 'Отслеживание', 'walls-delivery-calc' ) ) );
		$display_text = trim( (string) ( $tracking['display_text'] ?? $fallback_value ) );
		$url = $this->safe_tracking_url( (string) ( $tracking['url'] ?? '' ) );
		$copy_value = trim( (string) ( $tracking['copy_value'] ?? '' ) );

		if ( '' !== $url ) {
			$display_text = '' !== $display_text ? $display_text : $url;
			$copy_value = '' !== $copy_value ? $copy_value : $url;
		} elseif ( '' === $copy_value ) {
			$copy_value = $display_text;
		}

		return array(
			'label' => '' !== $label ? $label : __( 'Отслеживание', 'walls-delivery-calc' ),
			'display_text' => $display_text,
			'url' => $url,
			'copy_value' => $copy_value,
		);
	}

	private function with_status_presentation( array $status, string $carrier_key ): array {
		return array_merge(
			array( 'carrier_key' => $carrier_key ),
			$status,
			array( 'presentation' => $this->carrier_presentation( $carrier_key ) )
		);
	}

	private function status_block_label( string $carrier_key ): string {
		return $this->carrier_presentation( $carrier_key )['status_title'];
	}

	public function carrier_presentation( string $carrier_key ): array {
		$common = array(
			'carrier_label' => __( 'службы доставки', 'walls-delivery-calc' ),
			'status_title' => __( 'Статус службы доставки', 'walls-delivery-calc' ),
			'tracking_label' => __( 'Отслеживание', 'walls-delivery-calc' ),
			'create_button_label' => __( 'Подготовить отправление', 'walls-delivery-calc' ),
			'manual_attach_button_label' => __( 'Внести отслеживание вручную', 'walls-delivery-calc' ),
			'cancel_button_label' => __( 'Отменить отправление', 'walls-delivery-calc' ),
			'remove_button_label' => __( 'Удалить из заказа', 'walls-delivery-calc' ),
			'update_status_button_label' => __( 'Обновить статус', 'walls-delivery-calc' ),
			'manual_attach_field_label' => __( 'Номер отслеживания', 'walls-delivery-calc' ),
			'manual_attach_placeholder' => __( 'Номер отслеживания', 'walls-delivery-calc' ),
			'manual_attach_help' => __( 'Введите номер отслеживания для поиска и привязки отправления.', 'walls-delivery-calc' ),
			'created_toast' => __( 'Отправление создано.', 'walls-delivery-calc' ),
			'updated_toast' => __( 'Статус отправления обновлен.', 'walls-delivery-calc' ),
			'cancel_success_toast' => __( 'Отправление отменено.', 'walls-delivery-calc' ),
			'remove_success_toast' => __( 'Данные отправления удалены из заказа.', 'walls-delivery-calc' ),
			'error_fallback_message' => __( 'Не удалось получить статус отправления.', 'walls-delivery-calc' ),
			'polling_timeout_message' => __( 'Автоматическая проверка завершена. Если статус еще не обновился, воспользуйтесь кнопкой «Обновить статус».', 'walls-delivery-calc' ),
			'remove_confirmation_message' => '',
			'registration_error_toast' => __( 'Регистрация завершилась ошибкой.', 'walls-delivery-calc' ),
			'registration_success_toast' => __( 'Регистрация завершена успешно.', 'walls-delivery-calc' ),
			'auto_poll_registration' => '0',
			'registration_poll_interval_ms' => '5000',
			'registration_poll_max_attempts' => '14',
		);
		$adapter = $this->carrier_adapter( $carrier_key );
		if ( null !== $adapter ) {
			return array_merge( $common, $adapter->presentation() );
		}

		if ( CdekSettings::CARRIER_KEY === $carrier_key ) {
			return array_merge(
				$common,
				array(
					'carrier_label' => 'СДЭК',
					'status_title' => 'Статус СДЭК',
					'tracking_label' => 'Номер СДЭК',
					'create_button_label' => 'Создать отправление СДЭК',
					'manual_attach_button_label' => 'Внести номер СДЭК вручную',
					'manual_attach_placeholder' => 'Номер СДЭК',
					'manual_attach_help' => 'Введите номер СДЭК для поиска и привязки отправления.',
					'cancel_button_label' => 'Отменить отправление в СДЭК',
					'remove_button_label' => 'Удалить из заказа',
					'created_toast' => 'Заявка на регистрацию СДЭК принята.',
					'updated_toast' => 'Статус СДЭК обновлен.',
					'cancel_success_toast' => 'Отправление СДЭК отменено.',
					'remove_success_toast' => 'Данные СДЭК-отправления удалены из заказа.',
					'polling_timeout_message' => 'Автоматическая проверка завершена. Если статус еще не обновился, воспользуйтесь кнопкой «Обновить статус».',
					'registration_error_toast' => 'Регистрация СДЭК завершилась ошибкой.',
					'registration_success_toast' => 'Регистрация СДЭК завершена успешно.',
					'auto_poll_registration' => '1',
				)
			);
		}

		if ( RussianPostDomesticSettings::CARRIER_KEY === $carrier_key || '' === $carrier_key ) {
			return array_merge(
				$common,
				array(
					'carrier_label' => 'Почта России',
					'status_title' => 'Статус Почты России',
					'tracking_label' => 'Отслеживание',
					'create_button_label' => 'Подготовить отправление',
					'manual_attach_button_label' => 'Внести отслеживание вручную',
					'manual_attach_placeholder' => 'Номер отслеживания',
					'manual_attach_help' => 'Введите номер отслеживания для поиска и привязки отправления.',
				)
			);
		}

		return $common;
	}

	public function carrier_adapter( string $carrier_key ): ?CarrierShipmentAdapterInterface {
		return $this->carrier_adapters instanceof CarrierShipmentAdapterRegistry ? $this->carrier_adapters->get( $carrier_key ) : null;
	}

	private function document_actions_for_carrier( object $order, string $carrier_key, array $shipment ): array {
		if ( ! $this->document_providers instanceof ShipmentDocumentProviderRegistry || ! $this->document_downloads instanceof ShipmentDocumentDownloadService ) {
			return array();
		}
		$provider = $this->document_providers->get( $carrier_key );
		if ( null === $provider ) {
			return array();
		}
		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$actions = array();
		foreach ( $provider->actions( $order, $shipment ) as $action ) {
			if ( ! $action instanceof ShipmentDocumentAction || ! $action->visible ) {
				continue;
			}
			$row = $action->to_array();
			$row['download_url'] = $this->document_downloads->download_url( $order_id, $carrier_key, $action->key );
			$actions[] = $row;
		}

		return $actions;
	}

	public function document_download_url( int $order_id, string $carrier_key, string $action_key ): string {
		if ( $this->document_downloads instanceof ShipmentDocumentDownloadService ) {
			return $this->document_downloads->download_url( $order_id, $carrier_key, $action_key );
		}

		return '';
	}

}
