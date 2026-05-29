<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'pickup-address-search-test-key' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function pickup_rest_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_verify_nonce( string $nonce, string $action ): bool { return 'nonce' === $nonce && 'wp_rest' === $action; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function is_wp_error( mixed $value ): bool { return false; }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
function wp_remote_post( string $url, array $args = array() ): array {
	$GLOBALS['wdc_pickup_rest_http_requests'][] = array( 'url' => $url, 'args' => $args );
	return array_shift( $GLOBALS['wdc_pickup_rest_http_queue'] ) ?: array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'suggestions' => array() ), JSON_UNESCAPED_UNICODE ) );
}
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_pickup_rest_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string|null $autoload = null ): bool { $GLOBALS['wdc_pickup_rest_options'][ $key ] = $value; return true; }
function rest_ensure_response( mixed $data ): mixed { return $data; }
function __return_true(): bool { return true; }
function register_rest_route( string $namespace, string $route, array $args ): bool {
	$GLOBALS['wdc_rest_routes'][] = compact( 'namespace', 'route', 'args' );
	return true;
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( public string $code, public string $message, public array $data = array() ) {}
		public function get_error_code(): string { return $this->code; }
		public function get_error_message(): string { return $this->message; }
		public function get_error_data(): array { return $this->data; }
	}
}

final class WdcPickupRestRequest {
	public function __construct( private array $params = array(), private array $headers = array() ) {}
	public function get_param( string $key ): mixed { return $this->params[ $key ] ?? ''; }
	public function get_header( string $key ): string { return (string) ( $this->headers[ $key ] ?? '' ); }
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $pickup_rows = array();
		/** @var array<string,array<int,array<string,mixed>>> */
		public array $tables = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $value, $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }

		public function get_row( string $query, mixed $output = null ): ?array {
			$rows = $this->rows_for_query( $query );
			if ( preg_match( '/WHERE id = ([0-9]+)/', $query, $m ) ) {
				foreach ( $rows as $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === (int) $m[1] ) {
						return $row;
					}
				}
			}
			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			$rows = $this->rows_for_query( $query );
			if ( str_contains( $query, 'active = 1' ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => 1 === (int) ( $row['active'] ?? 0 ) ) );
			}
			if ( preg_match( "/carrier_key = '([^']+)'/", $query, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['carrier_key'] ?? '' ) === $m[1] ) );
			}
			if ( preg_match( "/postcode = '([^']+)'/", $query, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['postcode'] ?? '' ) === $m[1] ) );
			}
			if ( preg_match( '/longitude BETWEEN ([0-9.\\-]+) AND ([0-9.\\-]+)/', $query, $m ) ) {
				$min = (float) $m[1];
				$max = (float) $m[2];
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (float) ( $row['longitude'] ?? 999 ) >= $min && (float) ( $row['longitude'] ?? -999 ) <= $max ) );
			}
			if ( preg_match( '/latitude BETWEEN ([0-9.\\-]+) AND ([0-9.\\-]+)/', $query, $m ) ) {
				$min = (float) $m[1];
				$max = (float) $m[2];
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (float) ( $row['latitude'] ?? 999 ) >= $min && (float) ( $row['latitude'] ?? -999 ) <= $max ) );
			}
			if ( preg_match( "/point_type IN \\(([^)]+)\\)/", $query, $m ) ) {
				$types = array_map( static fn( string $type ): string => trim( $type, " '" ), explode( ',', $m[1] ) );
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => in_array( (string) ( $row['point_type'] ?? '' ), $types, true ) ) );
			}
			if ( preg_match( "/city_name LIKE '%([^']+)%'/", $query, $m ) && ! str_contains( $query, 'point_code LIKE' ) ) {
				$city = $m[1];
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => str_contains( (string) ( $row['city_name'] ?? '' ), $city ) ) );
			}
			if ( preg_match( "/point_code LIKE '%([^']+)%'/", $query, $m ) ) {
				$q = $m[1];
				$rows = array_values(
					array_filter(
						$rows,
						static fn( array $row ): bool => str_contains( (string) ( $row['point_code'] ?? '' ), $q )
							|| str_contains( (string) ( $row['postcode'] ?? '' ), $q )
							|| str_contains( (string) ( $row['city_name'] ?? '' ), $q )
							|| str_contains( (string) ( $row['address'] ?? '' ), $q )
					)
				);
			}
			if ( preg_match( '/LIMIT ([0-9]+)/', $query, $m ) ) {
				$rows = array_slice( $rows, 0, (int) $m[1] );
			}
			return $rows;
		}

		private function rows_for_query( string $query ): array {
			if ( preg_match( '/FROM ([A-Za-z0-9_]+)/', $query, $m ) ) {
				return $this->tables[ $m[1] ] ?? array();
			}
			return $this->pickup_rows;
		}
	}
}

