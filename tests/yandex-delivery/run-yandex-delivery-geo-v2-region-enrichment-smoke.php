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
yd_geo_v2_region_enrichment_assert( 'updated' === $result200['status'] && 'Смоленская' === $result200['region'] && 'locality_search_single_region' === $result200['reason'] && 2 === $result200['candidate_count'], 'Multiple close candidates in the same region must update with locality_search_single_region reason.' );
$result300 = $service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[2] );
yd_geo_v2_region_enrichment_assert( 'needs_review' === $result300['status'] && 'locality_search_multiple_regions' === $result300['reason'] && '' === (string) $geo_repository->find_by_geo_id( 300 )['region'], 'Similar candidates from multiple regions must stay needs_review.' );
$raw300 = json_decode( (string) ( $geo_repository->find_by_geo_id( 300 )['raw_stats_json'] ?? '' ), true );
yd_geo_v2_region_enrichment_assert( 'needs_review' === (string) ( $raw300['region_enrichment']['status'] ?? '' ), 'needs_review enrichment attempts must be audited.' );
$result400 = $service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[3] );
yd_geo_v2_region_enrichment_assert( 'updated' === $result400['status'] && 'Ставропольский' === $result400['region'] && 'locality_search_dominant_candidate' === $result400['reason'], 'Dominant nearby candidate must update region.' );
$result500 = $service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[4] );
yd_geo_v2_region_enrichment_assert( 'skipped' === $result500['status'] && 'invalid_coords' === $result500['reason'], 'Invalid geo coordinates must be skipped.' );
$raw500 = json_decode( (string) ( $geo_repository->find_by_geo_id( 500 )['raw_stats_json'] ?? '' ), true );
yd_geo_v2_region_enrichment_assert( 'skipped' === (string) ( $raw500['region_enrichment']['status'] ?? '' ), 'skipped enrichment attempts must be audited.' );

yd_geo_v2_region_enrichment_assert( 2 === $geo_repository->count_empty_active_regions(), 'Repository must count remaining active empty regions.' );
yd_geo_v2_region_enrichment_assert( 0 === $geo_repository->count_pending_empty_region_rows_for_enrichment(), 'Audited empty-region rows must not remain pending.' );
$batch = $service->enrich_batch( 0, 10 );
yd_geo_v2_region_enrichment_assert( 0 === $batch['processed'] && true === $batch['done'], 'Batch enrichment must skip already audited empty-region rows.' );


