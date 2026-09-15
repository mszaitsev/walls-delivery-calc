<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\ValueObjects;

final class RuleOperators {
	public const EQ           = 'eq';
	public const NEQ          = 'neq';
	public const GT           = 'gt';
	public const GTE          = 'gte';
	public const LT           = 'lt';
	public const LTE          = 'lte';
	public const IN           = 'in';
	public const NOT_IN       = 'not_in';
	public const CONTAINS     = 'contains';
	public const NOT_CONTAINS = 'not_contains';

	/**
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array( self::EQ, self::NEQ, self::GT, self::GTE, self::LT, self::LTE, self::IN, self::NOT_IN, self::CONTAINS, self::NOT_CONTAINS );
	}

	public static function is_valid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}
}
