<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ );
define( 'WDC_SECRET_KEY', 'yandex-delivery-foundation-smoke-key' );
define( 'WDC_PLUGIN_DIR', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

function yd_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "[FAIL] {$message}\n" );
		exit( 1 );
	}
}

function current_time( string $type ): string { return '2026-06-22 12:00:00'; }
function sanitize_key( string $key ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) ?? '' ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function sanitize_textarea_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( mixed $text ): string { return (string) $text; }
function checked( bool $checked, bool $current = true, bool $display = true ): string { return $checked === $current ? 'checked="checked"' : ''; }
function selected( mixed $selected, mixed $current = true, bool $display = true ): string { return $selected === $current ? 'selected="selected"' : ''; }
function wp_nonce_field( string $action ): void {}
function submit_button( string $text, string $type = 'primary', string $name = 'submit', bool $wrap = true, array $other_attributes = array() ): void { echo '<button>' . esc_html( $text ) . '</button>'; }
function admin_url( string $path = '' ): string { return 'admin.php' . $path; }
function add_query_arg( array $args, string $url = '' ): string { return $url . '?' . http_build_query( $args ); }
function get_option( string $name, mixed $default = false ): mixed { return $GLOBALS['yd_options'][ $name ] ?? $default; }
function update_option( string $name, mixed $value, bool $autoload = true ): bool { $GLOBALS['yd_options'][ $name ] = $value; return true; }

require_once WDC_PLUGIN_DIR . 'src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', WDC_PLUGIN_DIR . 'src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiResponse;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryConnectionDiagnosticService;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryHttpClientInterface;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliveryEndpoints;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Infrastructure\Logging\LogRedactor;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Rules\Storage\RuleRepository;

final class YdFakeHttp implements YandexDeliveryHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	public function __construct( private YandexDeliveryApiResponse|YandexDeliveryApiException $next ) {}
	public function request( string $method, string $url, array $args = array() ): YandexDeliveryApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		if ( $this->next instanceof YandexDeliveryApiException ) {
			throw $this->next;
		}
		return $this->next;
	}
}

if ( ! defined( 'ARRAY_A' ) ) { define( 'ARRAY_A', 'ARRAY_A' ); }
if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $services = array();
		/** @var array<int,array<string,mixed>> */
		public array $countries = array();
		public function prepare( string $query, mixed ...$args ): string { foreach ( $args as $arg ) { $query = preg_replace( '/%[sd]/', "'" . addslashes( (string) $arg ) . "'", $query, 1 ) ?? $query; } return $query; }
		public function insert( string $table, array $row, array $formats = array() ): bool { $this->insert_id++; $row['id'] = $this->insert_id; if ( str_contains( $table, 'countries' ) ) { $this->countries[] = $row; } else { $this->services[] = $row; } return true; }
		public function update( string $table, array $row, array $where, array $formats = array(), array $where_formats = array() ): bool { foreach ( $this->services as &$service ) { if ( isset( $where['id'] ) && (int) $service['id'] === (int) $where['id'] ) { $service = array_merge( $service, $row ); } } return true; }
		public function delete( string $table, array $where, array $formats = array() ): bool { if ( str_contains( $table, 'countries' ) ) { $this->countries = array_values( array_filter( $this->countries, static fn( array $row ): bool => (int) ( $row['service_id'] ?? 0 ) !== (int) ( $where['service_id'] ?? 0 ) ) ); } return true; }
		public function get_row( string $query, string $output = ARRAY_A ): ?array { $rows = $this->filter_services( $query ); return $rows[0] ?? null; }
		public function get_results( string $query, string $output = ARRAY_A ): array { if ( str_contains( $query, 'countries' ) ) { return $this->countries; } return $this->filter_services( $query ); }
		public function get_col( string $query ): array { if ( str_contains( $query, 'countries' ) ) { $service_id = 0; if ( preg_match( "/service_id = '?([0-9]+)'?/", $query, $m ) ) { $service_id = (int) $m[1]; } $rows = array_filter( $this->countries, static fn( array $row ): bool => 0 === $service_id || (int) ( $row['service_id'] ?? 0 ) === $service_id ); return array_values( array_map( static fn( array $row ): string => (string) ( $row['country_code'] ?? '' ), $rows ) ); } return array(); }
		private function filter_services( string $query ): array { $rows = $this->services; if ( preg_match( "/service_key = '([^']+)'/", $query, $m ) ) { $rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['service_key'] ?? '' ) === $m[1] ) ); } if ( preg_match( "/id = '(\d+)'/", $query, $m ) || preg_match( '/id = (\d+)/', $query, $m ) ) { $rows = array_values( array_filter( $rows, static fn( array $row ): bool => (int) ( $row['id'] ?? 0 ) === (int) $m[1] ) ); } if ( str_contains( $query, 'deleted = 0' ) ) { $rows = array_values( array_filter( $rows, static fn( array $row ): bool => empty( $row['deleted'] ) ) ); } usort( $rows, static fn( array $a, array $b ): int => ( (int) ( $a['sort_order'] ?? 0 ) <=> (int) ( $b['sort_order'] ?? 0 ) ) ?: ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) ) ); return $rows; }
	}
}

