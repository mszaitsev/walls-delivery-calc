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
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMatchScorer;
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
	array( 'id' => 20, 'country_code' => 'RU', 'region_name' => 'Москва', 'city_name' => 'Москва', 'place_name' => 'Москва', 'place_type' => 'г', 'display_name' => 'г Москва', 'active' => 1 ),
	array( 'id' => 21, 'country_code' => 'RU', 'region_name' => 'Санкт-Петербург', 'city_name' => 'Санкт-Петербург', 'place_name' => 'Санкт-Петербург', 'place_type' => 'г', 'display_name' => 'г Санкт-Петербург', 'active' => 1 ),
	array( 'id' => 22, 'country_code' => 'RU', 'region_name' => 'Республика Татарстан', 'city_name' => 'Казань', 'place_name' => 'Казань', 'place_type' => 'г', 'display_name' => 'Республика Татарстан, г Казань', 'active' => 1 ),
	array( 'id' => 23, 'country_code' => 'RU', 'region_name' => 'Свердловская область', 'city_name' => 'Екатеринбург', 'place_name' => 'Екатеринбург', 'place_type' => 'г', 'display_name' => 'Свердловская область, г Екатеринбург', 'active' => 1 ),
);

$migration_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0033_create_yandex_delivery_geo_mappings_table.php' );
$repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingRepository.php' );
$service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingService.php' );
$api_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Api/YandexDeliveryApiClient.php' );
$scorer_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMatchScorer.php' );
$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
yd_geo_assert( str_contains( $migration_source, 'YandexDeliveryGeoMappingRepository' ) && str_contains( $repository_source, 'wdc_yandex_delivery_geo_mappings' ), 'Migration must create Yandex geo mappings table through repository.' );
yd_geo_assert( str_contains( $repository_source, 'yandex_geo_id bigint(20) unsigned NULL' ), 'Schema must make yandex_geo_id nullable.' );
yd_geo_assert( ! str_contains( $repository_source, 'yandex_geo_id bigint(20) unsigned NOT NULL' ), 'Schema must not make yandex_geo_id NOT NULL.' );
foreach ( array( 'location_id bigint', 'yandex_locality varchar(255)', 'yandex_region varchar(255)', 'source_query varchar(255)', 'status varchar(32)', 'confidence decimal(5,2)', 'is_primary tinyint(1)', 'raw_json longtext', 'KEY location_id', 'KEY yandex_geo_id', 'KEY status', 'KEY is_primary' ) as $needle ) {
	yd_geo_assert( str_contains( $repository_source, $needle ), 'Schema must contain ' . $needle . '.' );
}
yd_geo_assert( str_contains( $repository_source, 'private function find_existing_mapping_id' ) && str_contains( $repository_source, 'private function update_row' ), 'Repository must use explicit upsert helpers.' );
yd_geo_assert( str_contains( $api_source, 'locationDetect' ) && str_contains( $api_source, 'LOCATION_DETECT_PATH' ), 'API client must expose location/detect.' );
yd_geo_assert( str_contains( $admin_source, "\$tabs['yandex_delivery_geo'] = 'Yandex geo_id';" ) && str_contains( $admin_source, 'Найти geo_id' ) && str_contains( $admin_source, 'Сделать основным' ), 'Admin page must expose Yandex geo_id tab and actions.' );
yd_geo_assert( str_contains( $admin_source, "(int) ( \$row['yandex_geo_id'] ?? 0 ) > 0" ) && str_contains( $admin_source, "'—'" ), 'Admin source must show primary button only for positive geo_id and render NULL as dash.' );
yd_geo_assert( str_contains( $admin_source, '?YandexDeliveryGeoMappingRepository $yandex_delivery_geo_mappings' ) && str_contains( $admin_source, '?YandexDeliveryGeoMappingService $yandex_delivery_geo_mapping_service' ), 'DeliveryServicesAdminPage constructor must promote Yandex geo mapping dependencies.' );
yd_geo_assert( str_contains( $plugin_source, '$this->container->get( YandexDeliveryGeoMappingRepository::class )' ) && str_contains( $plugin_source, '$this->container->get( YandexDeliveryGeoMappingService::class )' ), 'Plugin must pass Yandex geo mapping dependencies into DeliveryServicesAdminPage.' );
yd_geo_assert( str_contains( $admin_source, '! $this->yandex_delivery_geo_mappings instanceof YandexDeliveryGeoMappingRepository' ) && str_contains( $admin_source, '! $this->yandex_delivery_geo_mapping_service instanceof YandexDeliveryGeoMappingService' ), 'Yandex geo tab guard must be backed by constructor DI properties.' );
yd_geo_assert( str_contains( $service_source, 'is_string( $variant[\'address\'] ?? null )' ) && str_contains( $service_source, 'trim( $variant[\'address\'] )' ), 'Normalizer must use string address as locality fallback.' );
yd_geo_assert( str_contains( $scorer_source, 'final class YandexDeliveryGeoMatchScorer' ) && str_contains( $scorer_source, 'foreign_country_hint' ) && str_contains( $scorer_source, 'matched_by' ), 'Scorer source must contain deterministic smart geo match logic.' );
yd_geo_assert( str_contains( $plugin_source, 'YandexDeliveryGeoMatchScorer::class' ) && str_contains( $plugin_source, 'new YandexDeliveryGeoMatchScorer()' ), 'Plugin must register YandexDeliveryGeoMatchScorer in the container.' );
yd_geo_assert( str_contains( $plugin_source, '$this->container->get( YandexDeliveryGeoMatchScorer::class )' ), 'Plugin must pass YandexDeliveryGeoMatchScorer into YandexDeliveryGeoMappingService.' );
yd_geo_assert( ! str_contains( $service_source, 'new YandexDeliveryGeoMatchScorer()' ) && ! str_contains( $service_source, '??= new YandexDeliveryGeoMatchScorer' ), 'Geo mapping service must not fallback-construct the scorer.' );
yd_geo_assert( str_contains( $service_source, 'private YandexDeliveryGeoMatchScorer $scorer' ) && ! str_contains( $service_source, '?YandexDeliveryGeoMatchScorer $scorer = null' ), 'Geo mapping service constructor must require scorer dependency.' );
yd_geo_assert( ! str_contains( $admin_source, '\'details\' => $result' ) && str_contains( $admin_source, '\'mappings_count\' =>' ) && str_contains( $admin_source, '\'success\' => ! empty( $result[\'success\'] ) ? \'yes\' : \'no\'' ), 'Yandex geo detect action result must store compact details instead of the full result blob.' );

$repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$repository->create_schema_if_needed();
yd_geo_assert( 0 === $GLOBALS['wdc_yandex_delivery_geo_dbdelta_calls'], 'Fake repository must not call dbDelta.' );
$repository->save_mapping( array( 'location_id' => 10, 'yandex_geo_id' => 65, 'yandex_locality' => 'Новосибирск', 'yandex_region' => 'Новосибирская область', 'source_query' => 'Россия, Новосибирская область, Новосибирск', 'status' => YandexDeliveryGeoMappingStatus::MAPPED, 'confidence' => 100, 'is_primary' => 1 ) );
$repository->save_mapping( array( 'location_id' => 10, 'yandex_geo_id' => 65, 'yandex_locality' => 'Новосибирск', 'yandex_region' => 'Новосибирская область', 'source_query' => 'Россия, Новосибирская область, Новосибирск', 'status' => YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES, 'confidence' => 40, 'is_primary' => 1 ) );
$rows_10 = $repository->find_by_location_id( 10 );
yd_geo_assert( 1 === count( array_filter( $rows_10, static fn( array $row ): bool => (int) ( $row['yandex_geo_id'] ?? 0 ) === 65 ) ), 'save_mapping must upsert duplicate location_id + yandex_geo_id rows.' );
yd_geo_assert( YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES === (string) $rows_10[0]['status'] && 40.00 === (float) $rows_10[0]['confidence'], 'upsert must update existing positive mapping.' );
$repository->save_mapping( array( 'location_id' => 10, 'yandex_geo_id' => 215000, 'yandex_locality' => 'Новосибирск', 'yandex_region' => 'Новосибирская область', 'status' => YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES, 'confidence' => 40 ) );
yd_geo_assert( 2 === count( $repository->find_by_location_id( 10 ) ), 'find_by_location_id must return multiple geo_id rows for one location_id.' );
yd_geo_assert( true === $repository->set_primary( 10, 215000 ) && 215000 === $repository->find_primary_geo_id( 10 ), 'set_primary must switch primary mapping.' );
yd_geo_assert( YandexDeliveryGeoMappingStatus::MANUAL === (string) $repository->find_by_location_id( 10 )[0]['status'], 'set_primary must mark selected mapping as manual.' );
$stats = $repository->statistics();
yd_geo_assert( 1 === $stats['locations_with_mappings'] && 1 === $stats['primary_mappings'] && 1 === $stats['manual_mappings'], 'statistics must count locations, primary and manual mappings.' );

