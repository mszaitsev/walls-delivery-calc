<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'WDC_SECRET_KEY' ) || define( 'WDC_SECRET_KEY', 'yandex-delivery-geo-coverage-smoke-key' );
defined( 'WDC_PLUGIN_DIR' ) || define( 'WDC_PLUGIN_DIR', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once WDC_PLUGIN_DIR . 'src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', WDC_PLUGIN_DIR . 'src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoCoverageRepository;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoCoverageService;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoCoverageStatus;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingRepository;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingStatus;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

function yd_geo_coverage_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-23 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'yandex-delivery-geo-coverage-smoke-salt-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_yandex_delivery_geo_coverage_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_yandex_delivery_geo_coverage_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_yandex_delivery_geo_coverage_options'][ $key ] ); return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function dbDelta( string|array $queries = '', bool $execute = true ): array { return array(); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $yandex_delivery_geo_mappings = array();
		/** @var array<int,array<string,mixed>> */
		public array $yandex_delivery_geo_coverage = array();

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

final class YdGeoCoverageFakeHttp implements YandexDeliveryHttpClientInterface {
	/** @var array<int,YandexDeliveryApiResponse|YandexDeliveryApiException> */
	private array $queue;
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();

	public function __construct( YandexDeliveryApiResponse|YandexDeliveryApiException ...$responses ) {
		$this->queue = $responses;
	}

	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		$next = array_shift( $this->queue ) ?? new YandexDeliveryApiResponse( 200, '{"points":[]}' );
		if ( $next instanceof YandexDeliveryApiException ) {
			throw $next;
		}

		return $next;
	}
}

function yd_geo_coverage_api( YdGeoCoverageFakeHttp $http ): YandexDeliveryApiClient {
	$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
	$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'secret-test-token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'sender-1' ) );

	return new YandexDeliveryApiClient( $settings, $http );
}

function yd_geo_coverage_response( array $body ): YandexDeliveryApiResponse {
	return new YandexDeliveryApiResponse( 200, json_encode( $body, JSON_UNESCAPED_UNICODE ) ?: '{}' );
}

function yd_geo_coverage_point( string $id, string $operator, bool $dropoff, string $address ): array {
	return array( 'id' => $id, 'operator_id' => $operator, 'available_for_dropoff' => $dropoff, 'type' => 'pickup_point', 'address' => array( 'full_address' => $address ) );
}

$GLOBALS['wdc_yandex_delivery_geo_coverage_options'] = array();
$GLOBALS['wpdb'] = new wpdb();

$migration_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'database/migrations/0034_create_yandex_delivery_geo_coverage_table.php' );
$repository_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoCoverageRepository.php' );
yd_geo_coverage_assert( str_contains( $migration_source, 'YandexDeliveryGeoCoverageRepository' ), 'Migration must delegate schema creation to the coverage repository.' );
yd_geo_coverage_assert( str_contains( $repository_source, 'wdc_yandex_delivery_geo_coverage' ), 'Repository schema source must contain the Yandex geo coverage table.' );

$coverage_repo = new YandexDeliveryGeoCoverageRepository( $GLOBALS['wpdb'] );
$coverage_repo->save_result( array( 'location_id' => 10, 'yandex_geo_id' => 100, 'coverage_status' => YandexDeliveryGeoCoverageStatus::COVERED, 'pickup_points_count' => 2 ) );
$coverage_repo->save_result( array( 'location_id' => 10, 'yandex_geo_id' => 101, 'coverage_status' => YandexDeliveryGeoCoverageStatus::NOT_COVERED, 'pickup_points_count' => 0 ) );
yd_geo_coverage_assert( 1 === count( $GLOBALS['wpdb']->yandex_delivery_geo_coverage ), 'save_result() must upsert by location_id without duplicates.' );
yd_geo_coverage_assert( 101 === (int) $coverage_repo->find_by_location_id( 10 )['yandex_geo_id'], 'save_result() must update the existing location row.' );
$coverage_repo->save_result( array( 'location_id' => 11, 'coverage_status' => YandexDeliveryGeoCoverageStatus::NO_GEO_ID ) );
$coverage_repo->save_result( array( 'location_id' => 12, 'coverage_status' => YandexDeliveryGeoCoverageStatus::ERROR ) );
$stats = $coverage_repo->stats();
yd_geo_coverage_assert( 1 === $stats['not_covered'] && 1 === $stats['no_geo_id'] && 1 === $stats['error'] && 0 === $stats['covered'], 'stats() must count coverage statuses after upsert.' );

