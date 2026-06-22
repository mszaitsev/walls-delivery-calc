<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointImportService;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointNormalizer;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointRepository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointService;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

function yd_pickup_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-22 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'yandex-delivery-pickup-smoke-salt-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_yandex_delivery_pickup_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_yandex_delivery_pickup_options'][ $key ] = $value; return true; }
function add_option( string $key, mixed $value = '', string $deprecated = '', string $autoload = 'yes' ): bool { if ( array_key_exists( $key, $GLOBALS['wdc_yandex_delivery_pickup_options'] ) ) { return false; } $GLOBALS['wdc_yandex_delivery_pickup_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_yandex_delivery_pickup_options'][ $key ] ); return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function dbDelta( string|array $queries = '', bool $execute = true ): array { ++$GLOBALS['wdc_yandex_delivery_pickup_dbdelta_calls']; return array(); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $yandex_delivery_pickup_points = array();

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

final class YdPickupFakeHttp implements YandexDeliveryHttpClientInterface {
	/** @var array<int,YandexDeliveryApiResponse|YandexDeliveryApiException> */
	private array $queue;
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();

	public function __construct( YandexDeliveryApiResponse|YandexDeliveryApiException ...$responses ) {
		$this->queue = $responses;
	}

	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$this->calls[] = compact( 'method', 'url', 'args' );
		$next = array_shift( $this->queue ) ?? new YandexDeliveryApiResponse( 200, json_encode( array( 'points' => array() ) ) ?: '{}' );
		if ( $next instanceof YandexDeliveryApiException ) {
			throw $next;
		}

		return $next;
	}
}

$GLOBALS['wdc_yandex_delivery_pickup_options'] = array();
$GLOBALS['wdc_yandex_delivery_pickup_dbdelta_calls'] = 0;
$GLOBALS['wpdb'] = new wpdb();

$migration_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0032_create_yandex_delivery_pickup_points_table.php' );
$repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointRepository.php' );
$import_service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointImportService.php' );
yd_pickup_assert( str_contains( $migration_source, 'YandexDeliveryPickupPointRepository' ) && str_contains( $repository_source, 'wdc_yandex_delivery_pickup_points' ), 'Migration must create Yandex pickup points table through the repository.' );
$schema_start = strpos( $repository_source, 'public function create_schema_if_needed' );
$schema_end = strpos( $repository_source, 'public function mark_all_inactive' );
$schema_block = false !== $schema_start && false !== $schema_end ? substr( $repository_source, $schema_start, $schema_end - $schema_start ) : '';
yd_pickup_assert( '' !== $schema_block && str_contains( $repository_source, 'function can_create_schema' ) && ! str_contains( $schema_block, 'has_test_rows' ), 'create_schema_if_needed must not use has_test_rows as its early return condition.' );
yd_pickup_assert( ! str_contains( $import_service_source, '$responses =' ), 'Import service must not collect all API responses in memory.' );
yd_pickup_assert( ! str_contains( $import_service_source, 'download_all_pages()' ), 'Import service must not use a download_all_pages accumulator.' );
yd_pickup_assert( ! str_contains( $import_service_source, '$points = array_merge( $points' ), 'Import service must not merge every page into a global points array.' );
foreach ( array( 'platform_station_id', 'operator_id', 'operator_name', 'available_for_dropoff', 'available_for_c2c_dropoff', 'is_yandex_branded', 'raw_json', 'imported_at' ) as $column ) {
	yd_pickup_assert( str_contains( $repository_source, $column ), 'Repository schema must include column ' . $column . '.' );
}
foreach ( array( 'UNIQUE KEY platform_station_id', 'KEY type', 'KEY geo_id', 'KEY city_name', 'KEY available_for_dropoff', 'KEY is_active' ) as $index ) {
	yd_pickup_assert( str_contains( $repository_source, $index ), 'Repository schema must include index ' . $index . '.' );
}

$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin(
	array(
		YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST,
		'yandex_delivery_test_bearer_token' => 'secret-test-token',
		YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'sender-1',
	)
);

