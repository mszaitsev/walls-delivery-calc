<?php
declare(strict_types=1);

namespace WallsShop\WDC\Calendar\Services;

use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;

defined( 'ABSPATH' ) || exit;

final class CalendarScheduler {
	public const HOOK = 'wdc_calendar_generate_next_year';

	public function __construct(
		private ActionScheduler $scheduler,
		private CalendarService $calendar_service,
		private TimezoneService $timezone
	) {
	}

	public function register(): void {
		add_action( self::HOOK, array( $this->calendar_service, 'generate_next_year_if_needed' ) );
		add_action( 'init', array( $this, 'schedule' ) );
	}

	public function schedule(): void {
		if ( $this->scheduler->has_scheduled( self::HOOK ) ) {
			return;
		}

		$first_run = $this->timezone->now()->modify( 'tomorrow 01:00:00' )->getTimestamp();
		$this->scheduler->schedule_recurring( $first_run, DAY_IN_SECONDS, self::HOOK );
	}
}
