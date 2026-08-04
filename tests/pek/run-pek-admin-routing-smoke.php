<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\Admin\PekAdminPage;
use WallsShop\WDC\Carriers\Pek\Admin\PekAdminNoticeStore;
use WallsShop\WDC\Carriers\Pek\Admin\PekDestinationPickupDiagnosticService;
use WallsShop\WDC\Carriers\Pek\Admin\PekDestinationPickupDiagnosticStore;
use WallsShop\WDC\Carriers\Pek\Admin\PekQuoteDiagnosticService;
use WallsShop\WDC\Carriers\Pek\Admin\PekQuoteDiagnosticStore;
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
use WallsShop\WDC\Carriers\Pek\Quote\PekLightCargoSurchargePolicy;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteCargoBuilder;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteMessageSanitizer;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteRequestBuilder;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteResponseParser;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteService;
use WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;

function pek_route_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class PekRedirectException extends RuntimeException {
	public function __construct( public string $url ) {
		parent::__construct( $url );
	}
}

function is_admin(): bool { return true; }
function current_user_can( string $capability ): bool { $GLOBALS['pek_route_capability_checked'] = ( $GLOBALS['pek_route_capability_checked'] ?? 0 ) + 1; return true; }
function check_admin_referer( string $action ): bool { $GLOBALS['pek_route_nonce_checked'] = ( $GLOBALS['pek_route_nonce_checked'] ?? 0 ) + 1; return true; }
function wp_safe_redirect( string $url ): bool { throw new PekRedirectException( $url ); }
function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
function add_query_arg( array $args, string $url ): string { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
function current_time( string $type ): int|string { return 'timestamp' === $type ? 1785652800 : '2026-08-02 12:00:00'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function wp_unslash( mixed $value ): mixed {
	if ( is_array( $value ) ) {
		return array_map( 'wp_unslash', $value );
	}
	return is_string( $value ) ? stripslashes( $value ) : $value;
}
function sanitize_text_field( string $value ): string { return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $value ) ) ?? '' ); }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ); }
function sanitize_email( string $value ): string { return trim( $value ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_route_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['pek_route_options'][ $option ] = $value; return true; }
function get_current_user_id(): int { return (int) ( $GLOBALS['pek_route_current_user_id'] ?? 7 ); }
function get_transient( string $key ): mixed { return $GLOBALS['pek_route_transients'][ $key ]['value'] ?? false; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['pek_route_transients'][ $key ] = array( 'value' => $value, 'expiration' => $expiration ); return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['pek_route_transients'][ $key ] ); return true; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public array $services = array();
		public array $locations = array();
		public array $pek_location_mappings = array();
		public array $pek_terminals = array();
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function insert( string $table, array $data, array $format = array() ): bool { $data['id'] = ++$this->insert_id; $this->services[] = $data; return true; }
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool { return true; }
		public function get_row( string $query, mixed $output = null ): ?array {
			if ( preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (string) $row['service_key'] === $matches[1] && ( ! str_contains( $query, 'deleted = 0' ) || empty( $row['deleted'] ) ) ) {
						return $row;
					}
				}
			}
			return null;
		}
		public function get_results( string $query, mixed $output = null ): array { return $this->services; }
	}
}

final class PekRouteFakeHttp implements PekHttpClientInterface {
	public array $requests = array();
	public function __construct( private array $responses ) {}
	public function request( string $method, string $url, array $args ): array {
		$this->requests[] = array( 'method' => strtoupper( $method ), 'url' => $url, 'args' => $args );
		return array_shift( $this->responses ) ?? array( 'status' => 200, 'body' => '[]' );
	}
}

function pek_route_json( mixed $body ): array {
	return array( 'status' => 200, 'body' => json_encode( $body, JSON_UNESCAPED_UNICODE ) ?: 'null' );
}

