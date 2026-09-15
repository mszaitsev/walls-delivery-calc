<?php
declare(strict_types=1);

namespace WallsShop\WDC\Calendar\Services;

use DateTimeInterface;
use DateTimeImmutable;
use DateTimeZone;
use WallsShop\WDC\Calendar\CalendarTypes;
use WallsShop\WDC\Domain\Calendar\PlannedDeliveryDate;
use WallsShop\WDC\Domain\Common\DateRange;

defined( 'ABSPATH' ) || exit;

final class DeliveryDateCalculator {
	public function __construct(
		private CalendarService $calendar,
		private TimezoneService $timezone,
		private DeliveryDateFormatter $formatter
	) {
	}

	public function calculate( DateTimeInterface|string|null $order_date, int $processing_days, DateRange $carrier_days ): PlannedDeliveryDate {
		$order_date_value      = $this->timezone->to_local_datetime( $order_date )->format( 'Y-m-d' );
		$effective_order_date = $this->timezone->normalize_order_date( $order_date );
		$handoff_date         = $this->calendar->add_working_days( CalendarTypes::SHOP, $effective_order_date, $processing_days );
		$min_days             = $carrier_days->min_days ?? $carrier_days->max_days ?? 0;
		$max_days             = $carrier_days->max_days ?? $carrier_days->min_days ?? 0;
		$planned_min          = $this->calculate_carrier_date( $handoff_date, $min_days, $carrier_days->unit );
		$planned_max          = $this->calculate_carrier_date( $handoff_date, $max_days, $carrier_days->unit );

		return new PlannedDeliveryDate(
			$order_date_value,
			$effective_order_date,
			$processing_days,
			$handoff_date,
			$carrier_days,
			$planned_min,
			$planned_max,
			$this->formatter->format_comment( $planned_min, $planned_max )
		);
	}

	/**
	 * @return array{calculation_date:string,handoff_date:string,processing_working_days:int,processing_calendar_days:int,carrier_days_are_working:bool,carrier_calendar_days:DateRange,total_calendar_days:DateRange}
	 */
	public function normalize_lead_time( string $calculation_date, int $processing_days, DateRange $carrier_days, bool $carrier_days_are_working ): array {
		$calculation_date = $this->timezone->to_local_datetime( $calculation_date )->format( 'Y-m-d' );
		$processing_days  = max( 0, $processing_days );
		$handoff_date     = $this->calendar->add_working_days( CalendarTypes::SHOP, $calculation_date, $processing_days );
		$processing_calendar_days = $this->calendar_days_between( $calculation_date, $handoff_date );

		if ( $carrier_days->is_empty() ) {
			return array(
				'calculation_date'          => $calculation_date,
				'handoff_date'              => $handoff_date,
				'processing_working_days'   => $processing_days,
				'processing_calendar_days'  => $processing_calendar_days,
				'carrier_days_are_working'  => $carrier_days_are_working,
				'carrier_calendar_days'     => new DateRange(),
				'total_calendar_days'       => new DateRange(),
			);
		}

		$carrier_min = $this->carrier_calendar_days( $handoff_date, $carrier_days->min_days, $carrier_days_are_working );
		$carrier_max = $this->carrier_calendar_days( $handoff_date, $carrier_days->max_days, $carrier_days_are_working );

		return array(
			'calculation_date'          => $calculation_date,
			'handoff_date'              => $handoff_date,
			'processing_working_days'   => $processing_days,
			'processing_calendar_days'  => $processing_calendar_days,
			'carrier_days_are_working'  => $carrier_days_are_working,
			'carrier_calendar_days'     => DateRange::range( $carrier_min, $carrier_max, DateRange::UNIT_CALENDAR_DAYS ),
			'total_calendar_days'       => DateRange::range(
				null !== $carrier_min ? $processing_calendar_days + $carrier_min : null,
				null !== $carrier_max ? $processing_calendar_days + $carrier_max : null,
				DateRange::UNIT_CALENDAR_DAYS
			),
		);
	}

	public function planned_date_from_calendar_days( string $calculation_date, DateRange $days ): string {
		$value = $days->min_days ?? $days->max_days;
		if ( null === $value ) {
			return '';
		}

		return $this->calendar->add_calendar_days( $calculation_date, $value );
	}

	public function calendar_days_between( string $start_date, string $end_date ): int {
		$start = $this->date( $start_date );
		$end   = $this->date( $end_date );

		return max( 0, (int) $start->diff( $end )->format( '%r%a' ) );
	}

	private function calculate_carrier_date( string $handoff_date, int $days, string $unit ): string {
		if ( DateRange::UNIT_WORKING_DAYS === $unit ) {
			return $this->calendar->add_working_days( CalendarTypes::CARRIER_RU, $handoff_date, $days );
		}

		return $this->calendar->add_calendar_days( $handoff_date, $days );
	}

	private function carrier_calendar_days( string $handoff_date, ?int $days, bool $carrier_days_are_working ): ?int {
		if ( null === $days ) {
			return null;
		}
		if ( ! $carrier_days_are_working ) {
			return max( 0, $days );
		}

		$arrival_date = $this->calendar->add_working_days( CalendarTypes::CARRIER_RU, $handoff_date, max( 0, $days ) );

		return $this->calendar_days_between( $handoff_date, $arrival_date );
	}

	private function date( string $date ): DateTimeImmutable {
		return new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( TimezoneService::TIMEZONE ) );
	}
}
