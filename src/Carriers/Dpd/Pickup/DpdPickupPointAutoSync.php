<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Pickup;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class DpdPickupPointAutoSync {
	public const HOOK = 'wdc_dpd_pickup_points_autosync';
	public const CONTEXT = 'auto_cron';
	private const RECURRENCE = 'daily';

	public function __construct(
		private DpdSettings $settings,
		private DpdPickupPointImportService $importer,
		private ?Logger $logger,
		private TimezoneService $timezone
	) {
	}

	public function register(): void {
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( self::HOOK, array( $this, 'run_cron' ), 10, 1 );
	}

	public function activate(): void {
		$this->reschedule();
	}

	public function deactivate(): void {
		$this->clear_schedule();
	}

	public function ensure_scheduled(): void {
		if ( ! $this->settings->pickup_autosync_enabled() || array() === $this->settings->pickup_autosync_times() ) {
			$this->clear_schedule();
			return;
		}

		$this->schedule_missing_events();
	}

	public function reschedule(): void {
		$this->clear_schedule();
		if ( ! $this->settings->pickup_autosync_enabled() ) {
			return;
		}

		$this->schedule_missing_events();
	}

	public function clear_schedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	public function run_cron( string $time = '' ): void {
		$time = $this->settings->sanitize_pickup_autosync_time( $time );
		if ( ! $this->settings->pickup_autosync_enabled() || '' === $time || ! in_array( $time, $this->settings->pickup_autosync_times(), true ) ) {
			return;
		}

		$report = $this->importer->import_all( self::CONTEXT );
		if ( array() !== $report->errors ) {
			$this->log(
				'warning',
				'DPD pickup autosync failed.',
				array(
					'time' => $time,
					'status' => '' !== $report->status ? $report->status : 'error',
					'saved' => $report->saved_count,
					'errors' => count( $report->errors ),
				)
			);
		}
	}

	/** @return array<string,string> */
	public static function time_options(): array {
		return DpdSettings::pickup_autosync_time_options();
	}

	public function local_time_to_timestamp( string $time, ?\DateTimeImmutable $now = null ): int {
		$time = $this->settings->sanitize_pickup_autosync_time( $time );

		return '' === $time ? 0 : $this->timezone->next_local_time_timestamp( $time, $now );
	}

	private function schedule_missing_events(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		$times = $this->settings->pickup_autosync_times();
		foreach ( $times as $time ) {
			$scheduled = wp_next_scheduled( self::HOOK, array( $time ) );
			if ( false !== $scheduled && $time !== $this->timezone->format_timestamp( (int) $scheduled, 'H:i' ) ) {
				$this->clear_schedule();
				break;
			}
		}
		foreach ( $times as $time ) {
			$args = array( $time );
			$scheduled = wp_next_scheduled( self::HOOK, $args );
			if ( false !== $scheduled ) {
				continue;
			}
			wp_schedule_event( $this->local_time_to_timestamp( $time ), self::RECURRENCE, self::HOOK, $args );
		}
	}

	/** @param array<string,mixed> $context */
	private function log( string $level, string $message, array $context ): void {
		if ( $this->logger instanceof Logger && method_exists( $this->logger, $level ) ) {
			$this->logger->{$level}( $message, $context );
		}
	}
}