$not_found_1 = $repository->save_mapping( array( 'location_id' => 12, 'source_query' => 'missing query', 'status' => YandexDeliveryGeoMappingStatus::NOT_FOUND ) );
$not_found_2 = $repository->save_mapping( array( 'location_id' => 12, 'source_query' => 'missing query', 'status' => YandexDeliveryGeoMappingStatus::NOT_FOUND, 'confidence' => 10 ) );
$error_row = $repository->save_mapping( array( 'location_id' => 13, 'source_query' => 'error query', 'status' => YandexDeliveryGeoMappingStatus::ERROR ) );
yd_geo_assert( null === $not_found_1['yandex_geo_id'] && null === $not_found_2['yandex_geo_id'] && null === $error_row['yandex_geo_id'], 'not_found/error mappings must store yandex_geo_id as NULL.' );
yd_geo_assert( (int) $not_found_1['id'] === (int) $not_found_2['id'] && 1 === count( $repository->search( array( 'location_id' => 12, 'status' => YandexDeliveryGeoMappingStatus::NOT_FOUND, 'limit' => 10 ) ) ), 'negative rows must dedupe by location_id + status + source_query.' );

$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'secret-test-token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'sender-1' ) );
$location_repository = new LocationRepository( $GLOBALS['wpdb'] );
$query = ( new YandexDeliveryGeoMappingService( $location_repository, new YandexDeliveryApiClient( $settings, new YdGeoFakeHttp() ), $repository, new YandexDeliveryGeoMatchScorer() ) )->build_search_query( $location_repository->find_by_id( 11 ) );
yd_geo_assert( 'Россия, Новосибирская область, р-н Новосибирский район, с Гусиный Брод' === $query, 'search query must include country, region, district and settlement when district exists.' );
$scorer = new YandexDeliveryGeoMatchScorer();
$score = $scorer->score( $location_repository->find_by_id( 20 ), array( 'geo_id' => 213, 'address' => 'Москва, Москва' ), 2 );
yd_geo_assert( $score['confidence'] >= 95, 'Moscow exact candidate must get high score.' );
$score = $scorer->score( $location_repository->find_by_id( 20 ), array( 'geo_id' => 9001, 'address' => 'КП Москва река' ), 2 );
yd_geo_assert( $score['confidence'] <= 30, 'Moscow noisy cottage-settlement candidate must get low score.' );
$score = $scorer->score( $location_repository->find_by_id( 21 ), array( 'geo_id' => 2, 'address' => 'Санкт-Петербург' ), 2 );
yd_geo_assert( $score['confidence'] >= 95, 'Saint Petersburg exact candidate must get high score.' );
$score = $scorer->score( $location_repository->find_by_id( 21 ), array( 'geo_id' => 101, 'address' => 'посёлок Парголово, Санкт-Петербург' ), 2 );
yd_geo_assert( $score['confidence'] < 95, 'Pargolovo must score below the Saint Petersburg city candidate.' );
$score = $scorer->score( $location_repository->find_by_id( 10 ), array( 'geo_id' => 65, 'address' => array( 'locality' => 'Новосибирск', 'region' => 'Новосибирская область' ) ), 2 );
yd_geo_assert( $score['confidence'] >= 95, 'Novosibirsk exact candidate must get high score.' );
$score = $scorer->score( $location_repository->find_by_id( 10 ), array( 'geo_id' => 1002, 'address' => 'посёлок Новосибирский' ), 2 );
yd_geo_assert( $score['confidence'] <= 30, 'Novosibirsky settlement must get low score.' );
$score = $scorer->score( $location_repository->find_by_id( 22 ), array( 'geo_id' => 43, 'address' => 'Казань, Республика Татарстан (Татарстан)' ), 3 );
yd_geo_assert( $score['confidence'] >= 95, 'Kazan exact Татарстан candidate must get high score.' );
$score = $scorer->score( $location_repository->find_by_id( 22 ), array( 'geo_id' => 2001, 'address' => 'Казань, Франция' ), 3 );
yd_geo_assert( $score['confidence'] <= 30, 'Kazan France candidate must get low score.' );
$score = $scorer->score( $location_repository->find_by_id( 22 ), array( 'geo_id' => 2002, 'address' => 'Казаньо, Италия' ), 3 );
yd_geo_assert( $score['confidence'] <= 10, 'Kazanyo Italy candidate must get zero or weak score.' );
$score = $scorer->score( $location_repository->find_by_id( 23 ), array( 'geo_id' => 54, 'address' => 'Екатеринбург, Свердловская область' ), 2 );
yd_geo_assert( $score['confidence'] >= 95, 'Ekaterinburg exact candidate must get high score.' );
$score = $scorer->score( $location_repository->find_by_id( 23 ), array( 'geo_id' => 2003, 'address' => 'Нижний Тагил, Свердловская область' ), 2 );
yd_geo_assert( 0.0 === $score['confidence'], 'Nizhny Tagil candidate must not match Ekaterinburg.' );

