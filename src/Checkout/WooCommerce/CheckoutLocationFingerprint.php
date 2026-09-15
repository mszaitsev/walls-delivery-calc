<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class CheckoutLocationFingerprint {
	/**
	 * @param array<string,mixed> $context
	 */
	public function fingerprint( array $context ): string {
		$context = $this->normalize_location_aliases( $context );
		$country = $this->normalized_location_value( $context['country_code'] ?? '' );
		$prefix = '' !== $country ? 'country=' . strtoupper( $country ) . '|' : '';
		foreach ( array( 'location_id', 'fias_id', 'gar_object_id' ) as $key ) {
			$value = $this->normalized_location_value( $context[ $key ] ?? '' );
			if ( 'location_id' === $key && ( '' === $value || (int) $value <= 0 ) ) {
				continue;
			}
			if ( '' !== $value ) {
				return $prefix . $key . '=' . $value;
			}
		}

		$city = $this->normalized_location_value( $context['city_name'] ?? '' );
		$region = $this->normalized_location_value( $context['region_name'] ?? '' );
		if ( '' !== $city || '' !== $region ) {
			return $prefix . 'place=' . $region . '|' . $city;
		}

		$postcode = $this->normalized_location_value( $context['postcode'] ?? '' );
		return '' !== $postcode ? $prefix . 'postcode=' . $postcode : '';
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function normalize_location_aliases( array $context ): array {
		$aliases = array(
			'country_code' => array( 'country_code', 'country' ),
			'fias_id' => array( 'fias_id', 'city_fias_id', 'fias_location_guid' ),
			'gar_object_id' => array( 'gar_object_id', 'gar_id' ),
			'city_name' => array( 'city_name', 'settlement_name', 'place_name', 'city' ),
			'region_name' => array( 'region_name', 'state_value', 'region' ),
			'postcode' => array( 'postcode', 'postal_code' ),
		);
		foreach ( $aliases as $target => $sources ) {
			if ( '' !== trim( (string) ( $context[ $target ] ?? '' ) ) ) {
				continue;
			}
			foreach ( $sources as $source ) {
				$value = trim( (string) ( $context[ $source ] ?? '' ) );
				if ( '' !== $value ) {
					$context[ $target ] = $value;
					break;
				}
			}
		}

		return $context;
	}

	private function normalized_location_value( mixed $value ): string {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );

		return preg_replace( '/\s+/u', ' ', $value ) ?: $value;
	}
}
