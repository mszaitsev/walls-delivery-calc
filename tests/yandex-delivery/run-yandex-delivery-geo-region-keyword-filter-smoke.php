<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'WDC_PLUGIN_DIR', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }

require_once WDC_PLUGIN_DIR . 'src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', WDC_PLUGIN_DIR . 'src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingRepository;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingService;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingStatus;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMatchScorer;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoRegionKeywordFilter;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoResolutionPolicy;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

function yd_geo_region_filter_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "[FAIL] {$message}\n" );
		exit( 1 );
	}
}

function current_time( string $type ): string { return '2026-06-26 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<int,array<string,mixed>> */
		public array $yandex_delivery_geo_mappings = array();
		public function prepare( string $query, mixed ...$args ): string { return $query; }
		public function get_var( string $query ): mixed { return null; }
		public function get_col( string $query ): array { return array(); }
		public function get_results( string $query, string $output = ARRAY_A ): array { return array(); }
		public function query( string $query ): int { return 0; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}
$GLOBALS['wpdb'] = new wpdb();

function yd_geo_region_location( int $id, string $region_name, string $display_name = '' ): Location {
	return Location::from_array(
		array(
			'id' => $id,
			'country_code' => 'RU',
			'region_name' => $region_name,
			'place_name' => 'Комсомольский',
			'place_type' => 'поселок',
			'display_name' => '' !== $display_name ? $display_name : $region_name . ', поселок Комсомольский',
			'active' => 1,
		)
	);
}

function yd_geo_region_variant( int $geo_id, string $locality, string $address, string $region = '' ): array {
	return array(
		'geo_id' => $geo_id,
		'locality' => $locality,
		'region' => $region,
		'address' => array(
			'full_address' => $address,
			'locality' => $locality,
			'region' => $region,
		),
	);
}

$filter = new YandexDeliveryGeoRegionKeywordFilter();
$adygea = yd_geo_region_location( 1, 'Адыгея', 'респ Адыгея, Кошехабльский р-н, поселок Комсомольский' );
$altai = yd_geo_region_location( 2, 'Алтайский край' );
$unknown = yd_geo_region_location( 3, 'Регион без ключа' );

yd_geo_region_filter_assert( 'Адыгея' === $filter->keyword_for_location( $adygea ), 'Адыгея keyword must be selected for Адыгея region.' );
yd_geo_region_filter_assert( 'Алтайский' === $filter->keyword_for_location( $altai ), 'Алтайский keyword must win over shorter Алтай for Алтайский region.' );
yd_geo_region_filter_assert( '' === $filter->keyword_for_location( $unknown ), 'Unknown region must not select a keyword.' );

$rows = array(
	array( 'yandex_geo_id' => 101, 'yandex_locality' => 'посёлок Комсомольский', 'yandex_region' => '', 'raw_json' => json_encode( array( 'variant' => yd_geo_region_variant( 101, 'посёлок Комсомольский', 'посёлок Комсомольский, Республика Адыгея' ) ), JSON_UNESCAPED_UNICODE ) ),
	array( 'yandex_geo_id' => 102, 'yandex_locality' => 'посёлок Комсомольский, Пермский край', 'yandex_region' => 'Пермский край', 'raw_json' => '{}' ),
	array( 'yandex_geo_id' => 103, 'yandex_locality' => 'посёлок Комсомольский, Воронежская область', 'yandex_region' => 'Воронежская область', 'raw_json' => '{}' ),
);
$filtered = $filter->filter( $adygea, $rows );
yd_geo_region_filter_assert( 1 === count( $filtered['rows'] ) && 101 === (int) $filtered['rows'][0]['yandex_geo_id'] && 2 === (int) $filtered['removed_candidates'], 'Only candidate containing Адыгея in address/raw_json must remain.' );
$unfiltered = $filter->filter( $unknown, $rows );
yd_geo_region_filter_assert( 3 === count( $unfiltered['rows'] ) && false === (bool) $unfiltered['filtered'], 'Candidates must remain untouched when no region keyword is found.' );

$api = ( new ReflectionClass( YandexDeliveryApiClient::class ) )->newInstanceWithoutConstructor();
$service = new YandexDeliveryGeoMappingService( new LocationRepository( $GLOBALS['wpdb'] ), $api, new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] ), new YandexDeliveryGeoMatchScorer(), new YandexDeliveryGeoResolutionPolicy(), 'all', $filter );
$normalized = $service->normalize_detect_response(
	$adygea,
	$adygea->resolved_display_name(),
	array( 'locations' => array( yd_geo_region_variant( 101, 'Комсомольский', 'посёлок Комсомольский, Республика Адыгея' ), yd_geo_region_variant( 102, 'Комсомольский', 'посёлок Комсомольский, Пермский край' ), yd_geo_region_variant( 103, 'Комсомольский', 'посёлок Комсомольский, Воронежская область' ) ) )
);
yd_geo_region_filter_assert( 1 === count( $normalized ) && 101 === (int) $normalized[0]['yandex_geo_id'], 'normalize_detect_response() must apply region filtering before resolution policy.' );
$raw = json_decode( (string) $normalized[0]['raw_json'], true );
yd_geo_region_filter_assert( 'Адыгея' === (string) ( $raw['region_keyword_filter']['keyword'] ?? '' ) && 2 === (int) ( $raw['region_keyword_filter']['removed_candidates'] ?? 0 ), 'Saved row must contain compact region keyword filter audit when candidates were removed.' );

