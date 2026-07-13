<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/../../' );

	$GLOBALS['yd_geo_pipeline_v2_options'] = array();
	$GLOBALS['yd_geo_pipeline_v2_scheduled'] = array();

	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['yd_geo_pipeline_v2_options'][ $name ] ?? $default;
	}

	function update_option( string $name, mixed $value, bool $autoload = true ): bool {
		$GLOBALS['yd_geo_pipeline_v2_options'][ $name ] = $value;
		return true;
	}

	function current_time( string $type ): string {
		return '2026-06-29 12:00:00';
	}

	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		$GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ] = compact( 'timestamp', 'hook', 'args' );
		return true;
	}

	function wp_next_scheduled( string $hook ): int|false {
		return isset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ] ) ? (int) $GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ]['timestamp'] : false;
	}

	function wp_clear_scheduled_hook( string $hook ): int {
		$removed = isset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ] ) ? 1 : 0;
		unset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ] );
		return $removed;
	}
	function yd_geo_pipeline_v2_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}
}

namespace WallsShop\WDC\Carriers\YandexDelivery\Pickup {
	final class YandexDeliveryPickupPointV2Repository {
		/** @var array<int,array<string,mixed>> */
		public array $rows = array( array( 'platform_station_id' => 'stale', 'active' => 1, 'yandex_geo_id' => 999 ) );
		/** @var array<int,array<string,mixed>> */
		public array $staging_rows = array();
		public int $truncate_count = 0;
		public bool $promoted = false;

		public function truncate(): void { ++$this->truncate_count; $this->rows = array(); }
		public function prepare_staging_table(): void { ++$this->truncate_count; $this->staging_rows = array(); }
		public function promote_staging_to_live(): void { $this->rows = $this->staging_rows; $this->staging_rows = array(); $this->promoted = true; }
		public function count_all(): int { return count( $this->rows ); }
		public function count_active(): int { return count( array_filter( $this->rows, static fn( array $row ): bool => ! empty( $row['active'] ) ) ); }
		public function count_unique_geo_ids(): int { return count( array_unique( array_map( static fn( array $row ): int => (int) ( $row['yandex_geo_id'] ?? 0 ), $this->rows ) ) ); }
		public function count_active_unique_geo_ids(): int { return count( array_unique( array_map( static fn( array $row ): int => (int) ( $row['yandex_geo_id'] ?? 0 ), array_filter( $this->rows, static fn( array $row ): bool => ! empty( $row['active'] ) ) ) ) ); }
	}

	final class YandexDeliveryPickupPointV2RunnerService {
		/** @var array<string,mixed> */
		private array $state = array( 'status' => 'idle', 'processed' => 0, 'total' => 3, 'offset' => 0, 'message' => '' );
		private int $steps = 0;
		public int $truncate_count_at_step = 0;
		public int $start_full_api_sync_count = 0;

		public function reset(): array { $this->state = array( 'status' => 'idle', 'processed' => 0, 'total' => 3, 'offset' => 0, 'message' => '' ); $this->steps = 0; return $this->state; }
		public function start_full_api_sync(): array { ++$this->start_full_api_sync_count; $this->state = array( 'status' => 'ready_to_import', 'processed' => 0, 'total' => 3, 'offset' => 0, 'message' => 'downloaded', 'saved' => 0 ); return $this->state; }
		public function current_state(): array { return $this->state; }
		public function start_import(): array {
			$repository = $GLOBALS['yd_pipeline_fake_pickup_repository'] ?? null;
			if ( empty( $this->state['pickup_points_staging'] ) && $repository instanceof YandexDeliveryPickupPointV2Repository ) {
				$repository->prepare_staging_table();
				$this->state['pickup_points_staging'] = true;
			}
			$this->state['status'] = 'importing';
			$this->state['message'] = 'importing';
			return $this->state;
		}
		public function run_import_step(): array {
			$repository = $GLOBALS['yd_pipeline_fake_pickup_repository'] ?? null;
			++$this->steps;
			if ( $repository instanceof YandexDeliveryPickupPointV2Repository ) {
				$this->truncate_count_at_step = $repository->truncate_count;
				$repository->staging_rows[] = 1 === $this->steps ? array( 'platform_station_id' => 'new-10', 'active' => 1, 'yandex_geo_id' => 10 ) : array( 'platform_station_id' => 'new-20', 'active' => 1, 'yandex_geo_id' => 20 );
			}
			$this->state['processed'] = $this->steps;
			$this->state['saved'] = $this->steps;
			$this->state['offset'] = $this->steps;
			$this->state['status'] = $this->steps >= 2 ? 'done' : 'importing';
			$this->state['message'] = $this->steps >= 2 ? 'pickup done' : 'pickup batch';
			if ( $this->steps >= 2 && $repository instanceof YandexDeliveryPickupPointV2Repository ) {
				$repository->promote_staging_to_live();
			}
			return $this->state;
		}
		public function pause(): array { $this->state['status'] = 'paused'; return $this->state; }
	}
}

