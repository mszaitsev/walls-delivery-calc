<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

defined( 'ABSPATH' ) || exit;

final class ShipmentStatusAutoSyncCron {
	public const HOOK = 'wdc_shipment_status_autosync';
	public const SCHEDULE = 'wdc_every_6_hours';

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
		$schedules[ self::SCHEDULE ] = array(
			'interval' => ShipmentStatusAutoSyncService::INTERVAL_SECONDS,
			'display' => 'WDC every 6 hours',
		);

		return $schedules;
	}

	public function ensure_scheduled(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		if ( false !== wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		wp_schedule_event( time() + ShipmentStatusAutoSyncService::INTERVAL_SECONDS, self::SCHEDULE, self::HOOK );
	}

	public function run_cron(): void {
		$this->service->run( 'cron' );
	}
}
