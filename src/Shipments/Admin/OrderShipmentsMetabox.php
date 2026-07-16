<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin;

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
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentDownloadService;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentProviderRegistry;
use WallsShop\WDC\Shipments\Modal\CarrierShipmentModalExtensionInterface;
use WallsShop\WDC\Shipments\Modal\ShipmentModalExtensionRegistry;
use WallsShop\WDC\Shipments\RussianPost\RussianPostAddressNormalizer;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OrderShipmentsMetabox {
	private const NONCE_ACTION = 'wdc_shipments_admin';
	private const AJAX_CREATE = 'wdc_create_shipment';
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
	private ?YandexDeliveryPickupPointV2Repository $yandex_pickup_points = null;
	private ?YandexLocationMappingV2Repository $yandex_location_mapping = null;

	public function __construct(
		private OrderShipmentRepository $repository,
		private OrderShipmentDraftFactory $drafts,
		private ShipmentCreationService $creation,
		private DeliveryServiceRepository $services,
		private ShipmentStatusUpdateService $status_updates,
		private ?CdekOrderStatusService $cdek_status_updates = null,
		private ?ShipmentBacklogService $backlog = null,
		private ?RussianPostAddressNormalizer $address_normalizer = null,
		private ?RussianPostPickupPointTypeSettings $pickup_point_type_settings = null,
		private ?CdekDeliveryPointService $cdek_delivery_points = null,
		private ?DpdPickupPointService $dpd_pickup_points = null,
		private ?CdekRecipientAddressPreparationService $cdek_address_preparation = null,
		private ?AddressSuggestionService $address_suggestions = null,
		private string $plugin_url = '',
		private string $version = '1',
		private ?CdekBarcodePrintService $cdek_barcode_print = null,
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
		add_action( 'wp_ajax_' . self::AJAX_CREATE, array( $this, 'ajax_create' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_' . self::AJAX_UPDATE_STATUS, array( $this, 'ajax_update_status' ) );
		add_action( 'wp_ajax_' . self::AJAX_MARK_POLL_EXHAUSTED, array( $this, 'ajax_mark_poll_exhausted' ) );
		add_action( 'wp_ajax_' . self::AJAX_CANCEL, array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_' . self::AJAX_REMOVE_FROM_ORDER, array( $this, 'ajax_remove_from_order' ) );
		add_action( 'wp_ajax_' . self::AJAX_ATTACH_TRACKING, array( $this, 'ajax_attach_tracking' ) );
		add_action( 'wp_ajax_' . self::AJAX_NORMALIZE_ADDRESS, array( $this, 'ajax_normalize_address' ) );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH_PICKUP_POINTS, array( $this, 'ajax_search_pickup_points' ) );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH_PRODUCTS, array( $this, 'ajax_search_products' ) );
		add_action( 'wp_ajax_' . self::AJAX_CDEK_BARCODE_PREPARE, array( $this, 'ajax_cdek_barcode_prepare' ) );
		add_action( 'wp_ajax_' . self::AJAX_DPD_COURIER_CONTACT_HISTORY, array( $this, 'ajax_dpd_courier_contact_history' ) );
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
		wp_enqueue_script( 'wdc-shipments-admin', $this->plugin_url . 'assets/admin/shipments-admin.js', array( $provider_handle, 'wdc-pickup-api' ), $this->version, true );
		wp_localize_script(
			'wdc-shipments-admin',
			'wdcShipmentsAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'restUrl' => function_exists( 'rest_url' ) ? rest_url( 'wdc/v1/' ) : '',
				'restNonce' => wp_create_nonce( 'wp_rest' ),
				'nonce' => wp_create_nonce( self::NONCE_ACTION ),
				'createAction' => self::AJAX_CREATE,
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
		$address = is_array( $request['recipient_address'] ?? null ) ? $request['recipient_address'] : array();
		$place = is_array( $request['places'][0] ?? null ) ? $request['places'][0] : array();
		$place_rows = is_array( $request['places'] ?? null ) ? array_values( array_filter( $request['places'], 'is_array' ) ) : array();
		if ( array() === $place_rows ) {
			$place_rows = array( $place );
		}
		$place_rows = $this->editable_place_rows( $place_rows );
		$meta = is_array( $request['meta'] ?? null ) ? $request['meta'] : array();
		$carrier_key = (string) ( $request['carrier_key'] ?? $meta['carrier_key'] ?? '' );
		$service_key = (string) ( $meta['service_key'] ?? $request['rate_id'] ?? '' );
		$is_cdek = CdekSettings::CARRIER_KEY === $carrier_key;
		$is_dpd = DpdSettings::CARRIER_KEY === $carrier_key;
		$is_russian_post = RussianPostDomesticSettings::CARRIER_KEY === $carrier_key;
		$is_yandex = YandexDeliverySettings::CARRIER_KEY === $carrier_key;
		$requires_tariff = array_key_exists( 'requires_tariff', $modal_capabilities ) ? (bool) $modal_capabilities['requires_tariff'] : ! $is_yandex;
		$requires_postoffice = array_key_exists( 'requires_postoffice', $modal_capabilities ) ? (bool) $modal_capabilities['requires_postoffice'] : $is_russian_post;
		$requires_successful_preview = array_key_exists( 'requires_successful_preview', $modal_capabilities ) ? (bool) $modal_capabilities['requires_successful_preview'] : $is_dpd;
		$shipment = '' !== $carrier_key ? $this->repository->find_by_carrier( $order, $carrier_key ) : array();
		$settings = is_array( $request['services'] ?? null ) ? $request['services'] : array();
		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$selected_delivery_type = RussianPostDomesticSettings::normalize_delivery_type( (string) ( $request['delivery_type'] ?? $meta['delivery_type'] ?? DeliveryType::PICKUP ) );
		if ( '' === $selected_delivery_type && array() !== $services ) {
			$selected_delivery_type = (string) ( $services[0]['delivery_type'] ?? DeliveryType::PICKUP );
		}
		$selected_tariff_object = (string) ( $meta['tariff_object'] ?? '' );
		$selected_service_tariffs = array();
		foreach ( $services as $service ) {
			if ( $selected_delivery_type === (string) ( $service['delivery_type'] ?? '' ) ) {
				$selected_service_tariffs = is_array( $service['tariffs'] ?? null ) ? $service['tariffs'] : array();
				break;
			}
		}
		if ( '' === $selected_tariff_object && array() !== $selected_service_tariffs ) {
			$selected_tariff_object = (string) ( $selected_service_tariffs[0]['object_code'] ?? '' );
		}
		$selected_tariff_has_declared_value = false;
		foreach ( $selected_service_tariffs as $tariff ) {
			if ( $selected_tariff_object === (string) ( $tariff['object_code'] ?? '' ) ) {
				$selected_tariff_has_declared_value = ! empty( $tariff['has_declared_value'] );
				break;
			}
		}
		$selected_tariff_title = (string) ( $meta['selected_tariff_title'] ?? $meta['tariff_title'] ?? '' );
		foreach ( $selected_service_tariffs as $tariff ) {
			if ( $selected_tariff_object === (string) ( $tariff['object_code'] ?? '' ) ) {
				$selected_tariff_title = (string) ( $tariff['title'] ?? $selected_tariff_title );
				break;
			}
		}
		if ( '' === trim( $selected_tariff_title ) && '' !== $selected_tariff_object ) {
			$selected_tariff_title = sprintf( __( 'тариф %s', 'walls-delivery-calc' ), $selected_tariff_object );
		}
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
		$presentation = $this->carrier_presentation( $carrier_key );
		$tracking_presentation = $this->tracking_presentation( $status_payload, $presentation, $barcode );
		$price_label = (string) ( $status_payload['actual_cost_label'] ?? '' );
		$price_compare_status = (string) ( $status_payload['actual_cost_compare_status'] ?? '' );
		$price_compare_message = (string) ( $status_payload['actual_cost_compare_message'] ?? '' );
		$yandex_self_pickup_code = trim( (string) ( $status_payload['yandex_self_pickup_node_code'] ?? $shipment['yandex_self_pickup_node_code'] ?? '' ) );
		$button_policy = $this->button_policy()->resolve( $carrier_key, $shipment, $status_payload, $is_russian_post && $this->can_cancel_shipment( $shipment ) );
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
		$has_selected_service_tariffs = array() !== $selected_service_tariffs;
		$tariff_message_hidden_attr = $has_selected_service_tariffs ? ' hidden' : '';
		?>
		<div class="wdc-shipments-metabox" data-wdc-shipments-metabox data-carrier-key="<?php echo esc_attr( $carrier_key ); ?>" data-has-shipment="<?php echo $has_created ? '1' : '0'; ?>" <?php $this->render_presentation_attrs( $presentation ); ?>>
			<p><strong><?php echo esc_html__( 'Служба', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( (string) ( $meta['service_title'] ?? $request['rate_id'] ?? '-' ) ); ?></p>
			<p><strong><?php echo esc_html__( 'Статус посылки', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-shipment-summary-status><?php echo esc_html( $this->shipment_status_label( $shipment ) ); ?></span></p>
			<p data-wdc-tracking-row <?php echo '' === $tracking_presentation['display_text'] && '' === $tracking_presentation['copy_value'] ? 'hidden' : ''; ?>><strong data-wdc-tracking-label><?php echo esc_html( $tracking_presentation['label'] ); ?></strong>: <span data-wdc-tracking-number><?php $this->render_tracking_value( $tracking_presentation ); ?></span> <button type="button" class="wdc-copy-tracking-icon" data-wdc-copy-tracking data-tracking-number="<?php echo esc_attr( $tracking_presentation['copy_value'] ); ?>" aria-label="<?php echo esc_attr__( 'Копировать номер отслеживания', 'walls-delivery-calc' ); ?>" title="<?php echo esc_attr__( 'Копировать', 'walls-delivery-calc' ); ?>" <?php disabled( '' === $tracking_presentation['copy_value'] ); ?>>🗐</button> <span class="description" data-wdc-copy-tracking-status></span></p>
			<p data-wdc-yandex-self-pickup-code-row <?php echo '' === $yandex_self_pickup_code ? 'hidden' : ''; ?>><strong><?php echo esc_html__( 'Код для получения', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-yandex-self-pickup-code><?php echo esc_html( $yandex_self_pickup_code ); ?></span></p>
			<p data-wdc-shipment-price-row class="<?php echo esc_attr( $this->shipment_price_class( $price_compare_status ) ); ?>" title="<?php echo esc_attr( $price_compare_message ); ?>" <?php echo '' === $price_label ? 'hidden' : ''; ?>><strong><?php echo esc_html__( 'Цена', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-shipment-price-label><?php echo esc_html( $price_label ); ?></span></p>
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
							<button type="button" class="button button-primary" data-wdc-create-shipment><?php echo esc_html( $is_dpd ? __( 'Создать отправление DPD', 'walls-delivery-calc' ) : __( 'Создать отправление', 'walls-delivery-calc' ) ); ?></button>
						</section>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_create(): void {
		$buffer_level = ob_get_level();
		ob_start();
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		try {
			$order_id = (int) ( $_POST['order_id'] ?? 0 );
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! is_object( $order ) ) {
				$this->discard_preview_buffer( $buffer_level );
				wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ), 'error_code' => 'shipment_create_invalid_request' ), 404 );
			}
			$data = $_POST;
			$prepared = $this->maybe_prepare_cdek_courier_address( $order, $data );
			if ( ! empty( $prepared['error'] ) ) {
				throw new \InvalidArgumentException( (string) $prepared['error'] );
			}
			$request = $this->drafts->create_request_from_admin_data( $order, $data );
			$this->validate_preview_request( $request );
			$preview = $this->creation->safe_preview( $request );
			if ( ! empty( $preview['errors'] ) && is_array( $preview['errors'] ) && in_array( $request->carrier_key, array( DpdSettings::CARRIER_KEY, YandexDeliverySettings::CARRIER_KEY ), true ) ) {
				throw new \InvalidArgumentException( $this->public_shipment_error_message( (string) reset( $preview['errors'] ) ) );
			}
			if ( DpdSettings::CARRIER_KEY === $request->carrier_key ) {
				$adapter = $this->carrier_adapter( DpdSettings::CARRIER_KEY );
				if ( null === $adapter ) {
					throw new \InvalidArgumentException( __( 'Адаптер DPD недоступен.', 'walls-delivery-calc' ) );
				}
				$stage = sanitize_key( wp_unslash( $_POST['dpd_registration_stage'] ?? 'begin' ) );
				$result = 'submit' === $stage && method_exists( $adapter, 'submit_registration' )
					? $adapter->submit_registration( $order, $request, sanitize_text_field( wp_unslash( $_POST['registration_attempt_id'] ?? '' ) ) )
					: ( method_exists( $adapter, 'begin_registration' ) ? $adapter->begin_registration( $order, $request ) : array( 'success' => false, 'message' => __( 'Регистрация DPD недоступна.', 'walls-delivery-calc' ) ) );
				if ( empty( $result['success'] ) ) {
					$this->discard_preview_buffer( $buffer_level );
					wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Не удалось зарегистрировать отправление DPD.', 'walls-delivery-calc' ) ), 'preview' => $preview, 'error_code' => 'shipment_create_validation_failed' ), 400 );
				}
				$this->add_dpd_courier_contact_history( (string) ( $request->meta['sender_contact_fio'] ?? '' ) );
				$this->discard_preview_buffer( $buffer_level );
				wp_send_json_success(
					array_merge(
						$this->carrier_ui_payload( $order, $request->carrier_key, is_array( $result['shipment'] ?? null ) ? $result['shipment'] : null ),
						$result,
						array( 'message' => (string) ( $result['message'] ?? $this->carrier_presentation( $request->carrier_key )['created_toast'] ), 'preview' => $preview )
					)
				);
			}

			$result = $this->creation->create( $order, $request );
			if ( ! $result->success ) {
				$this->discard_preview_buffer( $buffer_level );
				wp_send_json_error( array( 'message' => $this->public_shipment_error_message( $result->error_message ), 'code' => $result->error_code, 'error_code' => (string) ( $result->error_code ?: 'shipment_create_failed' ), 'preview' => $preview ), 400 );
			}

			$this->discard_preview_buffer( $buffer_level );
			$accepted_reconciliation = is_array( $result->raw_reference['yandex_accepted_reconciliation'] ?? null ) ? $result->raw_reference['yandex_accepted_reconciliation'] : array();
			$success_message = array() !== $accepted_reconciliation
				? __( 'Отправление создано в Яндекс.Доставке. Ожидается получение статуса.', 'walls-delivery-calc' )
				: $this->carrier_presentation( $request->carrier_key )['created_toast'];
			wp_send_json_success(
				array_merge(
					$this->carrier_ui_payload( $order, $request->carrier_key ),
					array(
					'message' => $success_message,
					'tracking_number' => $result->tracking_number,
					'backlog_order_id' => $result->backlog_order_id,
					'preview' => $preview,
					'accepted' => ! empty( $accepted_reconciliation['accepted'] ),
					'reconciliation_required' => ! empty( $accepted_reconciliation['reconciliation_required'] ),
					'request_id' => (string) ( $accepted_reconciliation['request_id'] ?? '' ),
					)
				)
			);
		} catch ( \InvalidArgumentException $exception ) {
			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_error(
				array(
					'message' => $this->public_shipment_error_message( $exception->getMessage() ),
					'error_code' => 'shipment_create_validation_failed',
				),
				400
			);
		} catch ( \Throwable $exception ) {
			if ( str_contains( $exception::class, 'AjaxResponse' ) ) {
				throw $exception;
			}
			error_log(
				sprintf(
					'[walls-delivery-calc] shipment create failed. class=%s message=%s location=%s:%d',
					$exception::class,
					$exception->getMessage(),
					$exception->getFile(),
					$exception->getLine()
				)
			);
			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_error(
				array(
					'message' => __( 'Не удалось создать отправление. Подробности записаны в журнал ошибок.', 'walls-delivery-calc' ),
					'error_code' => 'shipment_create_unexpected_error',
				),
				500
			);
		}
	}

	public function ajax_preview(): void {
		$buffer_level = ob_get_level();
		ob_start();
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		try {
			$order_id = (int) ( $_POST['order_id'] ?? 0 );
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! is_object( $order ) ) {
				$this->discard_preview_buffer( $buffer_level );
				wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ), 'error_code' => 'shipment_preview_invalid_request' ), 404 );
			}
			$data = $_POST;
			$prepared = $this->maybe_prepare_cdek_courier_address( $order, $data );
			if ( ! empty( $prepared['error'] ) ) {
				throw new \InvalidArgumentException( (string) $prepared['error'] );
			}
			$request = $this->preview_request( $this->drafts->create_request_from_admin_data( $order, $data ) );
			$this->validate_preview_request( $request );
			$preview = $this->creation->safe_preview( $request );
			if ( YandexDeliverySettings::CARRIER_KEY === $request->carrier_key && ! empty( $preview['errors'] ) && is_array( $preview['errors'] ) ) {
				throw new \InvalidArgumentException( $this->public_shipment_error_message( (string) reset( $preview['errors'] ) ) );
			}

			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_success( array( 'preview' => $preview ) );
		} catch ( \InvalidArgumentException $exception ) {
			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_error(
				array(
					'message' => $this->public_shipment_error_message( $exception->getMessage() ),
					'error_code' => 'shipment_preview_validation_failed',
				),
				400
			);
		} catch ( \Throwable $exception ) {
			if ( str_contains( $exception::class, 'AjaxResponse' ) ) {
				throw $exception;
			}
			error_log(
				sprintf(
					'[walls-delivery-calc] shipment preview failed. class=%s message=%s location=%s:%d',
					$exception::class,
					$exception->getMessage(),
					$exception->getFile(),
					$exception->getLine()
				)
			);
			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_error(
				array(
					'message' => __( 'Сервер вернул некорректный ответ при подготовке отправления. Проверьте журнал ошибок.', 'walls-delivery-calc' ),
					'error_code' => 'shipment_preview_unexpected_error',
				),
				500
			);
		}
	}

	private function discard_preview_buffer( int $buffer_level ): void {
		while ( ob_get_level() > $buffer_level ) {
			ob_end_clean();
		}
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

	private function validate_preview_request( ShipmentCreateRequest $request ): void {
		if ( YandexDeliverySettings::CARRIER_KEY !== $request->carrier_key ) {
			return;
		}
		$source_station = trim( (string) ( $request->meta['yandex_source_platform_station_id'] ?? '' ) );
		if ( '' === $source_station ) {
			throw new \InvalidArgumentException( __( 'Не указана исходная станция Яндекс.', 'walls-delivery-calc' ) );
		}
		$this->validate_yandex_source_station( $source_station, ! empty( $request->meta['yandex_source_station_overridden'] ) );
		$delivery_type = (string) ( $request->delivery_type ?: ( $request->meta['delivery_type'] ?? '' ) );
		if ( DeliveryType::PICKUP === $delivery_type ) {
			$destination_station = trim( (string) ( $request->meta['yandex_pickup_platform_station_id'] ?? $request->pickup_point?->point_code ?? '' ) );
			if ( '' === $destination_station ) {
				throw new \InvalidArgumentException( __( 'Не выбран ПВЗ назначения Яндекс.', 'walls-delivery-calc' ) );
			}
		} elseif ( DeliveryType::COURIER === $delivery_type ) {
			$details = is_array( $request->meta['yandex_courier_details'] ?? null ) ? $request->meta['yandex_courier_details'] : array();
			if ( empty( $details['address_verified'] ) || 'dadata+yandex' !== (string) ( $details['normalization_source'] ?? '' ) ) {
				throw new \InvalidArgumentException( __( 'Проверьте адрес доставки через DaData.', 'walls-delivery-calc' ) );
			}
			if ( '' === trim( (string) ( $details['locality'] ?? '' ) ) ) {
				throw new \InvalidArgumentException( __( 'Не удалось определить населённый пункт. Проверьте полный адрес.', 'walls-delivery-calc' ) );
			}
			if ( '' === trim( (string) ( $details['street'] ?? '' ) ) ) {
				throw new \InvalidArgumentException( __( 'Не удалось определить улицу. Проверьте полный адрес.', 'walls-delivery-calc' ) );
			}
			if ( '' === trim( (string) ( $details['house'] ?? '' ) ) ) {
				throw new \InvalidArgumentException( __( 'Не удалось определить номер дома. Проверьте полный адрес.', 'walls-delivery-calc' ) );
			}
		}
	}

	private function validate_yandex_source_station( string $platform_station_id, bool $overridden ): void {
		$platform_station_id = trim( $platform_station_id );
		if ( '' === $platform_station_id ) {
			throw new \InvalidArgumentException( __( 'Не указана исходная станция Яндекс.', 'walls-delivery-calc' ) );
		}
		$row = $this->yandex_pickup_points()->find( $platform_station_id );
		if ( ! is_array( $row ) ) {
			if ( $overridden ) {
				throw new \InvalidArgumentException( __( 'ПВЗ отправления Яндекс не найден.', 'walls-delivery-calc' ) );
			}
			return;
		}
		if ( empty( $row['active'] ) ) {
			throw new \InvalidArgumentException( $overridden ? __( 'Выбранный ПВЗ Яндекс сейчас недоступен.', 'walls-delivery-calc' ) : __( 'Сохранённый ПВЗ отправления Яндекс недоступен. Выберите другой ПВЗ.', 'walls-delivery-calc' ) );
		}
		if ( empty( $row['available_for_dropoff'] ) ) {
			throw new \InvalidArgumentException( $overridden ? __( 'Выбранный ПВЗ Яндекс не принимает отправления.', 'walls-delivery-calc' ) : __( 'Сохранённый ПВЗ отправления Яндекс недоступен. Выберите другой ПВЗ.', 'walls-delivery-calc' ) );
		}
		if ( '' === trim( (string) ( $row['platform_station_id'] ?? '' ) ) ) {
			throw new \InvalidArgumentException( __( 'ПВЗ отправления Яндекс не найден.', 'walls-delivery-calc' ) );
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

	public function ajax_dpd_courier_contact_history(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
		$value = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );
		$history = 'remove' === $operation ? $this->remove_dpd_courier_contact_history( $value ) : $this->add_dpd_courier_contact_history( $value );

		wp_send_json_success( array( 'history' => $history ) );
	}
	public function ajax_update_status(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}

		$shipment_key = sanitize_key( wp_unslash( $_POST['shipment_key'] ?? RussianPostDomesticSettings::CARRIER_KEY ) );
		$adapter = $this->carrier_adapter( $shipment_key );
		if ( null === $adapter ) {
			wp_send_json_error( array( 'message' => __( 'Для выбранной службы нет адаптера отправлений.', 'walls-delivery-calc' ) ), 400 );
		}
		$result = $adapter->update_status( $order, $shipment_key );
		if ( ! (bool) ( $result['success'] ?? false ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Не удалось получить статус отправления.', 'walls-delivery-calc' ) ) ), 400 );
		}

		wp_send_json_success(
			array_merge(
				$this->carrier_ui_payload( $order, $shipment_key ),
				array(
					'message' => (string) ( $result['message'] ?? __( 'Статус отправления обновлен.', 'walls-delivery-calc' ) ),
					'pending' => ! empty( $result['pending'] ),
					'retryable' => ! empty( $result['retryable'] ),
					'carrier_status_value' => is_scalar( $result['status'] ?? null ) ? (string) $result['status'] : '',
				)
			)
		);
	}

	public function ajax_mark_poll_exhausted(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
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
			$adapter = $this->carrier_adapter( $shipment_key );
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
					$this->carrier_ui_payload( $order, $shipment_key, is_array( $result['shipment'] ?? null ) ? $result['shipment'] : null ),
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

	public function ajax_cancel(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) || $order_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}
		$shipment_key = sanitize_key( wp_unslash( $_POST['shipment_key'] ?? RussianPostDomesticSettings::CARRIER_KEY ) );
		$adapter = $this->carrier_adapter( $shipment_key );
		if ( null === $adapter ) {
			wp_send_json_error( array( 'message' => __( 'Для выбранной службы нет адаптера отправлений.', 'walls-delivery-calc' ) ), 400 );
		}
		$result = $adapter->cancel_in_carrier( $order, $shipment_key );
		if ( ! (bool) ( $result['success'] ?? false ) ) {
			$error_payload = array_merge(
				$this->carrier_ui_payload( $order, $shipment_key ),
				array( 'message' => (string) ( $result['message'] ?? __( 'Не удалось отменить отправление.', 'walls-delivery-calc' ) ), 'temporary_can_remove' => ! empty( $result['temporary_can_remove'] ) )
			);
			wp_send_json_error( $error_payload, 400 );
		}

		wp_send_json_success(
			array_merge(
				$this->carrier_ui_payload( $order, $shipment_key ),
				array(
				'message' => (string) ( $result['message'] ?? __( 'Отправление отменено.', 'walls-delivery-calc' ) ),
				'accepted' => ! empty( $result['accepted'] ),
				'cancellation_started' => ! empty( $result['cancellation_started'] ),
				'cancelled_and_removed' => ! empty( $result['cancelled_and_removed'] ),
				'auto_poll' => ! empty( $result['auto_poll'] ),
				'poll_interval_ms' => (int) ( $result['poll_interval_ms'] ?? 0 ),
				'poll_max_attempts' => (int) ( $result['poll_max_attempts'] ?? 0 ),
				'poll_purpose' => (string) ( $result['poll_purpose'] ?? '' ),
				)
			)
		);
	}

	public function ajax_remove_from_order(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) || $order_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}
		$shipment_key = sanitize_key( wp_unslash( $_POST['shipment_key'] ?? RussianPostDomesticSettings::CARRIER_KEY ) );
		$adapter = $this->carrier_adapter( $shipment_key );
		if ( null === $adapter ) {
			wp_send_json_error( array( 'message' => __( 'Для выбранной службы нет адаптера отправлений.', 'walls-delivery-calc' ) ), 400 );
		}
		$result = $adapter->remove_from_order( $order, $shipment_key );
		if ( ! (bool) ( $result['success'] ?? false ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Не удалось удалить данные отправления.', 'walls-delivery-calc' ) ) ), 400 );
		}

		wp_send_json_success(
			array_merge(
				$this->carrier_ui_payload( $order, $shipment_key ),
				array(
				'message' => (string) ( $result['message'] ?? __( 'Данные отправления удалены из заказа.', 'walls-delivery-calc' ) ),
				)
			)
		);
	}

	public function ajax_attach_tracking(): void {
		$buffer_level = ob_get_level();
		ob_start();
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
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
			$adapter = $this->carrier_adapter( $shipment_key );
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
					$this->carrier_ui_payload( $order, $shipment_key ),
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

	public function ajax_cdek_barcode_prepare(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
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
			$result['download_url'] = $this->document_download_url( $order_id, CdekSettings::CARRIER_KEY, 'download_label' );
		}

		wp_send_json_success( $result );
	}

	public function ajax_normalize_address(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}
		$original_address = sanitize_text_field( wp_unslash( $_POST['courier_original_address'] ?? $_POST['original_address'] ?? '' ) );
		$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
		$carrier_key = sanitize_key( wp_unslash( $_POST['carrier_key'] ?? '' ) );
		$delivery_type = RussianPostDomesticSettings::normalize_delivery_type( sanitize_key( wp_unslash( $_POST['delivery_type'] ?? '' ) ) );
		if ( YandexDeliverySettings::CARRIER_KEY === $carrier_key && DeliveryType::COURIER === $delivery_type ) {
			$result = $this->normalize_yandex_courier_address( $order, $original_address );
			wp_send_json_success( array( 'normalized_address' => $result ) );
		}
		if ( CdekSettings::CARRIER_KEY === $carrier_key && DeliveryType::COURIER === $delivery_type ) {
			if ( ! $this->cdek_address_preparation instanceof CdekRecipientAddressPreparationService ) {
				wp_send_json_error( array( 'message' => __( 'Нормализация адреса СДЭК недоступна.', 'walls-delivery-calc' ) ), 500 );
			}
			$result = $this->cdek_address_preparation->prepare( $order, $original_address, $this->recipient_location_context_from_request( $order ), $service_key ?: CdekSettings::SERVICE_KEY );
			wp_send_json_success( array( 'normalized_address' => $result ) );
		}
		if ( DpdSettings::CARRIER_KEY === $carrier_key && DeliveryType::COURIER === $delivery_type ) {
			if ( ! $this->cdek_address_preparation instanceof CdekRecipientAddressPreparationService ) {
				wp_send_json_error( array( 'message' => __( 'Нормализация адреса DPD недоступна.', 'walls-delivery-calc' ) ), 500 );
			}
			$result = $this->cdek_address_preparation->prepare( $order, $original_address, $this->recipient_location_context_from_request( $order ), DpdSettings::SERVICE_KEY );
			$result['service_key'] = DpdSettings::SERVICE_KEY;
			$result['source'] = ! empty( $result['success'] ) ? 'dadata+dpd' : (string) ( $result['source'] ?? 'dadata+dpd' );
			wp_send_json_success( array( 'normalized_address' => $result ) );
		}
		if ( ! $this->address_normalizer instanceof RussianPostAddressNormalizer ) {
			wp_send_json_error( array( 'message' => __( 'Нормализация адреса недоступна.', 'walls-delivery-calc' ) ), 500 );
		}

		$result = $this->address_normalizer->normalize( $order_id, $original_address );
		$result['order_id'] = $order_id;
		$result['service_key'] = $service_key;

		if ( method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'save' ) ) {
			$order->update_meta_data( '_wdc_shipment_rp_clean_address', $result );
			$order->save();
		}

		wp_send_json_success( array( 'normalized_address' => $result ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function normalize_yandex_courier_address( object $order, string $original_address ): array {
		$original_address = trim( $original_address );
		if ( '' === $original_address ) {
			return array(
				'success' => false,
				'message' => __( 'Введите полный адрес доставки.', 'walls-delivery-calc' ),
				'source' => 'dadata+yandex',
				'fields' => array(),
				'display' => '',
				'original_hash' => hash( 'sha256', $original_address ),
				'service_key' => YandexDeliverySettings::SERVICE_KEY,
			);
		}
		if ( ! $this->address_suggestions instanceof AddressSuggestionService ) {
			return array(
				'success' => false,
				'message' => __( 'Проверка адреса через DaData недоступна.', 'walls-delivery-calc' ),
				'source' => 'dadata+yandex',
				'fields' => array(),
				'display' => '',
				'original_hash' => hash( 'sha256', $original_address ),
				'service_key' => YandexDeliverySettings::SERVICE_KEY,
			);
		}

		$response = $this->address_suggestions->suggest( 'address', $original_address, $this->yandex_address_suggestion_context( $order ) );
		if ( empty( $response['success'] ) ) {
			return array(
				'success' => false,
				'message' => $this->dadata_error_message( (string) ( $response['error_code'] ?? '' ) ),
				'source' => 'dadata+yandex',
				'fields' => array(),
				'display' => '',
				'original_hash' => hash( 'sha256', $original_address ),
				'service_key' => YandexDeliverySettings::SERVICE_KEY,
			);
		}

		$items = is_array( $response['items'] ?? null ) ? $response['items'] : array();
		$item = null;
		foreach ( $items as $candidate ) {
			if ( is_array( $candidate ) && ! empty( $candidate['isDeliverable'] ) ) {
				$item = $candidate;
				break;
			}
		}
		if ( null === $item && isset( $items[0] ) && is_array( $items[0] ) ) {
			$item = $items[0];
		}
		if ( null === $item ) {
			return array(
				'success' => false,
				'message' => __( 'Адрес распознан недостаточно точно. Уточните его и проверьте повторно.', 'walls-delivery-calc' ),
				'source' => 'dadata+yandex',
				'fields' => array(),
				'display' => '',
				'original_hash' => hash( 'sha256', $original_address ),
				'service_key' => YandexDeliverySettings::SERVICE_KEY,
			);
		}

		$data = is_array( $item['data'] ?? null ) ? $item['data'] : array();
		$locality = $this->yandex_locality_from_normalized_item( $item );
		$street = trim( (string) ( $data['street_with_type'] ?? $data['street'] ?? '' ) );
		$house = trim( (string) ( $data['house'] ?? '' ) );
		$room = trim( (string) ( $data['flat'] ?? $data['room'] ?? $data['room_number'] ?? $data['premise'] ?? '' ) );
		$full_address = trim( (string) ( $item['unrestrictedValue'] ?? $item['value'] ?? $item['label'] ?? $original_address ) );
		$message = '';
		if ( '' === $locality ) {
			$message = __( 'Не удалось определить населённый пункт. Проверьте полный адрес.', 'walls-delivery-calc' );
		} elseif ( '' === $street ) {
			$message = __( 'Не удалось определить улицу. Проверьте полный адрес.', 'walls-delivery-calc' );
		} elseif ( '' === $house ) {
			$message = __( 'Не удалось определить номер дома. Проверьте полный адрес.', 'walls-delivery-calc' );
		} elseif ( empty( $item['isDeliverable'] ) ) {
			$message = __( 'Адрес распознан недостаточно точно. Уточните его и проверьте повторно.', 'walls-delivery-calc' );
		}
		$fields = array(
			'country' => 'Россия',
			'postal_code' => preg_replace( '/\D+/', '', (string) ( $data['postal_code'] ?? '' ) ) ?: '',
			'region' => trim( (string) ( $data['region_with_type'] ?? $data['region'] ?? '' ) ),
			'locality' => $locality,
			'street' => $street,
			'house' => $house,
			'room' => $room,
			'full_address' => $full_address,
		);

		return array(
			'success' => '' === $message,
			'message' => '' === $message ? __( 'Адрес Яндекс проверен через DaData.', 'walls-delivery-calc' ) : $message,
			'source' => 'dadata+yandex',
			'service_key' => YandexDeliverySettings::SERVICE_KEY,
			'original_hash' => hash( 'sha256', $original_address ),
			'display' => $full_address,
			'fields' => $fields,
			'quality' => array(
				'level' => (string) ( $item['level'] ?? '' ),
				'is_deliverable' => ! empty( $item['isDeliverable'] ),
			),
			'order_id' => method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
		);
	}

	/**
	 * @param array<string,mixed> $item
	 */
	private function yandex_locality_from_normalized_item( array $item ): string {
		$data = is_array( $item['data'] ?? null ) ? $item['data'] : array();
		foreach ( array(
			$item['locality'] ?? null,
			$item['city_name'] ?? null,
			$item['city'] ?? null,
			$item['place'] ?? null,
			$item['settlement'] ?? null,
			$data['locality'] ?? null,
			$data['city_name'] ?? null,
			$data['place'] ?? null,
			$data['settlement_with_type'] ?? null,
			$data['city_with_type'] ?? null,
			$data['settlement'] ?? null,
			$data['city'] ?? null,
		) as $value ) {
			$locality = $this->clean_yandex_locality( (string) $value );
			if ( '' !== $locality ) {
				return $locality;
			}
		}

		return $this->federal_city_locality( $data );
	}

	private function clean_yandex_locality( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		$value = preg_replace( '/^(г\.?|город|пгт|рп|рабочий\s+пос[её]лок|пос[её]лок|с\.?|село|д\.?|деревня)\s+/iu', '', $value ) ?? $value;
		return trim( $value );
	}

	/** @param array<string,mixed> $data */
	private function federal_city_locality( array $data ): string {
		$region = $this->clean_yandex_locality( (string) ( $data['region_with_type'] ?? $data['region'] ?? '' ) );
		$normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( $region ) : strtolower( $region );
		foreach ( array( 'москва', 'санкт-петербург', 'севастополь' ) as $city ) {
			if ( $city === $normalized ) {
				return $region;
			}
		}

		return '';
	}

	/**
	 * @return array<string,string>
	 */
	private function yandex_address_suggestion_context( object $order ): array {
		return array_filter(
			array(
				'country_code' => 'RU',
				'location_city_fias_id' => method_exists( $order, 'get_meta' ) ? (string) $order->get_meta( '_wdc_platform_location_fias_id', true ) : '',
			),
			static fn( string $value ): bool => '' !== trim( $value )
		);
	}

	private function dadata_error_message( string $code ): string {
		return match ( $code ) {
			'no_available_dadata_token' => __( 'Не настроен токен DaData для проверки адреса.', 'walls-delivery-calc' ),
			'dadata_daily_limit_exhausted' => __( 'Лимит DaData исчерпан. Повторите проверку позднее.', 'walls-delivery-calc' ),
			'dadata_timeout' => __( 'DaData не ответила вовремя. Повторите проверку адреса.', 'walls-delivery-calc' ),
			default => __( 'Не удалось проверить адрес через DaData.', 'walls-delivery-calc' ),
		};
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array{error:string}
	 */
	private function maybe_prepare_cdek_courier_address( object $order, array &$data ): array {
		$carrier_key = sanitize_key( wp_unslash( $data['carrier_key'] ?? '' ) );
		$delivery_type = RussianPostDomesticSettings::normalize_delivery_type( sanitize_key( wp_unslash( $data['delivery_type'] ?? '' ) ) );
		if ( CdekSettings::CARRIER_KEY !== $carrier_key || DeliveryType::COURIER !== $delivery_type ) {
			return array( 'error' => '' );
		}
		$original_address = sanitize_text_field( wp_unslash( $data['courier_original_address'] ?? $data['original_address'] ?? '' ) );
		$snapshot = $this->decoded_json_field( $data['normalized_address_json'] ?? '' );
		$valid = ! empty( $snapshot['success'] )
			&& (string) ( $snapshot['source'] ?? '' ) === 'dadata+cdek_location'
			&& (string) ( $snapshot['original_hash'] ?? '' ) === hash( 'sha256', trim( $original_address ) )
			&& (int) ( $snapshot['fields']['cdek_city_code'] ?? 0 ) > 0;
		if ( $valid ) {
			return array( 'error' => '' );
		}
		if ( ! $this->cdek_address_preparation instanceof CdekRecipientAddressPreparationService ) {
			return array( 'error' => __( 'Нормализация адреса СДЭК недоступна.', 'walls-delivery-calc' ) );
		}
		$prepared = $this->cdek_address_preparation->prepare( $order, $original_address, $this->recipient_location_context_from_request( $order, $data ), CdekSettings::SERVICE_KEY );
		$data['normalized_address_json'] = wp_json_encode( $prepared, JSON_UNESCAPED_UNICODE ) ?: '';
		if ( empty( $prepared['success'] ) ) {
			return array( 'error' => (string) ( $prepared['message'] ?? CdekRecipientAddressPreparationService::CITY_CODE_ERROR ) );
		}

		return array( 'error' => '' );
	}

	/**
	 * @param mixed $value
	 * @return array<string,mixed>
	 */
	private function decoded_json_field( mixed $value ): array {
		$json = (string) wp_unslash( $value );
		$decoded = '' !== trim( $json ) ? json_decode( $json, true ) : array();

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function recipient_location_context_from_request( object $order, array $data = array() ): array {
		$city = sanitize_text_field( wp_unslash( $data['recipient_location_city'] ?? $_POST['recipient_location_city'] ?? '' ) );
		$region = sanitize_text_field( wp_unslash( $data['recipient_location_region'] ?? $_POST['recipient_location_region'] ?? '' ) );
		$postcode = sanitize_text_field( wp_unslash( $data['recipient_location_postcode'] ?? $_POST['recipient_location_postcode'] ?? '' ) );
		$address = sanitize_text_field( wp_unslash( $data['recipient_location_address'] ?? $_POST['recipient_location_address'] ?? '' ) );
		if ( '' === $city && method_exists( $order, 'get_shipping_city' ) ) {
			$city = (string) $order->get_shipping_city();
		}
		if ( '' === $region && method_exists( $order, 'get_shipping_state' ) ) {
			$region = (string) $order->get_shipping_state();
		}
		if ( '' === $postcode && method_exists( $order, 'get_shipping_postcode' ) ) {
			$postcode = (string) $order->get_shipping_postcode();
		}

		$calculation = $this->order_array_meta( $order, '_wdc_delivery_calculation_data' );
		$rate_meta = $this->order_array_meta( $order, '_wdc_platform_rate_meta' );
		$cdek_city_code = $this->cdek_city_code_from_saved_data( $calculation, $rate_meta );

		return array(
			'country_code' => 'RU',
			'cdek_city_code' => $cdek_city_code > 0 ? $cdek_city_code : '',
			'cdek_to_city_code' => $cdek_city_code > 0 ? $cdek_city_code : '',
			'delivery_calculation_data' => $calculation,
			'rate_meta' => $rate_meta,
			'city_name' => $city,
			'city_value' => $city,
			'region_name' => $region,
			'state_value' => $region,
			'postal_code' => $postcode,
			'postcode' => $postcode,
			'display_name' => '' !== $address ? $address : trim( implode( ', ', array_filter( array( $postcode, $region, $city ) ) ) ),
			'fias_id' => sanitize_text_field( wp_unslash( $data['recipient_location_fias_id'] ?? $_POST['recipient_location_fias_id'] ?? '' ) ),
			'gar_id' => sanitize_text_field( wp_unslash( $data['recipient_location_gar_id'] ?? $_POST['recipient_location_gar_id'] ?? '' ) ),
			'location_id' => sanitize_text_field( wp_unslash( $data['recipient_location_id'] ?? $_POST['recipient_location_id'] ?? '' ) ),
			'lat' => sanitize_text_field( wp_unslash( $data['recipient_location_lat'] ?? $_POST['recipient_location_lat'] ?? '' ) ),
			'lng' => sanitize_text_field( wp_unslash( $data['recipient_location_lng'] ?? $_POST['recipient_location_lng'] ?? '' ) ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function order_array_meta( object $order, string $key ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$value = $order->get_meta( $key, true );
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string,mixed> $calculation
	 * @param array<string,mixed> $rate_meta
	 */
	private function cdek_city_code_from_saved_data( array $calculation, array $rate_meta ): int {
		foreach ( array(
			$calculation['api']['cdek_to_city_code'] ?? null,
			$rate_meta['api']['cdek_to_city_code'] ?? null,
			$rate_meta['location']['cdek_to_city_code'] ?? null,
			$calculation['api']['request_payload_sanitized']['to_location']['code'] ?? null,
			$rate_meta['request_payload_sanitized']['to_location']['code'] ?? null,
			$rate_meta['api']['request_payload_sanitized']['to_location']['code'] ?? null,
		) as $value ) {
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}
		}

		return 0;
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
	 * @return array<int,string>
	 */
	private function add_dpd_courier_contact_history( string $value ): array {
		$settings = new SettingsRepository();
		$history = $this->sanitize_dpd_courier_contact_history( array_merge( array( $value ), $settings->get_array( DpdSettings::COURIER_CONTACT_FIO_HISTORY_KEY, array() ) ) );
		$settings->set( DpdSettings::COURIER_CONTACT_FIO_HISTORY_KEY, $history );

		return $history;
	}

	/**
	 * @return array<int,string>
	 */
	private function remove_dpd_courier_contact_history( string $value ): array {
		$settings = new SettingsRepository();
		$remove = sanitize_text_field( wp_unslash( $value ) );
		$history = array_values( array_filter( $this->dpd_courier_contact_history(), static fn( string $item ): bool => $item !== $remove ) );
		$settings->set( DpdSettings::COURIER_CONTACT_FIO_HISTORY_KEY, $history );

		return $history;
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
	public function ajax_search_pickup_points(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}

		$query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		$mode = in_array( $mode, array( 'location', 'nearby', 'search' ), true ) ? $mode : 'search';
		$limit = max( 1, min( 'location' === $mode ? 2000 : 100, (int) ( $_POST['limit'] ?? ( 'location' === $mode ? 2000 : 50 ) ) ) );
		$carrier_key = sanitize_key( wp_unslash( $_POST['carrier_key'] ?? '' ) );
		$purpose = sanitize_key( wp_unslash( $_POST['purpose'] ?? '' ) );
		if ( YandexDeliverySettings::CARRIER_KEY === $carrier_key && 'source_dropoff' === $purpose ) {
			$this->ajax_search_yandex_source_dropoff_points( $mode, $limit );
		}
		if ( DpdSettings::CARRIER_KEY === $carrier_key && $this->dpd_pickup_points instanceof DpdPickupPointService ) {
			$city_id = (int) preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['city_id'] ?? '' ) );
			$location_id = (int) preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['location_id'] ?? '' ) );
			if ( $city_id > 0 ) {
				$points = 'search' === $mode && '' !== $query
					? $this->dpd_pickup_points->search_parcel_shops( $query, array( 'city_id' => $city_id, 'limit' => $limit ) )
					: $this->dpd_pickup_points->get_parcel_shops_by_city_id( $city_id, $limit );
			} elseif ( $location_id > 0 && 'location' === $mode ) {
				$points = array_values(
					array_filter(
						$this->dpd_pickup_points->get_points_for_location_id( $location_id ),
						static fn( array $point ): bool => 'parcel_shop' === (string) ( $point['type'] ?? '' )
					)
				);
			} else {
				$points = $this->dpd_pickup_points->search_parcel_shops(
					$query,
					array(
						'city_name' => sanitize_text_field( wp_unslash( $_POST['city'] ?? $_POST['city_name'] ?? '' ) ),
						'limit' => $limit,
					)
				);
			}
			wp_send_json_success(
				array(
					'points' => array_map( array( $this, 'dpd_pickup_point_ajax_row' ), array_slice( $points, 0, $limit ) ),
				)
			);
		}
		if ( CdekSettings::CARRIER_KEY === $carrier_key && $this->cdek_delivery_points instanceof CdekDeliveryPointService ) {
			$points = $this->cdek_delivery_points->pointsForLocation(
				array(
					'country_code' => 'RU',
					'city_name' => sanitize_text_field( wp_unslash( $_POST['city'] ?? $_POST['city_name'] ?? '' ) ),
					'city_value' => sanitize_text_field( wp_unslash( $_POST['city'] ?? $_POST['city_name'] ?? '' ) ),
					'region_name' => sanitize_text_field( wp_unslash( $_POST['region'] ?? $_POST['region_name'] ?? '' ) ),
					'state_value' => sanitize_text_field( wp_unslash( $_POST['region'] ?? $_POST['region_name'] ?? '' ) ),
					'postal_code' => sanitize_text_field( wp_unslash( $_POST['postcode'] ?? '' ) ),
					'postcode' => sanitize_text_field( wp_unslash( $_POST['postcode'] ?? '' ) ),
					'display_name' => sanitize_text_field( wp_unslash( $_POST['address'] ?? $query ) ),
					'fias_id' => sanitize_text_field( wp_unslash( $_POST['fias_id'] ?? '' ) ),
					'gar_id' => sanitize_text_field( wp_unslash( $_POST['gar_id'] ?? '' ) ),
					'location_id' => sanitize_text_field( wp_unslash( $_POST['location_id'] ?? '' ) ),
				),
				array( 'type' => 'ALL' )
			);
			if ( 'search' === $mode && '' !== $query ) {
				$needle = $this->normalize_pickup_search_text( $query );
				$points = array_values(
					array_filter(
						$points,
						fn( array $point ): bool => str_contains(
							$this->normalize_pickup_search_text(
								implode(
									' ',
									array(
										(string) ( $point['point_code'] ?? '' ),
										(string) ( $point['cdek_code'] ?? '' ),
										(string) ( $point['point_name'] ?? '' ),
										(string) ( $point['point_address'] ?? $point['address'] ?? '' ),
										(string) ( $point['point_postcode'] ?? $point['postcode'] ?? '' ),
									)
								)
							),
							$needle
						)
					)
				);
			}
			wp_send_json_success(
				array(
					'points' => array_values( $points ),
				)
			);
		}
		$repository = new RussianPostPickupPointRepository();
		if ( 'location' === $mode ) {
			$location_context = array(
				'city_name' => sanitize_text_field( wp_unslash( $_POST['city'] ?? $_POST['city_name'] ?? '' ) ),
				'city_value' => sanitize_text_field( wp_unslash( $_POST['city'] ?? $_POST['city_name'] ?? '' ) ),
				'region_name' => sanitize_text_field( wp_unslash( $_POST['region'] ?? $_POST['region_name'] ?? '' ) ),
				'state_value' => sanitize_text_field( wp_unslash( $_POST['region'] ?? $_POST['region_name'] ?? '' ) ),
				'postal_code' => sanitize_text_field( wp_unslash( $_POST['postcode'] ?? '' ) ),
				'postcode' => sanitize_text_field( wp_unslash( $_POST['postcode'] ?? '' ) ),
				'display_name' => sanitize_text_field( wp_unslash( $_POST['address'] ?? $query ) ),
				'fias_id' => sanitize_text_field( wp_unslash( $_POST['fias_id'] ?? '' ) ),
				'gar_id' => sanitize_text_field( wp_unslash( $_POST['gar_id'] ?? '' ) ),
				'location_id' => sanitize_text_field( wp_unslash( $_POST['location_id'] ?? '' ) ),
			);
			$order_id = (int) ( $_POST['order_id'] ?? 0 );
			if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
				if ( is_object( $order ) ) {
					$shipping_city = method_exists( $order, 'get_shipping_city' ) ? (string) $order->get_shipping_city() : '';
					$shipping_region = method_exists( $order, 'get_shipping_state' ) ? (string) $order->get_shipping_state() : '';
					$shipping_postcode = method_exists( $order, 'get_shipping_postcode' ) ? (string) $order->get_shipping_postcode() : '';
					$shipping_address = trim(
						implode(
							' ',
							array_filter(
								array(
									method_exists( $order, 'get_shipping_address_1' ) ? (string) $order->get_shipping_address_1() : '',
									method_exists( $order, 'get_shipping_address_2' ) ? (string) $order->get_shipping_address_2() : '',
								),
								static fn( string $value ): bool => '' !== trim( $value )
							)
						)
					);
					$location_context['city_name'] = '' !== trim( (string) $location_context['city_name'] ) ? $location_context['city_name'] : $shipping_city;
					$location_context['city_value'] = '' !== trim( (string) $location_context['city_value'] ) ? $location_context['city_value'] : $shipping_city;
					$location_context['region_name'] = '' !== trim( (string) $location_context['region_name'] ) ? $location_context['region_name'] : $shipping_region;
					$location_context['state_value'] = '' !== trim( (string) $location_context['state_value'] ) ? $location_context['state_value'] : $shipping_region;
					$location_context['postal_code'] = '' !== trim( (string) $location_context['postal_code'] ) ? $location_context['postal_code'] : $shipping_postcode;
					$location_context['postcode'] = '' !== trim( (string) $location_context['postcode'] ) ? $location_context['postcode'] : $shipping_postcode;
					$location_context['display_name'] = '' !== trim( (string) $location_context['display_name'] ) ? $location_context['display_name'] : $shipping_address;
				}
			}
			$rows = $repository->find_rows_by_location_context(
				$location_context,
				array( 'limit' => $limit )
			);
		} else {
			$rows = $repository->search_admin_pickup_rows( $query, array( 'limit' => $limit ) );
		}

		wp_send_json_success(
			array(
				'points' => array_map( array( $this, 'pickup_point_ajax_row' ), $rows ),
			)
		);
	}

	public function ajax_search_products(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		if ( ! function_exists( 'wc_get_products' ) || ! function_exists( 'wc_get_product' ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
		if ( '' === trim( $query ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$products = array();
		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$sku_id = (int) wc_get_product_id_by_sku( $query );
			if ( $sku_id > 0 ) {
				$product = wc_get_product( $sku_id );
				if ( is_object( $product ) ) {
					$products[ $sku_id ] = $product;
				}
			}
		}

		foreach ( $this->product_ids_by_partial_sku( $query, 20 ) as $sku_id ) {
			$product = wc_get_product( $sku_id );
			if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				$products[ (int) $product->get_id() ] = $product;
			}
			if ( count( $products ) >= 20 ) {
				break;
			}
		}

		foreach ( wc_get_products( array( 'limit' => 20, 'status' => array( 'publish', 'private' ), 'type' => array( 'simple', 'variation' ), 's' => $query ) ) as $product ) {
			if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				$products[ (int) $product->get_id() ] = $product;
			}
			if ( count( $products ) >= 20 ) {
				break;
			}
		}

		$items = array();
		foreach ( array_slice( $products, 0, 20, true ) as $product ) {
			$items[] = $this->shipment_product_search_row( $product );
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	/**
	 * @return array<int,int>
	 */
	private function product_ids_by_partial_sku( string $query, int $limit ): array {
		global $wpdb;

		if ( '' === $query || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return array();
		}

		$postmeta = isset( $wpdb->postmeta ) ? (string) $wpdb->postmeta : '';
		if ( '' === $postmeta ) {
			return array();
		}

		$like = function_exists( 'esc_like' ) ? esc_like( $query ) : addcslashes( $query, '_%\\' );
		$sql = "SELECT post_id FROM {$postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s LIMIT %d";
		if ( method_exists( $wpdb, 'prepare' ) ) {
			$sql = $wpdb->prepare( $sql, '%' . $like . '%', max( 1, $limit ) );
		} else {
			$sql = str_replace( array( '%s', '%d' ), array( "'" . str_replace( "'", "''", '%' . $like . '%' ) . "'", (string) max( 1, $limit ) ), $sql );
		}

		return array_values( array_filter( array_map( 'intval', (array) $wpdb->get_col( $sql ) ) ) );
	}

	private function normalize_pickup_search_text( string $value ): string {
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );

		return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function shipment_product_search_row( object $product ): array {
		$product_id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		$is_variation = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' );
		$price = method_exists( $product, 'get_price' ) ? (float) $product->get_price() : 0.0;
		$weight = method_exists( $product, 'get_weight' ) ? (string) $product->get_weight() : '';
		$length = method_exists( $product, 'get_length' ) ? (string) $product->get_length() : '';
		$width = method_exists( $product, 'get_width' ) ? (string) $product->get_width() : '';
		$height = method_exists( $product, 'get_height' ) ? (string) $product->get_height() : '';

		return array(
			'product_id' => $is_variation && $parent_id > 0 ? $parent_id : $product_id,
			'variation_id' => $is_variation ? $product_id : 0,
			'name' => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '',
			'sku' => method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
			'price' => round( max( 0.0, $price ), 2 ),
			'weight_g' => max( 1, (int) round( function_exists( 'wc_get_weight' ) ? (float) wc_get_weight( $weight, 'g' ) : (float) $weight ) ),
			'length_cm' => max( 0.1, round( function_exists( 'wc_get_dimension' ) ? (float) wc_get_dimension( $length, 'cm' ) : (float) $length, 1 ) ),
			'width_cm' => max( 0.1, round( function_exists( 'wc_get_dimension' ) ? (float) wc_get_dimension( $width, 'cm' ) : (float) $width, 1 ) ),
			'height_cm' => max( 0.1, round( function_exists( 'wc_get_dimension' ) ? (float) wc_get_dimension( $height, 'cm' ) : (float) $height, 1 ) ),
		);
	}

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

	private function preview_request( \WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest $request ): \WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest {
		return new \WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest(
			$request->order_id,
			$request->carrier_key,
			$request->delivery_type,
			$request->rate_id,
			$request->recipient_address,
			$request->pickup_point,
			$request->places,
			$request->declared_value,
			$request->insurance_enabled,
			$request->services,
			$request->recipient,
			array_merge( $request->meta, array( 'allow_failed_normalization_preview' => true ) )
		);
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
		$label_actions = $this->document_actions_for_carrier( $order, $carrier_key, $shipment );
		if ( array() !== $label_actions ) {
			$status['label_actions'] = $label_actions;
		}

		return array(
			'carrier_key' => $carrier_key,
			'shipment' => $shipment,
			'status' => $status,
			'presentation' => $presentation,
			'label_actions' => $label_actions,
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
	 * @return array{label:string,display_text:string,url:string,copy_value:string}
	 */
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

	/** @param array{label:string,display_text:string,url:string,copy_value:string} $tracking */
	private function render_tracking_value( array $tracking ): void {
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

	private function ajax_search_yandex_source_dropoff_points( string $mode, int $limit ): void {
		$limit = max( 1, min( 2000, $limit ) );
		$source_location_id = (int) preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['source_location_id'] ?? $_POST['location_id'] ?? '' ) );
		$source_platform_station_id = sanitize_text_field( wp_unslash( $_POST['source_platform_station_id'] ?? '' ) );
		$default_row = '' !== trim( $source_platform_station_id ) ? $this->yandex_pickup_points()->find( $source_platform_station_id ) : null;
		$context = array(
			'mode' => $mode,
			'center' => $this->yandex_source_dropoff_center( is_array( $default_row ) ? $default_row : null, array() ),
			'radius_km' => null,
			'total' => 0,
			'source_location_id' => $source_location_id,
			'yandex_geo_ids' => array(),
		);

		if ( 'nearby' === $mode ) {
			$latitude = filter_var( wp_unslash( $_POST['latitude'] ?? $_POST['lat'] ?? null ), FILTER_VALIDATE_FLOAT );
			$longitude = filter_var( wp_unslash( $_POST['longitude'] ?? $_POST['lng'] ?? null ), FILTER_VALIDATE_FLOAT );
			$radius_km = filter_var( wp_unslash( $_POST['radius_km'] ?? 10 ), FILTER_VALIDATE_FLOAT );
			if ( false === $latitude || false === $longitude || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ) {
				wp_send_json_error( array( 'message' => __( 'Не удалось определить область поиска ПВЗ.', 'walls-delivery-calc' ) ), 400 );
			}
			$radius_km = false === $radius_km ? 10.0 : max( 1.0, min( 50.0, (float) $radius_km ) );
			$rows = $this->yandex_pickup_points()->search_source_dropoff_points_near( (float) $latitude, (float) $longitude, $radius_km, min( 200, $limit ) );
			$context['center'] = array( 'lat' => (float) $latitude, 'lng' => (float) $longitude );
			$context['radius_km'] = $radius_km;
			$context['total'] = count( $rows );
			wp_send_json_success(
				array(
					'points' => array_map( array( $this, 'yandex_source_dropoff_ajax_row' ), $rows ),
					'context' => $context,
					'message' => array() === $rows ? __( 'Рядом с найденным адресом нет ПВЗ Яндекс, принимающих отправления.', 'walls-delivery-calc' ) : '',
				)
			);
		}

		$geo_ids = $source_location_id > 0 ? $this->yandex_location_mapping()->geo_ids_for_location( $source_location_id ) : array();
		if ( array() === $geo_ids && is_array( $default_row ) && (int) ( $default_row['yandex_geo_id'] ?? 0 ) > 0 ) {
			$geo_ids = array( (int) $default_row['yandex_geo_id'] );
		}
		if ( array() !== $geo_ids ) {
			$rows = $this->yandex_pickup_points()->source_dropoff_map_points_by_geo_ids( $geo_ids, $limit );
		} elseif ( is_array( $default_row ) && is_numeric( $default_row['latitude'] ?? null ) && is_numeric( $default_row['longitude'] ?? null ) ) {
			$rows = $this->yandex_pickup_points()->search_source_dropoff_points_near( (float) $default_row['latitude'], (float) $default_row['longitude'], 10.0, min( 200, $limit ) );
		} else {
			$rows = array();
		}

		$context['mode'] = 'location';
		$context['center'] = $this->yandex_source_dropoff_center( is_array( $default_row ) ? $default_row : null, $rows );
		$context['total'] = count( $rows );
		$context['yandex_geo_ids'] = array_values( array_map( 'intval', $geo_ids ) );
		wp_send_json_success(
			array(
				'points' => array_map( array( $this, 'yandex_source_dropoff_ajax_row' ), $rows ),
				'context' => $context,
				'message' => array() === $rows ? __( 'В выбранном городе не найдены ПВЗ Яндекс, принимающие отправления.', 'walls-delivery-calc' ) : '',
			)
		);
	}

	private function yandex_pickup_points(): YandexDeliveryPickupPointV2Repository {
		if ( ! $this->yandex_pickup_points instanceof YandexDeliveryPickupPointV2Repository ) {
			$this->yandex_pickup_points = new YandexDeliveryPickupPointV2Repository();
		}

		return $this->yandex_pickup_points;
	}

	private function yandex_location_mapping(): YandexLocationMappingV2Repository {
		if ( ! $this->yandex_location_mapping instanceof YandexLocationMappingV2Repository ) {
			$this->yandex_location_mapping = new YandexLocationMappingV2Repository();
		}

		return $this->yandex_location_mapping;
	}

	/**
	 * @param array<string,mixed>|null $source_row
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<string,float>|null
	 */
	private function yandex_source_dropoff_center( ?array $source_row, array $rows ): ?array {
		if ( is_array( $source_row ) && is_numeric( $source_row['latitude'] ?? null ) && is_numeric( $source_row['longitude'] ?? null ) ) {
			return array( 'lat' => (float) $source_row['latitude'], 'lng' => (float) $source_row['longitude'] );
		}
		$lat_sum = 0.0;
		$lng_sum = 0.0;
		$count = 0;
		foreach ( $rows as $row ) {
			if ( is_numeric( $row['latitude'] ?? null ) && is_numeric( $row['longitude'] ?? null ) ) {
				$lat_sum += (float) $row['latitude'];
				$lng_sum += (float) $row['longitude'];
				++$count;
			}
		}

		return $count > 0 ? array( 'lat' => round( $lat_sum / $count, 7 ), 'lng' => round( $lng_sum / $count, 7 ) ) : null;
	}

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
	private function pickup_point_ajax_row( array $row ): array {
		return array(
			'point_code' => (string) ( $row['point_code'] ?? '' ),
			'postcode' => (string) ( $row['postcode'] ?? '' ),
			'region_name' => (string) ( $row['region_name'] ?? '' ),
			'city_name' => (string) ( $row['city_name'] ?? '' ),
			'address' => (string) ( $row['address'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function yandex_source_dropoff_ajax_row( array $row ): array {
		$station_id = (string) ( $row['platform_station_id'] ?? '' );
		$title = (string) ( $row['name'] ?? '' );
		$address = (string) ( $row['full_address'] ?? '' );

		return array(
			'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
			'carrier' => YandexDeliverySettings::CARRIER_KEY,
			'service_key' => YandexDeliverySettings::SERVICE_KEY,
			'pickup_family' => YandexDeliverySettings::CARRIER_KEY . ':source_dropoff',
			'point_code' => $station_id,
			'platform_station_id' => $station_id,
			'display_code' => $station_id,
			'point_type' => 'source_dropoff',
			'type' => (string) ( $row['type'] ?? 'pickup_point' ),
			'point_title' => '' !== $title ? $title : ( '' !== $address ? $address : $station_id ),
			'display_title' => '' !== $title ? $title : ( '' !== $address ? $address : $station_id ),
			'region_name' => (string) ( $row['region'] ?? '' ),
			'city_name' => (string) ( $row['locality'] ?? '' ),
			'city' => (string) ( $row['locality'] ?? '' ),
			'address' => $address,
			'work_time' => (string) ( $row['schedule_text'] ?? '' ),
			'schedule_text' => (string) ( $row['schedule_text'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'drop_off' => true,
			'available_for_dropoff' => true,
			'marker_type' => 'source_dropoff',
			'distance_km' => isset( $row['distance_km'] ) ? (float) $row['distance_km'] : null,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function dpd_pickup_point_ajax_row( array $row ): array {
		return array(
			'carrier_key' => DpdSettings::CARRIER_KEY,
			'carrier' => DpdSettings::CARRIER_KEY,
			'point_code' => (string) ( $row['terminal_code'] ?? '' ),
			'display_code' => (string) ( $row['terminal_code'] ?? '' ),
			'point_type' => (string) ( $row['type'] ?? 'parcel_shop' ),
			'type' => (string) ( $row['type'] ?? 'parcel_shop' ),
			'point_title' => (string) ( $row['name'] ?? 'ПВЗ DPD' ),
			'display_title' => (string) ( $row['name'] ?? 'ПВЗ DPD' ),
			'postcode' => '',
			'region_name' => (string) ( $row['region_name'] ?? '' ),
			'city_id' => (string) ( $row['city_id'] ?? '' ),
			'city_name' => (string) ( $row['city_name'] ?? '' ),
			'address' => (string) ( $row['address'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'source' => (string) ( $row['source'] ?? '' ),
		);
	}
}
