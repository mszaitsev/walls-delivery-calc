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
		if ( ! $this->enabled() || $this->wdc_rates_count() < 2 ) {
			return;
		}

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
		if ( ! $this->enabled() ) {
			$this->current_sort_mode();
			return;
		}
		$mode = isset( $data['wdc_platform_checkout_sort_mode'] ) ? sanitize_key( wp_unslash( (string) $data['wdc_platform_checkout_sort_mode'] ) ) : '';
		if ( in_array( $mode, array( RateSorter::CHEAPEST, RateSorter::FASTEST ), true ) ) {
			$this->apply_mode( $mode );
		}
	}

	private function enabled(): bool {
		return $this->settings->get_bool( 'checkout_sort_selector_enabled', true );
	}

	public function current_sort_mode(): string {
		$session_mode = $this->session_manager->selected_sort_mode();
		$mode = $this->enabled() && '' !== $session_mode ? $session_mode : $this->settings->get_string( 'checkout_sort_mode', RateSorter::CHEAPEST );
		$mode = RateSorter::FASTEST === $mode ? RateSorter::FASTEST : RateSorter::CHEAPEST;
		if ( ! $this->enabled() ) {
			$this->apply_mode( $mode );
		}
		return $mode;
	}

	private function apply_mode( string $mode ): void {
		$previous = $this->session_manager->selected_sort_mode();
		if ( $previous === $mode ) {
			return;
		}
		if ( in_array( $previous, array( RateSorter::CHEAPEST, RateSorter::FASTEST ), true ) ) {
			$this->session_manager->reset_selections_for_sort_change();
		}
		$this->session_manager->save_sort_mode( $mode );
		$this->clear_shipping_rate_cache();
	}

	private function clear_shipping_rate_cache(): void {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->session ) || ! is_object( WC()->session ) ) {
			return;
		}

		$session = WC()->session;
		$keys = array_map( static fn( int $index ): string => 'shipping_for_package_' . $index, range( 0, 19 ) );
		if ( method_exists( $session, 'get_session_data' ) ) {
			$data = $session->get_session_data();
			$keys = array_filter( array_keys( is_array( $data ) ? $data : array() ), static fn( mixed $key ): bool => is_string( $key ) && str_starts_with( $key, 'shipping_for_package_' ) );
		}
		foreach ( $keys as $key ) {
			if ( method_exists( $session, '__unset' ) ) {
				$session->__unset( $key );
				continue;
			}

			if ( method_exists( $session, 'set' ) ) {
				$session->set( $key, null );
			}
		}
	}

	private function wdc_rates_count(): int {
		$count = 0;
		foreach ( $this->shipping_packages() as $package ) {
			if ( ! is_array( $package ) ) {
				continue;
			}
			$rates = is_array( $package['rates'] ?? null ) ? $package['rates'] : array();
			foreach ( $rates as $rate ) {
				if ( array() !== $this->rate_meta( $rate ) ) {
					$count++;
				}
			}
		}

		return $count;
	}

	/**
	 * @return array<int|string,mixed>
	 */
	private function shipping_packages(): array {
		if ( function_exists( 'WC' ) && is_object( WC() ) && method_exists( WC(), 'shipping' ) && is_object( WC()->shipping() ) && method_exists( WC()->shipping(), 'get_packages' ) ) {
			$packages = WC()->shipping()->get_packages();
			return is_array( $packages ) ? $packages : array();
		}

		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->session ) || ! is_object( WC()->session ) ) {
			return array();
		}

		$packages = array();
		for ( $index = 0; $index < 20; $index++ ) {
			$package = method_exists( WC()->session, 'get' ) ? WC()->session->get( 'shipping_for_package_' . $index ) : null;
			if ( is_array( $package ) ) {
				$packages[] = $package;
			}
		}

		return $packages;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function rate_meta( mixed $rate ): array {
		if ( is_object( $rate ) && method_exists( $rate, 'get_meta_data' ) ) {
			$meta = $rate->get_meta_data();
		} elseif ( is_object( $rate ) && isset( $rate->meta_data ) ) {
			$meta = $rate->meta_data;
		} elseif ( is_array( $rate ) && isset( $rate['meta_data'] ) ) {
			$meta = $rate['meta_data'];
		} else {
			$meta = array();
		}

		return is_array( $meta ) && isset( $meta['carrier_key'] ) ? $meta : array();
	}
}
