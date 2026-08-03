<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\Admin\PekAdminNoticeStore;
use WallsShop\WDC\Carriers\Pek\Admin\PekAdminPage;
use WallsShop\WDC\Carriers\Pek\Admin\PekDestinationPickupDiagnosticService;
use WallsShop\WDC\Carriers\Pek\Admin\PekDestinationPickupDiagnosticStore;
use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekConnectionDiagnosticService;
use WallsShop\WDC\Carriers\Pek\Api\PekHttpClientInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekRequestBudget;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseSearchCache;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseService;
use WallsShop\WDC\Carriers\Pek\Geography\PekAddressBuilder;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationMappingRepository;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationResolver;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Pickup\PekCargoConstraintsConverter;
use WallsShop\WDC\Carriers\Pek\Pickup\PekDestinationTerminalSearchCache;
use WallsShop\WDC\Carriers\Pek\Pickup\PekPickupPointProvider;
use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalRepository;
use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalService;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;

function pek_ui_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( string $value ): string { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $value ) ) ?? '' ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ); }
function sanitize_email( string $value ): string { return trim( $value ); }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function current_time( string $type ): int|string { return 'timestamp' === $type ? 1785652800 : '2026-08-02 12:00:00'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_ui_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['pek_ui_options'][ $option ] = $value; return true; }
function get_current_user_id(): int { return 11; }
function get_transient( string $key ): mixed { return $GLOBALS['pek_ui_transients'][ $key ]['value'] ?? false; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['pek_ui_transients'][ $key ] = array( 'value' => $value, 'expiration' => $expiration ); return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['pek_ui_transients'][ $key ] ); return true; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function selected( mixed $selected, mixed $current, bool $display = true ): string { return (string) $selected === (string) $current ? ' selected="selected"' : ''; }
function wp_nonce_field( string $action ): void { echo '<input type="hidden" name="_wpnonce" value="nonce">'; }
function submit_button( string $text, string $type = 'primary' ): void { echo '<button class="button button-' . esc_attr( $type ) . '" type="submit">' . esc_html( $text ) . '</button>'; }
function wc_get_logger(): object { return $GLOBALS['pek_ui_wc_logger']; }

final class PekUiFakeWooLogger {
	public array $entries = array();
	public function log( string $level, string $message, array $context = array() ): void {
		$this->entries[] = compact( 'level', 'message', 'context' );
	}
}

final class PekUiFakeHttp implements PekHttpClientInterface {
	public array $requests = array();
	public array $nearest_response = array( 'freeDepartments' => array(), 'paidDepartments' => array() );
	public function request( string $method, string $url, array $args ): array {
		$this->requests[] = compact( 'method', 'url', 'args' );
		if ( str_contains( (string) parse_url( $url, PHP_URL_PATH ), 'findzonebycoordinates' ) ) {
			return array( 'status' => 200, 'body' => wp_json_encode( array( array( 'zoneId' => 'zone', 'zoneName' => 'Zone', 'branchUID' => 'branch', 'branchTitle' => 'Branch', 'warehousePoint' => array( 'latitude' => 1, 'longitude' => 2 ) ) ) ) );
		}
		if ( str_contains( (string) parse_url( $url, PHP_URL_PATH ), 'findzonebyaddress' ) ) {
			return array( 'status' => 200, 'body' => wp_json_encode( array( 'zoneId' => 'zone-address', 'zoneName' => 'Zone', 'branchUID' => 'branch', 'branchTitle' => 'Branch', 'mainWarehouseId' => 'main-address', 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'country_code' => 'RU', 'formatted' => 'Россия, Новосибирск' ) ) ) ) );
		}
		return array( 'status' => 200, 'body' => wp_json_encode( $this->nearest_response ) );
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $locations = array();
		public array $pek_location_mappings = array();
		public array $pek_terminals = array();
	}
}