$GLOBALS['wpdb']->yandex_delivery_geo_mappings = array();
$safety_repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$safety_repository->save_mapping( array( 'location_id' => 10, 'yandex_geo_id' => 65, 'status' => YandexDeliveryGeoMappingStatus::MAPPED, 'confidence' => 100, 'is_primary' => 1 ) );
$error_service = new YandexDeliveryGeoMappingService( new LocationRepository( $GLOBALS['wpdb'] ), new YandexDeliveryApiClient( $settings, new YdGeoFakeHttp( new YandexDeliveryApiException( 'timeout', array( 'error_code' => 'timeout' ) ) ) ), $safety_repository, new YandexDeliveryGeoMatchScorer() );
$error_result = $error_service->detect_for_location_id( 10 );
$after_error = $safety_repository->find_by_location_id( 10 );
yd_geo_assert( false === $error_result['success'] && YandexDeliveryGeoMappingStatus::ERROR === $error_result['status'], 'detect_for_location_id must report API errors.' );
yd_geo_assert( null !== $safety_repository->find_primary_geo_id( 10 ) && 2 === count( $after_error ), 'failed location/detect must preserve old working mapping and add error diagnostic.' );
yd_geo_assert( null === $error_result['mappings'][0]['yandex_geo_id'], 'error diagnostic mapping must have NULL yandex_geo_id.' );