$GLOBALS['wpdb']->yandex_delivery_geo_v2 = array(
	$geo( 810, '', 'Брянск г', 53.30, 34.36, 3.0, 10 ),
	array_merge( $geo( 820, '', 'Качалино ст', 48.59, 44.06, 3.0, 10 ), array( 'first_full_address' => 'Качалино ст' ) ),
	array_merge( $geo( 830, '', 'Шаховская пгт', 56.02, 35.53, 3.0, 10 ), array( 'first_full_address' => 'Шаховская пгт Промзона Коптязино тер 1' ) ),
	$geo( 840, '', 'Несуществующий г', 55.0, 37.0, 3.0, 10 ),
);
$GLOBALS['wpdb']->wdc_locations = array(
	$location( 810, 'Брянская область', 'Брянск', '', 53.36, 34.36, '', 'г' ),
	$location( 820, 'Волгоградская область', '', '', 48.66, 44.06, 'железнодорожная станция Качалино', '', '', 'ст' ),
	$location( 830, 'Московская область', '', '', 56.02, 35.53, 'Коптязино', '', '', 'д' ),
	$location( 840, 'Московская область', '', '', 55.0, 37.0, 'Совсем другое', '', '', 'д' ),
	$location( 841, 'Тульская область', '', '', 55.01, 37.0, 'Тоже другое', '', '', 'д' ),
);
foreach ( array( 'Брянская область', 'Волгоградская область', 'Московская область', 'Тульская область' ) as $region ) {
	$region_repository->save_mapping( $region, array( $region ) );
}
$coordinate_service = new YandexGeoV2RegionEnrichmentService( $geo_repository, $GLOBALS['wpdb'], new YandexLocationMappingV2NameNormalizer(), $region_repository );
$coordinate_city = $coordinate_service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[0] );
$row810 = $geo_repository->find_by_geo_id( 810 );
$raw810 = json_decode( (string) ( $row810['raw_stats_json'] ?? '' ), true );
yd_geo_v2_region_enrichment_assert( 'updated' === $coordinate_city['status'] && 'Брянская область' === $coordinate_city['region'] && 'coordinate_fallback' === (string) ( $raw810['region_enrichment']['audit']['search_path'] ?? '' ), 'Coordinate fallback must update nearby city when locality search rejects by distance.' );
$coordinate_station = $coordinate_service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[1] );
$row820 = $geo_repository->find_by_geo_id( 820 );
$raw820 = json_decode( (string) ( $row820['raw_stats_json'] ?? '' ), true );
yd_geo_v2_region_enrichment_assert( 'updated' === $coordinate_station['status'] && 'coordinate_fallback' === (string) ( $raw820['region_enrichment']['audit']['search_path'] ?? '' ) && ( in_array( 'partial_locality', $raw820['region_enrichment']['audit']['matched_by'] ?? array(), true ) || in_array( 'type', $raw820['region_enrichment']['audit']['matched_by'] ?? array(), true ) || in_array( 'locality', $raw820['region_enrichment']['audit']['matched_by'] ?? array(), true ) ), 'Coordinate fallback must handle station aliases with partial locality.' );
$coordinate_address = $coordinate_service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[2] );
$row830 = $geo_repository->find_by_geo_id( 830 );
$raw830 = json_decode( (string) ( $row830['raw_stats_json'] ?? '' ), true );
yd_geo_v2_region_enrichment_assert( 'updated' === $coordinate_address['status'] && in_array( 'address_clue', $raw830['region_enrichment']['audit']['matched_by'] ?? array(), true ), 'Coordinate fallback must accept nearby address clue candidate.' );
$low_score = $coordinate_service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[3] );
$row840 = $geo_repository->find_by_geo_id( 840 );
$raw840 = json_decode( (string) ( $row840['raw_stats_json'] ?? '' ), true );
yd_geo_v2_region_enrichment_assert( 'not_found' === $low_score['status'] && 'coordinate_fallback_low_score' === $low_score['reason'] && ! isset( $raw840['region_enrichment']['audit']['diagnostics'] ), 'Low-score coordinate fallback must persist compact audit without diagnostics.' );
$GLOBALS['wpdb']->yandex_delivery_geo_v2 = array(
	$geo( 845, '', 'Качалино ст', 48.59, 44.06, 3.0, 10 ),
);
$GLOBALS['wpdb']->wdc_locations = array(
	$location( 845, 'Волгоградская область', '', '', 48.60, 44.06, 'Совсем другое 1', '', '', 'д' ),
	$location( 846, 'Волгоградская область', '', '', 48.61, 44.07, 'Совсем другое 2', '', '', 'д' ),
);
$region_only = $coordinate_service->enrich_one( $GLOBALS['wpdb']->yandex_delivery_geo_v2[0] );
$row845 = $geo_repository->find_by_geo_id( 845 );
$raw845 = json_decode( (string) ( $row845['raw_stats_json'] ?? '' ), true );
yd_geo_v2_region_enrichment_assert( 'updated' === $region_only['status'] && 'Волгоградская область' === $region_only['region'] && 'coordinate_fallback_single_nearby_region' === $region_only['reason'] && 'coordinate_fallback_region_only' === (string) ( $raw845['region_enrichment']['audit']['search_path'] ?? '' ) && in_array( 'single_nearby_region', $raw845['region_enrichment']['audit']['matched_by'] ?? array(), true ), 'Coordinate fallback region-only must update when nearby rows share one WDC region.' );
yd_geo_v2_region_enrichment_assert( ! empty( $raw845['region_enrichment']['audit']['nearby_region_counts']['Волгоградская область'] ) && isset( $raw845['region_enrichment']['audit']['nearby_region_min_distances']['Волгоградская область'] ), 'Region-only audit must contain nearby region counts and min distances.' );
$GLOBALS['wpdb']->yandex_delivery_geo_v2 = array(
	array_merge( $geo( 850, '', 'Повтор г', 55.0, 37.0, 3.0, 10 ), array( 'raw_stats_json' => '{"keep":true,"region_enrichment":{"status":"not_found"}}' ) ),
);
yd_geo_v2_region_enrichment_assert( 0 === $geo_repository->count_pending_empty_region_rows_for_enrichment(), 'Existing enrichment audit must keep empty row out of pending before reset.' );
$reset_attempts = $geo_repository->reset_region_enrichment_attempts_for_empty_regions();
$row850 = $geo_repository->find_by_geo_id( 850 );
$raw850 = json_decode( (string) ( $row850['raw_stats_json'] ?? '' ), true );
yd_geo_v2_region_enrichment_assert( 1 === $reset_attempts && ! array_key_exists( 'region_enrichment', $raw850 ) && 1 === $geo_repository->count_pending_empty_region_rows_for_enrichment(), 'Reset must remove enrichment audit from empty regions and make them pending again.' );