namespace WallsShop\WDC\Carriers\YandexDelivery\GeoV2 {
	final class YandexDeliveryGeoV2Repository {
		/** @var array<int,array<string,mixed>> */
		public array $rows = array( array( 'yandex_geo_id' => 999, 'active' => 1 ) );
		public bool $truncated = false;
		public int $active_count = 0;

		public function truncate(): void { $this->truncated = true; $this->rows = array(); }
		public function count_active(): int { return $this->active_count; }
		public function statistics(): array { return array( 'total' => count( $this->rows ), 'active' => $this->active_count, 'points_total' => 3, 'dropoff_total' => 1, 'no_region' => 0 ); }
	}

	final class YandexDeliveryGeoV2BuilderRunnerService {
		/** @var array<string,mixed> */
		private array $state = array( 'status' => 'idle', 'processed_geo_ids' => 0, 'message' => '' );
		private int $steps = 0;
		public bool $started_after_truncate = false;
		public bool $started_with_only_new_pickups = false;

		public function reset(): array { $this->state = array( 'status' => 'idle', 'processed_geo_ids' => 0, 'message' => '' ); $this->steps = 0; return $this->state; }
		public function start(): array {
			$geo_repository = $GLOBALS['yd_pipeline_fake_geo_repository'] ?? null;
			$pickup_repository = $GLOBALS['yd_pipeline_fake_pickup_repository'] ?? null;
			$this->started_after_truncate = $geo_repository instanceof YandexDeliveryGeoV2Repository && $geo_repository->truncated && array() === $geo_repository->rows;
			$this->started_with_only_new_pickups = $pickup_repository instanceof \WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository && $pickup_repository->promoted && array( 10, 20 ) === array_values( array_map( static fn( array $row ): int => (int) $row['yandex_geo_id'], $pickup_repository->rows ) );
			$this->state = array( 'status' => 'building', 'processed_geo_ids' => 0, 'message' => 'geo building' );
			return $this->state;
		}
		public function current_state(): array { return $this->state; }
		public function run_step(): array {
			++$this->steps;
			if ( 1 === $this->steps ) {
				$this->state = array( 'status' => 'building', 'processed_geo_ids' => 1, 'message' => 'geo halfway' );
				return $this->state;
			}
			$geo_repository = $GLOBALS['yd_pipeline_fake_geo_repository'] ?? null;
			if ( $geo_repository instanceof YandexDeliveryGeoV2Repository ) {
				$geo_repository->rows = array( array( 'yandex_geo_id' => 10, 'active' => 1 ), array( 'yandex_geo_id' => 20, 'active' => 1 ) );
				$geo_repository->active_count = 2;
			}
			$this->state = array( 'status' => 'done', 'processed_geo_ids' => 2, 'message' => 'geo done' );
			return $this->state;
		}
		public function pause(): array { $this->state['status'] = 'paused'; return $this->state; }
	}
}

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2 {
	final class YandexGeoV2RegionEnrichmentRunner {
		private array $state = array( 'status' => 'idle', 'processed' => 0, 'pending_empty_regions_remaining' => 0, 'message' => '' );
		public function start(): array { $this->state = array( 'status' => 'done', 'processed' => 2, 'pending_empty_regions_remaining' => 0, 'updated' => 1, 'needs_review' => 0, 'not_found' => 0, 'skipped' => 0, 'errors' => 0, 'message' => 'enrichment done' ); return $this->state; }
		public function current_state(): array { return $this->state; }
		public function run_step(): array { return $this->state; }
		public function pause(): array { $this->state['status'] = 'paused'; return $this->state; }
	}

	final class YandexRegionMappingV2Repository {
		public function sync_from_sources(): array { return array( 'yandex_regions' => 2, 'added' => 1, 'needs_review' => 0 ); }
	}

	final class YandexLocationMappingV2Runner {
		private array $state = array( 'status' => 'idle', 'processed' => 0, 'message' => '' );
		private int $steps = 0;
		public function reset(): array { $this->state = array( 'status' => 'idle', 'processed' => 0, 'message' => '' ); $this->steps = 0; return $this->state; }
		public function start(): array { $repository = $GLOBALS['yd_pipeline_fake_mapping_repository'] ?? null; if ( $repository instanceof YandexLocationMappingV2Repository ) { $repository->prepare_staging_table(); } $this->state = array( 'status' => 'mapping', 'processed' => 0, 'message' => 'mapping started' ); return $this->state; }
		public function current_state(): array { return $this->state; }
		public function run_step(): array { ++$this->steps; $repository = $GLOBALS['yd_pipeline_fake_mapping_repository'] ?? null; if ( $repository instanceof YandexLocationMappingV2Repository ) { if ( 1 === $this->steps ) { $repository->staging_rows[] = array( 'status' => 'mapped' ); } else { $repository->staging_rows[] = array( 'status' => 'mapped' ); $repository->promote_staging_to_live(); } } $this->state = 1 === $this->steps ? array( 'status' => 'mapping', 'processed' => 1, 'message' => 'mapping halfway' ) : array( 'status' => 'done', 'processed' => 2, 'message' => 'mapping done' ); return $this->state; }
		public function pause(): array { $this->state['status'] = 'paused'; return $this->state; }
	}

	final class YandexLocationMappingV2Repository {
		/** @var array<int,array<string,mixed>> */
		public array $rows = array( array( 'status' => 'stale' ) );
		/** @var array<int,array<string,mixed>> */
		public array $staging_rows = array();
		public bool $promoted = false;
		public function prepare_staging_table(): void { $this->staging_rows = array(); }
		public function promote_staging_to_live(): void { $this->rows = $this->staging_rows; $this->staging_rows = array(); $this->promoted = true; }
		public function statistics(): array { $rows = $this->promoted ? $this->rows : $this->staging_rows; return array( 'mapped' => count( $rows ), 'manual' => 99, 'needs_review' => 0, 'no_match' => 0, 'error' => 0, 'avg_confidence' => 100, 'avg_distance' => 1.5, 'territory_fallback' => 0, 'mapped_by_dominance' => array( 'distance_gap' => 1 ) ); }
	}
}