$GLOBALS['pek_ui_options'] = array();
$GLOBALS['pek_ui_transients'] = array();
$GLOBALS['pek_ui_wc_logger'] = new PekUiFakeWooLogger();
define( 'APP_ENCRYPTION_KEY', 'pek-ui-key' );

$settings_repository = new SettingsRepository();
$settings = new PekSettings( $settings_repository );
$credentials = new PekCredentials( $settings_repository, new EncryptionService() );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'diagnostic-user', 'pek_api_key' => 'very-secret-key' ) );
$settings->save_sender_warehouse( array( 'warehouseId' => 'nearest-timezone-wh', 'source' => 'free', 'branchTimezone' => 'UTC+04:00', 'branchName' => 'Самара', 'divisionName' => 'Самара Запад' ) );
$settings->save_diagnostic_result(
	array(
		'success' => false,
		'checked_at' => '2026-08-03 01:13:52',
		'classifier_mismatches' => array(
			array(
				'country' => 'RU',
				'expected' => '643',
				'actual' => '999',
			),
		),
		'checks' => array(
			'products' => array(
				'endpoint' => '/typesOfDelivery/all/',
				'method' => 'GET',
				'status' => 'failed',
				'success' => false,
				'skipped' => false,
				'required' => false,
				'error_code' => 'pek_http_403',
				'http_status' => 403,
				'message' => 'ПЭК отклонил доступ к методу.',
			),
			'countries' => array(
				'endpoint' => '/branches/country/',
				'method' => 'POST',
				'status' => 'passed',
				'success' => true,
				'skipped' => false,
				'required' => false,
				'error_code' => '',
				'http_status' => 200,
				'message' => 'RU подтверждена classifier 643.',
			),
			'warehouse_api' => array(
				'endpoint' => '/branches/all/',
				'method' => 'POST',
				'status' => 'passed',
				'success' => true,
				'skipped' => false,
				'required' => true,
				'error_code' => '',
				'http_status' => 200,
				'message' => 'Метод списка филиалов ПЭК доступен.',
			),
			'warehouse_match' => array(
				'endpoint' => '/branches/all/',
				'method' => 'POST',
				'status' => 'warning',
				'success' => false,
				'skipped' => false,
				'required' => false,
				'informational' => true,
				'affects_all_checks' => false,
				'warehouse_found' => false,
				'warehouse_id' => 'nearest-timezone-wh',
				'info_code' => 'pek_diagnostic_warehouse_not_matched',
				'branches_checked' => 5,
				'divisions_checked' => 17,
				'warehouses_checked' => 42,
				'message' => 'Сохранённый warehouse ID не найден в структуре ответа /branches/all/. Склад был выбран из free.',
			),
		),
	)
);
$cache = new PekSenderWarehouseSearchCache();
$cache->save_for_current_user(
	array(
		'success' => true,
		'message' => 'found',
		'items' => array(),
		'requested' => array( 'address' => 'Новосибирск', 'departmentOperation' => 2, 'type' => 3 ),
	)
);
$notice_store = new PekAdminNoticeStore();
$notice_store->save_for_current_user( 'success', 'Saved <safe>' );
$ui_http = new PekUiFakeHttp();
$api = new PekApiClient( $settings, $credentials, $ui_http, new PekRequestBudget( $settings ) );
$wpdb = new wpdb();
$wpdb->locations = array( array( 'id' => 10, 'country_code' => 'RU', 'display_name' => 'Новосибирск', 'city_name' => 'Новосибирск', 'place_name' => 'Новосибирск', 'latitude' => 55.030204, 'longitude' => 82.92043, 'active' => 1 ) );
$wpdb->pek_location_mappings = array();
$wpdb->pek_terminals = array();
$location_repository = new LocationRepository( $wpdb );
$location_resolver = new PekLocationResolver( $location_repository, new PekAddressBuilder(), new PekLocationMappingRepository( $wpdb ), $api, $settings );
$terminal_service = new PekTerminalService( $location_resolver, $api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), new PekTerminalRepository( $wpdb ), $settings );
$pickup_provider = new PekPickupPointProvider( $terminal_service );
$destination_diagnostic_service = new PekDestinationPickupDiagnosticService( new CarrierPickupPointProviderRegistry( array( $pickup_provider ) ), $location_repository, $terminal_service, $settings, $credentials, new Logger() );
$destination_report_store = new PekDestinationPickupDiagnosticStore();
$page = new PekAdminPage(
	$settings,
	$credentials,
	new PekConnectionDiagnosticService( $settings, $credentials, $api ),
	new PekSenderWarehouseService( $api, $settings, $cache ),
	$notice_store,
	$destination_diagnostic_service,
	$destination_report_store
);
$service = DeliveryService::from_array( array( 'id' => 5, 'service_key' => PekSettings::SERVICE_KEY, 'carrier_key' => PekSettings::CARRIER_KEY, 'title' => 'ПЭК' ) );

