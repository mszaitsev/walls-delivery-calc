<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexGeoV2RegionEnrichmentRunner;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexGeoV2RegionEnrichmentService;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2NameNormalizer;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexRegionMappingV2Repository;

function yd_geo_v2_region_enrichment_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function current_time( string $type ): string { return '2026-06-27 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['yd_geo_v2_region_enrichment_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['yd_geo_v2_region_enrichment_options'][ $key ] = $value; return true; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $yandex_delivery_geo_v2 = array();
		public array $yandex_region_mapping_v2 = array();
		public array $wdc_locations = array();
		public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) { $query = preg_replace( '/%[sdf]/', is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query; } return $query; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

$GLOBALS['yd_geo_v2_region_enrichment_options'] = array();
$GLOBALS['wpdb'] = new wpdb();

$geo = static function ( int $geo_id, string $region, string $locality, ?float $lat, ?float $lon, float $radius = 3.0, int $points = 10 ): array {
	return array( 'yandex_geo_id' => $geo_id, 'region' => $region, 'locality' => $locality, 'centroid_lat' => $lat, 'centroid_lon' => $lon, 'coverage_radius_safe_km' => $radius, 'points_count' => $points, 'raw_stats_json' => '{}', 'active' => 1, 'created_at' => '2026-06-27 00:00:00', 'updated_at' => '2026-06-27 00:00:00' );
};
$location = static function ( int $id, string $region, string $city, string $settlement, float $lat, float $lon, string $place = '', string $city_type = '', string $settlement_type = '', string $place_type = '' ): array {
	return array( 'id' => $id, 'region_name' => $region, 'city_name' => $city, 'city_type' => $city_type, 'settlement_name' => $settlement, 'settlement_type' => $settlement_type, 'place_name' => $place, 'place_type' => $place_type, 'display_name' => trim( $city . ' ' . $settlement . ' ' . $place ), 'latitude' => $lat, 'longitude' => $lon, 'active' => 1 );
};

$GLOBALS['wpdb']->yandex_delivery_geo_v2 = array(
	$geo( 100, '', 'Брянск г', 53.2434, 34.3642, 3.0, 50 ),
	$geo( 200, '', 'Починок', 54.4063, 32.4390, 3.0, 40 ),
	$geo( 300, '', 'Павловск', 59.6850, 30.4300, 6.0, 30 ),
	$geo( 400, '', 'Новопавловск г', 43.9600, 43.6400, 10.0, 20 ),
	$geo( 500, '', 'Алгасово с', 0.0, 0.0, 3.0, 10 ),
	$geo( 600, 'Уже есть', 'Не трогать', 55.0, 37.0, 3.0, 5 ),
);
$GLOBALS['wpdb']->wdc_locations = array(
	$location( 100, 'Брянская', 'Брянск', '', 53.2434, 34.3642, '', 'г' ),
	$location( 200, 'Смоленская', '', 'Починок', 54.4063, 32.4390 ),
	$location( 201, 'Смоленская', '', 'Починок', 54.4070, 32.4400 ),
	$location( 300, 'Санкт-Петербург', '', 'Павловск', 59.6850, 30.4300 ),
	$location( 301, 'Воронежская', '', 'Павловск', 59.6900, 30.4300 ),
	$location( 400, 'Ставропольский', 'Новопавловск', '', 43.9600, 43.6400, '', 'г' ),
	$location( 401, 'Другая', 'Новопавловск', '', 44.0250, 43.6400, '', 'г' ),
);

$geo_repository = new YandexDeliveryGeoV2Repository( $GLOBALS['wpdb'] );
$region_repository = new YandexRegionMappingV2Repository( $GLOBALS['wpdb'] );
foreach ( array( 'Брянская', 'Смоленская', 'Санкт-Петербург', 'Воронежская', 'Ставропольский', 'Другая' ) as $region ) {
	$region_repository->save_mapping( $region, array( $region ) );
}
$service = new YandexGeoV2RegionEnrichmentService( $geo_repository, $GLOBALS['wpdb'], new YandexLocationMappingV2NameNormalizer(), $region_repository );

$result100 = $service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[0] );
yd_geo_v2_region_enrichment_assert( 'updated' === $result100['status'] && 'Брянская' === $result100['region'], 'Single candidate must update empty geo_v2 region.' );
$row100 = $geo_repository->find_by_geo_id( 100 );
$raw100 = json_decode( (string) ( $row100['raw_stats_json'] ?? '' ), true );
yd_geo_v2_region_enrichment_assert( 'Брянская' === (string) ( $row100['region'] ?? '' ) && 'wdc_location_coordinates' === $raw100['region_enrichment']['source'] && 100 === (int) $raw100['region_enrichment']['audit']['matched_location_id'] && 'region_mapping_v2' === $raw100['region_enrichment']['audit']['region_mapping_source'], 'update_region_from_location must update region and raw_stats_json audit.' );

