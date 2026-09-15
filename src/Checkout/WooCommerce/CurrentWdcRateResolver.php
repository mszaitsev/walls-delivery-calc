<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CurrentWdcRateResolver {
	public function __construct( private CheckoutSessionManager $session_manager ) {
	}

	/**
	 * Resolve the WDC rate represented by the current WooCommerce session state.
	 *
	 * @return array<string,mixed>
	 */
	public function resolve(): array {
		$rates = $this->session_manager->rates();
		foreach ( $this->chosen_shipping_methods() as $rate_id ) {
			if ( isset( $rates[ $rate_id ] ) && is_array( $rates[ $rate_id ] ) ) {
				return $rates[ $rate_id ];
			}

			if ( ! str_starts_with( $rate_id, NewShippingMethod::METHOD_ID . ':' ) ) {
				continue;
			}

			$normalized = substr( $rate_id, strlen( NewShippingMethod::METHOD_ID . ':' ) );
			if ( isset( $rates[ $normalized ] ) && is_array( $rates[ $normalized ] ) ) {
				return $rates[ $normalized ];
			}
		}

		return array();
	}

	/** @return array<int,string> */
	private function chosen_shipping_methods(): array {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) && is_object( WC()->session ) && method_exists( WC()->session, 'get' ) ) {
			$chosen = WC()->session->get( 'chosen_shipping_methods', array() );

			return is_array( $chosen ) ? array_map( 'strval', $chosen ) : array();
		}

		return array();
	}
}
