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

		$address = $this->session_manager->normalized_address_result();
		if ( null !== $address ) {
			$map['_wdc_platform_normalized']           = $address->address->normalized;
			$map['_wdc_platform_normalization_source'] = $address->source;
			$map['_wdc_platform_fallback_city']        = $this->session_manager->fallback_city();
			$map['_wdc_platform_fallback_address']     = $address->address->fallback;
			$map['_wdc_platform_resolved_postcode']    = $address->address->postcode;
			$map['_wdc_platform_fias_id']              = $address->address->fias_id;
			$map['_wdc_platform_gar_id']               = $address->address->gar_id;
		}

		$pickup = $this->session_manager->pickup_selection();
		if (
			'pickup' === (string) ( $rate['delivery_type'] ?? '' )
			&& $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), (string) ( $rate['rate_id'] ?? '' ) )
		) {
			$map['_wdc_platform_pickup_code']      = $pickup['point_code'] ?? '';
			$map['_wdc_platform_pickup_address']   = $pickup['point_address'] ?? '';
			$map['_wdc_platform_pickup_comment']   = $pickup['point_comment'] ?? '';
			$map['_wdc_platform_pickup_work_time'] = $pickup['point_work_time'] ?? '';
		}

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

			if ( ! str_starts_with( $rate_id, NewShippingMethod::METHOD_ID . ':' ) ) {
				continue;
			}

			$normalized = substr( $rate_id, strlen( NewShippingMethod::METHOD_ID . ':' ) );
			if ( isset( $rates[ $normalized ] ) ) {
				return $rates[ $normalized ];
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
