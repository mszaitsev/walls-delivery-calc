<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class WooCommerceSessionBootstrapper {
	public function ensure(): bool {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) ) {
			return false;
		}

		$woocommerce = WC();
		if ( ! isset( $woocommerce->session ) || ! is_object( $woocommerce->session ) ) {
			if ( ! class_exists( '\WC_Session_Handler' ) ) {
				return false;
			}
			try {
				$session = new \WC_Session_Handler();
				if ( method_exists( $session, 'init' ) ) {
					$session->init();
				}
				$woocommerce->session = $session;
			} catch ( \Throwable ) {
				return false;
			}
		}

		if ( ! isset( $woocommerce->session ) || ! is_object( $woocommerce->session ) ) {
			return false;
		}

		try {
			if ( method_exists( $woocommerce->session, 'set_customer_session_cookie' ) ) {
				$woocommerce->session->set_customer_session_cookie( true );
			}
		} catch ( \Throwable ) {
			return false;
		}

		if ( ( ! isset( $woocommerce->customer ) || ! is_object( $woocommerce->customer ) ) && class_exists( '\WC_Customer' ) ) {
			$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
			try {
				$woocommerce->customer = new \WC_Customer( $user_id, true );
			} catch ( \Throwable ) {
				try {
					$woocommerce->customer = new \WC_Customer( 0, true );
				} catch ( \Throwable ) {
				}
			}
		}

		return isset( $woocommerce->session ) && is_object( $woocommerce->session );
	}
}
