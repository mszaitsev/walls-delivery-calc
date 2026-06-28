<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );

function yd_geo_pipeline_v2_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

$pipeline_file = $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexDeliveryGeoPipelineV2Runner.php';
$pipeline_source = (string) file_get_contents( $pipeline_file );
$admin_source = (string) file_get_contents( $root . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( $root . '/src/Core/Plugin.php' );
$js_source = (string) file_get_contents( $root . '/assets/admin/yandex-delivery-pickup-v2-runner.js' );
$mapper_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMapperV2Service.php' );
$geo_builder_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/GeoV2/YandexDeliveryGeoV2BuilderService.php' );
$geo_repository_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/GeoV2/YandexDeliveryGeoV2Repository.php' );
$mapping_repository_source = (string) file_get_contents( $root . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMappingV2Repository.php' );
$plugin_main = (string) file_get_contents( $root . '/walls-delivery-calc.php' );

foreach ( array( 'import_pvz', 'build_geo_v2', 'region_enrichment', 'region_mapping', 'location_mapping', 'done' ) as $stage ) {
	yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, $stage ), 'Pipeline runner must contain stage: ' . $stage );
}
$stage_order = array_map( static fn( string $stage ): int|false => strpos( $pipeline_source, "private const STAGE_" . strtoupper( $stage ) ), array( 'import_pvz', 'build_geo_v2', 'region_enrichment', 'region_mapping', 'location_mapping' ) );
yd_geo_pipeline_v2_assert( ! in_array( false, $stage_order, true ), 'Pipeline runner must define all stage constants.' );
yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, 'start_full_api_sync' ) && str_contains( $pipeline_source, 'run_import_step' ), 'Pipeline import stage must use existing pickup v2 runner.' );
yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, 'YandexDeliveryGeoV2BuilderRunnerService' ) && str_contains( $pipeline_source, 'run_geo_builder_stage' ), 'Pipeline must use existing geo_v2 builder runner.' );
yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, 'YandexGeoV2RegionEnrichmentRunner' ) && str_contains( $pipeline_source, 'run_region_enrichment_stage' ), 'Pipeline must use existing region enrichment runner.' );
yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, 'sync_from_sources' ) && str_contains( $pipeline_source, 'run_region_mapping_stage' ), 'Pipeline must sync region mapping through repository.' );
yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, 'YandexLocationMappingV2Runner' ) && str_contains( $pipeline_source, 'run_location_mapping_stage' ), 'Pipeline must use existing location mapping runner.' );
yd_geo_pipeline_v2_assert( str_contains( $pipeline_source, 'location_mapping_summary' ) && str_contains( $pipeline_source, 'mapped_by_dominance' ), 'Pipeline summary must include location mapping statistics.' );

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
yd_geo_pipeline_v2_assert( str_contains( $plugin_main, "Version: 0.99.1" ) && str_contains( $plugin_main, "define( 'WDC_VERSION', '0.99.1' )" ), 'Plugin version must be 0.99.1.' );

echo "Yandex Delivery geo pipeline v2 smoke OK\n";
