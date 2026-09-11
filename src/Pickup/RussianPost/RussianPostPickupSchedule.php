<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

use DateTimeImmutable;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;

defined( 'ABSPATH' ) || exit;

final class RussianPostPickupSchedule {
	public const DEFAULT_WEEKDAY = 1;
	public const DEFAULT_TIME = '09:00';

	public function __construct(
		private RussianPostOtpravkaApiSettings $settings,
		private TimezoneService $timezone
	) {
	}

	/** @return array<int,string> */
	public static function weekday_labels(): array {
		return array(
			1 => 'Понедельник',
			2 => 'Вторник',
			3 => 'Среда',
			4 => 'Четверг',
			5 => 'Пятница',
			6 => 'Суббота',
			7 => 'Воскресенье',
		);
	}

	/** @return array<int,string> */
	public static function time_options(): array {
		$options = array();
		for ( $minutes = 0; $minutes < 24 * 60; $minutes += 15 ) {
			$options[] = sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
		}

		return $options;
	}

	/** @return array{weekday:int,time:string,explicit:bool} */
	public function effective_settings( int|false|null $existing_timestamp = null ): array {
		if ( $this->settings->has_explicit_schedule() ) {
			return array( 'weekday' => $this->settings->schedule_weekday(), 'time' => $this->settings->schedule_time(), 'explicit' => true );
		}

		if ( is_int( $existing_timestamp ) && $existing_timestamp > 0 ) {
			$local = ( new DateTimeImmutable( '@' . $existing_timestamp ) )->setTimezone( $this->timezone->timezone() );

			return array( 'weekday' => (int) $local->format( 'N' ), 'time' => $local->format( 'H:i' ), 'explicit' => false );
		}

		return array( 'weekday' => self::DEFAULT_WEEKDAY, 'time' => self::DEFAULT_TIME, 'explicit' => false );
	}

	public function next_timestamp( int $weekday, string $time, ?DateTimeImmutable $now = null ): int {
		$weekday = $weekday >= 1 && $weekday <= 7 ? $weekday : self::DEFAULT_WEEKDAY;
		$time = in_array( $time, self::time_options(), true ) ? $time : self::DEFAULT_TIME;
		$now = null === $now ? $this->timezone->now() : $now->setTimezone( $this->timezone->timezone() );
		[ $hour, $minute ] = array_map( 'intval', explode( ':', $time ) );
		$days_ahead = ( $weekday - (int) $now->format( 'N' ) + 7 ) % 7;
		$next = $now->modify( '+' . $days_ahead . ' days' )->setTime( $hour, $minute, 0 );
		if ( $next->getTimestamp() <= $now->getTimestamp() ) {
			$next = $next->modify( '+7 days' );
		}

		return $next->getTimestamp();
	}

	public function sync(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}

		$existing = wp_next_scheduled( RussianPostPickupImporter::SCHEDULE_HOOK );
		if ( ! $this->settings->schedule_enabled() ) {
			if ( false !== $existing && function_exists( 'wp_clear_scheduled_hook' ) ) {
				wp_clear_scheduled_hook( RussianPostPickupImporter::SCHEDULE_HOOK );
			}
			return;
		}

		$effective = $this->effective_settings( $existing );
		$weekly = ! function_exists( 'wp_get_schedule' ) || 'weekly' === wp_get_schedule( RussianPostPickupImporter::SCHEDULE_HOOK );
		if ( false !== $existing && $weekly && $this->matches( (int) $existing, $effective['weekday'], $effective['time'] ) ) {
			return;
		}
		if ( false !== $existing ) {
			if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
				return;
			}
			wp_clear_scheduled_hook( RussianPostPickupImporter::SCHEDULE_HOOK );
		}
		wp_schedule_event( $this->next_timestamp( $effective['weekday'], $effective['time'] ), 'weekly', RussianPostPickupImporter::SCHEDULE_HOOK );
	}

	public function description( int|false|null $existing_timestamp = null ): string {
		$effective = $this->effective_settings( $existing_timestamp );
		$labels = self::weekday_labels();

		return ( $labels[ $effective['weekday'] ] ?? $labels[ self::DEFAULT_WEEKDAY ] ) . ', ' . $effective['time'];
	}

	private function matches( int $timestamp, int $weekday, string $time ): bool {
		$local = ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $this->timezone->timezone() );

		return (int) $local->format( 'N' ) === $weekday && $local->format( 'H:i' ) === $time;
	}
}
