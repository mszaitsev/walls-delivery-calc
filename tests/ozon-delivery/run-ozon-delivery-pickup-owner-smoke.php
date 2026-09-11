<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {}
	function as_next_scheduled_action( string $hook, array $args = array(), string $group = '' ): int { return 0; }
	function wp_timezone(): \DateTimeZone { return new \DateTimeZone( 'UTC' ); }
}

namespace WallsShop\WDC\Infrastructure\Queue {
	final class ActionScheduler {
		/** @var list<array{hook:string,args:array<int,mixed>,group:string}> */
		public array $single = array();
		/** @var list<array{hook:string,args:array<int,mixed>,group:string}> */
		public array $unscheduled = array();
		/** @var list<array{timestamp:int,interval:int,hook:string,args:array<int,mixed>,group:string}> */
		public array $recurring = array();
		public ?int $next = null;
		public bool $initialized = false;
		/** @var array<string,callable> */
		private array $callbacks = array();
		/** @var array<string,true> */
		private array $completed = array();

		public function has_scheduled( string $hook, array $args = array(), string $group = '' ): bool { return false; }
		public function next_scheduled( string $hook, array $args = array(), string $group = '' ): ?int { return $this->next; }
		public function schedule_recurring( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '' ): ?int { $this->recurring[] = compact( 'timestamp', 'interval', 'hook', 'args', 'group' ); $this->next = $timestamp; return 1; }
		public function schedule_single( int $timestamp, string $hook, array $args = array(), string $group = '' ): ?int { $this->single[] = array( 'hook' => $hook, 'args' => $args, 'group' => $group ); return 1; }
		public function unschedule( string $hook, array $args = array(), string $group = '' ): void { $this->unscheduled[] = array( 'hook' => $hook, 'args' => $args, 'group' => $group ); $this->next = null; }
		public function when_initialized( string $owner, callable $callback ): void { if ( isset( $this->completed[ $owner ] ) ) { return; } if ( $this->initialized ) { $this->completed[ $owner ] = true; $callback(); return; } $this->callbacks[ $owner ] = $callback; }
		public function initialize(): void { $this->initialized = true; foreach ( $this->callbacks as $owner => $callback ) { if ( ! isset( $this->completed[ $owner ] ) ) { $this->completed[ $owner ] = true; $callback(); } } $this->callbacks = array(); }
	}
}

namespace WallsShop\WDC\Calendar\Services {
	final class TimezoneService {
		public function next_local_time_timestamp( string $time, ?\DateTimeImmutable $now = null ): int { return 1; }
		public function format_timestamp( int $timestamp, string $format = 'd.m.Y H:i' ): string { return gmdate( $format, $timestamp ); }
	}
}

namespace WallsShop\WDC\Carriers\OzonDelivery {
	final class OzonDeliverySettings {
		public function __construct( private bool $enabled = false, private string $time = '02:00' ) {}
		public function pickup_auto_sync_enabled(): bool { return $this->enabled; }
		public function pickup_sync_time(): string { return $this->time; }
	}
}

namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup {
	final class OzonDeliveryPickupImportService {
		/** @var array<string,mixed>|null */
		public ?array $building = null;
		public ?string $started_job_id = null;
		public ?string $started_owner = null;
		public ?int $cancelled_id = null;
		/** @var array{complete:bool,failed:bool,retry?:bool,retry_after?:int} */
		public array $step_result = array( 'complete' => true, 'failed' => false );

		public function start( string $job_id, ?string $lock_owner = null ): ?int {
			$this->started_job_id = $job_id;
			$this->started_owner = $lock_owner;
			return 7;
		}

		/** @return array<string,mixed>|null */
		public function building_generation(): ?array { return $this->building; }
		public function cancel_generation( int $generation_id ): bool { $this->cancelled_id = $generation_id; return true; }
		/** @return array{complete:bool,failed:bool,retry?:bool,retry_after?:int} */
		public function run_step( string $job_id ): array { return $this->step_result; }
		public function fail_job( string $job_id, string $code, string $message ): void {}
	}

	final class OzonDeliveryPickupImportLock {
		public ?string $next_owner = 'OWNER-A';
		public ?string $owned = 'OWNER-A';
		/** @var list<string> */
		public array $released = array();

		public function acquire(): ?string { return $this->next_owner; }
		public function renew( string $owner ): bool { return true; }
		public function owns( string $owner ): bool { return null !== $this->owned && $owner === $this->owned; }
		public function release( string $owner ): void { $this->released[] = $owner; if ( $this->owns( $owner ) ) { $this->owned = null; } }
		public function current_owner(): ?string { return $this->owned; }
	}

	require_once dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupScheduler.php';

	use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
	use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
	use WallsShop\WDC\Calendar\Services\TimezoneService;

	function oz_owner_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$existing_timestamp = gmmktime( 2, 0, 0, 9, 12, 2026 );
	$scheduler_adapter = new ActionScheduler();
	$scheduler_adapter->next = $existing_timestamp;
	$scheduler = new OzonDeliveryPickupScheduler( $scheduler_adapter, new OzonDeliveryPickupImportService(), new OzonDeliveryPickupImportLock(), new OzonDeliverySettings( true, '02:00' ), new TimezoneService() );
	$scheduler->register();
	oz_owner_assert( array() === $scheduler_adapter->recurring, 'Ozon register before AS init must not touch scheduling.' );
	$scheduler_adapter->initialize();
	oz_owner_assert( array() === $scheduler_adapter->recurring && array() === $scheduler_adapter->unscheduled, 'Matching existing Ozon daily action must be preserved after deferred ensure.' );

	$scheduler_adapter = new ActionScheduler();
	$scheduler_adapter->initialized = true;
	$scheduler_adapter->next = $existing_timestamp;
	$scheduler = new OzonDeliveryPickupScheduler( $scheduler_adapter, new OzonDeliveryPickupImportService(), new OzonDeliveryPickupImportLock(), new OzonDeliverySettings( true, '03:00' ), new TimezoneService() );
	$scheduler->register();
	oz_owner_assert( 1 === count( $scheduler_adapter->unscheduled ) && 1 === count( $scheduler_adapter->recurring ), 'Ozon register after AS init must immediately reschedule a changed local time.' );
	$scheduler->register();
	oz_owner_assert( 1 === count( $scheduler_adapter->recurring ), 'Repeated Ozon registration must not duplicate the daily action.' );

	$scheduler_adapter = new ActionScheduler();
	$importer = new OzonDeliveryPickupImportService();
	$lock = new OzonDeliveryPickupImportLock();
	$scheduler = new OzonDeliveryPickupScheduler( $scheduler_adapter, $importer, $lock, new OzonDeliverySettings(), new TimezoneService() );
	oz_owner_assert( $scheduler->start_manual() && 'OWNER-A' === $importer->started_owner && 1 === count( $scheduler_adapter->single ) && $scheduler_adapter->single[0]['args'][1] === 'OWNER-A', 'Normal start must store the acquired lock owner on the generation and scheduled step args.' );

	$scheduler_adapter = new ActionScheduler();
	$importer = new OzonDeliveryPickupImportService();
	$importer->building = array( 'id' => 11, 'job_id' => 'JOB-A', 'lock_owner' => 'OWNER-A' );
	$lock = new OzonDeliveryPickupImportLock();
	$lock->owned = 'OWNER-A';
	$scheduler = new OzonDeliveryPickupScheduler( $scheduler_adapter, $importer, $lock, new OzonDeliverySettings(), new TimezoneService() );
	oz_owner_assert( $scheduler->stop_manual() && 11 === $importer->cancelled_id && array( 'JOB-A', 'OWNER-A' ) === $scheduler_adapter->unscheduled[0]['args'] && array( 'OWNER-A' ) === $lock->released, 'Normal stop must cancel, unschedule exact job/owner args, and release matching owner.' );

	$scheduler_adapter = new ActionScheduler();
	$importer = new OzonDeliveryPickupImportService();
	$importer->building = array( 'id' => 12, 'job_id' => 'JOB-OLD', 'lock_owner' => 'OWNER-A' );
	$lock = new OzonDeliveryPickupImportLock();
	$lock->owned = 'OWNER-B';
	$scheduler = new OzonDeliveryPickupScheduler( $scheduler_adapter, $importer, $lock, new OzonDeliverySettings(), new TimezoneService() );
	oz_owner_assert( $scheduler->stop_manual() && array( 'JOB-OLD', 'OWNER-A' ) === $scheduler_adapter->unscheduled[0]['args'] && array() === $lock->released && 'OWNER-B' === $lock->owned, 'Stop must not release a newer/mismatched lock owner.' );

	$scheduler_adapter = new ActionScheduler();
	$importer = new OzonDeliveryPickupImportService();
	$importer->building = array( 'id' => 13, 'job_id' => 'JOB-MISSING', 'lock_owner' => 'OWNER-A' );
	$lock = new OzonDeliveryPickupImportLock();
	$lock->owned = null;
	$scheduler = new OzonDeliveryPickupScheduler( $scheduler_adapter, $importer, $lock, new OzonDeliverySettings(), new TimezoneService() );
	oz_owner_assert( $scheduler->stop_manual() && array( 'JOB-MISSING', 'OWNER-A' ) === $scheduler_adapter->unscheduled[0]['args'] && array() === $lock->released, 'Stop must cancel and unschedule even when the lock has already disappeared.' );

	$scheduler_adapter = new ActionScheduler();
	$importer = new OzonDeliveryPickupImportService();
	$importer->step_result = array( 'complete' => true, 'failed' => false );
	$lock = new OzonDeliveryPickupImportLock();
	$lock->owned = 'OWNER-B';
	$scheduler = new OzonDeliveryPickupScheduler( $scheduler_adapter, $importer, $lock, new OzonDeliverySettings(), new TimezoneService() );
	$scheduler->run_step( 'JOB-A', 'OWNER-A' );
	oz_owner_assert( array() === $scheduler_adapter->single && array() === $lock->released && 'OWNER-B' === $lock->owned, 'Stale complete step must not schedule more work or release a newer owner.' );

	$lock->next_owner = 'OWNER-C';
	$lock->owned = 'OWNER-C';
	$scheduler = new OzonDeliveryPickupScheduler( new ActionScheduler(), $importer, $lock, new OzonDeliverySettings(), new TimezoneService() );
	oz_owner_assert( $scheduler->start_manual() && 'OWNER-C' === $importer->started_owner, 'A new import must be able to start with a fresh owner after stop.' );

	echo "Ozon Delivery pickup owner smoke passed.\n";
}
