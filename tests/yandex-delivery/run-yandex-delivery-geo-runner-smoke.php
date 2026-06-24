<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'WDC_SECRET_KEY' ) || define( 'WDC_SECRET_KEY', 'yandex-delivery-geo-runner-smoke-key' );
defined( 'WDC_PLUGIN_DIR' ) || define( 'WDC_PLUGIN_DIR', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once WDC_PLUGIN_DIR . 'src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', WDC_PLUGIN_DIR . 'src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingRepository;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingRunnerService;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingService;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMappingStatus;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoMatchScorer;
use WallsShop\WDC\Carriers\YandexDelivery\Geo\YandexDeliveryGeoResolutionPolicy;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;

function yd_geo_runner_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { static $i = 0; return gmdate( 'Y-m-d H:i:s', strtotime( '2026-06-24 12:00:00' ) + $i++ ); }
function wp_salt( string $scheme = '' ): string { return 'yandex-delivery-geo-runner-smoke-salt-' . $scheme; }
function wp_generate_uuid4(): string { static $i = 0; ++$i; return 'runner-session-' . $i; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_yandex_delivery_geo_runner_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_yandex_delivery_geo_runner_options'][ $key ] = $value; return true; }
function add_option( string $key, mixed $value = '', string $deprecated = '', bool|string $autoload = 'yes' ): bool { if ( array_key_exists( $key, $GLOBALS['wdc_yandex_delivery_geo_runner_options'] ) ) { return false; } $GLOBALS['wdc_yandex_delivery_geo_runner_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_yandex_delivery_geo_runner_options'][ $key ] ); return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function dbDelta( string|array $queries = '', bool $execute = true ): array { return array(); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $locations = array();
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

final class YdGeoRunnerFakeHttp implements YandexDeliveryHttpClientInterface {
	public array $calls = array();

	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$this->calls[] = compact( 'method', 'url', 'args' );
		$payload = json_decode( (string) ( $args['body'] ?? '{}' ), true );
		$query = (string) ( $payload['location'] ?? '' );
		if ( str_contains( $query, 'Retry Error' ) || str_contains( $query, 'Ошибка' ) ) {
			throw new YandexDeliveryApiException( 'timeout for ' . $query, array( 'error_code' => 'timeout' ) );
		}
		if ( str_contains( $query, 'Retry Empty' ) || str_contains( $query, 'Пусто' ) ) {
			return new YandexDeliveryApiResponse( 200, '{"locations":[]}' );
		}
		$geo_id = 100000 + count( $this->calls );
		if ( str_contains( $query, 'Retry Success' ) ) {
			$geo_id = 501;
		}
		$locality = preg_replace( '/^.*г\s+/u', '', $query ) ?: $query;
		return new YandexDeliveryApiResponse( 200, json_encode( array( 'locations' => array( array( 'geo_id' => $geo_id, 'locality' => $locality, 'region' => 'Новосибирская область' ) ) ), JSON_UNESCAPED_UNICODE ) ?: '{}' );
	}
}

function yd_geo_runner_location( int $id, string $name, string $country = 'RU', bool $active = true, string $display = 'auto' ): array {
	$display_name = 'auto' === $display ? 'Новосибирская область, г ' . $name : $display;
	return array( 'id' => $id, 'country_code' => $country, 'region_name' => 'Новосибирская область', 'city_name' => $name, 'place_name' => $name, 'place_type' => 'г', 'display_name' => $display_name, 'active' => $active ? 1 : 0 );
}

function yd_geo_runner_service( wpdb $wpdb, YdGeoRunnerFakeHttp $http ): YandexDeliveryGeoMappingRunnerService {
	$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
	$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'secret-test-token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'sender-1' ) );
	$repository = new YandexDeliveryGeoMappingRepository( $wpdb );
	$mapping_service = new YandexDeliveryGeoMappingService( new LocationRepository( $wpdb ), new YandexDeliveryApiClient( $settings, $http ), $repository, new YandexDeliveryGeoMatchScorer(), new YandexDeliveryGeoResolutionPolicy() );
	return new YandexDeliveryGeoMappingRunnerService( new LocationRepository( $wpdb ), $repository, $mapping_service );
}

$GLOBALS['wdc_yandex_delivery_geo_runner_options'] = array();
$GLOBALS['wpdb'] = new wpdb();
for ( $i = 1; $i <= 120; ++$i ) {
	$GLOBALS['wpdb']->locations[] = yd_geo_runner_location( $i, 'Город ' . $i );
}
$GLOBALS['wpdb']->locations[] = yd_geo_runner_location( 30, 'KZ City', 'KZ' );
$GLOBALS['wpdb']->locations[] = yd_geo_runner_location( 31, 'No Display', 'RU', true, '' );
$repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$repository->save_mapping( array( 'location_id' => 1, 'yandex_geo_id' => 65, 'status' => YandexDeliveryGeoMappingStatus::MAPPED, 'confidence' => 100, 'is_primary' => 1 ) );
$http = new YdGeoRunnerFakeHttp();
$runner = yd_geo_runner_service( $GLOBALS['wpdb'], $http );
$state = $runner->start_full();
yd_geo_runner_assert( 'full' === $state['mode'] && 'running' === $state['status'] && 120 === (int) $state['total_estimated'] && 50 === (int) $state['batch_size'] && array_key_exists( 'eta_finished_at', $state ) && '' === (string) $state['eta_finished_at'], 'start_full() must start full mode with fixed batch size 50, ETA fields and full estimated total.' );
$state = $runner->run_step( (string) $state['session_id'] );
yd_geo_runner_assert( 50 === (int) $state['next_location_id'] && 50 === (int) $state['processed'] && 50 === count( $http->calls ), 'Single worker first step must reserve and process location_id 1..50.' );
yd_geo_runner_assert( '' !== (string) $state['eta_finished_at'] && (float) $state['average_locations_per_second'] > 0 && (int) $state['elapsed_seconds'] > 0 && (int) $state['remaining_seconds'] >= 0, 'After delta with processed rows, runner state must expose ETA, average speed, elapsed and remaining seconds.' );
yd_geo_runner_assert( 65 !== $repository->find_primary_geo_id( 1 ), 'Full runner must rebuild an existing primary mapping instead of skipping it.' );
yd_geo_runner_assert( ! array_key_exists( 'skipped_existing', $state ), 'Runner state must not expose skipped_existing.' );
$runner_after_refresh = yd_geo_runner_service( $GLOBALS['wpdb'], $http );
$state_after_refresh = $runner_after_refresh->current_state();
yd_geo_runner_assert( 50 === (int) $state_after_refresh['next_location_id'] && 50 === (int) $state_after_refresh['processed'], 'current_state() must preserve reservation progress after refresh.' );
$paused = $runner_after_refresh->pause();
$resumed = $runner_after_refresh->start_full();
yd_geo_runner_assert( 'running' === (string) $resumed['status'] && (string) $state_after_refresh['session_id'] === (string) $resumed['session_id'] && 50 === (int) $resumed['next_location_id'] && 50 === (int) $resumed['processed'] && 'Маппинг продолжен.' === (string) $resumed['message'], 'start_full() from paused full state must resume without resetting cursor, counters or session.' );
$state = $runner_after_refresh->run_step( (string) $resumed['session_id'] );
yd_geo_runner_assert( 100 === (int) $state['next_location_id'] && 100 === (int) $state['processed'], 'Single worker second step must continue with location_id 51..100.' );
$state = $runner_after_refresh->run_step( (string) $state['session_id'] );
yd_geo_runner_assert( 120 === (int) $state['next_location_id'] && 120 === (int) $state['processed'], 'Single worker third step must finish remaining locations 101..120.' );
$state = $runner_after_refresh->run_step( (string) $state['session_id'] );
yd_geo_runner_assert( 'done' === $state['status'], 'Full runner must finish as done when locations are exhausted.' );
$finished_session = (string) $state['session_id'];
$reset_state = $runner_after_refresh->reset();
$new_full = $runner_after_refresh->start_full();
yd_geo_runner_assert( 'running' === (string) $new_full['status'] && (string) $new_full['session_id'] !== $finished_session && 0 === (int) $new_full['next_location_id'] && 0 === (int) $new_full['processed'], 'After reset, start_full() must create a new full session from the beginning.' );

$GLOBALS['wdc_yandex_delivery_geo_runner_options'] = array();
$GLOBALS['wpdb'] = new wpdb();
for ( $i = 1; $i <= 120; ++$i ) {
	$GLOBALS['wpdb']->locations[] = yd_geo_runner_location( $i, 'Pause Race ' . $i );
}
$http = new YdGeoRunnerFakeHttp();
$runner = yd_geo_runner_service( $GLOBALS['wpdb'], $http );
$state = $runner->start_full();
$state = $runner->run_step( (string) $state['session_id'] );
$paused = $runner->pause();
yd_geo_runner_assert( 'paused' === (string) $paused['status'] && 'Маппинг поставлен на паузу.' === (string) $paused['message'], 'pause() must return fresh paused state.' );
$apply_delta = new ReflectionMethod( YandexDeliveryGeoMappingRunnerService::class, 'apply_step_delta' );
$apply_delta->setAccessible( true );
$late = $apply_delta->invoke( $runner, (string) $paused['session_id'], array( 'processed' => 5, 'mapped' => 2, 'needs_review' => 1, 'not_found' => 1, 'tech_errors' => 1, 'errors_last' => array( array( 'location_id' => 55, 'message' => 'late timeout', 'checked_at' => '2026-06-24 12:00:00' ) ) ) );
yd_geo_runner_assert( 'paused' === (string) $late['status'] && 'Маппинг поставлен на паузу.' === (string) $late['message'] && 55 === (int) $late['processed'] && 52 === (int) $late['mapped'] && 50 === (int) $late['next_location_id'], 'Late apply_step_delta() after pause must add counters but keep paused status/message and reservation cursor.' );
$after_paused_step = $runner->run_step( (string) $late['session_id'] );
yd_geo_runner_assert( 'paused' === (string) $after_paused_step['status'] && 55 === (int) $after_paused_step['processed'], 'run_step() after pause must not reserve or process another batch.' );

$GLOBALS['wdc_yandex_delivery_geo_runner_options'] = array();
$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	yd_geo_runner_location( 101, 'Retry Success' ),
	yd_geo_runner_location( 102, 'Retry Empty' ),
	yd_geo_runner_location( 103, 'Retry Error' ),
	yd_geo_runner_location( 104, 'Normal City' ),
);
$repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$repository->save_technical_error_marker( 101, 'Retry Success', 'old timeout' );
$repository->save_technical_error_marker( 102, 'Retry Empty', 'old timeout' );
$repository->save_technical_error_marker( 103, 'Retry Error', 'old timeout' );
$http = new YdGeoRunnerFakeHttp();
$runner = yd_geo_runner_service( $GLOBALS['wpdb'], $http );
$state = $runner->retry_errors();
yd_geo_runner_assert( 'retry_errors' === $state['mode'] && 3 === (int) $state['total_estimated'] && 3 === (int) $state['technical_error_markers_count'] && 0 === (int) $state['tech_errors'], 'retry_errors() must estimate only technical marker rows and expose persistent marker count separately from session tech_errors.' );
$state = $runner->run_step( (string) $state['session_id'] );
yd_geo_runner_assert( 3 === count( $http->calls ) && 103 === (int) $state['next_location_id'] && 1 === (int) $state['mapped'] && 1 === (int) $state['not_found'] && 1 === (int) $state['tech_errors'] && 1 === (int) $state['technical_error_markers_count'], 'Retry runner must process only marker rows, classify success/not_found/new error, and refresh persistent marker count.' );
yd_geo_runner_assert( 501 === $repository->find_primary_geo_id( 101 ), 'Successful retry must replace marker with correct primary geo_id.' );
yd_geo_runner_assert( null === $repository->find_primary_geo_id( 102 ) && YandexDeliveryGeoMappingStatus::NOT_FOUND === (string) $repository->find_by_location_id( 102 )[0]['status'], 'Retry not_found must clear marker and save normal not_found row.' );
yd_geo_runner_assert( array( 103 ) === $repository->find_technical_error_location_ids_after( 0, 50 ), 'Retry technical error must keep marker for another retry.' );
$state = $runner->run_step( (string) $state['session_id'] );
yd_geo_runner_assert( 'done' === $state['status'], 'Retry runner must finish as done after marker ids are exhausted.' );

$marker = $repository->find_by_location_id( 103 )[0];
yd_geo_runner_assert( YandexDeliveryGeoMappingRepository::TECHNICAL_ERROR_GEO_ID === (int) $marker['yandex_geo_id'] && empty( $marker['is_primary'] ) && YandexDeliveryGeoMappingStatus::ERROR === (string) $marker['status'], 'Technical marker must be saved as non-primary yandex_geo_id=999999999 with error status.' );
yd_geo_runner_assert( null === $repository->find_primary_geo_id( 103 ), 'find_primary_geo_id() must never return technical marker 999999999.' );
yd_geo_runner_assert( false === $repository->set_primary( 103, YandexDeliveryGeoMappingRepository::TECHNICAL_ERROR_GEO_ID ), 'set_primary() must reject technical marker 999999999.' );

$GLOBALS['wdc_yandex_delivery_geo_runner_options'] = array();
$GLOBALS['wpdb'] = new wpdb();
for ( $i = 47709; $i <= 47714; ++$i ) {
	$GLOBALS['wpdb']->locations[] = yd_geo_runner_location( $i, 'Recovery ' . $i );
}
$repository = new YandexDeliveryGeoMappingRepository( $GLOBALS['wpdb'] );
$repository->save_mapping( array( 'location_id' => 47713, 'yandex_geo_id' => 87713, 'status' => YandexDeliveryGeoMappingStatus::MAPPED, 'confidence' => 100, 'is_primary' => 1 ) );
$repository->save_mapping( array( 'location_id' => 47714, 'yandex_geo_id' => null, 'status' => YandexDeliveryGeoMappingStatus::NOT_FOUND, 'confidence' => 0, 'is_primary' => 0 ) );
yd_geo_runner_assert( 47714 === $repository->find_max_processed_location_id(), 'find_max_processed_location_id() must return the latest processed mapping location_id.' );
$http = new YdGeoRunnerFakeHttp();
$runner = yd_geo_runner_service( $GLOBALS['wpdb'], $http );
$state = $runner->start_unprocessed();
yd_geo_runner_assert( 'unprocessed' === (string) $state['mode'] && 'running' === (string) $state['status'] && 47709 === (int) $state['next_location_id'] && 0 === (int) $state['processed'] && 6 === (int) $state['total_estimated'], 'start_unprocessed() must use max processed location_id minus safety offset 5 and reset counters.' );
yd_geo_runner_assert( str_contains( (string) $state['message'], 'location_id 47709' ), 'start_unprocessed() message must mention recovery start location_id.' );

$runner_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingRunnerService.php' );
$repository_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingRepository.php' );
$service_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingService.php' );
$admin_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'src/Core/Plugin.php' );
$js_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'assets/admin/yandex-delivery-geo-mapping-runner.js' );
$version_source = (string) file_get_contents( WDC_PLUGIN_DIR . 'walls-delivery-calc.php' );

