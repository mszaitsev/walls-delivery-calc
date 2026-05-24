<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\ValueObjects;

final class RuleConditionTypes {
	public const ORDER_TOTAL    = 'order_total';
	public const ITEMS_COUNT    = 'items_count';
	public const PAYMENT_METHOD = 'payment_method';
	public const CITY           = 'city';
	public const COUNTRY        = 'country';
	public const DELIVERY_TYPE  = 'delivery_type';
	public const DELIVERY_PRICE = 'delivery_price';
	public const WEIGHT         = 'weight';
	public const DIMENSIONS     = 'dimensions';
	public const VOLUME         = 'volume';
	public const DAY_OF_WEEK    = 'day_of_week';
	public const DAY_OF_MONTH   = 'day_of_month';
	public const MONTH          = 'month';
	public const DATE           = 'date';

	/**
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array(
			self::ORDER_TOTAL,
			self::ITEMS_COUNT,
			self::PAYMENT_METHOD,
			self::CITY,
			self::COUNTRY,
			self::DELIVERY_TYPE,
			self::DELIVERY_PRICE,
			self::WEIGHT,
			self::DIMENSIONS,
			self::VOLUME,
			self::DAY_OF_WEEK,
			self::DAY_OF_MONTH,
			self::MONTH,
			self::DATE,
		);
	}

	public static function is_valid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}
}
