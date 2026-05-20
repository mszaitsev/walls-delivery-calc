<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\ValueObjects;

final class RuleOperationBases {
	public const RUBLES                        = 'rubles';
	public const PERCENT_OF_DELIVERY          = 'percent_of_delivery';
	public const PERCENT_OF_ORDER             = 'percent_of_order';
	public const PERCENT_OF_ORDER_AND_DELIVERY = 'percent_of_order_and_delivery';

	/**
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array( self::RUBLES, self::PERCENT_OF_DELIVERY, self::PERCENT_OF_ORDER, self::PERCENT_OF_ORDER_AND_DELIVERY );
	}

	public static function is_valid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}
}
