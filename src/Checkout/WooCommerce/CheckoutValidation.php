<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Domain\Quote\DeliveryType;

defined( 'ABSPATH' ) || exit;

final class CheckoutValidation {
	public function __construct(
		private CheckoutSessionManager $session_manager
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 20, 2 );
	}

	public function validate( mixed $data = array(), mixed $errors = null ): void {
		if ( DeliveryType::PICKUP !== $this->selected_delivery_type() ) {
			return;
		}

		$selection = $this->session_manager->pickup_selection();
		if ( '' !== trim( (string) ( $selection['point_code'] ?? '' ) ) ) {
			return;
		}

		if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'wdc_pickup_required', __( 'Please select a pickup point.', 'walls-delivery-calc' ) );
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Please select a pickup point.', 'walls-delivery-calc' ), 'error' );
		}
	}

	private function selected_delivery_type(): string {
		$selected = $this->session_manager->selected_delivery_type();
		if ( '' !== $selected ) {
			return $selected;
		}

		$rates = $this->session_manager->rates();
		foreach ( $this->chosen_shipping_methods() as $rate_id ) {
			if ( isset( $rates[ $rate_id ] ) ) {
				return (string) ( $rates[ $rate_id ]['delivery_type'] ?? '' );
			}

			if ( str_starts_with( $rate_id, NewShippingMethod::METHOD_ID . ':' ) ) {
				$normalized = substr( $rate_id, strlen( NewShippingMethod::METHOD_ID . ':' ) );
				if ( isset( $rates[ $normalized ] ) ) {
					return (string) ( $rates[ $normalized ]['delivery_type'] ?? '' );
				}
			}
		}

		return '';
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
