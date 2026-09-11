<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

$GLOBALS['wdc_as_hooks'] = array();
$GLOBALS['wdc_as_did_actions'] = array();
$GLOBALS['wdc_as_current_action'] = null;
$GLOBALS['wdc_as_calls'] = array( 'single' => 0, 'recurring' => 0, 'unschedule' => 0, 'has' => 0, 'next' => 0 );
$GLOBALS['wdc_as_unscheduled_hooks'] = array();
$GLOBALS['wdc_as_log_calls'] = 0;
$GLOBALS['wdc_as_options'] = array(
	'wdc_fias_prepared_import_last_check_at' => '2026-09-10 12:00:00',
	'wdc_fias_prepared_import_cleanup_version' => 1,
	'wdc_gar_changes_last_check_at' => '2026-09-10 12:00:00',
	'wdc_gar_changes_pending' => true,
	'wdc_gar_changes_last_status' => array( 'pending' => true ),
	'wdc_core_settings' => array( 'gar_sync_enabled' => true, 'unrelated' => 'preserved' ),
);

final class ActionScheduler {
	public static bool $initialized = false;
	public static function is_initialized(): bool { return self::$initialized; }
}

function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
	$GLOBALS['wdc_as_hooks'][ $hook ][ $priority ][] = $callback;
}
function did_action( string $hook ): int { return (int) ( $GLOBALS['wdc_as_did_actions'][ $hook ] ?? 0 ); }
function doing_action( string $hook ): bool { return $hook === $GLOBALS['wdc_as_current_action']; }
function wdc_as_do_action( string $hook ): void {
	$GLOBALS['wdc_as_did_actions'][ $hook ] = did_action( $hook ) + 1;
	$GLOBALS['wdc_as_current_action'] = $hook;
	$priorities = $GLOBALS['wdc_as_hooks'][ $hook ] ?? array();
	ksort( $priorities );
	foreach ( $priorities as $callbacks ) {
		foreach ( $callbacks as $callback ) { $callback(); }
	}
	$GLOBALS['wdc_as_current_action'] = null;
}
function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int { ++$GLOBALS['wdc_as_calls']['single']; return 11; }
function as_schedule_recurring_action( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '' ): int { ++$GLOBALS['wdc_as_calls']['recurring']; return 12; }
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void { ++$GLOBALS['wdc_as_calls']['unschedule']; $GLOBALS['wdc_as_unscheduled_hooks'][] = $hook; }
function as_has_scheduled_action( string $hook, array $args = array(), string $group = '' ): bool { ++$GLOBALS['wdc_as_calls']['has']; return true; }
function as_next_scheduled_action( string $hook, array $args = array(), string $group = '' ): int { ++$GLOBALS['wdc_as_calls']['next']; return 1234567890; }
function wc_get_logger(): object {
	return new class {
		public function log( string $level, string $message, array $context ): void { ++$GLOBALS['wdc_as_log_calls']; }
	};
}
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_as_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = true ): bool { $GLOBALS['wdc_as_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_as_options'][ $key ] ); return true; }

require_once dirname( __DIR__, 2 ) . '/src/Infrastructure/Logging/LogRedactor.php';
require_once dirname( __DIR__, 2 ) . '/src/Infrastructure/Logging/Logger.php';
require_once dirname( __DIR__, 2 ) . '/src/Infrastructure/Queue/ActionScheduler.php';
require_once dirname( __DIR__, 2 ) . '/src/Infrastructure/Queue/ObsoleteScheduledTaskCleanup.php';

use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler as WdcActionScheduler;
use WallsShop\WDC\Infrastructure\Queue\ObsoleteScheduledTaskCleanup;

function action_scheduler_lifecycle_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$adapter = new WdcActionScheduler( new Logger() );
action_scheduler_lifecycle_assert( WdcActionScheduler::STATE_PENDING === $adapter->readiness(), 'Loaded procedural functions with an uninitialized store must be pending.' );
$GLOBALS['wdc_as_did_actions']['init'] = 1;
$GLOBALS['wdc_as_current_action'] = 'init';
action_scheduler_lifecycle_assert( WdcActionScheduler::STATE_PENDING === $adapter->readiness(), 'The init hook before priority 1 must still be pending.' );
$GLOBALS['wdc_as_did_actions']['init'] = 0;
$GLOBALS['wdc_as_current_action'] = null;
action_scheduler_lifecycle_assert( null === $adapter->schedule_single( time(), 'single' ), 'Pre-init single scheduling must be skipped.' );
action_scheduler_lifecycle_assert( null === $adapter->schedule_recurring( time(), 60, 'recurring' ), 'Pre-init recurring scheduling must be skipped.' );
$adapter->unschedule( 'unschedule' );
action_scheduler_lifecycle_assert( ! $adapter->has_scheduled( 'has' ), 'Pre-init scheduled lookup must be skipped.' );
action_scheduler_lifecycle_assert( null === $adapter->next_scheduled( 'next' ), 'Pre-init next lookup must be skipped.' );
action_scheduler_lifecycle_assert( 0 === array_sum( $GLOBALS['wdc_as_calls'] ), 'No Action Scheduler procedural API may run before datastore initialization.' );
action_scheduler_lifecycle_assert( 0 === $GLOBALS['wdc_as_log_calls'], 'Expected pre-init state must not produce an unavailable warning through WooCommerce logging.' );

$before_init_runs = 0;
$adapter->when_initialized( 'test-owner', static function () use ( &$before_init_runs ): void { ++$before_init_runs; } );
$adapter->when_initialized( 'test-owner', static function () use ( &$before_init_runs ): void { ++$before_init_runs; } );
action_scheduler_lifecycle_assert( 0 === $before_init_runs, 'An owner registered before AS init must be deferred.' );
ActionScheduler::$initialized = true;
wdc_as_do_action( 'action_scheduler_init' );
action_scheduler_lifecycle_assert( 1 === $before_init_runs, 'A deferred owner must run exactly once when AS becomes ready.' );