yd_geo_coverage_assert( YandexDeliveryGeoCoverageStatus::UNKNOWN === YandexDeliveryGeoCoverageStatus::normalize( 'bad' ), 'Coverage status normalize must fallback to unknown.' );

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->yandex_delivery_geo_mappings = array( array( 'id' => 1, 'location_id' => 20, 'yandex_geo_id' => null, 'status' => YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, 'is_primary' => 0 ) );
$no_geo_http = new YdGeoCoverageFakeHttp();
$no_geo_service = new YandexDeliveryGeoCoverageService( yd_geo_coverage_api( $no_geo_http ), new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] ), new YandexDeliveryGeoCoverageRepository( $GLOBALS['wpdb'] ) );
$no_geo = $no_geo_service->check_location( 20 );
yd_geo_coverage_assert( YandexDeliveryGeoCoverageStatus::NO_GEO_ID === $no_geo['coverage_status'], 'Missing primary geo_id must produce no_geo_id.' );
yd_geo_coverage_assert( 'needs_review' === $no_geo['source_status'], 'no_geo_id result must keep mapping source status when available.' );
yd_geo_coverage_assert( 0 === count( $no_geo_http->requests ), 'no_geo_id must not call Yandex API.' );

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->yandex_delivery_geo_mappings = array( array( 'id' => 1, 'location_id' => 30, 'yandex_geo_id' => 213, 'status' => YandexDeliveryGeoMappingStatus::MAPPED, 'is_primary' => 1 ) );
$covered_points = array(
	yd_geo_coverage_point( 'p1', '5post', true, 'Москва, ул 1' ),
	yd_geo_coverage_point( 'p2', '5post', false, 'Москва, ул 2' ),
	yd_geo_coverage_point( 'p3', 'market_l4g', false, 'Москва, ул 3' ),
);
$covered_http = new YdGeoCoverageFakeHttp( yd_geo_coverage_response( array( 'points' => $covered_points ) ) );
$covered_service = new YandexDeliveryGeoCoverageService( yd_geo_coverage_api( $covered_http ), new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] ), new YandexDeliveryGeoCoverageRepository( $GLOBALS['wpdb'] ) );
$covered = $covered_service->check_location( 30 );
$operators = json_decode( (string) $covered['operators_json'], true );
$sample = json_decode( (string) $covered['sample_points_json'], true );
$payload = json_decode( (string) $covered_http->requests[0]['args']['body'], true );
yd_geo_coverage_assert( YandexDeliveryGeoCoverageStatus::COVERED === $covered['coverage_status'], 'Non-empty pickup-points/list response must produce covered.' );
yd_geo_coverage_assert( 3 === (int) $covered['pickup_points_count'] && 1 === (int) $covered['dropoff_points_count'], 'covered result must count all returned points and dropoff points.' );
yd_geo_coverage_assert( 2 === $operators['5post'] && 1 === $operators['market_l4g'], 'operators_json must aggregate operator_id values.' );
yd_geo_coverage_assert( 3 === count( $sample ) && true === $sample[0]['dropoff'] && 'Москва, ул 1' === $sample[0]['address'], 'sample_points_json must store compact point samples.' );
yd_geo_coverage_assert( array( 'type' => 'pickup_point', 'geo_id' => 213 ) === $payload, 'pickup-points/list payload must contain integer geo_id and no pagination fields.' );
yd_geo_coverage_assert( ! array_key_exists( 'limit', $payload ), 'coverage payload must not contain limit.' );

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->yandex_delivery_geo_mappings = array( array( 'id' => 1, 'location_id' => 31, 'yandex_geo_id' => 214, 'status' => YandexDeliveryGeoMappingStatus::MAPPED, 'is_primary' => 1 ) );
$many_points = array();
for ( $i = 1; $i <= 6; ++$i ) {
	$many_points[] = yd_geo_coverage_point( 's' . $i, '5post', false, 'Москва, ул ' . $i );
}
$many_http = new YdGeoCoverageFakeHttp( yd_geo_coverage_response( array( 'items' => $many_points ) ) );
$many = ( new YandexDeliveryGeoCoverageService( yd_geo_coverage_api( $many_http ), new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] ), new YandexDeliveryGeoCoverageRepository( $GLOBALS['wpdb'] ) ) )->check_location( 31 );
$many_sample = json_decode( (string) $many['sample_points_json'], true );
yd_geo_coverage_assert( 5 === count( $many_sample ), 'sample_points_json must be limited to first 5 points.' );
yd_geo_coverage_assert( ! str_contains( (string) $many['raw_stats_json'], 'ул 6' ), 'raw_stats_json must not store the full raw response.' );

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->yandex_delivery_geo_mappings = array( array( 'id' => 1, 'location_id' => 40, 'yandex_geo_id' => 777, 'status' => YandexDeliveryGeoMappingStatus::MAPPED, 'is_primary' => 1 ) );
$empty = ( new YandexDeliveryGeoCoverageService( yd_geo_coverage_api( new YdGeoCoverageFakeHttp( yd_geo_coverage_response( array( 'result' => array() ) ) ) ), new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] ), new YandexDeliveryGeoCoverageRepository( $GLOBALS['wpdb'] ) ) )->check_location( 40 );
yd_geo_coverage_assert( YandexDeliveryGeoCoverageStatus::NOT_COVERED === $empty['coverage_status'] && 0 === (int) $empty['pickup_points_count'] && 0 === (int) $empty['dropoff_points_count'], 'Empty successful response must produce not_covered with zero counts.' );

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->yandex_delivery_geo_mappings = array( array( 'id' => 1, 'location_id' => 50, 'yandex_geo_id' => 888, 'status' => YandexDeliveryGeoMappingStatus::MAPPED, 'is_primary' => 1 ) );
$error = ( new YandexDeliveryGeoCoverageService( yd_geo_coverage_api( new YdGeoCoverageFakeHttp( new YandexDeliveryApiException( 'API exploded' ) ) ), new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] ), new YandexDeliveryGeoCoverageRepository( $GLOBALS['wpdb'] ) ) )->check_location( 50 );
yd_geo_coverage_assert( YandexDeliveryGeoCoverageStatus::ERROR === $error['coverage_status'] && str_contains( (string) $error['message'], 'API exploded' ), 'API exception must produce error status and message.' );

