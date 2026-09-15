<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Common;

final class DeliveryDaysFormatter {
	public static function format( DateRange $range ): string {
		if ( $range->is_empty() ) {
			return '';
		}

		return self::format_values( $range->min_days, $range->max_days );
	}

	/**
	 * @param array<string,mixed> $days
	 */
	public static function format_array( array $days ): string {
		return self::format_values( $days['min_days'] ?? $days['min'] ?? null, $days['max_days'] ?? $days['max'] ?? null );
	}

	public static function format_values( mixed $min, mixed $max ): string {
		if ( ! is_numeric( $min ) && ! is_numeric( $max ) ) {
			return '';
		}
		$min = is_numeric( $min ) ? max( 0, (int) $min ) : max( 0, (int) $max );
		$max = is_numeric( $max ) ? max( 0, (int) $max ) : $min;
		if ( 0 === $min && 0 === $max ) {
			return '';
		}
		if ( $min === $max ) {
			return $min . ' ' . self::word( $min );
		}

		return $min . '-' . $max . ' ' . self::word( $max );
	}

	private static function word( int $days ): string {
		$mod100 = $days % 100;
		if ( $mod100 >= 11 && $mod100 <= 14 ) {
			return 'дней';
		}
		$mod10 = $days % 10;

		return match ( $mod10 ) {
			1 => 'день',
			2, 3, 4 => 'дня',
			default => 'дней',
		};
	}
}
