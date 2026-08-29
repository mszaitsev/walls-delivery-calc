<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;
use DateTimeImmutable;
use DateTimeZone;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryPickupScheduler {
	public const GROUP = 'walls-delivery-calc'; public const DAILY_HOOK = 'wdc_ozon_delivery_pickup_daily'; public const STEP_HOOK = 'wdc_ozon_delivery_pickup_step';
	public function __construct( private ActionScheduler $scheduler, private OzonDeliveryPickupImportService $importer, private OzonDeliveryPickupImportLock $lock, private OzonDeliverySettings $settings ) {}
	public function register(): void { add_action( self::DAILY_HOOK, array( $this, 'run_scheduled' ) ); add_action( self::STEP_HOOK, array( $this, 'run_step' ), 10, 2 ); $this->ensure_schedule(); }
	public function ensure_schedule(): void { if ( ! $this->settings->pickup_auto_sync_enabled() || $this->scheduler->has_scheduled( self::DAILY_HOOK, array(), self::GROUP ) ) { return; } $this->schedule(); }
	public function reschedule(): void { $this->scheduler->unschedule( self::DAILY_HOOK, array(), self::GROUP ); if ( $this->settings->pickup_auto_sync_enabled() ) { $this->schedule(); } }
	public function start_manual(): bool { return $this->start(); }
	public function run_scheduled(): void { $this->start(); }
	public function run_step( string $job_id, string $owner ): void { if ( ! $this->lock->renew( $owner ) ) { $this->fail_job_after_lock_renew_failure( $job_id, $owner ); return; } try { $result = $this->importer->run_step( $job_id ); } catch ( \Throwable ) { $this->fail_job_and_release( $job_id, $owner, 'pickup_scheduler_step_failed', 'Не удалось выполнить фоновый шаг синхронизации ПВЗ Ozon.' ); return; } if ( $result['complete'] ) { $this->lock->release( $owner ); return; } if ( null === $this->scheduler->schedule_single( time() + 1, self::STEP_HOOK, array( $job_id, $owner ), self::GROUP ) ) { $this->fail_job_and_release( $job_id, $owner, 'pickup_step_schedule_failed', 'Не удалось запланировать следующий шаг синхронизации ПВЗ Ozon.' ); } }
	public function next_run(): ?int { return $this->scheduler->has_scheduled( self::DAILY_HOOK, array(), self::GROUP ) ? (int) as_next_scheduled_action( self::DAILY_HOOK, array(), self::GROUP ) : null; }
	private function start(): bool { $owner = $this->lock->acquire(); if ( null === $owner ) { return false; } try { $job_id = bin2hex( random_bytes( 16 ) ); $generation_id = $this->importer->start( $job_id ); } catch ( \Throwable ) { $this->lock->release( $owner ); return false; } if ( null === $generation_id ) { $this->lock->release( $owner ); return false; } if ( null === $this->scheduler->schedule_single( time(), self::STEP_HOOK, array( $job_id, $owner ), self::GROUP ) ) { $this->fail_job_and_release( $job_id, $owner, 'pickup_step_schedule_failed', 'Не удалось запланировать первый шаг синхронизации ПВЗ Ozon.' ); return false; } return true; }
	private function fail_job_and_release( string $job_id, string $owner, string $code, string $message ): void { try { $this->importer->fail_job( $job_id, $code, $message ); } catch ( \Throwable ) { } $this->lock->release( $owner ); }
	private function fail_job_after_lock_renew_failure( string $job_id, string $owner ): void { try { $this->importer->fail_job( $job_id, 'pickup_lock_renew_failed', 'Фоновая синхронизация остановлена из-за потери блокировки выполнения.' ); } catch ( \Throwable ) { } if ( $this->lock->owns( $owner ) ) { $this->lock->release( $owner ); } }
	private function schedule(): void { $time = $this->settings->pickup_sync_time(); $zone = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ); $next = new DateTimeImmutable( 'today ' . $time, $zone ); if ( $next->getTimestamp() <= time() ) { $next = $next->modify( '+1 day' ); } $this->scheduler->schedule_recurring( $next->getTimestamp(), 86400, self::DAILY_HOOK, array(), self::GROUP ); }
}
