<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CheckoutRateRenderer {
	public function register(): void {
		add_action( 'woocommerce_after_shipping_rate', array( $this, 'render' ), 10, 2 );
	}

	public function render( mixed $method, int|string $index = 0 ): void {
		$meta = $this->meta( $method );
		if ( array() === $meta || ! isset( $meta['carrier_key'] ) ) {
			return;
		}

		$classes = array( 'wdc-platform-rate-meta' );
		if ( ! empty( $meta['fallback_used'] ) || 'fallback' === (string) ( $meta['carrier_key'] ?? '' ) ) {
			$classes[] = 'wdc-platform-rate-meta--fallback';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '">';

		if ( is_array( $meta['crossed_price'] ?? null ) && isset( $meta['crossed_price']['amount_kopecks'] ) ) {
			echo '<span class="wdc-platform-crossed-price">' . esc_html( $this->format_money( (int) $meta['crossed_price']['amount_kopecks'] ) ) . '</span>';
		}

		if ( '' !== trim( (string) ( $meta['planned_delivery_comment'] ?? '' ) ) ) {
			echo '<span class="wdc-platform-delivery-comment">' . esc_html( (string) $meta['planned_delivery_comment'] ) . '</span>';
		}

		if ( is_array( $meta['comments'] ?? null ) ) {
			foreach ( $meta['comments'] as $comment ) {
				if ( '' !== trim( (string) $comment ) ) {
					echo '<span class="wdc-platform-delivery-comment">' . esc_html( (string) $comment ) . '</span>';
				}
			}
		}

		echo '</div>';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function meta( mixed $method ): array {
		if ( is_object( $method ) && method_exists( $method, 'get_meta_data' ) ) {
			$meta = $method->get_meta_data();

			return is_array( $meta ) ? $meta : array();
		}

		if ( is_object( $method ) && isset( $method->meta_data ) && is_array( $method->meta_data ) ) {
			return $method->meta_data;
		}

		return array();
	}

	private function format_money( int $kopecks ): string {
		return rtrim( rtrim( number_format( $kopecks / 100, 2, '.', ' ' ), '0' ), '.' ) . ' ₽';
	}
}