$normalizer = new YandexDeliveryPickupPointNormalizer();
$full = $normalizer->normalize(
	array(
		'id' => 'sender-1',
		'operator' => array( 'id' => 'op-1', 'name' => 'Партнер' ),
		'name' => 'ПВЗ Яндекс',
		'type' => 'pickup_point',
		'address' => array( 'country_code' => 'RU', 'region_name' => 'Новосибирская обл.', 'city_name' => 'Новосибирск', 'street' => 'Ленина', 'house' => '1' ),
		'geo_id' => '65',
		'coordinates' => array( 'lat' => '55.030199', 'lon' => '82.920430' ),
		'schedule' => array( array( 'days' => 'Пн-Вс', 'time' => '10:00-21:00' ) ),
		'payment_methods' => array( 'already_paid' ),
		'available_for_dropoff' => true,
		'available_for_c2c_dropoff' => false,
		'is_yandex_branded' => true,
	)
);
yd_pickup_assert( null !== $full && 'sender-1' === $full['platform_station_id'] && true === $full['available_for_dropoff'], 'Normalizer must normalize full Yandex pickup point object.' );
yd_pickup_assert( str_contains( (string) $full['raw_json'], 'ПВЗ Яндекс' ), 'Normalizer must preserve full raw_json.' );

$partial = $normalizer->normalize( array( 'platform_station_id' => 'partial-1' ) );
yd_pickup_assert( null !== $partial && 'pickup_point' === $partial['type'] && 'partial-1' === $partial['name'], 'Normalizer must survive incomplete objects with safe defaults.' );
yd_pickup_assert( null === $normalizer->normalize( array( 'name' => 'broken' ) ), 'Normalizer must skip objects without platform_station_id.' );

$repository = new YandexDeliveryPickupPointRepository( $GLOBALS['wpdb'] );
$repository->create_schema_if_needed();
yd_pickup_assert( 0 === $GLOBALS['wdc_yandex_delivery_pickup_dbdelta_calls'], 'Fake repository create_schema_if_needed must not call real dbDelta.' );
$global_repository = new YandexDeliveryPickupPointRepository();
$global_repository->create_schema_if_needed();
yd_pickup_assert( 0 === $GLOBALS['wdc_yandex_delivery_pickup_dbdelta_calls'], 'Repository without constructor argument must use global fake wpdb and still avoid dbDelta in smoke.' );
yd_pickup_assert( is_array( $global_repository->search( array( 'limit' => 1 ) ) ), 'Repository without constructor argument must keep a usable wpdb object.' );
$save = $repository->save_batch(
	array(
		$full,
		array_merge( $full, array( 'platform_station_id' => 'sender-1', 'address' => 'updated address' ) ),
		$partial,
		array( 'name' => 'broken' ),
	),
	'2026-06-22 12:00:00'
);
yd_pickup_assert( 3 === $save['saved'] && 1 === $save['skipped_invalid'], 'save_batch must save/update valid rows and skip invalid rows.' );
yd_pickup_assert( 2 === count( $GLOBALS['wpdb']->yandex_delivery_pickup_points ), 'Fake wpdb CRUD storage must be used when fake object is passed explicitly.' );
yd_pickup_assert( 0 === $repository->count_active(), 'Saved rows must remain inactive until activate_imported_points().' );
$activated = $repository->activate_imported_points( '2026-06-22 12:00:00' );
yd_pickup_assert( 2 === $activated && 2 === $repository->count_active(), 'activate_imported_points must reactivate only imported rows.' );
yd_pickup_assert( 'updated address' === (string) ( $repository->find_by_platform_station_id( 'sender-1' )['address'] ?? '' ), 'unique platform_station_id must update existing point.' );
yd_pickup_assert( 1 === count( $repository->search( array( 'city_name' => 'Новосиб', 'limit' => 20 ) ) ), 'search must filter by city_name.' );
yd_pickup_assert( 2 === ( $repository->count_by_type()['pickup_point'] ?? 0 ), 'count_by_type must count active pickup points.' );
$inactive = $repository->mark_all_inactive();
yd_pickup_assert( 2 === $inactive && 0 === $repository->count_active(), 'mark_all_inactive must mark active rows inactive.' );
$repository->activate_imported_points( '2026-06-22 12:00:00' );

$service = new YandexDeliveryPickupPointService( $repository, $settings );
$sender = $service->validate_sender_point();
yd_pickup_assert( true === $sender['found'] && true === $sender['valid'] && 'Новосибирск' === (string) ( $sender['point']['city_name'] ?? '' ), 'sender point validation must find active dropoff sender point.' );
$repository->save_batch( array( array_merge( $full, array( 'platform_station_id' => 'sender-2', 'available_for_dropoff' => false ) ) ), '2026-06-22 12:01:00' );
$repository->activate_imported_points( '2026-06-22 12:01:00' );
$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'sender-2' ) );
$sender_unavailable = ( new YandexDeliveryPickupPointService( $repository, $settings ) )->validate_sender_point();
yd_pickup_assert( true === $sender_unavailable['found'] && false === $sender_unavailable['valid'], 'sender validation must fail when available_for_dropoff=false.' );