$GLOBALS['yd_options'] = array();

$settings_repo = new SettingsRepository();
$encryption = new EncryptionService();
$settings = new YandexDeliverySettings( $settings_repo, $encryption );

yd_assert( YandexDeliveryEndpoints::host( YandexDeliverySettings::ENV_TEST ) === 'https://b2b.taxi.tst.yandex.net', 'test endpoint host must match Yandex sandbox host.' );
yd_assert( YandexDeliveryEndpoints::host( YandexDeliverySettings::ENV_PRODUCTION ) === 'https://b2b-authproxy.taxi.yandex.net', 'production endpoint host must match Yandex production host.' );

$settings->save_from_admin( array(
	YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST,
	'yandex_delivery_test_bearer_token' => 'secret-test-token',
	YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'station-1',
	'yandex_delivery_production_bearer_token' => 'secret-prod-token',
	YandexDeliverySettings::PRODUCTION_PLATFORM_STATION_ID_KEY => 'station-prod',
) );
$stored = get_option( 'wdc_core_settings', array() );
yd_assert( isset( $stored[ YandexDeliverySettings::TEST_TOKEN_ENCRYPTED_KEY ] ), 'test token must be stored encrypted.' );
yd_assert( ! str_contains( serialize( $stored ), 'secret-test-token' ), 'plain bearer token must not be stored in options.' );
yd_assert( $settings->credentials()->bearer_token === 'secret-test-token', 'encrypted test token must decrypt for active environment.' );

$old_encrypted = $stored[ YandexDeliverySettings::TEST_TOKEN_ENCRYPTED_KEY ];
$settings->save_from_admin( array( YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'station-1' ) );
$stored_after_empty = get_option( 'wdc_core_settings', array() );
yd_assert( $old_encrypted === $stored_after_empty[ YandexDeliverySettings::TEST_TOKEN_ENCRYPTED_KEY ], 'empty token field must keep old encrypted token.' );
$settings->save_from_admin( array( 'yandex_delivery_clear_test_bearer_token' => '1' ) );
$stored_after_clear = get_option( 'wdc_core_settings', array() );
yd_assert( '' === $stored_after_clear[ YandexDeliverySettings::TEST_TOKEN_ENCRYPTED_KEY ], 'explicit clear checkbox must remove encrypted token.' );

$settings->save_from_admin( array(
	YandexDeliverySettings::ENVIRONMENT_KEY => YandexDeliverySettings::ENV_TEST,
	'yandex_delivery_test_bearer_token' => 'secret-test-token',
	YandexDeliverySettings::TEST_PLATFORM_STATION_ID_KEY => 'station-1',
) );
$fake_http = new YdFakeHttp( new YandexDeliveryApiResponse( 200, json_encode( array( 'points' => array( array( 'id' => 'station-1', 'type' => 'pickup_point', 'available_for_dropoff' => true ) ) ) ) ?: '{}' ) );
$api = new YandexDeliveryApiClient( $settings, $fake_http );
$api->pickupPointsList( array( 'pickup_point_ids' => array( 'station-1' ), 'type' => 'pickup_point', 'available_for_dropoff' => true ) );
$request = $fake_http->requests[0];
yd_assert( $request['url'] === 'https://b2b.taxi.tst.yandex.net/api/b2b/platform/pickup-points/list', 'pickup-points/list must use active test endpoint.' );
yd_assert( ( $request['args']['headers']['Authorization'] ?? '' ) === 'Bearer secret-test-token', 'Bearer header must contain decrypted token for HTTP request only.' );
yd_assert( json_decode( (string) $request['args']['body'], true ) === array( 'pickup_point_ids' => array( 'station-1' ), 'type' => 'pickup_point', 'available_for_dropoff' => true ), 'pickup-points/list diagnostic payload must filter by pickup_point_ids, type and available_for_dropoff.' );

