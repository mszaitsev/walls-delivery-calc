<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2BuilderRunnerService;
use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2RunnerService;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoPipelineV2Runner {
	public const CRON_HOOK = 'wdc_yandex_delivery_geo_pipeline_v2_run_step';
	public const SCHEDULE_HOOK = 'wdc_yandex_delivery_geo_pipeline_v2_scheduled_start';
	private const STATE_OPTION = 'wdc_yandex_delivery_geo_pipeline_v2_state';
	private const SCHEDULE_OPTION = 'wdc_yandex_delivery_geo_pipeline_v2_schedule';
	private const STAGE_IMPORT_PVZ = 'import_pvz';
	private const STAGE_BUILD_GEO_V2 = 'build_geo_v2';
	private const STAGE_REGION_ENRICHMENT = 'region_enrichment';
	private const STAGE_REGION_MAPPING = 'region_mapping';
	private const STAGE_LOCATION_MAPPING = 'location_mapping';
	private const STAGE_DONE = 'done';

	public function __construct(
		private YandexDeliveryPickupPointV2RunnerService $pickup_runner,
		private YandexDeliveryPickupPointV2Repository $pickup_repository,
		private YandexDeliveryGeoV2BuilderRunnerService $geo_builder_runner,
		private YandexDeliveryGeoV2Repository $geo_repository,
		private YandexGeoV2RegionEnrichmentRunner $region_enrichment_runner,
		private YandexRegionMappingV2Repository $region_mapping_repository,
		private YandexLocationMappingV2Runner $location_mapping_runner,
		private YandexLocationMappingV2Repository $location_mapping_repository
	) {
	}

	/** @return array<string,mixed> */
	public function start(): array {
		$current = $this->current_state();
		if ( in_array( (string) ( $current['status'] ?? '' ), array( 'running', 'paused' ), true ) ) {
			return $current;
		}
		$state = $this->base_state( 'running', self::STAGE_IMPORT_PVZ );
		$state['session_id'] = sha1( uniqid( 'yandex-geo-pipeline-v2-', true ) );
		$state['started_at'] = $this->now();
		$state['updated_at'] = $state['started_at'];
		$state['message'] = 'Запускаем полное обновление Яндекс ПВЗ/географии.';
		$this->pickup_runner->reset();
		$this->save_state( $state );
		$this->schedule_next_step( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function resume(): array {
		$state = $this->current_state();
		if ( 'paused' === (string) ( $state['status'] ?? '' ) ) {
			$state['status'] = 'running';
			$state['updated_at'] = $this->now();
			$state['message'] = 'Продолжаем полное обновление Яндекс ПВЗ/географии.';
			$this->save_state( $state );
			$this->schedule_next_step( $state );
		}

		return $state;
	}

	/** @return array<string,mixed> */
	public function run_step(): array {
		$state = $this->current_state();
		if ( 'running' !== (string) ( $state['status'] ?? '' ) ) {
			return $state;
		}

		try {
			$stage = (string) ( $state['stage'] ?? self::STAGE_IMPORT_PVZ );
			$state = match ( $stage ) {
				self::STAGE_IMPORT_PVZ => $this->run_import_stage( $state ),
				self::STAGE_BUILD_GEO_V2 => $this->run_geo_builder_stage( $state ),
				self::STAGE_REGION_ENRICHMENT => $this->run_region_enrichment_stage( $state ),
				self::STAGE_REGION_MAPPING => $this->run_region_mapping_stage( $state ),
				self::STAGE_LOCATION_MAPPING => $this->run_location_mapping_stage( $state ),
				default => $this->complete( $state ),
			};
		} catch ( \Throwable $exception ) {
			$state = $this->fail( $state, $exception );
		}
		$this->save_state( $state );
		$this->schedule_next_step( $state );

		return $state;
	}

	public function run_scheduled_step(): void {
		$this->run_step();
	}

	public function run_scheduled_start(): void {
		$state = $this->current_state();
		if ( ! in_array( (string) ( $state['status'] ?? '' ), array( 'running', 'paused' ), true ) ) {
			$this->start();
		}
		$this->ensure_schedule();
	}

	/** @return array<string,mixed> */
	public function pause(): array {
		$state = $this->current_state();
		if ( 'running' !== (string) ( $state['status'] ?? '' ) ) {
			return $state;
		}
		match ( (string) ( $state['stage'] ?? '' ) ) {
			self::STAGE_IMPORT_PVZ => $this->pickup_runner->pause(),
			self::STAGE_BUILD_GEO_V2 => $this->geo_builder_runner->pause(),
			self::STAGE_REGION_ENRICHMENT => $this->region_enrichment_runner->pause(),
			self::STAGE_LOCATION_MAPPING => $this->location_mapping_runner->pause(),
			default => null,
		};
		$state['status'] = 'paused';
		$this->clear_scheduled_step();
		$state['updated_at'] = $this->now();
		$state['message'] = 'Полное обновление Яндекс ПВЗ/географии поставлено на паузу.';
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function reset(): array {
		$state = $this->base_state( 'idle', self::STAGE_IMPORT_PVZ );
		$state['message'] = 'Состояние полного обновления сброшено.';
		$this->clear_scheduled_step();
		$this->save_state( $state );

		return $state;
	}

	/** @return array<string,mixed> */
	public function current_state(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return $this->base_state( 'idle', self::STAGE_IMPORT_PVZ );
		}
		$state = get_option( self::STATE_OPTION, array() );

		return is_array( $state ) && array() !== $state ? array_merge( $this->base_state( 'idle', self::STAGE_IMPORT_PVZ ), $state ) : $this->base_state( 'idle', self::STAGE_IMPORT_PVZ );
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function run_import_stage( array $state ): array {
		$pickup_state = $this->pickup_runner->current_state();
		$status = (string) ( $pickup_state['status'] ?? 'idle' );
		if ( 'idle' === $status ) {
			$pickup_state = $this->pickup_runner->start_full_api_sync();
		} elseif ( 'ready_to_import' === $status || 'paused' === $status ) {
			$pickup_state = $this->pickup_runner->start_import();
		} elseif ( 'importing' === $status ) {
			$pickup_state = $this->pickup_runner->run_import_step();
		}
		$state = $this->with_stage_progress( $state, $pickup_state );
		$state['message'] = (string) ( $pickup_state['message'] ?? '' );
		$state['summary']['import_pvz'] = $this->pickup_summary( $pickup_state );
		if ( 'error' === (string) ( $pickup_state['status'] ?? '' ) ) {
			return $this->stage_error( $state, $state['message'] );
		}
		if ( 'done' === (string) ( $pickup_state['status'] ?? '' ) ) {
			$this->geo_repository->truncate();
			$this->geo_builder_runner->reset();
			$this->geo_builder_runner->start();
			return $this->advance( $state, self::STAGE_BUILD_GEO_V2, 'Импорт ПВЗ завершен. Переходим к построению geo_v2.' );
		}

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function run_geo_builder_stage( array $state ): array {
		$geo_state = $this->geo_builder_runner->current_state();
		if ( 'idle' === (string) ( $geo_state['status'] ?? '' ) || 'paused' === (string) ( $geo_state['status'] ?? '' ) ) {
			$geo_state = $this->geo_builder_runner->start();
		} elseif ( 'building' === (string) ( $geo_state['status'] ?? '' ) ) {
			$geo_state = $this->geo_builder_runner->run_step();
		}
		$state = $this->with_stage_progress( $state, $geo_state, 'processed_geo_ids' );
		$state = $this->with_total( $state, $this->pickup_repository->count_active_unique_geo_ids() );
		$state['message'] = (string) ( $geo_state['message'] ?? '' );
		$state['summary']['build_geo_v2'] = $this->geo_summary();
		if ( 'error' === (string) ( $geo_state['status'] ?? '' ) ) {
			return $this->stage_error( $state, $state['message'] );
		}
		if ( 'done' === (string) ( $geo_state['status'] ?? '' ) ) {
			$this->region_enrichment_runner->start();
			return $this->advance( $state, self::STAGE_REGION_ENRICHMENT, 'geo_v2 построена. Переходим к обогащению пустых регионов.' );
		}

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function run_region_enrichment_stage( array $state ): array {
		$enrichment_state = $this->region_enrichment_runner->current_state();
		if ( 'idle' === (string) ( $enrichment_state['status'] ?? '' ) || 'paused' === (string) ( $enrichment_state['status'] ?? '' ) ) {
			$enrichment_state = $this->region_enrichment_runner->start();
		} elseif ( 'enriching_regions' === (string) ( $enrichment_state['status'] ?? '' ) ) {
			$enrichment_state = $this->region_enrichment_runner->run_step();
		}
		$state = $this->with_stage_progress( $state, $enrichment_state );
		$state['total'] = (int) ( $enrichment_state['processed'] ?? 0 ) + (int) ( $enrichment_state['pending_empty_regions_remaining'] ?? 0 );
		$state['percent'] = $this->percent( (int) $state['processed'], (int) $state['total'] );
		$state['message'] = (string) ( $enrichment_state['message'] ?? '' );
		$state['summary']['region_enrichment'] = $this->only_keys( $enrichment_state, array( 'processed', 'updated', 'needs_review', 'not_found', 'skipped', 'errors' ) );
		if ( 'error' === (string) ( $enrichment_state['status'] ?? '' ) ) {
			return $this->stage_error( $state, $state['message'] );
		}
		if ( 'done' === (string) ( $enrichment_state['status'] ?? '' ) ) {
			return $this->advance( $state, self::STAGE_REGION_MAPPING, 'Обогащение регионов завершено. Синхронизируем справочник регионов.' );
		}

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function run_region_mapping_stage( array $state ): array {
		$result = $this->region_mapping_repository->sync_from_sources();
		$state['summary']['region_mapping'] = $result;
		$state['processed'] = (int) ( $result['yandex_regions'] ?? 0 );
		$state['total'] = (int) ( $result['yandex_regions'] ?? 0 );
		$state['percent'] = $this->percent( (int) $state['processed'], (int) $state['total'] );
		$this->location_mapping_runner->reset();
		$this->location_mapping_runner->start();

		return $this->advance( $state, self::STAGE_LOCATION_MAPPING, 'Справочник регионов синхронизирован. Переходим к location mapping v2.' );
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function run_location_mapping_stage( array $state ): array {
		$mapping_state = $this->location_mapping_runner->current_state();
		if ( 'idle' === (string) ( $mapping_state['status'] ?? '' ) || 'paused' === (string) ( $mapping_state['status'] ?? '' ) ) {
			$mapping_state = $this->location_mapping_runner->start();
		} elseif ( 'mapping' === (string) ( $mapping_state['status'] ?? '' ) ) {
			$mapping_state = $this->location_mapping_runner->run_step();
		}
		$state = $this->with_stage_progress( $state, $mapping_state );
		$state = $this->with_total( $state, $this->geo_repository->count_active() );
		$state['message'] = (string) ( $mapping_state['message'] ?? '' );
		$state['summary']['location_mapping'] = $this->location_mapping_summary();
		if ( 'error' === (string) ( $mapping_state['status'] ?? '' ) ) {
			return $this->stage_error( $state, $state['message'] );
		}
		if ( 'done' === (string) ( $mapping_state['status'] ?? '' ) ) {
			return $this->complete( $state );
		}

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function complete( array $state ): array {
		$state['status'] = 'done';
		$state['stage'] = self::STAGE_DONE;
		$state['stage_label'] = $this->stage_label( self::STAGE_DONE );
		$state['processed'] = 1;
		$state['total'] = 1;
		$state['percent'] = 100;
		$state['updated_at'] = $this->now();
		$state['message'] = 'Полное обновление Яндекс ПВЗ/географии завершено.';
		$state['summary']['geo_v2'] = $this->geo_summary();
		$state['summary']['location_mapping'] = $this->location_mapping_summary();

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function advance( array $state, string $stage, string $message ): array {
		$state['stage'] = $stage;
		$state['stage_label'] = $this->stage_label( $stage );
		$state['processed'] = 0;
		$state['total'] = null;
		$state['percent'] = null;
		$state['message'] = $message;
		$state['updated_at'] = $this->now();

		return $state;
	}

	/** @param array<string,mixed> $state @param array<string,mixed> $stage_state @return array<string,mixed> */
	private function with_stage_progress( array $state, array $stage_state, string $processed_key = 'processed' ): array {
		$state['processed'] = (int) ( $stage_state[ $processed_key ] ?? $stage_state['processed'] ?? 0 );
		$state['total'] = isset( $stage_state['total'] ) ? (int) $stage_state['total'] : null;
		$state['percent'] = is_int( $state['total'] ) ? $this->percent( (int) $state['processed'], (int) $state['total'] ) : null;
		$state['updated_at'] = $this->now();

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function with_total( array $state, int $total ): array {
		$state['total'] = $total;
		$state['percent'] = $this->percent( (int) $state['processed'], $total );

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function stage_error( array $state, string $message ): array {
		$state['status'] = 'error';
		$state['errors_count'] = (int) ( $state['errors_count'] ?? 0 ) + 1;
		$errors = is_array( $state['errors_last'] ?? null ) ? $state['errors_last'] : array();
		$errors[] = substr( trim( $message ), 0, 500 );
		$state['errors_last'] = array_slice( $errors, -5 );
		$state['message'] = substr( trim( $message ), 0, 500 );
		$state['updated_at'] = $this->now();

		return $state;
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function fail( array $state, \Throwable $exception ): array {
		$state = $this->stage_error( $state, $exception->getMessage() );
		$state['errors_last'][] = array(
			'type' => get_class( $exception ),
			'file' => $exception->getFile(),
			'line' => $exception->getLine(),
		);

		return $state;
	}

	/** @return array<string,mixed> */
	private function base_state( string $status, string $stage ): array {
		return array(
			'status' => $status,
			'stage' => $stage,
			'stage_label' => $this->stage_label( $stage ),
			'session_id' => '',
			'started_at' => '',
			'updated_at' => $this->now(),
			'processed' => 0,
			'total' => null,
			'percent' => null,
			'message' => '',
			'summary' => array(),
			'errors_count' => 0,
			'errors_last' => array(),
		);
	}

	/** @param array<string,mixed> $state */
	private function save_state( array $state ): void {
		if ( function_exists( 'update_option' ) ) {
			update_option( self::STATE_OPTION, $state, false );
		}
	}

	/** @param array<string,mixed> $state */
	private function schedule_next_step( array $state ): void {
		if ( 'running' !== (string) ( $state['status'] ?? '' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::CRON_HOOK ) ) {
			return;
		}
		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	private function clear_scheduled_step(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/** @return array{enabled:bool,days:array<int,int>,time:string,next_run:string} */
	public function schedule_settings(): array {
		$settings = function_exists( 'get_option' ) ? get_option( self::SCHEDULE_OPTION, array() ) : array();
		$days = is_array( $settings['days'] ?? null ) ? array_values( array_map( 'intval', $settings['days'] ) ) : array();
		$days = array_values( array_filter( array_unique( $days ), static fn( int $day ): bool => $day >= 1 && $day <= 7 ) );
		$time = preg_match( '/^\d{2}:\d{2}$/', (string) ( $settings['time'] ?? '' ) ) ? (string) $settings['time'] : '03:00';
		return array(
			'enabled' => ! empty( $settings['enabled'] ),
			'days' => $days,
			'time' => $time,
			'next_run' => $this->next_scheduled_run(),
		);
	}

	/** @param array<int,int|string> $days */
	public function save_schedule_settings( bool $enabled, array $days, string $time ): array {
		$days = array_values( array_filter( array_unique( array_map( 'intval', $days ) ), static fn( int $day ): bool => $day >= 1 && $day <= 7 ) );
		$time = preg_match( '/^\d{2}:\d{2}$/', $time ) ? $time : '03:00';
		$settings = array( 'enabled' => $enabled && array() !== $days, 'days' => $days, 'time' => $time );
		if ( function_exists( 'update_option' ) ) {
			update_option( self::SCHEDULE_OPTION, $settings, false );
		}
		$this->clear_scheduled_start();
		$this->ensure_schedule();
		return $this->schedule_settings();
	}

	public function ensure_schedule(): void {
		$settings = $this->schedule_settings();
		if ( empty( $settings['enabled'] ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		if ( function_exists( 'wp_next_scheduled' ) && wp_next_scheduled( self::SCHEDULE_HOOK ) ) {
			return;
		}
		$timestamp = $this->next_schedule_timestamp( $settings );
		if ( $timestamp > 0 ) {
			wp_schedule_single_event( $timestamp, self::SCHEDULE_HOOK );
		}
	}

	private function clear_scheduled_start(): void {
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( self::SCHEDULE_HOOK );
		}
	}

	/** @param array{enabled:bool,days:array<int,int>,time:string,next_run:string} $settings */
	private function next_schedule_timestamp( array $settings ): int {
		if ( empty( $settings['enabled'] ) || array() === $settings['days'] ) {
			return 0;
		}
		$now = time();
		[$hour, $minute] = array_map( 'intval', explode( ':', $settings['time'] ) );
		for ( $offset = 0; $offset < 14; ++$offset ) {
			$day_ts = strtotime( '+' . $offset . ' days', $now );
			if ( false === $day_ts || ! in_array( (int) date( 'N', $day_ts ), $settings['days'], true ) ) {
				continue;
			}
			$candidate = mktime( $hour, $minute, 0, (int) date( 'n', $day_ts ), (int) date( 'j', $day_ts ), (int) date( 'Y', $day_ts ) );
			if ( $candidate > $now ) {
				return $candidate;
			}
		}
		return 0;
	}

	private function next_scheduled_run(): string {
		$timestamp = function_exists( 'wp_next_scheduled' ) ? wp_next_scheduled( self::SCHEDULE_HOOK ) : false;
		return $timestamp ? gmdate( 'Y-m-d H:i:s', (int) $timestamp ) : '';
	}
	private function stage_label( string $stage ): string {
		return match ( $stage ) {
			self::STAGE_IMPORT_PVZ => 'Импорт ПВЗ',
			self::STAGE_BUILD_GEO_V2 => 'Построение geo_v2',
			self::STAGE_REGION_ENRICHMENT => 'Обогащение регионов',
			self::STAGE_REGION_MAPPING => 'Сопоставление регионов',
			self::STAGE_LOCATION_MAPPING => 'Сопоставление локаций',
			self::STAGE_DONE => 'Готово',
			default => $stage,
		};
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private function pickup_summary( array $state ): array {
		return array_merge(
			$this->only_keys( $state, array( 'processed', 'normalized', 'saved', 'skipped_invalid', 'json_file_size_bytes' ) ),
			array(
				'total' => $this->pickup_repository->count_all(),
				'active' => $this->pickup_repository->count_active(),
				'unique_geo_ids' => $this->pickup_repository->count_unique_geo_ids(),
			)
		);
	}

	/** @return array<string,mixed> */
	private function geo_summary(): array {
		$stats = $this->geo_repository->statistics();

		return $this->only_keys( $stats, array( 'total', 'active', 'points_total', 'dropoff_total', 'no_region' ) );
	}

	/** @return array<string,mixed> */
	private function location_mapping_summary(): array {
		$stats = $this->location_mapping_repository->statistics();

		return $this->only_keys( $stats, array( 'mapped', 'needs_review', 'no_match', 'error', 'avg_confidence', 'avg_distance', 'territory_fallback', 'mapped_by_dominance' ) );
	}

	/** @param array<string,mixed> $source @param array<int,string> $keys @return array<string,mixed> */
	private function only_keys( array $source, array $keys ): array {
		$result = array();
		foreach ( $keys as $key ) {
			$result[ $key ] = $source[ $key ] ?? 0;
		}

		return $result;
	}

	private function percent( int $processed, int $total ): ?int {
		return $total > 0 ? (int) min( 100, round( $processed * 100 / $total ) ) : null;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
