<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Quote;

final class DeliveryType {
	public const PICKUP      = 'pickup';
	public const COURIER     = 'courier';
	public const TERMINAL    = 'terminal';
	public const UNKNOWN     = 'unknown';

	/**
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array( self::PICKUP, self::COURIER, self::TERMINAL, self::UNKNOWN );
	}

	public static function is_valid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}
}
