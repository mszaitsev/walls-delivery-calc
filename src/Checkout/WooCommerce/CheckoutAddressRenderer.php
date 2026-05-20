<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CheckoutAddressRenderer {
	public function __construct(
		private CheckoutSessionManager $session_manager
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_review_order_before_shipping', array( $this, 'render' ), 15 );
	}

	public function render(): void {
		$result = $this->session_manager->normalized_address_result();
		if ( null === $result ) {
			return;
		}

		$address = $result->address;
		$city    = '' !== trim( $address->settlement ) ? $address->settlement : $address->city;

		echo '<tr class="wdc-address-normalization-row"><th>' . esc_html__( 'Address check', 'walls-delivery-calc' ) . '</th><td>';
		echo '<div class="wdc-address-normalization">';
		if ( $address->normalized ) {
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--normalized">' . esc_html__( 'City normalized:', 'walls-delivery-calc' ) . ' ' . esc_html( $city ) . '</p>';
		}
		if ( $address->fallback ) {
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--fallback">' . esc_html__( 'Unknown city is accepted as fallback.', 'walls-delivery-calc' ) . '</p>';
		}
		if ( '' !== trim( $address->postcode ) ) {
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--postcode">' . esc_html__( 'Resolved postcode:', 'walls-delivery-calc' ) . ' ' . esc_html( $address->postcode ) . '</p>';
		}
		echo '<p class="wdc-address-normalization__source">' . esc_html__( 'Source:', 'walls-delivery-calc' ) . ' ' . esc_html( $result->source ) . '</p>';
		echo '</div>';
		echo '</td></tr>';
	}
}
