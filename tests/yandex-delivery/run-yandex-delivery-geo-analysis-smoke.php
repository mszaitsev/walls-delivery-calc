<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'WDC_PLUGIN_DIR' ) || define( 'WDC_PLUGIN_DIR', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once WDC_PLUGIN_DIR . 'src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', WDC_PLUGIN_DIR . 'src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoAnalysisService;
use WallsShop\WDC\Locations\Storage\LocationRepository;

function yd_geo_analysis_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<int,array<string,mixed>> */
		public array $yandex_delivery_geo_mappings = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $replacement, $query, 1 ) ?? $query;
			}

			return $query;
		}
	}
}

function yd_geo_analysis_raw( array $matched_by, string $reason = '' ): string {
	return json_encode(
		array(
			'scoring' => array(
				'matched_by' => $matched_by,
				'reason'     => $reason,
			),
		),
		JSON_UNESCAPED_UNICODE
	) ?: '{}';
}

function yd_geo_analysis_row( int $id, int $location_id, float $confidence, string $status, array $matched_by = array(), string $reason = '' ): array {
	return array(
		'id'              => $id,
		'location_id'     => $location_id,
		'yandex_geo_id'   => 100000 + $id,
		'yandex_locality' => 'locality-' . $id,
		'yandex_region'   => 'region-' . $id,
		'source_query'    => 'query-' . $location_id,
		'status'          => $status,
		'confidence'      => $confidence,
		'is_primary'      => 0,
		'raw_json'        => yd_geo_analysis_raw( $matched_by, $reason ),
	);
}

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 1, 'country_code' => 'RU', 'region_name' => 'Регион A', 'city_name' => 'Город 1', 'place_name' => 'Город 1', 'place_type' => 'г', 'display_name' => 'Регион A, г Город 1', 'active' => 1 ),
	array( 'id' => 2, 'country_code' => 'RU', 'region_name' => 'Регион A', 'city_name' => '', 'place_name' => 'Село 2', 'place_type' => 'с', 'display_name' => 'Регион A, с Село 2', 'active' => 1 ),
	array( 'id' => 3, 'country_code' => 'RU', 'region_name' => 'Регион B', 'city_name' => '', 'place_name' => 'Хутор 3', 'place_type' => 'х', 'display_name' => 'Регион B, х Хутор 3', 'active' => 1 ),
	array( 'id' => 4, 'country_code' => 'RU', 'region_name' => 'Регион C', 'city_name' => '', 'place_name' => 'ПГТ 4', 'place_type' => 'пгт', 'display_name' => 'Регион C, пгт ПГТ 4', 'active' => 1 ),
);
$GLOBALS['wpdb']->yandex_delivery_geo_mappings = array(
	yd_geo_analysis_row( 1, 1, 100.0, 'mapped', array( 'locality_exact', 'region_match' ), 'perfect' ),
	yd_geo_analysis_row( 2, 2, 97.0, 'mapped', array( 'locality_exact', 'district_match' ), 'strong' ),
	yd_geo_analysis_row( 3, 3, 85.0, 'multiple_matches', array( 'weak_substring' ), 'needs manual review' ),
	yd_geo_analysis_row( 4, 4, 65.0, 'manual', array( 'type_equivalent' ), 'manual row' ),
	yd_geo_analysis_row( 5, 1, 45.0, 'multiple_matches', array( 'weak_substring', 'region_mismatch' ), 'low substring' ),
	yd_geo_analysis_row( 6, 2, 12.0, 'error', array( 'api_error' ), 'api failed' ),
	yd_geo_analysis_row( 7, 3, 0.0, 'not_found', array(), 'not found' ),
);

$analysis = new YandexDeliveryGeoAnalysisService( new LocationRepository( $GLOBALS['wpdb'] ), $GLOBALS['wpdb'] );