set_error_handler(
	static function ( int $severity, string $message ): bool {
		throw new RuntimeException( $message, $severity );
	}
);
ob_start();
$page->render_embedded( $service );
$html = (string) ob_get_clean();
restore_error_handler();

pek_ui_assert( str_contains( $html, 'RU' ) && str_contains( $html, '643' ) && str_contains( $html, '999' ), 'PEK diagnostic classifier mismatch must render country/expected/actual.' );
pek_ui_assert( ! str_contains( $html, '>Array<' ) && ! str_contains( $html, 'Array to string conversion' ), 'PEK diagnostic nested arrays must not render as Array or warning text.' );
pek_ui_assert( str_contains( $html, '2026-08-03 01:13:52' ) && ! str_contains( $html, '[redacted-phone]:13:52' ), 'PEK diagnostic checked_at must render as a full machine datetime.' );
pek_ui_assert( str_contains( $html, 'GET /typesOfDelivery/all/' ) && str_contains( $html, 'HTTP 403' ) && str_contains( $html, 'pek_http_403' ) && str_contains( $html, 'POST /branches/country/' ), 'PEK diagnostic checks must render method, endpoint, HTTP status and stable error code.' );
pek_ui_assert( str_contains( $html, 'Сопоставление выбранного склада' ) && str_contains( $html, 'Информация:' ) && str_contains( $html, 'Проверено филиалов: 5, отделений: 17, складов: 42' ) && str_contains( $html, 'pek_diagnostic_warehouse_not_matched' ), 'PEK admin UI must render warehouse_match as informational diagnostics with safe counters.' );
pek_ui_assert( ! str_contains( $html, 'ПЭК не подтвердил выбранный warehouse ID' ), 'PEK admin UI must not claim PEK explicitly rejected the saved warehouse ID.' );
pek_ui_assert( ! str_contains( $html, '&quot;products&quot;' ) && ! str_contains( $html, '{&quot;endpoint&quot;' ), 'PEK diagnostic checks must not render as raw nested JSON.' );
pek_ui_assert( str_contains( $html, 'Saved &lt;safe&gt;' ), 'PEK admin notice must render escaped content.' );
pek_ui_assert( 0 === count( $ui_http->requests ), 'Normal PEK admin page render must not call PEK API.' );
pek_ui_assert( str_contains( $html, '>free<' ), 'PEK admin UI must render sender warehouse source.' );
pek_ui_assert( str_contains( $html, 'UTC+04:00' ) && ! str_contains( $html, '04:00:00' ), 'PEK admin UI must render canonical sender warehouse branch timezone, not raw nearestdepartments timeZone.' );
$search_form_pos = strpos( $html, 'name="wdc_delivery_services_action" value="search_pek_sender_warehouse"' );
$search_field_pos = strpos( $html, 'id="pek_warehouse_search_address"' );
$table_pos = strpos( $html, '<table class="form-table" role="presentation">', $search_form_pos === false ? 0 : $search_form_pos );
pek_ui_assert( false !== $search_form_pos && false !== $table_pos && false !== $search_field_pos && $table_pos < $search_field_pos, 'PEK warehouse search field must be inside a form-table.' );
pek_ui_assert( array() === $notice_store->consume_for_current_user(), 'PEK admin notice must be consumed by render.' );