use WallsShop\WDC\Pickup\Rest\PickupPointsRestController;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Pickup\Search\PickupAddressSearchService;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataSuggestionClient;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wdc_pickup_rest_http_requests'] = array();
$GLOBALS['wdc_pickup_rest_http_queue'] = array();
$GLOBALS['wpdb']->prefix = 'wp_';
$GLOBALS['wpdb']->pickup_rows = array(
	array( 'id' => 1, 'carrier_key' => 'russian_post', 'point_code' => '630001-a', 'point_type' => 'OPS', 'address' => 'Ленина, 1', 'city_name' => 'Новосибирск', 'region_name' => 'НСО', 'postcode' => '630001', 'latitude' => 55.01, 'longitude' => 82.91, 'work_time' => '09-18', 'active' => 1 ),
	array( 'id' => 2, 'carrier_key' => 'russian_post', 'point_code' => '630002-b', 'point_type' => 'PVZ', 'address' => 'Советская, 2', 'city_name' => 'Новосибирск', 'region_name' => 'НСО', 'postcode' => '630002', 'latitude' => 55.02, 'longitude' => 82.92, 'work_time' => '10-19', 'active' => 1 ),
	array( 'id' => 3, 'carrier_key' => 'russian_post', 'point_code' => '101000-c', 'point_type' => 'APS', 'address' => 'Тверская, 3', 'city_name' => 'Москва', 'region_name' => 'Москва', 'postcode' => '101000', 'latitude' => 55.76, 'longitude' => 37.61, 'work_time' => '24/7', 'active' => 1 ),
);

$repo = new RussianPostPickupPointRepository( $GLOBALS['wpdb'] );
$GLOBALS['wpdb']->tables = array( $repo->main_table() => $GLOBALS['wpdb']->pickup_rows, 'wp_wdc_pickup_points' => array() );
$settings = new SettingsRepository();
$type_settings = new RussianPostPickupPointTypeSettings( $settings );
$encryption = new EncryptionService();
$token_pool = new DaDataTokenPool( $settings, $encryption );
$settings->replace(
	array(
		'dadata_suggestions_enabled' => true,
		'dadata_suggestions_tokens' => array(
			array(
				'id' => 'pickup-address-token',
				'encrypted_token' => $encryption->encrypt( 'secret-token' ),
				'masked_token' => '********oken',
				'daily_limit' => 2,
				'enabled' => true,
			),
		),
	)
);
$address_settings = new AddressSuggestionSettings( $settings, $encryption, $token_pool );
$address_client = new DaDataSuggestionClient( $address_settings, $token_pool, new Logger() );
$address_search = new PickupAddressSearchService( $repo, $address_client, $token_pool, $address_settings );
$controller = new PickupPointsRestController( $repo, $type_settings, $address_search );
$controller->register();
pickup_rest_assert( 4 === count( $GLOBALS['wdc_rest_routes'] ?? array() ), 'REST controller must register four routes including address search.' );
pickup_rest_assert( array( 'OPS', 'PVZ', 'APS' ) === $type_settings->enabled_types(), 'Pickup type defaults must enable OPS/PVZ/APS.' );
$route_by_path = array();
foreach ( $GLOBALS['wdc_rest_routes'] as $route ) {
	$route_by_path[ $route['route'] ] = $route;
}
$forbidden = $route_by_path['/points/address-search']['args']['permission_callback']( new WdcPickupRestRequest() );
pickup_rest_assert( $forbidden instanceof WP_Error && 'wdc_forbidden' === $forbidden->get_error_code() && 403 === (int) ( $forbidden->get_error_data()['status'] ?? 0 ), 'Address search without REST nonce must return 403.' );
pickup_rest_assert( true === $route_by_path['/points/address-search']['args']['permission_callback']( new WdcPickupRestRequest( headers: array( 'X-WP-Nonce' => 'nonce' ) ) ), 'Address search with valid REST nonce must be allowed.' );
pickup_rest_assert( '__return_true' === $route_by_path['/points']['args']['permission_callback'] && '__return_true' === $route_by_path['/points/search']['args']['permission_callback'] && '__return_true' === $route_by_path['/points/(?P<id>\\d+)']['args']['permission_callback'], 'Read-only pickup endpoints must remain public.' );

