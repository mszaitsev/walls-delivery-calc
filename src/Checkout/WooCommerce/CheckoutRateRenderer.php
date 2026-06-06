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
		$this->render_courier_address_summary( $meta );

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
	private function render_courier_address_summary( array $meta ): void {
		if ( ! CourierRateSupport::is_courier_meta( $meta ) ) {
			return;
		}

		$field = $this->checkout_address_field();
		$prefix = str_starts_with( $field, 'shipping_' ) ? 'shipping' : 'billing';
		$address_1 = $this->posted_value( $prefix . '_address_1' );
		$has_address_1 = '' !== trim( $address_1 );
		$address = $has_address_1 ? $this->format_address_parts( $this->checkout_address_parts( $prefix ) ) : '';
		$hidden_value = $has_address_1 ? '' : ' hidden';
		$hidden_warning = $has_address_1 ? ' hidden' : '';

		echo '<div class="wdc-courier-address-summary" data-wdc-courier-address-summary data-address-field="' . esc_attr( $field ) . '">';
		echo '<div class="wdc-courier-address-summary__title">' . esc_html__( 'Доставка курьером по адресу:', 'walls-delivery-calc' ) . '</div>';
		echo '<div class="wdc-courier-address-summary__value"' . $hidden_value . '>' . esc_html( $address ) . '</div>';
		echo '<div class="wdc-courier-address-summary__warning"' . $hidden_warning . '>';
		echo esc_html__( 'Для доставки курьером необходимо ', 'walls-delivery-calc' );
		echo '<a href="#' . esc_attr( $field ) . '">' . esc_html__( 'заполнить адрес', 'walls-delivery-calc' ) . '</a>.';
		echo '</div>';
		echo '</div>';
	}

	private function checkout_address_field(): string {
		$ship_to_different = $this->posted_value( 'ship_to_different_address' );
		if ( '' !== trim( $ship_to_different ) ) {
			return 'shipping_address_1';
		}

		if ( $this->posted_has( 'billing_address_1' ) ) {
			return 'billing_address_1';
		}

		return $this->posted_has( 'shipping_address_1' ) ? 'shipping_address_1' : 'billing_address_1';
	}

	/**
	 * @return array<int,string>
	 */
	private function checkout_address_parts( string $prefix ): array {
		return array(
			$this->posted_value( $prefix . '_postcode' ),
			$this->posted_value( $prefix . '_city' ),
			$this->posted_value( $prefix . '_address_1' ),
		);
	}

	/**
	 * @param array<int,string> $parts
	 */
	private function format_address_parts( array $parts ): string {
		$parts = array_values( array_filter( array_map( static fn ( string $part ): string => trim( $part ), $parts ), static fn ( string $part ): bool => '' !== $part ) );

		return implode( ', ', $parts );
	}

	private function posted_value( string $key ): string {
		if ( isset( $_POST[ $key ] ) && ! is_array( $_POST[ $key ] ) ) {
			$value = function_exists( 'wp_unslash' ) ? wp_unslash( $_POST[ $key ] ) : $_POST[ $key ];
			return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );
		}

		if ( function_exists( 'WC' ) && is_object( WC() ) && method_exists( WC(), 'checkout' ) ) {
			$checkout = WC()->checkout();
			if ( is_object( $checkout ) && method_exists( $checkout, 'get_value' ) ) {
				$value = $checkout->get_value( $key );
				return is_scalar( $value ) ? trim( (string) $value ) : '';
			}
		}

		return '';
	}

	private function posted_has( string $key ): bool {
		return isset( $_POST ) && is_array( $_POST ) && array_key_exists( $key, $_POST );
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
		$checkout_group_id = (string) ( $meta['checkout_group_id'] ?? $meta['rate_id'] ?? $service_key );
		$delivery_type = (string) ( $meta['delivery_type'] ?? '' );
		$selected = (string) ( $meta['selected_tariff_object'] ?? '' );
		echo '<div class="wdc-domestic-tariff-selector" data-wdc-service-key="' . esc_attr( $service_key ) . '" data-wdc-checkout-group-id="' . esc_attr( $checkout_group_id ) . '" data-wdc-delivery-type="' . esc_attr( $delivery_type ) . '">';
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
			$line = $title;
			if ( '' !== $comment ) {
				$line .= ' - ' . $comment;
			}
			if ( '' !== $price ) {
				$line .= ': ';
			}
			echo '<label class="wdc-domestic-tariff-selector__item">';
			echo '<input type="radio" name="wdc_domestic_tariff_' . esc_attr( $checkout_group_id ) . '" value="' . esc_attr( $object ) . '" data-title="' . esc_attr( $title ) . '" data-price="' . esc_attr( (string) ( $variant['price_rub'] ?? '' ) ) . '" ' . checked( $selected, $object, false ) . '>';
			echo '<span class="wdc-domestic-tariff-selector__line">';
			echo '<span class="wdc-domestic-tariff-selector__line-text">' . esc_html( $line ) . '</span>';
			if ( '' !== $price ) {
				echo '<span class="wdc-domestic-tariff-selector__price">' . esc_html( $price ) . '</span>';
			}
			if ( '' !== $crossed ) {
				echo ' <span class="wdc-platform-crossed-price wdc-domestic-tariff-selector__crossed-price">' . esc_html( $crossed ) . '</span>';
			}
			echo '</span>';
			echo '</label>';
		}
		echo '</div>';
	}

	private function format_rubles( float $rubles ): string {
		return rtrim( rtrim( number_format( $rubles, 2, '.', ' ' ), '0' ), '.' ) . ' руб.';
	}
}
