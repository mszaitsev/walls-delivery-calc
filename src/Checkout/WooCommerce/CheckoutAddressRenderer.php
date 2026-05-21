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

		$address      = $result->address;
		$city         = '' !== trim( $address->settlement ) ? $address->settlement : $address->city;
		$city_context = $this->session_manager->city_context();
		$postcode     = (string) ( $city_context['postcode'] ?? $address->postcode );

		echo '<tr class="wdc-address-normalization-row"><th>' . esc_html__( 'Проверка адреса', 'walls-delivery-calc' ) . '</th><td>';
		echo '<div class="wdc-address-normalization">';
		if ( 'local_db' === (string) ( $city_context['source'] ?? '' ) ) {
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--city-context">' . esc_html__( 'Населенный пункт выбран из справочника', 'walls-delivery-calc' ) . '</p>';
			$display = $this->city_display( $city_context );
			if ( '' !== $display ) {
				echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--city-display">' . esc_html( $display ) . '</p>';
			}
		} elseif ( $address->fallback ) {
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--fallback">' . esc_html__( 'Используется введенный вручную населенный пункт', 'walls-delivery-calc' ) . '</p>';
			if ( '' !== trim( $city ) ) {
				echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--fallback-city">' . esc_html( $city ) . '</p>';
			}
		} elseif ( $address->normalized ) {
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--normalized">' . esc_html__( 'Населенный пункт определен:', 'walls-delivery-calc' ) . ' ' . esc_html( $city ) . '</p>';
		}

		if ( '' !== trim( $postcode ) ) {
			echo '<p class="wdc-address-normalization__notice wdc-address-normalization__notice--postcode">' . esc_html__( 'Индекс:', 'walls-delivery-calc' ) . ' ' . esc_html( $postcode ) . '</p>';
		}

		if ( $address->normalized ) {
			echo '<p class="wdc-address-normalization__source">' . esc_html__( 'Адрес нормализован через:', 'walls-delivery-calc' ) . ' ' . esc_html( $this->source_label( $result->source ) ) . '</p>';
		} else {
			echo '<p class="wdc-address-normalization__source">' . esc_html__( 'Адрес улица/дом не нормализован', 'walls-delivery-calc' ) . '</p>';
		}

		echo '</div>';
		echo '</td></tr>';
	}

	private function source_label( string $source ): string {
		return match ( $source ) {
			'fias' => __( 'ФИАС/ГАР', 'walls-delivery-calc' ),
			'dadata' => __( 'DaData', 'walls-delivery-calc' ),
			'fallback' => __( 'fallback', 'walls-delivery-calc' ),
			default => $source,
		};
	}

	/**
	 * @param array<string,mixed> $city_context
	 */
	private function city_display( array $city_context ): string {
		$display = trim( (string) ( $city_context['display_name'] ?? '' ) );
		if ( '' !== $display ) {
			return $display;
		}

		$city = trim( (string) ( $city_context['settlement_name'] ?? '' ) );
		if ( '' === $city ) {
			$city = trim( (string) ( $city_context['city_name'] ?? '' ) );
		}

		$region = trim( (string) ( $city_context['region_name'] ?? '' ) );

		return trim( $city . ( '' !== $region ? ' — ' . $region : '' ) );
	}
}
