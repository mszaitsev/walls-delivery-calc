<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CheckoutRateRenderer {
	public function __construct(
		private ?CheckoutSessionManager $session_manager = null
	) {
	}

	public function register(): void {
		add_filter( 'woocommerce_cart_shipping_method_full_label', array( $this, 'append_crossed_price_to_label' ), 10, 2 );
		add_action( 'woocommerce_after_shipping_rate', array( $this, 'render' ), 10, 2 );
	}

	public function append_crossed_price_to_label( string $label, mixed $method ): string {
		$meta = $this->meta( $method );
		if ( array() === $meta || ! isset( $meta['carrier_key'] ) ) {
			return $label;
		}

		if ( ! is_array( $meta['crossed_price'] ?? null ) || ! isset( $meta['crossed_price']['amount_kopecks'] ) ) {
			return $label;
		}

		return $label . ' <span class="wdc-platform-crossed-price wdc-platform-crossed-price--inline">' . esc_html( $this->format_money( (int) $meta['crossed_price']['amount_kopecks'] ) ) . '</span>';
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

		$this->render_tariff_selector( $meta );

		if ( empty( $meta['domestic_tariff_grouped'] ) && empty( $meta['tariff_variants'] ) && '' !== trim( (string) ( $meta['planned_delivery_comment'] ?? '' ) ) ) {
			echo '<div class="wdc-platform-delivery-comment wdc-shipping-rate-comment">' . esc_html( (string) $meta['planned_delivery_comment'] ) . '</div>';
		}

		if ( is_array( $meta['comments'] ?? null ) ) {
			foreach ( $meta['comments'] as $comment ) {
				if ( '' !== trim( (string) $comment ) ) {
					echo '<div class="wdc-platform-delivery-comment wdc-shipping-rate-comment">' . esc_html( (string) $comment ) . '</div>';
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
		return rtrim( rtrim( number_format( $kopecks / 100, 2, '.', ' ' ), '0' ), '.' ) . ' руб.';
	}
	/**
	 * @param array<string,mixed> $meta
	 */
	private function render_tariff_selector( array $meta ): void {
		$variants = is_array( $meta['tariff_variants'] ?? null ) ? $meta['tariff_variants'] : array();
		if ( count( $variants ) < 2 ) {
			return;
		}
		$service_key = (string) ( $meta['service_key'] ?? '' );
		$selected = (string) ( $meta['selected_tariff_object'] ?? '' );
		echo '<div class="wdc-domestic-tariff-selector" data-wdc-service-key="' . esc_attr( $service_key ) . '">';
		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) ) {
				continue;
			}
			$object = (string) ( $variant['object_code'] ?? '' );
			$title = (string) ( $variant['title'] ?? '' );
			$comment = (string) ( $variant['planned_delivery_comment'] ?? '' );
			$price = isset( $variant['price_rub'] ) ? $this->format_rubles( (float) $variant['price_rub'] ) : '';
			$crossed = is_array( $variant['crossed_price'] ?? null ) && isset( $variant['crossed_price']['amount_kopecks'] )
				? $this->format_money( (int) $variant['crossed_price']['amount_kopecks'] )
				: '';
			echo '<label class="wdc-domestic-tariff-selector__item">';
			echo '<input type="radio" name="wdc_domestic_tariff_' . esc_attr( $service_key ) . '" value="' . esc_attr( $object ) . '" data-title="' . esc_attr( $title ) . '" data-price="' . esc_attr( (string) ( $variant['price_rub'] ?? '' ) ) . '" ' . checked( $selected, $object, false ) . '>';
			echo '<span class="wdc-domestic-tariff-selector__title">' . esc_html( $title ) . '</span>';
			if ( '' !== $comment ) {
				echo '<span class="wdc-domestic-tariff-selector__separator" aria-hidden="true"> - </span>';
				echo '<span class="wdc-domestic-tariff-selector__days">' . esc_html( $comment ) . '</span>';
			}
			if ( '' !== $price ) {
				echo '<span class="wdc-domestic-tariff-selector__separator" aria-hidden="true">: </span>';
				echo '<span class="wdc-domestic-tariff-selector__price">' . esc_html( $price ) . '</span>';
			}
			if ( '' !== $crossed ) {
				echo '<span class="wdc-platform-crossed-price wdc-domestic-tariff-selector__crossed-price">' . esc_html( $crossed ) . '</span>';
			}
			echo '</label>';
		}
		echo '</div>';
	}

	private function format_rubles( float $rubles ): string {
		return rtrim( rtrim( number_format( $rubles, 2, '.', ' ' ), '0' ), '.' ) . ' руб.';
	}
}
