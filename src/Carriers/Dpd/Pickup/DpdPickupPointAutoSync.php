<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Pickup;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class DpdPickupPointAutoSync {
	public const HOOK = 'wdc_dpd_pickup_points_autosync';
	public const CONTEXT = 'auto_cron';
	private const RECURRENCE = 'daily';

	public function __construct(
		private DpdSettings $settings,
		private DpdPickupPointImportService $importer,
		private ?Logger $logger = null
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
			$this->log( 'info', 'DPD pickup autosync skipped by settings.', array( 'time' => $time ) );
			return;
		}

		$report = $this->importer->import_all( self::CONTEXT );
		$this->log(
			array() === $report->errors ? 'info' : 'warning',
			'DPD pickup autosync finished.',
			array(
				'time' => $time,
				'status' => '' !== $report->status ? $report->status : ( array() === $report->errors ? 'success' : 'error' ),
				'saved' => $report->saved_count,
				'errors' => count( $report->errors ),
			)
		);
	}

	/** @return array<string,string> */
	public static function time_options(): array {
		return DpdSettings::pickup_autosync_time_options();
	}

	public function msk_time_to_utc_timestamp( string $time, ?\DateTimeImmutable $msk_date = null ): int {
		$time = $this->settings->sanitize_pickup_autosync_time( $time );
		if ( '' === $time ) {
			return 0;
		}
		$msk_date = $msk_date ?? $this->now_utc()->modify( '+3 hours' );
		$date = $msk_date->format( 'Y-m-d' );
		$utc = new \DateTimeImmutable( $date . ' ' . $time . ':00', new \DateTimeZone( 'UTC' ) );

		return $utc->modify( '-3 hours' )->getTimestamp();
	}

	private function schedule_missing_events(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		foreach ( $this->settings->pickup_autosync_times() as $time ) {
			$args = array( $time );
			if ( false !== wp_next_scheduled( self::HOOK, $args ) ) {
				continue;
			}
			wp_schedule_event( $this->next_utc_timestamp( $time ), self::RECURRENCE, self::HOOK, $args );
		}
	}

	private function next_utc_timestamp( string $time ): int {
		$now = $this->now_utc();
		$msk_today = $now->modify( '+3 hours' );
		$timestamp = $this->msk_time_to_utc_timestamp( $time, $msk_today );
		if ( $timestamp <= $now->getTimestamp() ) {
			$timestamp = $this->msk_time_to_utc_timestamp( $time, $msk_today->modify( '+1 day' ) );
		}

		return $timestamp;
	}

	private function now_utc(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
	}

	/** @param array<string,mixed> $context */
	private function log( string $level, string $message, array $context ): void {
		if ( $this->logger instanceof Logger && method_exists( $this->logger, $level ) ) {
			$this->logger->{$level}( $message, $context );
		}
	}
}
