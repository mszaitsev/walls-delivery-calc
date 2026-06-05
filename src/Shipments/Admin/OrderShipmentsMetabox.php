<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\RussianPost\RussianPostAddressNormalizer;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OrderShipmentsMetabox {
	private const NONCE_ACTION = 'wdc_shipments_admin';
	private const AJAX_CREATE = 'wdc_create_shipment';
	private const AJAX_PREVIEW = 'wdc_preview_shipment';
	private const AJAX_NORMALIZE_ADDRESS = 'wdc_normalize_shipment_address';

	public function __construct(
		private OrderShipmentRepository $repository,
		private OrderShipmentDraftFactory $drafts,
		private ShipmentCreationService $creation,
		private DeliveryServiceRepository $services,
		private ?RussianPostAddressNormalizer $address_normalizer = null,
		private string $plugin_url = '',
		private string $version = '1'
	) {
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_CREATE, array( $this, 'ajax_create' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_' . self::AJAX_NORMALIZE_ADDRESS, array( $this, 'ajax_normalize_address' ) );
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
		wp_enqueue_style( 'wdc-shipments-admin', $this->plugin_url . 'assets/admin/shipments-admin.css', array(), $this->version );
		wp_enqueue_script( 'wdc-shipments-admin', $this->plugin_url . 'assets/admin/shipments-admin.js', array(), $this->version, true );
		wp_localize_script(
			'wdc-shipments-admin',
			'wdcShipmentsAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( self::NONCE_ACTION ),
				'createAction' => self::AJAX_CREATE,
				'previewAction' => self::AJAX_PREVIEW,
				'normalizeAddressAction' => self::AJAX_NORMALIZE_ADDRESS,
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
		$selected_service_key = (string) ( $request['rate_id'] ?? $meta['service_key'] ?? '' );
		if ( '' === $selected_service_key && array() !== $services ) {
			$selected_service_key = (string) ( $services[0]['service_key'] ?? '' );
		}
		$selected_tariff_object = (string) ( $meta['tariff_object'] ?? '' );
		$selected_service_tariffs = array();
		foreach ( $services as $service ) {
			if ( $selected_service_key === (string) ( $service['service_key'] ?? '' ) ) {
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
		$calculated_weight_placeholder = (string) max( 1, (int) ( $place['weight_g'] ?? 1000 ) );
		$delivery_type = (string) ( $request['delivery_type'] ?? DeliveryType::PICKUP );
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
		?>
		<div class="wdc-shipments-metabox" data-wdc-shipments-metabox>
			<p><strong><?php echo esc_html__( 'Служба', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( (string) ( $meta['service_title'] ?? $request['rate_id'] ?? '-' ) ); ?></p>
			<p><strong><?php echo esc_html__( 'Статус WDC', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( (string) ( $shipment['status'] ?? __( 'не создано', 'walls-delivery-calc' ) ) ); ?></p>
			<?php if ( '' !== (string) ( $shipment['tracking_number'] ?? '' ) ) : ?><p><strong>Barcode:</strong> <?php echo esc_html( (string) $shipment['tracking_number'] ); ?></p><?php endif; ?>
			<?php if ( '' !== (string) ( $shipment['external_id'] ?? '' ) ) : ?><p><strong>Result ID:</strong> <?php echo esc_html( (string) $shipment['external_id'] ); ?></p><?php endif; ?>
			<?php if ( '' !== (string) ( $shipment['updated_at'] ?? '' ) ) : ?><p><strong><?php echo esc_html__( 'Обновлено', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( (string) $shipment['updated_at'] ); ?></p><?php endif; ?>
			<?php if ( array() !== $error && ! $has_created ) : ?><div class="notice notice-error inline"><p><?php echo esc_html( (string) ( $error['error_message'] ?? '' ) ); ?></p></div><?php endif; ?>
			<p class="wdc-shipments-actions">
				<button type="button" class="button button-primary" data-wdc-open-shipment-modal <?php disabled( $has_created ); ?>><?php echo esc_html__( 'Подготовить отправление', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button" disabled><?php echo esc_html__( 'Обновить статус', 'walls-delivery-calc' ); ?></button>
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
									<p><strong><?php echo esc_html__( 'Индекс выбранного ПВЗ / ОПС', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-pickup-index><?php echo esc_html( $pickup_destination_index ); ?></span></p>
									<p><strong><?php echo esc_html__( 'Адрес ПВЗ / ОПС', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-pickup-address><?php echo esc_html( '' !== $pickup_address ? $pickup_address : '-' ); ?></span></p>
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
								<label><?php echo esc_html__( 'Служба доставки', 'walls-delivery-calc' ); ?><select name="service_key" data-wdc-service-select>
									<?php foreach ( $services as $service ) : ?>
										<option value="<?php echo esc_attr( (string) $service['service_key'] ); ?>" data-delivery-type="<?php echo esc_attr( (string) $service['delivery_type'] ); ?>" data-tariffs="<?php echo esc_attr( wp_json_encode( $service['tariffs'] ?? array(), JSON_UNESCAPED_UNICODE ) ?: '[]' ); ?>" <?php selected( $selected_service_key, (string) $service['service_key'] ); ?>><?php echo esc_html( (string) $service['title'] ); ?></option>
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
									<label class="wdc-place-field"><?php echo esc_html__( 'Вес, г', 'walls-delivery-calc' ); ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[0][weight_g]" value="" placeholder="<?php echo esc_attr( $calculated_weight_placeholder ); ?>"></label>
									<label class="wdc-place-field"><?php echo esc_html__( 'Длина, см', 'walls-delivery-calc' ); ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[0][length_cm]" value="" placeholder="<?php echo esc_attr__( 'см', 'walls-delivery-calc' ); ?>"></label>
									<label class="wdc-place-field"><?php echo esc_html__( 'Ширина, см', 'walls-delivery-calc' ); ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[0][width_cm]" value="" placeholder="<?php echo esc_attr__( 'см', 'walls-delivery-calc' ); ?>"></label>
									<label class="wdc-place-field"><?php echo esc_html__( 'Высота, см', 'walls-delivery-calc' ); ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[0][height_cm]" value="" placeholder="<?php echo esc_attr__( 'см', 'walls-delivery-calc' ); ?>"></label>
									<label class="wdc-place-field" data-wdc-declared-value-field <?php echo $selected_tariff_has_declared_value ? '' : 'hidden'; ?>><?php echo esc_html__( 'Страховка, руб.', 'walls-delivery-calc' ); ?><input type="text" inputmode="numeric" pattern="[0-9]*" autocomplete="off" data-wdc-integer-input name="places[0][declared_value_rub]" value=""></label>
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
				'external_id' => $result->external_id,
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
}