$result200 = $service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[1] );
yd_geo_v2_region_enrichment_assert( 'updated' === $result200['status'] && 'Смоленская' === $result200['region'] && 'single_region' === $result200['reason'] && 2 === $result200['candidate_count'], 'Multiple close candidates in the same region must update with single_region reason.' );
$result300 = $service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[2] );
yd_geo_v2_region_enrichment_assert( 'needs_review' === $result300['status'] && 'multiple_regions' === $result300['reason'] && '' === (string) $geo_repository->find_by_geo_id( 300 )['region'], 'Similar candidates from multiple regions must stay needs_review.' );
$result400 = $service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[3] );
yd_geo_v2_region_enrichment_assert( 'updated' === $result400['status'] && 'Ставропольский' === $result400['region'] && 'dominant_candidate' === $result400['reason'], 'Dominant nearby candidate must update region.' );
$result500 = $service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[4] );
yd_geo_v2_region_enrichment_assert( 'skipped' === $result500['status'] && 'invalid_coords' === $result500['reason'], 'Invalid geo coordinates must be skipped.' );

yd_geo_v2_region_enrichment_assert( 2 === $geo_repository->count_empty_active_regions(), 'Repository must count remaining active empty regions.' );
$batch = $service->enrich_batch( 0, 10 );
yd_geo_v2_region_enrichment_assert( 2 === $batch['processed'] && 1 === $batch['needs_review'] && 1 === $batch['skipped'], 'Batch enrichment must process remaining empty regions.' );

$runner_repository = new YandexDeliveryGeoV2Repository( $GLOBALS['wpdb'] );
$runner = new YandexGeoV2RegionEnrichmentRunner( new YandexGeoV2RegionEnrichmentService( $runner_repository, $GLOBALS['wpdb'], null, $region_repository ), $runner_repository );
$state = $runner->start();
yd_geo_v2_region_enrichment_assert( 'enriching_regions' === $state['status'] && 2 === $state['empty_regions_remaining'], 'Runner start must switch to enriching_regions.' );
$state = $runner->run_step();
yd_geo_v2_region_enrichment_assert( 'done' === $state['status'] && 2 === $state['processed'] && 1 === $state['needs_review'] && 1 === $state['skipped'], 'Runner step must update enrichment counters.' );
$runner->start();
$paused = $runner->pause();
$after_pause = $runner->run_step();
yd_geo_v2_region_enrichment_assert( 'paused' === $paused['status'] && $after_pause['processed'] === $paused['processed'], 'Runner pause must stop enrichment loop.' );
$reset = $runner->reset();
yd_geo_v2_region_enrichment_assert( 'idle' === $reset['status'] && 0 === $reset['processed'], 'Runner reset must clear state.' );

$geo_repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/GeoV2/YandexDeliveryGeoV2Repository.php' );
$service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexGeoV2RegionEnrichmentService.php' );
$runner_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexGeoV2RegionEnrichmentRunner.php' );
$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/yandex-delivery-pickup-v2-runner.js' );
$mapper_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMapperV2Service.php' );
yd_geo_v2_region_enrichment_assert( str_contains( $service_source, 'YandexGeoV2RegionEnrichmentService' ) && str_contains( $runner_source, 'YandexGeoV2RegionEnrichmentRunner' ), 'Enrichment service and runner must exist.' );
yd_geo_v2_region_enrichment_assert( str_contains( $geo_repository_source, 'update_region_from_location' ) && str_contains( $geo_repository_source, 'region_enrichment' ), 'Geo v2 repository must expose update_region_from_location with audit.' );
yd_geo_v2_region_enrichment_assert( str_contains( $admin_source, 'Обогащение пустых регионов geo_v2' ) && str_contains( $admin_source, 'Запустить обогащение регионов' ) && str_contains( $admin_source, 'wdc_yandex_geo_v2_region_enrichment_start' ), 'Admin UI must contain geo_v2 region enrichment block and AJAX actions.' );
yd_geo_v2_region_enrichment_assert( str_contains( $js_source, 'data-wdc-yandex-geo-v2-region-enrichment' ) && str_contains( $js_source, 'enriching_regions' ), 'JS must contain geo_v2 region enrichment loop.' );
yd_geo_v2_region_enrichment_assert( ! str_contains( $mapper_source, 'YandexGeoV2RegionEnrichmentService' ) && ! str_contains( $mapper_source, 'update_region_from_location' ), 'Main mapper v2 pipeline must not call enrichment directly.' );

echo "Yandex Delivery geo v2 region enrichment smoke OK\n";
