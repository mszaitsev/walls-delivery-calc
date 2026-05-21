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
		if ( null === $result || '' === $this->session_manager->address_fingerprint() ) {
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
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--fallback">' . esc_html__( 'Используется введенный вручную населенный пункт', 'walls-delivery-calc' ) . '</p>';
			if ( '' !== trim( $city ) ) {
				echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--fallback-city">' . esc_html( $city ) . '</p>';
			}
		}
		if ( '' !== trim( $address->postcode ) ) {
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--postcode">' . esc_html__( 'Индекс:', 'walls-delivery-calc' ) . ' ' . esc_html( $address->postcode ) . ' <span class="wdc-address-normalization__source">(' . esc_html( $this->postcode_source_label( $result->source, $result->error_code ) ) . ')</span></p>';
		}
		if ( in_array( $result->error_code, array( 'api_timeout', 'api_failed', 'api_parse_failed', 'rate_limited' ), true ) ) {
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--api">' . esc_html__( 'Адрес будет обработан вручную, проверка сейчас недоступна.', 'walls-delivery-calc' ) . '</p>';
		}
		if ( ! $address->fallback ) {
			echo '<p class="wdc-address-normalization__source">' . esc_html__( 'Источник:', 'walls-delivery-calc' ) . ' ' . esc_html( $this->source_label( $result->source ) ) . '</p>';
		}
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

	private function postcode_source_label( string $source, string $error_code ): string {
		if ( 'fias' === $source && '' === $error_code ) {
			return __( 'FIAS/local', 'walls-delivery-calc' );
		}

		if ( 'fallback' === $source ) {
			return __( 'manual', 'walls-delivery-calc' );
		}

		return __( 'checkout', 'walls-delivery-calc' );
	}
}
