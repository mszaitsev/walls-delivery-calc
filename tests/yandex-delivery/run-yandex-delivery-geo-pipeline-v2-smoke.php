<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/../../' );
	require_once dirname( __DIR__, 2 ) . '/src/Calendar/Services/TimezoneService.php';

	$GLOBALS['yd_geo_pipeline_v2_options'] = array();
	$GLOBALS['yd_geo_pipeline_v2_scheduled'] = array();
	$GLOBALS['yd_geo_pipeline_v2_schedule_calls'] = 0;
	$GLOBALS['yd_geo_pipeline_v2_atomic_units'] = 0;
	$GLOBALS['yd_geo_pipeline_v2_ajax_response'] = array();

	function get_option( string $name, mixed $default = false ): mixed {
		return $GLOBALS['yd_geo_pipeline_v2_options'][ $name ] ?? $default;
	}

	function update_option( string $name, mixed $value, bool $autoload = true ): bool {
		$GLOBALS['yd_geo_pipeline_v2_options'][ $name ] = $value;
		return true;
	}

	function add_option( string $name, mixed $value, string $deprecated = '', string|bool $autoload = 'yes' ): bool {
		if ( array_key_exists( $name, $GLOBALS['yd_geo_pipeline_v2_options'] ) ) {
			return false;
		}
		$GLOBALS['yd_geo_pipeline_v2_options'][ $name ] = $value;
		return true;
	}

	function delete_option( string $name ): bool {
		if ( ! array_key_exists( $name, $GLOBALS['yd_geo_pipeline_v2_options'] ) ) {
			return false;
		}
		unset( $GLOBALS['yd_geo_pipeline_v2_options'][ $name ] );
		return true;
	}

	function current_time( string $type ): string {
		return '2026-06-29 12:00:00';
	}

	function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
		++$GLOBALS['yd_geo_pipeline_v2_schedule_calls'];
		$GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ] = compact( 'timestamp', 'hook', 'args' );
		return true;
	}

	function wp_next_scheduled( string $hook, array $args = array() ): int|false {
		if ( ! isset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ] ) || $args !== $GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ]['args'] ) {
			return false;
		}
		return (int) $GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ]['timestamp'];
	}

	function wp_clear_scheduled_hook( string $hook ): int {
		$removed = isset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ] ) ? 1 : 0;
		unset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ] );
		return $removed;
	}
	function wp_unschedule_event( int $timestamp, string $hook, array $args = array() ): bool {
		if ( isset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ] ) && $args === $GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ]['args'] ) {
			unset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ $hook ] );
			return true;
		}
		return false;
	}
	function yd_geo_pipeline_v2_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}
	function __( string $text, string $domain = 'default' ): string { unset( $domain ); return $text; }
	function current_user_can( string $capability ): bool { unset( $capability ); return true; }
	function check_ajax_referer( string $action, mixed $query_arg = false, bool $stop = true ): bool { unset( $action, $query_arg, $stop ); return true; }
	function wp_send_json_success( mixed $data = null, ?int $status_code = null, int $flags = 0 ): void { unset( $flags ); $GLOBALS['yd_geo_pipeline_v2_ajax_response'] = array( 'success' => true, 'data' => $data, 'status_code' => $status_code ?? 200 ); }
	function wp_send_json_error( mixed $data = null, ?int $status_code = null, int $flags = 0 ): void { unset( $flags ); $GLOBALS['yd_geo_pipeline_v2_ajax_response'] = array( 'success' => false, 'data' => $data, 'status_code' => $status_code ?? 400 ); }
}

namespace WallsShop\WDC\Admin {
	final class AdminMenu {
		public const CAPABILITY = 'manage_woocommerce';
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
		public int $import_step_count = 0;
		public int $cleanup_count = 0;
		public int $unreadable_json_attempts = 0;
		public bool $json_available = false;

