<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

defined( 'ABSPATH' ) || exit;

final class ShipmentStatusAutoSyncCron {
	public const HOOK = 'wdc_shipment_status_autosync';
	public const LEGACY_SCHEDULE = 'wdc_every_6_hours';
	private const SCHEDULE_PREFIX = 'wdc_shipment_status_autosync_';

	public function __construct(
		private ShipmentStatusAutoSyncService $service
	) {
	}

	public function register(): void {
		add_filter( 'cron_schedules', array( $this, 'add_schedule' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( self::HOOK, array( $this, 'run_cron' ) );
	}

	/**
	 * @param array<string,array<string,mixed>> $schedules
	 * @return array<string,array<string,mixed>>
	 */
	public function add_schedule( array $schedules ): array {
		$schedules[ $this->schedule_key() ] = array(
			'interval' => $this->service->interval_seconds(),
			'display' => 'WDC shipment status autosync: ' . $this->service->format_interval_minutes( $this->service->interval_minutes() ),
		);

		return $schedules;
	}

	public function ensure_scheduled(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		$next = wp_next_scheduled( self::HOOK );
		$current_schedule = false !== $next && function_exists( 'wp_get_schedule' ) ? wp_get_schedule( self::HOOK ) : false;
		if ( false !== $next && $this->schedule_key() === $current_schedule ) {
			return;
		}
		if ( false !== $next ) {
			$this->clear_schedule();
		}

		$this->schedule_event();
	}

	public function reschedule(): void {
		$this->clear_schedule();
		$this->schedule_event();
	}

	public function schedule_key(): string {
		return self::SCHEDULE_PREFIX . $this->service->interval_minutes();
	}

	public function run_cron(): void {
		$this->service->run( 'cron' );
	}

	private function schedule_event(): void {
		if ( function_exists( 'wp_schedule_event' ) ) {
			wp_schedule_event( time() + $this->service->interval_seconds(), $this->schedule_key(), self::HOOK );
		}
	}

	private function clear_schedule(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}
}
