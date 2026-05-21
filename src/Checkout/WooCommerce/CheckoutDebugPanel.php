<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CheckoutDebugPanel {
	public function __construct(
		private CheckoutSessionManager $session_manager,
		private ?CheckoutFeatureGate $feature_gate = null
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_checkout_form', array( $this, 'render' ), 20 );
	}

	public function render(): void {
		if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! $this->feature_gate instanceof CheckoutFeatureGate || ! $this->feature_gate->debug_panel_enabled() ) {
			return;
		}

		$debug = $this->session_manager->debug();
		if ( array() === $debug ) {
			return;
		}

		echo '<section class="wdc-platform-debug-panel">';
		echo '<h3>' . esc_html__( 'Отладка checkout WDC', 'walls-delivery-calc' ) . '</h3>';
		echo '<dl>';
		echo '<dt>' . esc_html__( 'Тарифы', 'walls-delivery-calc' ) . '</dt><dd>' . esc_html( (string) ( $debug['rates_count'] ?? 0 ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Попадания в кеш', 'walls-delivery-calc' ) . '</dt><dd>' . esc_html( (string) ( $debug['cache_hits'] ?? 0 ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Резервный тариф', 'walls-delivery-calc' ) . '</dt><dd>' . esc_html( ! empty( $debug['fallback_used'] ) ? __( 'да', 'walls-delivery-calc' ) : __( 'нет', 'walls-delivery-calc' ) ) . '</dd>';

		$address = $this->session_manager->normalized_address_result();
		if ( null !== $address ) {
			$city = '' !== trim( $address->address->settlement ) ? $address->address->settlement : $address->address->city;
			echo '<dt>' . esc_html__( 'Нормализованный адрес', 'walls-delivery-calc' ) . '</dt><dd>' . esc_html( $city . ' / ' . $address->address->postcode ) . '</dd>';
			echo '<dt>' . esc_html__( 'Адрес введен вручную', 'walls-delivery-calc' ) . '</dt><dd>' . esc_html( $address->address->fallback ? __( 'да', 'walls-delivery-calc' ) : __( 'нет', 'walls-delivery-calc' ) ) . '</dd>';
			echo '<dt>' . esc_html__( 'Источник нормализации', 'walls-delivery-calc' ) . '</dt><dd>' . esc_html( $this->source_label( $address->source ) ) . '</dd>';
		}
		echo '</dl>';

		if ( ! empty( $debug['carrier_errors'] ) && is_array( $debug['carrier_errors'] ) ) {
			echo '<strong>' . esc_html__( 'Ошибки перевозчиков', 'walls-delivery-calc' ) . '</strong><ul>';
			foreach ( $debug['carrier_errors'] as $carrier => $message ) {
				echo '<li>' . esc_html( (string) $carrier . ': ' . (string) $message ) . '</li>';
			}
			echo '</ul>';
		}

		if ( ! empty( $debug['rates'] ) && is_array( $debug['rates'] ) ) {
			echo '<strong>' . esc_html__( 'Тарифы расчета', 'walls-delivery-calc' ) . '</strong><ul>';
			foreach ( $debug['rates'] as $rate ) {
				if ( is_array( $rate ) ) {
					echo '<li>' . esc_html( (string) ( $rate['rate_id'] ?? '' ) . ' / ' . (string) ( $rate['carrier_key'] ?? '' ) . ' / ' . (string) ( $rate['price']['amount_kopecks'] ?? 0 ) ) . '</li>';
				}
			}
			echo '</ul>';
		}

		echo '</section>';
	}

	private function source_label( string $source ): string {
		return match ( $source ) {
			'fias' => __( 'ФИАС/ГАР', 'walls-delivery-calc' ),
			'fallback' => __( 'введено вручную', 'walls-delivery-calc' ),
			default => $source,
		};
	}
}
