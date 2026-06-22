<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'WDC_SECRET_KEY' ) || define( 'WDC_SECRET_KEY', 'yandex-delivery-geo-smoke-key' );
defined( 'WDC_PLUGIN_DIR' ) || define( 'WDC_PLUGIN_DIR', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once WDC_PLUGIN_DIR . 'src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', WDC_PLUGIN_DIR . 'src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingRepository;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingService;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingStatus;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;

function yd_geo_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-23 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'yandex-delivery-geo-smoke-salt-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_yandex_delivery_geo_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_yandex_delivery_geo_options'][ $key ] = $value; return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function dbDelta( string|array $queries = '', bool $execute = true ): array { ++$GLOBALS['wdc_yandex_delivery_geo_dbdelta_calls']; return array(); }

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

		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

final class YdGeoFakeHttp implements YandexDeliveryHttpClientInterface {
	/** @var array<int,YandexDeliveryApiResponse|YandexDeliveryApiException> */
	private array $queue;
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	public function __construct( YandexDeliveryApiResponse|YandexDeliveryApiException ...$responses ) {
		$this->queue = $responses;
	}

	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$this->calls[] = compact( 'method', 'url', 'args' );
		$next = array_shift( $this->queue ) ?? new YandexDeliveryApiResponse( 200, json_encode( array( 'locations' => array() ) ) ?: '{}' );
		if ( $next instanceof YandexDeliveryApiException ) {
			throw $next;
		}

		return $next;
	}
}

$GLOBALS['wdc_yandex_delivery_geo_options'] = array();
$GLOBALS['wdc_yandex_delivery_geo_dbdelta_calls'] = 0;
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 10, 'country_code' => 'RU', 'region_name' => 'Новосибирская область', 'district_name' => '', 'city_name' => 'Новосибирск', 'place_name' => 'Новосибирск', 'place_type' => 'г', 'display_name' => 'Новосибирская область, г Новосибирск', 'active' => 1 ),
	array( 'id' => 11, 'country_code' => 'RU', 'region_name' => 'Новосибирская область', 'district_name' => 'Новосибирский район', 'district_type' => 'р-н', 'city_name' => '', 'place_name' => 'Гусиный Брод', 'place_type' => 'с', 'display_name' => 'Новосибирская область, Новосибирский район, с Гусиный Брод', 'active' => 1 ),
);

$migration_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0033_create_yandex_delivery_geo_mappings_table.php' );
$repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingRepository.php' );
$service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingService.php' );
$api_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Api/YandexDeliveryApiClient.php' );
$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
yd_geo_assert( str_contains( $migration_source, 'YandexDeliveryGeoMappingRepository' ) && str_contains( $repository_source, 'wdc_yandex_delivery_geo_mappings' ), 'Migration must create Yandex geo mappings table through repository.' );
foreach ( array( 'location_id bigint', 'yandex_geo_id bigint', 'yandex_locality varchar(255)', 'yandex_region varchar(255)', 'source_query varchar(255)', 'status varchar(32)', 'confidence decimal(5,2)', 'is_primary tinyint(1)', 'raw_json longtext', 'KEY location_id', 'KEY yandex_geo_id', 'KEY status', 'KEY is_primary' ) as $needle ) {
	yd_geo_assert( str_contains( $repository_source, $needle ), 'Schema must contain ' . $needle . '.' );
}
yd_geo_assert( str_contains( $api_source, 'locationDetect' ) && str_contains( $api_source, 'LOCATION_DETECT_PATH' ), 'API client must expose location/detect.' );
yd_geo_assert( str_contains( $admin_source, "\$tabs['yandex_delivery_geo'] = 'Yandex geo_id';" ) && str_contains( $admin_source, 'Найти geo_id' ) && str_contains( $admin_source, 'Сделать основным' ), 'Admin page must expose Yandex geo_id tab and actions.' );

$repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$repository->create_schema_if_needed();
yd_geo_assert( 0 === $GLOBALS['wdc_yandex_delivery_geo_dbdelta_calls'], 'Fake repository must not call dbDelta.' );
$repository->save_mapping( array( 'location_id' => 10, 'yandex_geo_id' => 65, 'yandex_locality' => 'Новосибирск', 'yandex_region' => 'Новосибирская область', 'source_query' => 'Россия, Новосибирская область, Новосибирск', 'status' => YandexDeliveryGeoMappingStatus::MAPPED, 'confidence' => 100, 'is_primary' => 1 ) );
$repository->save_mapping( array( 'location_id' => 10, 'yandex_geo_id' => 215000, 'yandex_locality' => 'Новосибирск', 'yandex_region' => 'Новосибирская область', 'status' => YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES, 'confidence' => 40 ) );
yd_geo_assert( 2 === count( $repository->find_by_location_id( 10 ) ), 'find_by_location_id must return multiple geo_id rows for one location_id.' );
yd_geo_assert( 65 === $repository->find_primary_geo_id( 10 ), 'find_primary_geo_id must return primary mapping.' );
yd_geo_assert( true === $repository->set_primary( 10, 215000 ) && 215000 === $repository->find_primary_geo_id( 10 ), 'set_primary must switch primary mapping.' );
yd_geo_assert( YandexDeliveryGeoMappingStatus::MANUAL === (string) $repository->find_by_location_id( 10 )[0]['status'], 'set_primary must mark selected mapping as manual.' );
$stats = $repository->statistics();
yd_geo_assert( 1 === $stats['locations_with_mappings'] && 1 === $stats['primary_mappings'] && 1 === $stats['manual_mappings'], 'statistics must count locations, primary and manual mappings.' );

$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'secret-test-token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'sender-1' ) );
$http = new YdGeoFakeHttp( new YandexDeliveryApiResponse( 200, json_encode( array( 'locations' => array( array( 'geo_id' => 65, 'locality' => 'Новосибирск', 'region' => 'Новосибирская область' ) ) ), JSON_UNESCAPED_UNICODE ) ?: '{}' ) );
$service = new YandexDeliveryGeoMappingService( new LocationRepository( $GLOBALS['wpdb'] ), new YandexDeliveryApiClient( $settings, $http ), new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] ) );
$query = $service->build_search_query( ( new LocationRepository( $GLOBALS['wpdb'] ) )->find_by_id( 11 ) );
yd_geo_assert( 'Россия, Новосибирская область, р-н Новосибирский район, с Гусиный Брод' === $query, 'search query must include country, region, district and settlement when district exists.' );
$result = $service->detect_for_location_id( 10 );
yd_geo_assert( true === $result['success'] && YandexDeliveryGeoMappingStatus::MAPPED === $result['status'], 'detect_for_location_id must save mapped result.' );
$payload = json_decode( (string) $http->calls[0]['args']['body'], true );
yd_geo_assert( array( 'location' => 'Россия, Новосибирская область, г Новосибирск' ) === $payload, 'location/detect request must send built search string.' );
yd_geo_assert( 100.00 === (float) $result['mappings'][0]['confidence'], 'confidence must be 100 when locality and region match.' );
yd_geo_assert( 70.00 === $service->confidence( ( new LocationRepository( $GLOBALS['wpdb'] ) )->find_by_id( 10 ), 'Новосибирск', 'Другой регион', 1 ), 'confidence must be 70 when only locality matches.' );
$normalized_multiple = $service->normalize_detect_response( ( new LocationRepository( $GLOBALS['wpdb'] ) )->find_by_id( 10 ), 'query', array( 'body' => array( 'variants' => array( array( 'geoId' => 1, 'address' => array( 'locality' => 'Новосибирск', 'region' => 'Новосибирская область' ) ), array( 'id' => 2, 'name' => 'Новосибирск', 'region_name' => 'Новосибирская область' ) ) ) ) );
yd_geo_assert( 2 === count( $normalized_multiple ) && YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES === $normalized_multiple[0]['status'] && 40.00 === (float) $normalized_multiple[0]['confidence'], 'normalization must support multiple location/detect variants with confidence 40.' );
$normalized_empty = $service->normalize_detect_response( ( new LocationRepository( $GLOBALS['wpdb'] ) )->find_by_id( 10 ), 'query', array( 'body' => array( 'locations' => array() ) ) );
yd_geo_assert( YandexDeliveryGeoMappingStatus::NOT_FOUND === $normalized_empty[0]['status'] && 0 === (int) $normalized_empty[0]['yandex_geo_id'], 'empty location/detect result must normalize to not_found.' );
foreach ( array( 'mapped', 'multiple_matches', 'not_found', 'manual', 'error' ) as $status ) {
	yd_geo_assert( $status === YandexDeliveryGeoMappingStatus::normalize( $status ), 'Status model must allow ' . $status . '.' );
}

$repository->save_mapping( array( 'location_id' => 12, 'yandex_geo_id' => 0, 'status' => YandexDeliveryGeoMappingStatus::NOT_FOUND ) );
$repository->save_mapping( array( 'location_id' => 13, 'yandex_geo_id' => 0, 'status' => YandexDeliveryGeoMappingStatus::ERROR ) );
$stats = $repository->statistics();
yd_geo_assert( 1 === $stats['not_found'], 'statistics must count not_found mappings.' );
yd_geo_assert( in_array( 'error', YandexDeliveryGeoMappingStatus::all(), true ), 'status model must include error.' );

echo "Yandex Delivery geo mapping smoke test passed.\n";