$invalid_report = $destination_diagnostic_service->run( array( 'pek_destination_location_id' => 10, 'pek_destination_weight_kg' => 'abc', 'pek_destination_length_cm' => 10, 'pek_destination_width_cm' => 10, 'pek_destination_height_cm' => 10, 'pek_destination_max_place_weight_kg' => 1, 'pek_destination_places_count' => 1 ) );
pek_ui_assert( false === $invalid_report['success'] && 'pek_invalid_pickup_query' === $invalid_report['error_code'] && 0 === count( $ui_http->requests ), 'Invalid admin cargo input must return success=false without API.' );
$out_of_range_report = $destination_diagnostic_service->run( array( 'pek_destination_location_id' => 10, 'pek_destination_weight_kg' => 1, 'pek_destination_length_cm' => 5000, 'pek_destination_width_cm' => 10, 'pek_destination_height_cm' => 10, 'pek_destination_max_place_weight_kg' => 1, 'pek_destination_places_count' => 1 ) );
pek_ui_assert( false === $out_of_range_report['success'] && 0 === count( $ui_http->requests ), 'Out-of-range admin cargo input must not be silently clamped.' );
$success_empty_report = $destination_diagnostic_service->run( array( 'pek_destination_location_id' => 10, 'pek_destination_weight_kg' => 1.25, 'pek_destination_length_cm' => 100, 'pek_destination_width_cm' => 100, 'pek_destination_height_cm' => 100, 'pek_destination_max_place_weight_kg' => 1.25, 'pek_destination_places_count' => 10 ) );
pek_ui_assert( true === $success_empty_report['success'] && 0 === $success_empty_report['terminals']['total_returned'] && str_contains( $success_empty_report['message'], 'Подходящие терминалы не найдены' ), 'Successful empty destination diagnostic must be success=true with explicit empty message.' );
$destination_payload = json_decode( (string) $ui_http->requests[1]['args']['body'], true );
pek_ui_assert( 10.0 === (float) $destination_payload['volume'] && 1.25 === (float) $destination_payload['weight'], 'Admin diagnostic must multiply one-place dimensions by places_count and preserve decimal weight.' );
$GLOBALS['pek_ui_transients'] = array();
$ui_http->nearest_response = array( 'freeDepartments' => array( 'warehouseId' => 'bad', 'Authorization' => 'secret' ), 'paidDepartments' => null );
$failure_report = $destination_diagnostic_service->run( array( 'pek_destination_location_id' => 10, 'pek_destination_weight_kg' => 1.5, 'pek_destination_length_cm' => 100, 'pek_destination_width_cm' => 100, 'pek_destination_height_cm' => 100, 'pek_destination_max_place_weight_kg' => 1.5, 'pek_destination_places_count' => 1 ) );
pek_ui_assert( false === $failure_report['success'] && 'pek_unexpected_destination_nearest_departments' === $failure_report['error_code'] && 'destination_terminal_contract' === $failure_report['failure_stage'] && '/branches/nearestdepartments/' === $failure_report['endpoint'] && 200 === (int) $failure_report['http_status'], 'Malformed destination terminal response must create structured failure diagnostic status.' );
pek_ui_assert( 'coordinates' === $failure_report['location']['resolution_method'] && 'resolved' === $failure_report['location']['mapping_state'] && 'Branch' === $failure_report['location']['branch'] && 'Zone' === $failure_report['location']['zone'], 'Failure report must preserve successful location mapping context.' );
pek_ui_assert( is_array( $failure_report['response_shape'] ) && 'object' === $failure_report['response_shape']['root_type'] && 'object' === $failure_report['response_shape']['free_departments_type'] && 'null' === $failure_report['response_shape']['paid_departments_type'], 'Failure report must include safe nearestdepartments response shape.' );
$failure_json = wp_json_encode( $failure_report );
pek_ui_assert( ! str_contains( (string) $failure_json, 'Authorization' ) && ! str_contains( (string) $failure_json, 'secret' ) && ! str_contains( (string) $failure_json, 'warehouseId' ), 'Failure report must not store raw response values.' );
pek_ui_assert( 1 === count( $GLOBALS['pek_ui_wc_logger']->entries ) && 'error' === $GLOBALS['pek_ui_wc_logger']->entries[0]['level'] && 'PEK destination pickup diagnostic failed.' === $GLOBALS['pek_ui_wc_logger']->entries[0]['message'], 'Failed explicit admin diagnostic must write one project logger event.' );
$log_json = wp_json_encode( $GLOBALS['pek_ui_wc_logger']->entries[0]['context'] );
pek_ui_assert( str_contains( (string) $log_json, 'pek_unexpected_destination_nearest_departments' ) && ! str_contains( (string) $log_json, 'Authorization' ) && ! str_contains( (string) $log_json, 'secret' ) && ! str_contains( (string) $log_json, 'warehouseId' ), 'Diagnostic log context must be safe and include stable error code.' );
$destination_report_store->save_for_current_user( $failure_report );
ob_start();
$page->render_embedded( $service );
$failure_html = (string) ob_get_clean();
pek_ui_assert( str_contains( $failure_html, 'Код ошибки' ) && str_contains( $failure_html, 'pek_unexpected_destination_nearest_departments' ) && str_contains( $failure_html, 'Этап' ) && str_contains( $failure_html, 'destination_terminal_contract' ) && str_contains( $failure_html, 'POST /branches/nearestdepartments/' ) && str_contains( $failure_html, 'HTTP status' ) && str_contains( $failure_html, 'Resolution method' ) && str_contains( $failure_html, 'Mapping state' ) && str_contains( $failure_html, 'Main warehouse ID' ) && str_contains( $failure_html, 'Response shape' ) && str_contains( $failure_html, 'Free departments type' ) && str_contains( $failure_html, 'Rejections' ), 'Destination diagnostic UI must render named failure fields, mapping, response shape and rejections.' );
pek_ui_assert( ! str_contains( $failure_html, '10, RU, Новосибирск, 1' ) && ! str_contains( $failure_html, 'raw_response' ) && ! str_contains( $failure_html, 'Authorization' ) && ! str_contains( $failure_html, 'secret' ), 'Destination diagnostic UI must not render positional arrays or unsafe response data.' );

