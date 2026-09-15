<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Checkout\Comments\DeliveryCustomerCommentNormalizer;
use WallsShop\WDC\Checkout\Comments\DeliveryCustomerCommentRenderer;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryCustomerCommentsDisplay {
	public function __construct(
		private ?SettingsRepository $settings = null,
		private ?DeliveryCustomerCommentRenderer $customer_comment_renderer = null,
		private ?DeliveryCustomerCommentNormalizer $customer_comment_normalizer = null
	) {
		$this->customer_comment_normalizer ??= new DeliveryCustomerCommentNormalizer();
		$this->customer_comment_renderer ??= new DeliveryCustomerCommentRenderer( $this->customer_comment_normalizer );
	}

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
		$comments = $this->comments( $order->get_meta( '_wdc_platform_customer_comments', true ) );
		if ( array() === $comments ) {
			return;
		}

		echo '<section class="wdc-order-delivery-comments" style="margin:16px 0 0;"><h2 style="margin:0 0 8px;">' . esc_html( __( 'Информация о доставке', 'walls-delivery-calc' ) ) . '</h2>';
		echo $this->customer_comment_renderer->render_items( $comments );
		echo '</section>';
	}

	/** @return array<int,array<string,string>> */
	private function comments( mixed $raw ): array {
		return $this->customer_comment_normalizer->normalize( $raw );
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
