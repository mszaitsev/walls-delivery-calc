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
		public int $yandex_delivery_pickup_points_v2_truncate_count = 0;
		public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) { $query = preg_replace( '/%[sdf]/', is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query; } return $query; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

final class YdV2RunnerHttp implements YandexDeliveryHttpClientInterface {
	public array $calls = array();
	public function __construct( private string $body ) {}
	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$this->calls[] = compact( 'method', 'url', 'args' );
		if ( ! empty( $args['stream'] ) && isset( $args['filename'] ) ) {
			file_put_contents( (string) $args['filename'], $this->body );
			return new YandexDeliveryApiResponse( 200, '' );
		}

		return new YandexDeliveryApiResponse( 200, $this->body );
	}
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
$download_start = strpos( $api_source, 'public function pickupPointsListDownloadToFile' );
$download_end = false === $download_start ? false : strpos( $api_source, 'public function pickupPointsListRawJson', $download_start );
$download_block = false !== $download_start && false !== $download_end ? substr( $api_source, $download_start, $download_end - $download_start ) : '';
yd_v2_runner_assert( '' !== $download_block, 'API client must expose pickupPointsListDownloadToFile().' );
yd_v2_runner_assert( str_contains( $download_block, "'stream' => true" ) && str_contains( $download_block, "'filename' => " . '$target_file' ), 'Download-to-file API method must pass stream and filename HTTP args.' );
yd_v2_runner_assert( ! str_contains( $download_block, 'json_decode' ) && ! str_contains( $download_block, 'trim( $response->body' ), 'Download-to-file API method must not decode or trim the full response body.' );
$raw_start = strpos( $api_source, 'public function pickupPointsListRawJson' );
$raw_end = false === $raw_start ? false : strpos( $api_source, 'public function locationDetect', $raw_start );
$raw_block = false !== $raw_start && false !== $raw_end ? substr( $api_source, $raw_start, $raw_end - $raw_start ) : '';
yd_v2_runner_assert( '' !== $raw_block && ! str_contains( $raw_block, 'json_decode( $response->body, true )' ), 'Raw pickup list diagnostics method must not json_decode the whole response body.' );
$encode_start = strpos( $api_source, 'private function encodePayloadBody' );
$encode_end = false === $encode_start ? false : strpos( $api_source, 'private function extractErrorMessage', $encode_start );
$encode_block = false !== $encode_start && false !== $encode_end ? substr( $api_source, $encode_start, $encode_end - $encode_start ) : '';
yd_v2_runner_assert( '' !== $encode_block && str_contains( $encode_block, 'array() === $payload' ) && str_contains( $encode_block, "return '{}';" ), 'Raw API request must encode empty payload as JSON object.' );
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
$download_http = new YdV2RunnerHttp( '{"points":[]}' );
$download_api = new YandexDeliveryApiClient( $settings, $download_http );
$download_file = tempnam( sys_get_temp_dir(), 'yd-v2-download-' );
$download_result = $download_api->pickupPointsListDownloadToFile( array(), $download_file );
yd_v2_runner_assert( is_file( $download_file ) && '{"points":[]}' === (string) file_get_contents( $download_file ), 'Download-to-file API method must create target JSON file.' );
yd_v2_runner_assert( 200 === $download_result['http_code'] && $download_result['size_bytes'] > 0 && $download_result['file'] === $download_file, 'Download-to-file API method must return HTTP code, file and size.' );
yd_v2_runner_assert( '{}' === (string) $download_http->calls[0]['args']['body'] && true === $download_http->calls[0]['args']['stream'] && $download_file === $download_http->calls[0]['args']['filename'], 'Download-to-file API request must stream empty-payload JSON object into target file.' );
@unlink( $download_file );
$http = new YdV2RunnerHttp( (string) file_get_contents( $file ) );
$api = new YandexDeliveryApiClient( $settings, $http );
$runner_repository = new YandexDeliveryPickupPointV2Repository( $GLOBALS['wpdb'] );
$runner = new YandexDeliveryPickupPointV2RunnerService( $api, new YandexDeliveryPickupPointV2ImportService( $runner_repository, null, $reader ) );
$state = $runner->start_full_api_sync();
yd_v2_runner_assert( in_array( $state['status'], array( 'ready_to_import', 'importing' ), true ) && is_file( (string) $state['json_file_path'] ), 'Runner start must download JSON file and prepare import.' );
yd_v2_runner_assert( 'start' === (string) ( $state['last_action'] ?? '' ) && array_key_exists( 'last_http_status', $state ) && array_key_exists( 'last_error_context', $state ), 'Runner state must expose debug fields.' );
yd_v2_runner_assert( $state['json_file_size_bytes'] > 0, 'Runner state must store downloaded file size.' );
yd_v2_runner_assert( true === $http->calls[0]['args']['stream'] && (string) $state['json_file_path'] === $http->calls[0]['args']['filename'], 'Runner download must use HTTP stream directly into JSON file.' );
yd_v2_runner_assert( '{}' === (string) $http->calls[0]['args']['body'], 'Runner API request with empty payload must send JSON object, not array.' );
$payload = json_decode( (string) $http->calls[0]['args']['body'], true );
yd_v2_runner_assert( array() === $payload && ! array_key_exists( 'type', $payload ) && ! array_key_exists( 'geo_id', $payload ), 'Runner API request payload must be empty without type or geo_id.' );
$validator = new ReflectionMethod( YandexDeliveryPickupPointV2RunnerService::class, 'validate_json_file_container' );
$validator->setAccessible( true );
foreach ( array( '{ "points": [] }', '[{}]' ) as $valid_json_container ) {
	$valid_file = tempnam( sys_get_temp_dir(), 'yd-v2-valid-' );
	file_put_contents( $valid_file, $valid_json_container );
	$validator->invoke( $runner, $valid_file );
	@unlink( $valid_file );
}
foreach ( array( '<p>error</p>', '' ) as $invalid_json_container ) {
	$invalid_file = tempnam( sys_get_temp_dir(), 'yd-v2-invalid-' );
	file_put_contents( $invalid_file, $invalid_json_container );
	$rejected = false;
	try {
		$validator->invoke( $runner, $invalid_file );
	} catch ( Throwable ) {
		$rejected = true;
	}
	@unlink( $invalid_file );
	yd_v2_runner_assert( $rejected, 'Runner JSON file validator must reject empty files and HTML responses.' );
}
$path_before_reset = (string) $state['json_file_path'];
$state = $runner->start_import();
yd_v2_runner_assert( 'importing' === $state['status'], 'Runner start_import must switch to importing.' );
$state = $runner->run_import_step();
yd_v2_runner_assert( $state['offset'] > 0 && $state['processed'] > 0 && $state['saved'] > 0 && isset( $state['memory_peak_mb'] ), 'Runner step must update counters and memory peak.' );
yd_v2_runner_assert( 1 === $GLOBALS['wpdb']->yandex_delivery_pickup_points_v2_truncate_count && $runner_repository->count_all() > 0 && null === $runner_repository->find( 'stale-before-runner-import' ), 'Runner import steps must continue without repeated truncate and stale pickup rows must stay removed.' );
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
yd_v2_runner_assert( ! str_contains( $admin_source, 'yandex_delivery_geo_mapping_runner' ) && ! str_contains( $admin_source, '$tabs[\'yandex_delivery_pickup\']' ) && ! str_contains( $admin_source, '$tabs[\'yandex_delivery_geo\']' ) && ! str_contains( $admin_source, '$tabs[\'yandex_delivery_geo_coverage\']' ), 'Legacy Yandex geo/pickup tabs and runner AJAX must stay removed.' );
yd_v2_runner_assert( str_contains( $js_source, 'wdc_yandex_delivery_pickup_v2_runner_start' ) && str_contains( $js_source, 'wdc_yandex_delivery_pickup_v2_runner_step' ), 'V2 JS must call runner AJAX actions.' );
yd_v2_runner_assert( str_contains( $js_source, 'response.text()' ) && str_contains( $js_source, 'JSON.parse' ) && str_contains( $js_source, 'Сервер вернул не JSON' ), 'V2 JS must safely diagnose non-JSON AJAX responses.' );
$v2_ajax_start = strpos( $admin_source, 'public function ajax_yandex_delivery_pickup_v2_runner_start' );
$v2_ajax_end = false === $v2_ajax_start ? false : strpos( $admin_source, 'private function register_yandex_pickup_v2_ajax_shutdown_guard', $v2_ajax_start );
$v2_ajax_block = false !== $v2_ajax_start && false !== $v2_ajax_end ? substr( $admin_source, $v2_ajax_start, $v2_ajax_end - $v2_ajax_start ) : '';
yd_v2_runner_assert( '' !== $v2_ajax_block && str_contains( $v2_ajax_block, 'catch ( \Throwable $exception )' ) && str_contains( $v2_ajax_block, 'wp_send_json_error' ), 'V2 AJAX handlers must return JSON errors from catch.' );
yd_v2_runner_assert( '' !== $v2_ajax_block && ! str_contains( $v2_ajax_block, 'wp_die' ), 'V2 AJAX handlers must not use wp_die for nonce/capability failures.' );
yd_v2_runner_assert( str_contains( $admin_source, 'register_yandex_pickup_v2_ajax_shutdown_guard' ) && str_contains( $admin_source, 'register_shutdown_function' ) && str_contains( $admin_source, 'Fatal error:' ), 'V2 AJAX handlers must register fatal shutdown JSON guard.' );
$download_method_start = strpos( $runner_source, 'public function download_full_json' );
$download_method_end = false === $download_method_start ? false : strpos( $runner_source, 'public function start_import', $download_method_start );
$runner_download_block = false !== $download_method_start && false !== $download_method_end ? substr( $runner_source, $download_method_start, $download_method_end - $download_method_start ) : '';
yd_v2_runner_assert( '' !== $runner_download_block && ! str_contains( $runner_download_block, 'pickupPointsListRawJson' ) && str_contains( $runner_download_block, 'pickupPointsListDownloadToFile' ), 'Runner download must use download-to-file API method, not raw JSON string method.' );
yd_v2_runner_assert( '' !== $runner_download_block && ! str_contains( $runner_download_block, 'file_put_contents( $file, $json' ), 'Runner download must not write a full in-memory JSON string to file.' );
yd_v2_runner_assert( str_contains( $runner_source, 'pickup_points_truncated' ) && str_contains( $runner_source, 'truncate_repository' ), 'Runner must truncate pickup_points_v2 once before first import batch.' );
yd_v2_runner_assert( str_contains( $runner_source, 'validate_json_file_container' ) && str_contains( $runner_source, 'first_non_whitespace_byte' ) && str_contains( $runner_source, 'last_non_whitespace_byte' ) && str_contains( $runner_source, 'fseek' ), 'Runner must validate downloaded JSON container from first/last bytes.' );
yd_v2_runner_assert( str_contains( $runner_source, 'import_from_json_file_streamed' ) && ! str_contains( $runner_source, 'import_from_json_file(' ), 'Runner must use streamed import, not whole-file import.' );
yd_v2_runner_assert( str_contains( $import_source, 'file_get_contents' ) && str_contains( $import_source, 'import_from_json_file_streamed' ), 'Old small-file import may remain while streamed import exists.' );

echo "Yandex Delivery pickup v2 runner smoke OK\n";
