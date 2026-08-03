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

final class PekUiFakeHttp implements PekHttpClientInterface {
	public array $requests = array();
	public function request( string $method, string $url, array $args ): array {
		$this->requests[] = compact( 'method', 'url', 'args' );
		if ( str_contains( (string) parse_url( $url, PHP_URL_PATH ), 'findzonebycoordinates' ) ) {
			return array( 'status' => 200, 'body' => wp_json_encode( array( array( 'zoneId' => 'zone', 'zoneName' => 'Zone', 'branchUID' => 'branch', 'branchTitle' => 'Branch', 'warehousePoint' => array( 'latitude' => 1, 'longitude' => 2 ) ) ) ) );
		}
		return array( 'status' => 200, 'body' => '[]' );
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
define( 'APP_ENCRYPTION_KEY', 'pek-ui-key' );

$settings_repository = new SettingsRepository();
$settings = new PekSettings( $settings_repository );
$credentials = new PekCredentials( $settings_repository, new EncryptionService() );
$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret' ) );
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
$destination_diagnostic_service = new PekDestinationPickupDiagnosticService( new CarrierPickupPointProviderRegistry( array( $pickup_provider ) ), $location_repository, $terminal_service, $settings );
$page = new PekAdminPage(
	$settings,
	$credentials,
	new PekConnectionDiagnosticService( $settings, $credentials, $api ),
	new PekSenderWarehouseService( $api, $settings, $cache ),
	$notice_store,
	$destination_diagnostic_service,
	new PekDestinationPickupDiagnosticStore()
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

echo "PEK admin UI smoke OK\n";