$GLOBALS['yd_geo_v2_region_enrichment_options'] = array();
$GLOBALS['wpdb']->wdc_locations = array(
	$location( 100, 'Брянская', 'Брянск', '', 53.2434, 34.3642, '', 'г' ),
	$location( 300, 'Санкт-Петербург', '', 'Павловск', 59.6850, 30.4300 ),
	$location( 301, 'Воронежская', '', 'Павловск', 59.6900, 30.4300 ),
);
$GLOBALS['wpdb']->yandex_delivery_geo_v2 = array(
	$geo( 710, '', 'Неткандидата г', 10.0, 10.0, 3.0, 40 ),
	$geo( 720, '', 'Павловск', 59.6850, 30.4300, 6.0, 30 ),
	$geo( 730, '', 'Алгасово с', 0.0, 0.0, 3.0, 20 ),
	$geo( 740, '', 'Брянск г', 53.2434, 34.3642, 3.0, 10 ),
);
$runner_repository = new YandexDeliveryGeoV2Repository( $GLOBALS['wpdb'] );
$runner = new YandexGeoV2RegionEnrichmentRunner( new YandexGeoV2RegionEnrichmentService( $runner_repository, $GLOBALS['wpdb'], null, $region_repository ), $runner_repository );
$state = $runner->start();
yd_geo_v2_region_enrichment_assert( 'enriching_regions' === $state['status'] && 4 === $state['empty_regions_remaining'] && 10 === $state['batch_size'], 'Runner start must switch to enriching_regions with batch size 10.' );
$state = $runner->run_step();
yd_geo_v2_region_enrichment_assert( 'done' === $state['status'] && 4 === $state['processed'] && 1 === $state['updated'] && 1 === $state['needs_review'] && 1 === $state['not_found'] && 1 === $state['skipped'] && 0 === $state['pending_empty_regions_remaining'], 'Runner step must process each pending empty region once.' );
yd_geo_v2_region_enrichment_assert( 0 === $runner_repository->count_pending_empty_region_rows_for_enrichment(), 'Runner must leave no already-attempted empty rows pending.' );
$after_done = $runner->run_step();
yd_geo_v2_region_enrichment_assert( 4 === $after_done['processed'] && 1 === $after_done['not_found'] && 1 === $after_done['needs_review'] && 1 === $after_done['skipped'], 'Second runner step must not repeat not_found, needs_review, or skipped rows.' );
$runner->start();
$paused = $runner->pause();
$after_pause = $runner->run_step();
yd_geo_v2_region_enrichment_assert( 'paused' === $paused['status'] && $after_pause['processed'] === $paused['processed'], 'Runner pause must stop enrichment loop.' );
$reset = $runner->reset();
yd_geo_v2_region_enrichment_assert( 'idle' === $reset['status'] && 0 === $reset['processed'], 'Runner reset must clear state after clearing empty-region enrichment audits.' );

$geo_repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/GeoV2/YandexDeliveryGeoV2Repository.php' );
$service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexGeoV2RegionEnrichmentService.php' );
$runner_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexGeoV2RegionEnrichmentRunner.php' );
$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/yandex-delivery-pickup-v2-runner.js' );
$mapper_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMapperV2Service.php' );
yd_geo_v2_region_enrichment_assert( str_contains( $service_source, 'YandexGeoV2RegionEnrichmentService' ) && str_contains( $service_source, 'find_pending_empty_region_rows_for_enrichment' ) && str_contains( $service_source, 'coordinate_fallback' ) && str_contains( $service_source, 'coordinate_fallback_region_only' ) && str_contains( $service_source, 'nearby_region_counts' ) && str_contains( $service_source, 'diagnostics' ) && str_contains( $runner_source, 'YandexGeoV2RegionEnrichmentRunner' ) && str_contains( $runner_source, 'private const BATCH_SIZE = 10' ) && str_contains( $runner_source, 'count_pending_empty_region_rows_for_enrichment' ), 'Enrichment service and runner must use pending rows with batch size 10.' );
yd_geo_v2_region_enrichment_assert( str_contains( $geo_repository_source, 'update_region_from_location' ) && str_contains( $geo_repository_source, 'mark_region_enrichment_attempt' ) && str_contains( $geo_repository_source, 'find_pending_empty_region_rows_for_enrichment' ) && str_contains( $geo_repository_source, 'count_pending_empty_region_rows_for_enrichment' ) && str_contains( $geo_repository_source, 'reset_region_enrichment_attempts_for_empty_regions' ), 'Geo v2 repository must expose region enrichment update, attempt, and pending methods.' );
yd_geo_v2_region_enrichment_assert( str_contains( $admin_source, 'Обогащение пустых регионов geo_v2' ) && str_contains( $admin_source, 'Осталось необработанных пустых регионов' ) && str_contains( $admin_source, 'Запустить обогащение регионов' ) && str_contains( $admin_source, 'wdc_yandex_geo_v2_region_enrichment_start' ), 'Admin UI must contain geo_v2 region enrichment block, pending count, and AJAX actions.' );
yd_geo_v2_region_enrichment_assert( str_contains( $js_source, 'data-wdc-yandex-geo-v2-region-enrichment' ) && str_contains( $js_source, 'enriching_regions' ), 'JS must contain geo_v2 region enrichment loop.' );
yd_geo_v2_region_enrichment_assert( ! str_contains( $mapper_source, 'YandexGeoV2RegionEnrichmentService' ) && ! str_contains( $mapper_source, 'update_region_from_location' ), 'Main mapper v2 pipeline must not call enrichment directly.' );

echo "Yandex Delivery geo v2 region enrichment smoke OK\n";
