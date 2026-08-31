<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\ShipmentBacklogService;
use WallsShop\WDC\Shipments\Application\ShipmentMetaboxButtonPolicy;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAddressAjaxController;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentActualCostAjaxController;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentCreateAjaxController;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentDocumentsAjaxController;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentLifecycleAjaxController;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentManualAttachAjaxController;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentPreviewAjaxController;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentProductsAjaxController;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentRemovalAjaxController;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentStatusAjaxController;
use WallsShop\WDC\Shipments\Cdek\CdekOrderStatusService;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentDownloadService;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentProviderRegistry;
use WallsShop\WDC\Shipments\Modal\CarrierShipmentModalExtensionInterface;
use WallsShop\WDC\Shipments\Modal\ShipmentModalExtensionRegistry;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OrderShipmentsMetabox {
	private const NONCE_ACTION = 'wdc_shipments_admin';
	private const AJAX_CREATE = 'wdc_create_shipment';
	private const AJAX_CONTINUE_LIFECYCLE = 'wdc_continue_shipment_lifecycle';
	private const AJAX_PREVIEW = 'wdc_preview_shipment';
	private const AJAX_UPDATE_STATUS = 'wdc_update_shipment_status';
	private const AJAX_MARK_POLL_EXHAUSTED = 'wdc_mark_shipment_poll_exhausted';
	private const AJAX_CANCEL = 'wdc_cancel_shipment';
	private const AJAX_REMOVE_FROM_ORDER = 'wdc_remove_shipment_from_order';
	private const AJAX_ATTACH_TRACKING = 'wdc_attach_shipment_tracking_number';
	private const AJAX_NORMALIZE_ADDRESS = 'wdc_normalize_shipment_address';
	private const AJAX_SEARCH_PICKUP_POINTS = 'wdc_search_russian_post_pickup_points';
	private const AJAX_SEARCH_PRODUCTS = 'wdc_search_products_for_shipment_item';
	private const AJAX_CDEK_BARCODE_PREPARE = 'wdc_cdek_barcode_prepare';
	private const AJAX_DPD_COURIER_CONTACT_HISTORY = 'wdc_dpd_courier_contact_history';
	private const AJAX_SAVE_ACTUAL_COST = 'wdc_save_shipment_actual_cost';
	private const AJAX_CLEAR_ACTUAL_COST = 'wdc_clear_shipment_actual_cost';

	public function __construct(
		private OrderShipmentRepository $repository,
		private OrderShipmentDraftFactory $drafts,
		private DeliveryServiceRepository $services,
		private ShipmentStatusUpdateService $status_updates,
		private ShipmentActualCostResolver $actual_costs,
		private ShipmentCreateAjaxController $ajax_create_controller,
		private ShipmentLifecycleAjaxController $ajax_lifecycle_controller,
		private ShipmentPreviewAjaxController $ajax_preview_controller,
		private ShipmentStatusAjaxController $ajax_status_controller,
		private ShipmentRemovalAjaxController $ajax_removal_controller,
		private ShipmentManualAttachAjaxController $ajax_manual_attach_controller,
		private ShipmentAddressAjaxController $ajax_address_controller,
		private ShipmentActualCostAjaxController $ajax_actual_cost_controller,
		private ShipmentDocumentsAjaxController $ajax_documents_controller,
		private ShipmentProductsAjaxController $ajax_products_controller,
		private ?CdekOrderStatusService $cdek_status_updates = null,
		private ?ShipmentBacklogService $backlog = null,
		private ?RussianPostPickupPointTypeSettings $pickup_point_type_settings = null,
		private string $plugin_url = '',
		private string $version = '1',
		private ?CarrierShipmentAdapterRegistry $carrier_adapters = null,
		private ?ShipmentMetaboxButtonPolicy $button_policy = null,
		private ?ShipmentDocumentProviderRegistry $document_providers = null,
		private ?ShipmentDocumentDownloadService $document_downloads = null,
		private ?ShipmentModalExtensionRegistry $modal_extensions = null
	) {
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_CREATE, array( $this->ajax_create_controller, 'handle' ) );
		add_action( 'wp_ajax_' . self::AJAX_CONTINUE_LIFECYCLE, array( $this->ajax_lifecycle_controller, 'handle' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( $this->ajax_preview_controller, 'handle' ) );
		add_action( 'wp_ajax_' . self::AJAX_UPDATE_STATUS, array( $this->ajax_status_controller, 'handle_update' ) );
		add_action( 'wp_ajax_' . self::AJAX_MARK_POLL_EXHAUSTED, array( $this->ajax_status_controller, 'handle_mark_poll_exhausted' ) );
		add_action( 'wp_ajax_' . self::AJAX_CANCEL, array( $this->ajax_removal_controller, 'handle_cancel' ) );
		add_action( 'wp_ajax_' . self::AJAX_REMOVE_FROM_ORDER, array( $this->ajax_removal_controller, 'handle_remove' ) );
		add_action( 'wp_ajax_' . self::AJAX_ATTACH_TRACKING, array( $this->ajax_manual_attach_controller, 'handle' ) );
		add_action( 'wp_ajax_' . self::AJAX_NORMALIZE_ADDRESS, array( $this->ajax_address_controller, 'handle_normalize' ) );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH_PICKUP_POINTS, array( $this->ajax_address_controller, 'handle_search_pickup_points' ) );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH_PRODUCTS, array( $this->ajax_products_controller, 'handle_search_products' ) );
		add_action( 'wp_ajax_' . self::AJAX_CDEK_BARCODE_PREPARE, array( $this->ajax_documents_controller, 'handle_cdek_barcode_prepare' ) );
		add_action( 'wp_ajax_' . self::AJAX_DPD_COURIER_CONTACT_HISTORY, array( $this->ajax_products_controller, 'handle_dpd_contact_history' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE_ACTUAL_COST, array( $this->ajax_actual_cost_controller, 'handle_save' ) );
		add_action( 'wp_ajax_' . self::AJAX_CLEAR_ACTUAL_COST, array( $this->ajax_actual_cost_controller, 'handle_clear' ) );
	}

	public function add_meta_box(): void {
		foreach ( array( 'shop_order', 'woocommerce_page_wc-orders' ) as $screen ) {
			add_meta_box(
				'wdc_order_shipments',
				__( 'Отправления', 'walls-delivery-calc' ),
				array( $this, 'render' ),
				$screen,
				'side',
				'default'
			);
		}
	}

	public function enqueue_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id = is_object( $screen ) ? (string) ( $screen->id ?? '' ) : '';
		if ( ! in_array( $id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}
		$provider = $this->map_provider();
		$provider_handle = 'wdc-map-provider-' . $provider;
		if ( 'leaflet' === $provider ) {
			wp_enqueue_style( 'wdc-leaflet', $this->plugin_url . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
			wp_enqueue_script( 'wdc-leaflet', $this->plugin_url . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
			wp_enqueue_script( $provider_handle, $this->plugin_url . 'assets/frontend/pickup-map/providers/wdc-map-provider-leaflet.js', array( 'wdc-leaflet' ), $this->version, true );
		} else {
			wp_enqueue_script( $provider_handle, $this->plugin_url . 'assets/frontend/pickup-map/providers/wdc-map-provider-yandex.js', array(), $this->version, true );
		}
		wp_enqueue_style( 'wdc-pickup-map', $this->plugin_url . 'assets/frontend/pickup-map/wdc-pickup-map.css', array(), $this->version );
		wp_enqueue_style( 'wdc-shipments-admin', $this->plugin_url . 'assets/admin/shipments-admin.css', array( 'wdc-pickup-map' ), $this->version );
		wp_enqueue_script( 'wdc-pickup-api', $this->plugin_url . 'assets/frontend/pickup-map/wdc-pickup-api.js', array(), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin-core', $this->plugin_url . 'assets/admin/shipments/shipment-core.js', array( $provider_handle, 'wdc-pickup-api' ), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin-allocation', $this->plugin_url . 'assets/admin/shipments/shipment-allocation.js', array( 'wdc-shipments-admin-core' ), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin-preview', $this->plugin_url . 'assets/admin/shipments/shipment-preview.js', array( 'wdc-shipments-admin-allocation' ), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin-status', $this->plugin_url . 'assets/admin/shipments/shipment-status.js', array( 'wdc-shipments-admin-preview' ), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin-polling', $this->plugin_url . 'assets/admin/shipments/shipment-polling.js', array( 'wdc-shipments-admin-status' ), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin-picker', $this->plugin_url . 'assets/admin/shipments/shipment-picker.js', array( 'wdc-shipments-admin-polling' ), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin-cdek', $this->plugin_url . 'assets/admin/shipments/extensions/cdek.js', array( 'wdc-shipments-admin-picker' ), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin-dpd', $this->plugin_url . 'assets/admin/shipments/extensions/dpd.js', array( 'wdc-shipments-admin-cdek' ), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin-russian-post', $this->plugin_url . 'assets/admin/shipments/extensions/russian-post.js', array( 'wdc-shipments-admin-dpd' ), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin-yandex', $this->plugin_url . 'assets/admin/shipments/extensions/yandex.js', array( 'wdc-shipments-admin-russian-post' ), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin-pek', $this->plugin_url . 'assets/admin/shipments/extensions/pek.js', array( 'wdc-shipments-admin-yandex' ), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin-events', $this->plugin_url . 'assets/admin/shipments/shipment-events.js', array( 'wdc-shipments-admin-pek' ), $this->version, true );
		wp_enqueue_script( 'wdc-shipments-admin', $this->plugin_url . 'assets/admin/shipments-admin.js', array( 'wdc-shipments-admin-events' ), $this->version, true );
		wp_localize_script(
			'wdc-shipments-admin',
			'wdcShipmentsAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'restUrl' => function_exists( 'rest_url' ) ? rest_url( 'wdc/v1/' ) : '',
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'nonce' => wp_create_nonce( self::NONCE_ACTION ),
				'createAction' => self::AJAX_CREATE,
				'continueLifecycleAction' => self::AJAX_CONTINUE_LIFECYCLE,
				'previewAction' => self::AJAX_PREVIEW,
				'updateStatusAction' => self::AJAX_UPDATE_STATUS,
				'markPollExhaustedAction' => self::AJAX_MARK_POLL_EXHAUSTED,
				'cancelAction' => self::AJAX_CANCEL,
				'removeFromOrderAction' => self::AJAX_REMOVE_FROM_ORDER,
				'attachTrackingAction' => self::AJAX_ATTACH_TRACKING,
				'normalizeAddressAction' => self::AJAX_NORMALIZE_ADDRESS,
				'searchPickupPointsAction' => self::AJAX_SEARCH_PICKUP_POINTS,
				'searchProductsAction' => self::AJAX_SEARCH_PRODUCTS,
				'cdekBarcodePrepareAction' => self::AJAX_CDEK_BARCODE_PREPARE,
				'dpdCourierContactHistoryAction' => self::AJAX_DPD_COURIER_CONTACT_HISTORY,
				'saveActualCostAction' => self::AJAX_SAVE_ACTUAL_COST,
				'clearActualCostAction' => self::AJAX_CLEAR_ACTUAL_COST,
				'dpdCourierContactHistory' => $this->dpd_courier_contact_history(),
				'mapProvider' => $provider,
				'yandexApiKeyPresent' => '' !== $this->yandex_api_key(),
				'yandexApiKey' => 'yandex' === $provider ? $this->yandex_api_key() : '',
				'pickupPointTypes' => ( $this->pickup_point_type_settings ?? new RussianPostPickupPointTypeSettings( new SettingsRepository() ) )->all(),
			)
		);
	}

	public function render( mixed $post_or_order ): void {
		try {
			$this->render_inner( $post_or_order );
		} catch ( \Throwable $exception ) {
			$order_id = 0;
			try {
				$order = $this->resolve_order( $post_or_order );
				$order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
			} catch ( \Throwable ) {
				$order_id = 0;
			}

			error_log(
				sprintf(
					'[walls-delivery-calc] shipments metabox render failed. order_id=%d class=%s message=%s location=%s:%d',
					$order_id,
					$exception::class,
					$exception->getMessage(),
					$exception->getFile(),
					$exception->getLine()
				)
			);

			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Не удалось подготовить блок отправлений. Подробности записаны в журнал ошибок.', 'walls-delivery-calc' ) . '</p></div>';
		}
	}

	private function render_inner( mixed $post_or_order ): void {
		$order = $this->resolve_order( $post_or_order );
		if ( ! is_object( $order ) ) {
			echo '<p>' . esc_html__( 'Заказ не найден.', 'walls-delivery-calc' ) . '</p>';
			return;
		}
		$error = $this->repository->last_error( $order );
		$draft = $this->drafts->draft_array( $order );
		$safe_preview = array(
			'status' => 'preview_pending',
			'message' => 'Предпросмотр будет загружен после открытия модалки.',
		);
		$request = is_array( $draft['request'] ?? null ) ? $draft['request'] : array();
		$services = is_array( $draft['services'] ?? null ) ? $draft['services'] : array();
		$modal_capabilities = is_array( $draft['modal_capabilities'] ?? null ) ? $draft['modal_capabilities'] : array();
		$recipient = is_array( $request['recipient'] ?? null ) ? $request['recipient'] : array();
		$place = is_array( $request['places'][0] ?? null ) ? $request['places'][0] : array();
		$place_rows = is_array( $request['places'] ?? null ) ? array_values( array_filter( $request['places'], 'is_array' ) ) : array();
		if ( array() === $place_rows ) {
			$place_rows = array( $place );
		}
		$place_rows = $this->editable_place_rows( $place_rows );
		$meta = is_array( $request['meta'] ?? null ) ? $request['meta'] : array();
		$carrier_key = (string) ( $request['carrier_key'] ?? $meta['carrier_key'] ?? '' );
		$service_key = (string) ( $meta['service_key'] ?? $request['rate_id'] ?? '' );
		$requires_tariff = (bool) ( $modal_capabilities['requires_tariff'] ?? false );
		$requires_successful_preview = (bool) ( $modal_capabilities['requires_successful_preview'] ?? false );
		$shipment = '' !== $carrier_key ? $this->repository->find_by_carrier( $order, $carrier_key ) : array();
		$settings = is_array( $request['services'] ?? null ) ? $request['services'] : array();
		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$selected_delivery_type = (string) ( $request['delivery_type'] ?? $meta['delivery_type'] ?? DeliveryType::PICKUP );
		if ( '' === $selected_delivery_type && array() !== $services ) {
			$selected_delivery_type = (string) ( $services[0]['delivery_type'] ?? DeliveryType::PICKUP );
		}
		$selected_tariff_object = '';
		$selected_service_tariffs = array();
		$selected_tariff_has_declared_value = false;
		$has_selected_service_tariffs = array() !== $selected_service_tariffs;
		$tariff_message_hidden_attr = $has_selected_service_tariffs ? ' hidden' : '';
		$calculated_weight_g = max( 0, (int) ( $meta['place_weight_hint_g'] ?? $place['weight_g'] ?? 0 ) );
		$weight_hint = $calculated_weight_g > 0 ? sprintf( __( '⚖️%d', 'walls-delivery-calc' ), $calculated_weight_g ) : '';
		$default_declared_value_rub = max( 0, (int) ( $meta['default_declared_value_rub'] ?? 0 ) );
		$default_declared_value_attr = $default_declared_value_rub > 0 ? (string) $default_declared_value_rub : '';
		$declared_value_initial = $selected_tariff_has_declared_value ? $default_declared_value_attr : '';
		$delivery_type = $selected_delivery_type;
		$barcode = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
		$backlog_order_id = trim( (string) ( $shipment['backlog_order_id'] ?? '' ) );
		$status_payload = $this->status_payload_for_carrier( $order, $carrier_key );
		$status_payload = array_merge( $status_payload, array( 'carrier_key' => $carrier_key ) );
		$status_payload = $this->actual_costs->enrich_status_payload( $status_payload, $shipment, $order );
		$presentation = $this->carrier_presentation( $carrier_key );
		$tracking_presentation = $this->tracking_presentation( $status_payload, $presentation, $barcode );
		$price_label = (string) $status_payload['actual_cost_label'];
		$price_compare_status = (string) $status_payload['actual_cost_compare_status'];
		$price_compare_message = (string) $status_payload['actual_cost_compare_message'];
		$has_actual_cost = (bool) $status_payload['has_actual_cost'];
		$yandex_self_pickup_code = trim( (string) ( $status_payload['yandex_self_pickup_node_code'] ?? $shipment['yandex_self_pickup_node_code'] ?? '' ) );
		$button_policy = $this->button_policy()->resolve( $carrier_key, $shipment, $status_payload, $this->can_cancel_shipment( $shipment ) );
		$has_created = ! empty( $button_policy['has_shipment'] );
		$can_cancel = ! empty( $button_policy['can_cancel'] );
		$show_primary_actions = ! empty( $button_policy['show_create'] );
		$show_manual_attach = ! empty( $button_policy['show_manual_attach'] );
		$show_update = ! empty( $button_policy['show_update'] );
		$show_cancel = ! empty( $button_policy['show_cancel'] );
		$show_remove = ! empty( $button_policy['show_remove'] );
		$document_actions = $this->document_actions_for_carrier( $order, $carrier_key, $shipment );
		$modal_extension = $this->modal_extensions instanceof ShipmentModalExtensionRegistry ? $this->modal_extensions->get( $carrier_key ) : null;
		$modal_extension_context = $modal_extension instanceof CarrierShipmentModalExtensionInterface ? $modal_extension->modal_context( $order, $draft ) : array();
		$modal_create_button_label = __( 'Создать отправление', 'walls-delivery-calc' );
		if ( array_key_exists( 'requires_tariff', $modal_extension_context ) ) {
			$requires_tariff = (bool) $modal_extension_context['requires_tariff'];
		}
		if ( array_key_exists( 'requires_successful_preview', $modal_extension_context ) ) {
			$requires_successful_preview = (bool) $modal_extension_context['requires_successful_preview'];
		}
		if ( array_key_exists( 'selected_tariff_object', $modal_extension_context ) ) {
			$selected_tariff_object = (string) $modal_extension_context['selected_tariff_object'];
		}
		if ( is_array( $modal_extension_context['selected_service_tariffs'] ?? null ) ) {
			$selected_service_tariffs = $modal_extension_context['selected_service_tariffs'];
		}
		if ( array_key_exists( 'selected_tariff_has_declared_value', $modal_extension_context ) ) {
			$selected_tariff_has_declared_value = (bool) $modal_extension_context['selected_tariff_has_declared_value'];
			$declared_value_initial = $selected_tariff_has_declared_value ? $default_declared_value_attr : '';
		}
		if ( array_key_exists( 'modal_create_button_label', $modal_extension_context ) ) {
			$modal_create_button_label = (string) $modal_extension_context['modal_create_button_label'];
		}
		$has_selected_service_tariffs = array() !== $selected_service_tariffs;
		$tariff_message_hidden_attr = $has_selected_service_tariffs ? ' hidden' : '';
		?>
		<div class="wdc-shipments-metabox" data-wdc-shipments-metabox data-carrier-key="<?php echo esc_attr( $carrier_key ); ?>" data-has-shipment="<?php echo $has_created ? '1' : '0'; ?>" <?php $this->render_presentation_attrs( $presentation ); ?>>
			<p><strong><?php echo esc_html__( 'Служба', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( (string) ( $meta['service_title'] ?? $request['rate_id'] ?? '-' ) ); ?></p>
			<p><strong><?php echo esc_html__( 'Статус посылки', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-shipment-summary-status><?php echo esc_html( $this->shipment_status_label( $shipment ) ); ?></span></p>
			<?php $tracking_items = is_array( $tracking_presentation['items'] ?? null ) ? $tracking_presentation['items'] : array(); ?>
			<p data-wdc-tracking-row <?php echo array() === $tracking_items && '' === $tracking_presentation['display_text'] && '' === $tracking_presentation['copy_value'] ? 'hidden' : ''; ?>><strong data-wdc-tracking-label><?php echo esc_html( $tracking_presentation['label'] ); ?></strong>: <span data-wdc-tracking-number><?php $this->render_tracking_value( $tracking_presentation ); ?></span> <?php if ( array() === $tracking_items ) : ?><button type="button" class="wdc-copy-tracking-icon" data-wdc-copy-tracking data-tracking-number="<?php echo esc_attr( $tracking_presentation['copy_value'] ); ?>" aria-label="<?php echo esc_attr__( 'Копировать номер отслеживания', 'walls-delivery-calc' ); ?>" title="<?php echo esc_attr__( 'Копировать', 'walls-delivery-calc' ); ?>" <?php disabled( '' === $tracking_presentation['copy_value'] ); ?>>🗐</button><?php endif; ?> <span class="description" data-wdc-copy-tracking-status></span></p>
			<?php $return_tracking = is_array( $status_payload['return_tracking_presentation'] ?? null ) ? $this->normalize_tracking_presentation( $status_payload['return_tracking_presentation'] ) : array( 'label' => 'Возврат Ozon', 'display_text' => '', 'url' => '', 'copy_value' => '', 'items' => array() ); ?>
			<?php $return_tracking_items = is_array( $return_tracking['items'] ?? null ) ? $return_tracking['items'] : array(); ?>
			<p data-wdc-return-tracking-row <?php echo array() === $return_tracking_items && '' === $return_tracking['display_text'] && '' === $return_tracking['copy_value'] ? 'hidden' : ''; ?>><strong data-wdc-return-tracking-label><?php echo esc_html( $return_tracking['label'] ); ?></strong>: <span data-wdc-return-tracking-number><?php $this->render_tracking_value( $return_tracking ); ?></span> <?php if ( array() === $return_tracking_items ) : ?><button type="button" class="wdc-copy-tracking-icon" data-wdc-copy-tracking data-wdc-return-copy-tracking data-tracking-number="<?php echo esc_attr( $return_tracking['copy_value'] ); ?>" aria-label="<?php echo esc_attr__( 'Копировать номер возврата', 'walls-delivery-calc' ); ?>" title="<?php echo esc_attr__( 'Копировать', 'walls-delivery-calc' ); ?>" <?php disabled( '' === $return_tracking['copy_value'] ); ?>>🗐</button><?php endif; ?> <span class="description" data-wdc-return-copy-tracking-status></span></p>
			<p data-wdc-yandex-self-pickup-code-row <?php echo '' === $yandex_self_pickup_code ? 'hidden' : ''; ?>><strong><?php echo esc_html__( 'Код для получения', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-yandex-self-pickup-code><?php echo esc_html( $yandex_self_pickup_code ); ?></span></p>
			<p data-wdc-shipment-price-row class="<?php echo esc_attr( $this->shipment_price_class( $price_compare_status ) ); ?>" title="<?php echo esc_attr( $price_compare_message ); ?>" <?php echo '' === $price_label ? 'hidden' : ''; ?>><strong><?php echo esc_html__( 'Цена', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-shipment-price-label><?php echo esc_html( $price_label ); ?></span></p>
			<div class="wdc-shipment-actual-cost" data-wdc-shipment-actual-cost data-wdc-shipment-actual-cost-control data-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-shipment-key="<?php echo esc_attr( $carrier_key ); ?>" <?php echo $has_created ? '' : 'hidden'; ?>>
				<p data-wdc-actual-cost-input-wrap <?php echo $has_created && ! $has_actual_cost ? '' : 'hidden'; ?>><input type="text" inputmode="decimal" data-wdc-actual-cost-input placeholder="<?php echo esc_attr__( 'Фактическая стоимость, ₽', 'walls-delivery-calc' ); ?>"> <button type="button" class="button" data-wdc-save-actual-cost <?php echo $has_created && ! $has_actual_cost ? '' : 'hidden'; ?>><?php echo esc_html__( 'Сохранить', 'walls-delivery-calc' ); ?></button></p>
				<p><button type="button" class="button-link" data-wdc-clear-actual-cost <?php echo $has_created && $has_actual_cost ? '' : 'hidden'; ?>><?php echo esc_html__( 'Очистить фактическую стоимость', 'walls-delivery-calc' ); ?></button></p>
			</div>
			<p data-wdc-updated-row <?php echo '' === (string) ( $shipment['updated_at'] ?? '' ) ? 'hidden' : ''; ?>><strong><?php echo esc_html__( 'Обновлено', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-updated-at><?php echo esc_html( (string) ( $shipment['updated_at'] ?? '' ) ); ?></span></p>
			<?php $this->render_status_block( $status_payload ); ?>
			<span data-wdc-backlog-order-id hidden><?php echo esc_html( $backlog_order_id ); ?></span>
			<?php if ( array() !== $error && ! $has_created ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( (string) ( $error['error_message'] ?? '' ) ); ?></p></div><?php endif; ?>
			<div class="wdc-shipment-status-message" data-wdc-shipment-status-message></div>
			<?php if ( '' === $carrier_key ) : ?><p class="description"><?php echo esc_html__( 'Служба доставки для отправления не определена.', 'walls-delivery-calc' ); ?></p><?php endif; ?>
			<p class="wdc-shipments-actions">
				<button type="button" class="button button-primary" data-wdc-open-shipment-modal <?php echo $show_primary_actions ? '' : 'hidden'; ?> <?php disabled( ! $show_primary_actions ); ?>><?php echo esc_html( $presentation['create_button_label'] ); ?></button>
				<button type="button" class="button" data-wdc-update-shipment-status data-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-shipment-key="<?php echo esc_attr( $carrier_key ); ?>" <?php echo $show_update ? '' : 'hidden'; ?> <?php disabled( ! $show_update ); ?>><?php echo esc_html( $presentation['update_status_button_label'] ); ?></button>
				<?php $this->render_document_action_links( $document_actions, $order_id ); ?>
				<button type="button" class="button" data-wdc-open-manual-tracking <?php echo $show_manual_attach ? '' : 'hidden'; ?> <?php disabled( ! $show_manual_attach ); ?>><?php echo esc_html( $presentation['manual_attach_button_label'] ); ?></button>
				<button type="button" class="button" data-wdc-cancel-shipment data-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-shipment-key="<?php echo esc_attr( $carrier_key ); ?>" <?php echo $show_cancel ? '' : 'hidden'; ?> <?php disabled( ! $can_cancel ); ?>><?php echo esc_html( $presentation['cancel_button_label'] ); ?></button>
				<button type="button" class="button" data-wdc-remove-shipment-from-order data-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-shipment-key="<?php echo esc_attr( $carrier_key ); ?>" <?php echo $show_remove ? '' : 'hidden'; ?> <?php disabled( ! $show_remove ); ?>><?php echo esc_html( $presentation['remove_button_label'] ); ?></button>
			</p>
			<div class="wdc-manual-tracking" data-wdc-manual-tracking-form hidden>
				<label><span data-wdc-manual-attach-label><?php echo esc_html( $presentation['manual_attach_field_label'] ?? $presentation['manual_attach_placeholder'] ); ?></span><input type="text" data-wdc-manual-tracking-input autocomplete="off" placeholder="<?php echo esc_attr( $presentation['manual_attach_placeholder'] ); ?>"></label>
				<p class="description" data-wdc-manual-attach-help><?php echo esc_html( $presentation['manual_attach_help'] ); ?></p>
				<p>
					<button type="button" class="button button-primary" data-wdc-attach-tracking data-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-shipment-key="<?php echo esc_attr( $carrier_key ); ?>"><?php echo esc_html__( 'Найти и сохранить', 'walls-delivery-calc' ); ?></button>
					<button type="button" class="button" data-wdc-cancel-manual-tracking><?php echo esc_html__( 'Отмена', 'walls-delivery-calc' ); ?></button>
				</p>
			</div>
			<div class="wdc-shipment-modal" data-wdc-shipment-modal hidden>
				<div class="wdc-shipment-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__( 'Подготовка отправления', 'walls-delivery-calc' ); ?>">
					<button type="button" class="wdc-shipment-modal__close" data-wdc-close-shipment-modal aria-label="<?php echo esc_attr__( 'Закрыть', 'walls-delivery-calc' ); ?>">×</button>
					<h2><?php echo esc_html__( 'Подготовка отправления', 'walls-delivery-calc' ); ?></h2>
					<div id="wdc-shipment-form-<?php echo esc_attr( (string) $order_id ); ?>" class="wdc-shipment-form" data-wdc-shipment-form="1" data-wdc-requires-tariff="<?php echo $requires_tariff ? '1' : '0'; ?>" data-wdc-requires-successful-preview="<?php echo $requires_successful_preview ? '1' : '0'; ?>" role="group">
						<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>">
						<input type="hidden" name="carrier_key" value="<?php echo esc_attr( $carrier_key ); ?>">
						<div class="wdc-shipment-tabs" role="tablist">
							<button type="button" class="button wdc-shipment-tab is-active" data-wdc-shipment-tab="main"><?php echo esc_html__( 'Основное', 'walls-delivery-calc' ); ?></button>
							<button type="button" class="button wdc-shipment-tab" data-wdc-shipment-tab="places"><?php echo esc_html__( 'Грузоместа', 'walls-delivery-calc' ); ?></button>
						</div>
						<div data-wdc-shipment-tab-panel="main">
						<div class="wdc-shipment-grid">
							<section>
								<h3><?php echo esc_html__( 'Получатель', 'walls-delivery-calc' ); ?></h3>
								<label><?php echo esc_html__( 'ФИО', 'walls-delivery-calc' ); ?><input name="recipient_name" value="<?php echo esc_attr( (string) ( $recipient['name'] ?? '' ) ); ?>"></label>
								<label><?php echo esc_html__( 'Телефон', 'walls-delivery-calc' ); ?><input name="recipient_phone" value="<?php echo esc_attr( (string) ( $recipient['phone'] ?? '' ) ); ?>"></label>
								<label>Email<input name="recipient_email" value="<?php echo esc_attr( (string) ( $recipient['email'] ?? '' ) ); ?>"></label>
								<div data-wdc-pickup-section <?php echo DeliveryType::PICKUP === $delivery_type ? '' : 'hidden'; ?>>
									<?php if ( $modal_extension instanceof CarrierShipmentModalExtensionInterface ) : ?>
										<?php $modal_extension->render_pickup_fields( $order, $draft, $modal_extension_context ); ?>
									<?php endif; ?>
								</div>
								<div data-wdc-courier-section <?php echo DeliveryType::COURIER === $delivery_type ? '' : 'hidden'; ?>>
									<?php if ( $modal_extension instanceof CarrierShipmentModalExtensionInterface ) : ?>
										<?php $modal_extension->render_courier_fields( $order, $draft, $modal_extension_context ); ?>
									<?php endif; ?>
								</div>
							</section>
							<section>
								<h3><?php echo esc_html__( 'Доставка', 'walls-delivery-calc' ); ?></h3>
								<input type="hidden" name="service_key" value="<?php echo esc_attr( $service_key ); ?>">
								<label><?php echo esc_html__( 'Сценарий доставки', 'walls-delivery-calc' ); ?><select name="delivery_type" data-wdc-service-select>
									<?php foreach ( $services as $service ) : ?>
										<option value="<?php echo esc_attr( (string) $service['delivery_type'] ); ?>" data-service-key="<?php echo esc_attr( (string) $service['service_key'] ); ?>" data-delivery-type="<?php echo esc_attr( (string) $service['delivery_type'] ); ?>" data-tariffs="<?php echo esc_attr( wp_json_encode( $service['tariffs'] ?? array(), JSON_UNESCAPED_UNICODE ) ?: '[]' ); ?>" <?php selected( $selected_delivery_type, (string) $service['delivery_type'] ); ?>><?php echo esc_html( (string) $service['title'] ); ?></option>
									<?php endforeach; ?>
								</select></label>
								<?php if ( $requires_tariff ) : ?>
								<label><?php echo esc_html__( 'Тариф', 'walls-delivery-calc' ); ?><select name="tariff_object" data-wdc-tariff-select data-selected-tariff="<?php echo esc_attr( $selected_tariff_object ); ?>" <?php disabled( ! $has_selected_service_tariffs ); ?>>
									<?php foreach ( $selected_service_tariffs as $tariff ) : ?>
										<?php
										$tariff_object = (string) ( $tariff['object_code'] ?? '' );
										if ( '' === $tariff_object ) {
											continue;
										}
										?>
										<option value="<?php echo esc_attr( $tariff_object ); ?>" data-selected-missing="<?php echo ! empty( $tariff['selected_missing'] ) ? '1' : '0'; ?>" data-delivery-mode="<?php echo esc_attr( (string) (int) ( $tariff['delivery_mode'] ?? 0 ) ); ?>" <?php selected( $selected_tariff_object, $tariff_object ); ?>><?php echo esc_html( (string) ( $tariff['title'] ?? $tariff_object ) ); ?></option>
									<?php endforeach; ?>
								</select></label>
								<p class="description" data-wdc-tariff-message<?php echo $tariff_message_hidden_attr; ?>><?php echo esc_html__( 'Для выбранной службы доставки нет включенных тарифов. Включите тариф на странице настроек службы доставки.', 'walls-delivery-calc' ); ?></p>
								<?php endif; ?>
								<?php if ( $modal_extension instanceof CarrierShipmentModalExtensionInterface ) : ?>
									<?php $modal_extension->render_fields( $order, $draft, $modal_extension_context ); ?>
								<?php endif; ?>
							</section>
						</div>
						<section>
							<h3><?php echo esc_html__( 'Грузоместа', 'walls-delivery-calc' ); ?></h3>
							<?php if ( '' !== $weight_hint && count( $place_rows ) > 1 ) : ?><p class="description" data-wdc-weight-hint><?php echo esc_html( $weight_hint ); ?></p><?php endif; ?>
							<div data-wdc-places>
								<?php foreach ( $place_rows as $place_index => $place_row ) : ?>
									<?php
									$declared_value_for_place = 0 === $place_index ? $declared_value_initial : '';
									$weight_hint_for_place = 1 === count( $place_rows ) && 0 === $place_index ? $weight_hint : '';
									?>
									<div class="wdc-place-row" data-wdc-place>
										<div class="wdc-place-row__title" data-wdc-place-title><?php echo esc_html( sprintf( __( 'Место %d', 'walls-delivery-calc' ), $place_index + 1 ) ); ?></div>
										<label class="wdc-place-field"><?php echo esc_html__( 'Вес, г', 'walls-delivery-calc' ); ?> <?php if ( '' !== $weight_hint_for_place ) : ?><span class="wdc-weight-hint" data-wdc-weight-hint><?php echo esc_html( $weight_hint_for_place ); ?></span><?php endif; ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[<?php echo esc_attr( (string) $place_index ); ?>][weight_g]" value="<?php echo esc_attr( (string) ( $place_row['weight_g'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'г', 'walls-delivery-calc' ); ?>"></label>
										<label class="wdc-place-field"><?php echo esc_html__( 'Длина, см', 'walls-delivery-calc' ); ?><input type="text" inputmode="decimal" autocomplete="off" data-wdc-decimal-input="2" name="places[<?php echo esc_attr( (string) $place_index ); ?>][length_cm]" value="<?php echo esc_attr( (string) ( $place_row['length_cm'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'см', 'walls-delivery-calc' ); ?>"></label>
										<label class="wdc-place-field"><?php echo esc_html__( 'Ширина, см', 'walls-delivery-calc' ); ?><input type="text" inputmode="decimal" autocomplete="off" data-wdc-decimal-input="2" name="places[<?php echo esc_attr( (string) $place_index ); ?>][width_cm]" value="<?php echo esc_attr( (string) ( $place_row['width_cm'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'см', 'walls-delivery-calc' ); ?>"></label>
										<label class="wdc-place-field"><?php echo esc_html__( 'Высота, см', 'walls-delivery-calc' ); ?><input type="text" inputmode="decimal" autocomplete="off" data-wdc-decimal-input="2" name="places[<?php echo esc_attr( (string) $place_index ); ?>][height_cm]" value="<?php echo esc_attr( (string) ( $place_row['height_cm'] ?? '' ) ); ?>" placeholder="<?php echo esc_attr__( 'см', 'walls-delivery-calc' ); ?>"></label>
										<label class="wdc-place-field" data-wdc-declared-value-field data-default-declared-value-rub="<?php echo esc_attr( $default_declared_value_attr ); ?>" <?php echo $selected_tariff_has_declared_value ? '' : 'hidden'; ?>><?php echo esc_html__( 'Страховка, руб.', 'walls-delivery-calc' ); ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[<?php echo esc_attr( (string) $place_index ); ?>][declared_value_rub]" value="<?php echo esc_attr( $declared_value_for_place ); ?>" <?php disabled( ! $selected_tariff_has_declared_value ); ?>></label>
										<button type="button" class="button" data-wdc-remove-place <?php disabled( count( $place_rows ) <= 1 ); ?>><?php echo esc_html__( 'Удалить', 'walls-delivery-calc' ); ?></button>
									</div>
								<?php endforeach; ?>
							</div>
							<button type="button" class="button" data-wdc-add-place><?php echo esc_html__( 'Добавить место', 'walls-delivery-calc' ); ?></button>
						</section>
						<?php if ( ! empty( $settings['send_goods_items'] ) ) : ?>
							<section>
								<h3><?php echo esc_html__( 'Состав вложения', 'walls-delivery-calc' ); ?></h3>
								<label><input type="checkbox" name="send_goods_items" value="1" checked> <?php echo esc_html__( 'Передавать goods.items', 'walls-delivery-calc' ); ?></label>
								<label><input type="checkbox" name="combine_goods_items" value="1" <?php checked( ! empty( $settings['combine_goods_items'] ) ); ?>> <?php echo esc_html__( 'Объединить товары в одну строку', 'walls-delivery-calc' ); ?></label>
								<label><?php echo esc_html__( 'Название объединенной строки', 'walls-delivery-calc' ); ?><input name="combined_goods_name" value="<?php echo esc_attr( (string) ( $settings['combined_goods_name'] ?? '' ) ); ?>"></label>
							</section>
						<?php endif; ?>
						</div>
						<div data-wdc-shipment-tab-panel="places" hidden>
							<section>
								<h3><?php echo esc_html__( 'Грузоместа', 'walls-delivery-calc' ); ?></h3>
								<div class="wdc-cdek-items-summary" data-wdc-shipment-items-summary></div>
								<?php $this->render_shipment_item_rows( $request ); ?>
							</section>
						</div>
						<section>
							<h3><?php echo esc_html__( 'Проверка', 'walls-delivery-calc' ); ?></h3>
							<div class="wdc-shipment-errors" data-wdc-shipment-errors></div>
							<pre class="wdc-shipment-preview" data-wdc-shipment-preview><?php echo esc_html( wp_json_encode( $safe_preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ?: '{}' ); ?></pre>
							<button type="button" class="button" data-wdc-preview-shipment><?php echo esc_html__( 'Предпросмотр payload', 'walls-delivery-calc' ); ?></button>
							<button type="button" class="button button-primary" data-wdc-create-shipment><?php echo esc_html( $modal_create_button_label ); ?></button>
						</section>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<int,array<string,mixed>> $draft_place_rows
	 * @return array<int,array<string,mixed>>
	 */
	private function editable_place_rows( array $draft_place_rows ): array {
		$rows = array();
		foreach ( array_values( $draft_place_rows ) as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$row['place_number'] = (int) ( $row['place_number'] ?? $row['number'] ?? ( $index + 1 ) );
			$row['weight_g'] = '';
			$row['length_cm'] = '';
			$row['width_cm'] = '';
			$row['height_cm'] = '';
			$rows[] = $row;
		}

		return array() !== $rows ? $rows : array( array( 'place_number' => 1, 'weight_g' => '', 'length_cm' => '', 'width_cm' => '', 'height_cm' => '' ) );
	}

	/**
	 * @return array<int,string>
	 */
	private function dpd_courier_contact_history(): array {
		$settings = new SettingsRepository();
		$values = $settings->get_array( DpdSettings::COURIER_CONTACT_FIO_HISTORY_KEY, array() );

		return $this->sanitize_dpd_courier_contact_history( $values );
	}

	/**
	 * @param array<int|string,mixed> $values
	 * @return array<int,string>
	 */
	private function sanitize_dpd_courier_contact_history( array $values ): array {
		$history = array();
		foreach ( $values as $value ) {
			$value = substr( sanitize_text_field( wp_unslash( (string) $value ) ), 0, 120 );
			if ( '' !== $value && ! in_array( $value, $history, true ) ) {
				$history[] = $value;
			}
		}

		return array_slice( $history, 0, 20 );
	}

	/**
	 * @return array<string,mixed>
	 */
	/**
	 * @param array<string,mixed> $item
	 */
	/** @param array<string,mixed> $data */
	/**
	 * @return array<string,string>
	 */
	/**
	 * @param array<string,mixed> $data
	 * @return array{error:string}
	 */
	/**
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	/**
	 * @return array<string,mixed>
	 */
	/**
	 * @param array<string,mixed> $calculation
	 * @param array<string,mixed> $rate_meta
	 */
	/**
	 * @return array<int,string>
	 */
	/**
	 * @return array<int,string>
	 */
	/**
	 * @return array<int,string>
	 */
	/**
	 * @param array<int|string,mixed> $values
	 * @return array<int,string>
	 */
	/**
	 * @return array<int,int>
	 */
	/**
	 * @return array<string,mixed>
	 */
	private function resolve_order( mixed $post_or_order ): ?object {
		if ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' ) && method_exists( $post_or_order, 'get_meta' ) ) {
			return $post_or_order;
		}
		$order_id = is_object( $post_or_order ) && isset( $post_or_order->ID ) ? (int) $post_or_order->ID : 0;

		return $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	}

	/**
	 * @param array<string,mixed> $request
	 */
	private function render_shipment_item_rows( array $request ): void {
		$places = is_array( $request['places'] ?? null ) ? $request['places'] : array();
		$items = is_array( $places[0]['items'] ?? null ) ? $places[0]['items'] : array();
		if ( array() === $items ) {
			echo '<p class="description">' . esc_html__( 'В заказе нет товарных строк. Добавьте товар вручную, если он должен попасть в грузоместо.', 'walls-delivery-calc' ) . '</p>';
		}
		?>
		<table class="widefat striped wdc-cdek-items-table" data-wdc-shipment-items-table>
			<thead><tr><th><?php echo esc_html__( 'Товар', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Артикул', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Кол-во', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Цена', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Вес, г', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Длина, см', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Ширина, см', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Высота, см', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Место', 'walls-delivery-calc' ); ?></th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $items as $index => $item ) : ?>
				<?php
				$row_key = (string) ( $item['order_item_id'] ?? $item['item_id'] ?? '' );
				$row_key = '' !== $row_key ? 'order-item-' . $row_key : 'item-' . (string) ( $index + 1 );
				$quantity = max( 1, (int) ( $item['quantity'] ?? 1 ) );
				$sku = (string) ( $item['sku'] ?? '' );
				if ( '' === $sku ) {
					$sku = substr( 'item' . (string) ( $index + 1 ), 0, 20 );
				}
				$unit = is_array( $item['unit_price'] ?? null ) ? (int) ( $item['unit_price']['amount_kopecks'] ?? 0 ) / 100 : 0;
				$original_item = array(
					'name' => (string) ( $item['name'] ?? 'Товар' ),
					'ware_key' => $sku,
					'ordered_quantity' => $quantity,
					'amount' => min( 999, $quantity ),
					'cost' => $unit,
					'weight' => max( 1, (int) ( $item['weight_g'] ?? 0 ) ),
					'length_cm' => max( 0.1, (float) ( $item['length_cm'] ?? 1 ) ),
					'width_cm' => max( 0.1, (float) ( $item['width_cm'] ?? 1 ) ),
					'height_cm' => max( 0.1, (float) ( $item['height_cm'] ?? 1 ) ),
					'place_number' => 1,
				);
				$original_json = wp_json_encode( $original_item, JSON_UNESCAPED_UNICODE ) ?: '{}';
				?>
				<tr data-wdc-shipment-item-row data-wdc-base-row="1" data-wdc-original-item="<?php echo esc_attr( $original_json ); ?>" data-item-key="<?php echo esc_attr( $row_key ); ?>" data-group-key="<?php echo esc_attr( $row_key ); ?>" data-ordered-quantity="<?php echo esc_attr( (string) $quantity ); ?>" data-wdc-row-index="<?php echo esc_attr( (string) $index ); ?>">
					<td class="wdc-cdek-item-product"><?php echo esc_html( (string) ( $item['name'] ?? 'Товар' ) ); ?><input type="hidden" name="shipment_items[<?php echo esc_attr( (string) $index ); ?>][name]" value="<?php echo esc_attr( (string) ( $item['name'] ?? 'Товар' ) ); ?>"><input type="hidden" name="shipment_items[<?php echo esc_attr( (string) $index ); ?>][item_key]" value="<?php echo esc_attr( $row_key ); ?>"><input type="hidden" name="shipment_items[<?php echo esc_attr( (string) $index ); ?>][ordered_quantity]" value="<?php echo esc_attr( (string) $quantity ); ?>"></td>
					<td class="wdc-cdek-item-sku"><?php echo esc_html( $sku ); ?><input type="hidden" name="shipment_items[<?php echo esc_attr( (string) $index ); ?>][ware_key]" value="<?php echo esc_attr( $sku ); ?>"></td>
					<td><input class="wdc-cdek-input-qty" type="number" min="1" max="<?php echo esc_attr( (string) min( 999, $quantity ) ); ?>" step="1" name="shipment_items[<?php echo esc_attr( (string) $index ); ?>][amount]" value="<?php echo esc_attr( (string) min( 999, $quantity ) ); ?>" data-wdc-shipment-item-qty data-wdc-integer-input></td>
					<td><input class="wdc-cdek-input-price" type="text" inputmode="decimal" autocomplete="off" name="shipment_items[<?php echo esc_attr( (string) $index ); ?>][cost]" value="<?php echo esc_attr( (string) $unit ); ?>" data-wdc-decimal-input="2"></td>
					<td><input class="wdc-cdek-input-weight" type="number" min="1" step="1" name="shipment_items[<?php echo esc_attr( (string) $index ); ?>][weight]" value="<?php echo esc_attr( (string) max( 1, (int) ( $item['weight_g'] ?? 0 ) ) ); ?>" data-wdc-integer-input></td>
					<td><input class="wdc-cdek-input-dim" type="text" inputmode="decimal" autocomplete="off" name="shipment_items[<?php echo esc_attr( (string) $index ); ?>][length_cm]" value="<?php echo esc_attr( (string) max( 0.1, (float) ( $item['length_cm'] ?? 0.1 ) ) ); ?>" data-wdc-decimal-input="1"></td>
					<td><input class="wdc-cdek-input-dim" type="text" inputmode="decimal" autocomplete="off" name="shipment_items[<?php echo esc_attr( (string) $index ); ?>][width_cm]" value="<?php echo esc_attr( (string) max( 0.1, (float) ( $item['width_cm'] ?? 0.1 ) ) ); ?>" data-wdc-decimal-input="1"></td>
					<td><input class="wdc-cdek-input-dim" type="text" inputmode="decimal" autocomplete="off" name="shipment_items[<?php echo esc_attr( (string) $index ); ?>][height_cm]" value="<?php echo esc_attr( (string) max( 0.1, (float) ( $item['height_cm'] ?? 0.1 ) ) ); ?>" data-wdc-decimal-input="1"></td>
					<td><select name="shipment_items[<?php echo esc_attr( (string) $index ); ?>][place_number]" data-wdc-shipment-place-select><option value="1">1</option></select></td>
					<td class="wdc-cdek-item-actions" data-wdc-shipment-item-actions><?php if ( $quantity > 1 ) : ?><button type="button" class="wdc-icon-action wdc-icon-action--split" data-wdc-shipment-item-split title="<?php echo esc_attr__( 'Разбить товар по грузоместам', 'walls-delivery-calc' ); ?>" aria-label="<?php echo esc_attr__( 'Разбить товар по грузоместам', 'walls-delivery-calc' ); ?>">🔀</button><?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p><button type="button" class="button" data-wdc-add-manual-shipment-item data-wdc-add-manual-cdek-item><?php echo esc_html__( 'Добавить товар', 'walls-delivery-calc' ); ?></button></p>
		<?php
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
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

	/**
	 * @param array<string,mixed> $status
	 */
	private function render_status_block( array $status ): void {
		?>
		<div class="wdc-shipment-status" data-wdc-shipment-status-block>
			<p><strong><?php echo esc_html( $this->status_block_label( (string) ( $status['carrier_key'] ?? '' ) ) ); ?>:</strong> <span data-wdc-status-carrier><?php echo esc_html( (string) ( $status['carrier_status_title'] ?? '' ) ?: '-' ); ?></span></p>
			<p><strong><?php echo esc_html__( 'Последняя операция', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-status-operation><?php echo esc_html( $this->operation_summary( $status ) ); ?></span></p>
			<p data-wdc-planned-delivery-row <?php echo '' === (string) ( $status['planned_delivery_date'] ?? $status['cdek_planned_delivery_date'] ?? '' ) ? 'hidden' : ''; ?>><strong><?php echo esc_html__( 'Плановая дата доставки', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-planned-delivery-date><?php echo esc_html( (string) ( $status['planned_delivery_date'] ?? $status['cdek_planned_delivery_date'] ?? '' ) ); ?></span></p>
			<p data-wdc-dpd-places-row <?php echo '' === (string) ( $status['dpd_places_summary'] ?? '' ) ? 'hidden' : ''; ?>><strong data-wdc-dpd-places-label><?php echo esc_html( (string) ( $status['dpd_places_label'] ?? __( 'Грузоместа DPD', 'walls-delivery-calc' ) ) ); ?></strong>: <span data-wdc-dpd-places-summary><?php echo esc_html( (string) ( $status['dpd_places_summary'] ?? '' ) ); ?></span></p>
			<p data-wdc-status-updated-row <?php echo '' === (string) ( $status['updated_at'] ?? '' ) ? 'hidden' : ''; ?>><strong><?php echo esc_html__( 'Обновлено', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-status-updated><?php echo esc_html( (string) ( $status['updated_at'] ?? '' ) ); ?></span></p>
			<p><strong><?php echo esc_html__( 'Проверено', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-status-checked><?php echo esc_html( (string) ( $status['tracking_checked_at'] ?? '' ) ?: '-' ); ?></span></p>
			<div class="wdc-shipment-polling-indicator" data-wdc-shipment-polling-indicator data-wdc-cdek-polling-indicator hidden><span class="wdc-shipment-inline-spinner" aria-hidden="true"></span><span><?php echo esc_html__( 'Проверяем регистрацию отправления…', 'walls-delivery-calc' ); ?></span></div>
		</div>
		<?php
	}

	/**
	 * @return array<string,mixed>
	 */
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

	/**
	 * @return array<string,mixed>
	 */
	private function carrier_ui_payload( object $order, string $carrier_key, ?array $shipment_override = null ): array {
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
	 * @param array<string,mixed>  $status
	 * @param array<string,string> $presentation
	 * @return array{label:string,display_text:string,url:string,copy_value:string,items:array<int,array<string,string>>}
	 */
	private function tracking_presentation( array $status, array $presentation, string $fallback_value ): array {
		$tracking = is_array( $status['tracking_presentation'] ?? null ) ? $status['tracking_presentation'] : array();
		$label = trim( (string) ( $tracking['label'] ?? $presentation['tracking_label'] ?? __( 'Отслеживание', 'walls-delivery-calc' ) ) );
		$display_text = trim( (string) ( $tracking['display_text'] ?? $fallback_value ) );
		$url = $this->safe_tracking_url( (string) ( $tracking['url'] ?? '' ) );
		$copy_value = trim( (string) ( $tracking['copy_value'] ?? '' ) );
		$items = $this->tracking_items( $tracking['items'] ?? null );

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
			'items' => $items,
		);
	}

	/** @param array{label:string,display_text:string,url:string,copy_value:string,items?:array<int,array<string,string>>} $tracking */
	private function render_tracking_value( array $tracking ): void {
		$items = is_array( $tracking['items'] ?? null ) ? $tracking['items'] : array();
		if ( array() !== $items ) {
			foreach ( $items as $index => $item ) {
				if ( $index > 0 ) {
					echo '<br>';
				}
				if ( '' !== (string) ( $item['label'] ?? '' ) ) {
					printf( '<span class="description">%s: </span>', esc_html( (string) $item['label'] ) );
				}
				echo esc_html( (string) ( $item['display_text'] ?? $item['copy_value'] ?? '' ) );
				printf(
					' <button type="button" class="wdc-copy-tracking-icon" data-wdc-copy-tracking data-tracking-number="%s" aria-label="%s" title="%s">🗐</button>',
					esc_attr( (string) ( $item['copy_value'] ?? $item['display_text'] ?? '' ) ),
					esc_attr__( 'Копировать номер отслеживания', 'walls-delivery-calc' ),
					esc_attr__( 'Копировать', 'walls-delivery-calc' )
				);
			}
			return;
		}
		if ( '' !== $tracking['url'] ) {
			printf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $tracking['url'] ),
				esc_html( $tracking['display_text'] )
			);
			return;
		}

		echo esc_html( $tracking['display_text'] );
	}

	/** @return array<int,array<string,string>> */
	private function tracking_items( mixed $items ): array {
		if ( ! is_array( $items ) ) {
			return array();
		}
		$result = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$display_text = trim( (string) ( $item['display_text'] ?? $item['displayText'] ?? '' ) );
			$copy_value = trim( (string) ( $item['copy_value'] ?? $item['copyValue'] ?? $display_text ) );
			if ( '' === $display_text && '' === $copy_value ) {
				continue;
			}
			$result[] = array(
				'label' => trim( (string) ( $item['label'] ?? '' ) ),
				'display_text' => $display_text,
				'copy_value' => $copy_value,
			);
		}

		return $result;
	}

	private function safe_tracking_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );

		return in_array( $scheme, array( 'http', 'https' ), true ) ? $url : '';
	}

	/**
	 * @param array<string,mixed> $status
	 * @return array<string,mixed>
	 */
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

	/**
	 * @return array<string,string>
	 */
	private function carrier_presentation( string $carrier_key ): array {
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

	private function carrier_adapter( string $carrier_key ): ?CarrierShipmentAdapterInterface {
		return $this->carrier_adapters instanceof CarrierShipmentAdapterRegistry ? $this->carrier_adapters->get( $carrier_key ) : null;
	}

	/**
	 * @param array<string,mixed> $shipment
	 * @return array<int,array<string,mixed>>
	 */
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

	/**
	 * @param array<int,array<string,mixed>> $actions
	 */
	private function render_document_action_links( array $actions, int $order_id ): void {
		foreach ( $actions as $action ) {
			if ( empty( $action['visible'] ) ) {
				continue;
			}
			$data = is_array( $action['data'] ?? null ) ? $action['data'] : array();
			$url = (string) ( $action['download_url'] ?? '' );
			$attrs = array(
				'class' => 'button',
				'data-wdc-shipment-document-download' => '1',
				'data-order-id' => (string) $order_id,
				'data-action-key' => (string) ( $action['key'] ?? '' ),
				'data-download-url' => $url,
				'href' => $url,
			);
			if ( is_array( $data['attrs'] ?? null ) ) {
				foreach ( $data['attrs'] as $name => $value ) {
					$name = (string) $name;
					if ( str_starts_with( $name, 'data-' ) ) {
						$attrs[ $name ] = (string) $value;
					}
				}
			}
			echo '<a';
			foreach ( $attrs as $name => $value ) {
				echo ' ' . esc_attr( $name ) . '="' . ( in_array( $name, array( 'href', 'data-download-url' ), true ) ? esc_url( $value ) : esc_attr( $value ) ) . '"';
			}
			echo '>' . esc_html( (string) ( $action['label'] ?? 'Скачать документ' ) ) . '</a>';
		}
	}

	/**
	 * @param array<string,string> $presentation
	 */
	private function render_presentation_attrs( array $presentation ): void {
		foreach ( $presentation as $key => $value ) {
			echo ' data-' . esc_attr( str_replace( '_', '-', $key ) ) . '="' . esc_attr( $value ) . '"';
		}
	}

	/**
	 * @param array<string,mixed> $shipment
	 */
	private function shipment_status_label( array $shipment ): string {
		$universal = (string) ( $shipment['universal_status_code'] ?? '' );
		if ( '' !== $universal && DeliveryStatus::is_valid( $universal ) ) {
			return DeliveryStatus::label( $universal );
		}

		return match ( (string) ( $shipment['status'] ?? '' ) ) {
			'registration_pending' => 'регистрация',
			'created' => 'создано',
			'registered' => 'зарегистрировано',
			'failed' => 'ошибка',
			'', 'draft' => 'не создано',
			default => 'не определено',
		};
	}

	/**
	 * @param array<string,mixed> $status
	 */
	private function operation_summary( array $status ): string {
		$parts = array_filter(
			array(
				(string) ( $status['carrier_operation_date'] ?? '' ),
				(string) ( $status['carrier_operation_code'] ?? $status['carrier_operation_address'] ?? '' ),
				(string) ( $status['carrier_operation_marker'] ?? $status['carrier_operation_index'] ?? '' ),
			),
			static fn ( string $value ): bool => '' !== trim( $value )
		);

		return array() !== $parts ? implode( ', ', $parts ) : '-';
	}

	private function shipment_price_class( string $compare_status ): string {
		return match ( $compare_status ) {
			'ok' => 'wdc-shipment-price-ok',
			'warning' => 'wdc-shipment-price-warning',
			default => 'wdc-shipment-price-neutral',
		};
	}

	private function document_download_url( int $order_id, string $carrier_key, string $action_key ): string {
		if ( $this->document_downloads instanceof ShipmentDocumentDownloadService ) {
			return $this->document_downloads->download_url( $order_id, $carrier_key, $action_key );
		}

		return '';
	}

	/**
	 * @param array<string,mixed>|null $source_row
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,float>|null
	 */
	private function map_provider(): string {
		$provider = ( new SettingsRepository() )->get_string( 'pickup_map_provider', 'leaflet' );

		return 'yandex' === $provider ? 'yandex' : 'leaflet';
	}

	private function yandex_api_key(): string {
		return trim( ( new SettingsRepository() )->get_string( 'pickup_map_yandex_api_key', '' ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
}