$bbox = $controller->points( array( 'carrier' => 'russian_post', 'bbox' => '82.90,55.00,82.93,55.03' ) );
pickup_rest_assert( 2 === count( $bbox ) && array( 1, 2 ) === array_column( $bbox, 'id' ), 'bbox must return only points inside requested area.' );
pickup_rest_assert( array( 'russian_post', 'russian_post' ) === array_column( $bbox, 'carrier' ), 'REST summaries must expose russian_post carrier from the carrier-specific table.' );
$removed_fields = array( 'brand_name', 'ecom_options', 'payment', 'accepts_cash', 'accepts_card', 'partial_redemption', 'return_available', 'fitting_available', 'contents_checking', 'functionality_checking', 'weight_limit_grams', 'raw_reference', 'work_time_json', 'source_hash' );
foreach ( $removed_fields as $removed_field ) {
	pickup_rest_assert( ! array_key_exists( $removed_field, $bbox[0] ), 'summary must not expose removed field: ' . $removed_field );
}

$type_filtered = $controller->points( array( 'carrier' => 'russian_post', 'bbox' => '0,0,180,90', 'type' => array( 'APS' ) ) );
pickup_rest_assert( 1 === count( $type_filtered ) && 3 === $type_filtered[0]['id'], 'type filter must work.' );

$disabled_pvz = $settings->all();
$disabled_pvz['russian_post_domestic_pickup_type_pvz_enabled'] = false;
update_option( 'wdc_core_settings', $disabled_pvz, false );
$without_pvz = $controller->points( array( 'carrier' => 'russian_post', 'bbox' => '0,0,180,90' ) );
pickup_rest_assert( array( 1, 3 ) === array_column( $without_pvz, 'id' ), 'Disabled PVZ must be excluded from /points.' );
$requested_disabled_pvz = $controller->points( array( 'carrier' => 'russian_post', 'bbox' => '0,0,180,90', 'type' => array( 'PVZ' ) ) );
pickup_rest_assert( array() === $requested_disabled_pvz, 'Requested PVZ must return empty when PVZ is disabled.' );
$requested_enabled_ops = $controller->points( array( 'carrier' => 'russian_post', 'bbox' => '0,0,180,90', 'type' => array( 'OPS' ) ) );
pickup_rest_assert( 1 === count( $requested_enabled_ops ) && 1 === $requested_enabled_ops[0]['id'], 'Requested OPS must return OPS when OPS is enabled.' );
$detail_ops_enabled = $controller->detail( array( 'id' => 1 ) );
pickup_rest_assert( is_array( $detail_ops_enabled ) && 1 === $detail_ops_enabled['id'], 'detail OPS must be available when OPS is enabled.' );
$detail_pvz_disabled = $controller->detail( array( 'id' => 2 ) );
pickup_rest_assert( $detail_pvz_disabled instanceof WP_Error && 'not_found' === $detail_pvz_disabled->get_error_code(), 'detail PVZ must return 404 when PVZ is disabled.' );
$all_disabled = $settings->all();
$all_disabled['russian_post_domestic_pickup_type_ops_enabled'] = false;
$all_disabled['russian_post_domestic_pickup_type_pvz_enabled'] = false;
$all_disabled['russian_post_domestic_pickup_type_aps_enabled'] = false;
update_option( 'wdc_core_settings', $all_disabled, false );
pickup_rest_assert( array( 'OPS' ) === $type_settings->enabled_types(), 'All pickup types disabled must automatically re-enable OPS.' );
update_option( 'wdc_core_settings', array(), false );
$detail_pvz_enabled = $controller->detail( array( 'id' => 2 ) );
pickup_rest_assert( is_array( $detail_pvz_enabled ) && 2 === $detail_pvz_enabled['id'], 'detail PVZ must be available when PVZ is enabled.' );
$detail_pvz_requested_ops = $controller->detail( array( 'id' => 2, 'type' => array( 'OPS' ) ) );
pickup_rest_assert( $detail_pvz_requested_ops instanceof WP_Error && 'not_found' === $detail_pvz_requested_ops->get_error_code(), 'detail PVZ with type[]=OPS must return 404.' );

$limited = $controller->points( array( 'carrier' => 'russian_post', 'bbox' => '0,0,180,90', 'limit' => '1' ) );
pickup_rest_assert( 1 === count( $limited ), 'limit must clamp result count.' );

$search = $controller->search( array( 'carrier' => 'russian_post', 'q' => '630002', 'city' => 'Новосибирск', 'limit' => '1000' ) );
pickup_rest_assert( 1 === count( $search ) && 2 === $search[0]['id'], 'search must find by postcode/city/address and clamp limit.' );

