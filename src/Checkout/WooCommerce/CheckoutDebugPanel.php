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

		$address = $this->session_manager->normalized_address_result();
		$city    = null !== $address ? ( '' !== trim( $address->address->settlement ) ? $address->address->settlement : $address->address->city ) : '';
		$selected_city = $this->session_manager->selected_city();

		echo '<section class="wdc-platform-debug-panel">';
		echo '<h3>' . esc_html__( 'Отладка checkout WDC', 'walls-delivery-calc' ) . '</h3>';
		echo '<dl>';
		$this->row( __( 'Тарифы', 'walls-delivery-calc' ), (string) ( $debug['rates_count'] ?? 0 ) );
		$this->row( __( 'Попадания в кеш', 'walls-delivery-calc' ), (string) ( $debug['cache_hits'] ?? 0 ) );
		$this->row( __( 'Резервный тариф', 'walls-delivery-calc' ), ! empty( $debug['fallback_used'] ) ? __( 'да', 'walls-delivery-calc' ) : __( 'нет', 'walls-delivery-calc' ) );
		$this->row( __( 'Fingerprint адреса', 'walls-delivery-calc' ), $this->session_manager->address_fingerprint() );
		$this->row( __( 'ID выбранного населенного пункта', 'walls-delivery-calc' ), (string) ( $selected_city['id'] ?? '' ) );
		$this->row( __( 'Нормализованный город', 'walls-delivery-calc' ), $city );
		$this->row( __( 'Город из checkout', 'walls-delivery-calc' ), (string) ( $debug['raw_checkout_city'] ?? '' ) );
		$this->row( __( 'Город в session', 'walls-delivery-calc' ), (string) ( $selected_city['city_name'] ?? '' ) );
		$this->row( __( 'Fallback-город', 'walls-delivery-calc' ), $this->session_manager->fallback_city() );
		$this->row( __( 'Сортировка в session', 'walls-delivery-calc' ), $this->session_manager->selected_sort_mode() );
		$this->row( __( 'Примененная сортировка', 'walls-delivery-calc' ), (string) ( $debug['sort_mode'] ?? '' ) );
		if ( null !== $address ) {
			$address_debug = $address->debug;
			$this->row( __( 'Источник нормализации', 'walls-delivery-calc' ), $this->source_label( $address->source ) );
			$this->row( __( 'normalized', 'walls-delivery-calc' ), $address->address->normalized ? 'true' : 'false' );
			$this->row( __( 'Normalization chain', 'walls-delivery-calc' ), implode( ' -> ', $this->normalization_chain( $address_debug ) ) );
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

	private function row( string $label, string $value ): void {
		echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
	}

	private function source_label( string $source ): string {
		return match ( $source ) {
			'fias' => __( 'ФИАС/ГАР', 'walls-delivery-calc' ),
			'fallback' => __( 'введено вручную', 'walls-delivery-calc' ),
			default => $source,
		};
	}

	/**
	 * @param array<string,mixed> $debug
	 * @return array<int,string>
	 */
	private function normalization_chain( array $debug ): array {
		$chain = $debug['normalization_chain'] ?? array();
		return is_array( $chain ) && array() !== $chain
			? array_map( 'strval', $chain )
			: array( 'local city DB', 'fias placeholder', 'manual fallback' );
	}
}
