<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class OrderShippingMetaPersister {
	public function __construct(
		private CheckoutSessionManager $session_manager
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'persist' ), 20, 2 );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function persist( mixed $order, array $data = array() ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$rate = $this->selected_rate();
		if ( array() === $rate ) {
			return;
		}

		$map = array(
			'_wdc_platform_carrier_key'              => $rate['carrier_key'] ?? '',
			'_wdc_platform_rate_id'                  => $rate['rate_id'] ?? '',
			'_wdc_platform_delivery_type'            => $rate['delivery_type'] ?? '',
			'_wdc_platform_crossed_price'            => $rate['crossed_price'] ?? null,
			'_wdc_platform_planned_delivery_comment' => $rate['planned_delivery_comment'] ?? '',
			'_wdc_platform_comments'                 => $rate['comments'] ?? array(),
			'_wdc_platform_fallback_used'            => ! empty( $rate['fallback_used'] ) || 'fallback' === (string) ( $rate['carrier_key'] ?? '' ),
		);

		foreach ( $map as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function selected_rate(): array {
		$rates  = $this->session_manager->rates();
		$chosen = $this->chosen_shipping_methods();

		foreach ( $chosen as $rate_id ) {
			if ( isset( $rates[ $rate_id ] ) ) {
				return $rates[ $rate_id ];
			}
		}

		return count( $rates ) > 0 ? reset( $rates ) : array();
	}

	/**
	 * @return array<int,string>
	 */
	private function chosen_shipping_methods(): array {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) && is_object( WC()->session ) && method_exists( WC()->session, 'get' ) ) {
			$chosen = WC()->session->get( 'chosen_shipping_methods', array() );

			return is_array( $chosen ) ? array_map( 'strval', $chosen ) : array();
		}

		return array();
	}
}
