<?php
declare(strict_types=1);

namespace WallsShop\WDC\Calendar\Services;

use DateTimeImmutable;
use DateTimeZone;
use WallsShop\WDC\Calendar\CalendarTypes;
use WallsShop\WDC\Calendar\Storage\CalendarRepository;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class CalendarService {
	private const ATTENTION_OPTION = 'wdc_calendar_attention_required';

	public function __construct(
		private CalendarRepository $repository,
		private YearGenerator $year_generator,
		private SettingsRepository $settings,
		private TimezoneService $timezone
	) {
	}

	public function is_working_day( string $calendar_type, string $date ): bool {
		$this->ensure_year_exists( $calendar_type, (int) substr( $date, 0, 4 ) );
		$day = $this->repository->get_day( $calendar_type, $date );

		return null !== $day && $day->working;
	}

	public function get_next_working_day( string $calendar_type, string $date ): string {
		$current = $this->date( $date );

		for ( $i = 0; $i < 730; $i++ ) {
			$formatted = $current->format( 'Y-m-d' );
			if ( $this->is_working_day( $calendar_type, $formatted ) ) {
				return $formatted;
			}
			$current = $current->modify( '+1 day' );
		}

		return $date;
	}

	public function add_working_days( string $calendar_type, string $date, int $days ): string {
		if ( $days <= 0 ) {
			return $date;
		}

		$current = $this->date( $date );
		$added   = 0;

		for ( $i = 0; $i < 1460; $i++ ) {
			$current   = $current->modify( '+1 day' );
			$formatted = $current->format( 'Y-m-d' );

			if ( $this->is_working_day( $calendar_type, $formatted ) ) {
				$added++;
			}

			if ( $added >= $days ) {
				return $formatted;
			}
		}

		return $current->format( 'Y-m-d' );
	}

	public function add_calendar_days( string $date, int $days ): string {
		return $this->date( $date )->modify( sprintf( '%+d days', $days ) )->format( 'Y-m-d' );
	}

	public function ensure_year_exists( string $calendar_type, int $year ): void {
		if ( $this->repository->has_year( $calendar_type, $year ) ) {
			return;
		}

		$this->repository->save_days( $this->year_generator->generate_year( $calendar_type, $year ) );
	}

	public function ensure_initial_years(): void {
		foreach ( CalendarTypes::all() as $calendar_type ) {
			$this->ensure_year_exists( $calendar_type, 2026 );
		}
	}

	public function generate_next_year_if_needed(): void {
		if ( ! $this->settings->get_bool( 'auto_generate_next_year', true ) ) {
			return;
		}

		$today = $this->timezone->now();
		if ( '12-01' !== $today->format( 'm-d' ) ) {
			return;
		}

		$next_year = (int) $today->modify( '+1 year' )->format( 'Y' );

		foreach ( CalendarTypes::all() as $calendar_type ) {
			if ( $this->repository->has_year( $calendar_type, $next_year ) ) {
				continue;
			}

			$this->repository->save_days( $this->year_generator->generate_year( $calendar_type, $next_year ) );
			$this->mark_attention_required( $calendar_type, $next_year );
		}
	}

	public function mark_attention_resolved( string $calendar_type, int $year ): void {
		$notices = $this->attention_required();
		unset( $notices[ $this->attention_key( $calendar_type, $year ) ] );
		update_option( self::ATTENTION_OPTION, $notices, false );
	}

	/**
	 * @return array<string, array{calendar_type:string, year:int}>
	 */
	public function attention_required(): array {
		$value = get_option( self::ATTENTION_OPTION, array() );

		return is_array( $value ) ? $value : array();
	}

	private function mark_attention_required( string $calendar_type, int $year ): void {
		$notices = $this->attention_required();
		$notices[ $this->attention_key( $calendar_type, $year ) ] = array(
			'calendar_type' => $calendar_type,
			'year'          => $year,
		);

		update_option( self::ATTENTION_OPTION, $notices, false );
	}

	private function attention_key( string $calendar_type, int $year ): string {
		return $calendar_type . '_' . $year;
	}

	private function date( string $date ): DateTimeImmutable {
		return new DateTimeImmutable( $date . ' 00:00:00', new DateTimeZone( TimezoneService::TIMEZONE ) );
	}
}
