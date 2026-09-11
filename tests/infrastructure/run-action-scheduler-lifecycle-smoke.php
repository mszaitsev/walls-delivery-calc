<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

$GLOBALS['wdc_as_hooks'] = array();
$GLOBALS['wdc_as_did_actions'] = array();
$GLOBALS['wdc_as_current_action'] = null;
$GLOBALS['wdc_as_calls'] = array( 'single' => 0, 'recurring' => 0, 'unschedule' => 0, 'has' => 0, 'next' => 0 );
$GLOBALS['wdc_as_log_calls'] = 0;

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
function as_unschedule_all_actions( string $hook, array $args = array(), string $group = '' ): void { ++$GLOBALS['wdc_as_calls']['unschedule']; }
function as_has_scheduled_action( string $hook, array $args = array(), string $group = '' ): bool { ++$GLOBALS['wdc_as_calls']['has']; return true; }
function as_next_scheduled_action( string $hook, array $args = array(), string $group = '' ): int { ++$GLOBALS['wdc_as_calls']['next']; return 1234567890; }
function wc_get_logger(): object {
	return new class {
		public function log( string $level, string $message, array $context ): void { ++$GLOBALS['wdc_as_log_calls']; }
	};
}

require_once dirname( __DIR__, 2 ) . '/src/Infrastructure/Logging/LogRedactor.php';
require_once dirname( __DIR__, 2 ) . '/src/Infrastructure/Logging/Logger.php';
require_once dirname( __DIR__, 2 ) . '/src/Infrastructure/Queue/ActionScheduler.php';

use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler as WdcActionScheduler;

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
	'src/Locations/Gar/GarSyncManager.php',
	'src/Locations/Import/FiasImportManager.php',
) as $owner_file ) {
	$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $owner_file );
	action_scheduler_lifecycle_assert( str_contains( $source, 'when_initialized' ), $owner_file . ' must use the shared AS lifecycle boundary.' );
}

echo "Action Scheduler lifecycle smoke passed.\n";
