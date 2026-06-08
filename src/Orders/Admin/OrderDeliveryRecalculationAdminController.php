<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Orders\Application\OrderDeliveryRecalculationService;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryRecalculationAdminController {
	public const AJAX_PREVIEW = 'wdc_order_delivery_recalculate_preview';
	public const NONCE_ACTION = 'wdc_order_delivery_recalculation';

	public function __construct(
		private OrderDeliveryRecalculationService $service,
		private OrderDeliveryRateRenderer $renderer,
		private string $plugin_url = '',
		private string $version = '1'
	) {
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( $this, 'ajax_preview' ) );
	}

	public function enqueue_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id = is_object( $screen ) ? (string) ( $screen->id ?? '' ) : '';
		if ( ! in_array( $id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		wp_enqueue_style( 'wdc-order-delivery-recalculation', $this->plugin_url . 'assets/admin/order-delivery-recalculation.css', array(), $this->version );
		wp_enqueue_script( 'wdc-order-delivery-recalculation', $this->plugin_url . 'assets/admin/order-delivery-recalculation.js', array(), $this->version, true );
		wp_localize_script(
			'wdc-order-delivery-recalculation',
			'wdcOrderDeliveryRecalculation',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'action'  => self::AJAX_PREVIEW,
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

		$result = $this->service->preview( $order );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => (string) $result['message'] ), 400 );
		}

		wp_send_json_success(
			array(
				'html'    => $this->renderer->render( $result['rates'] ),
				'rates'   => $result['rates'],
				'request' => $result['request'],
			)
		);
	}
}