action_scheduler_lifecycle_assert( 11 === $adapter->schedule_single( time(), 'single' ), 'Single scheduling must work after initialization.' );
action_scheduler_lifecycle_assert( 12 === $adapter->schedule_recurring( time(), 60, 'recurring' ), 'Recurring scheduling must work after initialization.' );
action_scheduler_lifecycle_assert( $adapter->has_scheduled( 'has' ), 'Scheduled lookup must work after initialization.' );
action_scheduler_lifecycle_assert( 1234567890 === $adapter->next_scheduled( 'next' ), 'Next scheduled lookup must work after initialization.' );

$after_init_runs = 0;
$adapter->when_initialized( 'late-owner', static function () use ( &$after_init_runs ): void { ++$after_init_runs; } );
$adapter->when_initialized( 'late-owner', static function () use ( &$after_init_runs ): void { ++$after_init_runs; } );
action_scheduler_lifecycle_assert( 1 === $after_init_runs, 'An owner registered after AS init must run immediately and only once.' );

$legacy_cleanup = new ObsoleteScheduledTaskCleanup( $adapter );
$legacy_cleanup->register();
action_scheduler_lifecycle_assert( 2 === $GLOBALS['wdc_as_calls']['unschedule'], 'Legacy prepared FIAS and GAR actions must each be unscheduled once after AS initialization.' );
action_scheduler_lifecycle_assert( array( 'wdc_fias_prepared_import_check', 'wdc_gar_daily_check' ) === $GLOBALS['wdc_as_unscheduled_hooks'], 'Cleanup must target only the two retired scheduled hooks.' );
action_scheduler_lifecycle_assert( ! isset( $GLOBALS['wdc_as_options']['wdc_fias_prepared_import_last_check_at'] ), 'Dead prepared FIAS last-check option must be deleted.' );
foreach ( array( 'wdc_gar_changes_last_check_at', 'wdc_gar_changes_pending', 'wdc_gar_changes_last_status' ) as $dead_gar_option ) {
	action_scheduler_lifecycle_assert( ! isset( $GLOBALS['wdc_as_options'][ $dead_gar_option ] ), 'Dead GAR option must be deleted: ' . $dead_gar_option );
}
action_scheduler_lifecycle_assert( ! isset( $GLOBALS['wdc_as_options']['wdc_core_settings']['gar_sync_enabled'] ) && 'preserved' === ( $GLOBALS['wdc_as_options']['wdc_core_settings']['unrelated'] ?? '' ), 'Cleanup must remove only the dead GAR setting from core settings.' );
action_scheduler_lifecycle_assert( ! isset( $GLOBALS['wdc_as_options']['wdc_fias_prepared_import_cleanup_version'] ), 'Superseded FIAS-only cleanup marker must be deleted.' );
action_scheduler_lifecycle_assert( 2 === (int) ( $GLOBALS['wdc_as_options']['wdc_obsolete_scheduled_task_cleanup_version'] ?? 0 ), 'Combined cleanup marker version 2 must be persisted.' );
$second_request_adapter = new WdcActionScheduler( new Logger() );
( new ObsoleteScheduledTaskCleanup( $second_request_adapter ) )->register();
action_scheduler_lifecycle_assert( 2 === $GLOBALS['wdc_as_calls']['unschedule'], 'Versioned obsolete-task cleanup must not repeat on later requests.' );

ActionScheduler::$initialized = false;
$GLOBALS['wdc_as_did_actions']['init'] = 1;
$unavailable = new WdcActionScheduler( new Logger() );
action_scheduler_lifecycle_assert( WdcActionScheduler::STATE_UNAVAILABLE === $unavailable->readiness(), 'A non-ready library after init must be unavailable.' );
$unavailable->has_scheduled( 'missing' );
$unavailable->has_scheduled( 'missing' );
action_scheduler_lifecycle_assert( 1 === $GLOBALS['wdc_as_log_calls'], 'Genuine unavailability must retain one deduplicated diagnostic warning per wrapper method.' );

foreach ( array(
	'src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupScheduler.php',
	'src/Calendar/Services/CalendarScheduler.php',
) as $owner_file ) {
	$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $owner_file );
	action_scheduler_lifecycle_assert( str_contains( $source, 'when_initialized' ), $owner_file . ' must use the shared AS lifecycle boundary.' );
}
$cleanup_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Infrastructure/Queue/ObsoleteScheduledTaskCleanup.php' );
action_scheduler_lifecycle_assert( ! is_file( dirname( __DIR__, 2 ) . '/src/Locations/Import/FiasImportManager.php' ), 'Dead FiasImportManager must be removed.' );
foreach ( array( 'src/Locations/Gar/GarSyncManager.php', 'src/Locations/Gar/GarChangesClient.php', 'src/Locations/Services/GarChangesService.php' ) as $retired_file ) {
	action_scheduler_lifecycle_assert( ! is_file( dirname( __DIR__, 2 ) . '/' . $retired_file ), 'Dead GAR/SPAS runtime file must be removed: ' . $retired_file );
}
action_scheduler_lifecycle_assert( str_contains( $cleanup_source, 'wdc_fias_prepared_import_check' ) && str_contains( $cleanup_source, 'wdc_gar_daily_check' ) && str_contains( $cleanup_source, 'when_initialized' ) && str_contains( $cleanup_source, 'unschedule' ) && ! str_contains( $cleanup_source, 'schedule_recurring' ), 'Retired hooks may remain only in versioned legacy unscheduling cleanup.' );

echo "Action Scheduler lifecycle smoke passed.\n";
