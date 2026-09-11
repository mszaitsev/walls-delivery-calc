<?php
declare(strict_types=1);

namespace WallsShop\WDC\Admin;

use WallsShop\WDC\Calendar\Services\CalendarScheduler;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointAutoSync;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupScheduler;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Gar\GarSyncManager;
use WallsShop\WDC\Locations\Import\FiasImportManager;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImporter;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncCron;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncService;

defined( 'ABSPATH' ) || exit;

final class ScheduledTaskCatalog {
	public const TASK_KEYS = array( 'shipment_statuses', 'russian_post_pickup', 'ozon_pickup', 'yandex_geo', 'dpd_pickup', 'gar', 'fias', 'calendar' );

	public function __construct(
		private ActionScheduler $action_scheduler,
		private TimezoneService $timezone,
		private SettingsRepository $settings,
		private DpdSettings $dpd_settings,
		private RussianPostOtpravkaApiSettings $russian_post_settings,
		private OzonDeliverySettings $ozon_settings,
		private OzonDeliveryPickupScheduler $ozon_scheduler,
		private YandexDeliveryGeoPipelineV2Runner $yandex_runner,
		private ShipmentStatusAutoSyncService $shipment_status_auto_sync
	) {
	}

	/** @return array<int,array{key:string,label:string,schedule:string,next_run:string,status:string}> */
	public function tasks(): array {
		$dpd_times = $this->dpd_settings->pickup_autosync_times();
		$yandex = $this->yandex_runner->schedule_settings();
		$status_enabled = $this->shipment_status_auto_sync->enabled()
			&& $this->settings->get_bool( 'woocommerce_runtime_enabled', true );

		return array(
			$this->task( 'shipment_statuses', 'Автосинхронизация статусов отправлений', 'Каждые ' . $this->shipment_status_auto_sync->format_interval_minutes( $this->shipment_status_auto_sync->interval_minutes() ), $status_enabled, $this->wp_next( ShipmentStatusAutoSyncCron::HOOK ) ),
			$this->task( 'russian_post_pickup', 'Обновление ПВЗ Почты России', 'Раз в неделю', $this->russian_post_settings->schedule_enabled(), $this->wp_next( RussianPostPickupImporter::SCHEDULE_HOOK ) ),
			$this->task( 'ozon_pickup', 'Обновление ПВЗ Ozon Delivery', 'Ежедневно в ' . $this->ozon_settings->pickup_sync_time(), $this->ozon_settings->pickup_auto_sync_enabled(), $this->ozon_scheduler->next_run() ),
			$this->task( 'yandex_geo', 'Полное обновление ПВЗ/географии Яндекс', $this->yandex_schedule( $yandex ), ! empty( $yandex['enabled'] ), $this->yandex_runner->next_run_timestamp() ),
			$this->task( 'dpd_pickup', 'Обновление ПВЗ DPD', array() === $dpd_times ? '—' : implode( ', ', $dpd_times ), $this->dpd_settings->pickup_autosync_enabled() && array() !== $dpd_times, $this->next_dpd_run( $dpd_times ) ),
			$this->task( 'gar', 'Проверка обновлений GAR', 'Каждые 24 часа', $this->settings->get_bool( 'gar_sync_enabled', false ), $this->action_scheduler->next_scheduled( GarSyncManager::DAILY_HOOK ) ),
			$this->task( 'fias', 'Проверка подготовленного FIAS dataset', 'Каждые 7 дней', true, $this->action_scheduler->next_scheduled( FiasImportManager::WEEKLY_HOOK ) ),
			$this->task( 'calendar', 'Генерация календаря следующего года', 'Первый понедельник месяца в 09:00', true, $this->action_scheduler->next_scheduled( CalendarScheduler::HOOK ) )
		);
	}

	/** @return array{key:string,label:string,schedule:string,next_run:string,status:string} */
	private function task( string $key, string $label, string $schedule, bool $enabled, ?int $next_run ): array {
		return array(
			'key' => $key,
			'label' => $label,
			'schedule' => $schedule,
			'next_run' => $enabled && null !== $next_run ? $this->timezone->format_timestamp( $next_run ) : '—',
			'status' => ! $enabled ? 'Отключена' : ( null === $next_run ? 'Не запланировано' : 'Запланировано' ),
		);
	}

	/** @param array<int,string> $times */
	private function next_dpd_run( array $times ): ?int {
		$next = array();
		foreach ( $times as $time ) {
			$timestamp = $this->wp_next( DpdPickupPointAutoSync::HOOK, array( $time ) );
			if ( null !== $timestamp ) {
				$next[] = $timestamp;
			}
		}

		return array() === $next ? null : min( $next );
	}

	/** @param array<string,mixed> $settings */
	private function yandex_schedule( array $settings ): string {
		$labels = array( 1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс' );
		$days = array();
		foreach ( (array) ( $settings['days'] ?? array() ) as $day ) {
			if ( isset( $labels[ (int) $day ] ) ) {
				$days[] = $labels[ (int) $day ];
			}
		}

		return array() === $days ? '—' : implode( ', ', $days ) . ' в ' . (string) ( $settings['time'] ?? '03:00' );
	}

	/** @param array<int,mixed> $args */
	private function wp_next( string $hook, array $args = array() ): ?int {
		if ( ! function_exists( 'wp_next_scheduled' ) ) {
			return null;
		}
		$timestamp = wp_next_scheduled( $hook, $args );

		return false === $timestamp ? null : (int) $timestamp;
	}
}
