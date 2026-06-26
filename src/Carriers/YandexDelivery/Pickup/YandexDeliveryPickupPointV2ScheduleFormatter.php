<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pickup;

use WallsShop\WDC\Pickup\Schedule\CompactWeeklyScheduleFormatter;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPickupPointV2ScheduleFormatter {
	public function __construct( private ?CompactWeeklyScheduleFormatter $formatter = null ) {
		$this->formatter ??= new CompactWeeklyScheduleFormatter();
	}

	public function format( mixed $schedule ): string {
		if ( is_string( $schedule ) ) {
			return $this->formatter->normalize_time_range( $schedule );
		}
		if ( ! is_array( $schedule ) || array() === $schedule ) {
			return '';
		}

		$items = $this->extract_items( $schedule );
		if ( array() !== $items ) {
			return $this->formatter->format_items( $items );
		}

		return '';
	}

	/**
	 * @param array<int|string,mixed> $schedule
	 * @return array<int,array{day:string,work:string,break?:string}>
	 */
	private function extract_items( array $schedule ): array {
		if ( array_is_list( $schedule ) ) {
			$items = array();
			foreach ( $schedule as $row ) {
				array_push( $items, ...$this->extract_row_items( $row ) );
			}

			return $items;
		}

		foreach ( array( 'restrictions', 'working_time', 'workingTime', 'work_time', 'workTime', 'items' ) as $key ) {
			if ( isset( $schedule[ $key ] ) && is_array( $schedule[ $key ] ) ) {
				return $this->extract_items( $schedule[ $key ] );
			}
		}

		return $this->extract_row_items( $schedule );
	}

	/**
	 * @return array<int,array{day:string,work:string,break?:string}>
	 */
	private function extract_row_items( mixed $row ): array {
		if ( is_string( $row ) ) {
			if ( preg_match( '/^(.+?)\s+(\d{1,2}:\d{2}\s*[-–—]\s*\d{1,2}:\d{2})$/u', trim( $row ), $matches ) ) {
				return $this->items_for_days( $matches[1], $matches[2] );
			}

			return array();
		}
		if ( is_object( $row ) ) {
			$row = get_object_vars( $row );
		}
		if ( ! is_array( $row ) ) {
			return array();
		}

		$days = $row['days'] ?? $row['weekDays'] ?? $row['week_days'] ?? $row['day'] ?? $row['weekday'] ?? null;
		$work = $row['time'] ?? $row['workTime'] ?? $row['work_time'] ?? $row['workingTime'] ?? $row['working_time'] ?? $row['interval'] ?? null;
		if ( is_array( $work ) ) {
			$work = $this->time_pair( $work );
		}
		if ( ( null === $work || '' === $work ) && is_array( $row['time_from'] ?? null ) && is_array( $row['time_to'] ?? null ) ) {
			$work = $this->time_pair( array( 'from' => $row['time_from'], 'to' => $row['time_to'] ) );
		}
		$break = $row['break'] ?? $row['breakTime'] ?? $row['break_time'] ?? null;
		if ( is_array( $break ) ) {
			$break = $this->time_pair( $break );
		}

		return $this->items_for_days( $days, $work, $break );
	}

	/**
	 * @return array<int,array{day:string,work:string,break?:string}>
	 */
	private function items_for_days( mixed $days, mixed $work, mixed $break = null ): array {
		$days = $this->formatter->expand_days( $days );
		$work = $this->formatter->normalize_time_range( is_scalar( $work ) ? (string) $work : '' );
		if ( array() === $days || '' === $work ) {
			return array();
		}

		$items = array();
		foreach ( $days as $day ) {
			$items[] = array(
				'day' => $day,
				'work' => $work,
				'break' => $this->formatter->normalize_time_range( is_scalar( $break ) ? (string) $break : '' ),
			);
		}

		return $items;
	}

	/**
	 * @param array<int|string,mixed> $value
	 */
	private function time_pair( array $value ): string {
		$from = $this->time_value( $value['from'] ?? $value['start'] ?? $value['start_time'] ?? $value['startTime'] ?? null );
		$to = $this->time_value( $value['to'] ?? $value['end'] ?? $value['end_time'] ?? $value['endTime'] ?? null );

		return '' !== $from && '' !== $to ? $from . '-' . $to : '';
	}

	private function time_value( mixed $value ): string {
		if ( is_array( $value ) ) {
			$hours = $value['hours'] ?? $value['hour'] ?? null;
			$minutes = $value['minutes'] ?? $value['minute'] ?? 0;
			if ( is_numeric( $hours ) && is_numeric( $minutes ) ) {
				return sprintf( '%02d:%02d', max( 0, min( 23, (int) $hours ) ), max( 0, min( 59, (int) $minutes ) ) );
			}
		}
		if ( is_scalar( $value ) ) {
			return trim( (string) $value );
		}

		return '';
	}
}
