<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Orders\Application\OrderDeliveryRecalculationService;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryRecalculationAdminController {
	public const AJAX_PREVIEW = 'wdc_order_delivery_recalculate_preview';
	public const AJAX_LOCATION_SEARCH = 'wdc_order_delivery_recalculate_location_search';
	public const NONCE_ACTION = 'wdc_order_delivery_recalculation';

	public function __construct(
		private OrderDeliveryRecalculationService $service,
		private OrderDeliveryRateRenderer $renderer,
		private ?CheckoutLocationAjax $location_search = null,
		private string $plugin_url = '',
		private string $version = '1'
	) {
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_' . self::AJAX_LOCATION_SEARCH, array( $this, 'ajax_location_search' ) );
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
				'locationSearchAction' => self::AJAX_LOCATION_SEARCH,
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

		$result = $this->service->preview( $order, $this->selected_location_from_request() );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => (string) $result['message'] ), 400 );
		}

		wp_send_json_success(
			array(
				'html'    => $this->renderer->render( $result['rates'] ),
				'rates'   => $result['rates'],
				'request' => $result['request'],
				'location' => $result['location'],
			)
		);
	}

	public function ajax_location_search(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		if ( null === $this->location_search ) {
			wp_send_json_error( array( 'message' => __( 'Поиск населенных пунктов недоступен.', 'walls-delivery-calc' ) ), 500 );
		}

		$query = $this->request_string( 'query' );
		$country = $this->request_string( 'country_code' );
		$payload = $this->location_search->payload( $query, '', $country );
		wp_send_json_success( $payload );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function selected_location_from_request(): ?array {
		$raw = $_POST['selected_location'] ?? null;
		if ( null === $raw ) {
			return null;
		}
		if ( is_string( $raw ) ) {
			$raw = function_exists( 'wp_unslash' ) ? wp_unslash( $raw ) : $raw;
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $this->sanitize_location_payload( $decoded ) : null;
		}
		return is_array( $raw ) ? $this->sanitize_location_payload( $raw ) : null;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function sanitize_location_payload( array $payload ): array {
		$allowed = array(
			'id',
			'fias_id',
			'gar_id',
			'gar_object_id',
			'country_code',
			'region_name',
			'region_code',
			'state_value',
			'city_name',
			'city_value',
			'place_name',
			'settlement_name',
			'display_name',
			'option_label',
			'label',
			'postal_code',
			'postcode',
		);
		$clean = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}
			$value = $payload[ $key ];
			$clean[ $key ] = is_scalar( $value ) ? $this->clean_string( (string) $value ) : $value;
		}

		return $clean;
	}

	private function request_string( string $key ): string {
		$value = $_REQUEST[ $key ] ?? '';
		if ( is_array( $value ) ) {
			return '';
		}
		return $this->clean_string( (string) $value );
	}

	private function clean_string( string $value ): string {
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( $value );
	}
}