$GLOBALS['pek_ui_transients'] = array();
$GLOBALS['pek_ui_wc_logger']->entries = array();
$long_message = str_repeat( ' слишком   длинно ', 80 ) . "\r\n\tapi_key=very-secret-key token=very-secret-key login=diagnostic-user Basic " . base64_encode( 'diagnostic-user:very-secret-key' );
$ui_http->nearest_response = array(
	'error' => array(
		'title' => '<script>alert(1)</script>',
		'message' => '<b>bad</b> Некорректные параметры: Значение volume должно быть больше 0. diagnostic-user:very-secret-key ' . $long_message,
		'fields' => array(
			array(
				'Key' => 'volume',
				'Value' => array(
					'Значение должно быть больше 0.',
					'login=diagnostic-user api_key=very-secret-key Basic ' . base64_encode( 'diagnostic-user:very-secret-key' ),
				),
				'RejectedValue' => 'Москва, секретный адрес',
			),
			array(
				'Key' => 'searchRadius',
				'Value' => array( 'Максимально допустимое значение — 100.' ),
				'AttemptedValue' => array( 'secret' => true ),
			),
			array(
				'Key' => 'volume',
				'Value' => array( 'Значение должно быть больше 0.', 'Второе сообщение.' ),
			),
			array(
				'Key' => '<script>field</script>',
				'Value' => array( '<b>bad field</b>' ),
			),
			array(
				'Key' => array( 'bad' ),
				'Value' => array( 'skip' ),
			),
			array(
				'Key' => 'badMessage',
				'Value' => array( array( 'nested' ) ),
			),
		),
	),
);
$logical_report = $destination_diagnostic_service->run( array( 'pek_destination_location_id' => 10, 'pek_destination_weight_kg' => 1.75, 'pek_destination_length_cm' => 100, 'pek_destination_width_cm' => 100, 'pek_destination_height_cm' => 100, 'pek_destination_max_place_weight_kg' => 1.75, 'pek_destination_places_count' => 1 ) );
pek_ui_assert( false === $logical_report['success'] && 'pek_logical_error' === $logical_report['error_code'] && 'destination_terminal_logical' === $logical_report['failure_stage'] && 'Не удалось использовать ответ ПЭК для выбранного направления.' === $logical_report['message'], 'PEK logical error must keep stable diagnostic message and stable error code.' );
pek_ui_assert( str_contains( $logical_report['api_error_message'], '<script>alert(1)</script>: <b>bad</b> Некорректные параметры' ) && str_contains( $logical_report['api_error_message'], 'Значение volume должно быть больше 0.' ), 'PEK logical error must expose safe title/message as api_error_message.' );
pek_ui_assert( is_array( $logical_report['field_errors'] ?? null ) && 'volume' === (string) ( $logical_report['field_errors'][0]['field'] ?? '' ) && array( 'Значение должно быть больше 0.', '[redacted] [redacted] Basic [redacted]', 'Второе сообщение.' ) === ( $logical_report['field_errors'][0]['messages'] ?? array() ) && 'searchRadius' === (string) ( $logical_report['field_errors'][1]['field'] ?? '' ), 'PEK logical field errors must be normalized, ordered, merged and redacted in diagnostic report.' );
$terminal_last_report = $destination_diagnostic_service->run( array( 'pek_destination_location_id' => 10, 'pek_destination_weight_kg' => 1.76, 'pek_destination_length_cm' => 100, 'pek_destination_width_cm' => 100, 'pek_destination_height_cm' => 100, 'pek_destination_max_place_weight_kg' => 1.76, 'pek_destination_places_count' => 1 ) );
pek_ui_assert( 'volume' === (string) ( $terminal_last_report['field_errors'][0]['field'] ?? '' ), 'PEK terminal last_report field_errors must reach diagnostic report on repeated logical failure.' );
$logical_message_length = function_exists( 'mb_strlen' ) ? mb_strlen( $logical_report['api_error_message'] ) : strlen( $logical_report['api_error_message'] );
pek_ui_assert( $logical_message_length <= 500 && ! preg_match( '/[\r\n\t]/', $logical_report['api_error_message'] ), 'api_error_message must be length-limited and control-free.' );
$logical_json = wp_json_encode( $logical_report );
pek_ui_assert( ! str_contains( (string) $logical_json, 'very-secret-key' ) && ! str_contains( (string) $logical_json, 'diagnostic-user' ) && ! str_contains( (string) $logical_json, base64_encode( 'diagnostic-user:very-secret-key' ) ) && ! str_contains( (string) $logical_json, 'api_key=' ) && ! str_contains( (string) $logical_json, 'token=' ), 'api_error_message report must redact PEK credentials and credential query keys.' );
pek_ui_assert( 2 === count( $GLOBALS['pek_ui_wc_logger']->entries ) && 'pek_logical_error' === $GLOBALS['pek_ui_wc_logger']->entries[0]['context']['error_code'] && str_contains( (string) $GLOBALS['pek_ui_wc_logger']->entries[0]['context']['api_error_message'], 'Значение volume должно быть больше 0.' ) && 'volume' === (string) ( $GLOBALS['pek_ui_wc_logger']->entries[0]['context']['field_errors'][0]['field'] ?? '' ), 'PEK logical error must be logged with safe api_error_message and field_errors.' );
$logical_log_json = wp_json_encode( $GLOBALS['pek_ui_wc_logger']->entries[0]['context'] );
pek_ui_assert( ! str_contains( (string) $logical_log_json, 'very-secret-key' ) && ! str_contains( (string) $logical_log_json, 'diagnostic-user' ) && ! str_contains( (string) $logical_log_json, 'raw_response' ) && ! str_contains( (string) $logical_log_json, 'body' ) && ! str_contains( (string) $logical_log_json, 'RejectedValue' ) && ! str_contains( (string) $logical_log_json, 'Москва, секретный адрес' ), 'PEK logical error log context must not contain credentials, raw response data or rejected values.' );
$destination_report_store->save_for_current_user( $logical_report );
$stored_logical = $destination_report_store->consume_for_current_user();
$stored_logical_json = wp_json_encode( $stored_logical );
pek_ui_assert( str_contains( (string) ( $stored_logical['api_error_message'] ?? '' ), 'Значение volume должно быть больше 0.' ) && ! str_contains( (string) $stored_logical_json, 'very-secret-key' ) && ! str_contains( (string) $stored_logical_json, 'diagnostic-user' ), 'Destination diagnostic store must preserve safe api_error_message without secrets.' );
pek_ui_assert( 'volume' === (string) ( $stored_logical['field_errors'][0]['field'] ?? '' ) && ! str_contains( (string) $stored_logical_json, 'RejectedValue' ) && ! str_contains( (string) $stored_logical_json, 'AttemptedValue' ), 'Destination diagnostic store must preserve only safe field error fields/messages.' );
$destination_report_store->save_for_current_user( $logical_report );
ob_start();
$page->render_embedded( $service );
$logical_html = (string) ob_get_clean();
pek_ui_assert( str_contains( $logical_html, 'Ошибка ПЭК' ) && str_contains( $logical_html, 'pek_logical_error' ) && str_contains( $logical_html, 'Значение volume должно быть больше 0.' ), 'Destination diagnostic UI must render safe PEK API message separately.' );
pek_ui_assert( str_contains( $logical_html, 'Ошибки полей ПЭК' ) && str_contains( $logical_html, '<th scope="row">volume</th>' ) && str_contains( $logical_html, 'Максимально допустимое значение — 100.' ) && str_contains( $logical_html, '&lt;script&gt;field&lt;/script&gt;' ) && str_contains( $logical_html, '&lt;b&gt;bad field&lt;/b&gt;' ), 'Destination diagnostic UI must render escaped PEK field errors.' );
pek_ui_assert( str_contains( $logical_html, '&lt;script&gt;alert(1)&lt;/script&gt;' ) && str_contains( $logical_html, '&lt;b&gt;bad&lt;/b&gt;' ) && ! str_contains( $logical_html, '<script>alert(1)</script>' ) && ! str_contains( $logical_html, '<b>bad</b>' ), 'Destination diagnostic UI must escape HTML in api_error_message.' );
$logical_report_html = strstr( $logical_html, 'Ошибка ПЭК' );
pek_ui_assert( false !== $logical_report_html && ! str_contains( $logical_report_html, 'very-secret-key' ) && ! str_contains( $logical_report_html, 'diagnostic-user' ) && ! str_contains( $logical_report_html, 'Authorization' ) && ! str_contains( $logical_report_html, 'Москва, секретный адрес' ) && ! str_contains( $logical_report_html, 'RejectedValue' ), 'Destination diagnostic report UI must not expose PEK credentials or rejected values.' );

