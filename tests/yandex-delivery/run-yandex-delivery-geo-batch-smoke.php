<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'WDC_SECRET_KEY' ) || define( 'WDC_SECRET_KEY', 'yandex-delivery-geo-batch-smoke-key' );
defined( 'WDC_PLUGIN_DIR' ) || define( 'WDC_PLUGIN_DIR', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once WDC_PLUGIN_DIR . 'src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', WDC_PLUGIN_DIR . 'src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingBatchService;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingRepository;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingService;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingStatus;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMatchScorer;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;

function yd_geo_batch_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-23 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'yandex-delivery-geo-batch-smoke-salt-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_yandex_delivery_geo_batch_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_yandex_delivery_geo_batch_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_yandex_delivery_geo_batch_options'][ $key ] ); return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function dbDelta( string|array $queries = '', bool $execute = true ): array { return array(); }

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

final class YdGeoBatchFakeHttp implements YandexDeliveryHttpClientInterface {
	/** @var array<int,YandexDeliveryApiResponse|YandexDeliveryApiException> */
	private array $queue;
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	public function __construct( YandexDeliveryApiResponse|YandexDeliveryApiException ...$responses ) {
		$this->queue = $responses;
	}

	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$this->calls[] = compact( 'method', 'url', 'args' );
		$next = array_shift( $this->queue ) ?? new YandexDeliveryApiResponse( 200, '{"locations":[]}' );
		if ( $next instanceof YandexDeliveryApiException ) {
			throw $next;
		}

		return $next;
	}
}

function yd_geo_batch_response( array $locations ): YandexDeliveryApiResponse {
	return new YandexDeliveryApiResponse( 200, json_encode( array( 'locations' => $locations ), JSON_UNESCAPED_UNICODE ) ?: '{}' );
}

function yd_geo_batch_location( int $id, string $name, string $region = 'Новосибирская область' ): array {
	return array( 'id' => $id, 'country_code' => 'RU', 'region_name' => $region, 'city_name' => $name, 'place_name' => $name, 'place_type' => 'г', 'display_name' => $region . ', г ' . $name, 'active' => 1 );
}

$GLOBALS['wdc_yandex_delivery_geo_batch_options'] = array();
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	yd_geo_batch_location( 1, 'Уже Основной' ),
	yd_geo_batch_location( 2, 'Новосибирск' ),
	yd_geo_batch_location( 3, 'Бердск' ),
	yd_geo_batch_location( 4, 'Не найден' ),
	yd_geo_batch_location( 5, 'Ошибка' ),
	array( 'id' => 6, 'country_code' => 'KZ', 'display_name' => 'Казахстан, Алматы', 'active' => 1 ),
	array( 'id' => 7, 'country_code' => 'RU', 'display_name' => '', 'active' => 1 ),
);

$batch_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingBatchService.php' );
$location_repository_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Locations/Storage/LocationRepository.php' );
$admin_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Core/Plugin.php' );
yd_geo_batch_assert( str_contains( $batch_source, 'detect_for_location_id' ), 'Batch service must use detect_for_location_id.' );
yd_geo_batch_assert( ! str_contains( $batch_source, 'locationDetect' ), 'Batch service must not call location/detect directly.' );
yd_geo_batch_assert( str_contains( $location_repository_source, 'find_batch_after_id(' ), 'LocationRepository source must contain find_batch_after_id().' );
yd_geo_batch_assert( str_contains( $location_repository_source, 'public function find_batch_after_id( int $after_id, int $limit, string $country_code = \'RU\', bool $require_display_name = true ): array' ), 'LocationRepository must expose the Yandex geo batch helper signature.' );
yd_geo_batch_assert( str_contains( $admin_source, "\$tabs['yandex_delivery_geo_batch'] = 'Yandex geo batch';" ) && str_contains( $admin_source, 'start_yandex_delivery_geo_batch' ) && str_contains( $admin_source, 'run_yandex_delivery_geo_batch_step' ), 'Admin UI must expose Yandex geo batch tab and actions.' );
yd_geo_batch_assert( str_contains( $plugin_source, 'YandexDeliveryGeoMappingBatchService::class' ), 'Plugin must register YandexDeliveryGeoMappingBatchService.' );
foreach ( array( 'CheckoutOrchestrator', 'pricing', 'pickupPointsList', 'YandexDeliveryPickupPointImportService' ) as $forbidden ) {
	yd_geo_batch_assert( ! str_contains( $batch_source, $forbidden ), 'Batch service must not call checkout/pricing/pickup import code: ' . $forbidden );
}

