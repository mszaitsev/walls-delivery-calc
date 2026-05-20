<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

use WallsShop\WDC\Domain\Address\AddressNormalizationResult;
use WallsShop\WDC\Locations\Normalization\AddressNormalizerInterface;

defined( 'ABSPATH' ) || exit;

final class CheckoutAddressNormalizer {
	public function __construct(
		private AddressNormalizerInterface $fias_normalizer,
		private AddressNormalizerInterface $dadata_normalizer,
		private AddressNormalizerInterface $fallback_normalizer
	) {
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function normalize( string $input, array $context = array() ): AddressNormalizationResult {
		$fias = $this->fias_normalizer->normalize( $input, $context );
		if ( $fias->success ) {
			return $fias;
		}

		$dadata = $this->dadata_normalizer->normalize( $input, $context );
		if ( $dadata->success ) {
			return $dadata;
		}

		return $this->fallback_normalizer->normalize( $input, $context );
	}
}
