<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Checkout\Validation\CheckoutAddressValidation;
use WallsShop\WDC\Domain\Quote\DeliveryType;

defined( 'ABSPATH' ) || exit;

final class CheckoutValidation {
	public function __construct(
		private CheckoutSessionManager $session_manager,
		private ?CheckoutAddressValidation $address_validation = null
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'validate' ), 20, 2 );
	}

	public function validate( mixed $data = array(), mixed $errors = null ): void {
		$data = is_array( $data ) ? $data : array();
		$rate = $this->selected_rate();
		if ( array() === $rate ) {
			$this->validate_city( $this->session_manager->selected_delivery_type(), $data, $errors );
			if ( DeliveryType::PICKUP === $this->session_manager->selected_delivery_type() && ! $this->has_any_pickup_selection() ) {
				$this->add_pickup_error( $errors );
			}

			return;
		}

		$this->validate_city( (string) ( $rate['delivery_type'] ?? '' ), $data, $errors );

		if ( DeliveryType::PICKUP !== (string) ( $rate['delivery_type'] ?? '' ) ) {
			return;
		}

		if ( $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), (string) ( $rate['rate_id'] ?? '' ) ) ) {
			return;
		}

		$this->add_pickup_error( $errors );
	}

	private function validate_city( string $delivery_type, array $data, mixed $errors = null ): void {
		if ( ! in_array( $delivery_type, array( DeliveryType::PICKUP, DeliveryType::COURIER ), true ) ) {
			return;
		}

		$validator = $this->address_validation ?? new CheckoutAddressValidation( $this->session_manager );
		if ( $validator->has_city( $data ) ) {
			return;
		}

		$this->add_city_error( $errors );
	}

	private function add_pickup_error( mixed $errors = null ): void {
		if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'wdc_pickup_required', __( 'Please select a pickup point.', 'walls-delivery-calc' ) );
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Please select a pickup point.', 'walls-delivery-calc' ), 'error' );
		}
	}

	private function add_city_error( mixed $errors = null ): void {
		if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'wdc_city_required', __( 'Please enter a delivery city.', 'walls-delivery-calc' ) );
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Please enter a delivery city.', 'walls-delivery-calc' ), 'error' );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	private function selected_rate(): array {
		$rates = $this->session_manager->rates();
		foreach ( $this->chosen_shipping_methods() as $rate_id ) {
			if ( isset( $rates[ $rate_id ] ) ) {
				return $rates[ $rate_id ];
			}

			if ( str_starts_with( $rate_id, NewShippingMethod::METHOD_ID . ':' ) ) {
				$normalized = substr( $rate_id, strlen( NewShippingMethod::METHOD_ID . ':' ) );
				if ( isset( $rates[ $normalized ] ) ) {
					return $rates[ $normalized ];
				}
			}
		}

		return array();
	}

	private function has_any_pickup_selection(): bool {
		$selection = $this->session_manager->pickup_selection();

		return '' !== trim( (string) ( $selection['point_code'] ?? '' ) );
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