$buckets = $analysis->get_bucket_statistics();
yd_geo_analysis_assert( 1 === $buckets['100'], 'Bucket 100 must be counted.' );
yd_geo_analysis_assert( 1 === $buckets['95_99'], 'Bucket 95_99 must be counted.' );
yd_geo_analysis_assert( 1 === $buckets['80_94'], 'Bucket 80_94 must be counted.' );
yd_geo_analysis_assert( 1 === $buckets['60_79'], 'Bucket 60_79 must be counted.' );
yd_geo_analysis_assert( 1 === $buckets['40_59'], 'Bucket 40_59 must be counted.' );
yd_geo_analysis_assert( 1 === $buckets['1_39'], 'Bucket 1_39 must be counted.' );
yd_geo_analysis_assert( 1 === $buckets['0'], 'Bucket 0 must be counted.' );

$statuses = $analysis->get_status_statistics();
yd_geo_analysis_assert( 2 === $statuses['mapped'], 'Mapped status must be counted.' );
yd_geo_analysis_assert( 2 === $statuses['multiple_matches'], 'multiple_matches status must be counted.' );
yd_geo_analysis_assert( 1 === $statuses['not_found'], 'not_found status must be counted.' );
yd_geo_analysis_assert( 1 === $statuses['manual'], 'manual status must be counted.' );
yd_geo_analysis_assert( 1 === $statuses['error'], 'error status must be counted.' );

$regions = $analysis->get_top_regions( 59.99 );
yd_geo_analysis_assert( 'Регион A' === $regions[0]['region'] && 2 === $regions[0]['count'], 'Top regions must aggregate low-confidence mappings by WDC location region.' );

$types = $analysis->get_top_settlement_types( 59.99 );yd_geo_analysis_assert( array( 'город', 'село', 'хутор' ) === array_column( $types, 'type' ), 'Top settlement types must use canonical readable type labels.' );

$patterns = $analysis->get_top_matched_by_patterns( 59.99 );
$pattern_counts = array_column( $patterns, 'count', 'pattern' );
yd_geo_analysis_assert( 1 === ( $pattern_counts['weak_substring'] ?? 0 ), 'matched_by weak_substring must be counted from raw_json.scoring.matched_by.' );
yd_geo_analysis_assert( 1 === ( $pattern_counts['region_mismatch'] ?? 0 ), 'matched_by region_mismatch must be counted from raw_json.scoring.matched_by.' );
yd_geo_analysis_assert( 1 === ( $pattern_counts['api_error'] ?? 0 ), 'matched_by api_error must be counted from raw_json.scoring.matched_by.' );

$low_rows = $analysis->get_low_confidence_rows( 59.99, 100 );
yd_geo_analysis_assert( 3 === count( $low_rows ), 'Low confidence rows must respect max_confidence.' );
yd_geo_analysis_assert( 0.0 === $low_rows[0]['confidence'] && 'Регион B, х Хутор 3' === $low_rows[0]['display_name'], 'Low confidence rows must include display_name and be sorted by lowest confidence.' );
yd_geo_analysis_assert( str_contains( $low_rows[1]['matched_by'], 'api_error' ) && 'api failed' === $low_rows[1]['reason'], 'Low confidence rows must include matched_by and reason.' );
yd_geo_analysis_assert( str_contains( $low_rows[2]['matched_by'], 'weak_substring' ) && 'query-1' === $low_rows[2]['source_query'], 'Low confidence rows must include matched_by and source_query.' );

$service_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoAnalysisService.php' );
$admin_source   = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source  = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Core/Plugin.php' );
$version_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'walls-delivery-calc.php' );

foreach ( array( 'detect_for_location_id', 'locationDetect', 'YandexDeliveryApiClient', 'CheckoutOrchestrator', 'pricing', 'pickupPointsList', 'YandexDeliveryPickupPointImportService' ) as $forbidden ) {
	yd_geo_analysis_assert( ! str_contains( $service_source, $forbidden ), 'Analysis service must not call mapping/API/checkout/pricing/pickup code: ' . $forbidden );
}