$settings->replace(
	array(
		'dadata_suggestions_enabled' => true,
		'dadata_suggestions_tokens' => array(
			array(
				'id' => 'pickup-address-token',
				'encrypted_token' => $encryption->encrypt( 'secret-token' ),
				'masked_token' => '********oken',
				'daily_limit' => 2,
				'enabled' => true,
			),
		),
	)
);
$GLOBALS['wdc_pickup_rest_http_queue'][] = array(
	'response' => array( 'code' => 200 ),
	'body' => wp_json_encode(
		array(
			'suggestions' => array(
				array(
					'value' => 'г Новосибирск, ул Ленина, д 15',
					'unrestricted_value' => '630099, Новосибирская обл, г Новосибирск, ул Ленина, д 15',
					'data' => array( 'geo_lat' => '55.012', 'geo_lon' => '82.915' ),
				),
			),
		),
		JSON_UNESCAPED_UNICODE
	),
);
$address_result = $controller->address_search( array( 'carrier' => 'russian_post', 'query' => 'Ленина 15', 'country_code' => 'RU' ) );
pickup_rest_assert( 'address' === $address_result['search_type'] && true === $address_result['address_search_available'] && 55.012 === (float) $address_result['address']['lat'], 'Address search must return DaData coordinates.' );
pickup_rest_assert( 1 === count( $GLOBALS['wdc_pickup_rest_http_requests'] ?? array() ) && 1 === $token_pool->usage_today( 'pickup-address-token' ), 'Address search must use DaDataSuggestionClient and increment shared token usage.' );
$cached_address_result = $controller->address_search( array( 'carrier' => 'russian_post', 'query' => 'Ленина 15', 'country_code' => 'RU' ) );
pickup_rest_assert( 1 === count( $GLOBALS['wdc_pickup_rest_http_requests'] ?? array() ) && 1 === $token_pool->usage_today( 'pickup-address-token' ) && $cached_address_result['address'] === $address_result['address'], 'Address search cache must avoid repeated DaData usage.' );
$postcode_result = $controller->address_search( array( 'carrier' => 'russian_post', 'query' => '630002', 'country_code' => 'RU' ) );
pickup_rest_assert( 'postcode' === $postcode_result['search_type'] && count( $postcode_result['points'] ) > 1 && 2 === $postcode_result['points'][0]['id'] && 1 === count( $GLOBALS['wdc_pickup_rest_http_requests'] ?? array() ), 'Six-digit postcode search must return nearest points around the exact postcode anchor without calling DaData.' );
pickup_rest_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Search/PickupAddressSearchService.php' ), '$nearest = $this->points->find_nearest_rows( $anchor' ), 'Postcode exact matches must expand to nearest points around the anchor.' );
$GLOBALS['wdc_pickup_rest_http_queue'][] = array(
	'response' => array( 'code' => 200 ),
	'body' => wp_json_encode( array( 'suggestions' => array( array( 'value' => 'Маркса 7', 'data' => array( 'geo_lat' => '55.03', 'geo_lon' => '82.93' ) ) ) ), JSON_UNESCAPED_UNICODE ),
);
$controller->address_search( array( 'carrier' => 'russian_post', 'query' => 'Маркса 7', 'country_code' => 'RU' ) );
$exhausted = $controller->address_search( array( 'carrier' => 'russian_post', 'query' => 'Красный проспект', 'country_code' => 'RU' ) );
pickup_rest_assert( false === $exhausted['address_search_available'], 'Exhausted DaData token pool must disable address search.' );
pickup_rest_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Search/PickupAddressSearchService.php' ), 'AddressSuggestionClientInterface' ), 'Address search service must depend on the existing DaData client interface.' );

$detail = $controller->detail( array( 'id' => 1 ) );
pickup_rest_assert( 1 === $detail['id'] && '630001-a' === $detail['point_code'] && '09-18' === $detail['work_time'], 'detail must return minimal point card fields with compact work_time.' );
foreach ( $removed_fields as $removed_field ) {
	pickup_rest_assert( ! array_key_exists( $removed_field, $detail ), 'detail must not expose removed field: ' . $removed_field );
}

$invalid = $controller->points( array( 'carrier' => 'russian_post', 'bbox' => 'bad' ) );
pickup_rest_assert( $invalid instanceof WP_Error && 'invalid_bbox' === $invalid->get_error_code(), 'invalid bbox must return error.' );
$unsupported = $controller->points( array( 'carrier' => 'demo', 'bbox' => '0,0,180,90' ) );
pickup_rest_assert( array() === $unsupported, 'Unsupported carrier must not read legacy pickup table.' );

echo "Pickup REST smoke test passed.\n";
