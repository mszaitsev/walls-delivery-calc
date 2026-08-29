<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['oz_pickup_lock_options'] = array();
$GLOBALS['oz_pickup_lock_update_mode'] = 'normal';

function add_option( string $name, mixed $value ): bool { if ( array_key_exists( $name, $GLOBALS['oz_pickup_lock_options'] ) ) { return false; } $GLOBALS['oz_pickup_lock_options'][ $name ] = $value; return true; }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['oz_pickup_lock_options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value ): bool { $mode = $GLOBALS['oz_pickup_lock_update_mode']; if ( 'failure' === $mode ) { return false; } $changed = ! array_key_exists( $name, $GLOBALS['oz_pickup_lock_options'] ) || $GLOBALS['oz_pickup_lock_options'][ $name ] !== $value; $GLOBALS['oz_pickup_lock_options'][ $name ] = $value; return 'persisted_false' === $mode ? false : $changed; }
function delete_option( string $name ): bool { unset( $GLOBALS['oz_pickup_lock_options'][ $name ] ); return true; }

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

$scheduler = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupScheduler.php' ) ?: '';
$importer = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportService.php' ) ?: '';
$repository = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupRepository.php' ) ?: '';
$lock_source = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportLock.php' ) ?: '';
oz_pickup_scheduler_assert( str_contains( $scheduler, 'schedule_recurring' ) && str_contains( $scheduler, '86400' ) && str_contains( $scheduler, 'wp_timezone' ) && str_contains( $scheduler, 'reschedule' ), 'Daily timezone-aware schedule and reschedule are required.' );
oz_pickup_scheduler_assert( str_contains( $scheduler, 'schedule_single( time(), self::STEP_HOOK' ) && str_contains( $scheduler, '$this->lock->renew( $owner )' ) && str_contains( $scheduler, '$this->importer->run_step( $job_id )' ), 'A same-second first step must renew before reaching the importer.' );
oz_pickup_scheduler_assert( str_contains( $lock_source, 'max( $now + self::TTL, $current_expiry + 1 )' ) && str_contains( $lock_source, '$current_expiry <= $now' ) && str_contains( $lock_source, 'persisted' ), 'Lease renewal must advance expiry, reject expired leases and verify a false update result.' );
oz_pickup_scheduler_assert( str_contains( $scheduler, 'pickup_lock_renew_failed' ) && str_contains( $scheduler, 'fail_job_after_lock_renew_failure' ) && str_contains( $scheduler, '$this->lock->owns( $owner )' ), 'Renew failure must fail only the matching job and must not release a foreign lock.' );
oz_pickup_scheduler_assert( str_contains( $repository, 'public function start( string $job_id ): ?int' ) && str_contains( $repository, 'false === $this->wpdb->insert' ) && str_contains( $repository, 'return $id > 0 ? $id : null' ), 'Generation creation must fail closed for insert errors and zero IDs.' );
oz_pickup_scheduler_assert( str_contains( $scheduler, 'null === $generation_id' ) && str_contains( $scheduler, 'pickup_step_schedule_failed' ) && substr_count( $scheduler, 'fail_job_and_release' ) >= 3 && str_contains( $scheduler, "if ( \$result['complete'] )" ), 'First and next step enqueue failures must fail the job, release the owner lock and retain normal completion.' );
oz_pickup_scheduler_assert( str_contains( $importer, 'public function fail_job' ) && str_contains( $importer, "'building' ===" ) && str_contains( $importer, '$this->repository->fail' ), 'Scheduler failure must use the carrier-owned importer failure path and not direct SQL.' );

echo "Ozon Delivery pickup scheduler smoke passed.\n";
