<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\ValueObjects;

final class RuleActionTypes {
	public const CHANGE_PRICE         = 'change_price';
	public const CHANGE_DELIVERY_DAYS = 'change_delivery_days';
	public const ADD_COMMENT          = 'add_comment';
	public const DISABLE_RATE         = 'disable_rate';

	/**
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array( self::CHANGE_PRICE, self::CHANGE_DELIVERY_DAYS, self::ADD_COMMENT, self::DISABLE_RATE );
	}

	public static function is_valid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}
}