$success_http = new YdGeoFakeHttp( new YandexDeliveryApiResponse( 200, json_encode( array( 'locations' => array( array( 'geo_id' => 777, 'locality' => 'Новосибирск', 'region' => 'Новосибирская область' ) ) ), JSON_UNESCAPED_UNICODE ) ?: '{}' ) );
$success_service = new YandexDeliveryGeoMappingService( new LocationRepository( $GLOBALS['wpdb'] ), new YandexDeliveryApiClient( $settings, $success_http ), $safety_repository, new YandexDeliveryGeoMatchScorer() );
$success_result = $success_service->detect_for_location_id( 10 );
$after_success = $safety_repository->find_by_location_id( 10 );
yd_geo_assert( true === $success_result['success'] && 1 === count( $after_success ) && 777 === (int) $after_success[0]['yandex_geo_id'], 'successful location/detect must replace old mappings with new mappings.' );
$payload = json_decode( (string) $success_http->calls[0]['args']['body'], true );
yd_geo_assert( array( 'location' => 'Россия, Новосибирская область, г Новосибирск' ) === $payload, 'location/detect request must send built search string.' );
yd_geo_assert( 100.00 === (float) $success_result['mappings'][0]['confidence'], 'confidence must be 100 when locality and region match.' );
yd_geo_assert( 70.00 === $success_service->confidence( ( new LocationRepository( $GLOBALS['wpdb'] ) )->find_by_id( 10 ), 'Новосибирск', 'Другой регион', 1 ), 'confidence must be 70 when only locality matches.' );
$normalized_multiple = $success_service->normalize_detect_response( ( new LocationRepository( $GLOBALS['wpdb'] ) )->find_by_id( 10 ), 'query', array( 'body' => array( 'variants' => array( array( 'geoId' => 1, 'address' => array( 'locality' => 'Новосибирск', 'region' => 'Новосибирская область' ) ), array( 'id' => 2, 'name' => 'Новосибирск', 'region_name' => 'Новосибирская область' ) ) ) ) );
yd_geo_assert( 2 === count( $normalized_multiple ) && YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES === $normalized_multiple[0]['status'] && 100.00 === (float) $normalized_multiple[0]['confidence'] && empty( $normalized_multiple[0]['is_primary'] ), 'normalization must keep equally strong duplicate variants ambiguous.' );
yd_geo_assert( 'Новосибирск' === $normalized_multiple[0]['yandex_locality'] && 'Новосибирская область' === $normalized_multiple[0]['yandex_region'], 'structured address normalization must keep locality and region.' );
$normalized_address_string = $success_service->normalize_detect_response( ( new LocationRepository( $GLOBALS['wpdb'] ) )->find_by_id( 10 ), 'query', array( 'body' => array( 'locations' => array( array( 'geo_id' => 2, 'address' => 'Санкт-Петербург' ) ) ) ) );
yd_geo_assert( 'Санкт-Петербург' === $normalized_address_string[0]['yandex_locality'] && '' === $normalized_address_string[0]['yandex_region'], 'string address must normalize to fallback locality without region.' );
$normalized_auto_primary = $success_service->normalize_detect_response( $location_repository->find_by_id( 22 ), 'query', array( 'body' => array( 'locations' => array( array( 'geo_id' => 43, 'address' => 'Казань, Республика Татарстан (Татарстан)' ), array( 'geo_id' => 2001, 'address' => 'Казань, Франция' ), array( 'geo_id' => 2002, 'address' => 'Казаньо, Италия' ) ) ) ) );
yd_geo_assert( 43 === (int) $normalized_auto_primary[0]['yandex_geo_id'] && 1 === (int) $normalized_auto_primary[0]['is_primary'] && YandexDeliveryGeoMappingStatus::MAPPED === $normalized_auto_primary[0]['status'], 'auto-primary must select the reliable Kazan candidate.' );
yd_geo_assert( empty( $normalized_auto_primary[1]['is_primary'] ) && empty( $normalized_auto_primary[2]['is_primary'] ) && YandexDeliveryGeoMappingStatus::MULTIPLE_MATCHES === $normalized_auto_primary[1]['status'], 'auto-primary must not select lower-confidence extra candidates.' );
$raw = json_decode( (string) $normalized_auto_primary[0]['raw_json'], true );
yd_geo_assert( is_array( $raw['variant'] ?? null ) && is_array( $raw['scoring'] ?? null ) && isset( $raw['scoring']['confidence'] ) && is_array( $raw['scoring']['matched_by'] ?? null ) && is_string( $raw['scoring']['reason'] ?? null ), 'raw_json must contain variant and scoring diagnostics.' );
$normalized_empty = $success_service->normalize_detect_response( ( new LocationRepository( $GLOBALS['wpdb'] ) )->find_by_id( 10 ), 'query', array( 'body' => array( 'locations' => array() ) ) );
yd_geo_assert( YandexDeliveryGeoMappingStatus::NOT_FOUND === $normalized_empty[0]['status'] && null === $normalized_empty[0]['yandex_geo_id'], 'empty location/detect result must normalize to not_found with NULL geo_id.' );
foreach ( array( 'mapped', 'multiple_matches', 'not_found', 'manual', 'error' ) as $status ) {
	yd_geo_assert( $status === YandexDeliveryGeoMappingStatus::normalize( $status ), 'Status model must allow ' . $status . '.' );
}
$stats = $safety_repository->statistics();
yd_geo_assert( in_array( 'error', YandexDeliveryGeoMappingStatus::all(), true ), 'status model must include error.' );

echo "Yandex Delivery geo mapping smoke test passed.\n";