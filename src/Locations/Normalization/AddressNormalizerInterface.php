<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Normalization;

use WallsShop\WDC\Domain\Address\AddressNormalizationResult;

defined( 'ABSPATH' ) || exit;

interface AddressNormalizerInterface {
	/**
	 * @param array<string,mixed> $context
	 */
	public function normalize( string $input, array $context = array() ): AddressNormalizationResult;
}