foreach ( array( '$wpdb', 'SELECT', 'FROM wp_wdc_locations' ) as $forbidden_sql ) {
	yd_geo_batch_assert( ! str_contains( $batch_source, $forbidden_sql ), 'Batch service must use LocationRepository instead of direct location SQL: ' . $forbidden_sql );
}

$GLOBALS['wpdb']->locations = array(
	yd_geo_batch_location( 10, 'Десять' ),
	yd_geo_batch_location( 20, 'Двадцать' ),
	yd_geo_batch_location( 30, 'Тридцать' ),
	yd_geo_batch_location( 40, 'Сорок' ),
);
$helper_repository = new LocationRepository( $GLOBALS['wpdb'] );
yd_geo_batch_assert( method_exists( $helper_repository, 'find_batch_after_id' ), 'LocationRepository instance must have find_batch_after_id().' );
$helper_batch = $helper_repository->find_batch_after_id( 20, 2 );
yd_geo_batch_assert( 2 === count( $helper_batch ) && 30 === $helper_batch[0]->id && 40 === $helper_batch[1]->id, 'find_batch_after_id(20, 2) must return Location objects for ids 30 and 40.' );
$GLOBALS['wpdb']->locations = array(
	array_merge( yd_geo_batch_location( 10, 'RU 10' ), array( 'country_code' => 'RU' ) ),
	array_merge( yd_geo_batch_location( 20, 'KZ 20' ), array( 'country_code' => 'KZ' ) ),
	array_merge( yd_geo_batch_location( 30, 'RU 30' ), array( 'country_code' => 'RU', 'display_name' => '' ) ),
	array_merge( yd_geo_batch_location( 40, 'RU 40' ), array( 'country_code' => 'RU' ) ),
);
$ru_rows = $helper_repository->find_batch_after_id( 0, 10, 'RU', false );
$kz_rows = $helper_repository->find_batch_after_id( 0, 10, 'KZ', false );
yd_geo_batch_assert( array( 10, 30, 40 ) === array_map( static fn( $location ): int => (int) $location->id, $ru_rows ), 'find_batch_after_id() must filter RU rows in test doubles.' );
yd_geo_batch_assert( array( 20 ) === array_map( static fn( $location ): int => (int) $location->id, $kz_rows ), 'find_batch_after_id() must filter KZ rows in test doubles.' );
$display_rows = $helper_repository->find_batch_after_id( 0, 10, 'RU', true );
yd_geo_batch_assert( array( 10, 40 ) === array_map( static fn( $location ): int => (int) $location->id, $display_rows ), 'find_batch_after_id() must exclude empty display_name when required.' );
$GLOBALS['wpdb']->locations = array(
	yd_geo_batch_location( 1, 'Уже Основной' ),
	yd_geo_batch_location( 2, 'Новосибирск' ),
	yd_geo_batch_location( 3, 'Бердск' ),
	yd_geo_batch_location( 4, 'Не найден' ),
	yd_geo_batch_location( 5, 'Ошибка' ),
	array( 'id' => 6, 'country_code' => 'KZ', 'display_name' => 'Казахстан, Алматы', 'active' => 1 ),
	array( 'id' => 7, 'country_code' => 'RU', 'display_name' => '', 'active' => 1 ),
);
$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'secret-test-token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'sender-1' ) );
$locations = new LocationRepository( $GLOBALS['wpdb'] );
$repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$repository->save_mapping( array( 'location_id' => 1, 'yandex_geo_id' => 1001, 'status' => YandexDeliveryGeoMappingStatus::MAPPED, 'confidence' => 100, 'is_primary' => 1 ) );
$http = new YdGeoBatchFakeHttp(
	yd_geo_batch_response( array( array( 'geo_id' => 65, 'locality' => 'Новосибирск', 'region' => 'Новосибирская область' ) ) ),
	yd_geo_batch_response( array( array( 'geo_id' => 101, 'locality' => 'Бердск', 'region' => 'Новосибирская область' ), array( 'geo_id' => 102, 'locality' => 'Бердск', 'region' => 'Новосибирская область' ) ) ),
	yd_geo_batch_response( array() ),
	new YandexDeliveryApiException( 'timeout', array( 'error_code' => 'timeout' ) )
);
$mapping_service = new YandexDeliveryGeoMappingService( $locations, new YandexDeliveryApiClient( $settings, $http ), $repository, new YandexDeliveryGeoMatchScorer() );
$batch = new YandexDeliveryGeoMappingBatchService( $locations, $repository, $mapping_service );

