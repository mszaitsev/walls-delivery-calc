<?php
declare(strict_types=1);

namespace WallsShop\WDC\Calendar\Services;

use DateTimeInterface;
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

	private function calculate_carrier_date( string $handoff_date, int $days, string $unit ): string {
		if ( DateRange::UNIT_WORKING_DAYS === $unit ) {
			return $this->calendar->add_working_days( CalendarTypes::CARRIER_RU, $handoff_date, $days );
		}

		return $this->calendar->add_calendar_days( $handoff_date, $days );
	}
}