$GLOBALS['wpdb']->yandex_delivery_pickup_points = array( array( 'id' => 1, 'platform_station_id' => 'old-1', 'type' => 'pickup_point', 'city_name' => 'Омск', 'available_for_dropoff' => 1, 'is_active' => 1 ) );
$http = new YdPickupFakeHttp(
	new YandexDeliveryApiResponse( 200, json_encode( array( 'points' => array( array( 'id' => 'api-1', 'type' => 'pickup_point', 'city_name' => 'Москва', 'available_for_dropoff' => false ) ), 'next_page_token' => 'page-2' ), JSON_UNESCAPED_UNICODE ) ?: '{}' ),
	new YandexDeliveryApiResponse( 200, json_encode( array( 'points' => array( array( 'id' => 'api-2', 'type' => 'pickup_point', 'city_name' => 'Казань', 'available_for_dropoff' => true ) ) ), JSON_UNESCAPED_UNICODE ) ?: '{}' )
);
$importer = new YandexDeliveryPickupPointImportService( new YandexDeliveryApiClient( $settings, $http ), $normalizer, new YandexDeliveryPickupPointRepository( $GLOBALS['wpdb'] ), $settings );
$report = $importer->import_all();
yd_pickup_assert( 'success' === $report['status'] && 2 === $report['fetched'] && 2 === $report['normalized'] && 2 === $report['saved'] && 1 === $report['inactive'], 'Import statistics must include fetched/normalized/saved/inactive counts.' );
yd_pickup_assert( 2 === count( $http->calls ), 'Importer must request each pickup-points/list page separately.' );
$first_payload = json_decode( (string) $http->calls[0]['args']['body'], true );
$second_payload = json_decode( (string) $http->calls[1]['args']['body'], true );
yd_pickup_assert( array( 'type' => 'pickup_point', 'limit' => 1000 ) === $first_payload && ! array_key_exists( 'page_token', $first_payload ), 'First importer request must not include page_token.' );
yd_pickup_assert( 'page-2' === (string) ( $second_payload['page_token'] ?? '' ), 'Second importer request must include page_token=page-2.' );
yd_pickup_assert( 2 === ( new YandexDeliveryPickupPointRepository( $GLOBALS['wpdb'] ) )->count_active(), 'Importer must activate imported points and keep old points inactive.' );
yd_pickup_assert( null !== ( new YandexDeliveryPickupPointRepository( $GLOBALS['wpdb'] ) )->find_by_platform_station_id( 'api-1' ), 'Importer must not drop points with available_for_dropoff=false.' );
yd_pickup_assert( 'success' === (string) ( $settings->last_pickup_import_report()['status'] ?? '' ), 'Import service must store last import report.' );
$GLOBALS['wdc_yandex_delivery_pickup_options']['wdc_yandex_delivery_pickup_import_lock'] = array( 'token' => 'stuck', 'expires' => time() + 3600 );
$importer->reset_lock();
yd_pickup_assert( ! isset( $GLOBALS['wdc_yandex_delivery_pickup_options']['wdc_yandex_delivery_pickup_import_lock'] ), 'Import reset_lock must delete a running pickup import lock regardless of TTL.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
yd_pickup_assert( str_contains( $admin_source, "\$tabs['yandex_delivery_pickup'] = 'ПВЗ / точки сдачи';" ), 'Admin page must register Yandex pickup tab.' );
yd_pickup_assert( str_contains( $admin_source, 'run_yandex_delivery_pickup_import' ) && str_contains( $admin_source, 'Импортировать точки Яндекс' ), 'Admin page must expose manual Yandex pickup import button.' );
yd_pickup_assert( str_contains( $admin_source, 'reset_lock()' ) && str_contains( $admin_source, 'Сбросить результат и lock' ), 'Admin reset action must clear Yandex pickup report and import lock.' );
yd_pickup_assert( ! preg_match( '/yandex_delivery.*(cron|autosync)|YandexDelivery.*(Cron|AutoSync)/i', $plugin_source ), 'Yandex pickup stage must not register cron/autosync.' );
yd_pickup_assert( ! str_contains( $plugin_source, 'YandexDeliveryQuoteCarrier' ) && ! str_contains( $plugin_source, 'offers/create' ), 'Yandex pickup stage must not add checkout quote or shipment creation.' );

echo "Yandex Delivery pickup points smoke test passed.\n";
