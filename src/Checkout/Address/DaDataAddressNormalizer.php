<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Address\AddressNormalizationResult;
use WallsShop\WDC\Locations\Normalization\AddressNormalizerInterface;

defined( 'ABSPATH' ) || exit;

final class DaDataAddressNormalizer implements AddressNormalizerInterface {
	/**
	 * @param array<string,mixed> $context
	 */
	public function normalize( string $input, array $context = array() ): AddressNormalizationResult {
		$address = new Address(
			country_code: (string) ( $context['country_code'] ?? '' ),
			city: (string) ( $context['city'] ?? '' ),
			postcode: (string) ( $context['postcode'] ?? '' ),
			street: (string) ( $context['address_1'] ?? '' ),
			house: (string) ( $context['address_2'] ?? '' ),
			raw_address: $input,
			normalized: false,
			fallback: false
		);

		return new AddressNormalizationResult(
			$input,
			$address,
			false,
			0.0,
			'dadata',
			'normalizer_not_configured',
			'DaData normalizer is prepared but no API integration is configured.'
		);
	}
}
