<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Validation;

use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;

defined( 'ABSPATH' ) || exit;

final class CheckoutAddressValidation {
	public function __construct(
		private CheckoutSessionManager $session_manager
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function has_city( array $data ): bool {
		if ( '' !== $this->city_from_data( $data ) ) {
			return true;
		}

		$result = $this->session_manager->normalized_address_result();
		if ( null === $result ) {
			return false;
		}

		return $result->address->has_city();
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function city_from_data( array $data ): string {
		$value = $data['shipping_city'] ?? $data['city'] ?? '';
		if ( is_array( $value ) ) {
			return '';
		}

		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );

		return trim( $value );
	}
}