$diagnostics = new YandexDeliveryConnectionDiagnosticService( $settings, $api );
$result = $diagnostics->checkPickupPoint();
yd_assert( $result['success'] && 'success' === $result['status'], 'successful diagnostic must require found pickup_point with available_for_dropoff=true.' );
yd_assert( ! str_contains( serialize( $result ), 'secret-test-token' ), 'successful diagnostics must not include bearer token.' );
$diagnostic_request = $fake_http->requests[1];
yd_assert( json_decode( (string) $diagnostic_request['args']['body'], true ) === array( 'pickup_point_ids' => array( 'station-1' ), 'type' => 'pickup_point', 'available_for_dropoff' => true ), 'explicit connection diagnostic must use the documented pickup-points/list payload.' );

$cases = array(
	'point_not_found' => array( 200, array( 'points' => array() ) ),
	'dropoff_unavailable' => array( 200, array( 'points' => array( array( 'id' => 'station-1', 'type' => 'pickup_point', 'available_for_dropoff' => false ) ) ) ),
	'unsupported_point_type' => array( 200, array( 'points' => array( array( 'id' => 'station-1', 'type' => 'terminal', 'available_for_dropoff' => true ) ) ) ),
	'auth_failed' => array( 401, array( 'code' => 'unauthorized', 'message' => 'bad token' ) ),
	'server_error' => array( 500, array( 'code' => 'server_error', 'message' => 'try later' ) ),
);
foreach ( $cases as $expected => [$code, $body] ) {
	$diag = new YandexDeliveryConnectionDiagnosticService( $settings, new YandexDeliveryApiClient( $settings, new YdFakeHttp( new YandexDeliveryApiResponse( $code, json_encode( $body ) ?: '{}' ) ) ) );
	$out = $diag->checkPickupPoint();
	yd_assert( $expected === $out['status'], "diagnostic status must be {$expected}." );
}

$malformed = new YandexDeliveryConnectionDiagnosticService( $settings, new YandexDeliveryApiClient( $settings, new YdFakeHttp( new YandexDeliveryApiResponse( 200, '{bad json' ) ) ) );
yd_assert( 'malformed_json' === $malformed->checkPickupPoint()['status'], 'malformed JSON must be reported separately.' );
$empty_json = new YandexDeliveryConnectionDiagnosticService( $settings, new YandexDeliveryApiClient( $settings, new YdFakeHttp( new YandexDeliveryApiResponse( 200, '' ) ) ) );
yd_assert( 'empty_json' === $empty_json->checkPickupPoint()['status'], 'empty JSON must be reported separately.' );
try {
	( new YandexDeliveryApiClient( $settings, new YdFakeHttp( new YandexDeliveryApiResponse( 400, json_encode( array( 'code' => 'bad_station', 'message' => 'Station phone +79998887766 email a@example.com address full street' ) ) ?: '{}' ) ) ) )->pickupPointsList( array( 'phone' => '+79998887766', 'email' => 'a@example.com', 'address' => 'full street' ) );
	yd_assert( false, 'Yandex API error must throw exception.' );
} catch ( YandexDeliveryApiException $exception ) {
	$payload = serialize( $exception->details() ) . $exception->getMessage();
	yd_assert( str_contains( $payload, 'bad_station' ), 'Yandex code/message must be extracted into exception details.' );

	yd_assert( ! str_contains( $payload, 'secret-test-token' ) && ! str_contains( $payload, '+79998887766' ) && ! str_contains( $payload, 'a@example.com' ) && ! str_contains( $payload, 'full street' ), 'exception diagnostics must redact token, phone, email and full address.' );
}
$warnings = array();
$previous_handler = set_error_handler( static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
	$warnings[] = $errstr;
	return true;
}, E_WARNING );
try {
	( new YandexDeliveryApiClient( $settings, new YdFakeHttp( new YandexDeliveryApiResponse( 400, json_encode( array( 'error' => array( 'message' => 'Something failed' ) ) ) ?: '{}' ) ) ) )->pickupPointsList( array() );
	yd_assert( false, 'Yandex API error with nested message must throw exception.' );
} catch ( YandexDeliveryApiException $exception ) {
	yd_assert( '' === (string) ( $exception->details()['yandex_error_code'] ?? 'not-empty' ), 'nested error without scalar code must produce empty Yandex error code.' );
	yd_assert( str_contains( $exception->getMessage(), 'Something failed' ), 'nested error message must still be extracted.' );
} finally {
	if ( null !== $previous_handler ) {
		set_error_handler( $previous_handler );
	} else {
		restore_error_handler();
	}
}
yd_assert( array() === $warnings, 'nested error array without code must not trigger PHP warnings.' );
$redacted = ( new LogRedactor() )->redact_context( array( 'Authorization' => 'Bearer secret-test-token', 'phone' => '+79998887766', 'email' => 'a@example.com', 'address' => 'full street' ) );
yd_assert( '[redacted]' === $redacted['Authorization'] && '[redacted]' === $redacted['phone'] && '[redacted]' === $redacted['email'] && '[redacted]' === $redacted['address'], 'log redactor must redact auth, phone, email and address keys.' );

