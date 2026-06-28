<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexRegionMappingV2Repository;

function yd_region_mapping_v2_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function current_time( string $type ): string { return '2026-06-27 12:00:00'; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $yandex_region_mapping_v2 = array();
		public array $yandex_delivery_pickup_points_v2 = array();
		public array $yandex_delivery_geo_v2 = array();
		public array $wdc_locations = array();
		public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) { $query = preg_replace( '/%[sdf]/', is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query; } return $query; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

$GLOBALS['wpdb'] = new wpdb();
$repository = new YandexRegionMappingV2Repository( $GLOBALS['wpdb'] );
$schema = $repository->schema();
foreach ( array( 'yandex_region varchar(255) NOT NULL', 'wdc_region_name varchar(255) NOT NULL', 'needs_review tinyint(1) NOT NULL DEFAULT 0', 'KEY yandex_region', 'KEY wdc_region_name', 'UNIQUE KEY yandex_wdc_region (yandex_region, wdc_region_name)' ) as $needle ) {
	yd_region_mapping_v2_assert( str_contains( $schema, $needle ), 'Schema must contain: ' . $needle );
}
foreach ( array( 'status', 'confidence', 'matched_by', 'is_manual' ) as $forbidden ) {
	yd_region_mapping_v2_assert( ! preg_match( '/\b' . preg_quote( $forbidden, '/' ) . '\b/', $schema ), 'Schema must not contain forbidden field: ' . $forbidden );
}

$GLOBALS['wpdb']->yandex_delivery_pickup_points_v2 = array(
	array( 'region' => 'Москва и Московская область' ),
	array( 'region' => 'Санкт-Петербург и Ленинградская область' ),
	array( 'region' => 'Чувашская Республика — Чувашия' ),
	array( 'region' => 'Кемеровская область — Кузбасс' ),
	array( 'region' => 'Республика Татарстан (Татарстан)' ),
	array( 'region' => 'Витебская область' ),
	array( 'region' => '' ),
);
$GLOBALS['wpdb']->yandex_delivery_geo_v2 = array(
	array( 'region' => 'федеральная территория Сириус' ),
	array( 'region' => 'Старый регион' ),
);
$GLOBALS['wpdb']->wdc_locations = array(
	array( 'region_name' => 'Москва' ),
	array( 'region_name' => 'Московская' ),
	array( 'region_name' => 'Санкт-Петербург' ),
	array( 'region_name' => 'Ленинградская' ),
	array( 'region_name' => 'Чувашская Республика -' ),
	array( 'region_name' => 'Кемеровская область - Кузбасс' ),
	array( 'region_name' => 'Татарстан' ),
);
$GLOBALS['wpdb']->yandex_region_mapping_v2 = array(
	array( 'id' => 1, 'yandex_region' => 'Республика Татарстан (Татарстан)', 'wdc_region_name' => 'Татарстан', 'needs_review' => 0, 'created_at' => 'old', 'updated_at' => 'old' ),
	array( 'id' => 2, 'yandex_region' => 'Старый регион', 'wdc_region_name' => 'Устаревшая область', 'needs_review' => 0, 'created_at' => 'old', 'updated_at' => 'old' ),
);

$report = $repository->sync_from_sources();
yd_region_mapping_v2_assert( 8 === $report['yandex_regions'] && 7 === $report['wdc_regions'] && 8 === $report['added'] && 6 === $report['needs_review'] && 1 === $report['stale_review'], 'sync_from_sources must import regions, add rows, and flag stale existing mapping.' );
$rows = $repository->list_rows();
$by_yandex = array();
foreach ( $rows as $row ) {
	$by_yandex[ (string) $row['yandex_region'] ][] = $row;
}
yd_region_mapping_v2_assert( array( 'Москва', 'Московская' ) === $repository->find_wdc_regions_for_yandex( 'Москва и Московская область' ), 'Moscow complex region must map one-to-many.' );
yd_region_mapping_v2_assert( array( 'Ленинградская', 'Санкт-Петербург' ) === $repository->find_wdc_regions_for_yandex( 'Санкт-Петербург и Ленинградская область' ), 'Saint Petersburg complex region must map one-to-many.' );
yd_region_mapping_v2_assert( array( 'Чувашская Республика -' ) === $repository->find_wdc_regions_for_yandex( 'Чувашская Республика — Чувашия' ), 'Chuvash region must auto-map to the WDC spelling.' );
yd_region_mapping_v2_assert( array( 'Кемеровская область - Кузбасс' ) === $repository->find_wdc_regions_for_yandex( 'Кемеровская область — Кузбасс' ), 'Kemerovo/Kuzbass region must auto-map to the WDC spelling.' );
yd_region_mapping_v2_assert( 1 === count( $by_yandex['Республика Татарстан (Татарстан)'] ?? array() ) && 'old' === (string) $by_yandex['Республика Татарстан (Татарстан)'][0]['created_at'], 'Existing mapping must not be overwritten by sync.' );
yd_region_mapping_v2_assert( 1 === (int) ( $by_yandex['Старый регион'][0]['needs_review'] ?? 0 ), 'Existing mapping with missing WDC region must be marked needs_review.' );
yd_region_mapping_v2_assert( isset( $by_yandex['Витебская область'][0] ) && '' === (string) $by_yandex['Витебская область'][0]['wdc_region_name'] && 1 === (int) $by_yandex['Витебская область'][0]['needs_review'], 'Missing WDC match must be saved as not selected and needs_review.' );
yd_region_mapping_v2_assert( isset( $by_yandex['федеральная территория Сириус'][0] ) && '' === (string) $by_yandex['федеральная территория Сириус'][0]['wdc_region_name'] && 1 === (int) $by_yandex['федеральная территория Сириус'][0]['needs_review'], 'Federal territory without match must require review.' );

$save = $repository->save_mapping( 'Москва и Московская область', array( 'Москва' ) );
yd_region_mapping_v2_assert( 1 === $save['saved'] && array( 'Москва' ) === $repository->find_wdc_regions_for_yandex( 'Москва и Московская область' ), 'save_mapping must replace one-to-many rows for one Yandex region.' );
$blank = $repository->save_mapping( 'Витебская область', array( '' ) );
$rows = $repository->list_rows();
$blank_rows = array_values( array_filter( $rows, static fn( array $row ): bool => 'Витебская область' === (string) $row['yandex_region'] ) );
yd_region_mapping_v2_assert( 1 === $blank['saved'] && 1 === count( $blank_rows ) && '' === (string) $blank_rows[0]['wdc_region_name'] && 1 === (int) $blank_rows[0]['needs_review'] && array() === $repository->find_wdc_regions_for_yandex( 'Витебская область' ), 'Not selected must be stored as empty string and not returned as selected WDC region.' );
yd_region_mapping_v2_assert( array( 'Кемеровская область - Кузбасс', 'Ленинградская', 'Москва', 'Московская', 'Санкт-Петербург', 'Татарстан', 'Чувашская Республика -' ) === $repository->list_wdc_regions(), 'list_wdc_regions must return unique non-empty WDC regions.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$mapper_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexLocationMapperV2Service.php' );
$repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/LocationMappingV2/YandexRegionMappingV2Repository.php' );
yd_region_mapping_v2_assert( str_contains( $admin_source, 'Сопоставление регионов Яндекса' ) && str_contains( $admin_source, 'Обновить список регионов' ) && str_contains( $admin_source, 'Не выбран' ), 'Admin UI must render Yandex region mapping v2 block.' );
yd_region_mapping_v2_assert( str_contains( $admin_source, 'sync_yandex_region_mapping_v2' ) && str_contains( $admin_source, 'save_yandex_region_mapping_v2' ), 'Admin must handle region mapping sync/save POST actions.' );
yd_region_mapping_v2_assert( str_contains( $plugin_source, 'YandexRegionMappingV2Repository::class' ), 'Plugin DI must register region mapping v2 repository.' );
yd_region_mapping_v2_assert( str_contains( $mapper_source, 'YandexRegionMappingV2Repository' ) && str_contains( $mapper_source, 'find_wdc_regions_for_yandex' ), 'Mapper v2 must use region mapping v2 repository as the WDC region source.' );
yd_region_mapping_v2_assert( str_contains( $repository_source, 'sync_from_sources' ) && str_contains( $repository_source, 'save_mapping' ), 'New region mapping repository must exist.' );
yd_region_mapping_v2_assert( file_exists( dirname( __DIR__, 2 ) . '/database/migrations/0039_create_yandex_region_mapping_v2.php' ), 'Migration 0039 must exist.' );

echo "Yandex Delivery region mapping v2 smoke OK\n";