namespace {
	$root = dirname( __DIR__, 2 );
	$pipeline_file = $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexDeliveryGeoPipelineV2Runner.php';
	$pipeline_source = (string) file_get_contents( $pipeline_file );
	$pickup_repository_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2Repository.php' );
	$pickup_runner_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2RunnerService.php' );
	$admin_source = (string) file_get_contents( $root . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
	$plugin_source = (string) file_get_contents( $root . '/src/Core/Plugin.php' );
	$js_source = (string) file_get_contents( $root . '/assets/admin/yandex-delivery-pickup-v2-runner.js' );
	$mapper_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMapperV2Service.php' );
	$geo_builder_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/GeoV2/YandexDeliveryGeoV2BuilderService.php' );
	$geo_repository_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/GeoV2/YandexDeliveryGeoV2Repository.php' );
	$mapping_repository_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMappingV2Repository.php' );
	$plugin_main = (string) file_get_contents( $root . '/walls-delivery-calc.php' );

	require_once $pipeline_file;

	foreach ( array( 'import_pvz', 'build_geo_v2', 'region_enrichment', 'region_mapping', 'location_mapping', 'done' ) as $stage ) {
		yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, $stage ), 'Pipeline runner must contain stage: ' . $stage );
	}
	yd_geo_pipeline_v2_assert( str_contains( $pickup_repository_source, 'public function truncate(): void' ) && str_contains( $pickup_repository_source, 'TRUNCATE TABLE' ), 'Pickup v2 repository must expose truncate().' );
	yd_geo_pipeline_v2_assert( str_contains( $pickup_repository_source, 'prepare_staging_table' ) && str_contains( $pickup_repository_source, 'promote_staging_to_live' ), 'Pickup v2 repository must support staging table promotion.' );
	 yd_geo_pipeline_v2_assert( str_contains( $pickup_runner_source, 'prepare_staging_repository' ) && str_contains( $pickup_runner_source, 'promote_staging_repository' ), 'Pickup v2 runner must import into staging and promote only after success.' );
	 yd_geo_pipeline_v2_assert( str_contains( $mapping_repository_source, 'prepare_staging_table' ) && str_contains( $mapping_repository_source, 'promote_staging_to_live' ), 'Location mapping repository must support staging table promotion.' );
	yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, '$this->geo_repository->truncate();' ), 'Pipeline must truncate geo_v2 before build_geo_v2 after fresh PVZ import.' );
	yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, 'count_active_unique_geo_ids' ), 'Pipeline must use active unique pickup geoId total for geo_v2 build progress.' );
	yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, '$this->geo_repository->count_active()' ), 'Pipeline must use active geo_v2 total for location mapping progress.' );
	yd_geo_pipeline_v2_assert( ! str_contains( $pipeline_source, "'mapped', 'manual', 'needs_review'" ), 'Pipeline location mapping summary must not expose unused manual status.' );

	yd_geo_pipeline_v2_assert( str_contains( $plugin_source, 'YandexDeliveryGeoPipelineV2Runner::class' ) && str_contains( $plugin_source, 'new YandexDeliveryGeoPipelineV2Runner' ), 'Plugin DI must register pipeline runner.' );
