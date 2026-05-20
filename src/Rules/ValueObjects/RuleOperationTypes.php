<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\ValueObjects;

final class RuleOperationTypes {
	public const INCREASE = 'increase';
	public const DECREASE = 'decrease';
	public const EQUALS   = 'equals';

	/**
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array( self::INCREASE, self::DECREASE, self::EQUALS );
	}

	public static function is_valid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}
}