function pek_route_page( PekRouteFakeHttp $http, SettingsRepository $settings_repository, PekSenderWarehouseSearchCache $cache ): DeliveryServicesAdminPage {
	$GLOBALS['wpdb'] = new wpdb();
	$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
	$service = $services->ensure_pek_service();
	$settings = new PekSettings( $settings_repository );
	$credentials = new PekCredentials( $settings_repository, new EncryptionService() );
	$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret-key' ) );
	$api = new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) );
	$location_repository = new LocationRepository( $GLOBALS['wpdb'] );
	$location_resolver = new PekLocationResolver( $location_repository, new PekAddressBuilder(), new PekLocationMappingRepository( $GLOBALS['wpdb'] ), $api, $settings );
	$terminal_service = new PekTerminalService( $location_resolver, $api, new PekCargoConstraintsConverter(), new PekDestinationTerminalSearchCache(), new PekTerminalRepository( $GLOBALS['wpdb'] ), $settings );
	$pickup_provider = new PekPickupPointProvider( $terminal_service );
	$pickup_registry = new CarrierPickupPointProviderRegistry( array( $pickup_provider ) );
	$quote_builder = new PekQuoteRequestBuilder( $settings, new PekQuoteCargoBuilder() );
	$quote_service = new PekQuoteService( $credentials, $api, $quote_builder, new PekQuoteResponseParser(), new PekQuoteMessageSanitizer( $credentials, $settings ), new PekLightCargoSurchargePolicy( $settings ) );
	$pek_admin = new PekAdminPage(
		$settings,
		$credentials,
		new PekConnectionDiagnosticService( $settings, $credentials, $api ),
		new PekSenderWarehouseService( $api, $settings, $cache ),
		new PekAdminNoticeStore(),
		new PekDestinationPickupDiagnosticService( $pickup_registry, $location_repository, $terminal_service, $settings, $credentials ),
		new PekDestinationPickupDiagnosticStore(),
		new PekQuoteDiagnosticService( $location_repository, $location_resolver, new PekAddressBuilder(), $settings, $pickup_registry, $quote_service ),
		new PekQuoteDiagnosticStore()
	);
	$page = ( new ReflectionClass( DeliveryServicesAdminPage::class ) )->newInstanceWithoutConstructor();
	foreach ( array( 'services' => $services, 'pek_admin' => $pek_admin ) as $property => $value ) {
		$ref = new ReflectionProperty( DeliveryServicesAdminPage::class, $property );
		$ref->setAccessible( true );
		$ref->setValue( $page, $value );
	}
	$GLOBALS['pek_route_service_id'] = (int) $service->id;

	return $page;
}

function pek_route_run_action( DeliveryServicesAdminPage $page, string $action, array $post = array() ): string {
	$GLOBALS['pek_route_capability_checked'] = 0;
	$GLOBALS['pek_route_nonce_checked'] = 0;
	$_POST = array_merge(
		array(
			'wdc_delivery_services_action' => $action,
			'service_key' => PekSettings::SERVICE_KEY,
			'id' => (string) $GLOBALS['pek_route_service_id'],
		),
		$post
	);
	try {
		$page->handle_actions();
		pek_route_assert( false, 'PEK action must redirect through wp_safe_redirect.' );
	} catch ( PekRedirectException $exception ) {
		pek_route_assert( $GLOBALS['pek_route_capability_checked'] === 1 && $GLOBALS['pek_route_nonce_checked'] === 1, 'PEK action must pass shared capability and nonce entry contract.' );
		return $exception->url;
	}
}

$GLOBALS['pek_route_options'] = array();
$GLOBALS['pek_route_transients'] = array();
define( 'APP_ENCRYPTION_KEY', 'pek-route-test-key' );

$settings_repository = new SettingsRepository();
$cache = new PekSenderWarehouseSearchCache();
$http = new PekRouteFakeHttp( array(
	pek_route_json( array( array( 'type' => 3 ) ) ),
	pek_route_json( array( array( 'shortName' => 'RU', 'codeByClassifier' => '643' ) ) ),
	pek_route_json( array( array( 'name' => 'ООО' ) ) ),
	pek_route_json( array( 'freeDepartments' => array( array( 'warehouseId' => 'wh-route', 'branchId' => 'br', 'branchName' => 'Branch', 'divisionName' => 'Division', 'address' => 'Address' ) ), 'paidDepartments' => array() ) ),
) );
$page = pek_route_page( $http, $settings_repository, $cache );
$settings = new PekSettings( $settings_repository );

$cache->save_for_current_user( array( 'success' => true, 'message' => 'old', 'items' => array( array( 'warehouseId' => 'old-wh' ) ), 'requested' => array( 'departmentOperation' => 2, 'type' => 3 ) ) );
$redirect = pek_route_run_action( $page, 'save_pek_settings', array( PekSettings::LOGIN_KEY => 'login', PekSettings::REQUEST_TIMEOUT_KEY => '22', PekSettings::SENDER_FULL_NAME_KEY => 'ООО Test' ) );
pek_route_assert( $settings->request_timeout() === 22 && str_contains( $redirect, 'service=pek' ) && str_contains( $redirect, 'tab=pek_settings' ), 'save_pek_settings must reach PekAdminPage and redirect to PEK tab.' );
pek_route_assert( array() === $cache->current_for_current_user(), 'save_pek_settings must clear current-user PEK warehouse search cache.' );
pek_route_assert( array() === $settings_repository->get_array( 'pek_admin_notice', array() ), 'PEK admin notices must not be persisted in SettingsRepository.' );

$redirect = pek_route_run_action( $page, 'check_pek_connection' );
$diagnostic = $settings->last_diagnostic();
pek_route_assert( $diagnostic['success'] === true, 'check_pek_connection must save successful diagnostic: ' . ( json_encode( $diagnostic, JSON_UNESCAPED_UNICODE ) ?: '' ) );
pek_route_assert( array_column( array_slice( $http->requests, 0, 3 ), 'method' ) === array( 'GET', 'POST', 'POST' ), 'check_pek_connection must use GET, POST, POST fake HTTP sequence.' );
pek_route_assert( str_contains( $redirect, 'service=pek' ) && str_contains( $redirect, 'tab=pek_settings' ), 'check_pek_connection must redirect to PEK tab.' );

