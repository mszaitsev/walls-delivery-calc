<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Normalization;

use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Address\AddressNormalizationResult;

defined( 'ABSPATH' ) || exit;

final class FallbackAddressNormalizer implements AddressNormalizerInterface {
	/**
	 * @param array<string,mixed> $context
	 */
	public function normalize( string $input, array $context = array() ): AddressNormalizationResult {
		$address = new Address(
			country_code: (string) ( $context['country_code'] ?? '' ),
			region_name: (string) ( $context['region_name'] ?? '' ),
			city: (string) ( $context['city'] ?? '' ),
			settlement: (string) ( $context['settlement'] ?? '' ),
			postcode: (string) ( $context['postcode'] ?? '' ),
			raw_address: $input,
			normalized: false,
			fallback: true
		);

		return new AddressNormalizationResult( $input, $address, false, 0.0, 'fallback', 'normalizer_unavailable', 'Внешний нормализатор не настроен.' );
	}
}
