<?php
declare(strict_types=1);

namespace WallsShop\WDC\Calendar;

defined( 'ABSPATH' ) || exit;

final class CalendarTypes {
	public const CARRIER_RU = 'carrier_ru';
	public const SHOP       = 'shop';

	/**
	 * @return array<int, string>
	 */
	public static function all(): array {
		return array(
			self::CARRIER_RU,
			self::SHOP,
		);
	}

	public static function is_valid( string $calendar_type ): bool {
		return in_array( $calendar_type, self::all(), true );
	}
}