$service_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoCoverageService.php' );
$admin_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Core/Plugin.php' );
$version_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'walls-delivery-calc.php' );

yd_geo_coverage_assert( str_contains( $service_source, 'find_primary_geo_id( $location_id )' ), 'check_location() must use find_primary_geo_id().' );
$no_geo_block = substr( $service_source, strpos( $service_source, 'if ( null === $geo_id )' ) ?: 0, 500 );
yd_geo_coverage_assert( '' !== $no_geo_block && ! str_contains( $no_geo_block, 'pickupPointsList' ), 'no_geo_id branch must not call Yandex API.' );
yd_geo_coverage_assert( str_contains( $service_source, '\'geo_id\' => (int) $geo_id' ), 'coverage payload must include integer geo_id.' );
yd_geo_coverage_assert( ! str_contains( $service_source, "'limit'" ) && ! str_contains( $service_source, 'page_token' ), 'coverage service must not use pagination fields.' );
yd_geo_coverage_assert( str_contains( $service_source, 'count( $sample ) < 5' ), 'coverage service must cap sample_points_json at 5.' );
yd_geo_coverage_assert( ! str_contains( $service_source, 'raw_response' ) && ! str_contains( $service_source, '\'response\' => $response' ), 'coverage service must not persist full raw response.' );
yd_geo_coverage_assert( str_contains( $admin_source, "\$tabs['yandex_delivery_geo_coverage'] = 'Покрытие Яндекса';" ) && ! str_contains( $admin_source, "\$tabs['yandex_delivery_geo_batch']" ) && ! str_contains( $admin_source, "\$tabs['yandex_delivery_geo_analysis']" ), 'Admin UI must expose Yandex coverage tab and hide old technical Yandex tabs.' );
yd_geo_coverage_assert( str_contains( $admin_source, 'check_yandex_delivery_geo_coverage' ) && str_contains( $admin_source, 'Проверить покрытие' ), 'Admin UI must handle coverage check action.' );
yd_geo_coverage_assert( str_contains( $admin_source, 'yandex_delivery_geo_coverage_location_query' ) && str_contains( $admin_source, 'Найти населённый пункт' ), 'Coverage tab must expose location name search.' );
yd_geo_coverage_assert( str_contains( $admin_source, "'check_yandex_delivery_geo_coverage' => 'yandex_delivery_geo_coverage'" ), 'Coverage action must redirect back to the coverage tab.' );
yd_geo_coverage_assert( str_contains( $plugin_source, 'YandexDeliveryGeoCoverageRepository::class' ) && str_contains( $plugin_source, 'YandexDeliveryGeoCoverageService::class' ), 'Plugin DI must register coverage repository and service.' );
yd_geo_coverage_assert( str_contains( $version_source, '0.90.1' ), 'Plugin version must be 0.90.1.' );

echo "Yandex Delivery geo coverage smoke OK\n";
