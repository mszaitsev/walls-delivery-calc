<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CheckoutDebugPanel {
	public function __construct(
		private CheckoutSessionManager $session_manager
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_checkout_form', array( $this, 'render' ), 20 );
	}

	public function render(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$debug = $this->session_manager->debug();
		if ( array() === $debug ) {
			return;
		}

		echo '<section class="wdc-platform-debug-panel">';
		echo '<h3>' . esc_html__( 'WDC checkout debug', 'walls-delivery-calc' ) . '</h3>';
		echo '<dl>';
		echo '<dt>' . esc_html__( 'Rates', 'walls-delivery-calc' ) . '</dt><dd>' . esc_html( (string) ( $debug['rates_count'] ?? 0 ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Cache hits', 'walls-delivery-calc' ) . '</dt><dd>' . esc_html( (string) ( $debug['cache_hits'] ?? 0 ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Fallback', 'walls-delivery-calc' ) . '</dt><dd>' . esc_html( ! empty( $debug['fallback_used'] ) ? 'yes' : 'no' ) . '</dd>';

		$address = $this->session_manager->normalized_address_result();
		if ( null !== $address ) {
			$city = '' !== trim( $address->address->settlement ) ? $address->address->settlement : $address->address->city;
			echo '<dt>' . esc_html__( 'Normalized address', 'walls-delivery-calc' ) . '</dt><dd>' . esc_html( $city . ' / ' . $address->address->postcode ) . '</dd>';
			echo '<dt>' . esc_html__( 'Address fallback', 'walls-delivery-calc' ) . '</dt><dd>' . esc_html( $address->address->fallback ? 'yes' : 'no' ) . '</dd>';
			echo '<dt>' . esc_html__( 'Normalization source', 'walls-delivery-calc' ) . '</dt><dd>' . esc_html( $address->source ) . '</dd>';
		}
		echo '</dl>';

		if ( ! empty( $debug['carrier_errors'] ) && is_array( $debug['carrier_errors'] ) ) {
			echo '<strong>' . esc_html__( 'Carrier errors', 'walls-delivery-calc' ) . '</strong><ul>';
			foreach ( $debug['carrier_errors'] as $carrier => $message ) {
				echo '<li>' . esc_html( (string) $carrier . ': ' . (string) $message ) . '</li>';
			}
			echo '</ul>';
		}

		if ( ! empty( $debug['rates'] ) && is_array( $debug['rates'] ) ) {
			echo '<strong>' . esc_html__( 'Orchestration rates', 'walls-delivery-calc' ) . '</strong><ul>';
			foreach ( $debug['rates'] as $rate ) {
				if ( is_array( $rate ) ) {
					echo '<li>' . esc_html( (string) ( $rate['rate_id'] ?? '' ) . ' / ' . (string) ( $rate['carrier_key'] ?? '' ) . ' / ' . (string) ( $rate['price']['amount_kopecks'] ?? 0 ) ) . '</li>';
				}
			}
			echo '</ul>';
		}

		echo '</section>';
	}
}
