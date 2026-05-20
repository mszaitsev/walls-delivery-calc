<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

use WallsShop\WDC\Checkout\Locations\CheckoutCityResolver;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Address\AddressNormalizationResult;
use WallsShop\WDC\Locations\Normalization\AddressNormalizerInterface;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class FiasAddressNormalizer implements AddressNormalizerInterface {
	public function __construct(
		private CheckoutCityResolver $city_resolver
	) {
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function normalize( string $input, array $context = array() ): AddressNormalizationResult {
		$city_input = trim( (string) ( $context['city'] ?? '' ) );
		if ( '' === $city_input ) {
			$city_input = $input;
		}

		$location = $this->city_resolver->resolve_city( $city_input );
		if ( ! $location instanceof Location ) {
			return new AddressNormalizationResult(
				$input,
				$this->address_from_context( $input, $context ),
				false,
				0.0,
				'fias',
				'location_not_found',
				'Local FIAS stub could not match the checkout city.'
			);
		}

		$postcode = trim( $location->postcode );
		if ( '' === $postcode ) {
			$postcode = trim( (string) ( $context['postcode'] ?? '' ) );
		}

		$address = new Address(
			country_code: '' !== $location->country_code ? $location->country_code : (string) ( $context['country_code'] ?? '' ),
			region_name: $location->region_name,
			region_code: $location->region_code,
			city: $location->city_name,
			settlement: $location->settlement_name,
			postcode: $postcode,
			street: (string) ( $context['address_1'] ?? '' ),
			house: (string) ( $context['address_2'] ?? '' ),
			raw_address: $input,
			fias_id: $location->fias_id,
			gar_id: $location->gar_id,
			normalized: true,
			fallback: false
		);

		return new AddressNormalizationResult( $input, $address, true, 0.85, 'fias' );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function address_from_context( string $input, array $context ): Address {
		return new Address(
			country_code: (string) ( $context['country_code'] ?? '' ),
			city: (string) ( $context['city'] ?? '' ),
			postcode: (string) ( $context['postcode'] ?? '' ),
			street: (string) ( $context['address_1'] ?? '' ),
			house: (string) ( $context['address_2'] ?? '' ),
			raw_address: $input,
			normalized: false,
			fallback: false
		);
	}
}
