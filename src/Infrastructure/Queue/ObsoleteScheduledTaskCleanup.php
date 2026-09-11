<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Queue;

defined( 'ABSPATH' ) || exit;

final class ObsoleteScheduledTaskCleanup {
	public const FIAS_PREPARED_IMPORT_HOOK = 'wdc_fias_prepared_import_check';
	public const GAR_DAILY_CHECK_HOOK = 'wdc_gar_daily_check';
	private const CLEANUP_VERSION_OPTION = 'wdc_obsolete_scheduled_task_cleanup_version';
	private const PREVIOUS_CLEANUP_VERSION_OPTION = 'wdc_fias_prepared_import_cleanup_version';
	private const CLEANUP_VERSION = 2;
	private const LEGACY_OPTIONS = array(
		'wdc_fias_prepared_import_last_check_at',
		'wdc_gar_changes_last_check_at',
		'wdc_gar_changes_pending',
		'wdc_gar_changes_last_status',
	);
	private const CORE_SETTINGS_OPTION = 'wdc_core_settings';
	private const GAR_ENABLED_SETTING = 'gar_sync_enabled';

	public function __construct( private ActionScheduler $scheduler ) {
	}

	public function register(): void {
		$this->scheduler->when_initialized( self::class, array( $this, 'cleanup' ) );
	}

	public function cleanup(): void {
		if ( self::CLEANUP_VERSION === (int) get_option( self::CLEANUP_VERSION_OPTION, 0 ) ) {
			return;
		}

		$this->scheduler->unschedule( self::FIAS_PREPARED_IMPORT_HOOK );
		$this->scheduler->unschedule( self::GAR_DAILY_CHECK_HOOK );
		foreach ( self::LEGACY_OPTIONS as $option ) {
			delete_option( $option );
		}
		$this->delete_legacy_core_setting();
		delete_option( self::PREVIOUS_CLEANUP_VERSION_OPTION );
		update_option( self::CLEANUP_VERSION_OPTION, self::CLEANUP_VERSION, false );
	}

	private function delete_legacy_core_setting(): void {
		$settings = get_option( self::CORE_SETTINGS_OPTION, array() );
		if ( ! is_array( $settings ) || ! array_key_exists( self::GAR_ENABLED_SETTING, $settings ) ) {
			return;
		}

		unset( $settings[ self::GAR_ENABLED_SETTING ] );
		update_option( self::CORE_SETTINGS_OPTION, $settings, false );
	}
}
