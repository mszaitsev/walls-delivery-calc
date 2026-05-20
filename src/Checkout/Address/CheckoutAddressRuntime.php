<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

use WallsShop\WDC\Checkout\Locations\CheckoutCityResolver;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Domain\Address\AddressNormalizationResult;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class CheckoutAddressRuntime {
	public function __construct(
		private CheckoutAddressNormalizer $normalizer,
		private CheckoutCityResolver $city_resolver,
		private CheckoutSessionManager $session_manager
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'update_order_review' ), 10, 1 );
	}

	public function update_order_review( mixed $posted_data ): void {
		$data = array();
		if ( is_string( $posted_data ) ) {
			parse_str( $posted_data, $data );
		} elseif ( is_array( $posted_data ) ) {
			$data = $posted_data;
		}

		$this->resolve_checkout_address( $data );
	}

	/**
	 * @param array<string,mixed> $checkoutData
	 */
	public function resolve_checkout_address( array $checkoutData ): AddressNormalizationResult {
		$context  = $this->context_from_checkout_data( $checkoutData );
		$raw      = $this->raw_address( $context );
		$result   = $this->normalizer->normalize( $raw, $context );
		$location = $this->city_resolver->resolve_city( (string) $context['city'] );

		if ( $location instanceof Location ) {
			$this->session_manager->save_selected_city( $location->to_array() );
		} else {
			$this->session_manager->save_selected_city( array() );
		}

		if ( $result->address->fallback ) {
			$this->session_manager->save_fallback_city( (string) $context['city'] );
		} else {
			$this->session_manager->save_fallback_city( '' );
		}

		$this->session_manager->save_normalized_address_result( $result );

		return $result;
	}

	/**
	 * @param array<string,mixed> $checkoutData
	 * @return array<string,string>
	 */
	private function context_from_checkout_data( array $checkoutData ): array {
		$country   = $this->value( $checkoutData, 'shipping_country', 'country', 'RU' );
		$city      = $this->value( $checkoutData, 'shipping_city', 'city', '' );
		$postcode  = $this->value( $checkoutData, 'shipping_postcode', 'postcode', '' );
		$address_1 = $this->value( $checkoutData, 'shipping_address_1', 'address_1', '' );
		$address_2 = $this->value( $checkoutData, 'shipping_address_2', 'address_2', '' );
		if ( '' === $address_1 ) {
			$address_1 = $this->value( $checkoutData, 'address', 'street', '' );
		}

		if ( '' === $postcode && '' !== $city ) {
			$postcode = (string) ( $this->city_resolver->resolve_postcode( $city ) ?? '' );
		}

		return array(
			'country_code' => strtoupper( $country ),
			'city'         => $city,
			'postcode'     => $postcode,
			'address_1'    => $address_1,
			'address_2'    => $address_2,
		);
	}

	/**
	 * @param array<string,string> $context
	 */
	private function raw_address( array $context ): string {
		return trim(
			implode(
				' ',
				array_filter(
					array(
						$context['country_code'],
						$context['postcode'],
						$context['city'],
						$context['address_1'],
						$context['address_2'],
					),
					static fn ( string $value ): bool => '' !== trim( $value )
				)
			)
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function value( array $data, string $primary, string $secondary, string $default ): string {
		$value = $data[ $primary ] ?? $data[ $secondary ] ?? $default;
		if ( is_array( $value ) ) {
			return '';
		}

		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
		$value = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );

		return trim( $value );
	}
}
