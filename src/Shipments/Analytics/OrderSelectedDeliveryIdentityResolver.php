<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;

defined( 'ABSPATH' ) || exit;

final class OrderSelectedDeliveryIdentityResolver {
	public function resolve( object $order ): ?OrderSelectedDeliveryIdentity {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return null;
		}
		$value = $order->get_meta( OrderShippingMetaPersister::CALCULATION_META_KEY, true );
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );
			$value = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $value ) ) {
			return null;
		}

		$carrier_key = $this->first_string(
			$value['carrier_key'] ?? null,
			$value['carrier']['carrier_key'] ?? null,
			$value['carrier']['key'] ?? null,
			$value['service']['carrier_key'] ?? null,
			$value['rate']['carrier_key'] ?? null,
			$value['result']['carrier_key'] ?? null
		);
		if ( '' === $carrier_key ) {
			return null;
		}

		return new OrderSelectedDeliveryIdentity(
			$this->normalize_key( $carrier_key ),
			$this->normalize_key(
				$this->first_string(
					$value['service_key'] ?? null,
					$value['carrier']['service_key'] ?? null,
					$value['service']['service_key'] ?? null,
					$value['service']['key'] ?? null,
					$value['rate']['service_key'] ?? null,
					$value['result']['service_key'] ?? null
				)
			)
		);
	}

	private function first_string( mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}

		return '';
	}

	private function normalize_key( string $value ): string {
		return function_exists( 'sanitize_key' )
			? sanitize_key( $value )
			: strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ?? '' );
	}
}
