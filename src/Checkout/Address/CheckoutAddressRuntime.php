<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

use WallsShop\WDC\Checkout\Locations\CheckoutCityResolver;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Domain\Address\Address;
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
		$data = $this->parse_posted_data( $posted_data );
		$this->resolve_checkout_address( $data );
	}

	/**
	 * @param array<string,mixed> $checkoutData
	 */
	public function resolve_checkout_address( array $checkoutData ): AddressNormalizationResult {
		$context     = $this->context_from_checkout_data( $checkoutData );
		$fingerprint = $this->fingerprint_from_context( $context );

		if ( '' !== $this->session_manager->address_fingerprint() && $fingerprint !== $this->session_manager->address_fingerprint() ) {
			$this->session_manager->clear_normalized_address();
			$this->session_manager->clear_pickup_selection();
			$this->clear_shipping_rate_cache();
		}

		$selected = $this->selected_location_from_context( $context );
		if ( array() !== $selected ) {
			$result = $this->result_from_selected_location( $context, $selected );
			$this->session_manager->save_selected_city( $selected );
			$this->session_manager->save_fallback_city( '' );
			$this->session_manager->save_normalized_address_result( $result );
			$this->session_manager->save_address_fingerprint( $fingerprint );

			return $result;
		}

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
		$this->session_manager->save_address_fingerprint( $fingerprint );

		return $result;
	}

	/**
	 * @param array<string,mixed> $checkoutData
	 */
	public function fingerprint_from_checkout_data( array $checkoutData ): string {
		return $this->fingerprint_from_context( $this->context_from_checkout_data( $checkoutData ) );
	}

	/**
	 * @param array<string,mixed> $posted_data
	 * @return array<string,mixed>
	 */
	private function parse_posted_data( mixed $posted_data ): array {
		$data = array();
		if ( is_string( $posted_data ) ) {
			parse_str( $posted_data, $data );
		} elseif ( is_array( $posted_data ) ) {
			$data = $posted_data;
		}

		return $data;
	}

	/**
	 * @param array<string,mixed> $checkoutData
	 * @return array<string,string>
	 */
	private function context_from_checkout_data( array $checkoutData ): array {
		$country   = $this->value( $checkoutData, 'shipping_country', 'billing_country', $this->value( $checkoutData, 'country', 'country', 'RU' ) );
		$city      = $this->value( $checkoutData, 'shipping_city', 'billing_city', $this->value( $checkoutData, 'city', 'city', '' ) );
		$postcode  = $this->value( $checkoutData, 'shipping_postcode', 'billing_postcode', $this->value( $checkoutData, 'postcode', 'postcode', '' ) );
		$address_1 = $this->value( $checkoutData, 'shipping_address_1', 'billing_address_1', $this->value( $checkoutData, 'address', 'street', '' ) );
		$address_2 = $this->value( $checkoutData, 'shipping_address_2', 'billing_address_2', '' );

		$selected_postcode = $this->value( $checkoutData, 'wdc_platform_location_postcode', 'wdc_platform_location_postcode', '' );
		if ( '' !== $selected_postcode ) {
			$postcode = $selected_postcode;
		}

		if ( '' === $postcode && '' !== $city ) {
			$postcode = (string) ( $this->city_resolver->resolve_postcode( $city ) ?? '' );
		}

		return array(
			'country_code'          => strtoupper( $country ),
			'city'                  => $city,
			'postcode'              => $postcode,
			'address_1'             => $address_1,
			'address_2'             => $address_2,
			'selected_location_id'  => $this->value( $checkoutData, 'wdc_platform_location_id', 'wdc_platform_location_id', '' ),
			'selected_fias_id'      => $this->value( $checkoutData, 'wdc_platform_location_fias_id', 'wdc_platform_location_fias_id', '' ),
			'selected_gar_id'       => $this->value( $checkoutData, 'wdc_platform_location_gar_id', 'wdc_platform_location_gar_id', '' ),
			'selected_display_name' => $this->value( $checkoutData, 'wdc_platform_location_display_name', 'wdc_platform_location_display_name', '' ),
			'selected_region_name'  => $this->value( $checkoutData, 'wdc_platform_location_region_name', 'wdc_platform_location_region_name', '' ),
		);
	}

	/**
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	private function selected_location_from_context( array $context ): array {
		if ( '' === $context['selected_location_id'] && '' === $context['selected_fias_id'] && '' === $context['selected_gar_id'] && '' === $context['selected_display_name'] ) {
			return array();
		}

		return array(
			'id'              => $context['selected_location_id'],
			'fias_id'         => $context['selected_fias_id'],
			'gar_id'          => $context['selected_gar_id'],
			'country_code'    => $context['country_code'],
			'region_name'     => $context['selected_region_name'],
			'region_code'     => '',
			'city_name'       => $context['city'],
			'settlement_name' => '',
			'settlement_type' => 'город',
			'display_name'    => $context['selected_display_name'],
			'postcode'        => $context['postcode'],
			'active'          => true,
		);
	}

	/**
	 * @param array<string,string> $context
	 * @param array<string,mixed>  $selected
	 */
	private function result_from_selected_location( array $context, array $selected ): AddressNormalizationResult {
		$city = (string) ( $selected['city_name'] ?? $context['city'] );
		$address = new Address(
			country_code: $context['country_code'],
			region_name: (string) ( $selected['region_name'] ?? '' ),
			city: $city,
			postcode: (string) ( $selected['postcode'] ?? $context['postcode'] ),
			street: $context['address_1'],
			house: $context['address_2'],
			raw_address: $this->raw_address( array_merge( $context, array( 'city' => $city, 'postcode' => (string) ( $selected['postcode'] ?? $context['postcode'] ) ) ) ),
			fias_id: (string) ( $selected['fias_id'] ?? '' ),
			gar_id: (string) ( $selected['gar_id'] ?? '' ),
			normalized: true,
			fallback: false
		);

		return new AddressNormalizationResult( $address->raw_address, $address, true, 1.0, '' !== $address->fias_id ? 'fias' : 'manual' );
	}

	/**
	 * @param array<string,string> $context
	 */
	private function fingerprint_from_context( array $context ): string {
		return sha1(
			implode(
				'|',
				array_map(
					static fn ( string $value ): string => function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $value ), 'UTF-8' ) : strtolower( trim( $value ) ),
					array(
						$context['country_code'],
						$context['city'],
						$context['postcode'],
						$context['address_1'],
						$context['address_2'],
						$context['selected_location_id'],
						$context['selected_fias_id'],
						$context['selected_gar_id'],
						$context['selected_display_name'],
					)
				)
			)
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

	private function clear_shipping_rate_cache(): void {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || ! isset( WC()->session ) || ! is_object( WC()->session ) ) {
			return;
		}

		$session = WC()->session;
		for ( $index = 0; $index < 20; $index++ ) {
			$key = 'shipping_for_package_' . $index;
			if ( method_exists( $session, '__unset' ) ) {
				$session->__unset( $key );
				continue;
			}

			if ( method_exists( $session, 'set' ) ) {
				$session->set( $key, null );
			}
		}
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
