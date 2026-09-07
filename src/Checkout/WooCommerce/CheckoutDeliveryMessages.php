<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Infrastructure\Settings\CheckoutDeliveryMessageSettings;

defined( 'ABSPATH' ) || exit;

final class CheckoutDeliveryMessages {
	public function __construct(
		private CheckoutDeliveryMessageSettings $settings,
		private CheckoutCartTotalsResolver $totals
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_review_order_before_shipping', array( $this, 'render' ), 4 );
	}

	public function render(): void {
		$blocks = array();
		$info = $this->info_html();
		if ( '' !== $info ) {
			$blocks[] = '<div class="wdc-checkout-delivery-info">' . $info . '</div>';
		}

		$promo = $this->promo_html();
		if ( '' !== $promo ) {
			$blocks[] = $promo;
		}

		if ( array() === $blocks ) {
			return;
		}

		echo '<div class="wdc-checkout-delivery-messages">';
		echo implode( '', $blocks );
		echo '</div>';
	}

	private function info_html(): string {
		if ( ! $this->settings->info_enabled() ) {
			return '';
		}

		return $this->non_empty_html( $this->settings->info_html() );
	}

	private function promo_html(): string {
		if ( ! $this->settings->promo_enabled() ) {
			return '';
		}

		$threshold = $this->settings->promo_threshold_kopecks();
		if ( $threshold <= 0 ) {
			return '';
		}

		$total = $this->selected_total_kopecks();
		$reached = $total >= $threshold;
		$template = $reached ? $this->settings->promo_reached_html() : $this->settings->promo_below_html();
		$html = strtr(
			$template,
			array(
				'{s}' => CheckoutDeliveryMessageSettings::format_kopecks_amount( $threshold ),
				'{d}' => CheckoutDeliveryMessageSettings::format_kopecks_amount( max( 0, $threshold - $total ) ),
			)
		);
		$html = $this->non_empty_html( CheckoutDeliveryMessageSettings::sanitize_html( $html ) );
		if ( '' === $html ) {
			return '';
		}

		$state = $reached ? 'reached' : 'below';

		return '<div class="wdc-checkout-delivery-promo wdc-checkout-delivery-promo--' . esc_attr( $state ) . '">' . $html . '</div>';
	}

	private function selected_total_kopecks(): int {
		if ( CheckoutDeliveryMessageSettings::BASIS_SHIPPABLE_CART_ITEMS === $this->settings->promo_total_basis() ) {
			return $this->totals->shippable_cart_items_total()->get_kopecks();
		}

		return $this->totals->all_cart_items_total()->get_kopecks();
	}

	private function non_empty_html( string $html ): string {
		$html = trim( $html );
		$text = trim( html_entity_decode( $this->strip_all_tags( $html ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) );

		return '' === $text ? '' : $html;
	}

	private function strip_all_tags( string $html ): string {
		if ( function_exists( 'wp_strip_all_tags' ) ) {
			return wp_strip_all_tags( $html );
		}

		return strip_tags( preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html ) ?? '' );
	}
}
