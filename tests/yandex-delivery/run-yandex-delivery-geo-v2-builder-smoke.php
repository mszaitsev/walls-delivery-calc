<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2BuilderRunnerService;
use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2BuilderService;
use WallsShop\WDC\Carriers\YandexDelivery\GeoV2\YandexDeliveryGeoV2Repository;

function yd_geo_v2_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function current_time( string $type ): string { return '2026-06-26 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['yd_geo_v2_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['yd_geo_v2_options'][ $key ] = $value; return true; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $yandex_delivery_pickup_points_v2 = array();
		public array $yandex_delivery_geo_v2 = array();
		public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) { $query = preg_replace( '/%[sdf]/', is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query; } return $query; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

$GLOBALS['yd_geo_v2_options'] = array();
$GLOBALS['wpdb'] = new wpdb();

$repository = new YandexDeliveryGeoV2Repository( $GLOBALS['wpdb'] );
$schema = $repository->schema();
foreach ( array( 'yandex_geo_id bigint(20) unsigned NOT NULL', 'points_count int(10) unsigned NOT NULL DEFAULT 0', 'dropoff_count int(10) unsigned NOT NULL DEFAULT 0', 'types_json longtext NULL', 'operators_json longtext NULL', 'centroid_lat decimal(10,7) NULL', 'sample_points_json longtext NULL', 'raw_stats_json longtext NULL', 'UNIQUE KEY yandex_geo_id (yandex_geo_id)', 'KEY region_locality (region(80), locality(120))' ) as $needle ) {
	yd_geo_v2_assert( str_contains( $schema, $needle ), 'Geo v2 schema must contain: ' . $needle );
}
$upsert = $repository->upsert( array( 'yandex_geo_id' => 999, 'region' => 'Тест', 'locality' => 'Город', 'points_count' => 1, 'dropoff_count' => 1, 'types_json' => '{}', 'operators_json' => '{}', 'sample_points_json' => '[]', 'raw_stats_json' => '{}', 'active' => 1 ) );
yd_geo_v2_assert( 1 === $upsert['saved'] && 999 === (int) ( $repository->find_by_geo_id( 999 )['yandex_geo_id'] ?? 0 ), 'Geo v2 repository must upsert and find by geo id.' );
$repository->truncate();

$pickup = static function ( int $geo_id, string $id, int $dropoff, float $lat, float $lon, string $type = 'pickup_point', string $operator = 'market_l4g' ): array {
	return array(
		'platform_station_id' => $id,
		'yandex_geo_id' => $geo_id,
		'region' => 'Новосибирская область',
		'sub_region' => 'Новосибирский район',
		'locality' => 100 === $geo_id ? 'Новосибирск' : 'Бердск',
		'type' => $type,
		'operator_id' => $operator,
		'available_for_dropoff' => $dropoff,
		'latitude' => $lat,
		'longitude' => $lon,
		'full_address' => $id . ' full address',
		'active' => 1,
	);
};
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2[] = $pickup( 100, 'g100-a', 1, 55.0, 82.0, 'pickup_point', 'op-a' );
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2[] = $pickup( 100, 'g100-b', 1, 56.0, 83.0, 'terminal', 'op-b' );
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2[] = $pickup( 100, 'g100-c', 0, 57.0, 84.0, 'pickup_point', 'op-a' );
for ( $i = 1; $i <= 31; ++$i ) {
	$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2[] = $pickup( 200, 'g200-' . $i, $i % 2, 50.0 + $i / 100, 80.0 + $i / 100, 'pickup_point', 'op-c' );
}
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2[] = $pickup( 0, 'g0', 1, 1.0, 1.0 );
$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2[] = array_merge( $pickup( 300, 'inactive', 1, 1.0, 1.0 ), array( 'active' => 0 ) );

$builder = new YandexDeliveryGeoV2BuilderService( $repository, $GLOBALS['wpdb'] );
$step1 = $builder->build_all( 1, 0 );
yd_geo_v2_assert( 1 === $step1['processed_geo_ids'] && 1 === $step1['saved'] && 1 === $step1['next_offset'] && false === $step1['done'], 'Builder offset must apply to unique geo ids.' );
$step2 = $builder->build_all( 10, 1 );
yd_geo_v2_assert( 1 === $step2['processed_geo_ids'] && true === $step2['done'], 'Builder second step must finish remaining geo ids.' );

$geo100 = $repository->find_by_geo_id( 100 );
yd_geo_v2_assert( null !== $geo100, 'Geo 100 aggregate must exist.' );
yd_geo_v2_assert( 3 === (int) $geo100['points_count'] && 2 === (int) $geo100['dropoff_count'], 'Geo 100 counts must match fixture.' );
yd_geo_v2_assert( 'Новосибирская область' === (string) $geo100['region'] && 'Новосибирск' === (string) $geo100['locality'], 'Geo 100 region/locality must use common values.' );
yd_geo_v2_assert( abs( 56.0 - (float) $geo100['centroid_lat'] ) < 0.00001 && abs( 83.0 - (float) $geo100['centroid_lon'] ) < 0.00001, 'Geo 100 centroid must average valid coordinates.' );
yd_geo_v2_assert( 55.0 === (float) $geo100['min_lat'] && 57.0 === (float) $geo100['max_lat'] && 82.0 === (float) $geo100['min_lon'] && 84.0 === (float) $geo100['max_lon'], 'Geo 100 min/max coordinates must match fixture.' );
$types100 = json_decode( (string) $geo100['types_json'], true );
$operators100 = json_decode( (string) $geo100['operators_json'], true );
$sample100 = json_decode( (string) $geo100['sample_points_json'], true );
$raw100 = json_decode( (string) $geo100['raw_stats_json'], true );
yd_geo_v2_assert( 2 === (int) $types100['pickup_point'] && 1 === (int) $types100['terminal'], 'types_json must count point types.' );
yd_geo_v2_assert( 2 === (int) $operators100['op-a'] && 1 === (int) $operators100['op-b'], 'operators_json must count operator ids.' );
yd_geo_v2_assert( 3 === count( $sample100 ) && 3 === (int) $raw100['valid_coordinate_points'] && 0 === (int) $raw100['invalid_coordinate_points'], 'Sample and raw stats must match geo 100.' );

$geo200 = $repository->find_by_geo_id( 200 );
$sample200 = json_decode( (string) ( $geo200['sample_points_json'] ?? '[]' ), true );
yd_geo_v2_assert( 31 === (int) ( $geo200['points_count'] ?? 0 ) && 3 === count( $sample200 ), 'Geo 200 must sample first, every 30th, and last.' );
yd_geo_v2_assert( 'g200-1' === $sample200[0]['platform_station_id'] && 'g200-30' === $sample200[1]['platform_station_id'] && 'g200-31' === $sample200[2]['platform_station_id'], 'Geo 200 sample ids must be first/every 30th/last.' );
yd_geo_v2_assert( null === $repository->find_by_geo_id( 0 ) && null === $repository->find_by_geo_id( 300 ), 'Geo 0/null and inactive pickups must not be aggregated.' );
$stats = $repository->statistics();
yd_geo_v2_assert( 2 === $stats['total'] && 2 === $stats['active'] && 34 === $stats['points_total'] && $stats['dropoff_total'] > 0, 'Geo v2 statistics must aggregate totals.' );

$runner_repository = new YandexDeliveryGeoV2Repository( $GLOBALS['wpdb'] );
$runner = new YandexDeliveryGeoV2BuilderRunnerService( new YandexDeliveryGeoV2BuilderService( $runner_repository, $GLOBALS['wpdb'] ) );
$state = $runner->start();
yd_geo_v2_assert( 'building' === $state['status'], 'Geo v2 runner start must switch to building.' );
$state = $runner->run_step();
yd_geo_v2_assert( $state['offset'] > 0 && $state['processed_geo_ids'] > 0 && $state['saved'] > 0 && in_array( $state['status'], array( 'building', 'done' ), true ), 'Geo v2 runner step must update progress.' );
$runner->start();
$paused = $runner->pause();
$after_pause = $runner->run_step();
yd_geo_v2_assert( 'paused' === $paused['status'] && $after_pause['offset'] === $paused['offset'], 'Geo v2 pause must not build further.' );
$reset = $runner->reset();
yd_geo_v2_assert( 'idle' === $reset['status'] && 0 === $reset['offset'], 'Geo v2 reset must clear state.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/yandex-delivery-pickup-v2-runner.js' );
$constructor_start = strpos( $admin_source, 'public function __construct' );
$constructor_end = false === $constructor_start ? false : strpos( $admin_source, ') {', $constructor_start );
$constructor_block = false !== $constructor_start && false !== $constructor_end ? substr( $admin_source, $constructor_start, $constructor_end - $constructor_start ) : '';
$plugin_start = strpos( $plugin_source, 'new DeliveryServicesAdminPage' );
$plugin_end = false === $plugin_start ? false : strpos( $plugin_source, '$this->container->register( OrderQuoteRequestMapper::class', $plugin_start );
$plugin_block = false !== $plugin_start && false !== $plugin_end ? substr( $plugin_source, $plugin_start, $plugin_end - $plugin_start ) : '';
$constructor_order = array(
	strpos( $constructor_block, 'YandexDeliveryPickupPointV2Repository $yandex_delivery_pickup_v2_repository' ),
	strpos( $constructor_block, 'YandexDeliveryPickupPointV2RunnerService $yandex_delivery_pickup_v2_runner' ),
	strpos( $constructor_block, 'YandexDeliveryGeoV2Repository $yandex_delivery_geo_v2_repository' ),
	strpos( $constructor_block, 'YandexDeliveryGeoV2BuilderRunnerService $yandex_delivery_geo_v2_builder_runner' ),
	strpos( $constructor_block, 'YandexDeliveryGeoMappingRepository $yandex_delivery_geo_mappings' ),
);
$plugin_order = array(
	strpos( $plugin_block, 'YandexDeliveryPickupPointV2Repository::class' ),
	strpos( $plugin_block, 'YandexDeliveryPickupPointV2RunnerService::class' ),
	strpos( $plugin_block, 'YandexDeliveryGeoV2Repository::class' ),
	strpos( $plugin_block, 'YandexDeliveryGeoV2BuilderRunnerService::class' ),
	strpos( $plugin_block, 'YandexDeliveryGeoMappingRepository::class' ),
);
yd_geo_v2_assert( ! in_array( false, $constructor_order, true ) && $constructor_order === array_values( array_filter( $constructor_order, 'is_int' ) ) && $constructor_order === array_values( $constructor_order ) && $constructor_order[0] < $constructor_order[1] && $constructor_order[1] < $constructor_order[2] && $constructor_order[2] < $constructor_order[3] && $constructor_order[3] < $constructor_order[4], 'Admin constructor must keep pickup v2, geo v2, then old mapping dependency order.' );
yd_geo_v2_assert( ! in_array( false, $plugin_order, true ) && $plugin_order[0] < $plugin_order[1] && $plugin_order[1] < $plugin_order[2] && $plugin_order[2] < $plugin_order[3] && $plugin_order[3] < $plugin_order[4], 'Plugin DI arguments must match constructor order and keep old mapping after geo v2 args.' );
yd_geo_v2_assert( str_contains( $admin_source, 'Агрегация geoId v2' ) && str_contains( $admin_source, 'Построить geoId v2' ), 'Admin v2 tab must contain geo v2 builder UI.' );
yd_geo_v2_assert( str_contains( $admin_source, 'wdc_yandex_delivery_geo_v2_builder_start' ) && str_contains( $admin_source, 'wdc_yandex_delivery_geo_v2_builder_step' ), 'Geo v2 AJAX actions must be registered.' );
yd_geo_v2_assert( str_contains( $admin_source, 'wp_send_json_success' ) && str_contains( $admin_source, 'wp_send_json_error' ) && str_contains( $admin_source, 'register_yandex_pickup_v2_ajax_shutdown_guard' ), 'Geo v2 AJAX handlers must use JSON-safe wrapper.' );
yd_geo_v2_assert( str_contains( $js_source, 'data-wdc-yandex-geo-v2-builder' ) && str_contains( $js_source, 'wdc_yandex_delivery_geo_v2_builder_step' ) && str_contains( $js_source, "runningStatus: 'building'" ), 'JS must contain the second geo v2 builder loop.' );
yd_geo_v2_assert( ! str_contains( $admin_source, 'Текущий импорт пока недоступен' ), 'Old v2 placeholder must not return.' );

echo "Yandex Delivery geo v2 builder smoke OK\n";