$redirect = pek_route_run_action( $page, 'search_pek_sender_warehouse', array( 'pek_warehouse_search_address' => 'Новосибирск' ) );
pek_route_assert( ( $cache->current_for_current_user()['items'][0]['warehouseId'] ?? '' ) === 'wh-route' && str_contains( $redirect, 'service=pek' ) && str_contains( $redirect, 'tab=pek_settings' ), 'search_pek_sender_warehouse must reach PekAdminPage, save user cache, and redirect to PEK tab.' );

$redirect = pek_route_run_action( $page, 'select_pek_sender_warehouse', array( 'pek_sender_warehouse_id' => 'wh-route' ) );
pek_route_assert( $settings->sender_warehouse()['warehouseId'] === 'wh-route' && str_contains( $redirect, 'service=pek' ) && str_contains( $redirect, 'tab=pek_settings' ), 'select_pek_sender_warehouse must reach PekAdminPage and redirect to PEK tab.' );
$notice_store = new PekAdminNoticeStore();
$GLOBALS['pek_route_current_user_id'] = 8;
pek_route_assert( array() === $notice_store->consume_for_current_user(), 'PEK notice must be scoped away from another admin user.' );
$GLOBALS['pek_route_current_user_id'] = 7;
$notice = $notice_store->consume_for_current_user();
pek_route_assert( array() !== $notice && array() === $notice_store->consume_for_current_user() && $notice_store->ttl_seconds() <= 120, 'PEK notice must be one-shot and TTL must be <= 120 seconds.' );

$before = $settings->request_timeout();
$_POST = array(
	'wdc_delivery_services_action' => 'save_pek_settings',
	'service_key' => 'cdek',
	'id' => (string) $GLOBALS['pek_route_service_id'],
	PekSettings::REQUEST_TIMEOUT_KEY => '11',
);
try {
	$page->handle_actions();
	pek_route_assert( false, 'Wrong service PEK action must still PRG redirect safely.' );
} catch ( PekRedirectException $exception ) {
	pek_route_assert( $settings->request_timeout() === $before && str_contains( $exception->url, 'service=pek' ) && str_contains( $exception->url, 'tab=pek_settings' ), 'Wrong service_key must not invoke PEK component.' );
}

pek_route_assert( count( $http->requests ) === 4 && str_starts_with( $http->requests[3]['url'], PekSettings::BASE_URL ), 'PEK admin routing smoke must use fake HTTP only and perform no production call.' );

$destination_settings_repository = new SettingsRepository();
$destination_cache = new PekSenderWarehouseSearchCache();
$destination_http = new PekRouteFakeHttp( array(
	pek_route_json( array( 'zoneId' => 'z', 'branchUID' => 'b', 'mainWarehouseId' => 'main', 'GeoData' => array( 'precision' => 'exact', 'Address' => array( 'formatted' => array() ) ) ) ),
) );
$destination_page = pek_route_page( $destination_http, $destination_settings_repository, $destination_cache );
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 11, 'country_code' => 'RU', 'city_name' => 'Линево', 'place_name' => 'Линево', 'display_name' => 'Линево', 'active' => 1 ),
);
$destination_store = new PekDestinationPickupDiagnosticStore();
$destination_store->save_for_current_user(
	array(
		'success' => true,
		'message' => 'old success',
		'terminals' => array( 'points' => array( array( 'code' => 'old-terminal', 'raw_response' => array( 'secret' => true ) ) ) ),
		'raw_response' => array( 'secret' => true ),
	)
);
$destination_redirect = pek_route_run_action(
	$destination_page,
	'diagnose_pek_destination_pickup',
	array(
		'pek_destination_location_id' => 11,
		'pek_destination_weight_kg' => 1,
		'pek_destination_length_cm' => 10,
		'pek_destination_width_cm' => 10,
		'pek_destination_height_cm' => 10,
		'pek_destination_max_place_weight_kg' => 1,
		'pek_destination_places_count' => 1,
	)
);
$fresh_report = $destination_store->consume_for_current_user();
$fresh_json = wp_json_encode( $fresh_report );
pek_route_assert( str_contains( $destination_redirect, 'service=pek' ) && false === ( $fresh_report['success'] ?? true ) && 'pek_invalid_findzone_formatted_address' === (string) ( $fresh_report['error_code'] ?? '' ), 'Destination diagnostic malformed zone response must save current safe failure report.' );
pek_route_assert( ! str_contains( $fresh_json, 'old-terminal' ) && ! str_contains( $fresh_json, 'raw_response' ) && ! str_contains( $fresh_json, 'secret' ), 'Destination diagnostic action must clear stale report before run and store only sanitized failure data.' );

echo "PEK admin routing smoke OK\n";