$GLOBALS['wpdb'] = new wpdb();
$service_repo = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$country_repo = new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] );
$manager = new DeliveryServiceManager( $service_repo, $country_repo, new RuleRepository( $GLOBALS['wpdb'] ), ( new ReflectionClass( WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory::class ) )->newInstanceWithoutConstructor() );
$manager->ensure_builtin_services();
$service = $service_repo->find_by_service_key( YandexDeliverySettings::SERVICE_KEY );
yd_assert( $service instanceof DeliveryService, 'built-in yandex_delivery service must be created.' );
yd_assert( YandexDeliverySettings::CARRIER_KEY === $service->carrier_key && YandexDeliverySettings::TITLE === $service->title, 'built-in service must have Yandex carrier key and title.' );
yd_assert( false === $service->enabled, 'built-in yandex_delivery service must be disabled by default.' );
yd_assert( true === $service_repo->is_predefined_service_key( YandexDeliverySettings::SERVICE_KEY ), 'built-in yandex_delivery service must not be deletable as custom.' );
yd_assert( array( 'RU' ) === $country_repo->countries( (int) $service->id ), 'built-in yandex_delivery service must be RU-only.' );
yd_assert( 50 === $service->sort_order, 'built-in yandex_delivery service must be sorted after DPD.' );

$admin_source = file_get_contents( WDC_PLUGIN_DIR . 'src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ) ?: '';
$plugin_source = file_get_contents( WDC_PLUGIN_DIR . 'src/Core/Plugin.php' ) ?: '';
$shipment_registry_line = preg_match( '/CarrierShipmentAdapterRegistry::class.*?\) \);/s', $plugin_source, $m ) ? $m[0] : '';
$carrier_registry_block = preg_match( '/CarrierRegistry::class,.*?return \$registry;/s', $plugin_source, $m ) ? $m[0] : '';
yd_assert( str_contains( $admin_source, "\$tabs['yandex_delivery_settings'] = 'Данные для входа';" ), 'admin tab must be named Данные для входа.' );
yd_assert( str_contains( $carrier_registry_block, 'YandexDeliveryCarrier::class' ), 'Yandex Delivery checkout carrier must be registered.' );
yd_assert( ! str_contains( $shipment_registry_line, 'YandexDelivery' ) && ! str_contains( $shipment_registry_line, 'yandex_delivery' ), 'Yandex Delivery must not register shipment adapter in this stage.' );
yd_assert( substr_count( $admin_source, 'checkPickupPoint()' ) === 1 && str_contains( $admin_source, "'check_yandex_delivery_connection' === \$action" ), 'Yandex API diagnostic must be reachable only from explicit check action.' );
yd_assert( str_contains( $admin_source, "'save_yandex_delivery_settings' === \$action" ), 'Yandex settings save action must exist.' );
preg_match( '/' . preg_quote( "'save_yandex_delivery_settings' === \$action", '/' ) . '[\s\S]*?\n\t\t\t}/', $admin_source, $save_match );
$save_block = (string) ( $save_match[0] ?? '' );
yd_assert( '' !== $save_block && ! str_contains( $save_block, 'checkPickupPoint' ), 'saving settings must not automatically call Yandex API.' );
preg_match( '/private function render_yandex_delivery_settings_tab.*?private function render_dpd_settings_tab/s', $admin_source, $render_match );
$render_block = (string) ( $render_match[0] ?? '' );
yd_assert( '' !== $render_block && ! str_contains( $render_block, 'checkPickupPoint' ), 'rendering settings must not automatically call Yandex API.' );

echo "[OK] Yandex Delivery foundation smoke passed.\n";
