<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {}
	require_once dirname( __DIR__, 2 ) . '/src/Infrastructure/Background/BackgroundExecutionBudget.php';
}

namespace WallsShop\WDC\Infrastructure\Queue {
	final class ActionScheduler {
		/** @var list<array{timestamp:int,hook:string,args:array<int,mixed>,group:string}> */ public array $single = array();
		/** @var list<array{hook:string,args:array<int,mixed>,group:string}> */ public array $unscheduled = array();
		public bool $schedule_failure = false;
		public function schedule_single( int $timestamp, string $hook, array $args = array(), string $group = '' ): ?int { if ( $this->schedule_failure ) { return null; } $this->single[] = compact( 'timestamp', 'hook', 'args', 'group' ); return count( $this->single ); }
		public function has_scheduled( string $hook, array $args = array(), string $group = '' ): bool { foreach ( $this->single as $action ) { if ( $hook === $action['hook'] && $args === $action['args'] && $group === $action['group'] ) { return true; } } return false; }
		public function unschedule( string $hook, array $args = array(), string $group = '' ): void { $this->unscheduled[] = compact( 'hook', 'args', 'group' ); $this->single = array_values( array_filter( $this->single, static fn( array $action ): bool => $hook !== $action['hook'] || $args !== $action['args'] || $group !== $action['group'] ) ); }
		public function next_scheduled( string $hook, array $args = array(), string $group = '' ): ?int { return null; }
		public function schedule_recurring( int $timestamp, int $interval, string $hook, array $args = array(), string $group = '' ): ?int { return 1; }
		public function when_initialized( string $owner, callable $callback ): void { $callback(); }
	}
}

namespace WallsShop\WDC\Calendar\Services {
	final class TimezoneService { public function next_local_time_timestamp( string $time, ?\DateTimeImmutable $now = null ): int { return 1; } public function format_timestamp( int $timestamp, string $format = 'd.m.Y H:i' ): string { return '02:00'; } }
}

namespace WallsShop\WDC\Carriers\OzonDelivery {
	final class OzonDeliverySettings { public function __construct( private bool $enabled = false ) {} public function pickup_auto_sync_enabled(): bool { return $this->enabled; } public function pickup_sync_time(): string { return '02:00'; } }
}

namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup {
	final class OzonDeliveryPickupImportService {
		/** @var array<string,mixed>|null */ public ?array $building = array( 'id' => 7, 'job_id' => 'JOB-A', 'lock_owner' => 'OWNER-A', 'state' => 'building' );
		/** @var list<array{complete:bool,failed:bool,retry?:bool,retry_after?:int}|\Throwable> */ public array $results = array();
		public int $step_calls = 0; public int $fail_calls = 0; public int $start_calls = 0; public int $cancel_calls = 0;
		/** @var callable|null */ public $after_step = null;
		public function building_generation(): ?array { return $this->building; }
		public function run_step( string $job_id ): array { ++$this->step_calls; if ( is_callable( $this->after_step ) ) { ( $this->after_step )( $this->step_calls ); } $result = array_shift( $this->results ) ?? array( 'complete' => false, 'failed' => false ); if ( $result instanceof \Throwable ) { throw $result; } return $result; }
		public function fail_job( string $job_id, string $code, string $message ): void { ++$this->fail_calls; if ( is_array( $this->building ) && $job_id === $this->building['job_id'] ) { $this->building = null; } }
		public function start( string $job_id, ?string $lock_owner = null ): ?int { ++$this->start_calls; $this->building = array( 'id' => 8, 'job_id' => $job_id, 'lock_owner' => $lock_owner, 'state' => 'building' ); return 8; }
		public function cancel_generation( int $generation_id ): bool { ++$this->cancel_calls; $this->building = null; return true; }
	}

	final class OzonDeliveryPickupImportLock {
		public ?string $owner = 'OWNER-A';
		public ?string $execution_token = null;
		/** @var list<bool> */ public array $renew_results = array();
		public int $renew_calls = 0; /** @var list<string> */ public array $released = array();
		public function acquire(): ?string { return $this->owner; }
		public function renew( string $owner ): bool { ++$this->renew_calls; $result = array_shift( $this->renew_results ); return null !== $result ? $result : $this->owns( $owner ); }
		public function claim_execution( string $owner ): ?string { if ( ! $this->owns( $owner ) || null !== $this->execution_token ) { return null; } return $this->execution_token = 'EXECUTION-' . $owner; }
		public function renew_execution( string $owner, string $token ): bool { ++$this->renew_calls; $result = array_shift( $this->renew_results ); return null !== $result ? $result : $this->owns_execution( $owner, $token ); }
		public function owns_execution( string $owner, string $token ): bool { return $this->owns( $owner ) && null !== $this->execution_token && hash_equals( $this->execution_token, $token ); }
		public function release_execution( string $owner, string $token ): void { if ( $this->owns_execution( $owner, $token ) ) { $this->execution_token = null; } }
		public function owns( string $owner ): bool { return null !== $this->owner && hash_equals( $this->owner, $owner ); }
		public function current_owner(): ?string { return $this->owner; }
		public function release( string $owner ): void { if ( $this->owns( $owner ) ) { $this->released[] = $owner; $this->owner = null; $this->execution_token = null; } }
	}

	require_once dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupScheduler.php';

	use WallsShop\WDC\Calendar\Services\TimezoneService;
	use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
	use WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget;
	use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;

	function oz_worker_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new \RuntimeException( $message ); } }
	function oz_worker_budget( int $units ): callable { return static fn(): BackgroundExecutionBudget => new BackgroundExecutionBudget( 18.0, $units, null, static fn(): float => 0.0 ); }
	/** @return array{0:OzonDeliveryPickupScheduler,1:ActionScheduler,2:OzonDeliveryPickupImportService,3:OzonDeliveryPickupImportLock} */
	function oz_worker_fixture( callable $budget ): array { $queue = new ActionScheduler(); $importer = new OzonDeliveryPickupImportService(); $lock = new OzonDeliveryPickupImportLock(); return array( new OzonDeliveryPickupScheduler( $queue, $importer, $lock, new OzonDeliverySettings(), new TimezoneService(), $budget ), $queue, $importer, $lock ); }

	[ $worker, $queue, $importer, $lock ] = oz_worker_fixture( oz_worker_budget( 3 ) );
	$worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 3 === $importer->step_calls && 1 === count( $queue->single ) && array( 'JOB-A', 'OWNER-A' ) === $queue->single[0]['args'], 'One callback must run three units and create one exact continuation at the unit cap.' );

	$clock_values = array( 0.0, 0.0, 0.0, 19.0 ); $clock = static function () use ( &$clock_values ): float { return array_shift( $clock_values ) ?? 19.0; };
	[ $worker, $queue, $importer ] = oz_worker_fixture( static fn(): BackgroundExecutionBudget => new BackgroundExecutionBudget( 18.0, 10, null, $clock ) ); $worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 1 === $importer->step_calls && 1 === count( $queue->single ), 'Soft wall-time must stop without sleep and leave one continuation.' );

	$memory_values = array( 0, 100 ); $memory = static function () use ( &$memory_values ): int { return array_shift( $memory_values ) ?? 100; };
	[ $worker, $queue, $importer ] = oz_worker_fixture( static fn(): BackgroundExecutionBudget => new BackgroundExecutionBudget( 18.0, 10, 80, static fn(): float => 0.0, $memory ) ); $worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 1 === $importer->step_calls && 1 === count( $queue->single ), 'Memory threshold must stop deterministically and leave one continuation.' );

	[ $worker, $queue, $importer, $lock ] = oz_worker_fixture( oz_worker_budget( 10 ) ); $importer->results = array( array( 'complete' => false, 'failed' => false ), array( 'complete' => true, 'failed' => false ), array( 'complete' => false, 'failed' => false ) ); $worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 2 === $importer->step_calls && array() === $queue->single && array( 'OWNER-A' ) === $lock->released, 'Completion on unit two must release own lock, schedule nothing and stop further units.' );

	[ $worker, $queue, $importer, $lock ] = oz_worker_fixture( oz_worker_budget( 10 ) ); $importer->results = array( array( 'complete' => false, 'failed' => false, 'retry' => true, 'retry_after' => 5 ) ); $before = time(); $worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 1 === $importer->step_calls && 1 === count( $queue->single ) && $queue->single[0]['timestamp'] >= $before + 5 && 'OWNER-A' === $lock->owner, 'Retry must stop the slice immediately, retain ownership and schedule exactly one five-second continuation.' );

	[ $worker, $queue, $importer, $lock ] = oz_worker_fixture( oz_worker_budget( 10 ) ); $importer->results = array( new \RuntimeException( 'boom' ) ); $worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 1 === $importer->step_calls && 1 === $importer->fail_calls && array() === $queue->single && array( 'OWNER-A' ) === $lock->released, 'Thrown worker step must fail owned generation, release own lock and schedule nothing.' );

	[ $worker, $queue, $importer, $lock ] = oz_worker_fixture( oz_worker_budget( 10 ) ); $importer->after_step = static function () use ( $importer ): void { $importer->building = null; }; $worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 1 === $importer->step_calls && array() === $queue->single && 0 === $importer->fail_calls, 'Cancellation after one atomic unit must prevent a second unit and continuation.' );

	[ $worker, $queue, $importer, $lock ] = oz_worker_fixture( oz_worker_budget( 10 ) ); $importer->after_step = static function () use ( $lock ): void { $lock->owner = 'OWNER-B'; }; $worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 1 === $importer->step_calls && array() === $queue->single && array() === $lock->released && 0 === $importer->fail_calls, 'Lost ownership between units must stop without scheduling, failing, or releasing the new owner.' );

	[ $worker, $queue, $importer, $lock ] = oz_worker_fixture( oz_worker_budget( 10 ) ); $lock->owner = 'OWNER-B'; $worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 0 === $importer->step_calls && 0 === $importer->fail_calls && array() === $lock->released, 'Foreign owner callback must do no work and must not mutate the current owner.' );
	[ $worker, $queue, $importer, $lock ] = oz_worker_fixture( oz_worker_budget( 10 ) ); $lock->owner = null; $worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 0 === $importer->step_calls && 1 === $importer->fail_calls && array() === $queue->single, 'A missing lease before the slice must fail the matching building generation without calling the importer.' );

	[ $worker, $queue, $importer, $lock ] = oz_worker_fixture( oz_worker_budget( 1 ) ); $queue->schedule_failure = true; $worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 1 === $importer->step_calls && 1 === $importer->fail_calls && array( 'OWNER-A' ) === $lock->released && array() === $queue->single, 'Continuation enqueue failure must preserve terminal failure semantics and release the owned lease.' );

	[ $worker, $queue, $importer, $lock ] = oz_worker_fixture( oz_worker_budget( 1 ) ); $worker->run_step( 'JOB-A', 'OWNER-A' ); $worker->run_step( 'JOB-A', 'OWNER-B' );
	oz_worker_assert( 1 === $importer->step_calls && 1 === count( $queue->single ), 'Overlapping foreign callback must not process the same generation or duplicate its continuation.' );
	$worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 2 === $importer->step_calls && 1 === count( $queue->single ), 'An existing exact continuation must not be duplicated.' );

	[ $worker, $queue, $importer ] = oz_worker_fixture( oz_worker_budget( 1 ) ); $importer->after_step = static function ( int $step ) use ( &$worker ): void { if ( 1 === $step ) { $worker->run_step( 'JOB-A', 'OWNER-A' ); } }; $worker->run_step( 'JOB-A', 'OWNER-A' );
	oz_worker_assert( 1 === $importer->step_calls && 1 === count( $queue->single ), 'A concurrent callback with the same job owner must be rejected by the per-callback execution claim.' );

	[ $worker, $queue, $importer, $lock ] = oz_worker_fixture( oz_worker_budget( 1 ) ); $worker->ensure_schedule(); $worker->ensure_schedule();
	oz_worker_assert( 1 === count( $queue->single ) && 0 === $importer->step_calls, 'Bootstrap self-heal must schedule one missing exact continuation without executing work or duplicating it.' );
	$queue = new ActionScheduler(); $importer = new OzonDeliveryPickupImportService(); $lock = new OzonDeliveryPickupImportLock(); $lock->owner = 'OWNER-B'; $worker = new OzonDeliveryPickupScheduler( $queue, $importer, $lock, new OzonDeliverySettings(), new TimezoneService(), oz_worker_budget( 1 ) ); $worker->ensure_schedule();
	oz_worker_assert( array() === $queue->single && 0 === $importer->step_calls, 'Self-heal must not resurrect a building generation owned by a foreign lease.' );

	echo "Ozon Delivery pickup bounded worker smoke passed.\n";
}
