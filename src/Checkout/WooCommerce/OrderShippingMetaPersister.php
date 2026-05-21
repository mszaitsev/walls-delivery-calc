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
			$address_fallback_used = $address->address->fallback || ! $address->address->normalized;
			$map['_wdc_platform_normalized']           = $address->address->normalized;
			$map['_wdc_platform_normalization_source'] = $address->source;
			$map['_wdc_platform_fallback_city']        = $this->session_manager->fallback_city();
			$map['_wdc_platform_fallback_address']     = $address_fallback_used ? $address->address->raw_address : '';
			$map['_wdc_platform_address_fallback_used'] = $address_fallback_used;
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
			$this->set_pickup_shipping_address( $order, $pickup, $address );
		}

		foreach ( $map as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
	}

	/**
	 * @param array<string,mixed> $pickup
	 */
	private function set_pickup_shipping_address( object $order, array $pickup, mixed $address_result ): void {
		$address = is_object( $address_result ) && isset( $address_result->address ) ? $address_result->address : null;
		$this->call_order_setter( $order, 'set_shipping_address_1', (string) ( $pickup['point_address'] ?? '' ) );
		$this->call_order_setter( $order, 'set_shipping_address_2', '' !== (string) ( $pickup['point_code'] ?? '' ) ? 'Код ПВЗ: ' . (string) $pickup['point_code'] : '' );
		$this->call_order_setter( $order, 'set_shipping_city', is_object( $address ) ? (string) ( $address->settlement ?: $address->city ) : '' );
		$this->call_order_setter( $order, 'set_shipping_postcode', is_object( $address ) ? (string) $address->postcode : '' );
		$this->call_order_setter( $order, 'set_shipping_country', is_object( $address ) && '' !== (string) $address->country_code ? (string) $address->country_code : 'RU' );
	}

	private function call_order_setter( object $order, string $method, string $value ): void {
		if ( '' !== $value && method_exists( $order, $method ) ) {
			$order->{$method}( $value );
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
