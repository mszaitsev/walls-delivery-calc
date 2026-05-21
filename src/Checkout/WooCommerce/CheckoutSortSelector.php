<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class CheckoutSortSelector {
	public function __construct(
		private CheckoutSessionManager $session_manager,
		private SettingsRepository $settings
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_review_order_before_shipping', array( $this, 'render' ), 5 );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_update_order_review' ), 10, 1 );
		add_action( 'wp_loaded', array( $this, 'capture_posted_selection' ) );
	}

	public function capture_update_order_review( string $posted_data ): void {
		parse_str( $posted_data, $data );
		$this->capture( is_array( $data ) ? $data : array() );
	}

	public function capture_posted_selection(): void {
		$this->capture( $_POST );
	}

	public function render(): void {
		$current = $this->current_sort_mode();

		echo '<tr class="wdc-checkout-sort-row"><th>' . esc_html__( 'Сортировка доставки', 'walls-delivery-calc' ) . '</th><td>';
		echo '<select class="wdc-checkout-sort" name="wdc_platform_checkout_sort_mode">';
		echo '<option value="' . esc_attr( RateSorter::CHEAPEST ) . '" ' . selected( $current, RateSorter::CHEAPEST, false ) . '>' . esc_html__( 'По цене', 'walls-delivery-calc' ) . '</option>';
		echo '<option value="' . esc_attr( RateSorter::FASTEST ) . '" ' . selected( $current, RateSorter::FASTEST, false ) . '>' . esc_html__( 'По сроку', 'walls-delivery-calc' ) . '</option>';
		echo '</select>';
		echo '</td></tr>';
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function capture( array $data ): void {
		$mode = isset( $data['wdc_platform_checkout_sort_mode'] ) ? sanitize_key( wp_unslash( (string) $data['wdc_platform_checkout_sort_mode'] ) ) : '';
		if ( in_array( $mode, array( RateSorter::CHEAPEST, RateSorter::FASTEST ), true ) ) {
			$previous = $this->session_manager->selected_sort_mode();
			$this->session_manager->save_sort_mode( $mode );
			if ( $previous !== $mode ) {
				$this->clear_shipping_rate_cache();
			}
		}
	}

	private function current_sort_mode(): string {
		$session_mode = $this->session_manager->selected_sort_mode();
		$mode         = '' !== $session_mode ? $session_mode : $this->settings->get_string( 'checkout_sort_mode', RateSorter::CHEAPEST );

		return RateSorter::FASTEST === $mode ? RateSorter::FASTEST : RateSorter::CHEAPEST;
	}

	private function clear_shipping_rate_cache(): void {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->session ) || ! is_object( WC()->session ) ) {
			return;
		}

		$session = WC()->session;
		for ( $index = 0; $index < 20; $index++ ) {
			$key = 'shipping_for_package_' . $index;
			if ( method_exists( $session, '__unset' ) ) {
				$session->__unset( $key );
				continue;
			}

			if ( method_exists( $session, 'set' ) ) {
				$session->set( $key, null );
			}
		}
	}
}
