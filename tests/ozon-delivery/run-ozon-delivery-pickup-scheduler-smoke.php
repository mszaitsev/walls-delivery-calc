<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['oz_pickup_lock_options'] = array();
$GLOBALS['oz_pickup_lock_update_mode'] = 'normal';

function add_option( string $name, mixed $value ): bool { if ( array_key_exists( $name, $GLOBALS['oz_pickup_lock_options'] ) ) { return false; } $GLOBALS['oz_pickup_lock_options'][ $name ] = $value; return true; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['oz_pickup_lock_options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value ): bool { $mode = $GLOBALS['oz_pickup_lock_update_mode']; if ( 'failure' === $mode ) { return false; } $changed = ! array_key_exists( $name, $GLOBALS['oz_pickup_lock_options'] ) || $GLOBALS['oz_pickup_lock_options'][ $name ] !== $value; $GLOBALS['oz_pickup_lock_options'][ $name ] = $value; return 'persisted_false' === $mode ? false : $changed; }
function delete_option( string $name ): bool { unset( $GLOBALS['oz_pickup_lock_options'][ $name ] ); return true; }
function maybe_serialize( mixed $value ): string { return serialize( $value ); }
function wp_cache_delete( string $key, string $group = '' ): bool { return true; }

final class OzonLockCasWpdb {
	public string $options = 'wp_options';
	public string $last_error = '';
	/** @var list<mixed> */ private array $args = array();
	/** @var callable|null */ public $before_query = null;
	public function prepare( string $sql, mixed ...$args ): string { $this->args = $args; return $sql; }
	public function query( string $sql ): int|false {
		if ( is_callable( $this->before_query ) ) { ( $this->before_query )(); $this->before_query = null; }
		$name = str_contains( $sql, 'DELETE FROM' ) ? (string) $this->args[0] : (string) $this->args[1];
		$expected = str_contains( $sql, 'DELETE FROM' ) ? (string) $this->args[1] : (string) $this->args[2];
		$current = $GLOBALS['oz_pickup_lock_options'][$name] ?? null;
		if ( serialize( $current ) !== $expected ) { return 0; }
		if ( str_contains( $sql, 'DELETE FROM' ) ) { unset( $GLOBALS['oz_pickup_lock_options'][$name] ); return 1; }
		$GLOBALS['oz_pickup_lock_options'][$name] = unserialize( (string) $this->args[0] );
		return 1;
	}
}

$root = dirname( __DIR__, 2 );
require_once $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportLock.php';

use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupImportLock;

function oz_pickup_scheduler_assert( bool $value, string $message ): void { if ( ! $value ) { throw new RuntimeException( $message ); } }
function oz_pickup_lock_value(): array { return $GLOBALS['oz_pickup_lock_options']['wdc_ozon_delivery_pickup_import_lock'] ?? array(); }

$lock = new OzonDeliveryPickupImportLock();
$owner = $lock->acquire();
oz_pickup_scheduler_assert( is_string( $owner ) && '' !== $owner, 'Lock acquisition must return an owner token.' );
$first_expiry = (int) ( oz_pickup_lock_value()['expires_at'] ?? 0 );
oz_pickup_scheduler_assert( $first_expiry >= time() + 899, 'Acquired lease must use the configured 900-second TTL.' );
oz_pickup_scheduler_assert( $lock->renew( $owner ), 'Same-second acquire and renew must succeed.' );
$same_second_expiry = (int) ( oz_pickup_lock_value()['expires_at'] ?? 0 );
oz_pickup_scheduler_assert( $same_second_expiry > $first_expiry, 'Same-second renew must advance the expiry.' );
oz_pickup_scheduler_assert( $lock->renew( $owner ) && (int) oz_pickup_lock_value()['expires_at'] > $same_second_expiry, 'Repeated renew must extend the lease.' );
oz_pickup_scheduler_assert( ! $lock->renew( 'wrong-owner' ), 'A different owner must not renew the lease.' );
oz_pickup_scheduler_assert( $owner === $lock->current_owner(), 'Current owner must expose the valid active lock owner for safe manual cancellation.' );
delete_option( 'wdc_ozon_delivery_pickup_import_lock' );
oz_pickup_scheduler_assert( ! $lock->renew( $owner ), 'A missing lease must not renew.' );
$GLOBALS['oz_pickup_lock_options']['wdc_ozon_delivery_pickup_import_lock'] = array( 'owner' => $owner, 'expires_at' => time() );
oz_pickup_scheduler_assert( ! $lock->renew( $owner ), 'A lease at its exact expiry boundary must be expired.' );
$replacement_owner = $lock->acquire();
oz_pickup_scheduler_assert( is_string( $replacement_owner ) && $replacement_owner !== $owner, 'Acquire must replace an expired lease.' );
$GLOBALS['oz_pickup_lock_update_mode'] = 'persisted_false';
oz_pickup_scheduler_assert( $lock->renew( $replacement_owner ), 'Persisted matching renewal must succeed even when update_option returns false.' );
$GLOBALS['oz_pickup_lock_update_mode'] = 'failure';
oz_pickup_scheduler_assert( ! $lock->renew( $replacement_owner ), 'A real persistence failure must fail renewal.' );
$GLOBALS['oz_pickup_lock_update_mode'] = 'normal';
$execution_token = $lock->claim_execution( $replacement_owner );
oz_pickup_scheduler_assert( is_string( $execution_token ) && $lock->owns_execution( $replacement_owner, $execution_token ), 'The current job owner must acquire an exclusive per-callback execution claim.' );
oz_pickup_scheduler_assert( null === $lock->claim_execution( $replacement_owner ), 'A second callback for the same job owner must not overlap an active execution claim.' );
oz_pickup_scheduler_assert( $lock->renew_execution( $replacement_owner, $execution_token ), 'The callback owner must renew its exact execution claim between units.' );
$lock->release_execution( $replacement_owner, 'stale-token' );
oz_pickup_scheduler_assert( $lock->owns_execution( $replacement_owner, $execution_token ), 'A stale callback token must not release the current execution claim.' );
$lock->release_execution( $replacement_owner, $execution_token );
oz_pickup_scheduler_assert( ! $lock->owns_execution( $replacement_owner, $execution_token ) && is_string( $lock->claim_execution( $replacement_owner ) ), 'Only the exact callback token may release the execution claim for the next callback.' );

$cas_db = new OzonLockCasWpdb();
$cas_lock = new OzonDeliveryPickupImportLock( $cas_db );
$GLOBALS['oz_pickup_lock_options']['wdc_ozon_delivery_pickup_import_lock'] = array( 'owner' => 'OWNER-A', 'expires_at' => time() + 100 );
$cas_db->before_query = static function (): void { $GLOBALS['oz_pickup_lock_options']['wdc_ozon_delivery_pickup_import_lock'] = array( 'owner' => 'OWNER-B', 'expires_at' => time() + 900 ); };
oz_pickup_scheduler_assert( ! $cas_lock->renew( 'OWNER-A' ) && 'OWNER-B' === (string) oz_pickup_lock_value()['owner'], 'A stale renew CAS must not overwrite a newer owner.' );
$GLOBALS['oz_pickup_lock_options']['wdc_ozon_delivery_pickup_import_lock'] = array( 'owner' => 'OWNER-A', 'expires_at' => time() + 100 );
$cas_db->before_query = static function (): void { $GLOBALS['oz_pickup_lock_options']['wdc_ozon_delivery_pickup_import_lock'] = array( 'owner' => 'OWNER-B', 'expires_at' => time() + 900 ); };
$cas_lock->release( 'OWNER-A' );
oz_pickup_scheduler_assert( 'OWNER-B' === (string) oz_pickup_lock_value()['owner'], 'A stale release CAS must not delete a newer owner.' );
$GLOBALS['oz_pickup_lock_options']['wdc_ozon_delivery_pickup_import_lock'] = array( 'owner' => 'OWNER-EXPIRED', 'expires_at' => time() - 1 );
$cas_db->before_query = static function (): void { $GLOBALS['oz_pickup_lock_options']['wdc_ozon_delivery_pickup_import_lock'] = array( 'owner' => 'OWNER-B', 'expires_at' => time() + 900 ); };
oz_pickup_scheduler_assert( null === $cas_lock->acquire() && 'OWNER-B' === (string) oz_pickup_lock_value()['owner'], 'Expired takeover CAS must not delete a concurrently renewed/new owner.' );
$GLOBALS['oz_pickup_lock_options']['wdc_ozon_delivery_pickup_import_lock'] = array( 'owner' => 'OWNER-A', 'expires_at' => time() + 100 );
$cas_db->before_query = static function (): void { $GLOBALS['oz_pickup_lock_options']['wdc_ozon_delivery_pickup_import_lock'] = array( 'owner' => 'OWNER-B', 'expires_at' => time() + 900 ); };
oz_pickup_scheduler_assert( null === $cas_lock->claim_execution( 'OWNER-A' ) && 'OWNER-B' === (string) oz_pickup_lock_value()['owner'], 'A stale execution-claim CAS must not overwrite a newer job owner.' );
$GLOBALS['oz_pickup_lock_options']['wdc_ozon_delivery_pickup_import_lock'] = array( 'owner' => 'OWNER-A', 'expires_at' => time() + 900, 'execution_token' => 'EXECUTION-A', 'execution_expires_at' => time() + 900 );
$cas_db->before_query = static function (): void { $GLOBALS['oz_pickup_lock_options']['wdc_ozon_delivery_pickup_import_lock']['execution_token'] = 'EXECUTION-B'; };
$cas_lock->release_execution( 'OWNER-A', 'EXECUTION-A' );
oz_pickup_scheduler_assert( 'EXECUTION-B' === (string) oz_pickup_lock_value()['execution_token'], 'A stale callback must not release a newer execution claim.' );

$scheduler = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupScheduler.php' ) ?: '';
$importer = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportService.php' ) ?: '';
$repository = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupRepository.php' ) ?: '';
$lock_source = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportLock.php' ) ?: '';
oz_pickup_scheduler_assert( str_contains( $scheduler, 'schedule_recurring' ) && str_contains( $scheduler, '86400' ) && str_contains( $scheduler, 'TimezoneService' ) && ! str_contains( $scheduler, 'wp_timezone' ) && str_contains( $scheduler, 'reschedule' ) && str_contains( $scheduler, 'when_initialized' ), 'Daily schedule must use the WDC timezone owner, defer bootstrap until AS init and support rescheduling.' );
oz_pickup_scheduler_assert( str_contains( $scheduler, 'ensure_continuation( $job_id, $owner, 0 )' ) && str_contains( $scheduler, '$this->lock->claim_execution( $owner )' ) && str_contains( $scheduler, '$this->lock->renew_execution( $owner, $execution_token )' ) && str_contains( $scheduler, '$this->importer->run_step( $job_id )' ), 'A worker callback must use the exact continuation helper and hold its exclusive execution claim before reaching the importer.' );
oz_pickup_scheduler_assert( str_contains( $lock_source, 'max( $now + self::TTL, $current_expiry + 1 )' ) && str_contains( $lock_source, '$current_expiry <= $now' ) && str_contains( $lock_source, 'claim_execution' ) && str_contains( $lock_source, 'release_execution' ) && str_contains( $lock_source, 'compare_and_replace' ) && str_contains( $lock_source, 'compare_and_delete' ), 'Lease and per-callback execution ownership must use owner-safe compare operations.' );
oz_pickup_scheduler_assert( str_contains( $scheduler, 'pickup_lock_renew_failed' ) && str_contains( $scheduler, 'fail_job_after_lock_renew_failure' ) && str_contains( $scheduler, '$this->lock->owns( $owner )' ), 'Renew failure must fail only the matching job and must not release a foreign lock.' );
oz_pickup_scheduler_assert( str_contains( $repository, 'public function start( string $job_id, ?string $lock_owner = null ): ?int' ) && str_contains( $repository, "'lock_owner'" ) && str_contains( $repository, 'false === $this->wpdb->insert' ) && str_contains( $repository, 'return $id > 0 ? $id : null' ), 'Generation creation must store the lock owner and fail closed for insert errors and zero IDs.' );
oz_pickup_scheduler_assert( str_contains( $scheduler, 'null === $generation_id' ) && str_contains( $scheduler, 'pickup_step_schedule_failed' ) && substr_count( $scheduler, 'fail_job_and_release' ) >= 3 && str_contains( $scheduler, "if ( ! empty( \$result['complete'] ) )" ) && str_contains( $scheduler, '$this->lock->owns( $owner )' ), 'First and next step enqueue failures must fail the job, release only the matching owner lock and retain normal completion.' );
$run_step = substr( $scheduler, strpos( $scheduler, 'public function run_step' ), strpos( $scheduler, 'public function next_run' ) - strpos( $scheduler, 'public function run_step' ) );
oz_pickup_scheduler_assert( str_contains( $run_step, "\$result['retry_after']" ) && str_contains( $run_step, 'schedule_continuation_or_fail' ) && str_contains( $run_step, 'mark_unit_processed()' ) && str_contains( $run_step, 'while ( $budget->can_continue() )' ), 'Retry must end the bounded slice and use importer backoff without releasing the generation lock.' );
oz_pickup_scheduler_assert( str_contains( $scheduler, 'public function stop_manual' ) && str_contains( $scheduler, '$this->importer->building_generation()' ) && str_contains( $scheduler, "generation['lock_owner']" ) && str_contains( $scheduler, '$this->importer->cancel_generation' ) && str_contains( $scheduler, '$this->lock->owns( $owner )' ) && str_contains( $scheduler, 'unschedule( self::STEP_HOOK, array( (string) $generation[\'job_id\'], $owner ), self::GROUP )' ), 'Manual stop must use generation lock_owner as authority, cancel the building generation, unschedule exact job/owner args, and release only a matching owner.' );
oz_pickup_scheduler_assert( str_contains( $importer, 'public function fail_job' ) && str_contains( $importer, "'building' ===" ) && str_contains( $importer, '$this->repository->fail' ), 'Scheduler failure must use the carrier-owned importer failure path and not direct SQL.' );

echo "Ozon Delivery pickup scheduler smoke passed.\n";
