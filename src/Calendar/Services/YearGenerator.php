<?php
declare(strict_types=1);

namespace WallsShop\WDC\Calendar\Services;

use DatePeriod;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use WallsShop\WDC\Calendar\CalendarTypes;
use WallsShop\WDC\Domain\Calendar\CalendarDay;

defined( 'ABSPATH' ) || exit;

final class YearGenerator {
	/**
	 * @return array<int, CalendarDay>
	 */
	public function generate_year( string $calendar_type, int $year ): array {
		if ( ! CalendarTypes::is_valid( $calendar_type ) ) {
			throw new InvalidArgumentException( 'Invalid calendar type.' );
		}

		$timezone = new DateTimeZone( TimezoneService::TIMEZONE );
		$start    = new DateTimeImmutable( sprintf( '%d-01-01', $year ), $timezone );
		$end      = $start->modify( '+1 year' );
		$period   = new DatePeriod( $start, new \DateInterval( 'P1D' ), $end );
		$days     = array();

		foreach ( $period as $date ) {
			$weekday = (int) $date->format( 'N' );
			$working = match ( $calendar_type ) {
				CalendarTypes::CARRIER_RU => $weekday < 6,
				CalendarTypes::SHOP => 7 !== $weekday,
				default => true,
			};

			$days[] = new CalendarDay(
				$date->format( 'Y-m-d' ),
				$working,
				$working ? 'weekday' : 'weekend',
				$calendar_type
			);
		}

		return $days;
	}
}
