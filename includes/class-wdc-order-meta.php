<?php
/**
 * Persist and display delivery calculation order meta.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Order_Meta {
	private WDC_Logger $logger;

	private WDC_Settings $settings;

	public function __construct( ?WDC_Logger $logger = null, ?WDC_Settings $settings = null ) {
		$this->logger = $logger ?? new WDC_Logger();
		$this->settings = $settings ?? new WDC_Settings();
	}

	public function init(): void {
		add_action( 'woocommerce_checkout_create_order_shipping_item', array( $this, 'save_shipping_item_meta' ), 10, 4 );
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'add_hpos_meta_box' ) );
		add_action( 'add_meta_boxes_shop_order', array( $this, 'add_classic_meta_box' ) );
	}

	/**
	 * @param mixed $item WooCommerce shipping item.
	 * @param mixed $package_key Checkout package key.
	 * @param mixed $package Checkout package.
	 * @param mixed $order WooCommerce order.
	 */
	public function save_shipping_item_meta( $item, $package_key, $package, $order ): void {
		unset( $package_key, $package );

		if ( ! $item instanceof WC_Order_Item_Shipping || ! $order instanceof WC_Order ) {
			return;
		}

		$quote_summary = $item->get_meta( 'wdc_quote_summary', true );
		$rate_summary = $item->get_meta( 'wdc_rate_summary', true );
		$raw_meta = $item->get_meta( 'wdc_raw_meta', true );

		if ( ! is_array( $quote_summary ) || ! is_array( $rate_summary ) || ! is_array( $raw_meta ) ) {
			if ( 'wdc_dynamic_delivery' === $item->get_method_id() ) {
				$this->debug_log( 'Missing WDC rate meta for shipping item.', array( 'method_id' => $item->get_method_id() ) );
			} else {
				$this->debug_log( 'Skipped non-WDC shipping item.', array( 'method_id' => $item->get_method_id() ) );
			}

			return;
		}

		if ( (string) ( $quote_summary['service_id'] ?? '' ) !== WDC_Carrier_Registry::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ) {
			$this->debug_log( 'Skipped non-WDC shipping item.', array( 'service_id' => $quote_summary['service_id'] ?? '' ) );
			return;
		}

		$order_meta = $this->build_order_meta( $quote_summary, $rate_summary, $raw_meta );
		foreach ( $order_meta as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}

		$this->add_visible_shipping_item_meta( $item, $order_meta );
		$this->debug_log( 'WDC order meta saved.', array( 'order_id' => $order->get_id(), 'rate_id' => $order_meta['_wdc_delivery_rate_id'] ?? '' ) );
	}

	public function add_hpos_meta_box(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';
		add_meta_box( 'wdc_delivery_calculation', 'Расчёт доставки', array( $this, 'render_meta_box' ), $screen, 'side', 'default' );
	}

	public function add_classic_meta_box(): void {
		add_meta_box( 'wdc_delivery_calculation', 'Расчёт доставки', array( $this, 'render_meta_box' ), 'shop_order', 'side', 'default' );
	}

	/**
	 * @param mixed $post_or_order_object Current order screen object.
	 */
	public function render_meta_box( $post_or_order_object ): void {
		$order = $post_or_order_object instanceof WC_Order
			? $post_or_order_object
			: wc_get_order( $post_or_order_object instanceof WP_Post ? $post_or_order_object->ID : 0 );

		if ( ! $order instanceof WC_Order ) {
			echo '<p>' . esc_html__( 'Данные расчёта доставки не найдены.', 'walls-delivery-calc' ) . '</p>';
			return;
		}

		if ( '' === (string) $order->get_meta( '_wdc_delivery_provider', true ) ) {
			echo '<p>' . esc_html__( 'Данные расчёта доставки не найдены.', 'walls-delivery-calc' ) . '</p>';
			return;
		}

		$rows = $this->get_meta_box_rows( $order );
		echo '<table class="widefat striped"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}

			echo '<tr><th style="width:45%;text-align:left;">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$this->render_technical_details( $order );
	}

	/**
	 * @param array<string, mixed> $quote_summary Compact quote summary.
	 * @param array<string, mixed> $rate_summary Compact rate summary.
	 * @param array<string, mixed> $raw_meta Compact API diagnostics.
	 * @return array<string, scalar>
	 */
	private function build_order_meta( array $quote_summary, array $rate_summary, array $raw_meta ): array {
		$destination = isset( $quote_summary['destination'] ) && is_array( $quote_summary['destination'] ) ? $quote_summary['destination'] : array();
		$weight = isset( $quote_summary['weight'] ) && is_array( $quote_summary['weight'] ) ? $quote_summary['weight'] : array();
		$request_params = isset( $rate_summary['request_params'] ) && is_array( $rate_summary['request_params'] ) ? $rate_summary['request_params'] : array();
		$service_settings = $this->settings->get()['services'][ WDC_Carrier_Registry::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ];
		$is_fallback = ! empty( $rate_summary['is_fallback'] );
		$post_price_kop = $this->first_numeric( $raw_meta, array( 'paynds', 'paymoneynds' ) );
		$post_price_rub = $is_fallback ? 0 : (float) ( $rate_summary['base_price_rub'] ?? 0 );
		$api_original_price_kop = isset( $rate_summary['api_original_price_kop'] ) && is_numeric( $rate_summary['api_original_price_kop'] )
			? (int) $rate_summary['api_original_price_kop']
			: ( null === $post_price_kop ? 0 : (int) $post_price_kop );
		$api_original_price_rub = isset( $rate_summary['api_original_price_rub'] ) && is_numeric( $rate_summary['api_original_price_rub'] )
			? (float) $rate_summary['api_original_price_rub']
			: (float) $api_original_price_kop / 100;
		$api_error = $this->first_non_empty_string(
			array(
				$raw_meta['api_error_message'] ?? '',
				$quote_summary['error_message'] ?? '',
				$quote_summary['fallback_reason'] ?? '',
			)
		);

		if ( 0.0 === $post_price_rub && null !== $post_price_kop ) {
			$post_price_rub = (float) $post_price_kop / 100;
		}

		return array(
			'_wdc_delivery_provider' => (string) ( $quote_summary['carrier_id'] ?? '' ),
			'_wdc_delivery_service' => (string) ( $quote_summary['service_id'] ?? '' ),
			'_wdc_delivery_rate_id' => (string) ( $rate_summary['rate_id'] ?? '' ),
			'_wdc_delivery_rate_title' => (string) ( $rate_summary['rate_title'] ?? $rate_summary['rate_label'] ?? '' ),
			'_wdc_delivery_method' => (string) ( $rate_summary['delivery_method'] ?? '' ),
			'_wdc_transport_type' => (string) ( $rate_summary['transport_type'] ?? '' ),
			'_wdc_tariff_type' => (string) ( $rate_summary['tariff_type'] ?? '' ),
			'_wdc_currency' => (string) ( $rate_summary['currency'] ?? 'RUB' ),
			'_wdc_wc_country' => (string) ( $destination['country_code'] ?? '' ),
			'_wdc_carrier_country_id' => (string) ( $destination['carrier_country_id'] ?? '' ),
			'_wdc_carrier_country_name' => (string) ( $destination['country'] ?? '' ),
			'_wdc_cart_weight_g' => absint( $weight['cart_weight_g'] ?? 0 ),
			'_wdc_packaging_weight_g' => absint( $weight['packaging_weight_g'] ?? 0 ),
			'_wdc_total_weight_g' => absint( $weight['total_weight_g'] ?? 0 ),
			'_wdc_post_price_kop' => null === $post_price_kop ? 0 : (int) $post_price_kop,
			'_wdc_post_price_rub' => $post_price_rub,
			'_wdc_api_original_price_kop' => $api_original_price_kop,
			'_wdc_api_original_price_rub' => $api_original_price_rub,
			'_wdc_formula_divider' => (float) ( $rate_summary['formula_divider'] ?? $service_settings['formula_divider'] ),
			'_wdc_formula_add_rub' => (float) ( $rate_summary['formula_add_rub'] ?? $service_settings['formula_add_rub'] ),
			'_wdc_items_net_total_rub' => (float) ( $rate_summary['items_net_total_rub'] ?? 0 ),
			'_wdc_shipping_discount_percent_from_items_total' => (float) ( $rate_summary['shipping_discount_percent_from_items_total'] ?? $service_settings['shipping_discount_percent_from_items_total'] ),
			'_wdc_shipping_discount_amount_rub' => $is_fallback ? 0 : (int) ( $rate_summary['shipping_discount_amount_rub'] ?? 0 ),
			'_wdc_shipping_price_before_items_discount_rub' => $is_fallback ? 0 : (int) ( $rate_summary['shipping_price_before_items_discount_rub'] ?? $rate_summary['price'] ?? 0 ),
			'_wdc_final_shipping_price_rub' => $is_fallback ? 0 : (float) ( $rate_summary['price'] ?? 0 ),
			'_wdc_origin_postcode' => (string) ( $request_params['from'] ?? $service_settings['origin_postcode'] ?? '' ),
			'_wdc_object_code' => (string) ( $request_params['object'] ?? $service_settings['object_code'] ?? '' ),
			'_wdc_isavia_requested' => (string) ( $request_params['isavia'] ?? $service_settings['isavia'] ?? '' ),
			'_wdc_transtype_result' => (string) ( $raw_meta['transtype'] ?? '' ),
			'_wdc_transtype_name' => (string) ( $raw_meta['transname'] ?? $rate_summary['transport_type_title'] ?? '' ),
			'_wdc_api_date' => (string) ( $raw_meta['date'] ?? $request_params['date'] ?? '' ),
			'_wdc_api_cache_hit' => ! empty( $rate_summary['cache_hit'] ) ? 'yes' : 'no',
			'_wdc_api_error' => $api_error,
			'_wdc_is_fallback' => $is_fallback ? 'yes' : 'no',
			'_wdc_api_paynds' => (string) ( $raw_meta['paynds'] ?? '' ),
			'_wdc_api_paymoneynds' => (string) ( $raw_meta['paymoneynds'] ?? '' ),
			'_wdc_api_url' => (string) ( $raw_meta['api_url'] ?? '' ),
			'_wdc_api_http_code' => (string) ( $raw_meta['api_http_code'] ?? '' ),
		);
	}

	/**
	 * @param array<string, scalar> $order_meta Saved order meta.
	 */
	private function add_visible_shipping_item_meta( WC_Order_Item_Shipping $item, array $order_meta ): void {
		$item->add_meta_data( 'Расчёт доставки', 'Почта России, международная доставка', true );
		$item->add_meta_data( 'Страна расчёта', $this->format_country( $order_meta ), true );
		$item->add_meta_data( 'Вес расчёта', $order_meta['_wdc_total_weight_g'] . ' г', true );
		$item->add_meta_data( 'Итог расчёта', $order_meta['_wdc_final_shipping_price_rub'] . ' руб.', true );
	}

	/**
	 * @return array<string, string>
	 */
	private function get_meta_box_rows( WC_Order $order ): array {
		$formula = $this->format_number( $order->get_meta( '_wdc_formula_divider', true ) );
		$formula_add = $this->format_number( $order->get_meta( '_wdc_formula_add_rub', true ) );
		$formula = '' !== $formula ? 'цена / ' . $formula . ' + ' . $formula_add : '';

		return array(
			'Служба' => 'Почта России',
			'Сценарий' => 'международная доставка',
			'Страна' => $this->format_country_from_order( $order ),
			'Вес товаров' => $this->format_grams( $order->get_meta( '_wdc_cart_weight_g', true ) ),
			'Вес упаковки' => $this->format_grams( $order->get_meta( '_wdc_packaging_weight_g', true ) ),
			'Общий вес' => $this->format_grams( $order->get_meta( '_wdc_total_weight_g', true ) ),
			'Формула' => $formula,
			'Стоимость доставки до скидки' => $this->format_rub( $order->get_meta( '_wdc_shipping_price_before_items_discount_rub', true ) ),
			'Скидка от суммы товаров' => $this->format_discount( $order ),
			'Итоговая стоимость доставки' => $this->format_rub( $order->get_meta( '_wdc_final_shipping_price_rub', true ) ),
			'Тип доставки' => $this->format_transport_type( (string) $order->get_meta( '_wdc_transport_type', true ), (string) $order->get_meta( '_wdc_transtype_name', true ) ),
			'Кеш API' => $this->format_yes_no( (string) $order->get_meta( '_wdc_api_cache_hit', true ) ),
			'Fallback' => $this->format_yes_no( (string) $order->get_meta( '_wdc_is_fallback', true ) ),
			'Ошибка API' => (string) $order->get_meta( '_wdc_api_error', true ),
		);
	}

	/**
	 * @param array<string, mixed> $values Source values.
	 * @param array<int, string>  $keys Candidate keys.
	 */
	private function first_numeric( array $values, array $keys ): ?float {
		foreach ( $keys as $key ) {
			if ( isset( $values[ $key ] ) && is_numeric( $values[ $key ] ) ) {
				return (float) $values[ $key ];
			}
		}

		return null;
	}

	/**
	 * @param array<int, mixed> $values Candidate values.
	 */
	private function first_non_empty_string( array $values ): string {
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}

	/**
	 * @param array<string, scalar> $order_meta Saved order meta.
	 */
	private function format_country( array $order_meta ): string {
		$name = $this->uppercase( (string) ( $order_meta['_wdc_carrier_country_name'] ?? '' ) );
		$country = (string) ( $order_meta['_wdc_wc_country'] ?? '' );
		$carrier_id = (string) ( $order_meta['_wdc_carrier_country_id'] ?? '' );

		return trim( $name . ' / ' . $country . ' / код Почты России ' . $carrier_id, ' /' );
	}

	private function format_country_from_order( WC_Order $order ): string {
		return $this->format_country(
			array(
				'_wdc_carrier_country_name' => (string) $order->get_meta( '_wdc_carrier_country_name', true ),
				'_wdc_wc_country' => (string) $order->get_meta( '_wdc_wc_country', true ),
				'_wdc_carrier_country_id' => (string) $order->get_meta( '_wdc_carrier_country_id', true ),
			)
		);
	}

	private function format_transport_type( string $transport_type, string $transport_name ): string {
		if ( '' !== $transport_name ) {
			return $transport_name;
		}

		if ( 'air' === $transport_type ) {
			return 'авиа';
		}

		if ( 'ground' === $transport_type ) {
			return 'наземная';
		}

		return 'неизвестно';
	}

	private function format_yes_no( string $value ): string {
		return 'yes' === $value ? 'да' : 'нет';
	}

	private function format_discount( WC_Order $order ): string {
		$amount = $this->format_number( $order->get_meta( '_wdc_shipping_discount_amount_rub', true ) );
		$percent = $this->format_number( $order->get_meta( '_wdc_shipping_discount_percent_from_items_total', true ) );

		return $amount . ' руб. (' . $percent . '%)';
	}

	private function render_technical_details( WC_Order $order ): void {
		$rows = array(
			'Оригинальная цена API Почты России' => $this->format_rub( $order->get_meta( '_wdc_api_original_price_rub', true ) ) . ' (' . absint( $order->get_meta( '_wdc_api_original_price_kop', true ) ) . ' коп.)',
			'paynds' => (string) $order->get_meta( '_wdc_api_paynds', true ),
			'paymoneynds' => (string) $order->get_meta( '_wdc_api_paymoneynds', true ),
			'API URL' => (string) $order->get_meta( '_wdc_api_url', true ),
			'HTTP code' => (string) $order->get_meta( '_wdc_api_http_code', true ),
		);

		echo '<details style="margin-top:12px;">';
		echo '<summary>' . esc_html__( 'Технические данные расчёта', 'walls-delivery-calc' ) . '</summary>';
		echo '<table class="widefat striped" style="margin-top:8px;"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}

			echo '<tr><th style="width:45%;text-align:left;">' . esc_html( $label ) . '</th><td style="word-break:break-all;">' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '</details>';
	}

	private function format_grams( $value ): string {
		return absint( $value ) . ' г';
	}

	private function format_rub( $value ): string {
		return $this->format_number( $value ) . ' руб.';
	}

	private function format_number( $value ): string {
		if ( ! is_numeric( $value ) ) {
			return '';
		}

		$number = (float) $value;

		return rtrim( rtrim( number_format( $number, 2, '.', '' ), '0' ), '.' );
	}

	private function uppercase( string $value ): string {
		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
	}

	/**
	 * @param array<string, mixed> $context Debug context.
	 */
	private function debug_log( string $message, array $context = array() ): void {
		$settings = $this->settings->get();
		if ( 'yes' === $settings['debug_enabled'] ) {
			$this->logger->log( 'debug', $message, $context );
		}
	}
}