$GLOBALS['pek_ui_transients'] = array();
$ui_http->nearest_response = array( 'error' => array( 'title' => '', 'message' => '' ) );
$empty_api_message_report = $destination_diagnostic_service->run( array( 'pek_destination_location_id' => 10, 'pek_destination_weight_kg' => 1.8, 'pek_destination_length_cm' => 100, 'pek_destination_width_cm' => 100, 'pek_destination_height_cm' => 100, 'pek_destination_max_place_weight_kg' => 1.8, 'pek_destination_places_count' => 1 ) );
pek_ui_assert( 'ПЭК вернул логическую ошибку без описания.' === $empty_api_message_report['api_error_message'], 'Empty PEK logical error must get a generic safe fallback.' );
$destination_report_store->save_for_current_user(
	array(
		'checked_at' => '2026-08-03 10:00:00',
		'message' => 'Диагностика направления ПЭК выполнена.',
		'location' => array(),
		'terminals' => array(
			'total_returned' => 1,
			'points' => array(
				array(
					'carrier_key' => 'pek',
					'code' => 'terminal-ui',
					'address' => 'Адрес терминала',
					'city' => '',
					'latitude' => 55.1,
					'longitude' => 82.9,
					'type' => 'terminal',
					'work_time' => 'Пн: 09:00-18:00',
					'raw_reference' => array(
						'source' => 'free',
						'branch_name' => 'Новосибирск',
						'division_name' => 'Центр',
						'limits' => array( 'maxWeight' => 10 ),
					),
				),
			),
		),
	)
);
ob_start();
$page->render_embedded( $service );
$destination_html = (string) ob_get_clean();
pek_ui_assert( str_contains( $destination_html, '<th>Филиал</th>' ) && str_contains( $destination_html, '<th>Отделение</th>' ) && str_contains( $destination_html, '<th>Время работы</th>' ), 'PEK destination diagnostics table must show branch, division and work time columns.' );
pek_ui_assert( str_contains( $destination_html, 'Новосибирск' ) && str_contains( $destination_html, 'Центр' ) && str_contains( $destination_html, 'Пн: 09:00-18:00' ), 'PEK destination diagnostics table must render branch, division and work time values.' );
$destination_report_store->save_for_current_user(
	array(
		'success' => false,
		'error_code' => 'safe',
		'api_error_message' => 'safe api message',
		'field_errors' => array(
			array(
				'field' => 'safe_field',
				'messages' => array( 'safe message' ),
				'RejectedValue' => 'must not survive',
				'AttemptedValue' => array( 'must' => 'not survive' ),
			),
			array(
				'field' => array( 'bad' ),
				'messages' => array( 'bad' ),
			),
		),
		'message' => 'safe',
		'raw_error' => array( 'secret' => true ),
		'error' => array( 'secret' => true ),
		'raw_response' => array( 'secret' => true ),
		'body' => 'secret',
		'location' => array( 'location_id' => 10, 'credentials' => 'secret' ),
		'terminals' => array( 'points' => array( array( 'code' => 'safe-point', 'nested' => array( 'Authorization' => 'Basic secret', 'body' => 'secret' ) ) ) ),
		'api_key' => 'secret',
		'login' => 'secret',
		'token' => 'secret',
	)
);
$sanitized_report = $destination_report_store->consume_for_current_user();
$sanitized_json = wp_json_encode( $sanitized_report );
pek_ui_assert( str_contains( $sanitized_json, 'safe-point' ) && str_contains( $sanitized_json, 'safe api message' ) && 'safe_field' === (string) ( $sanitized_report['field_errors'][0]['field'] ?? '' ) && str_contains( $sanitized_json, 'safe message' ) && ! str_contains( $sanitized_json, 'RejectedValue' ) && ! str_contains( $sanitized_json, 'AttemptedValue' ) && ! str_contains( $sanitized_json, 'must not survive' ) && ! str_contains( $sanitized_json, 'Authorization' ) && ! str_contains( $sanitized_json, 'api_key' ) && ! str_contains( $sanitized_json, 'raw_error' ) && ! str_contains( $sanitized_json, 'raw_response' ) && ! str_contains( $sanitized_json, 'credentials' ) && ! str_contains( $sanitized_json, 'secret' ), 'PEK destination diagnostic report store must preserve safe api_error_message/field_errors and recursively sanitize unsafe keys.' );

echo "PEK admin UI smoke OK\n";
