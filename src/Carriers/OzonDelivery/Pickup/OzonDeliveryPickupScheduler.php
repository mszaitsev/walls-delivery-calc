<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryPickupScheduler {
	public const GROUP = 'walls-delivery-calc'; public const DAILY_HOOK = 'wdc_ozon_delivery_pickup_daily'; public const STEP_HOOK = 'wdc_ozon_delivery_pickup_step';
	private const WORKER_SOFT_TIME_BUDGET_SECONDS = 18.0;
	private const MAX_UNITS_PER_WORKER_SLICE = 10;
	private const MEMORY_BUDGET_FRACTION = 0.8;

	public function __construct( private ActionScheduler $scheduler, private OzonDeliveryPickupImportService $importer, private OzonDeliveryPickupImportLock $lock, private OzonDeliverySettings $settings, private TimezoneService $timezone, private mixed $execution_budget_factory = null ) {}
	public function register(): void { add_action( self::DAILY_HOOK, array( $this, 'run_scheduled' ) ); add_action( self::STEP_HOOK, array( $this, 'run_step' ), 10, 2 ); $this->scheduler->when_initialized( self::class, array( $this, 'ensure_schedule' ) ); }
	public function ensure_schedule(): void {
		$this->ensure_worker_continuation();
		if ( ! $this->settings->pickup_auto_sync_enabled() ) { $this->scheduler->unschedule( self::DAILY_HOOK, array(), self::GROUP ); return; }
		$next = $this->scheduler->next_scheduled( self::DAILY_HOOK, array(), self::GROUP );
		if ( null !== $next && $this->settings->pickup_sync_time() === $this->timezone->format_timestamp( $next, 'H:i' ) ) { return; }
		if ( null !== $next ) { $this->scheduler->unschedule( self::DAILY_HOOK, array(), self::GROUP ); }
		$this->schedule();
	}
	public function reschedule(): void { $this->scheduler->unschedule( self::DAILY_HOOK, array(), self::GROUP ); if ( $this->settings->pickup_auto_sync_enabled() ) { $this->schedule(); } }
	public function start_manual(): bool { return $this->start(); }
	public function stop_manual(): bool { $generation = $this->importer->building_generation(); if ( ! is_array( $generation ) ) { return false; } $owner = isset( $generation['lock_owner'] ) && is_scalar( $generation['lock_owner'] ) ? (string) $generation['lock_owner'] : ''; if ( ! $this->importer->cancel_generation( (int) $generation['id'] ) ) { return false; } if ( '' !== $owner ) { $this->scheduler->unschedule( self::STEP_HOOK, array( (string) $generation['job_id'], $owner ), self::GROUP ); if ( $this->lock->owns( $owner ) ) { $this->lock->release( $owner ); } } return true; }
	public function run_scheduled(): void { $this->start(); }
	public function run_step( string $job_id, string $owner ): void {
		$execution_token = null;
		try {
			if ( ! $this->owned_building_generation( $job_id, $owner ) ) { return; }
			$execution_token = $this->lock->claim_execution( $owner );
			if ( null === $execution_token ) {
				$current_owner = $this->lock->current_owner();
				if ( null === $current_owner || ! hash_equals( $owner, $current_owner ) ) { $this->fail_job_after_lock_renew_failure( $job_id, $owner ); }
				return;
			}
		} catch ( \Throwable ) {
			$this->fail_job_and_release( $job_id, $owner, 'pickup_scheduler_step_failed', 'Не удалось выполнить фоновый шаг синхронизации ПВЗ Ozon.' );
			return;
		}

		try {
			$budget = $this->new_execution_budget();
			$budget->start();
			try {
			while ( $budget->can_continue() ) {
				if ( ! $this->owned_building_generation( $job_id, $owner ) || ! $this->lock->owns_execution( $owner, $execution_token ) ) { return; }
				$result = $this->importer->run_step( $job_id );
				$budget->mark_unit_processed();

				if ( ! empty( $result['complete'] ) ) {
					if ( $this->lock->owns( $owner ) ) { $this->lock->release( $owner ); }
					return;
				}
				if ( ! empty( $result['retry'] ) ) {
					$this->schedule_continuation_or_fail( $job_id, $owner, max( 1, (int) ( $result['retry_after'] ?? 1 ) ) );
					return;
				}
				if ( ! $this->owned_building_generation( $job_id, $owner ) || ! $this->lock->renew_execution( $owner, $execution_token ) ) {
					$this->fail_job_after_lock_renew_failure( $job_id, $owner );
					return;
				}
			}
			} catch ( \Throwable ) {
				$this->fail_job_and_release( $job_id, $owner, 'pickup_scheduler_step_failed', 'Не удалось выполнить фоновый шаг синхронизации ПВЗ Ozon.' );
				return;
			}

			if ( $this->owned_building_generation( $job_id, $owner ) && $this->lock->owns_execution( $owner, $execution_token ) ) {
				$this->schedule_continuation_or_fail( $job_id, $owner, 1 );
			}
		} finally {
			if ( null !== $execution_token ) { $this->lock->release_execution( $owner, $execution_token ); }
		}
	}
	public function next_run(): ?int { return $this->scheduler->next_scheduled( self::DAILY_HOOK, array(), self::GROUP ); }
	private function start(): bool { $owner = $this->lock->acquire(); if ( null === $owner ) { return false; } try { $job_id = bin2hex( random_bytes( 16 ) ); $generation_id = $this->importer->start( $job_id, $owner ); } catch ( \Throwable ) { $this->lock->release( $owner ); return false; } if ( null === $generation_id ) { $this->lock->release( $owner ); return false; } if ( ! $this->ensure_continuation( $job_id, $owner, 0 ) ) { $this->fail_job_and_release( $job_id, $owner, 'pickup_step_schedule_failed', 'Не удалось запланировать первый шаг синхронизации ПВЗ Ozon.' ); return false; } return true; }
	private function fail_job_and_release( string $job_id, string $owner, string $code, string $message ): void { try { if ( $this->owned_building_generation( $job_id, $owner ) && $this->lock->owns( $owner ) ) { $this->importer->fail_job( $job_id, $code, $message ); } } catch ( \Throwable ) { } try { if ( $this->lock->owns( $owner ) ) { $this->lock->release( $owner ); } } catch ( \Throwable ) { } }
	private function fail_job_after_lock_renew_failure( string $job_id, string $owner ): void {
		$current_owner = $this->lock->current_owner();
		if ( $this->owned_building_generation( $job_id, $owner ) && ( null === $current_owner || hash_equals( $owner, $current_owner ) ) ) {
			try { $this->importer->fail_job( $job_id, 'pickup_lock_renew_failed', 'Фоновая синхронизация остановлена из-за потери блокировки выполнения.' ); } catch ( \Throwable ) { }
		}
		if ( $this->lock->owns( $owner ) ) { $this->lock->release( $owner ); }
	}
	private function owned_building_generation( string $job_id, string $owner ): bool {
		$generation = $this->importer->building_generation();
		return is_array( $generation )
			&& hash_equals( (string) ( $generation['job_id'] ?? '' ), $job_id )
			&& hash_equals( (string) ( $generation['lock_owner'] ?? '' ), $owner );
	}
	private function schedule_continuation_or_fail( string $job_id, string $owner, int $delay ): void {
		if ( ! $this->ensure_continuation( $job_id, $owner, $delay ) ) {
			$this->fail_job_and_release( $job_id, $owner, 'pickup_step_schedule_failed', 'Не удалось запланировать следующий шаг синхронизации ПВЗ Ozon.' );
		}
	}
	private function ensure_continuation( string $job_id, string $owner, int $delay ): bool {
		$args = array( $job_id, $owner );
		if ( $this->scheduler->has_scheduled( self::STEP_HOOK, $args, self::GROUP ) ) { return true; }
		return null !== $this->scheduler->schedule_single( time() + max( 0, $delay ), self::STEP_HOOK, $args, self::GROUP );
	}
	private function ensure_worker_continuation(): void {
		$generation = $this->importer->building_generation();
		if ( ! is_array( $generation ) ) { return; }
		$job_id = (string) ( $generation['job_id'] ?? '' );
		$owner = (string) ( $generation['lock_owner'] ?? '' );
		if ( '' === $job_id || '' === $owner || $owner !== $this->lock->current_owner() ) { return; }
		$execution_token = $this->lock->claim_execution( $owner );
		if ( null === $execution_token ) { return; }
		try {
			if ( $this->owned_building_generation( $job_id, $owner ) && $this->lock->owns_execution( $owner, $execution_token ) ) { $this->ensure_continuation( $job_id, $owner, 1 ); }
		} finally {
			$this->lock->release_execution( $owner, $execution_token );
		}
	}
	private function new_execution_budget(): BackgroundExecutionBudget {
		if ( is_callable( $this->execution_budget_factory ) ) {
			$budget = ( $this->execution_budget_factory )();
			if ( $budget instanceof BackgroundExecutionBudget ) { return $budget; }
		}
		$memory_limit = function_exists( 'ini_get' ) ? (string) ini_get( 'memory_limit' ) : '';
		return new BackgroundExecutionBudget( self::WORKER_SOFT_TIME_BUDGET_SECONDS, self::MAX_UNITS_PER_WORKER_SLICE, BackgroundExecutionBudget::memory_threshold_from_limit( $memory_limit, self::MEMORY_BUDGET_FRACTION ) );
	}
	private function schedule(): void { $this->scheduler->schedule_recurring( $this->timezone->next_local_time_timestamp( $this->settings->pickup_sync_time() ), 86400, self::DAILY_HOOK, array(), self::GROUP ); }
}
