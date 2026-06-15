<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Pickup\Cdek\CdekDeliveryPointService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentBacklogService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Cdek\CdekOrderStatusService;
use WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService;
use WallsShop\WDC\Shipments\RussianPost\RussianPostAddressNormalizer;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OrderShipmentsMetabox {
	private const NONCE_ACTION = 'wdc_shipments_admin';
	private const AJAX_CREATE = 'wdc_create_shipment';
	private const AJAX_PREVIEW = 'wdc_preview_shipment';
	private const AJAX_UPDATE_STATUS = 'wdc_update_shipment_status';
	private const AJAX_CANCEL = 'wdc_cancel_shipment';
	private const AJAX_REMOVE_FROM_ORDER = 'wdc_remove_shipment_from_order';
	private const AJAX_ATTACH_TRACKING = 'wdc_attach_shipment_tracking_number';
	private const AJAX_NORMALIZE_ADDRESS = 'wdc_normalize_shipment_address';
	private const AJAX_SEARCH_PICKUP_POINTS = 'wdc_search_russian_post_pickup_points';
	private const AJAX_SEARCH_PRODUCTS = 'wdc_search_products_for_shipment_item';

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
		private ?CdekRecipientAddressPreparationService $cdek_address_preparation = null,
		private string $plugin_url = '',
		private string $version = '1'
	) {
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_CREATE, array( $this, 'ajax_create' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_' . self::AJAX_UPDATE_STATUS, array( $this, 'ajax_update_status' ) );
		add_action( 'wp_ajax_' . self::AJAX_CANCEL, array( $this, 'ajax_cancel' ) );
		add_action( 'wp_ajax_' . self::AJAX_REMOVE_FROM_ORDER, array( $this, 'ajax_remove_from_order' ) );
		add_action( 'wp_ajax_' . self::AJAX_ATTACH_TRACKING, array( $this, 'ajax_attach_tracking' ) );
		add_action( 'wp_ajax_' . self::AJAX_NORMALIZE_ADDRESS, array( $this, 'ajax_normalize_address' ) );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH_PICKUP_POINTS, array( $this, 'ajax_search_pickup_points' ) );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH_PRODUCTS, array( $this, 'ajax_search_products' ) );
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
				'cancelAction' => self::AJAX_CANCEL,
				'removeFromOrderAction' => self::AJAX_REMOVE_FROM_ORDER,
				'attachTrackingAction' => self::AJAX_ATTACH_TRACKING,
				'normalizeAddressAction' => self::AJAX_NORMALIZE_ADDRESS,
				'searchPickupPointsAction' => self::AJAX_SEARCH_PICKUP_POINTS,
				'searchProductsAction' => self::AJAX_SEARCH_PRODUCTS,
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
		$postoffice_codes = is_array( $draft['postoffice_codes'] ?? null ) ? $draft['postoffice_codes'] : array( '630005' );
		$recipient = is_array( $request['recipient'] ?? null ) ? $request['recipient'] : array();
		$address = is_array( $request['recipient_address'] ?? null ) ? $request['recipient_address'] : array();
		$place = is_array( $request['places'][0] ?? null ) ? $request['places'][0] : array();
		$meta = is_array( $request['meta'] ?? null ) ? $request['meta'] : array();
		$carrier_key = (string) ( $request['carrier_key'] ?? $meta['carrier_key'] ?? '' );
		$service_key = (string) ( $meta['service_key'] ?? $request['rate_id'] ?? '' );
		$is_cdek = CdekSettings::CARRIER_KEY === $carrier_key;
		$is_russian_post = RussianPostDomesticSettings::CARRIER_KEY === $carrier_key;
		$shipment = '' !== $carrier_key ? $this->repository->find_by_carrier( $order, $carrier_key ) : array();
		$settings = is_array( $request['services'] ?? null ) ? $request['services'] : array();
		$pickup_code = (string) ( $request['pickup_point']['point_code'] ?? $meta['pickup_point_code'] ?? '' );
		$pickup_destination_index = $this->pickup_destination_index( $pickup_code, (string) ( $address['postcode'] ?? '' ), $meta );
		$pickup_display_value = $is_cdek ? $pickup_code : $pickup_destination_index;
		$pickup_row = is_array( $meta['pickup_point_row'] ?? null ) ? $meta['pickup_point_row'] : array();
		$cdek_pickup_type_label = $is_cdek ? $this->cdek_pickup_type_label( $pickup_row ) : '';
		$pickup_postcode = (string) ( $pickup_row['postcode'] ?? $pickup_destination_index );
		$pickup_context = is_array( $meta['pickup_location_context'] ?? null ) ? $meta['pickup_location_context'] : array();
		$order_shipping_city = method_exists( $order, 'get_shipping_city' ) ? (string) $order->get_shipping_city() : '';
		$order_shipping_region = method_exists( $order, 'get_shipping_state' ) ? (string) $order->get_shipping_state() : '';
		$order_shipping_postcode = method_exists( $order, 'get_shipping_postcode' ) ? (string) $order->get_shipping_postcode() : '';
		$order_shipping_address = trim(
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
		$region = (string) ( $address['region_name'] ?? $order_shipping_region );
		$city = (string) ( $address['settlement'] ?? $address['city'] ?? $order_shipping_city );
		$recipient_postcode = (string) ( $address['postcode'] ?? $order_shipping_postcode );
		$recipient_address_context = (string) ( $address['raw_address'] ?? $order_shipping_address );
		$pickup_demand_address = implode( ', ', array_filter( array( $pickup_destination_index, $region, $city, 'до востребования' ), static fn ( string $value ): bool => '' !== trim( $value ) ) );
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
		$selected_delivery_mode = (int) ( $meta['delivery_mode'] ?? $meta['cdek_delivery_mode'] ?? 0 );
		foreach ( $selected_service_tariffs as $tariff ) {
			if ( $selected_tariff_object === (string) ( $tariff['object_code'] ?? '' ) && in_array( (int) ( $tariff['delivery_mode'] ?? 0 ), array( 1, 2, 3, 4 ), true ) ) {
				$selected_delivery_mode = (int) $tariff['delivery_mode'];
				break;
			}
		}
		$cdek_recipient_door = $is_cdek && in_array( $selected_delivery_mode, array( 1, 3 ), true );
		$cdek_sender_door = $is_cdek && in_array( $selected_delivery_mode, array( 1, 2 ), true );
		if ( '' === trim( $selected_tariff_title ) && '' !== $selected_tariff_object ) {
			$selected_tariff_title = sprintf( __( 'тариф %s', 'walls-delivery-calc' ), $selected_tariff_object );
		}
		$has_selected_service_tariffs = array() !== $selected_service_tariffs;
		$tariff_message_hidden_attr = $has_selected_service_tariffs ? ' hidden' : '';
		$calculated_weight_g = max( 0, (int) ( $meta['place_weight_hint_g'] ?? $place['weight_g'] ?? 0 ) );
		$weight_hint = $calculated_weight_g > 0 ? sprintf( '(%d)', $calculated_weight_g ) : '';
		$shipment_point = (string) ( $meta['shipment_point'] ?? '' );
		$shipment_point_address = (string) ( $meta['shipment_point_address'] ?? '' );
		$shipment_point_display = implode( ', ', array_filter( array( $shipment_point, $shipment_point_address ), static fn( string $value ): bool => '' !== trim( $value ) ) );
		$sender_from_door_display = implode( ', ', array_filter( array( (string) ( $meta['sender_city_name'] ?? '' ), (string) ( $meta['sender_address'] ?? '' ) ), static fn( string $value ): bool => '' !== trim( $value ) ) );
		$default_declared_value_rub = max( 0, (int) ( $meta['default_declared_value_rub'] ?? 0 ) );
		$default_declared_value_attr = $default_declared_value_rub > 0 ? (string) $default_declared_value_rub : '';
		$declared_value_initial = $selected_tariff_has_declared_value ? $default_declared_value_attr : '';
		$delivery_type = $selected_delivery_type;
		$pickup_point_found = ! empty( $meta['pickup_point_found'] );
		$pickup_address = $recipient_address_context;
		$courier_original_address = (string) ( $meta['courier_original_address'] ?? '' );
		$normalized_address = is_array( $meta['normalized_address'] ?? null ) ? $meta['normalized_address'] : array();
		$normalized_display = (string) ( $normalized_address['display'] ?? '' );
		$normalized_is_cdek = 'dadata+cdek_location' === (string) ( $normalized_address['source'] ?? '' );
		$normalized_status = array() !== $normalized_address
			? ( ! empty( $normalized_address['success'] ) ? ( $normalized_is_cdek ? '✅ Данные для СДЭК корректны' : 'Адрес обработан Почтой России.' ) : ( $normalized_is_cdek ? (string) ( $normalized_address['message'] ?? 'Адрес не подтвержден СДЭК, создание отправления заблокировано.' ) : 'Адрес не подтвержден Почтой России, создание отправления заблокировано.' ) )
			: 'Адрес нужно обработать перед созданием отправления.';
		$normalized_json = wp_json_encode( $normalized_address, JSON_UNESCAPED_UNICODE ) ?: '';
		$has_created = in_array( (string) ( $shipment['status'] ?? '' ), array( 'registration_pending', 'created', 'registered' ), true );
		$barcode = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
		$backlog_order_id = trim( (string) ( $shipment['backlog_order_id'] ?? '' ) );
		$status_payload = $is_cdek && $this->cdek_status_updates instanceof CdekOrderStatusService ? $this->cdek_status_updates->status_payload( $shipment, $order ) : $this->status_updates->status_payload( $shipment, $order );
		$status_payload = array_merge( $status_payload, array( 'carrier_key' => $carrier_key ) );
		$presentation = $this->carrier_presentation( $carrier_key );
		$price_label = (string) ( $status_payload['actual_cost_label'] ?? '' );
		$price_compare_status = (string) ( $status_payload['actual_cost_compare_status'] ?? '' );
		$price_compare_message = (string) ( $status_payload['actual_cost_compare_message'] ?? '' );
		$can_cancel = $is_russian_post && $this->can_cancel_shipment( $shipment );
		if ( $is_cdek ) {
			$can_cancel = ! empty( $status_payload['can_cancel'] );
		}
		$can_remove = ! empty( $status_payload['can_remove_from_order'] ) || ( $is_russian_post && '' !== $barcode && ! $can_cancel );
		$can_update = ! empty( $status_payload['can_update_status'] ) || ( $has_created && ( $is_cdek || '' !== $barcode ) );
		$show_primary_actions = '' !== $carrier_key && ! $has_created;
		$show_update = $has_created && $can_update;
		$show_cancel = $has_created && $can_cancel;
		$show_remove = $has_created && $can_remove;
		?>
		<div class="wdc-shipments-metabox" data-wdc-shipments-metabox data-carrier-key="<?php echo esc_attr( $carrier_key ); ?>" data-has-shipment="<?php echo $has_created ? '1' : '0'; ?>" <?php $this->render_presentation_attrs( $presentation ); ?>>
			<p><strong><?php echo esc_html__( 'Служба', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( (string) ( $meta['service_title'] ?? $request['rate_id'] ?? '-' ) ); ?></p>
			<p><strong><?php echo esc_html__( 'Статус посылки', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-shipment-summary-status><?php echo esc_html( $this->shipment_status_label( $shipment ) ); ?></span></p>
			<p data-wdc-tracking-row <?php echo '' === $barcode ? 'hidden' : ''; ?>><strong data-wdc-tracking-label><?php echo esc_html( $presentation['tracking_label'] ); ?></strong>: <span data-wdc-tracking-number><?php echo esc_html( $barcode ); ?></span> <button type="button" class="wdc-copy-tracking-icon" data-wdc-copy-tracking data-tracking-number="<?php echo esc_attr( $barcode ); ?>" aria-label="<?php echo esc_attr__( 'Копировать номер отслеживания', 'walls-delivery-calc' ); ?>" title="<?php echo esc_attr__( 'Копировать', 'walls-delivery-calc' ); ?>" <?php disabled( '' === $barcode ); ?>>🗐</button> <span class="description" data-wdc-copy-tracking-status></span></p>
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
				<button type="button" class="button" data-wdc-open-manual-tracking <?php echo $show_primary_actions ? '' : 'hidden'; ?> <?php disabled( ! $show_primary_actions ); ?>><?php echo esc_html( $presentation['manual_attach_button_label'] ); ?></button>
				<button type="button" class="button" data-wdc-cancel-shipment data-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-shipment-key="<?php echo esc_attr( $carrier_key ); ?>" <?php echo $show_cancel ? '' : 'hidden'; ?> <?php disabled( ! $can_cancel ); ?>><?php echo esc_html( $presentation['cancel_button_label'] ); ?></button>
				<button type="button" class="button" data-wdc-remove-shipment-from-order data-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-shipment-key="<?php echo esc_attr( $carrier_key ); ?>" <?php echo $show_remove ? '' : 'hidden'; ?> <?php disabled( ! $show_remove ); ?>><?php echo esc_html( $presentation['remove_button_label'] ); ?></button>
			</p>
			<div class="wdc-manual-tracking" data-wdc-manual-tracking-form hidden>
				<label><span data-wdc-manual-attach-label><?php echo esc_html( $presentation['manual_attach_placeholder'] ); ?></span><input type="text" data-wdc-manual-tracking-input autocomplete="off" placeholder="<?php echo esc_attr( $presentation['manual_attach_placeholder'] ); ?>"></label>
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
					<div id="wdc-shipment-form-<?php echo esc_attr( (string) $order_id ); ?>" class="wdc-shipment-form" data-wdc-shipment-form="1" role="group">
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
									<input type="hidden" name="pickup_point_code" value="<?php echo esc_attr( $pickup_code ); ?>">
									<?php if ( $is_cdek ) : ?><input type="hidden" name="delivery_point" value="<?php echo esc_attr( $pickup_code ); ?>" data-wdc-delivery-point-field><?php endif; ?>
									<input type="hidden" name="pickup_point_postcode" value="<?php echo esc_attr( $pickup_postcode ); ?>" data-wdc-pickup-postcode-field>
									<input type="hidden" name="pickup_point_address" value="<?php echo esc_attr( $pickup_address ); ?>" data-wdc-pickup-address-field>
									<input type="hidden" name="pickup_point_city" value="<?php echo esc_attr( $city ); ?>" data-wdc-pickup-city-field>
									<input type="hidden" name="pickup_point_region" value="<?php echo esc_attr( $region ); ?>" data-wdc-pickup-region-field>
									<input type="hidden" name="pickup_point_type" value="<?php echo esc_attr( (string) ( $pickup_row['point_type'] ?? '' ) ); ?>" data-wdc-pickup-type-field>
									<input type="hidden" name="pickup_point_title" value="<?php echo esc_attr( (string) ( $pickup_row['display_title'] ?? $pickup_row['point_title'] ?? '' ) ); ?>" data-wdc-pickup-title-field>
									<input type="hidden" name="pickup_point_lat" value="<?php echo esc_attr( (string) ( $pickup_row['lat'] ?? '' ) ); ?>" data-wdc-pickup-lat-field>
									<input type="hidden" name="pickup_point_lng" value="<?php echo esc_attr( (string) ( $pickup_row['lng'] ?? '' ) ); ?>" data-wdc-pickup-lng-field>
									<input type="hidden" name="pickup_carrier_key" value="<?php echo esc_attr( $is_cdek ? CdekSettings::CARRIER_KEY : RussianPostDomesticSettings::CARRIER_KEY ); ?>" data-wdc-pickup-carrier-key>
									<input type="hidden" name="pickup_service_key" value="<?php echo esc_attr( $is_cdek ? CdekSettings::SERVICE_KEY : RussianPostDomesticSettings::SERVICE_KEY ); ?>" data-wdc-pickup-service-key>
									<input type="hidden" name="pickup_family" value="<?php echo esc_attr( (string) ( $meta['pickup_family'] ?? ( $is_cdek ? CdekSettings::CARRIER_KEY . ':pickup' : RussianPostDomesticSettings::CARRIER_KEY . ':pickup' ) ) ); ?>" data-wdc-pickup-family>
									<input type="hidden" name="recipient_location_city" value="<?php echo esc_attr( (string) ( $pickup_context['city_name'] ?? $pickup_context['city_value'] ?? $city ) ); ?>" data-wdc-pickup-location-city>
									<input type="hidden" name="recipient_location_region" value="<?php echo esc_attr( (string) ( $pickup_context['region_name'] ?? $pickup_context['state_value'] ?? $region ) ); ?>" data-wdc-pickup-location-region>
									<input type="hidden" name="recipient_location_postcode" value="<?php echo esc_attr( (string) ( $pickup_context['postal_code'] ?? $pickup_context['postcode'] ?? $recipient_postcode ) ); ?>" data-wdc-pickup-location-postcode>
									<input type="hidden" name="recipient_location_address" value="<?php echo esc_attr( (string) ( $pickup_context['address'] ?? $pickup_context['display_name'] ?? $recipient_address_context ) ); ?>" data-wdc-pickup-location-address>
									<input type="hidden" name="recipient_location_fias_id" value="<?php echo esc_attr( (string) ( $pickup_context['fias_id'] ?? '' ) ); ?>" data-wdc-pickup-location-fias>
									<input type="hidden" name="recipient_location_gar_id" value="<?php echo esc_attr( (string) ( $pickup_context['gar_id'] ?? '' ) ); ?>" data-wdc-pickup-location-gar>
									<input type="hidden" name="recipient_location_id" value="<?php echo esc_attr( (string) ( $pickup_context['location_id'] ?? '' ) ); ?>" data-wdc-pickup-location-id>
									<input type="hidden" name="recipient_location_lat" value="<?php echo esc_attr( (string) ( $pickup_context['lat'] ?? '' ) ); ?>" data-wdc-pickup-location-lat>
									<input type="hidden" name="recipient_location_lng" value="<?php echo esc_attr( (string) ( $pickup_context['lng'] ?? '' ) ); ?>" data-wdc-pickup-location-lng>
									<p><strong><?php echo esc_html( $is_cdek ? __( 'Код ПВЗ', 'walls-delivery-calc' ) : __( 'Индекс выбранного ПВЗ / ОПС', 'walls-delivery-calc' ) ); ?>:</strong> <span data-wdc-pickup-index><?php echo esc_html( '' !== $pickup_display_value ? $pickup_display_value : '-' ); ?></span></p>
									<?php if ( $is_cdek && '' !== $cdek_pickup_type_label ) : ?>
										<p><strong><?php echo esc_html__( 'Тип точки', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-cdek-pickup-type-label><?php echo esc_html( $cdek_pickup_type_label ); ?></span></p>
									<?php endif; ?>
									<p><strong><?php echo esc_html( $is_cdek ? __( 'Адрес ПВЗ', 'walls-delivery-calc' ) : __( 'Адрес ПВЗ / ОПС', 'walls-delivery-calc' ) ); ?>:</strong> <span data-wdc-pickup-address><?php echo esc_html( '' !== $pickup_address ? $pickup_address : '-' ); ?></span></p>
									<p><button type="button" class="button" data-wdc-open-pickup-picker><?php echo esc_html__( 'Выбрать другой ПВЗ', 'walls-delivery-calc' ); ?></button></p>
									<?php if ( ! $pickup_point_found ) : ?>
										<p class="description wdc-shipment-warning" data-wdc-pickup-warning><?php echo esc_html( $is_cdek ? __( 'ПВЗ СДЭК не выбран. Создание отправления заблокировано до выбора корректного ПВЗ.', 'walls-delivery-calc' ) : __( 'ПВЗ/ОПС не найден в справочнике Почты России. Создание отправления заблокировано до выбора корректного ПВЗ.', 'walls-delivery-calc' ) ); ?></p>
									<?php endif; ?>
								</div>
								<div data-wdc-courier-section <?php echo DeliveryType::COURIER === $delivery_type ? '' : 'hidden'; ?>>
									<input type="hidden" name="recipient_location_city" value="<?php echo esc_attr( (string) ( $pickup_context['city_name'] ?? $pickup_context['city_value'] ?? $city ) ); ?>">
									<input type="hidden" name="recipient_location_region" value="<?php echo esc_attr( (string) ( $pickup_context['region_name'] ?? $pickup_context['state_value'] ?? $region ) ); ?>">
									<input type="hidden" name="recipient_location_postcode" value="<?php echo esc_attr( (string) ( $pickup_context['postal_code'] ?? $pickup_context['postcode'] ?? $recipient_postcode ) ); ?>">
									<input type="hidden" name="recipient_location_address" value="<?php echo esc_attr( (string) ( $pickup_context['address'] ?? $pickup_context['display_name'] ?? $recipient_address_context ) ); ?>">
									<input type="hidden" name="recipient_location_fias_id" value="<?php echo esc_attr( (string) ( $pickup_context['fias_id'] ?? '' ) ); ?>">
									<input type="hidden" name="recipient_location_gar_id" value="<?php echo esc_attr( (string) ( $pickup_context['gar_id'] ?? '' ) ); ?>">
									<input type="hidden" name="recipient_location_id" value="<?php echo esc_attr( (string) ( $pickup_context['location_id'] ?? '' ) ); ?>">
									<input type="hidden" name="recipient_location_lat" value="<?php echo esc_attr( (string) ( $pickup_context['lat'] ?? '' ) ); ?>">
									<input type="hidden" name="recipient_location_lng" value="<?php echo esc_attr( (string) ( $pickup_context['lng'] ?? '' ) ); ?>">
									<label><?php echo esc_html__( 'Оригинальный адрес покупателя', 'walls-delivery-calc' ); ?><textarea name="courier_original_address" rows="3" data-wdc-courier-original-address><?php echo esc_textarea( $courier_original_address ); ?></textarea></label>
									<button type="button" class="button" data-wdc-normalize-address><?php echo esc_html__( 'Обработать адрес', 'walls-delivery-calc' ); ?></button>
									<input type="hidden" name="normalized_address_json" value="<?php echo esc_attr( $normalized_json ); ?>" data-wdc-normalized-address-json>
									<p class="description" data-wdc-normalized-status><?php echo esc_html( $normalized_status ); ?></p>
									<label><span data-wdc-normalized-address-label><?php echo esc_html( $is_cdek ? __( 'Нормализованный адрес СДЭК', 'walls-delivery-calc' ) : __( 'Нормализованный адрес Почты России', 'walls-delivery-calc' ) ); ?></span><textarea rows="3" readonly data-wdc-normalized-address-display><?php echo esc_textarea( $normalized_display ); ?></textarea></label>
									<p class="description" data-wdc-cdek-city-code-row <?php echo ( $is_cdek && ! empty( $normalized_address['fields']['cdek_city_code'] ) ) ? '' : 'hidden'; ?>><?php echo esc_html__( 'Код города СДЭК', 'walls-delivery-calc' ); ?>: <span data-wdc-cdek-city-code><?php echo esc_html( (string) ( $normalized_address['fields']['cdek_city_code'] ?? '' ) ); ?></span></p>
									<?php if ( $is_cdek ) : ?>
										<label data-wdc-cdek-courier-comment-row <?php echo $cdek_recipient_door ? '' : 'hidden'; ?>><?php echo esc_html__( 'Комментарий курьеру', 'walls-delivery-calc' ); ?><textarea name="cdek_courier_comment" rows="2" maxlength="255"><?php echo esc_textarea( (string) ( $meta['cdek_courier_comment'] ?? '' ) ); ?></textarea><span class="description"><?php echo esc_html__( 'Будет передан в СДЭК как комментарий к заказу. Не более 255 символов.', 'walls-delivery-calc' ); ?></span></label>
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
								<?php if ( $is_cdek ) : ?>
									<p><strong><?php echo esc_html__( 'В заказе тариф', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( '' !== $selected_tariff_title ? $selected_tariff_title : '-' ); ?></p>
									<input type="hidden" name="shipment_point" value="<?php echo esc_attr( $shipment_point ); ?>" data-wdc-sender-shipment-point>
									<input type="hidden" name="sender_shipment_point" value="<?php echo esc_attr( $shipment_point ); ?>">
									<input type="hidden" name="shipment_point_address" value="<?php echo esc_attr( $shipment_point_address ); ?>" data-wdc-sender-shipment-point-address>
									<input type="hidden" name="sender_shipment_point_address" value="<?php echo esc_attr( $shipment_point_address ); ?>">
									<input type="hidden" name="sender_pickup_city" value="Новосибирск" data-wdc-sender-pickup-city>
									<div data-wdc-cdek-sender-door <?php echo $cdek_sender_door ? '' : 'hidden'; ?>>
										<p><strong><?php echo esc_html__( 'Отправитель', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html__( 'от двери', 'walls-delivery-calc' ); ?></p>
										<p><strong><?php echo esc_html__( 'Адрес отправителя', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( '' !== $sender_from_door_display ? $sender_from_door_display : '-' ); ?></p>
									</div>
									<div data-wdc-cdek-sender-warehouse <?php echo $cdek_sender_door ? 'hidden' : ''; ?>>
										<p><strong><?php echo esc_html__( 'ПВЗ отправителя', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-sender-shipment-point-display><?php echo esc_html( '' !== $shipment_point_display ? $shipment_point_display : '-' ); ?></span></p>
										<p><button type="button" class="button" data-wdc-open-sender-pickup-picker><?php echo esc_html__( 'Выбрать другой ПВЗ отправителя', 'walls-delivery-calc' ); ?></button></p>
									</div>
								<?php else : ?>
								<label><?php echo esc_html__( 'Индекс места приема', 'walls-delivery-calc' ); ?><select name="postoffice_code">
									<?php foreach ( $postoffice_codes as $code ) : ?>
										<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( (string) ( $meta['postoffice_code'] ?? '' ), (string) $code ); ?>><?php echo esc_html( (string) $code ); ?></option>
									<?php endforeach; ?>
								</select></label>
								<?php endif; ?>
							</section>
						</div>
						<section>
							<h3><?php echo esc_html__( 'Грузоместа', 'walls-delivery-calc' ); ?></h3>
							<div data-wdc-places>
								<div class="wdc-place-row" data-wdc-place>
									<div class="wdc-place-row__title" data-wdc-place-title><?php echo esc_html__( 'Место 1', 'walls-delivery-calc' ); ?></div>
									<label class="wdc-place-field"><?php echo esc_html__( 'Вес, г', 'walls-delivery-calc' ); ?> <?php if ( '' !== $weight_hint ) : ?><span class="wdc-weight-hint" data-wdc-weight-hint><?php echo esc_html( $weight_hint ); ?></span><?php endif; ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[0][weight_g]" value="" placeholder="<?php echo esc_attr__( 'г', 'walls-delivery-calc' ); ?>"></label>
									<label class="wdc-place-field"><?php echo esc_html__( 'Длина, см', 'walls-delivery-calc' ); ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[0][length_cm]" value="" placeholder="<?php echo esc_attr__( 'см', 'walls-delivery-calc' ); ?>"></label>
									<label class="wdc-place-field"><?php echo esc_html__( 'Ширина, см', 'walls-delivery-calc' ); ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[0][width_cm]" value="" placeholder="<?php echo esc_attr__( 'см', 'walls-delivery-calc' ); ?>"></label>
									<label class="wdc-place-field"><?php echo esc_html__( 'Высота, см', 'walls-delivery-calc' ); ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[0][height_cm]" value="" placeholder="<?php echo esc_attr__( 'см', 'walls-delivery-calc' ); ?>"></label>
									<label class="wdc-place-field" data-wdc-declared-value-field data-default-declared-value-rub="<?php echo esc_attr( $default_declared_value_attr ); ?>" <?php echo $selected_tariff_has_declared_value ? '' : 'hidden'; ?>><?php echo esc_html__( 'Страховка, руб.', 'walls-delivery-calc' ); ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[0][declared_value_rub]" value="<?php echo esc_attr( $declared_value_initial ); ?>" <?php disabled( ! $selected_tariff_has_declared_value ); ?>></label>
									<button type="button" class="button" data-wdc-remove-place disabled><?php echo esc_html__( 'Удалить', 'walls-delivery-calc' ); ?></button>
								</div>
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
								<div class="wdc-cdek-items-summary" data-wdc-shipment-items-summary data-wdc-cdek-items-summary></div>
								<?php $this->render_shipment_item_rows( $request ); ?>
							</section>
						</div>
						<section>
							<h3><?php echo esc_html__( 'Проверка', 'walls-delivery-calc' ); ?></h3>
							<div class="wdc-shipment-errors" data-wdc-shipment-errors></div>
							<pre class="wdc-shipment-preview" data-wdc-shipment-preview><?php echo esc_html( wp_json_encode( $safe_preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ?: '{}' ); ?></pre>
							<button type="button" class="button button-primary" data-wdc-create-shipment><?php echo esc_html__( 'Создать отправление', 'walls-delivery-calc' ); ?></button>
						</section>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	public function ajax_create(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}
		$data = $_POST;
		$prepared = $this->maybe_prepare_cdek_courier_address( $order, $data );
		if ( ! empty( $prepared['error'] ) ) {
			wp_send_json_error( array( 'message' => (string) $prepared['error'] ), 400 );
		}
		$request = $this->drafts->create_request_from_admin_data( $order, $data );
		$preview = $this->creation->safe_preview( $request );
		$result = $this->creation->create( $order, $request );
		if ( ! $result->success ) {
			wp_send_json_error( array( 'message' => $result->error_message, 'code' => $result->error_code, 'preview' => $preview ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => $this->carrier_presentation( $request->carrier_key )['created_toast'],
				'tracking_number' => $result->tracking_number,
				'backlog_order_id' => $result->backlog_order_id,
				'status' => $this->status_payload_for_carrier( $order, $request->carrier_key ),
				'preview' => $preview,
			)
		);
	}

	public function ajax_preview(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}
		$data = $_POST;
		$this->maybe_prepare_cdek_courier_address( $order, $data );
		$request = $this->preview_request( $this->drafts->create_request_from_admin_data( $order, $data ) );
		$preview = $this->creation->safe_preview( $request );

		wp_send_json_success( array( 'preview' => $preview ) );
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
		$result = CdekSettings::CARRIER_KEY === $shipment_key && $this->cdek_status_updates instanceof CdekOrderStatusService
			? $this->cdek_status_updates->update( $order )
			: $this->status_updates->update_russian_post( $order, $shipment_key );
		if ( ! (bool) ( $result['success'] ?? false ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Не удалось получить статус отправления.', 'walls-delivery-calc' ) ) ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => (string) ( $result['message'] ?? __( 'Статус отправления обновлен.', 'walls-delivery-calc' ) ),
				'status' => $this->with_status_presentation( is_array( $result['status'] ?? null ) ? $result['status'] : array(), $shipment_key ),
			)
		);
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
		if ( CdekSettings::CARRIER_KEY === $shipment_key && $this->cdek_status_updates instanceof CdekOrderStatusService ) {
			$result = $this->cdek_status_updates->cancel_created_order( $order );
		} else {
			if ( ! $this->backlog instanceof ShipmentBacklogService ) {
				wp_send_json_error( array( 'message' => __( 'Отмена отправлений недоступна.', 'walls-delivery-calc' ) ), 500 );
			}
			$result = $this->backlog->cancel_russian_post( $order, $shipment_key );
		}
		if ( ! (bool) ( $result['success'] ?? false ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Не удалось отменить отправление.', 'walls-delivery-calc' ) ) ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => (string) ( $result['message'] ?? __( 'Отправление отменено.', 'walls-delivery-calc' ) ),
				'status' => $this->with_status_presentation( is_array( $result['status'] ?? null ) ? $result['status'] : array(), $shipment_key ),
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
		if ( CdekSettings::CARRIER_KEY === $shipment_key && $this->cdek_status_updates instanceof CdekOrderStatusService ) {
			$result = $this->cdek_status_updates->remove_local_if_allowed( $order );
		} else {
			if ( ! $this->backlog instanceof ShipmentBacklogService ) {
				wp_send_json_error( array( 'message' => __( 'Не удалось удалить данные отправления.', 'walls-delivery-calc' ) ), 500 );
			}
			$result = $this->backlog->remove_from_order( $order, $shipment_key );
		}
		if ( ! (bool) ( $result['success'] ?? false ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Не удалось удалить данные отправления.', 'walls-delivery-calc' ) ) ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => (string) ( $result['message'] ?? __( 'Данные отправления удалены из заказа.', 'walls-delivery-calc' ) ),
				'status' => $this->with_status_presentation( is_array( $result['status'] ?? null ) ? $result['status'] : array(), $shipment_key ),
			)
		);
	}

	public function ajax_attach_tracking(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) || $order_id <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}
		$shipment_key = sanitize_key( wp_unslash( $_POST['shipment_key'] ?? RussianPostDomesticSettings::CARRIER_KEY ) );
		$barcode = sanitize_text_field( wp_unslash( $_POST['barcode'] ?? '' ) );
		if ( CdekSettings::CARRIER_KEY === $shipment_key && $this->cdek_status_updates instanceof CdekOrderStatusService ) {
			$result = $this->cdek_status_updates->attach_by_cdek_number( $order, $barcode );
		} else {
			if ( ! $this->backlog instanceof ShipmentBacklogService ) {
				wp_send_json_error( array( 'message' => __( 'Ручное внесение ШПИ недоступно.', 'walls-delivery-calc' ) ), 500 );
			}
			$result = $this->backlog->attach_tracking_number( $order, $barcode, $shipment_key );
		}
		if ( ! (bool) ( $result['success'] ?? false ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Не удалось сохранить номер отслеживания.', 'walls-delivery-calc' ) ) ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => (string) ( $result['message'] ?? __( 'Номер отслеживания сохранен.', 'walls-delivery-calc' ) ),
				'warning' => (string) ( $result['warning'] ?? '' ),
				'tracking_number' => (string) ( $result['tracking_number'] ?? '' ),
				'backlog_order_id' => (string) ( $result['backlog_order_id'] ?? '' ),
				'status' => $this->with_status_presentation( is_array( $result['status'] ?? null ) ? $result['status'] : array(), $shipment_key ),
			)
		);
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
		if ( CdekSettings::CARRIER_KEY === $carrier_key && DeliveryType::COURIER === $delivery_type ) {
			if ( ! $this->cdek_address_preparation instanceof CdekRecipientAddressPreparationService ) {
				wp_send_json_error( array( 'message' => __( 'Нормализация адреса СДЭК недоступна.', 'walls-delivery-calc' ) ), 500 );
			}
			$result = $this->cdek_address_preparation->prepare( $order, $original_address, $this->recipient_location_context_from_request( $order ), $service_key ?: CdekSettings::SERVICE_KEY );
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
	 * @param array<string,mixed> $pickup_row
	 */
	private function cdek_pickup_type_label( array $pickup_row ): string {
		$type = strtoupper(
			trim(
				(string) (
					$pickup_row['point_type']
					?? $pickup_row['cdek_type']
					?? $pickup_row['marker_type']
					?? ''
				)
			)
		);
		$type = str_replace( '_', '-', $type );
		if ( in_array( $type, array( 'POSTAMAT', 'POSTOMAT', 'LOCKER' ), true ) || 'POSTAMAT' === strtoupper( (string) ( $pickup_row['marker_type'] ?? '' ) ) || 'POSTAMAT' === strtoupper( (string) ( $pickup_row['point_title'] ?? '' ) ) ) {
			return __( 'Постамат СДЭК', 'walls-delivery-calc' );
		}
		if ( 'PVZ' === $type || 'PICKUP' === $type ) {
			return __( 'ПВЗ СДЭК', 'walls-delivery-calc' );
		}
		$title = trim( (string) ( $pickup_row['point_title'] ?? $pickup_row['display_title'] ?? $pickup_row['point_type_label'] ?? '' ) );
		if ( str_contains( function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title ), 'постамат' ) ) {
			return __( 'Постамат СДЭК', 'walls-delivery-calc' );
		}
		if ( str_contains( function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title ), 'пвз' ) || str_contains( function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title ), 'пункт выдачи' ) ) {
			return __( 'ПВЗ СДЭК', 'walls-delivery-calc' );
		}

		return '';
	}

	public function ajax_search_pickup_points(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}

		$query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
		$mode = 'location' === sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) ) ? 'location' : 'search';
		$limit = max( 1, min( 'location' === $mode ? 2000 : 100, (int) ( $_POST['limit'] ?? ( 'location' === $mode ? 2000 : 50 ) ) ) );
		$carrier_key = sanitize_key( wp_unslash( $_POST['carrier_key'] ?? '' ) );
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
		<table class="widefat striped wdc-cdek-items-table" data-wdc-shipment-items-table data-wdc-cdek-items-table>
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
				<tr data-wdc-shipment-item-row data-wdc-cdek-item-row data-wdc-base-row="1" data-wdc-original-item="<?php echo esc_attr( $original_json ); ?>" data-item-key="<?php echo esc_attr( $row_key ); ?>" data-group-key="<?php echo esc_attr( $row_key ); ?>" data-ordered-quantity="<?php echo esc_attr( (string) $quantity ); ?>" data-wdc-row-index="<?php echo esc_attr( (string) $index ); ?>">
					<td class="wdc-cdek-item-product"><?php echo esc_html( (string) ( $item['name'] ?? 'Товар' ) ); ?><input type="hidden" name="cdek_items[<?php echo esc_attr( (string) $index ); ?>][name]" value="<?php echo esc_attr( (string) ( $item['name'] ?? 'Товар' ) ); ?>"><input type="hidden" name="cdek_items[<?php echo esc_attr( (string) $index ); ?>][item_key]" value="<?php echo esc_attr( $row_key ); ?>"><input type="hidden" name="cdek_items[<?php echo esc_attr( (string) $index ); ?>][ordered_quantity]" value="<?php echo esc_attr( (string) $quantity ); ?>"></td>
					<td class="wdc-cdek-item-sku"><?php echo esc_html( $sku ); ?><input type="hidden" name="cdek_items[<?php echo esc_attr( (string) $index ); ?>][ware_key]" value="<?php echo esc_attr( $sku ); ?>"></td>
					<td><input class="wdc-cdek-input-qty" type="number" min="1" max="<?php echo esc_attr( (string) min( 999, $quantity ) ); ?>" step="1" name="cdek_items[<?php echo esc_attr( (string) $index ); ?>][amount]" value="<?php echo esc_attr( (string) min( 999, $quantity ) ); ?>" data-wdc-shipment-item-qty data-wdc-cdek-qty data-wdc-integer-input></td>
					<td><input class="wdc-cdek-input-price" type="text" inputmode="decimal" autocomplete="off" name="cdek_items[<?php echo esc_attr( (string) $index ); ?>][cost]" value="<?php echo esc_attr( (string) $unit ); ?>" data-wdc-decimal-input="2"></td>
					<td><input class="wdc-cdek-input-weight" type="number" min="1" step="1" name="cdek_items[<?php echo esc_attr( (string) $index ); ?>][weight]" value="<?php echo esc_attr( (string) max( 1, (int) ( $item['weight_g'] ?? 0 ) ) ); ?>" data-wdc-integer-input></td>
					<td><input class="wdc-cdek-input-dim" type="text" inputmode="decimal" autocomplete="off" name="cdek_items[<?php echo esc_attr( (string) $index ); ?>][length_cm]" value="<?php echo esc_attr( (string) max( 0.1, (float) ( $item['length_cm'] ?? 0.1 ) ) ); ?>" data-wdc-decimal-input="1"></td>
					<td><input class="wdc-cdek-input-dim" type="text" inputmode="decimal" autocomplete="off" name="cdek_items[<?php echo esc_attr( (string) $index ); ?>][width_cm]" value="<?php echo esc_attr( (string) max( 0.1, (float) ( $item['width_cm'] ?? 0.1 ) ) ); ?>" data-wdc-decimal-input="1"></td>
					<td><input class="wdc-cdek-input-dim" type="text" inputmode="decimal" autocomplete="off" name="cdek_items[<?php echo esc_attr( (string) $index ); ?>][height_cm]" value="<?php echo esc_attr( (string) max( 0.1, (float) ( $item['height_cm'] ?? 0.1 ) ) ); ?>" data-wdc-decimal-input="1"></td>
					<td><select name="cdek_items[<?php echo esc_attr( (string) $index ); ?>][place_number]" data-wdc-shipment-place-select data-wdc-cdek-place-select><option value="1">1</option></select></td>
					<td class="wdc-cdek-item-actions" data-wdc-shipment-item-actions><?php if ( $quantity > 1 ) : ?><button type="button" class="wdc-icon-action wdc-icon-action--split" data-wdc-shipment-item-split data-wdc-cdek-split title="<?php echo esc_attr__( 'Разбить товар по грузоместам', 'walls-delivery-calc' ); ?>" aria-label="<?php echo esc_attr__( 'Разбить товар по грузоместам', 'walls-delivery-calc' ); ?>">🔀</button><?php endif; ?></td>
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

	/**
	 * @param array<string,mixed> $status
	 */
	private function render_status_block( array $status ): void {
		?>
		<div class="wdc-shipment-status" data-wdc-shipment-status-block>
			<p><strong><?php echo esc_html( $this->status_block_label( (string) ( $status['carrier_key'] ?? '' ) ) ); ?>:</strong> <span data-wdc-status-carrier><?php echo esc_html( (string) ( $status['carrier_status_title'] ?? '' ) ?: '-' ); ?></span></p>
			<p><strong><?php echo esc_html__( 'Последняя операция', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-status-operation><?php echo esc_html( $this->operation_summary( $status ) ); ?></span></p>
			<p data-wdc-planned-delivery-row <?php echo '' === (string) ( $status['cdek_planned_delivery_date'] ?? '' ) ? 'hidden' : ''; ?>><strong><?php echo esc_html__( 'Плановая дата доставки', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-planned-delivery-date><?php echo esc_html( (string) ( $status['cdek_planned_delivery_date'] ?? '' ) ); ?></span></p>
			<p><strong><?php echo esc_html__( 'Проверено', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-status-checked><?php echo esc_html( (string) ( $status['tracking_checked_at'] ?? '' ) ?: '-' ); ?></span></p>
			<div class="wdc-cdek-polling-indicator" data-wdc-cdek-polling-indicator hidden><span class="wdc-spinner" aria-hidden="true"></span><span><?php echo esc_html__( 'Проверяем регистрацию отправления…', 'walls-delivery-calc' ); ?></span></div>
		</div>
		<?php
	}

	/**
	 * @return array<string,mixed>
	 */
	private function status_payload_for_carrier( object $order, string $carrier_key ): array {
		$shipment = $this->repository->find_by_carrier( $order, $carrier_key );
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
			'manual_attach_placeholder' => __( 'Номер отслеживания', 'walls-delivery-calc' ),
			'manual_attach_help' => __( 'Введите номер отслеживания для поиска и привязки отправления.', 'walls-delivery-calc' ),
			'created_toast' => __( 'Отправление создано.', 'walls-delivery-calc' ),
			'updated_toast' => __( 'Статус отправления обновлен.', 'walls-delivery-calc' ),
			'cancel_success_toast' => __( 'Отправление отменено.', 'walls-delivery-calc' ),
			'remove_success_toast' => __( 'Данные отправления удалены из заказа.', 'walls-delivery-calc' ),
			'error_fallback_message' => __( 'Не удалось получить статус отправления.', 'walls-delivery-calc' ),
			'polling_timeout_message' => __( 'Автоматическая проверка завершена. Если статус еще не обновился, воспользуйтесь кнопкой «Обновить статус».', 'walls-delivery-calc' ),
			'registration_error_toast' => __( 'Регистрация завершилась ошибкой.', 'walls-delivery-calc' ),
			'registration_success_toast' => __( 'Регистрация завершена успешно.', 'walls-delivery-calc' ),
			'auto_poll_registration' => '0',
		);

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
				(string) ( $status['carrier_operation_address'] ?? '' ),
				(string) ( $status['carrier_operation_index'] ?? '' ),
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

	/**
	 * @param array<string,mixed> $meta
	 */
	private function pickup_destination_index( string $pickup_code, string $postcode, array $meta ): string {
		$explicit = preg_replace( '/\D+/', '', (string) ( $meta['pickup_point_postcode'] ?? $meta['pickup_postcode'] ?? '' ) ) ?? '';
		if ( 1 === preg_match( '/^\d{6}$/', $explicit ) ) {
			return $explicit;
		}
		if ( 1 === preg_match( '/^(\d{6})/', trim( $pickup_code ), $matches ) ) {
			return (string) $matches[1];
		}
		$postcode = preg_replace( '/\D+/', '', $postcode ) ?? '';

		return 1 === preg_match( '/^\d{6}$/', $postcode ) ? $postcode : '';
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
}
