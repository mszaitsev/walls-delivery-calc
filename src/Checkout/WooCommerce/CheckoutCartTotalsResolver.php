<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Domain\Common\Money;

defined( 'ABSPATH' ) || exit;

final class CheckoutCartTotalsResolver {
	public function all_cart_items_total(): Money {
		$cart = $this->cart();
		if ( is_object( $cart ) && method_exists( $cart, 'get_cart_contents_total' ) ) {
			return Money::from_rubles( (float) $cart->get_cart_contents_total() );
		}

		return Money::from_kopecks( 0 );
	}

	public function shippable_cart_items_total(): Money {
		$total_kopecks = 0;
		foreach ( $this->shipping_packages() as $package ) {
			if ( ! is_array( $package ) || ! array_key_exists( 'contents_cost', $package ) ) {
				continue;
			}
			$total_kopecks += Money::from_rubles( (float) $package['contents_cost'] )->get_kopecks();
		}

		return Money::from_kopecks( $total_kopecks );
	}

	private function cart(): mixed {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->cart ) ) {
			return null;
		}

		return WC()->cart;
	}

	/**
	 * @return array<int|string,mixed>
	 */
	private function shipping_packages(): array {
		$cart = $this->cart();
		if ( is_object( $cart ) && method_exists( $cart, 'get_shipping_packages' ) ) {
			$packages = $cart->get_shipping_packages();
			return is_array( $packages ) ? $packages : array();
		}

		if ( function_exists( 'WC' ) && is_object( WC() ) && method_exists( WC(), 'shipping' ) && is_object( WC()->shipping() ) && method_exists( WC()->shipping(), 'get_packages' ) ) {
			$packages = WC()->shipping()->get_packages();
			return is_array( $packages ) ? $packages : array();
		}

		return array();
	}
}
