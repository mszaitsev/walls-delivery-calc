<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryCustomerCommentsDisplay {
	public function __construct( private ?SettingsRepository $settings = null ) {}

	public function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render' ), 21 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email' ), 21, 4 );
	}

	public function render_email( mixed $order, mixed $sent_to_admin = false, mixed $plain_text = false, mixed $email = null ): void {
		unset( $sent_to_admin );
		if ( ! is_object( $order ) || $plain_text || ! $this->email_enabled( $email ) ) {
			return;
		}

		$this->render( $order );
	}

	public function render( mixed $order ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
			return;
		}
		$delivery_type = (string) $order->get_meta( '_wdc_platform_delivery_type', true );
		$requires_pickup_point = filter_var( $order->get_meta( '_wdc_platform_requires_pickup_point', true ), FILTER_VALIDATE_BOOLEAN );
		if ( DeliveryType::PICKUP === $delivery_type && $requires_pickup_point ) {
			return;
		}
		$comments = $this->comments( $order->get_meta( '_wdc_platform_customer_comments', true ) );
		if ( array() === $comments ) {
			return;
		}

		echo '<section class="wdc-order-delivery-comments" style="margin:16px 0 0;"><h2 style="margin:0 0 8px;">' . esc_html( __( 'Информация о доставке', 'walls-delivery-calc' ) ) . '</h2>';
		foreach ( $comments as $comment ) {
			echo '<div class="wdc-order-delivery-comments__item" style="margin:4px 0;">' . esc_html( $comment ) . '</div>';
		}
		echo '</section>';
	}

	/** @return array<int,string> */
	private function comments( mixed $raw ): array {
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw = is_array( $decoded ) ? $decoded : array( $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$result = array();
		foreach ( $raw as $comment ) {
			if ( ! is_scalar( $comment ) ) {
				continue;
			}
			$text = trim( (string) $comment );
			if ( '' === $text ) {
				continue;
			}
			$text = substr( $text, 0, 500 );
			if ( in_array( $text, $result, true ) ) {
				continue;
			}
			$result[] = $text;
		}

		return $result;
	}

	private function email_enabled( mixed $email ): bool {
		$email_id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';
		if ( '' === $email_id || ! $this->settings instanceof SettingsRepository ) {
			return false;
		}
		$enabled = $this->settings->get_array( 'pickup_email_card_enabled_emails', array() );
		$enabled = array_map( 'strval', $enabled );

		return in_array( $email_id, $enabled, true );
	}
}
