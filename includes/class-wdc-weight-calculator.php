<?php
/**
 * Package weight calculation.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Weight_Calculator {
	/**
	 * @param array<string, mixed> $package WooCommerce package data.
	 * @param array<int, array<string, mixed>> $packaging_tiers Packaging tiers.
	 * @return array<string, int>
	 */
	public function calculate_package_weight( array $package, array $packaging_tiers = array() ): array {
		$cart_weight_g = 0;
		$contents = isset( $package['contents'] ) && is_array( $package['contents'] ) ? $package['contents'] : array();

		foreach ( $contents as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$quantity = isset( $item['quantity'] ) ? max( 0, (int) $item['quantity'] ) : 1;
			$product_weight_kg = 0.0;

			if ( isset( $item['data'] ) && is_object( $item['data'] ) && method_exists( $item['data'], 'get_weight' ) ) {
				$weight = $item['data']->get_weight();
				$product_weight_kg = is_numeric( $weight ) ? (float) $weight : 0.0;
			}

			$cart_weight_g += (int) round( max( 0, $product_weight_kg ) * 1000 * $quantity );
		}

		$packaging_weight_g = $this->find_packaging_weight( $cart_weight_g, $packaging_tiers );

		return array(
			'cart_weight_g' => $cart_weight_g,
			'packaging_weight_g' => $packaging_weight_g,
			'total_weight_g' => $cart_weight_g + $packaging_weight_g,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $packaging_tiers Packaging tiers.
	 */
	private function find_packaging_weight( int $cart_weight_g, array $packaging_tiers ): int {
		$max_packaging_weight_g = 0;

		foreach ( $packaging_tiers as $tier ) {
			if ( ! is_array( $tier ) ) {
				continue;
			}

			$from_weight_g = isset( $tier['from_weight_g'] ) ? (int) $tier['from_weight_g'] : (int) ( $tier['from_g'] ?? 0 );
			$to_weight_g = isset( $tier['to_weight_g'] ) ? (int) $tier['to_weight_g'] : (int) ( $tier['to_g'] ?? 0 );
			$packaging_weight_g = isset( $tier['packaging_weight_g'] ) ? max( 0, (int) $tier['packaging_weight_g'] ) : 0;
			$max_packaging_weight_g = max( $max_packaging_weight_g, $packaging_weight_g );

			if ( $cart_weight_g >= $from_weight_g && $cart_weight_g <= $to_weight_g ) {
				return $packaging_weight_g;
			}
		}

		return $max_packaging_weight_g;
	}
}
