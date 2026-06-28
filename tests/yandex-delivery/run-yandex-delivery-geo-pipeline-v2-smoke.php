<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/../../' );

	$GLOBALS['yd_geo_pipeline_v2_options'] = array();

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
		public int $truncate_count = 0;

		public function truncate(): void { ++$this->truncate_count; $this->rows = array(); }
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

		public function reset(): array { $this->state = array( 'status' => 'idle', 'processed' => 0, 'total' => 3, 'offset' => 0, 'message' => '' ); $this->steps = 0; return $this->state; }
		public function start_full_api_sync(): array { $this->state = array( 'status' => 'ready_to_import', 'processed' => 0, 'total' => 3, 'offset' => 0, 'message' => 'downloaded', 'saved' => 0 ); return $this->state; }
		public function current_state(): array { return $this->state; }
		public function start_import(): array {
			$repository = $GLOBALS['yd_pipeline_fake_pickup_repository'] ?? null;
			if ( empty( $this->state['pickup_points_truncated'] ) && $repository instanceof YandexDeliveryPickupPointV2Repository ) {
				$repository->truncate();
				$this->state['pickup_points_truncated'] = true;
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
				$repository->rows[] = 1 === $this->steps ? array( 'platform_station_id' => 'new-10', 'active' => 1, 'yandex_geo_id' => 10 ) : array( 'platform_station_id' => 'new-20', 'active' => 1, 'yandex_geo_id' => 20 );
			}
			$this->state['processed'] = $this->steps;
			$this->state['saved'] = $this->steps;
			$this->state['offset'] = $this->steps;
			$this->state['status'] = $this->steps >= 2 ? 'done' : 'importing';
			$this->state['message'] = $this->steps >= 2 ? 'pickup done' : 'pickup batch';
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
			$this->started_with_only_new_pickups = $pickup_repository instanceof \WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository && array( 10, 20 ) === array_values( array_map( static fn( array $row ): int => (int) $row['yandex_geo_id'], $pickup_repository->rows ) );
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
		public function start(): array { $this->state = array( 'status' => 'mapping', 'processed' => 0, 'message' => 'mapping started' ); return $this->state; }
		public function current_state(): array { return $this->state; }
		public function run_step(): array { ++$this->steps; $this->state = 1 === $this->steps ? array( 'status' => 'mapping', 'processed' => 1, 'message' => 'mapping halfway' ) : array( 'status' => 'done', 'processed' => 2, 'message' => 'mapping done' ); return $this->state; }
		public function pause(): array { $this->state['status'] = 'paused'; return $this->state; }
	}

	final class YandexLocationMappingV2Repository {
		public function statistics(): array { return array( 'mapped' => 2, 'manual' => 99, 'needs_review' => 0, 'no_match' => 0, 'error' => 0, 'avg_confidence' => 100, 'avg_distance' => 1.5, 'territory_fallback' => 0, 'mapped_by_dominance' => array( 'distance_gap' => 1 ) ); }
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
	yd_geo_pipeline_v2_assert( str_contains( $pickup_runner_source, 'pickup_points_truncated' ) && str_contains( $pickup_runner_source, 'truncate_repository' ), 'Pickup v2 runner must truncate pickup table once before the first import batch.' );
	yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, '$this->geo_repository->truncate();' ), 'Pipeline must truncate geo_v2 before build_geo_v2 after fresh PVZ import.' );
	yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, 'count_active_unique_geo_ids' ), 'Pipeline must use active unique pickup geoId total for geo_v2 build progress.' );
	yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, '$this->geo_repository->count_active()' ), 'Pipeline must use active geo_v2 total for location mapping progress.' );
	yd_geo_pipeline_v2_assert( ! str_contains( $pipeline_source, "'mapped', 'manual', 'needs_review'" ), 'Pipeline location mapping summary must not expose unused manual status.' );

	yd_geo_pipeline_v2_assert( str_contains( $plugin_source, 'YandexDeliveryGeoPipelineV2Runner::class' ) && str_contains( $plugin_source, 'new YandexDeliveryGeoPipelineV2Runner' ), 'Plugin DI must register pipeline runner.' );
	yd_geo_pipeline_v2_assert( str_contains( $admin_source, 'Полное обновление Яндекс ПВЗ/географии' ) && str_contains( $admin_source, 'wdc_yandex_delivery_geo_pipeline_v2_start' ) && str_contains( $admin_source, 'geoPipelineInitialState' ), 'Admin must expose one-button pipeline block and AJAX actions.' );
	yd_geo_pipeline_v2_assert( str_contains( $js_source, 'data-wdc-yandex-geo-pipeline-v2' ) && str_contains( $js_source, 'wdc_yandex_delivery_geo_pipeline_v2_step' ) && str_contains( $js_source, "runningStatus: 'running'" ), 'JS must contain pipeline loop.' );

	yd_geo_pipeline_v2_assert( str_contains( $mapper_source, 'load_active_overrides_cache' ) && str_contains( $mapper_source, 'manual_override_decision' ), 'Manual overrides must still be applied inside location mapping.' );
	yd_geo_pipeline_v2_assert( str_contains( $mapper_source, 'compact_raw_json' ) && str_contains( $mapper_source, 'wdc_yandex_location_mapping_v2_debug_raw' ), 'Location mapping raw_json must be compact by default with debug opt-in.' );
	foreach ( array( 'sql_search_terms', 'address_locality_terms', 'rejected_samples', 'rejected_candidates', 'diagnostics' ) as $heavy_key ) {
		yd_geo_pipeline_v2_assert( str_contains( $mapper_source, "unset( \$raw[ '" . $heavy_key . "' ]" ) || str_contains( $mapper_source, "'" . $heavy_key . "'" ), 'Mapper compact raw logic must know heavy key: ' . $heavy_key );
	}
	yd_geo_pipeline_v2_assert( str_contains( $geo_builder_source, "'sample_points_json' => \$this->json( array( 'addresses'" ) && str_contains( $geo_builder_source, 'sample_addresses' ), 'Geo builder must persist compact address-only sample JSON.' );
	yd_geo_pipeline_v2_assert( str_contains( $geo_repository_source, 'compact_region_enrichment_audit' ) && str_contains( $geo_repository_source, 'wdc_yandex_geo_v2_region_enrichment_debug_raw' ), 'Geo repository must compact region enrichment audit by default.' );
	yd_geo_pipeline_v2_assert( str_contains( $mapping_repository_source, 'find_recent_no_match' ) && ! str_contains( $mapping_repository_source, "'sql_search_terms' =>" ), 'Review/no_match repository output must not depend on heavy sql_search_terms.' );
	yd_geo_pipeline_v2_assert( str_contains( $plugin_main, 'Version: 0.99.3' ) && str_contains( $plugin_main, "define( 'WDC_VERSION', '0.99.3' )" ), 'Plugin version must be 0.99.3.' );

	$pickup_runner = new \WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2RunnerService();
	$pickup_repository = new \WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository();
	$GLOBALS['yd_pipeline_fake_pickup_repository'] = $pickup_repository;
	$geo_builder_runner = new \WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2BuilderRunnerService();
	$geo_repository = new \WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2Repository();
	$GLOBALS['yd_pipeline_fake_geo_repository'] = $geo_repository;
	$runner = new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexDeliveryGeoPipelineV2Runner(
		$pickup_runner,
		$pickup_repository,
		$geo_builder_runner,
		$geo_repository,
		new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexGeoV2RegionEnrichmentRunner(),
		new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexRegionMappingV2Repository(),
		new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Runner(),
		new \WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository()
	);

	$runner->start();
	$state = $runner->run_step();
	yd_geo_pipeline_v2_assert( 'import_pvz' === $state['stage'] && 'importing' === $pickup_runner->current_state()['status'], 'Pipeline must start pickup import before first batch.' );
	yd_geo_pipeline_v2_assert( 1 === $pickup_repository->truncate_count && 0 === $pickup_repository->count_all(), 'Pipeline pickup import must truncate pickup_points_v2 exactly once before first batch.' );

	$state = $runner->run_step();
	yd_geo_pipeline_v2_assert( 'import_pvz' === $state['stage'] && 1 === $pickup_repository->count_all() && 1 === $pickup_repository->truncate_count && 1 === $pickup_runner->truncate_count_at_step, 'First pickup import batch must run after truncate without repeating truncate.' );

	$state = $runner->run_step();
	yd_geo_pipeline_v2_assert( 'build_geo_v2' === $state['stage'], 'Pipeline must advance to build_geo_v2 after pickup batches finish.' );
	yd_geo_pipeline_v2_assert( 1 === $pickup_repository->truncate_count && 2 === $pickup_repository->count_all(), 'Second pickup import batch must not truncate again.' );
	yd_geo_pipeline_v2_assert( array( 10, 20 ) === array_values( array_map( static fn( array $row ): int => (int) $row['yandex_geo_id'], $pickup_repository->rows ) ), 'Pickup repository must contain only fresh imported rows after one-time truncate.' );
	yd_geo_pipeline_v2_assert( $geo_repository->truncated, 'Pipeline must truncate geo_v2 after import_pvz before geo_v2 build.' );
	yd_geo_pipeline_v2_assert( $geo_builder_runner->started_after_truncate && $geo_builder_runner->started_with_only_new_pickups, 'Geo builder must start after geo_v2 truncate and see only fresh pickup rows.' );

	$state = $runner->run_step();
	yd_geo_pipeline_v2_assert( 'build_geo_v2' === $state['stage'], 'Pipeline must stay on build_geo_v2 while builder is running.' );
	yd_geo_pipeline_v2_assert( 1 === $state['processed'] && 2 === $state['total'] && 50 === $state['percent'], 'build_geo_v2 must expose processed/total/percent from active unique pickup geoId count.' );

	$runner->run_step();
	$runner->run_step();
	$runner->run_step();
	$state = $runner->run_step();
	yd_geo_pipeline_v2_assert( 'location_mapping' === $state['stage'], 'Pipeline must reach location_mapping stage.' );
	yd_geo_pipeline_v2_assert( 1 === $state['processed'] && 2 === $state['total'] && 50 === $state['percent'], 'location_mapping must expose processed/total/percent from active geo_v2 rows.' );
	yd_geo_pipeline_v2_assert( ! array_key_exists( 'manual', $state['summary']['location_mapping'] ?? array() ), 'location_mapping summary must not contain manual.' );

	echo "Yandex Delivery geo pipeline v2 smoke OK\n";
}