<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\ValueObjects;

final class RuleOperationBases {
	public const RUBLES                        = 'rubles';
	public const PERCENT_OF_DELIVERY          = 'percent_of_delivery';
	public const PERCENT_OF_ORDER             = 'percent_of_order';
	public const PERCENT_OF_ORDER_AND_DELIVERY = 'percent_of_order_and_delivery';
	public const CALENDAR_DAYS                = 'calendar_days';
	public const BUSINESS_DAYS                = 'business_days';

	/**
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array( self::RUBLES, self::PERCENT_OF_DELIVERY, self::PERCENT_OF_ORDER, self::PERCENT_OF_ORDER_AND_DELIVERY, self::CALENDAR_DAYS, self::BUSINESS_DAYS );
	}

	/**
	 * @return array<int,string>
	 */
	public static function money_bases(): array {
		return array( self::RUBLES, self::PERCENT_OF_DELIVERY, self::PERCENT_OF_ORDER, self::PERCENT_OF_ORDER_AND_DELIVERY );
	}

	/**
	 * @return array<int,string>
	 */
	public static function day_bases(): array {
		return array( self::CALENDAR_DAYS, self::BUSINESS_DAYS );
	}

	public static function is_valid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}
}
