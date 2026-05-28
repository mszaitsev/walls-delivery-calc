<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
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
			return;
		}

		$delivery_type = (string) ( $rate['delivery_type'] ?? '' );
		$this->validate_city( $delivery_type, $data, $errors );

		if ( DeliveryType::PICKUP !== $delivery_type ) {
			return;
		}

		if ( $this->rate_skips_pickup_selection( $rate ) ) {
			return;
		}

		if ( $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), (string) ( $rate['rate_id'] ?? '' ) ) ) {
			return;
		}

		$this->add_pickup_error( $errors, $rate );
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

	/**
	 * @param array<string,mixed> $rate
	 */
	private function add_pickup_error( mixed $errors = null, array $rate = array() ): void {
		$message = RussianPostDomesticSettings::PICKUP_SERVICE_KEY === (string) ( $rate['service_key'] ?? '' )
			? __( 'Выберите пункт выдачи Почты России.', 'walls-delivery-calc' )
			: __( 'Р’С‹Р±РµСЂРёС‚Рµ РїСѓРЅРєС‚ РІС‹РґР°С‡Рё.', 'walls-delivery-calc' );
		if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'wdc_pickup_required', $message );
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, 'error' );
		}
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function rate_skips_pickup_selection( array $rate ): bool {
		if ( ! empty( $rate['no_pickup_selection'] ) ) {
			return true;
		}

		$meta = $rate['rate_meta'] ?? array();
		if ( is_array( $meta ) && ! empty( $meta['no_pickup_selection'] ) ) {
			return true;
		}

		return false;
	}

	private function add_city_error( mixed $errors = null ): void {
		if ( is_object( $errors ) && method_exists( $errors, 'add' ) ) {
			$errors->add( 'wdc_city_required', __( 'Р’РІРµРґРёС‚Рµ РЅР°СЃРµР»РµРЅРЅС‹Р№ РїСѓРЅРєС‚.', 'walls-delivery-calc' ) );
			return;
		}

		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( __( 'Р’РІРµРґРёС‚Рµ РЅР°СЃРµР»РµРЅРЅС‹Р№ РїСѓРЅРєС‚.', 'walls-delivery-calc' ), 'error' );
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
