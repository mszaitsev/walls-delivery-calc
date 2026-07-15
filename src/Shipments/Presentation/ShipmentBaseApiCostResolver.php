<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Presentation;

use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;

defined( 'ABSPATH' ) || exit;

final class ShipmentBaseApiCostResolver {
	public function resolve_from_order( ?object $order ): ?int {
		if ( null === $order || ! method_exists( $order, 'get_meta' ) ) {
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

		$api = is_array( $value['api'] ?? null ) ? $value['api'] : array();
		foreach ( array( 'api_base_price_kopecks', 'api_base_cost_kopecks', 'base_api_cost_kopecks' ) as $key ) {
			$kopecks = $this->positive_int_or_null( $api[ $key ] ?? $value[ $key ] ?? null );
			if ( null !== $kopecks ) {
				return $kopecks;
			}
		}

		foreach ( array( 'api_base_price_rub', 'api_price_with_vat_rub', 'base_api_cost_rub' ) as $key ) {
			$rubles = $this->numeric_or_null( $api[ $key ] ?? $value[ $key ] ?? null );
			if ( null !== $rubles && $rubles > 0 ) {
				return (int) round( $rubles * 100 );
			}
		}

		return null;
	}

	private function positive_int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) {
			$integer = (int) $value;

			return $integer > 0 ? $integer : null;
		}

		return null;
	}

	private function numeric_or_null( mixed $value ): ?float {
		return is_int( $value ) || is_float( $value ) || ( is_string( $value ) && is_numeric( $value ) ) ? (float) $value : null;
	}
}
