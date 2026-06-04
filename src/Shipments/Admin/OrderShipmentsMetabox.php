<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OrderShipmentsMetabox {
	private const NONCE_ACTION = 'wdc_shipments_admin';
	private const AJAX_CREATE = 'wdc_create_shipment';

	public function __construct(
		private OrderShipmentRepository $repository,
		private OrderShipmentDraftFactory $drafts,
		private ShipmentCreationService $creation,
		private DeliveryServiceRepository $services,
		private string $plugin_url = '',
		private string $version = '1'
	) {
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_CREATE, array( $this, 'ajax_create' ) );
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
			)
		);
	}

	public function render( mixed $post_or_order ): void {
		$order = $this->resolve_order( $post_or_order );
		if ( ! is_object( $order ) ) {
			echo '<p>' . esc_html__( 'Заказ не найден.', 'walls-delivery-calc' ) . '</p>';
			return;
		}
		$shipment = $this->repository->find_by_carrier( $order, RussianPostDomesticSettings::CARRIER_KEY );
		$error = $this->repository->last_error( $order );
		$draft = $this->drafts->draft_array( $order );
		$safe_preview = $this->creation->safe_preview( $this->drafts->create_request_from_order( $order ) );
		$request = is_array( $draft['request'] ?? null ) ? $draft['request'] : array();
		$services = is_array( $draft['services'] ?? null ) ? $draft['services'] : array();
		$recipient = is_array( $request['recipient'] ?? null ) ? $request['recipient'] : array();
		$address = is_array( $request['recipient_address'] ?? null ) ? $request['recipient_address'] : array();
		$place = is_array( $request['places'][0] ?? null ) ? $request['places'][0] : array();
		$meta = is_array( $request['meta'] ?? null ) ? $request['meta'] : array();
		$settings = is_array( $request['services'] ?? null ) ? $request['services'] : array();
		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
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
					<form data-wdc-shipment-form>
						<input type="hidden" name="order_id" value="<?php echo esc_attr( (string) $order_id ); ?>">
						<div class="wdc-shipment-grid">
							<section>
								<h3><?php echo esc_html__( 'Получатель', 'walls-delivery-calc' ); ?></h3>
								<label><?php echo esc_html__( 'ФИО', 'walls-delivery-calc' ); ?><input name="recipient_name" value="<?php echo esc_attr( (string) ( $recipient['name'] ?? '' ) ); ?>"></label>
								<label><?php echo esc_html__( 'Телефон', 'walls-delivery-calc' ); ?><input name="recipient_phone" value="<?php echo esc_attr( (string) ( $recipient['phone'] ?? '' ) ); ?>"></label>
								<label>Email<input name="recipient_email" value="<?php echo esc_attr( (string) ( $recipient['email'] ?? '' ) ); ?>"></label>
								<label><?php echo esc_html__( 'Индекс', 'walls-delivery-calc' ); ?><input name="postcode" value="<?php echo esc_attr( (string) ( $address['postcode'] ?? '' ) ); ?>"></label>
								<label><?php echo esc_html__( 'Адрес', 'walls-delivery-calc' ); ?><textarea name="raw_address" rows="2"><?php echo esc_textarea( (string) ( $address['raw_address'] ?? '' ) ); ?></textarea></label>
								<label><?php echo esc_html__( 'Текущий ПВЗ', 'walls-delivery-calc' ); ?><input name="pickup_point_code" value="<?php echo esc_attr( (string) ( $request['pickup_point']['point_code'] ?? $meta['pickup_point_code'] ?? '' ) ); ?>"></label>
								<label><?php echo esc_html__( 'Адрес ПВЗ', 'walls-delivery-calc' ); ?><input name="pickup_point_address" value="<?php echo esc_attr( (string) ( $request['pickup_point']['point_address'] ?? '' ) ); ?>"></label>
								<button type="button" class="button" data-wdc-admin-pickup-map><?php echo esc_html__( 'Выбрать другой ПВЗ на карте', 'walls-delivery-calc' ); ?></button>
							</section>
							<section>
								<h3><?php echo esc_html__( 'Доставка', 'walls-delivery-calc' ); ?></h3>
								<label><?php echo esc_html__( 'Служба доставки', 'walls-delivery-calc' ); ?><select name="service_key" data-wdc-service-select>
									<?php foreach ( $services as $service ) : ?>
										<option value="<?php echo esc_attr( (string) $service['service_key'] ); ?>" data-delivery-type="<?php echo esc_attr( (string) $service['delivery_type'] ); ?>" <?php selected( (string) $request['rate_id'], (string) $service['service_key'] ); ?>><?php echo esc_html( (string) $service['title'] ); ?></option>
									<?php endforeach; ?>
								</select></label>
								<label><?php echo esc_html__( 'Тариф/object', 'walls-delivery-calc' ); ?><input name="tariff_object" value="<?php echo esc_attr( (string) ( $meta['tariff_object'] ?? '' ) ); ?>"></label>
								<label><?php echo esc_html__( 'Индекс места приема', 'walls-delivery-calc' ); ?><input name="postoffice_code" value="<?php echo esc_attr( (string) ( $meta['postoffice_code'] ?? '' ) ); ?>"></label>
								<label><?php echo esc_html__( 'Срок хранения, дней', 'walls-delivery-calc' ); ?><input type="number" min="15" max="60" name="shelf_life_days" value="<?php echo esc_attr( (string) ( $settings['shelf_life_days'] ?? 30 ) ); ?>"></label>
							</section>
						</div>
						<section>
							<h3><?php echo esc_html__( 'Грузоместа', 'walls-delivery-calc' ); ?></h3>
							<div data-wdc-places>
								<div class="wdc-place-row" data-wdc-place>
									<input type="number" min="1" name="places[0][weight_g]" value="<?php echo esc_attr( (string) ( $place['weight_g'] ?? 1000 ) ); ?>" placeholder="<?php echo esc_attr__( 'вес, г', 'walls-delivery-calc' ); ?>">
									<input type="number" min="1" name="places[0][length_cm]" value="<?php echo esc_attr( (string) ( $place['length_cm'] ?? 20 ) ); ?>" placeholder="<?php echo esc_attr__( 'длина, см', 'walls-delivery-calc' ); ?>">
									<input type="number" min="1" name="places[0][width_cm]" value="<?php echo esc_attr( (string) ( $place['width_cm'] ?? 20 ) ); ?>" placeholder="<?php echo esc_attr__( 'ширина, см', 'walls-delivery-calc' ); ?>">
									<input type="number" min="1" name="places[0][height_cm]" value="<?php echo esc_attr( (string) ( $place['height_cm'] ?? 10 ) ); ?>" placeholder="<?php echo esc_attr__( 'высота, см', 'walls-delivery-calc' ); ?>">
									<input type="number" min="0" name="places[0][declared_value_kopecks]" value="<?php echo esc_attr( (string) ( $place['declared_value']['amount_kopecks'] ?? 0 ) ); ?>" placeholder="<?php echo esc_attr__( 'ОЦ, коп.', 'walls-delivery-calc' ); ?>">
									<button type="button" class="button" data-wdc-remove-place><?php echo esc_html__( 'Удалить', 'walls-delivery-calc' ); ?></button>
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
							<button type="submit" class="button button-primary"><?php echo esc_html__( 'Создать отправление', 'walls-delivery-calc' ); ?></button>
						</section>
					</form>
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

	private function resolve_order( mixed $post_or_order ): ?object {
		if ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_id' ) && method_exists( $post_or_order, 'get_meta' ) ) {
			return $post_or_order;
		}
		$order_id = is_object( $post_or_order ) && isset( $post_or_order->ID ) ? (int) $post_or_order->ID : 0;

		return $order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	}
}
