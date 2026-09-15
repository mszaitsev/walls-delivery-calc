<?php
declare(strict_types=1);

namespace WallsShop\WDC\Calendar\Storage;

use WallsShop\WDC\Domain\Calendar\CalendarDay;

defined( 'ABSPATH' ) || exit;

final class CalendarRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function get_day( string $calendar_type, string $date ): ?CalendarDay {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare(
				"SELECT calendar_type, calendar_date, is_working, reason FROM {$this->table_name()} WHERE calendar_type = %s AND calendar_date = %s LIMIT 1",
				$calendar_type,
				$date
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->row_to_day( $row ) : null;
	}

	public function save_day( CalendarDay $day ): void {
		$now = current_time( 'mysql' );

		$this->wpdb->replace(
			$this->table_name(),
			array(
				'calendar_type' => $day->calendar_type,
				'calendar_date' => $day->date,
				'is_working'    => $day->working ? 1 : 0,
				'reason'        => $day->reason,
				'created_at'    => $now,
				'updated_at'    => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * @param array<int, CalendarDay> $days
	 */
	public function save_days( array $days ): void {
		foreach ( $days as $day ) {
			if ( $day instanceof CalendarDay ) {
				$this->save_day( $day );
			}
		}
	}

	/**
	 * @return array<string, CalendarDay>
	 */
	public function get_year( string $calendar_type, int $year ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT calendar_type, calendar_date, is_working, reason FROM {$this->table_name()} WHERE calendar_type = %s AND YEAR(calendar_date) = %d ORDER BY calendar_date ASC",
				$calendar_type,
				$year
			),
			ARRAY_A
		);

		$days = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$day = $this->row_to_day( $row );
				$days[ $day->date ] = $day;
			}
		}

		return $days;
	}

	public function has_year( string $calendar_type, int $year ): bool {
		$count = $this->wpdb->get_var(
			$this->wpdb->prepare(
				"SELECT COUNT(*) FROM {$this->table_name()} WHERE calendar_type = %s AND YEAR(calendar_date) = %d",
				$calendar_type,
				$year
			)
		);

		return (int) $count > 0;
	}

	public function delete_year( string $calendar_type, int $year ): void {
		$this->wpdb->query(
			$this->wpdb->prepare(
				"DELETE FROM {$this->table_name()} WHERE calendar_type = %s AND YEAR(calendar_date) = %d",
				$calendar_type,
				$year
			)
		);
	}

	private function table_name(): string {
		return $this->wpdb->prefix . 'wdc_calendar_days';
	}

	/**
	 * @param array<string, mixed> $row
	 */
	private function row_to_day( array $row ): CalendarDay {
		return new CalendarDay(
			(string) $row['calendar_date'],
			(bool) (int) $row['is_working'],
			(string) ( $row['reason'] ?? '' ),
			(string) $row['calendar_type']
		);
	}
}