$state = $batch->start( 0, 0 );
yd_geo_batch_assert( 'running' === $state['status'] && 1 === $state['limit'] && 1 === $state['batch_size'] && '' !== $state['session_id'], 'start() must create running state and clamp low values.' );
$state = $batch->start( 20000, 500 );
yd_geo_batch_assert( 10000 === $state['limit'] && 100 === $state['batch_size'], 'start() must clamp high values.' );
$state = $batch->start( 20, 4 );
$state = $batch->run_step();
yd_geo_batch_assert( 'running' === $state['status'], 'First limited step must leave the batch running.' );
yd_geo_batch_assert( 4 === $state['last_location_id'] && 3 === $state['processed'] && 1 === $state['skipped_existing'], 'run_step() must process only batch_size locations and count skipped_existing.' );
yd_geo_batch_assert( 1 === $state['mapped'] && 1 === $state['ambiguous'] && 1 === $state['not_found'] && 0 === $state['errors'], 'run_step() must classify mapped, ambiguous and not_found results.' );
yd_geo_batch_assert( 3 === count( $http->calls ), 'Skipped primary mapping must not call detect_for_location_id/API.' );
yd_geo_batch_assert( 2 === (int) $state['confidence_buckets']['95_99'] && 1 === (int) $state['confidence_buckets']['0'], 'confidence_buckets must update by best confidence.' );
$state = $batch->run_step();
yd_geo_batch_assert( 1 === $state['errors'] && 1 === count( $state['errors_last'] ) && 5 === (int) $state['last_location_id'], 'run_step() must count API errors and store errors_last.' );
yd_geo_batch_assert( 4 === count( $http->calls ), 'Second step must continue from last_location_id and skip non-RU/empty display rows.' );
$state = $batch->pause();
yd_geo_batch_assert( 'paused' === $state['status'], 'pause() must set paused status.' );
$state = $batch->reset();
yd_geo_batch_assert( 'idle' === $state['status'] && 0 === $state['processed'] && array() === $state['errors_last'], 'reset() must clear state.' );

$GLOBALS['wdc_yandex_delivery_geo_batch_options'] = array();
$GLOBALS['wpdb']->locations = array(
	yd_geo_batch_location( 8, 'Тлюстенхабль', 'Республика Адыгея' ),
);
$GLOBALS['wpdb']->yandex_delivery_geo_mappings = array();
$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'secret-test-token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'sender-1' ) );
$weak_single_repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$weak_single_http = new YdGeoBatchFakeHttp(
	yd_geo_batch_response( array( array( 'geo_id' => 155, 'address' => 'Тлюстенхабль, Франция' ) ) )
);
$weak_single_service = new YandexDeliveryGeoMappingService( new LocationRepository( $GLOBALS['wpdb'] ), new YandexDeliveryApiClient( $settings, $weak_single_http ), $weak_single_repository, new YandexDeliveryGeoMatchScorer() );
$weak_single_batch = new YandexDeliveryGeoMappingBatchService( new LocationRepository( $GLOBALS['wpdb'] ), $weak_single_repository, $weak_single_service );
$state = $weak_single_batch->start( 1, 1 );
$state = $weak_single_batch->run_step();
yd_geo_batch_assert( 1 === $state['processed'] && 1 === $state['ambiguous'] && 0 === $state['errors'], 'single weak candidate must be classified as ambiguous without batch errors.' );

$GLOBALS['wdc_yandex_delivery_geo_batch_options'] = array();
$GLOBALS['wpdb']->locations = array();
$GLOBALS['wpdb']->yandex_delivery_geo_mappings = array();
$error_responses = array();
for ( $i = 10; $i < 22; ++$i ) {
	$GLOBALS['wpdb']->locations[] = yd_geo_batch_location( $i, 'Ошибка ' . $i );
	$error_responses[] = new YandexDeliveryApiException( 'error ' . $i, array( 'error_code' => 'error' ) );
}
$error_http = new YdGeoBatchFakeHttp( ...$error_responses );
$error_repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$error_service = new YandexDeliveryGeoMappingService( new LocationRepository( $GLOBALS['wpdb'] ), new YandexDeliveryApiClient( $settings, $error_http ), $error_repository, new YandexDeliveryGeoMatchScorer() );
$error_batch = new YandexDeliveryGeoMappingBatchService( new LocationRepository( $GLOBALS['wpdb'] ), $error_repository, $error_service );
$state = $error_batch->start( 12, 12 );
$state = $error_batch->run_step();
yd_geo_batch_assert( 12 === $state['processed'] && 12 === $state['errors'] && 10 === count( $state['errors_last'] ), 'errors_last must store only the last 10 errors.' );
yd_geo_batch_assert( 12 === (int) $state['confidence_buckets']['0'], 'Error results must increment zero confidence bucket.' );
yd_geo_batch_assert( 'success' === $state['status'], 'Batch must finish when processed + skipped_existing reaches limit.' );

echo "Yandex Delivery geo batch smoke OK\n";