$not_found = $service->normalize_detect_response(
	$adygea,
	$adygea->resolved_display_name(),
	array( 'locations' => array( yd_geo_region_variant( 201, 'Комсомольский', 'посёлок Комсомольский, Пермский край' ), yd_geo_region_variant( 202, 'Комсомольский', 'посёлок Комсомольский, Воронежская область' ) ) )
);
$not_found_raw = json_decode( (string) $not_found[0]['raw_json'], true );
yd_geo_region_filter_assert( 1 === count( $not_found ) && YandexDeliveryGeoMappingStatus::NOT_FOUND === (string) $not_found[0]['status'] && empty( $not_found[0]['is_primary'] ) && null === $not_found[0]['yandex_geo_id'], 'All foreign-region candidates must normalize to a single not_found mapping.' );
yd_geo_region_filter_assert( 'Адыгея' === (string) ( $not_found_raw['region_keyword_filter']['keyword'] ?? '' ) && 2 === (int) ( $not_found_raw['region_keyword_filter']['removed_candidates'] ?? 0 ) && str_contains( (string) ( $not_found_raw['region_keyword_filter']['reason'] ?? '' ), 'No Yandex candidate' ), 'not_found mapping must contain region keyword filter audit.' );

$admin_source = file_get_contents( WDC_PLUGIN_DIR . 'src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ) ?: '';
$service_source = file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingService.php' ) ?: '';
$plugin_source = file_get_contents( WDC_PLUGIN_DIR . 'src/Core/Plugin.php' ) ?: '';
$version_source = file_get_contents( WDC_PLUGIN_DIR . 'walls-delivery-calc.php' ) ?: '';
yd_geo_region_filter_assert( str_contains( $admin_source, 'Ключевые слова регионального автоотсева' ) && str_contains( $admin_source, '<details' ) && str_contains( $admin_source, 'implode( \' , \' )' ) || str_contains( $admin_source, "implode( ', ', YandexDeliveryGeoRegionKeywordFilter::keywords() )" ), 'Admin UI must show region keyword list in a details block separated by commas.' );
yd_geo_region_filter_assert( str_contains( $service_source, 'region_keyword_filter->filter' ) && strpos( $service_source, 'region_keyword_filter->filter' ) < strpos( $service_source, 'resolution_policy->resolve' ), 'Mapping service must apply region keyword filter before resolution policy.' );
yd_geo_region_filter_assert( str_contains( $plugin_source, 'YandexDeliveryGeoRegionKeywordFilter::class' ), 'Plugin container must register the region keyword filter.' );
yd_geo_region_filter_assert( str_contains( $version_source, '0.92.0' ), 'Plugin version must be 0.92.0.' );

echo "Yandex Delivery geo region keyword filter smoke passed.\n";
