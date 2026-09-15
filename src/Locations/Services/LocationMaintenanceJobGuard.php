<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Services;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class LocationMaintenanceJobGuard {
	public const UPDATE_OPTION = 'wdc_locations_incremental_update_job';
	private const JOBS = array(
		'wdc_gar_import_job', self::UPDATE_OPTION, 'wdc_locations_snapshot_import_job',
		'wdc_locations_display_name_rebuild_job', 'wdc_dadata_postcode_fill_job',
		'wdc_dadata_coordinates_fill_job', 'wdc_russianpost_courier_calc_postcode_fill_job',
		'wdc_dpd_geography_import_state',
	);

	public function active( array $job ): bool {
		return array() !== $job && ! in_array( (string) ( $job['phase'] ?? $job['status'] ?? '' ), array( 'idle', 'finished', 'completed', 'applied', 'failed', 'cancelled', 'canceled' ), true );
	}

	public function read( string $key = self::UPDATE_OPTION ): array {
		if ( function_exists( 'wp_cache_delete' ) ) {
			wp_cache_delete( 'alloptions', 'options' );
			wp_cache_delete( 'notoptions', 'options' );
			wp_cache_delete( $key, 'options' );
		}
		$value = get_option( $key, array() );
		return is_array( $value ) ? $value : array();
	}

	/** Called only while holding the shared database write lock. */
	public function assert_writer_allowed( string $owner = '' ): void {
		$job = $this->read();
		if ( $this->active( $job ) && ( '' === $owner || ! hash_equals( (string) ( $job['job_id'] ?? '' ), $owner ) ) ) {
			throw new RuntimeException( 'Идёт процесс обновления базы населённых пунктов. Завершите или отмените его.', 423 );
		}
	}

	public function assert_no_active_jobs(): void {
		foreach ( self::JOBS as $key ) {
			if ( $this->active( $this->read( $key ) ) ) {
				throw new RuntimeException( 'Завершите или отмените текущую задачу базы населённых пунктов.', 423 );
			}
		}
	}
}
