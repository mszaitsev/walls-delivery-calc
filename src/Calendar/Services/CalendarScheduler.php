<?php
declare(strict_types=1);

namespace WallsShop\WDC\Calendar\Services;

use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;

defined( 'ABSPATH' ) || exit;

final class CalendarScheduler {
	public const HOOK = 'wdc_calendar_generate_next_year';
	private const SCHEDULE_VERSION_OPTION = 'wdc_calendar_schedule_version';
	private const SCHEDULE_VERSION = 2;

	public function __construct(
		private ActionScheduler $scheduler,
		private CalendarService $calendar_service,
		private TimezoneService $timezone
	) {
	}

	public function register(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		$this->scheduler->when_initialized( self::class, array( $this, 'schedule' ) );
	}

	public function schedule(): void {
		$migration_required = self::SCHEDULE_VERSION !== (int) get_option( self::SCHEDULE_VERSION_OPTION, 0 );
		if ( $migration_required ) {
			$this->scheduler->unschedule( self::HOOK );
		} elseif ( null !== $this->scheduler->next_scheduled( self::HOOK ) ) {
			return;
		}

		$this->schedule_next();
	}

	public function run(): void {
		try {
			$this->calendar_service->generate_next_year_if_needed();
		} finally {
			$this->schedule_next();
		}
	}

	public function next_run_timestamp( ?\DateTimeImmutable $now = null ): int {
		$now = null === $now ? $this->timezone->now() : $now->setTimezone( $this->timezone->timezone() );
		$first = $this->first_monday( $now );
		if ( $first->getTimestamp() > $now->getTimestamp() ) {
			return $first->getTimestamp();
		}

		return $this->first_monday( $now->modify( 'first day of next month' ) )->getTimestamp();
	}

	private function schedule_next(): void {
		$scheduled = $this->scheduler->next_scheduled( self::HOOK );
		if ( null !== $scheduled && $scheduled > time() ) {
			return;
		}

		if ( null !== $this->scheduler->schedule_single( $this->next_run_timestamp(), self::HOOK ) ) {
			update_option( self::SCHEDULE_VERSION_OPTION, self::SCHEDULE_VERSION, false );
		}
	}

	private function first_monday( \DateTimeImmutable $month ): \DateTimeImmutable {
		$first = $month->setTimezone( $this->timezone->timezone() )->modify( 'first day of this month' )->setTime( 9, 0, 0 );
		$days_until_monday = ( 8 - (int) $first->format( 'N' ) ) % 7;

		return 0 === $days_until_monday ? $first : $first->modify( '+' . $days_until_monday . ' days' );
	}
}