		public function reset(): array { $this->state = array( 'status' => 'idle', 'processed' => 0, 'total' => 3, 'offset' => 0, 'message' => '' ); $this->steps = 0; return $this->state; }
		public function start_full_api_sync(): array { ++$this->start_full_api_sync_count; ++$GLOBALS['yd_geo_pipeline_v2_atomic_units']; $this->json_available = empty( $GLOBALS['yd_geo_pipeline_v2_download_error'] ); $this->state = ! $this->json_available ? array( 'status' => 'error', 'processed' => 0, 'total' => 3, 'offset' => 0, 'message' => 'download failed', 'saved' => 0 ) : array( 'status' => 'ready_to_import', 'processed' => 0, 'total' => 3, 'offset' => 0, 'message' => 'downloaded', 'saved' => 0 ); if ( ! empty( $GLOBALS['yd_geo_pipeline_v2_overlap_callback'] ) ) { $GLOBALS['yd_geo_pipeline_v2_overlap_callback'](); } return $this->state; }
		public function current_state(): array { return $this->state; }
		public function start_import(): array {
			++$GLOBALS['yd_geo_pipeline_v2_atomic_units'];
			$repository = $GLOBALS['yd_pipeline_fake_pickup_repository'] ?? null;
			if ( empty( $this->state['pickup_points_staging'] ) && $repository instanceof YandexDeliveryPickupPointV2Repository ) {
				$repository->prepare_staging_table();
				$this->state['pickup_points_staging'] = true;
			}
			$this->state['status'] = 'importing';
			$this->state['message'] = 'importing';
			if ( ! empty( $GLOBALS['yd_geo_pipeline_v2_between_units_callback'] ) ) { $callback = $GLOBALS['yd_geo_pipeline_v2_between_units_callback']; $GLOBALS['yd_geo_pipeline_v2_between_units_callback'] = null; $callback(); }
			return $this->state;
		}
		public function run_import_step(): array {
			++$GLOBALS['yd_geo_pipeline_v2_atomic_units'];
			++$this->import_step_count;
			if ( ! $this->json_available ) {
				++$this->unreadable_json_attempts;
				$this->state['status'] = 'error';
				$this->state['message'] = 'Yandex Delivery pickup v2 JSON file is not readable.';
				return $this->state;
			}
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
				$this->json_available = false;
				++$this->cleanup_count;
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
		public int $start_count = 0;

		public function reset(): array { $this->state = array( 'status' => 'idle', 'processed_geo_ids' => 0, 'message' => '' ); $this->steps = 0; return $this->state; }
		public function start(): array {
			++$this->start_count;
			$geo_repository = $GLOBALS['yd_pipeline_fake_geo_repository'] ?? null;
			$pickup_repository = $GLOBALS['yd_pipeline_fake_pickup_repository'] ?? null;
			$this->started_after_truncate = $geo_repository instanceof YandexDeliveryGeoV2Repository && $geo_repository->truncated && array() === $geo_repository->rows;
			$this->started_with_only_new_pickups = $pickup_repository instanceof \WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository && $pickup_repository->promoted && array( 10, 20 ) === array_values( array_map( static fn( array $row ): int => (int) $row['yandex_geo_id'], $pickup_repository->rows ) );
			$this->state = array( 'status' => 'building', 'processed_geo_ids' => 0, 'message' => 'geo building' );
			return $this->state;
		}
		public function current_state(): array { return $this->state; }
		public function run_step(): array {
			++$GLOBALS['yd_geo_pipeline_v2_atomic_units'];
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
		public int $start_count = 0;
		public function start(): array { ++$this->start_count; ++$GLOBALS['yd_geo_pipeline_v2_atomic_units']; $this->state = array( 'status' => 'done', 'processed' => 2, 'pending_empty_regions_remaining' => 0, 'updated' => 1, 'needs_review' => 0, 'not_found' => 0, 'skipped' => 0, 'errors' => 0, 'message' => 'enrichment done' ); return $this->state; }
		public function current_state(): array { return $this->state; }
		public function run_step(): array { return $this->state; }
		public function pause(): array { $this->state['status'] = 'paused'; return $this->state; }
	}

	final class YandexRegionMappingV2Repository {
		public function sync_from_sources(): array { ++$GLOBALS['yd_geo_pipeline_v2_atomic_units']; return array( 'yandex_regions' => 2, 'added' => 1, 'needs_review' => 0 ); }
	}

	final class YandexLocationMappingV2Runner {
		private array $state = array( 'status' => 'idle', 'processed' => 0, 'message' => '' );
		private int $steps = 0;
		public int $start_count = 0;
		public function reset(): array { $this->state = array( 'status' => 'idle', 'processed' => 0, 'message' => '' ); $this->steps = 0; return $this->state; }
		public function start(): array { ++$this->start_count; ++$GLOBALS['yd_geo_pipeline_v2_atomic_units']; $repository = $GLOBALS['yd_pipeline_fake_mapping_repository'] ?? null; if ( $repository instanceof YandexLocationMappingV2Repository ) { $repository->prepare_staging_table(); } $this->state = array( 'status' => 'mapping', 'processed' => 0, 'message' => 'mapping started' ); return $this->state; }
		public function current_state(): array { return $this->state; }
		public function run_step(): array { ++$GLOBALS['yd_geo_pipeline_v2_atomic_units']; ++$this->steps; $repository = $GLOBALS['yd_pipeline_fake_mapping_repository'] ?? null; if ( $repository instanceof YandexLocationMappingV2Repository ) { if ( 1 === $this->steps ) { $repository->staging_rows[] = array( 'status' => 'mapped' ); } else { $repository->staging_rows[] = array( 'status' => 'mapped' ); $repository->promote_staging_to_live(); } } $this->state = 1 === $this->steps ? array( 'status' => 'mapping', 'processed' => 1, 'message' => 'mapping halfway' ) : array( 'status' => 'done', 'processed' => 2, 'message' => 'mapping done' ); return $this->state; }
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
	$execution_lock_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexDeliveryGeoPipelineV2ExecutionLock.php' );
	$pickup_repository_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2Repository.php' );
	$pickup_runner_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2RunnerService.php' );
	$admin_source = (string) file_get_contents( $root . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
	$plugin_source = (string) file_get_contents( $root . '/src/Core/Plugin.php' );
	$js_source = (string) file_get_contents( $root . '/assets/admin/yandex-delivery-pickup-v2-runner.js' );
	$mapper_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMapperV2Service.php' );
	$geo_builder_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/GeoV2/YandexDeliveryGeoV2BuilderService.php' );
	$geo_builder_runner_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/GeoV2/YandexDeliveryGeoV2BuilderRunnerService.php' );
	$region_enrichment_runner_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexGeoV2RegionEnrichmentRunner.php' );
	$location_mapping_runner_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMappingV2Runner.php' );
	$geo_repository_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/GeoV2/YandexDeliveryGeoV2Repository.php' );
	$mapping_repository_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMappingV2Repository.php' );
	$plugin_main = (string) file_get_contents( $root . '/walls-delivery-calc.php' );

	require_once $root . '/src/Infrastructure/Background/BackgroundExecutionBudget.php';
	require_once $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexDeliveryGeoPipelineV2ExecutionLock.php';
	require_once $pipeline_file;
	require_once $root . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php';

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
	yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, 'WORKER_SOFT_TIME_BUDGET_SECONDS = 18.0' ) && str_contains( $pipeline_source, 'MAX_UNITS_PER_WORKER_SLICE = 25' ) && str_contains( $pipeline_source, 'MEMORY_BUDGET_FRACTION = 0.8' ), 'Yandex worker must keep the accepted 18-second/25-unit/80%-memory budget.' );
	yd_geo_pipeline_v2_assert( str_contains( $pickup_runner_source, 'BATCH_SIZE = 500' ) && str_contains( $geo_builder_runner_source, 'BATCH_SIZE = 500' ) && str_contains( $region_enrichment_runner_source, 'BATCH_SIZE = 10' ) && str_contains( $location_mapping_runner_source, 'BATCH_SIZE = 100' ), 'Existing Yandex atomic batch sizes must remain unchanged.' );
	yd_geo_pipeline_v2_assert( str_contains( $execution_lock_source, 'add_option(' ) && str_contains( $execution_lock_source, 'option_value = %s' ) && str_contains( $execution_lock_source, "'token' => \$token" ), 'Outer execution lease must use atomic add and token-scoped compare operations.' );

	yd_geo_pipeline_v2_assert( str_contains( $plugin_source, 'YandexDeliveryGeoPipelineV2Runner::class' ) && str_contains( $plugin_source, 'new YandexDeliveryGeoPipelineV2Runner' ), 'Plugin DI must register pipeline runner.' );
yd_geo_pipeline_v2_assert( str_contains( $plugin_source, 'YandexDeliveryGeoPipelineV2Runner::CRON_HOOK' ) && str_contains( $plugin_source, 'run_scheduled_step' ), 'Plugin must register pipeline step cron hook.' );
	 yd_geo_pipeline_v2_assert( str_contains( $plugin_source, 'YandexDeliveryGeoPipelineV2Runner::SCHEDULE_HOOK' ) && str_contains( $plugin_source, 'run_scheduled_start' ), 'Plugin must register pipeline scheduled start hook.' );
	yd_geo_pipeline_v2_assert( str_contains( $admin_source, 'Расписание полного обновления' ) && str_contains( $admin_source, 'save_yandex_geo_pipeline_v2_schedule' ) && str_contains( $admin_source, 'Время указывается по Новосибирску (GMT+7)' ), 'Admin must expose pipeline schedule settings with Novosibirsk time explanation.' );
	yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, 'TimezoneService' ) && ! str_contains( $pipeline_source, 'Europe/Moscow' ), 'Pipeline schedule must use the canonical WDC timezone owner.' );
	 yd_geo_pipeline_v2_assert( str_contains( $admin_source, 'Полное обновление Яндекс ПВЗ/географии' ) && str_contains( $admin_source, 'wdc_yandex_delivery_geo_pipeline_v2_start' ) && str_contains( $admin_source, 'geoPipelineInitialState' ), 'Admin must expose one-button pipeline block and AJAX actions.' );
	yd_geo_pipeline_v2_assert( str_contains( $js_source, 'data-wdc-yandex-geo-pipeline-v2' ) && str_contains( $js_source, 'wdc_yandex_delivery_geo_pipeline_v2_status' ) && str_contains( $js_source, 'pollOnly: true' ), 'JS must poll pipeline status without driving server steps.' );
	yd_geo_pipeline_v2_assert( str_contains( $admin_source, 'is_yandex_geo_pipeline_active' ) && substr_count( $admin_source, 'reject_yandex_standalone_mutation_while_geo_pipeline_active()' ) >= 5 && str_contains( $admin_source, "array( 'running', 'paused' )" ) && str_contains( $admin_source, "409" ), 'Every standalone lower AJAX family must use the running/paused full-pipeline conflict guard.' );
	yd_geo_pipeline_v2_assert( str_contains( $admin_source, "'sync_yandex_region_mapping_v2'" ) && str_contains( $admin_source, "'save_yandex_location_manual_override_v2'" ) && str_contains( $admin_source, 'if ( $this->is_yandex_geo_pipeline_active() )' ), 'Region mapping and manual override POST mutations must share the full-pipeline ownership boundary.' );
	yd_geo_pipeline_v2_assert( substr_count( $js_source, 'standalone: true' ) === 4 && str_contains( $js_source, 'standaloneMutationBlocked' ) && str_contains( $js_source, "['running', 'paused']" ), 'Browser must suppress all four standalone lower loops while the full pipeline is running or paused.' );

