<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\RussianPost\RussianPostAddressNormalizer;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OrderShipmentsMetabox {
	private const NONCE_ACTION = 'wdc_shipments_admin';
	private const AJAX_CREATE = 'wdc_create_shipment';
	private const AJAX_PREVIEW = 'wdc_preview_shipment';
	private const AJAX_UPDATE_STATUS = 'wdc_update_shipment_status';
	private const AJAX_NORMALIZE_ADDRESS = 'wdc_normalize_shipment_address';
	private const AJAX_SEARCH_PICKUP_POINTS = 'wdc_search_russian_post_pickup_points';

	public function __construct(
		private OrderShipmentRepository $repository,
		private OrderShipmentDraftFactory $drafts,
		private ShipmentCreationService $creation,
		private DeliveryServiceRepository $services,
		private ShipmentStatusUpdateService $status_updates,
		private ?RussianPostAddressNormalizer $address_normalizer = null,
		private ?RussianPostPickupPointTypeSettings $pickup_point_type_settings = null,
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
		add_action( 'wp_ajax_' . self::AJAX_NORMALIZE_ADDRESS, array( $this, 'ajax_normalize_address' ) );
		add_action( 'wp_ajax_' . self::AJAX_SEARCH_PICKUP_POINTS, array( $this, 'ajax_search_pickup_points' ) );
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
		wp_enqueue_script( 'wdc-shipments-admin', $this->plugin_url . 'assets/admin/shipments-admin.js', array( $provider_handle ), $this->version, true );
		wp_localize_script(
			'wdc-shipments-admin',
			'wdcShipmentsAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::NONCE_ACTION ),
				'createAction' => self::AJAX_CREATE,
				'previewAction' => self::AJAX_PREVIEW,
				'updateStatusAction' => self::AJAX_UPDATE_STATUS,
				'normalizeAddressAction' => self::AJAX_NORMALIZE_ADDRESS,
				'searchPickupPointsAction' => self::AJAX_SEARCH_PICKUP_POINTS,
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
		$shipment = $this->repository->find_by_carrier( $order, RussianPostDomesticSettings::CARRIER_KEY );
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
		$settings = is_array( $request['services'] ?? null ) ? $request['services'] : array();
		$pickup_code = (string) ( $request['pickup_point']['point_code'] ?? $meta['pickup_point_code'] ?? '' );
		$pickup_destination_index = $this->pickup_destination_index( $pickup_code, (string) ( $address['postcode'] ?? '' ), $meta );
		$region = (string) ( $address['region_name'] ?? '' );
		$city = (string) ( $address['settlement'] ?? $address['city'] ?? '' );
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
		$has_selected_service_tariffs = array() !== $selected_service_tariffs;
		$tariff_message_hidden_attr = $has_selected_service_tariffs ? ' hidden' : '';
		$calculated_weight_g = max( 0, (int) ( $place['weight_g'] ?? 0 ) );
		$weight_label = $calculated_weight_g > 0 ? sprintf( __( 'Вес, г (%d)', 'walls-delivery-calc' ), $calculated_weight_g ) : __( 'Вес, г', 'walls-delivery-calc' );
		$default_declared_value_rub = max( 0, (int) ( $meta['default_declared_value_rub'] ?? 0 ) );
		$default_declared_value_attr = $default_declared_value_rub > 0 ? (string) $default_declared_value_rub : '';
		$declared_value_initial = $selected_tariff_has_declared_value ? $default_declared_value_attr : '';
		$delivery_type = $selected_delivery_type;
		$pickup_point_found = ! empty( $meta['pickup_point_found'] );
		$pickup_address = (string) ( $address['raw_address'] ?? '' );
		$courier_original_address = (string) ( $meta['courier_original_address'] ?? '' );
		$normalized_address = is_array( $meta['normalized_address'] ?? null ) ? $meta['normalized_address'] : array();
		$normalized_display = (string) ( $normalized_address['display'] ?? '' );
		$normalized_status = array() !== $normalized_address
			? ( ! empty( $normalized_address['success'] ) ? 'Адрес обработан Почтой России.' : 'Адрес не подтвержден Почтой России, создание отправления заблокировано.' )
			: 'Адрес нужно обработать перед созданием отправления.';
		$normalized_json = wp_json_encode( $normalized_address, JSON_UNESCAPED_UNICODE ) ?: '';
		$has_created = in_array( (string) ( $shipment['status'] ?? '' ), array( 'created', 'registered' ), true );
		$barcode = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
		$status_payload = $this->status_updates->status_payload( $shipment );
		?>
		<div class="wdc-shipments-metabox" data-wdc-shipments-metabox>
			<p><strong><?php echo esc_html__( 'Служба', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( (string) ( $meta['service_title'] ?? $request['rate_id'] ?? '-' ) ); ?></p>
			<p><strong><?php echo esc_html__( 'Статус WDC', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-shipment-summary-status><?php echo esc_html( $this->shipment_status_label( $shipment ) ); ?></span></p>
			<?php if ( '' !== $barcode ) : ?><p><strong>Barcode:</strong> <?php echo esc_html( $barcode ); ?></p><?php endif; ?>
			<?php if ( '' !== (string) ( $shipment['updated_at'] ?? '' ) ) : ?><p><strong><?php echo esc_html__( 'Обновлено', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( (string) $shipment['updated_at'] ); ?></p><?php endif; ?>
			<?php $this->render_status_block( $status_payload ); ?>
			<?php if ( array() !== $error && ! $has_created ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( (string) ( $error['error_message'] ?? '' ) ); ?></p></div><?php endif; ?>
			<div class="wdc-shipment-status-message" data-wdc-shipment-status-message></div>
			<p class="wdc-shipments-actions">
				<button type="button" class="button button-primary" data-wdc-open-shipment-modal <?php disabled( $has_created ); ?>><?php echo esc_html__( 'Подготовить отправление', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button" data-wdc-update-shipment-status data-order-id="<?php echo esc_attr( (string) $order_id ); ?>" data-shipment-key="<?php echo esc_attr( RussianPostDomesticSettings::CARRIER_KEY ); ?>" <?php disabled( ! $has_created || '' === $barcode ); ?>><?php echo esc_html__( 'Обновить статус', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button" disabled><?php echo esc_html__( 'Скачать документы', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button" disabled><?php echo esc_html__( 'Отменить отправление', 'walls-delivery-calc' ); ?></button>
			</p>
			<div class="wdc-shipment-modal" data-wdc-shipment-modal hidden>
				<div class="wdc-shipment-modal__dialog" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr__( 'Подготовка отправления', 'walls-delivery-calc' ); ?>">
					<button type="button" class="wdc-shipment-modal__close" data-wdc-close-shipment-modal aria-label="<?php echo esc_attr__( 'Закрыть', 'walls-delivery-calc' ); ?>">×</button>
					<h2><?php echo esc_html__( 'Подготовка отправления', 'walls-delivery-calc' ); ?></h2>
					<div id="wdc-shipment-form-<?php echo esc_attr( (string) $order_id ); ?>" class="wdc-shipment-form" data-wdc-shipment-form="1" role="group">
						<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>">
						<div class="wdc-shipment-grid">
							<section>
								<h3><?php echo esc_html__( 'Получатель', 'walls-delivery-calc' ); ?></h3>
								<label><?php echo esc_html__( 'ФИО', 'walls-delivery-calc' ); ?><input name="recipient_name" value="<?php echo esc_attr( (string) ( $recipient['name'] ?? '' ) ); ?>"></label>
								<label><?php echo esc_html__( 'Телефон', 'walls-delivery-calc' ); ?><input name="recipient_phone" value="<?php echo esc_attr( (string) ( $recipient['phone'] ?? '' ) ); ?>"></label>
								<label>Email<input name="recipient_email" value="<?php echo esc_attr( (string) ( $recipient['email'] ?? '' ) ); ?>"></label>
								<div data-wdc-pickup-section <?php echo DeliveryType::PICKUP === $delivery_type ? '' : 'hidden'; ?>>
									<input type="hidden" name="pickup_point_code" value="<?php echo esc_attr( $pickup_code ); ?>">
									<input type="hidden" name="pickup_point_postcode" value="<?php echo esc_attr( $pickup_destination_index ); ?>" data-wdc-pickup-postcode-field>
									<input type="hidden" name="pickup_point_address" value="<?php echo esc_attr( $pickup_address ); ?>" data-wdc-pickup-address-field>
									<input type="hidden" name="pickup_point_city" value="<?php echo esc_attr( $city ); ?>" data-wdc-pickup-city-field>
									<input type="hidden" name="pickup_point_region" value="<?php echo esc_attr( $region ); ?>" data-wdc-pickup-region-field>
									<input type="hidden" name="pickup_point_lat" value="" data-wdc-pickup-lat-field>
									<input type="hidden" name="pickup_point_lng" value="" data-wdc-pickup-lng-field>
									<p><strong><?php echo esc_html__( 'Индекс выбранного ПВЗ / ОПС', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-pickup-index><?php echo esc_html( $pickup_destination_index ); ?></span></p>
									<p><strong><?php echo esc_html__( 'Адрес ПВЗ / ОПС', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-pickup-address><?php echo esc_html( '' !== $pickup_address ? $pickup_address : '-' ); ?></span></p>
									<p><button type="button" class="button" data-wdc-open-pickup-picker><?php echo esc_html__( 'Выбрать другой ПВЗ', 'walls-delivery-calc' ); ?></button></p>
									<?php if ( ! $pickup_point_found ) : ?>
										<p class="description wdc-shipment-warning" data-wdc-pickup-warning><?php echo esc_html__( 'ПВЗ/ОПС не найден в справочнике Почты России. Создание отправления заблокировано до выбора корректного ПВЗ.', 'walls-delivery-calc' ); ?></p>
									<?php endif; ?>
								</div>
								<div data-wdc-courier-section <?php echo DeliveryType::COURIER === $delivery_type ? '' : 'hidden'; ?>>
									<label><?php echo esc_html__( 'Оригинальный адрес покупателя', 'walls-delivery-calc' ); ?><textarea name="courier_original_address" rows="3" data-wdc-courier-original-address><?php echo esc_textarea( $courier_original_address ); ?></textarea></label>
									<button type="button" class="button" data-wdc-normalize-address><?php echo esc_html__( 'Обработать адрес', 'walls-delivery-calc' ); ?></button>
									<input type="hidden" name="normalized_address_json" value="<?php echo esc_attr( $normalized_json ); ?>" data-wdc-normalized-address-json>
									<p class="description" data-wdc-normalized-status><?php echo esc_html( $normalized_status ); ?></p>
									<label><?php echo esc_html__( 'Нормализованный адрес Почты России', 'walls-delivery-calc' ); ?><textarea rows="3" readonly data-wdc-normalized-address-display><?php echo esc_textarea( $normalized_display ); ?></textarea></label>
								</div>
							</section>
							<section>
								<h3><?php echo esc_html__( 'Доставка', 'walls-delivery-calc' ); ?></h3>
								<input type="hidden" name="service_key" value="<?php echo esc_attr( RussianPostDomesticSettings::SERVICE_KEY ); ?>">
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
										<option value="<?php echo esc_attr( $tariff_object ); ?>" <?php selected( $selected_tariff_object, $tariff_object ); ?>><?php echo esc_html( (string) ( $tariff['title'] ?? $tariff_object ) ); ?></option>
									<?php endforeach; ?>
								</select></label>
								<p class="description" data-wdc-tariff-message<?php echo $tariff_message_hidden_attr; ?>><?php echo esc_html__( 'Для выбранной службы доставки нет включенных тарифов. Включите тариф на странице настроек службы доставки.', 'walls-delivery-calc' ); ?></p>
								<label><?php echo esc_html__( 'Индекс места приема', 'walls-delivery-calc' ); ?><select name="postoffice_code">
									<?php foreach ( $postoffice_codes as $code ) : ?>
										<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( (string) ( $meta['postoffice_code'] ?? '' ), (string) $code ); ?>><?php echo esc_html( (string) $code ); ?></option>
									<?php endforeach; ?>
								</select></label>
							</section>
						</div>
						<section>
							<h3><?php echo esc_html__( 'Грузоместа', 'walls-delivery-calc' ); ?></h3>
							<div data-wdc-places>
								<div class="wdc-place-row" data-wdc-place>
									<div class="wdc-place-row__title" data-wdc-place-title><?php echo esc_html__( 'Место 1', 'walls-delivery-calc' ); ?></div>
									<label class="wdc-place-field"><?php echo esc_html( $weight_label ); ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[0][weight_g]" value="" placeholder="<?php echo esc_attr__( 'г', 'walls-delivery-calc' ); ?>"></label>
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
		$request = $this->drafts->create_request_from_admin_data( $order, $_POST );
		$preview = $this->creation->safe_preview( $request );
		$result = $this->creation->create( $order, $request );
		if ( ! $result->success ) {
			wp_send_json_error( array( 'message' => $result->error_message, 'code' => $result->error_code, 'preview' => $preview ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Отправление создано.', 'walls-delivery-calc' ),
				'tracking_number' => $result->tracking_number,
				'status' => $this->status_updates->status_payload( $this->repository->find_by_carrier( $order, RussianPostDomesticSettings::CARRIER_KEY ) ),
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
		$request = $this->preview_request( $this->drafts->create_request_from_admin_data( $order, $_POST ) );
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
		$result = $this->status_updates->update_russian_post( $order, $shipment_key );
		if ( ! (bool) ( $result['success'] ?? false ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Не удалось получить статус Почты России.', 'walls-delivery-calc' ) ) ), 400 );
		}

		wp_send_json_success(
			array(
				'message' => (string) ( $result['message'] ?? __( 'Статус отправления обновлен.', 'walls-delivery-calc' ) ),
				'status' => is_array( $result['status'] ?? null ) ? $result['status'] : array(),
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
		if ( ! $this->address_normalizer instanceof RussianPostAddressNormalizer ) {
			wp_send_json_error( array( 'message' => __( 'Нормализация адреса недоступна.', 'walls-delivery-calc' ) ), 500 );
		}

		$original_address = sanitize_text_field( wp_unslash( $_POST['courier_original_address'] ?? $_POST['original_address'] ?? '' ) );
		$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
		$result = $this->address_normalizer->normalize( $order_id, $original_address );
		$result['order_id'] = $order_id;
		$result['service_key'] = $service_key;

		if ( method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'save' ) ) {
			$order->update_meta_data( '_wdc_shipment_rp_clean_address', $result );
			$order->save();
		}

		wp_send_json_success( array( 'normalized_address' => $result ) );
	}

	public function ajax_search_pickup_points(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}

		$query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
		$limit = max( 1, min( 100, (int) ( $_POST['limit'] ?? 50 ) ) );
		$rows = ( new RussianPostPickupPointRepository() )->search_admin_pickup_rows( $query, array( 'limit' => $limit ) );

		wp_send_json_success(
			array(
				'points' => array_map( array( $this, 'pickup_point_ajax_row' ), $rows ),
			)
		);
	}

	private function resolve_order( mixed $post_or_order ): ?object {
		if ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' ) && method_exists( $post_or_order, 'get_meta' ) ) {
			return $post_or_order;
		}
		$order_id = is_object( $post_or_order ) && isset( $post_or_order->ID ) ? (int) $post_or_order->ID : 0;

		return $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
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
	 * @param array<string,mixed> $status
	 */
	private function render_status_block( array $status ): void {
		?>
		<div class="wdc-shipment-status" data-wdc-shipment-status-block>
			<p><strong><?php echo esc_html__( 'Статус в плагине', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-status-plugin><?php echo esc_html( (string) ( $status['universal_status_label'] ?? '' ) ?: 'не определён' ); ?></span></p>
			<p><strong><?php echo esc_html__( 'Статус Почты России', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-status-carrier><?php echo esc_html( (string) ( $status['carrier_status_title'] ?? '' ) ?: '-' ); ?></span></p>
			<p><strong><?php echo esc_html__( 'Последняя операция', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-status-operation><?php echo esc_html( $this->operation_summary( $status ) ); ?></span></p>
			<p><strong><?php echo esc_html__( 'Проверено', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-status-checked><?php echo esc_html( (string) ( $status['tracking_checked_at'] ?? '' ) ?: '-' ); ?></span></p>
			<p><strong>Barcode:</strong> <span data-wdc-status-barcode><?php echo esc_html( (string) ( $status['barcode'] ?? '' ) ?: '-' ); ?></span></p>
		</div>
		<?php
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
