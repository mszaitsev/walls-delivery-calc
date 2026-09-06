<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\SelfPickup;

use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;

defined( 'ABSPATH' ) || exit;

final class SelfPickupDiscountService {
	public function __construct(
		private DeliveryServiceRepository $services,
		private SelfPickupSettings $settings,
		private SelfPickupDiscountPolicy $policy,
		private CheckoutSessionManager $session_manager
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply' ), 20, 1 );
	}

	public function apply( mixed $cart ): void {
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'add_fee' ) || ! $this->cart_needs_shipping( $cart ) ) {
			return;
		}
		$service = $this->services->find_by_service_key( SelfPickupSettings::SERVICE_KEY );
		if ( null === $service || null === $service->id ) {
			return;
		}
		$result = $this->policy->calculate(
			$this->self_pickup_selected(),
			$this->cart_item_total_after_coupons_kopecks( $cart ),
			$this->settings->discount_policy( (int) $service->id )
		);
		if ( ! $result->eligible || $result->amount_kopecks <= 0 ) {
			return;
		}
		$label = trim( $result->fee_label );
		if ( '' === $label ) {
			$label = SelfPickupSettings::DEFAULT_DISCOUNT_FEE_LABEL;
		}
		$cart->add_fee( $label, -1 * ( $result->amount_kopecks / 100 ), false );
	}

	public function current_discount_result( mixed $cart = null ): SelfPickupDiscountResult {
		$service = $this->services->find_by_service_key( SelfPickupSettings::SERVICE_KEY );
		if ( null === $service || null === $service->id ) {
			return new SelfPickupDiscountResult( false, 0, 0.0, 0, SelfPickupSettings::DEFAULT_DISCOUNT_FEE_LABEL );
		}
		return $this->policy->calculate(
			$this->self_pickup_selected(),
			is_object( $cart ) ? $this->cart_item_total_after_coupons_kopecks( $cart ) : $this->cart_item_total_after_coupons_kopecks( $this->cart() ),
			$this->settings->discount_policy( (int) $service->id )
		);
	}

	private function self_pickup_selected(): bool {
		foreach ( $this->chosen_shipping_methods() as $method_id ) {
			$method_id = $this->session_manager->normalize_rate_id( $method_id );
			$rates = $this->session_manager->rates();
			$rate = $rates[ $method_id ] ?? array();
			if ( SelfPickupSettings::CARRIER_KEY === (string) ( $rate['carrier_key'] ?? '' ) && SelfPickupSettings::SERVICE_KEY === (string) ( $rate['service_key'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/** @return array<int,string> */
	private function chosen_shipping_methods(): array {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) && is_object( WC()->session ) && method_exists( WC()->session, 'get' ) ) {
			$chosen = WC()->session->get( 'chosen_shipping_methods', array() );
			return is_array( $chosen ) ? array_values( array_map( 'strval', $chosen ) ) : array();
		}

		return array();
	}

	private function cart_item_total_after_coupons_kopecks( mixed $cart ): int {
		if ( ! is_object( $cart ) ) {
			return 0;
		}
		$total = 0.0;
		if ( method_exists( $cart, 'get_cart_contents_total' ) ) {
			$total += (float) $cart->get_cart_contents_total();
			return max( 0, (int) round( $total * 100 ) );
		}
		if ( method_exists( $cart, 'get_subtotal' ) ) {
			$total += (float) $cart->get_subtotal();
		}
		if ( method_exists( $cart, 'get_discount_total' ) ) {
			$total -= (float) $cart->get_discount_total();
		}
		return max( 0, (int) round( $total * 100 ) );
	}

	private function cart_needs_shipping( mixed $cart ): bool {
		if ( is_object( $cart ) && method_exists( $cart, 'needs_shipping' ) ) {
			return (bool) $cart->needs_shipping();
		}
		return true;
	}

	private function cart(): mixed {
		return function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->cart ) ? WC()->cart : null;
	}
}
