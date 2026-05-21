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

		echo '<tr class="wdc-address-normalization-row"><th>' . esc_html__( 'Проверка адреса', 'walls-delivery-calc' ) . '</th><td>';
		echo '<div class="wdc-address-normalization">';
		if ( $address->normalized ) {
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--normalized">' . esc_html__( 'Населенный пункт определен:', 'walls-delivery-calc' ) . ' ' . esc_html( $city ) . '</p>';
		}
		if ( $address->fallback ) {
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--fallback">' . esc_html__( 'Город не найден в справочнике, будет использовано введенное значение.', 'walls-delivery-calc' ) . '</p>';
		}
		if ( '' !== trim( $address->postcode ) ) {
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--postcode">' . esc_html__( 'Индекс:', 'walls-delivery-calc' ) . ' ' . esc_html( $address->postcode ) . '</p>';
		}
		echo '<p class="wdc-address-normalization__source">' . esc_html__( 'Источник:', 'walls-delivery-calc' ) . ' ' . esc_html( $this->source_label( $result->source ) ) . '</p>';
		echo '</div>';
		echo '</td></tr>';
	}

	private function source_label( string $source ): string {
		return match ( $source ) {
			'fias' => __( 'ФИАС/ГАР', 'walls-delivery-calc' ),
			'fallback' => __( 'введено вручную', 'walls-delivery-calc' ),
			default => $source,
		};
	}
}
