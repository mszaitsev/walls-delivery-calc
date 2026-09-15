<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Services;

defined( 'ABSPATH' ) || exit;

final class CheckoutPostcode {
	private const MISSING_POSTCODE_SENTINEL = '999999999';

	public static function usable_value( string $postcode ): string {
		$postcode = trim( $postcode );

		return '' !== $postcode && self::MISSING_POSTCODE_SENTINEL !== $postcode ? $postcode : '';
	}
}