yd_geo_runner_assert( str_contains( $runner_source, 'private const BATCH_SIZE = 50' ) && str_contains( $runner_source, 'eta_finished_at' ) && str_contains( $runner_source, 'average_locations_per_second' ) && str_contains( $runner_source, 'remaining_seconds' ) && str_contains( $runner_source, 'count_technical_error_markers' ) && str_contains( $runner_source, '$status_before_delta' ) && str_contains( $runner_source, "'running' === " . "$" . "status_before_delta" ) && str_contains( $runner_source, "'running' !== (string) " . "$" . "state['status']" ) && ! str_contains( $runner_source, 'WORKER_COUNT' ) && ! str_contains( $runner_source, 'worker_count' ) && ! str_contains( $runner_source, 'DEFAULT_LIMIT' ) && ! str_contains( $runner_source, 'clamp_limit' ), 'Runner service must use fixed batch size 50, ETA fields, persistent marker count, reserve running guard and paused-safe delta handling.' );
yd_geo_runner_assert( str_contains( $runner_source, "\$state['status'] = 'done'" ) && str_contains( $runner_source, "\$state['mode'] = 'retry_errors'" ) && str_contains( $runner_source, "\$state['mode'] = 'unprocessed'" ), 'Runner state must support done, retry_errors and unprocessed mode.' );
yd_geo_runner_assert( ! str_contains( $runner_source, 'find_primary_geo_id( $location_id )' ), 'Full runner must not skip existing primary mappings.' );
yd_geo_runner_assert( str_contains( $runner_source, 'reserve_batch' ) && str_contains( $runner_source, 'next_location_id' ) && str_contains( $runner_source, "array( 'full', 'unprocessed' )" ) && str_contains( $runner_source, 'delete_location_mappings( $location_id )' ), 'Full/unprocessed runner must reserve with next_location_id and delete old mapping rows before remapping each location.' );
yd_geo_runner_assert( ! str_contains( $runner_source, 'skipped_existing' ) && ! str_contains( $admin_source, 'skipped_existing' ) && ! str_contains( $js_source, 'skipped_existing' ), 'Runner state, admin UI and JS must not expose skipped_existing.' );
yd_geo_runner_assert( str_contains( $repository_source, 'TECHNICAL_ERROR_GEO_ID = 999999999' ) && str_contains( $repository_source, 'save_technical_error_marker' ) && str_contains( $repository_source, 'find_technical_error_location_ids_after' ) && str_contains( $repository_source, 'clear_technical_error_marker' ) && str_contains( $repository_source, 'is_technical_error_geo_id' ) && str_contains( $repository_source, 'find_max_processed_location_id' ), 'Repository must expose technical marker and recovery helpers.' );
yd_geo_runner_assert( str_contains( $service_source, 'detect_for_runner' ) && str_contains( $service_source, 'save_technical_error_marker' ), 'Mapping service must expose runner-safe detection and save marker on technical errors.' );
foreach ( array( 'wdc_yandex_delivery_geo_mapping_runner_start', 'wdc_yandex_delivery_geo_mapping_runner_start_unprocessed', 'wdc_yandex_delivery_geo_mapping_runner_retry_errors', 'wdc_yandex_delivery_geo_mapping_runner_step', 'wdc_yandex_delivery_geo_mapping_runner_pause', 'wdc_yandex_delivery_geo_mapping_runner_reset', 'wdc_yandex_delivery_geo_mapping_runner_status' ) as $action ) {
	yd_geo_runner_assert( str_contains( $admin_source, $action ), 'Admin AJAX action missing: ' . $action );
}
yd_geo_runner_assert( str_contains( $admin_source, 'Ручная обработка будет доступна после завершения или постановки процесса на паузу.' ) && str_contains( $admin_source, '$this->yandex_delivery_geo_runner->is_running()' ), 'Admin POST/UI must block manual mapping while runner is running.' );
yd_geo_runner_assert( str_contains( $admin_source, 'is_technical_error_geo_id( $row_geo_id )' ) && str_contains( $admin_source, 'Маппинг необработанных' ) && str_contains( $admin_source, 'Запустить / продолжить полный маппинг' ) && ! str_contains( $admin_source, 'yandex_delivery_geo_batch_limit' ) && ! str_contains( $admin_source, 'yandex_delivery_geo_batch_size' ), 'Admin UI must expose recovery/full resume controls, hide primary action for marker and remove legacy limit/batch_size fields.' );
yd_geo_runner_assert( ! str_contains( $admin_source, "'worker_count' => 'worker_count'" ) && str_contains( $admin_source, "'batch_size' => 'batch_size'" ) && str_contains( $admin_source, 'eta_finished_at' ) && str_contains( $admin_source, 'technical_error_markers_count' ), 'Admin runner state table must show batch_size, ETA and marker count while hiding worker_count.' );
yd_geo_runner_assert( str_contains( $plugin_source, 'YandexDeliveryGeoMappingRunnerService::class' ), 'Plugin must register runner service.' );
yd_geo_runner_assert( str_contains( $js_source, 'function loop()' ) && str_contains( $js_source, "post('step'" ) && str_contains( $js_source, 'stopRequested' ) && str_contains( $js_source, 'activeSessionId' ) && str_contains( $js_source, 'nextState.session_id !== sessionId' ) && str_contains( $js_source, "post('start_unprocessed'" ) && str_contains( $js_source, "if (nextState.status === 'running')" ) && str_contains( $js_source, 'eta_finished_at' ) && str_contains( $js_source, 'technical_error_markers_count' ) && ! str_contains( $js_source, 'activeWorkers' ) && ! str_contains( $js_source, 'workerLoop' ) && ! str_contains( $js_source, 'worker_id' ) && ! str_contains( $js_source, 'workerCount' ), 'Runner JS must render ETA/marker fields, bootstrap only running status, use one guarded loop, no parallel worker markers, and ignore stale responses after pause.' );
yd_geo_runner_assert( str_contains( $version_source, '0.91.2' ), 'Plugin version must be 0.91.2.' );
foreach ( array( 'CheckoutOrchestrator', 'pricing', 'pickupPointsList', 'YandexDeliveryPickupPointImportService' ) as $forbidden ) {
	yd_geo_runner_assert( ! str_contains( $runner_source, $forbidden ), 'Runner must not touch checkout/pricing/PVZ import code: ' . $forbidden );
}

echo "Yandex Delivery geo mapping runner smoke OK\n";
