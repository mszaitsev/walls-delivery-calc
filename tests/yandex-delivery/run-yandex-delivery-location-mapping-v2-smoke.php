<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMapperV2Service;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Runner;

function yd_location_mapping_v2_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function current_time( string $type ): string { return '2026-06-26 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['yd_location_mapping_v2_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['yd_location_mapping_v2_options'][ $key ] = $value; return true; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $yandex_delivery_geo_v2 = array();
		public array $yandex_location_mapping_v2 = array();
		public array $wdc_locations = array();
		public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) { $query = preg_replace( '/%[sdf]/', is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query; } return $query; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

$GLOBALS['yd_location_mapping_v2_options'] = array();
$GLOBALS['wpdb'] = new wpdb();

$repository = new YandexLocationMappingV2Repository( $GLOBALS['wpdb'] );
$schema = $repository->schema();
foreach ( array( 'yandex_geo_id bigint(20) unsigned NOT NULL', 'location_id bigint(20) unsigned NOT NULL', 'status varchar(32) NOT NULL', 'confidence decimal(5,2) NULL', 'distance_km decimal(10,3) NULL', 'region_match tinyint(1)', 'locality_match tinyint(1)', 'coordinate_match tinyint(1)', 'matched_by_json longtext NULL', 'raw_json longtext NULL', 'is_primary tinyint(1)', 'UNIQUE KEY geo_location (yandex_geo_id, location_id)' ) as $needle ) {
	yd_location_mapping_v2_assert( str_contains( $schema, $needle ), 'Mapping v2 schema must contain: ' . $needle );
}

$upsert = $repository->upsert( array( 'yandex_geo_id' => 1, 'location_id' => 10, 'status' => 'manual', 'confidence' => 99.999, 'distance_km' => 1.23456, 'region_match' => 1, 'locality_match' => 1, 'coordinate_match' => 1, 'matched_by_json' => '["manual"]', 'raw_json' => '{}', 'is_primary' => 1 ) );
yd_location_mapping_v2_assert( 1 === $upsert['saved'], 'Repository must save mapping row.' );
$found_by_geo = $repository->find_by_geo( 1 );
yd_location_mapping_v2_assert( 1 === count( $found_by_geo ) && 10 === (int) $found_by_geo[0]['location_id'] && 100.0 === (float) $found_by_geo[0]['confidence'] && 1.235 === (float) $found_by_geo[0]['distance_km'], 'Repository must normalize and find by geo.' );
yd_location_mapping_v2_assert( 1 === count( $repository->find_by_location( 10 ) ), 'Repository must find by location.' );
$repository->truncate();

$geo = static function ( int $geo_id, string $region, string $locality, float $lat, float $lon, float $radius = 5.0 ): array {
	return array( 'yandex_geo_id' => $geo_id, 'region' => $region, 'locality' => $locality, 'centroid_lat' => $lat, 'centroid_lon' => $lon, 'coverage_radius_safe_km' => $radius, 'active' => 1 );
};
$location = static function ( int $id, string $region, string $city, string $settlement, float $lat, float $lon ): array {
	return array( 'id' => $id, 'region_name' => $region, 'city_name' => $city, 'settlement_name' => $settlement, 'display_name' => trim( $city . ' ' . $settlement ), 'latitude' => $lat, 'longitude' => $lon, 'active' => 1 );
};
$GLOBALS['wpdb']->yandex_delivery_geo_v2 = array(
	$geo( 100, 'Новосибирская область', 'Новосибирск', 55.0302, 82.9204 ),
	$geo( 200, 'Новосибирская область', 'Бердск', 54.7582, 83.1072 ),
	$geo( 300, '', 'Москва г', 55.7558, 37.6173 ),
	$geo( 400, 'Тестовая область', 'Нетгорода', 50.0, 50.0 ),
	$geo( 500, 'Новосибирская область', 'Далёкий', 55.0, 82.0 ),
	$geo( 600, 'Москва и Московская область', 'Москва', 55.7558, 37.6173 ),
	$geo( 700, 'Новосибирская область', 'Краснообск', 54.0000, 82.0000 ),
	$geo( 800, 'Новосибирская область', 'Сортировка', 55.1000, 82.1000 ),
);
$GLOBALS['wpdb']->wdc_locations = array(
	$location( 10, 'Новосибирская область', 'Новосибирск', '', 55.0302, 82.9204 ),
	$location( 20, 'Новосибирская область', 'Бердск', '', 54.7582, 83.1072 ),
	$location( 21, 'Новосибирская область', '', 'Бердск', 54.7600, 83.1100 ),
	$location( 30, 'Москва', 'г Москва', '', 55.7558, 37.6173 ),
	$location( 50, 'Новосибирская область', 'Далёкий', '', 60.0, 90.0 ),
	$location( 70, 'Новосибирская область', 'Новосибирск', 'Краснообск', 54.0000, 82.0000 ),
	$location( 80, 'Другой регион', 'Сортировка', '', 55.1000, 82.1000 ),
	$location( 81, 'Новосибирская область', 'Сортировка', '', 55.1000, 82.1000 ),
);

$mapper = new YandexLocationMapperV2Service( $repository, $GLOBALS['wpdb'] );
$result = $mapper->build_all( 10, 0 );
yd_location_mapping_v2_assert( 8 === $result['processed_geo_ids'] && 4 === $result['mapped'] && 2 === $result['needs_review'] && 2 === $result['no_match'] && true === $result['done'], 'Mapper batch must classify mapped, needs_review, and no_match geo ids.' );
$geo100 = $repository->find_by_geo( 100 );
yd_location_mapping_v2_assert( 1 === count( $geo100 ) && 'mapped' === $geo100[0]['status'] && 100.0 === (float) $geo100[0]['confidence'] && 1 === (int) $geo100[0]['is_primary'], 'Single candidate must be mapped with full confidence.' );
$matched_by100 = json_decode( (string) $geo100[0]['matched_by_json'], true );
$raw100 = json_decode( (string) $geo100[0]['raw_json'], true );
yd_location_mapping_v2_assert( in_array( 'locality', $matched_by100, true ) && in_array( 'region', $matched_by100, true ) && in_array( 'coordinates', $matched_by100, true ), 'matched_by_json must include locality, region, and coordinates.' );
yd_location_mapping_v2_assert( array_key_exists( 'distance', $raw100 ) && array_key_exists( 'radius', $raw100 ) && array_key_exists( 'threshold', $raw100 ) && true === $raw100['region_matched'] && 'city_name' === $raw100['locality_source'] && 1 === (int) $raw100['candidate_count'], 'raw_json must contain distance, radius, threshold, region_matched, locality_source, and candidate_count.' );
$geo200 = $repository->find_by_geo( 200 );
yd_location_mapping_v2_assert( 2 === count( $geo200 ) && 'needs_review' === $geo200[0]['status'] && 1 === (int) $geo200[0]['is_primary'], 'Multiple candidates must be saved as needs_review variants.' );
$geo300 = $repository->find_by_geo( 300 );
yd_location_mapping_v2_assert( 1 === count( $geo300 ) && 'mapped' === $geo300[0]['status'] && 80.0 === (float) $geo300[0]['confidence'], 'Regionless geo must match by normalized locality and coordinates.' );
$geo400 = $repository->find_by_geo( 400 );
$geo500 = $repository->find_by_geo( 500 );
yd_location_mapping_v2_assert( 'no_match' === $geo400[0]['status'] && 0 === (int) $geo400[0]['location_id'] && 'no_match' === $geo500[0]['status'], 'Missing and far candidates must become no_match.' );
$geo600 = $repository->find_by_geo( 600 );
$raw600 = json_decode( (string) $geo600[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo600 ) && 'mapped' === $geo600[0]['status'] && 80.0 === (float) $geo600[0]['confidence'] && false === $raw600['region_matched'], 'Region mismatch must not block locality and coordinate match.' );
$geo700 = $repository->find_by_geo( 700 );
$raw700 = json_decode( (string) $geo700[0]['raw_json'], true );
yd_location_mapping_v2_assert( 1 === count( $geo700 ) && 'mapped' === $geo700[0]['status'] && 'settlement_name' === $raw700['locality_source'], 'Mapper must match locality through settlement_name.' );
$geo800 = $repository->find_by_geo( 800 );
$primary800 = array_values( array_filter( $geo800, static fn( array $row ): bool => 1 === (int) $row['is_primary'] ) );
yd_location_mapping_v2_assert( 2 === count( $geo800 ) && 1 === count( $primary800 ) && 81 === (int) $primary800[0]['location_id'] && 100.0 === (float) $primary800[0]['confidence'], 'Candidate sorting must prefer higher confidence before distance.' );
$stats = $repository->statistics();
yd_location_mapping_v2_assert( 10 === $stats['total'] && 4 === $stats['mapped'] && 4 === $stats['needs_review'] && 2 === $stats['no_match'] && null !== $stats['avg_confidence'] && null !== $stats['avg_distance'], 'Repository statistics must count statuses and averages.' );

$runner_repository = new YandexLocationMappingV2Repository( $GLOBALS['wpdb'] );
$runner = new YandexLocationMappingV2Runner( new YandexLocationMapperV2Service( $runner_repository, $GLOBALS['wpdb'] ), $runner_repository );
$state = $runner->start();
yd_location_mapping_v2_assert( 'mapping' === $state['status'] && 0 === count( $GLOBALS['wpdb']->yandex_location_mapping_v2 ), 'Runner start must truncate and switch to mapping.' );
$state = $runner->run_step();
yd_location_mapping_v2_assert( 'done' === $state['status'] && 8 === $state['processed'] && 4 === $state['mapped'] && 2 === $state['needs_review'] && 2 === $state['no_match'] && null !== $state['avg_confidence'] && null !== $state['avg_distance'], 'Runner step must update mapping counters and averages.' );
$runner->start();
$paused = $runner->pause();
$after_pause = $runner->run_step();
yd_location_mapping_v2_assert( 'paused' === $paused['status'] && $after_pause['offset'] === $paused['offset'], 'Runner pause must stop mapping loop.' );
$reset = $runner->reset();
yd_location_mapping_v2_assert( 'idle' === $reset['status'] && 0 === $reset['processed'], 'Runner reset must clear state.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/yandex-delivery-pickup-v2-runner.js' );
$mapper_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMapperV2Service.php' );
yd_location_mapping_v2_assert( str_contains( $admin_source, 'Маппинг geoId → населённые пункты' ) && str_contains( $admin_source, 'Построить сопоставление' ), 'Admin v2 tab must contain location mapping v2 UI.' );
yd_location_mapping_v2_assert( str_contains( $admin_source, 'wdc_yandex_location_mapping_v2_start' ) && str_contains( $admin_source, 'wdc_yandex_location_mapping_v2_step' ), 'Admin must register location mapping v2 AJAX actions.' );
yd_location_mapping_v2_assert( str_contains( $js_source, 'data-wdc-yandex-location-mapping-v2' ) && str_contains( $js_source, 'wdc_yandex_location_mapping_v2_step' ) && str_contains( $js_source, "runningStatus: 'mapping'" ), 'JS must contain independent location mapping v2 loop.' );
yd_location_mapping_v2_assert( str_contains( $plugin_source, 'YandexLocationMappingV2Repository::class' ) && str_contains( $plugin_source, 'YandexLocationMapperV2Service::class' ) && str_contains( $plugin_source, 'YandexLocationMappingV2Runner::class' ), 'Plugin DI must register location mapping v2 services.' );
yd_location_mapping_v2_assert( ! str_contains( $mapper_source, 'locationDetect' ) && ! str_contains( $mapper_source, 'YandexDeliveryApiClient' ) && ! str_contains( $mapper_source, '/api/' ), 'Location mapping v2 mapper must stay offline and not call Yandex API.' );
yd_location_mapping_v2_assert( ! str_contains( $mapper_source, 'region_name LIKE' ) && str_contains( $mapper_source, 'location_locality_variants' ) && str_contains( $mapper_source, 'locality_source' ), 'Mapper source must keep region out of SQL, compare all locality variants, and store locality_source.' );
yd_location_mapping_v2_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0038_create_yandex_location_mapping_v2.php' ), 'YandexLocationMappingV2Repository' ), 'Migration 0038 must create mapping v2 schema via repository.' );

echo "Yandex Delivery location mapping v2 smoke OK\n";
