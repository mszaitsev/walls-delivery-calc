<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2ImportService;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2JsonStreamReader;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2RunnerService;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

function yd_v2_runner_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function current_time( string $type ): string { return '2026-06-26 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'yd-v2-runner-' . $scheme; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['yd_v2_runner_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['yd_v2_runner_options'][ $key ] = $value; return true; }
function wp_upload_dir(): array { return array( 'basedir' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-yd-v2-runner-smoke' ); }
function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function dbDelta( string|array $queries = '', bool $execute = true ): array { return array(); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $yandex_delivery_pickup_points_v2 = array();
		public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) { $query = preg_replace( '/%[sdf]/', is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query; } return $query; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

final class YdV2RunnerHttp implements YandexDeliveryHttpClientInterface {
	public array $calls = array();
	public function __construct( private string $body ) {}
	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse { $this->calls[] = compact( 'method', 'url', 'args' ); return new YandexDeliveryApiResponse( 200, $this->body ); }
}

$GLOBALS['yd_v2_runner_options'] = array();
$GLOBALS['wpdb'] = new wpdb();

$reader = new YandexDeliveryPickupPointV2JsonStreamReader();
$point = static fn( string $id, int $geo = 1 ): array => array( 'id' => $id, 'type' => 'pickup_point', 'address' => array( 'geoId' => $geo, 'locality' => 'Новосибирск' ), 'schedule' => array( 'restrictions' => array( array( 'days' => array( 1 ), 'time_from' => array( 'hours' => 9, 'minutes' => 0 ), 'time_to' => array( 'hours' => 21, 'minutes' => 0 ) ) ) ) );
$write_json = static function ( mixed $payload ): string { $file = tempnam( sys_get_temp_dir(), 'yd-v2-json-' ); file_put_contents( $file, wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) ); return $file; };
foreach ( array( array( $point( 'a' ), $point( 'b' ) ), array( 'points' => array( $point( 'c' ), $point( 'd' ) ) ), array( 'pickup_points' => array( $point( 'e' ), $point( 'f' ) ) ), array( 'items' => array( $point( 'g' ), $point( 'h' ) ) ), array( 'results' => array( $point( 'i' ), $point( 'j' ), $point( 'k' ) ) ) ) as $payload ) {
	$file = $write_json( $payload );
	$rows = iterator_to_array( $reader->read_points( $file ) );
	@unlink( $file );
	yd_v2_runner_assert( count( $rows ) >= 2 && isset( $rows[0]['id'] ), 'Stream reader must yield objects from supported response shape.' );
}
$meta_file = $write_json( array( 'points' => array( array( 'id' => 'point-1', 'type' => 'pickup_point' ) ), 'meta' => array( 'id' => 'must-not-be-read-as-point' ) ) );
$meta_rows = iterator_to_array( $reader->read_points( $meta_file ) );
@unlink( $meta_file );
yd_v2_runner_assert( 1 === count( $meta_rows ) && 'point-1' === (string) ( $meta_rows[0]['id'] ?? '' ), 'Stream reader must stop after target points array and not read meta as point.' );
yd_v2_runner_assert( ! in_array( 'must-not-be-read-as-point', array_map( static fn( array $row ): string => (string) ( $row['id'] ?? '' ), $meta_rows ), true ), 'Stream reader must not yield meta object after target array.' );
$reader_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2JsonStreamReader.php' );
yd_v2_runner_assert( ! str_contains( $reader_source, 'file_get_contents' ), 'Stream reader must not load the whole file with file_get_contents.' );
$api_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Api/YandexDeliveryApiClient.php' );
$raw_start = strpos( $api_source, 'public function pickupPointsListRawJson' );
$raw_end = false === $raw_start ? false : strpos( $api_source, 'public function locationDetect', $raw_start );
$raw_block = false !== $raw_start && false !== $raw_end ? substr( $api_source, $raw_start, $raw_end - $raw_start ) : '';
yd_v2_runner_assert( '' !== $raw_block && ! str_contains( $raw_block, 'json_decode( $response->body, true )' ), 'Raw pickup list download must not json_decode the whole response body.' );
$raw_request_start = strpos( $api_source, 'private function rawRequest' );
$raw_request_end = false === $raw_request_start ? false : strpos( $api_source, 'private function extractErrorMessage', $raw_request_start );
$raw_request_block = false !== $raw_request_start && false !== $raw_request_end ? substr( $api_source, $raw_request_start, $raw_request_end - $raw_request_start ) : '';
yd_v2_runner_assert( '' !== $raw_request_block && str_contains( $raw_request_block, 'array() === $payload' ) && str_contains( $raw_request_block, "\$body = '{}';" ), 'Raw API request must encode empty payload as JSON object.' );
yd_v2_runner_assert( str_contains( $reader_source, '$array_depth' ) && str_contains( $reader_source, 'break 2' ), 'Stream reader source must stop after target array closes.' );

$repository = new YandexDeliveryPickupPointV2Repository( $GLOBALS['wpdb'] );
$importer = new YandexDeliveryPickupPointV2ImportService( $repository, null, $reader );
$file = $write_json( array( 'points' => array( $point( 's1', 10 ), $point( 's2', 20 ), $point( 's3', 20 ) ) ) );
$step1 = $importer->import_from_json_file_streamed( $file, 0, 2 );
yd_v2_runner_assert( 2 === $step1['processed'] && 2 === $step1['saved'] && 2 === $step1['next_offset'] && false === $step1['done'], 'Streamed import first step must import only requested limit.' );
$step2 = $importer->import_from_json_file_streamed( $file, 2, 2 );
yd_v2_runner_assert( 1 === $step2['processed'] && 1 === $step2['saved'] && 3 === $step2['next_offset'] && true === $step2['done'], 'Streamed import second step must continue from offset and finish.' );
yd_v2_runner_assert( 3 === $repository->count_all() && 2 === $repository->count_unique_geo_ids(), 'Repository stats must count imported rows and unique geo ids.' );

$settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin( array( YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST, 'yandex_delivery_test_bearer_token' => 'token', YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'station' ) );
$http = new YdV2RunnerHttp( (string) file_get_contents( $file ) );
$api = new YandexDeliveryApiClient( $settings, $http );
$runner = new YandexDeliveryPickupPointV2RunnerService( $api, new YandexDeliveryPickupPointV2ImportService( new YandexDeliveryPickupPointV2Repository( $GLOBALS['wpdb'] ), null, $reader ) );
$state = $runner->start_full_api_sync();
yd_v2_runner_assert( in_array( $state['status'], array( 'ready_to_import', 'importing' ), true ) && is_file( (string) $state['json_file_path'] ), 'Runner start must download JSON file and prepare import.' );
yd_v2_runner_assert( 'start' === (string) ( $state['last_action'] ?? '' ) && array_key_exists( 'last_http_status', $state ) && array_key_exists( 'last_error_context', $state ), 'Runner state must expose debug fields.' );
yd_v2_runner_assert( '{}' === (string) $http->calls[0]['args']['body'], 'Runner API request with empty payload must send JSON object, not array.' );
$payload = json_decode( (string) $http->calls[0]['args']['body'], true );
yd_v2_runner_assert( array() === $payload && ! array_key_exists( 'type', $payload ) && ! array_key_exists( 'geo_id', $payload ), 'Runner API request payload must be empty without type or geo_id.' );
$path_before_reset = (string) $state['json_file_path'];
$state = $runner->start_import();
yd_v2_runner_assert( 'importing' === $state['status'], 'Runner start_import must switch to importing.' );
$state = $runner->run_import_step();
yd_v2_runner_assert( $state['offset'] > 0 && $state['processed'] > 0 && $state['saved'] > 0 && isset( $state['memory_peak_mb'] ), 'Runner step must update counters and memory peak.' );
$paused = $runner->pause();
yd_v2_runner_assert( 'paused' !== $paused['status'] || $paused['offset'] === $state['offset'], 'Runner pause must not advance import.' );
$reset = $runner->reset();
yd_v2_runner_assert( 'idle' === $reset['status'] && is_file( $path_before_reset ), 'Runner reset must not delete downloaded JSON file.' );
@unlink( $file );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/yandex-delivery-pickup-v2-runner.js' );
$import_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2ImportService.php' );
$runner_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2RunnerService.php' );
yd_v2_runner_assert( str_contains( $admin_source, 'Скачать и импортировать полный список' ), 'V2 tab must render full sync button.' );
yd_v2_runner_assert( str_contains( $admin_source, 'без type и geo_id' ), 'V2 tab must explain empty API filter payload.' );
yd_v2_runner_assert( str_contains( $admin_source, "'yandex_delivery_pickup_v2' ===" ) && str_contains( $admin_source, 'yandex-delivery-pickup-v2-runner.js' ), 'V2 runner JS must be enqueued only on Yandex pickup v2 tab.' );
yd_v2_runner_assert( ! str_contains( $admin_source, 'Текущий импорт пока недоступен' ), 'V2 tab placeholder must be removed.' );
yd_v2_runner_assert( str_contains( $js_source, 'wdc_yandex_delivery_pickup_v2_runner_start' ) && str_contains( $js_source, 'wdc_yandex_delivery_pickup_v2_runner_step' ), 'V2 JS must call runner AJAX actions.' );
yd_v2_runner_assert( str_contains( $js_source, 'response.text()' ) && str_contains( $js_source, 'JSON.parse' ) && str_contains( $js_source, 'Сервер вернул не JSON' ), 'V2 JS must safely diagnose non-JSON AJAX responses.' );
$v2_ajax_start = strpos( $admin_source, 'public function ajax_yandex_delivery_pickup_v2_runner_start' );
$v2_ajax_end = false === $v2_ajax_start ? false : strpos( $admin_source, 'private function can_handle_yandex_delivery_geo_mapping_runner_ajax', $v2_ajax_start );
$v2_ajax_block = false !== $v2_ajax_start && false !== $v2_ajax_end ? substr( $admin_source, $v2_ajax_start, $v2_ajax_end - $v2_ajax_start ) : '';
yd_v2_runner_assert( '' !== $v2_ajax_block && str_contains( $v2_ajax_block, 'catch ( \Throwable $exception )' ) && str_contains( $v2_ajax_block, 'wp_send_json_error' ), 'V2 AJAX handlers must return JSON errors from catch.' );
yd_v2_runner_assert( '' !== $v2_ajax_block && ! str_contains( $v2_ajax_block, 'wp_die' ), 'V2 AJAX handlers must not use wp_die for nonce/capability failures.' );
yd_v2_runner_assert( str_contains( $admin_source, 'register_yandex_pickup_v2_ajax_shutdown_guard' ) && str_contains( $admin_source, 'register_shutdown_function' ) && str_contains( $admin_source, 'Fatal error:' ), 'V2 AJAX handlers must register fatal shutdown JSON guard.' );
yd_v2_runner_assert( str_contains( $runner_source, 'import_from_json_file_streamed' ) && ! str_contains( $runner_source, 'import_from_json_file(' ), 'Runner must use streamed import, not whole-file import.' );
yd_v2_runner_assert( str_contains( $import_source, 'file_get_contents' ) && str_contains( $import_source, 'import_from_json_file_streamed' ), 'Old small-file import may remain while streamed import exists.' );

echo "Yandex Delivery pickup v2 runner smoke OK\n";