yd_geo_pipeline_v2_assert( str_contains( $plugin_source, 'YandexDeliveryGeoPipelineV2Runner::CRON_HOOK' ) && str_contains( $plugin_source, 'run_scheduled_step' ), 'Plugin must register pipeline step cron hook.' );
	 yd_geo_pipeline_v2_assert( str_contains( $plugin_source, 'YandexDeliveryGeoPipelineV2Runner::SCHEDULE_HOOK' ) && str_contains( $plugin_source, 'run_scheduled_start' ), 'Plugin must register pipeline scheduled start hook.' );
	yd_geo_pipeline_v2_assert( str_contains( $admin_source, 'Расписание полного обновления' ) && str_contains( $admin_source, 'save_yandex_geo_pipeline_v2_schedule' ) && str_contains( $admin_source, 'Все время указывается по Москве (GMT+3)' ), 'Admin must expose pipeline schedule settings with Moscow time explanation.' );
	yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, "DateTimeZone( 'Europe/Moscow' )" ) && str_contains( $pipeline_source, " . ' MSK'" ), 'Pipeline schedule must calculate and display runs in Moscow time.' );
	 yd_geo_pipeline_v2_assert( str_contains( $admin_source, 'Полное обновление Яндекс ПВЗ/географии' ) && str_contains( $admin_source, 'wdc_yandex_delivery_geo_pipeline_v2_start' ) && str_contains( $admin_source, 'geoPipelineInitialState' ), 'Admin must expose one-button pipeline block and AJAX actions.' );
	yd_geo_pipeline_v2_assert( str_contains( $js_source, 'data-wdc-yandex-geo-pipeline-v2' ) && str_contains( $js_source, 'wdc_yandex_delivery_geo_pipeline_v2_status' ) && str_contains( $js_source, 'pollOnly: true' ), 'JS must poll pipeline status without driving server steps.' );

	yd_geo_pipeline_v2_assert( str_contains( $mapper_source, 'load_active_overrides_cache' ) && str_contains( $mapper_source, 'manual_override_decision' ), 'Manual overrides must still be applied inside location mapping.' );
	yd_geo_pipeline_v2_assert( str_contains( $mapper_source, 'compact_raw_json' ) && str_contains( $mapper_source, 'wdc_yandex_location_mapping_v2_debug_raw' ), 'Location mapping raw_json must be compact by default with debug opt-in.' );
	foreach ( array( 'sql_search_terms', 'address_locality_terms', 'rejected_samples', 'rejected_candidates', 'diagnostics' ) as $heavy_key ) {
		yd_geo_pipeline_v2_assert( str_contains( $mapper_source, "unset( \$raw[ '" . $heavy_key . "' ]" ) || str_contains( $mapper_source, "'" . $heavy_key . "'" ), 'Mapper compact raw logic must know heavy key: ' . $heavy_key );
	}
	yd_geo_pipeline_v2_assert( str_contains( $geo_builder_source, "'sample_points_json' => \$this->json( array( 'addresses'" ) && str_contains( $geo_builder_source, 'sample_addresses' ), 'Geo builder must persist compact address-only sample JSON.' );
	yd_geo_pipeline_v2_assert( str_contains( $geo_repository_source, 'compact_region_enrichment_audit' ) && str_contains( $geo_repository_source, 'wdc_yandex_geo_v2_region_enrichment_debug_raw' ), 'Geo repository must compact region enrichment audit by default.' );
	yd_geo_pipeline_v2_assert( str_contains( $mapping_repository_source, 'find_recent_no_match' ) && ! str_contains( $mapping_repository_source, "'sql_search_terms' =>" ), 'Review/no_match repository output must not depend on heavy sql_search_terms.' );
	yd_geo_pipeline_v2_assert( str_contains( $plugin_main, 'Version: 0.108.14' ) && str_contains( $plugin_main, "define( 'WDC_VERSION', '0.108.14' )" ), 'Plugin version must be 0.108.14.' );

	$pickup_runner = new \WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2RunnerService();
	$pickup_repository = new \WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository();
	$GLOBALS['yd_pipeline_fake_pickup_repository'] = $pickup_repository;
	$geo_builder_runner = new \WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2BuilderRunnerService();
	$geo_repository = new \WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2Repository();
	$GLOBALS['yd_pipeline_fake_geo_repository'] = $geo_repository;
	$mapping_repository = new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository();
	$GLOBALS['yd_pipeline_fake_mapping_repository'] = $mapping_repository;
	$runner = new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner(
		$pickup_runner,
		$pickup_repository,
		$geo_builder_runner,
		$geo_repository,
		new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexGeoV2RegionEnrichmentRunner(),
		new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexRegionMappingV2Repository(),
		new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Runner(),
		$mapping_repository
	);

	$schedule = $runner->save_schedule_settings( true, array( 1, 3 ), '04:30' );
	yd_geo_pipeline_v2_assert( ! empty( $schedule['enabled'] ) && array( 1, 3 ) === $schedule['days'] && '04:30' === $schedule['time'], 'Pipeline schedule settings must be saved.' );
	yd_geo_pipeline_v2_assert( isset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::SCHEDULE_HOOK ] ), 'Pipeline schedule must create WP-Cron start event.' );
	$scheduled_timestamp = (int) $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::SCHEDULE_HOOK ]['timestamp'];
	$scheduled_msk = ( new \DateTimeImmutable( '@' . $scheduled_timestamp ) )->setTimezone( new \DateTimeZone( 'Europe/Moscow' ) );
	yd_geo_pipeline_v2_assert( '04:30' === $scheduled_msk->format( 'H:i' ) && in_array( (int) $scheduled_msk->format( 'N' ), array( 1, 3 ), true ), 'Pipeline schedule timestamp must represent selected Moscow time and day.' );
	yd_geo_pipeline_v2_assert( str_contains( (string) $schedule['next_run'], $scheduled_msk->format( 'Y-m-d H:i' ) ) && str_contains( (string) $schedule['next_run'], 'MSK' ), 'Pipeline next_run must be displayed in Moscow time.' );
	$previous_timezone = date_default_timezone_get();
	date_default_timezone_set( 'Pacific/Honolulu' );
	$schedule_honolulu = $runner->save_schedule_settings( true, array( 1, 3 ), '04:30' );
	$timestamp_honolulu = (int) $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::SCHEDULE_HOOK ]['timestamp'];
	date_default_timezone_set( 'Asia/Novosibirsk' );
	$schedule_novosibirsk = $runner->save_schedule_settings( true, array( 1, 3 ), '04:30' );
	$timestamp_novosibirsk = (int) $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::SCHEDULE_HOOK ]['timestamp'];
	date_default_timezone_set( $previous_timezone );
	yd_geo_pipeline_v2_assert( $timestamp_honolulu === $timestamp_novosibirsk && $schedule_honolulu['next_run'] === $schedule_novosibirsk['next_run'], 'Pipeline schedule must not change when PHP or WordPress timezone changes.' );
	$runner->save_schedule_settings( false, array(), '04:30' );
	yd_geo_pipeline_v2_assert( ! isset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::SCHEDULE_HOOK ] ), 'Disabling pipeline schedule must clear WP-Cron start event.' );
	$started = $runner->start();
	$session = (string) ( $started['session_id'] ?? '' );
	$runner->run_scheduled_start();
	yd_geo_pipeline_v2_assert( $session === (string) ( $runner->current_state()['session_id'] ?? '' ), 'Scheduled start must not create a second pipeline while one is running.' );
	yd_geo_pipeline_v2_assert( isset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::CRON_HOOK ] ), 'Pipeline start must schedule the next server-side step.' );
	yd_geo_pipeline_v2_assert( 0 === $pickup_runner->start_full_api_sync_count && 'idle' === $pickup_runner->current_state()['status'], 'Pipeline start must not download JSON synchronously.' );
	$state = $runner->run_step();
	yd_geo_pipeline_v2_assert( 'import_pvz' === $state['stage'] && 'ready_to_import' === $pickup_runner->current_state()['status'] && 1 === $pickup_runner->start_full_api_sync_count, 'First server step must download JSON for import_pvz.' );
	yd_geo_pipeline_v2_assert( 0 === $pickup_repository->truncate_count && 1 === $pickup_repository->count_all(), 'Download step must not truncate pickup_points_v2 before import starts.' );

	$state = $runner->run_step();
	yd_geo_pipeline_v2_assert( 'import_pvz' === $state['stage'] && 'importing' === $pickup_runner->current_state()['status'], 'Pipeline must start pickup import before first batch.' );
	yd_geo_pipeline_v2_assert( 1 === $pickup_repository->truncate_count && 1 === $pickup_repository->count_all() && 0 === count( $pickup_repository->staging_rows ), 'Pipeline pickup import must prepare staging exactly once before first batch and leave live pickup rows unchanged.' );

	$state = $runner->run_step();
	yd_geo_pipeline_v2_assert( 'import_pvz' === $state['stage'] && 1 === $pickup_repository->count_all() && 1 === count( $pickup_repository->staging_rows ) && 1 === $pickup_repository->truncate_count && 1 === $pickup_runner->truncate_count_at_step, 'First pickup import batch must use staging and leave live pickup rows unchanged.' );

	$state = $runner->run_step();
	yd_geo_pipeline_v2_assert( 'build_geo_v2' === $state['stage'], 'Pipeline must advance to build_geo_v2 after pickup batches finish.' );
	yd_geo_pipeline_v2_assert( 1 === $pickup_repository->truncate_count && 2 === $pickup_repository->count_all(), 'Second pickup import batch must not truncate again.' );
	yd_geo_pipeline_v2_assert( $pickup_repository->promoted && array( 10, 20 ) === array_values( array_map( static fn( array $row ): int => (int) $row['yandex_geo_id'], $pickup_repository->rows ) ), 'Pickup staging must replace live rows only after successful import completion.' );
	yd_geo_pipeline_v2_assert( $geo_repository->truncated, 'Pipeline must truncate geo_v2 after import_pvz before geo_v2 build.' );
	yd_geo_pipeline_v2_assert( $geo_builder_runner->started_after_truncate && $geo_builder_runner->started_with_only_new_pickups, 'Geo builder must start after geo_v2 truncate and see only fresh pickup rows.' );

	$state = $runner->run_step();
	yd_geo_pipeline_v2_assert( 'build_geo_v2' === $state['stage'], 'Pipeline must stay on build_geo_v2 while builder is running.' );
	yd_geo_pipeline_v2_assert( 1 === $state['processed'] && 2 === $state['total'] && 50 === $state['percent'], 'build_geo_v2 must expose processed/total/percent from active unique pickup geoId count.' );

	for ( $i = 0; $i < 8 && 'location_mapping' !== (string) ( $state['stage'] ?? '' ); ++$i ) {
		$state = $runner->run_step();
	}
	yd_geo_pipeline_v2_assert( 'location_mapping' === $state['stage'], 'Pipeline must reach location_mapping stage.' );
	if ( 0 === (int) ( $state['processed'] ?? 0 ) ) {
		$state = $runner->run_step();
	}
	yd_geo_pipeline_v2_assert( 1 === $state['processed'] && 2 === $state['total'] && 50 === $state['percent'], 'location_mapping must expose processed/total/percent from active geo_v2 rows.' );
	yd_geo_pipeline_v2_assert( ! array_key_exists( 'manual', $state['summary']['location_mapping'] ?? array() ), 'location_mapping summary must not contain manual.' );
	$state = $runner->run_step();
	 yd_geo_pipeline_v2_assert( 'done' === $state['stage'] && $mapping_repository->promoted && 2 === count( $mapping_repository->rows ), 'Location mapping staging must replace live mapping only after done.' );

	echo "Yandex Delivery geo pipeline v2 smoke OK\n";
}