yd_geo_analysis_assert( str_contains( $admin_source, "\$tabs['yandex_delivery_geo'] = 'Маппинг geo_id';" ) && ! str_contains( $admin_source, "\$tabs['yandex_delivery_geo_analysis']" ), 'Admin navigation must expose geo analysis inside the consolidated mapping tab only.' );
yd_geo_analysis_assert( str_contains( $admin_source, 'Аналитика маппинга' ) && str_contains( $admin_source, 'Статистика confidence' ) && str_contains( $admin_source, 'Статистика статусов' ) && str_contains( $admin_source, 'Проблемные регионы' ) && str_contains( $admin_source, 'Типы населённых пунктов' ) && str_contains( $admin_source, 'Сигналы сопоставления' ) && str_contains( $admin_source, 'Низкая уверенность' ), 'Consolidated mapping tab must render Russian analysis sections.' );
yd_geo_analysis_assert( str_contains( $admin_source, 'name="tab" value="yandex_delivery_geo"' ), 'Yandex geo analysis filter must return to the consolidated mapping tab.' );
$analysis_tab_start = strpos( $admin_source, 'function render_yandex_delivery_geo_analysis_tab' );
$analysis_tab_end   = strpos( $admin_source, 'function render_yandex_delivery_geo_batch_tab', false === $analysis_tab_start ? 0 : $analysis_tab_start );
$analysis_tab_source = false !== $analysis_tab_start && false !== $analysis_tab_end ? substr( $admin_source, $analysis_tab_start, $analysis_tab_end - $analysis_tab_start ) : '';
yd_geo_analysis_assert( '' !== $analysis_tab_source, 'Smoke must find render_yandex_delivery_geo_analysis_tab source slice.' );
yd_geo_analysis_assert( ! str_contains( $analysis_tab_source, '->carrier_code()' ), 'Yandex geo analysis tab must not call missing DeliveryService::carrier_code().' );
yd_geo_analysis_assert( str_contains( $analysis_tab_source, 'is_yandex_delivery_service( $service )' ), 'Yandex geo analysis tab must use existing is_yandex_delivery_service() guard.' );
yd_geo_analysis_assert( str_contains( $admin_source, 'get_low_confidence_rows' ) && str_contains( $admin_source, 'max_confidence' ), 'Admin UI must render analysis dashboard and max_confidence filter.' );
yd_geo_analysis_assert( str_contains( $admin_source, '?YandexDeliveryGeoAnalysisService $yandex_delivery_geo_analysis' ), 'DeliveryServicesAdminPage must receive YandexDeliveryGeoAnalysisService through constructor DI.' );
yd_geo_analysis_assert( str_contains( $plugin_source, 'YandexDeliveryGeoAnalysisService::class' ), 'Plugin source must reference YandexDeliveryGeoAnalysisService::class.' );
yd_geo_analysis_assert( preg_match( '/container->register\(\s*YandexDeliveryGeoAnalysisService::class\s*,\s*fn\(\):\s*YandexDeliveryGeoAnalysisService\s*=>\s*new\s+YandexDeliveryGeoAnalysisService\(\s*\$this->container->get\(\s*LocationRepository::class\s*\)\s*\)\s*\)/s', $plugin_source ) === 1, 'Plugin container must register YandexDeliveryGeoAnalysisService with the LocationRepository dependency.' );
yd_geo_analysis_assert( preg_match( '/new\s+DeliveryServicesAdminPage\(.*\$this->container->get\(\s*YandexDeliveryGeoAnalysisService::class\s*\)/s', $plugin_source ) === 1, 'Plugin must pass YandexDeliveryGeoAnalysisService into DeliveryServicesAdminPage.' );
yd_geo_analysis_assert( str_contains( $version_source, '0.87.2' ), 'Plugin version must be 0.87.2.' );

echo "Yandex Delivery geo analysis smoke OK\n";
