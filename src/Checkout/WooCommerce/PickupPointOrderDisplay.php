<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class PickupPointOrderDisplay {
	public function register(): void {
		add_action( 'woocommerce_thankyou', array( $this, 'render_by_order_id' ), 20 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render' ), 20 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email' ), 20, 4 );
	}

	public function render_by_order_id( mixed $order_id ): void {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order_id );
			if ( is_object( $order ) ) {
				$this->render( $order );
			}
		}
	}

	public function render_email( mixed $order, mixed $sent_to_admin = false, mixed $plain_text = false, mixed $email = null ): void {
		unset( $sent_to_admin, $email );
		if ( ! is_object( $order ) ) {
			return;
		}
		if ( $plain_text ) {
			$point = $this->point( $order );
			if ( array() !== $point ) {
				echo "\nПункт выдачи Почты России: " . esc_html( $point['address'] ) . "\n";
				echo 'Индекс ПВЗ: ' . esc_html( $point['postcode'] ) . "\n";
				echo 'Тип ПВЗ: ' . esc_html( $point['type'] ) . "\n";
			}
			return;
		}

		$this->render( $order );
	}

	public function render( mixed $order ): void {
		if ( ! is_object( $order ) ) {
			return;
		}
		$point = $this->point( $order );
		if ( array() === $point ) {
			return;
		}

		echo '<section class="wdc-order-pickup-point"><h2>Пункт выдачи Почты России</h2><table class="shop_table shop_table_responsive"><tbody>';
		$this->row( 'Адрес', $point['address'] );
		$this->row( 'Индекс', $point['postcode'] );
		$this->row( 'Тип', $point['type'] );
		$this->row( 'Код', $point['code'] );
		echo '</tbody></table></section>';
	}

	/**
	 * @return array<string,string>
	 */
	private function point( object $order ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$address = trim( (string) $order->get_meta( '_wdc_pickup_point_address', true ) );
		$code = trim( (string) $order->get_meta( '_wdc_pickup_point_code', true ) );
		if ( '' === $address && '' === $code ) {
			return array();
		}

		return array(
			'address' => $address,
			'postcode' => trim( (string) $order->get_meta( '_wdc_pickup_point_postcode', true ) ),
			'type' => trim( (string) $order->get_meta( '_wdc_pickup_point_type', true ) ),
			'code' => $code,
		);
	}

	private function row( string $label, string $value ): void {
		if ( '' === trim( $value ) ) {
			return;
		}

		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
	}
}
