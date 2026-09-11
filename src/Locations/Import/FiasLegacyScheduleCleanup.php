<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Import;

use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;

defined( 'ABSPATH' ) || exit;

final class FiasLegacyScheduleCleanup {
	public const LEGACY_HOOK = 'wdc_fias_prepared_import_check';
	private const LEGACY_LAST_CHECK_OPTION = 'wdc_fias_prepared_import_last_check_at';
	private const CLEANUP_VERSION_OPTION = 'wdc_fias_prepared_import_cleanup_version';
	private const CLEANUP_VERSION = 1;

	public function __construct( private ActionScheduler $scheduler ) {
	}

	public function register(): void {
		$this->scheduler->when_initialized( self::class, array( $this, 'cleanup' ) );
	}

	public function cleanup(): void {
		if ( self::CLEANUP_VERSION === (int) get_option( self::CLEANUP_VERSION_OPTION, 0 ) ) {
			return;
		}

		$this->scheduler->unschedule( self::LEGACY_HOOK );
		delete_option( self::LEGACY_LAST_CHECK_OPTION );
		update_option( self::CLEANUP_VERSION_OPTION, self::CLEANUP_VERSION, false );
	}
}