	yd_geo_pipeline_v2_assert( str_contains( $mapper_source, 'load_active_overrides_cache' ) && str_contains( $mapper_source, 'manual_override_decision' ), 'Manual overrides must still be applied inside location mapping.' );
	yd_geo_pipeline_v2_assert( str_contains( $mapper_source, 'compact_raw_json' ) && str_contains( $mapper_source, 'wdc_yandex_location_mapping_v2_debug_raw' ), 'Location mapping raw_json must be compact by default with debug opt-in.' );
	foreach ( array( 'sql_search_terms', 'address_locality_terms', 'rejected_samples', 'rejected_candidates', 'diagnostics' ) as $heavy_key ) {
		yd_geo_pipeline_v2_assert( str_contains( $mapper_source, "unset( \$raw[ '" . $heavy_key . "' ]" ) || str_contains( $mapper_source, "'" . $heavy_key . "'" ), 'Mapper compact raw logic must know heavy key: ' . $heavy_key );
	}
	yd_geo_pipeline_v2_assert( str_contains( $geo_builder_source, "'sample_points_json' => \$this->json( array( 'addresses'" ) && str_contains( $geo_builder_source, 'sample_addresses' ), 'Geo builder must persist compact address-only sample JSON.' );
	yd_geo_pipeline_v2_assert( str_contains( $geo_repository_source, 'compact_region_enrichment_audit' ) && str_contains( $geo_repository_source, 'wdc_yandex_geo_v2_region_enrichment_debug_raw' ), 'Geo repository must compact region enrichment audit by default.' );
	yd_geo_pipeline_v2_assert( str_contains( $mapping_repository_source, 'find_recent_no_match' ) && ! str_contains( $mapping_repository_source, "'sql_search_terms' =>" ), 'Review/no_match repository output must not depend on heavy sql_search_terms.' );
yd_geo_pipeline_v2_assert( str_contains( $plugin_main, 'Version: 1.0.16' ) && str_contains( $plugin_main, "define( 'WDC_VERSION', '1.0.16' )" ) && str_contains( $plugin_main, "define( 'WDC_SCHEMA_VERSION', '1.0.0' )" ), 'Plugin version must be 1.0.16 while the unchanged schema baseline remains 1.0.0.' );

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
		$mapping_repository,
		new \WallsShop\WDC\Calendar\Services\TimezoneService(),
		new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2ExecutionLock()
	);

	$schedule = $runner->save_schedule_settings( true, array( 1, 3 ), '04:30' );
	yd_geo_pipeline_v2_assert( ! empty( $schedule['enabled'] ) && array( 1, 3 ) === $schedule['days'] && '04:30' === $schedule['time'], 'Pipeline schedule settings must be saved.' );
	yd_geo_pipeline_v2_assert( isset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::SCHEDULE_HOOK ] ), 'Pipeline schedule must create WP-Cron start event.' );
	$scheduled_timestamp = (int) $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::SCHEDULE_HOOK ]['timestamp'];
	$scheduled_nsk = ( new \DateTimeImmutable( '@' . $scheduled_timestamp ) )->setTimezone( new \DateTimeZone( 'Asia/Novosibirsk' ) );
	yd_geo_pipeline_v2_assert( '04:30' === $scheduled_nsk->format( 'H:i' ) && in_array( (int) $scheduled_nsk->format( 'N' ), array( 1, 3 ), true ), 'Pipeline schedule timestamp must represent selected Novosibirsk time and day.' );
	yd_geo_pipeline_v2_assert( ( new \WallsShop\WDC\Calendar\Services\TimezoneService() )->format_timestamp( $scheduled_timestamp ) === $schedule['next_run'], 'Pipeline next_run must be displayed in Novosibirsk time.' );
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
	yd_geo_pipeline_v2_assert( array( $session ) === $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::CRON_HOOK ]['args'], 'Pipeline continuation must be scoped to the current session.' );
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

	$make_runner = static function ( ?callable $budget_factory = null ): array {
		$GLOBALS['yd_geo_pipeline_v2_options'] = array();
		$GLOBALS['yd_geo_pipeline_v2_scheduled'] = array();
		$GLOBALS['yd_geo_pipeline_v2_schedule_calls'] = 0;
		$GLOBALS['yd_geo_pipeline_v2_atomic_units'] = 0;
		$GLOBALS['yd_geo_pipeline_v2_download_error'] = false;
		$GLOBALS['yd_geo_pipeline_v2_overlap_callback'] = null;
		$GLOBALS['yd_geo_pipeline_v2_between_units_callback'] = null;
		$pickup = new \WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2RunnerService();
		$pickup_repository = new \WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository();
		$geo_builder = new \WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2BuilderRunnerService();
		$geo_repository = new \WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2Repository();
		$mapping_repository = new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository();
		$region_enrichment = new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexGeoV2RegionEnrichmentRunner();
		$location_mapping = new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Runner();
		$GLOBALS['yd_pipeline_fake_pickup_repository'] = $pickup_repository;
		$GLOBALS['yd_pipeline_fake_geo_repository'] = $geo_repository;
		$GLOBALS['yd_pipeline_fake_mapping_repository'] = $mapping_repository;
		$lock = new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2ExecutionLock();
		$runner = new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner(
			$pickup,
			$pickup_repository,
			$geo_builder,
			$geo_repository,
			$region_enrichment,
			new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexRegionMappingV2Repository(),
			$location_mapping,
			$mapping_repository,
			new \WallsShop\WDC\Calendar\Services\TimezoneService(),
			$lock,
			$budget_factory
		);

		return compact( 'runner', 'pickup', 'pickup_repository', 'geo_builder', 'geo_repository', 'region_enrichment', 'location_mapping', 'mapping_repository', 'lock' );
	};
	$make_admin_page = static function ( array $bundle ): \WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage {
		$reflection = new \ReflectionClass( \WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage::class );
		/** @var \WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage $page */
		$page = $reflection->newInstanceWithoutConstructor();
		foreach ( array(
			'yandex_delivery_geo_pipeline_v2_runner' => $bundle['runner'],
			'yandex_delivery_pickup_v2_runner' => $bundle['pickup'],
			'yandex_delivery_geo_v2_builder_runner' => $bundle['geo_builder'],
			'yandex_geo_v2_region_enrichment_runner' => $bundle['region_enrichment'],
			'yandex_location_mapping_v2_runner' => $bundle['location_mapping'],
		) as $property_name => $value ) {
			$property = $reflection->getProperty( $property_name );
			$property->setValue( $page, $value );
		}

		return $page;
	};
	$run_scheduled = static function ( object $runner, string $session_id ): void {
		unset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::CRON_HOOK ] );
		$runner->run_scheduled_step( $session_id );
	};

	$bundle = $make_runner( static fn(): \WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget => new \WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget( 100.0, 3 ) );
	$started = $bundle['runner']->start();
	$session = (string) $started['session_id'];
	$run_scheduled( $bundle['runner'], $session );
	yd_geo_pipeline_v2_assert( 1 === $bundle['pickup']->start_full_api_sync_count && 0 === $bundle['pickup']->import_step_count, 'Heavy JSON download must run once and end its worker slice before local import.' );
	yd_geo_pipeline_v2_assert( array( $session ) === ( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::CRON_HOOK ]['args'] ?? array() ), 'Heavy download must leave one owner-scoped continuation.' );
	$before_units = $GLOBALS['yd_geo_pipeline_v2_atomic_units'];
	$run_scheduled( $bundle['runner'], $session );
	yd_geo_pipeline_v2_assert( 3 === $GLOBALS['yd_geo_pipeline_v2_atomic_units'] - $before_units, 'Scheduled worker must process multiple local units and stop exactly at the unit cap.' );
	yd_geo_pipeline_v2_assert( 'build_geo_v2' === (string) $bundle['runner']->current_state()['stage'], 'Three local units after download must preserve stage order and reach geo_v2.' );
	$run_scheduled( $bundle['runner'], $session );
	$run_scheduled( $bundle['runner'], $session );
	$run_scheduled( $bundle['runner'], $session );
	yd_geo_pipeline_v2_assert( 'done' === (string) $bundle['runner']->current_state()['status'] && ! isset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::CRON_HOOK ] ), 'Terminal done must leave zero continuation.' );

	$bundle = $make_runner( static fn(): \WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget => new \WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget( 100.0, 3 ) );
	$admin_page = $make_admin_page( $bundle );
	$session = (string) $bundle['runner']->start()['session_id'];
	$run_scheduled( $bundle['runner'], $session );
	$run_scheduled( $bundle['runner'], $session );
	yd_geo_pipeline_v2_assert( 'build_geo_v2' === (string) $bundle['runner']->current_state()['stage'] && 1 === $bundle['pickup']->cleanup_count && ! $bundle['pickup']->json_available, 'Successful outer-owned pickup completion must promote staging, delete its JSON once, and advance to build_geo_v2.' );

	$assert_ajax_conflict = static function ( callable $request, string $message ): void {
		$GLOBALS['yd_geo_pipeline_v2_ajax_response'] = array();
		$request();
		$response = $GLOBALS['yd_geo_pipeline_v2_ajax_response'];
		yd_geo_pipeline_v2_assert( false === ( $response['success'] ?? true ) && 409 === (int) ( $response['status_code'] ?? 0 ) && str_contains( (string) ( $response['data']['message'] ?? '' ), 'Полное обновление Яндекс ПВЗ/географии' ), $message );
	};
	$pickup_steps = $bundle['pickup']->import_step_count;
	$assert_ajax_conflict( static fn() => $admin_page->ajax_yandex_delivery_pickup_v2_runner_step(), 'A stale browser pickup step must receive HTTP 409 while the full pipeline is running.' );
	yd_geo_pipeline_v2_assert( $pickup_steps === $bundle['pickup']->import_step_count && 0 === $bundle['pickup']->unreadable_json_attempts && 1 === $bundle['pickup']->cleanup_count, 'Rejected stale pickup AJAX must not re-read the successfully deleted JSON or overwrite lower state.' );

	$geo_starts = $bundle['geo_builder']->start_count;
	$enrichment_starts = $bundle['region_enrichment']->start_count;
	$mapping_starts = $bundle['location_mapping']->start_count;
	$assert_ajax_conflict( static fn() => $admin_page->ajax_yandex_delivery_geo_v2_builder_start(), 'Standalone geo builder mutation must be blocked while the full pipeline is active.' );
	$assert_ajax_conflict( static fn() => $admin_page->ajax_yandex_geo_v2_region_enrichment_start(), 'Standalone region enrichment mutation must be blocked while the full pipeline is active.' );
	$assert_ajax_conflict( static fn() => $admin_page->ajax_yandex_location_mapping_v2_start(), 'Standalone location mapping mutation must be blocked while the full pipeline is active.' );
	yd_geo_pipeline_v2_assert( $geo_starts === $bundle['geo_builder']->start_count && $enrichment_starts === $bundle['region_enrichment']->start_count && $mapping_starts === $bundle['location_mapping']->start_count, 'Active full-pipeline guard must prevent every lower callback from mutating its runner.' );

	$bundle['runner']->pause();
	$assert_ajax_conflict( static fn() => $admin_page->ajax_yandex_delivery_pickup_v2_runner_reset(), 'Paused full pipeline must retain ownership and reject standalone pickup reset.' );
	foreach ( array( 'done', 'error', 'idle' ) as $inactive_status ) {
		$outer_state = $bundle['runner']->current_state();
		$outer_state['status'] = $inactive_status;
		$GLOBALS['yd_geo_pipeline_v2_options']['wdc_yandex_delivery_geo_pipeline_v2_state'] = $outer_state;
		$GLOBALS['yd_geo_pipeline_v2_ajax_response'] = array();
		$admin_page->ajax_yandex_delivery_pickup_v2_runner_reset();
		yd_geo_pipeline_v2_assert( true === ( $GLOBALS['yd_geo_pipeline_v2_ajax_response']['success'] ?? false ), 'Standalone pickup mutation must remain available when full pipeline status is ' . $inactive_status . '.' );
	}
	$inactive_state = $bundle['runner']->current_state();
	$inactive_state['status'] = 'idle';
	$GLOBALS['yd_geo_pipeline_v2_options']['wdc_yandex_delivery_geo_pipeline_v2_state'] = $inactive_state;
	foreach ( array(
		array( static fn() => $admin_page->ajax_yandex_delivery_geo_v2_builder_start(), $bundle['geo_builder'], 'start_count', 'geo builder' ),
		array( static fn() => $admin_page->ajax_yandex_geo_v2_region_enrichment_start(), $bundle['region_enrichment'], 'start_count', 'region enrichment' ),
		array( static fn() => $admin_page->ajax_yandex_location_mapping_v2_start(), $bundle['location_mapping'], 'start_count', 'location mapping' ),
	) as $inactive_mutation ) {
		$before = $inactive_mutation[1]->{$inactive_mutation[2]};
		$GLOBALS['yd_geo_pipeline_v2_ajax_response'] = array();
		$inactive_mutation[0]();
		yd_geo_pipeline_v2_assert( true === ( $GLOBALS['yd_geo_pipeline_v2_ajax_response']['success'] ?? false ) && $before + 1 === $inactive_mutation[1]->{$inactive_mutation[2]}, 'Inactive full pipeline must preserve standalone ' . $inactive_mutation[3] . ' mutation.' );
	}

	$clock = 0.0;
	$bundle = $make_runner( static function () use ( &$clock ): \WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget {
		return new \WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget( 18.0, 25, null, static function () use ( &$clock ): float { $clock += 10.0; return $clock; } );
	} );
	$session = (string) $bundle['runner']->start()['session_id'];
	$run_scheduled( $bundle['runner'], $session );
	$before_units = $GLOBALS['yd_geo_pipeline_v2_atomic_units'];
	$run_scheduled( $bundle['runner'], $session );
	yd_geo_pipeline_v2_assert( 1 === $GLOBALS['yd_geo_pipeline_v2_atomic_units'] - $before_units && 'importing' === $bundle['pickup']->current_state()['status'], 'Deterministic fake clock must stop the local loop on time budget.' );

	$memory = 0;
	$bundle = $make_runner( static function () use ( &$memory ): \WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget {
		$memory = 0;
		return new \WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget( 100.0, 25, 80, static fn(): float => 0.0, static function () use ( &$memory ): int { $memory += 50; return $memory; } );
	} );
	$session = (string) $bundle['runner']->start()['session_id'];
	$run_scheduled( $bundle['runner'], $session );
	$before_units = $GLOBALS['yd_geo_pipeline_v2_atomic_units'];
	$run_scheduled( $bundle['runner'], $session );
	yd_geo_pipeline_v2_assert( 1 === $GLOBALS['yd_geo_pipeline_v2_atomic_units'] - $before_units && 'importing' === $bundle['pickup']->current_state()['status'], 'Deterministic memory provider must stop the local loop on memory budget.' );

	$bundle = $make_runner();
	$GLOBALS['yd_geo_pipeline_v2_download_error'] = true;
	$session = (string) $bundle['runner']->start()['session_id'];
	$run_scheduled( $bundle['runner'], $session );
	yd_geo_pipeline_v2_assert( 'error' === (string) $bundle['runner']->current_state()['status'] && 0 === $bundle['pickup']->cleanup_count && ! isset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::CRON_HOOK ] ), 'Download error must be terminal, must not clean up a successful-import JSON, and must leave zero continuation.' );

	$bundle = $make_runner();
	$old_session = (string) $bundle['runner']->start()['session_id'];
	$bundle['runner']->reset();
	$new_session = (string) $bundle['runner']->start()['session_id'];
	$before_units = $GLOBALS['yd_geo_pipeline_v2_atomic_units'];
	$bundle['runner']->run_scheduled_step( $old_session );
	yd_geo_pipeline_v2_assert( $new_session === (string) $bundle['runner']->current_state()['session_id'] && $before_units === $GLOBALS['yd_geo_pipeline_v2_atomic_units'], 'Stale callback must do zero work and must not overwrite a new session.' );

	unset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::CRON_HOOK ] );
	$before_units = $GLOBALS['yd_geo_pipeline_v2_atomic_units'];
	$bundle['runner']->run_scheduled_step();
	yd_geo_pipeline_v2_assert( $before_units === $GLOBALS['yd_geo_pipeline_v2_atomic_units'] && array( $new_session ) === ( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::CRON_HOOK ]['args'] ?? array() ), 'Legacy callback without args must do no work and self-heal one owner-scoped continuation.' );
	unset( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::CRON_HOOK ] );
	$bundle['runner']->ensure_schedule();
	$calls = $GLOBALS['yd_geo_pipeline_v2_schedule_calls'];
	$bundle['runner']->ensure_schedule();
	yd_geo_pipeline_v2_assert( $calls === $GLOBALS['yd_geo_pipeline_v2_schedule_calls'], 'Schedule ensure must restore a missing continuation without creating a duplicate.' );

	$bundle = $make_runner();
	$session = (string) $bundle['runner']->start()['session_id'];
	$run_scheduled( $bundle['runner'], $session );
	$GLOBALS['yd_geo_pipeline_v2_between_units_callback'] = static fn() => $bundle['runner']->pause();
	$run_scheduled( $bundle['runner'], $session );
	yd_geo_pipeline_v2_assert( 'paused' === (string) $bundle['runner']->current_state()['status'] && 0 === $bundle['pickup']->import_step_count, 'Pause between units must prevent the next local unit and leave zero continuation.' );
	$resumed = $bundle['runner']->resume();
	$calls = $GLOBALS['yd_geo_pipeline_v2_schedule_calls'];
	$bundle['runner']->resume();
	yd_geo_pipeline_v2_assert( $session === (string) $resumed['session_id'] && 'running' === (string) $resumed['status'] && $calls === $GLOBALS['yd_geo_pipeline_v2_schedule_calls'], 'Resume must keep the same session and create at most one owner-scoped continuation.' );

	$bundle = $make_runner();
	$old_session = (string) $bundle['runner']->start()['session_id'];
	$run_scheduled( $bundle['runner'], $old_session );
	$new_session = '';
	$GLOBALS['yd_geo_pipeline_v2_between_units_callback'] = static function () use ( $bundle, &$new_session ): void {
		$bundle['runner']->reset();
		$new_session = (string) $bundle['runner']->start()['session_id'];
	};
	$run_scheduled( $bundle['runner'], $old_session );
	yd_geo_pipeline_v2_assert( '' !== $new_session && $new_session === (string) $bundle['runner']->current_state()['session_id'] && 0 === $bundle['pickup']->import_step_count, 'Reset/new session between units must make the old worker stale without overwriting the new owner.' );
	yd_geo_pipeline_v2_assert( array( $new_session ) === ( $GLOBALS['yd_geo_pipeline_v2_scheduled'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner::CRON_HOOK ]['args'] ?? array() ), 'Reset/new session race must retain only the new owner continuation.' );

	$bundle = $make_runner();
	$session = (string) $bundle['runner']->start()['session_id'];
	$GLOBALS['yd_geo_pipeline_v2_overlap_callback'] = static fn() => $bundle['runner']->run_scheduled_step( $session );
	$run_scheduled( $bundle['runner'], $session );
	yd_geo_pipeline_v2_assert( 1 === $bundle['pickup']->start_full_api_sync_count, 'Second overlapping worker must not process the active pipeline while its lease is owned.' );

	$bundle = $make_runner();
	$token_a = $bundle['lock']->acquire( 'session-a' );
	yd_geo_pipeline_v2_assert( is_string( $token_a ) && null === $bundle['lock']->acquire( 'session-b' ), 'Execution lease must reject a second owner while active.' );
	$lease = $GLOBALS['yd_geo_pipeline_v2_options'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2ExecutionLock::OPTION_NAME ];
	$lease['expires_at'] = 0;
	$GLOBALS['yd_geo_pipeline_v2_options'][ \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2ExecutionLock::OPTION_NAME ] = $lease;
	$token_b = $bundle['lock']->acquire( 'session-b' );
	yd_geo_pipeline_v2_assert( is_string( $token_b ), 'Expired lease must allow compare-delete takeover by a new owner.' );
	yd_geo_pipeline_v2_assert( ! $bundle['lock']->renew( 'session-a', (string) $token_a ), 'Stale owner must not renew the current owner lease.' );
	$bundle['lock']->release( 'session-a', (string) $token_a );
	yd_geo_pipeline_v2_assert( $bundle['lock']->owns( 'session-b', (string) $token_b ), 'Stale owner must not release the current owner lease.' );
	$bundle['lock']->release( 'session-b', (string) $token_b );

	echo "Yandex Delivery geo pipeline v2 smoke OK\n";
}
