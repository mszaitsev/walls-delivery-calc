<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'pickup-address-search-test-key' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}
if ( ! class_exists( 'WC_Session_Handler' ) ) {
	class WC_Session_Handler {
		/** @var array<string,mixed> */
		public static array $persisted_data = array();
		public static bool $throw_on_init = false;
		/** @var array<string,mixed> */
		public array $data = array();
		public bool $initialized = false;
		public bool $cookie_set = false;
		public bool $saved = false;
		public function init(): void {
			if ( self::$throw_on_init ) {
				throw new RuntimeException( 'session init failed' );
			}
			$this->initialized = true;
			$this->data = self::$persisted_data;
		}
		public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
		public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; self::$persisted_data[ $key ] = $value; }
		public function set_customer_session_cookie( bool $set ): void { $this->cookie_set = $set; }
		public function save_data(): void { $this->saved = true; self::$persisted_data = $this->data; }
	}
}

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
function WC(): object {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new class {
			public mixed $session;
			public mixed $customer = null;
			public function __construct() {
				$this->session = new class {
					/** @var array<string,mixed> */
					public array $data = array();
					public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
					public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
					public function set_customer_session_cookie( bool $set ): void {}
					public function save_data(): void {}
				};
			}
		};
	}

	return $wc;
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
use WallsShop\WDC\Pickup\Rest\CheckoutPickupPointRestController;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Pickup\Search\PickupAddressSearchService;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceSessionBootstrapper;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataSuggestionClient;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Pickup\PekCheckoutPickupPointFormatter;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderInterface;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;
use WallsShop\WDC\Pickup\Providers\CheckoutPickupPointProviderQueryResolver;

final class WdcPickupRestPekProvider implements CarrierPickupPointProviderInterface {
	public array $queries = array();
	public array $selection_queries = array();
	/** @param array<int,PickupPoint> $points */
	public function __construct( private array $points ) {}
	public function carrier_key(): string { return PekSettings::CARRIER_KEY; }
	public function search( CarrierPickupPointQuery $query ): array {
		$this->queries[] = $query;
		return $this->points;
	}
	public function resolve_selection( CarrierPickupPointSelectionQuery $query ): ?PickupPoint {
		$this->selection_queries[] = $query;
		foreach ( $this->points as $point ) {
			if ( $point->code === $query->point_code ) {
				return $point;
			}
		}

		return null;
	}
}

function wdc_pickup_rest_pek_snapshot( string $fingerprint = 'pek-destination-fp', mixed $latitude = 55.755864, mixed $longitude = 37.617698 ): array {
	return array(
		'carrier_key' => PekSettings::CARRIER_KEY,
		'purpose' => CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP,
		'location_id' => 153912,
		'country_code' => 'RU',
		'fallback_address_fingerprint' => hash( 'sha256', 'Россия, Москва' ),
		'latitude' => $latitude,
		'longitude' => $longitude,
		'cargo' => array(
			'weight_g' => 1000,
			'volume_cm3' => 1000,
			'max_dimension_cm' => 10,
			'max_place_weight_g' => 1000,
			'places_count' => 1,
		),
		'radius_km' => 50,
		'limit' => 50,
		'destination_fingerprint' => $fingerprint,
		'provider_destination_fingerprint' => $fingerprint,
	);
}

function wdc_pickup_rest_store_pek_rate( CheckoutSessionManager $session, array $snapshot ): array {
	$rate = new DeliveryRate(
		PekSettings::PICKUP_RATE_ID,
		PekSettings::CARRIER_KEY,
		'ПЭК',
		PekSettings::SERVICE_KEY,
		'ПЭК',
		PekSettings::PICKUP_TARIFF_KEY,
		PekSettings::PICKUP_TARIFF_NAME,
		DeliveryType::PICKUP,
		'ПЭК до терминала',
		Money::from_kopecks( 109000 ),
		null,
		null,
		DateRange::single( 4, DateRange::UNIT_CALENDAR_DAYS ),
		'',
		'',
		array(),
		false,
		'',
		true,
		false,
		array(
			'pickup_family' => PekSettings::PICKUP_FAMILY,
			'pickup_provider_query' => $snapshot,
			'requires_rate_refresh_on_pickup_selection' => true,
		),
		Money::from_kopecks( 109000 )
	);
	$mapped = ( new WooCommerceRateMapper() )->map( $rate );
	$stored_rate = array_merge(
		$mapped['meta_data'],
		array(
			'rate_id' => $rate->rate_id,
			'label' => $mapped['label'],
			'cost' => $mapped['cost'],
			'planned_delivery_comment' => $rate->planned_delivery_comment,
			'delivery_days' => $rate->delivery_days->to_array(),
			'fallback_used' => false,
			'service_title' => $rate->service_name,
		)
	);
	$session->save_rates( array( PekSettings::PICKUP_RATE_ID => $stored_rate ) );

	return $stored_rate;
}

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wdc_pickup_rest_http_requests'] = array();
$GLOBALS['wdc_pickup_rest_http_queue'] = array();
$GLOBALS['wpdb']->prefix = 'wp_';
$GLOBALS['wpdb']->pickup_rows = array(
	array( 'id' => 1, 'carrier_key' => 'russian_post', 'point_code' => '630001-a', 'point_type' => 'OPS', 'address' => 'Ленина, 1', 'city_name' => 'Новосибирск', 'region_name' => 'НСО', 'postcode' => '630001', 'latitude' => 55.01, 'longitude' => 82.91, 'work_time' => '09-18', 'active' => 1 ),
	array( 'id' => 2, 'carrier_key' => 'russian_post', 'point_code' => '630002-b', 'point_type' => 'PVZ', 'address' => 'Советская, 2', 'city_name' => 'Новосибирск', 'region_name' => 'НСО', 'postcode' => '630002', 'latitude' => 55.02, 'longitude' => 82.92, 'work_time' => '10-19', 'active' => 1 ),
	array( 'id' => 3, 'carrier_key' => 'russian_post', 'point_code' => '101000-c', 'point_type' => 'APS', 'address' => 'Тверская, 3', 'city_name' => 'Москва', 'region_name' => 'Москва', 'postcode' => '101000', 'latitude' => 55.76, 'longitude' => 37.61, 'work_time' => '24/7', 'active' => 1 ),
);
$GLOBALS['wpdb']->pickup_rows[0]['fias_location_guid'] = 'nsk-fias';

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
				'daily_limit' => 5,
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
pickup_rest_assert( 'russian_post_domestic' === (string) ( $bbox[0]['carrier_key'] ?? '' ) && 'russian_post_domestic:pickup' === (string) ( $bbox[0]['pickup_family'] ?? '' ), 'Russian Post REST summary must expose normalized carrier_key and pickup_family.' );
pickup_rest_assert( 'Отделение Почты России' === (string) ( $bbox[0]['point_title'] ?? '' ) && 'Пункт выдачи' === (string) ( $bbox[0]['point_type_label'] ?? '' ) && 'pickup' === (string) ( $bbox[0]['marker_type'] ?? '' ), 'Russian Post REST summary must expose normalized pickup presentation fields.' );
pickup_rest_assert( '630001' === (string) ( $bbox[0]['display_code'] ?? '' ) && 'Отделение Почты России 630001' === (string) ( $bbox[0]['display_title'] ?? '' ), 'Russian Post REST summary must expose postcode-based display title for map side list.' );
pickup_rest_assert( is_array( $bbox[0]['snapshot'] ?? null ) && 'russian_post_domestic:pickup' === (string) ( $bbox[0]['snapshot']['pickup_family'] ?? '' ), 'Russian Post REST summary must include normalized snapshot.' );
pickup_rest_assert( 'nsk-fias' === (string) ( $bbox[0]['fias_location_guid'] ?? '' ), 'REST summaries must expose pickup FIAS location GUID for cross-location checkout checks.' );
$removed_fields = array( 'brand_name', 'ecom_options', 'payment', 'accepts_cash', 'accepts_card', 'partial_redemption', 'return_available', 'fitting_available', 'contents_checking', 'functionality_checking', 'weight_limit_grams', 'raw_reference', 'work_time_json', 'source_hash' );
foreach ( $removed_fields as $removed_field ) {
	pickup_rest_assert( ! array_key_exists( $removed_field, $bbox[0] ), 'summary must not expose removed field: ' . $removed_field );
}

$type_filtered = $controller->points( array( 'carrier' => 'russian_post', 'bbox' => '0,0,180,90', 'type' => array( 'APS' ) ) );
pickup_rest_assert( 1 === count( $type_filtered ) && 3 === $type_filtered[0]['id'], 'type filter must work.' );

$disabled_pvz = $settings->all();
$disabled_pvz['russian_post_domestic_point_type_pvz_enabled'] = false;
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
$all_disabled['russian_post_domestic_point_type_ops_enabled'] = false;
$all_disabled['russian_post_domestic_point_type_pvz_enabled'] = false;
$all_disabled['russian_post_domestic_point_type_aps_enabled'] = false;
update_option( 'wdc_core_settings', $all_disabled, false );
pickup_rest_assert( array( 'OPS' ) === $type_settings->enabled_types(), 'All pickup types disabled must automatically re-enable OPS.' );
update_option( 'wdc_core_settings', array(), false );
$detail_pvz_enabled = $controller->detail( array( 'id' => 2 ) );
pickup_rest_assert( is_array( $detail_pvz_enabled ) && 2 === $detail_pvz_enabled['id'], 'detail PVZ must be available when PVZ is enabled.' );
$detail_pvz_requested_ops = $controller->detail( array( 'id' => 2, 'type' => array( 'OPS' ) ) );
pickup_rest_assert( $detail_pvz_requested_ops instanceof WP_Error && 'not_found' === $detail_pvz_requested_ops->get_error_code(), 'detail PVZ with type[]=OPS must return 404.' );

$checkout_pickup_controller_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Rest/CheckoutPickupPointRestController.php' ) ?: '';
pickup_rest_assert( str_contains( $checkout_pickup_controller_source, 'RussianPostDomesticSettings::is_pickup_rate_id' ), 'Checkout pickup point REST save must accept Russian Post pickup group ids without clearing the selected point.' );
$frontend_map_source = file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/pickup-map/wdc-pickup-map.js' ) ?: '';
pickup_rest_assert( ! str_contains( $frontend_map_source, 'LIST_LIMIT' ) && ! str_contains( $frontend_map_source, 'points.slice(0' ) && str_contains( $frontend_map_source, 'points.map(renderListItem).join' ), 'Frontend pickup map must not re-truncate full REST point lists to the first 100 rows.' );
$rp_repository_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/RussianPost/RussianPostPickupPointRepository.php' ) ?: '';
pickup_rest_assert( str_contains( $rp_repository_source, 'limit_from_filters( $filters, 1000, 2000 )' ) && ! str_contains( $rp_repository_source, 'limit_from_filters( $filters, 300, 500 )' ), 'Russian Post location-context pickup lookup must not keep the old 300-row admin cap.' );

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
				'daily_limit' => 4,
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
$cdek_same_address_result = $controller->address_search( array( 'carrier' => 'cdek', 'query' => 'Ленина 15', 'country_code' => 'RU', 'location_id' => '100' ) );
pickup_rest_assert( 'address' === $cdek_same_address_result['search_type'] && array() === $cdek_same_address_result['points'] && 2 === count( $GLOBALS['wdc_pickup_rest_http_requests'] ?? array() ), 'CDEK address-only search must not reuse the Russian Post address-search cache entry with pickup points.' );
$GLOBALS['wdc_pickup_rest_http_queue'][] = array(
	'response' => array( 'code' => 200 ),
	'body' => wp_json_encode(
		array(
			'suggestions' => array(
				array(
					'value' => 'г Москва, ул Тверская, д 1',
					'unrestricted_value' => '125009, г Москва, ул Тверская, д 1',
					'data' => array( 'geo_lat' => '55.757', 'geo_lon' => '37.615' ),
				),
			),
		),
		JSON_UNESCAPED_UNICODE
	),
);
$cdek_address_result = $controller->address_search( array( 'carrier' => 'cdek', 'query' => 'Тверская 1', 'country_code' => 'RU', 'location_id' => '100' ) );
pickup_rest_assert( 'address' === $cdek_address_result['search_type'] && true === $cdek_address_result['address_search_available'] && 55.757 === (float) $cdek_address_result['address']['lat'] && array() === $cdek_address_result['points'], 'CDEK address search must use the shared DaData path and return marker coordinates without replacing carrier pickup points.' );
pickup_rest_assert( 3 === count( $GLOBALS['wdc_pickup_rest_http_requests'] ?? array() ) && 3 === $token_pool->usage_today( 'pickup-address-token' ), 'CDEK address search must call DaData without changing carrier context.' );
$postcode_result = $controller->address_search( array( 'carrier' => 'russian_post', 'query' => '630002', 'country_code' => 'RU' ) );
pickup_rest_assert( 'postcode' === $postcode_result['search_type'] && count( $postcode_result['points'] ) > 1 && 2 === $postcode_result['points'][0]['id'] && 3 === count( $GLOBALS['wdc_pickup_rest_http_requests'] ?? array() ), 'Six-digit postcode search must return nearest points around the exact postcode anchor without calling DaData.' );
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
$resolver_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Services/PickupPointLocationResolver.php' );
pickup_rest_assert( str_contains( $resolver_source, "'city_value' => \$formatter->format_checkout_city_value( \$location )" ) && str_contains( $resolver_source, "'state_value' => \$formatter->format_checkout_state_value( \$location )" ), 'Resolve-location payload must expose city selector compatible city/state values.' );
pickup_rest_assert( str_contains( $resolver_source, "'region_type' => \$location->region_type" ) && str_contains( $resolver_source, "'city_type' => \$location->city_type" ) && str_contains( $resolver_source, "'place_type' => \$location->resolved_place_type()" ), 'Resolve-location payload must expose full location type fields.' );

$invalid = $controller->points( array( 'carrier' => 'russian_post', 'bbox' => 'bad' ) );
pickup_rest_assert( $invalid instanceof WP_Error && 'invalid_bbox' === $invalid->get_error_code(), 'invalid bbox must return error.' );
$unsupported = $controller->points( array( 'carrier' => 'demo', 'bbox' => '0,0,180,90' ) );
pickup_rest_assert( array() === $unsupported, 'Unsupported carrier must not read legacy pickup table.' );

$pickup_rest_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Rest/PickupPointsRestController.php' );
$cdek_service_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Cdek/CdekDeliveryPointService.php' ) ?: '';
$checkout_rest_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Rest/CheckoutPickupPointRestController.php' ) ?: '';
pickup_rest_assert( str_contains( $pickup_rest_source . $cdek_service_source . $checkout_rest_source, "'description'" ) && str_contains( $pickup_rest_source . $cdek_service_source . $checkout_rest_source, "'storage_notice'" ) && str_contains( $pickup_rest_source . $cdek_service_source . $checkout_rest_source, "'cdek_code'" ) && str_contains( $cdek_service_source . $checkout_rest_source, "'pickup_family'" ) && str_contains( $cdek_service_source . $checkout_rest_source, "'point_title'" ), 'CDEK pickup REST summary must expose description, storage_notice, cdek_code and normalized presentation fields.' );
$session_bootstrapper = new WooCommerceSessionBootstrapper();
$checkout_controller = new CheckoutPickupPointRestController( $repo, new CheckoutSessionManager(), null, null, null, null, null, null, null, $session_bootstrapper );
WC()->session = null;
$rp_save = $checkout_controller->save(
	new WdcPickupRestRequest(
		array(
			'carrier' => 'russian_post_domestic',
			'shipping_method_id' => 'russian_post_domestic:pickup',
			'point_id' => '1',
		),
		array( 'X-WP-Nonce' => 'nonce' )
	)
);
pickup_rest_assert( WC()->session instanceof WC_Session_Handler && WC()->session->initialized && WC()->session->cookie_set && WC()->session->saved, 'REST save must initialize a missing WooCommerce session before saving Russian Post pickup.' );
pickup_rest_assert( '630001-a' === (string) ( $rp_save['pickup_selections']['russian_post_domestic:pickup']['point_code'] ?? '' ) && '630001-a' === (string) ( WC()->session->data['wdc_platform_pickup_selections']['russian_post_domestic:pickup']['point_code'] ?? '' ), 'REST save without pre-existing WC session must write Russian Post canonical bucket.' );
WC()->session = null;
$cdek_save = $checkout_controller->save(
	new WdcPickupRestRequest(
		array(
			'carrier' => 'cdek',
			'shipping_method_id' => 'cdek:pickup',
			'point' => array(
				'id' => 'cdek:KEM7',
				'carrier_key' => 'cdek',
				'service_key' => 'cdek',
				'pickup_family' => 'cdek:pickup',
				'point_code' => 'KEM7',
				'point_type' => 'PVZ',
				'point_address' => 'CDEK address',
				'point_postcode' => '650004',
				'city_name' => 'Kemerovo',
				'region_name' => 'Kemerovo oblast',
			),
		),
		array( 'X-WP-Nonce' => 'nonce' )
	)
);
pickup_rest_assert( WC()->session instanceof WC_Session_Handler && 'KEM7' === (string) ( $cdek_save['pickup_selections']['cdek:pickup']['point_code'] ?? '' ) && 'KEM7' === (string) ( WC()->session->data['wdc_platform_pickup_selections']['cdek:pickup']['point_code'] ?? '' ), 'REST save without pre-existing WC session must write CDEK canonical bucket.' );
$cdek_state = $checkout_controller->state( new WdcPickupRestRequest( array( 'pickup_family' => 'cdek:pickup' ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_rest_assert( 'KEM7' === (string) ( $cdek_state['pickup_selections']['cdek:pickup']['point_code'] ?? '' ) && 'KEM7' === (string) ( $cdek_state['pickup_point']['point_code'] ?? '' ), 'REST state after save must read the initialized CDEK canonical bucket.' );
pickup_rest_assert( isset( $cdek_state['pickupSelections']['cdek:pickup'], $cdek_state['pickup_selections']['cdek:pickup'] ) && 'KEM7' === (string) ( $cdek_state['pickupSelections']['cdek:pickup']['point_code'] ?? '' ), 'REST state must expose pickup selections as camelCase and snake_case dictionaries.' );
$cdek_reset = $checkout_controller->delete( new WdcPickupRestRequest( array( 'pickup_family' => 'cdek:pickup' ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_rest_assert( ! isset( $cdek_reset['pickup_selections']['cdek:pickup'] ) && WC()->session instanceof WC_Session_Handler, 'REST family reset must keep the initialized session and remove only the requested bucket.' );
$session = new CheckoutSessionManager();
$session->save_pickup_selection_for_family( 'cdek:pickup', array( 'carrier_key' => 'cdek', 'service_key' => 'cdek', 'point_code' => 'KEM7', 'point_address' => 'CDEK address' ) );
$session->save_pickup_selection_for_family( 'russian_post_domestic:pickup', array( 'carrier_key' => 'russian_post_domestic', 'service_key' => 'russian_post_domestic', 'point_code' => '630001-a', 'point_address' => 'Ленина, 1' ) );
pickup_rest_assert( 'KEM7' === (string) ( WC()->session->data['wdc_platform_pickup_selections']['cdek:pickup']['point_code'] ?? '' ) && '630001-a' === (string) ( WC()->session->data['wdc_platform_pickup_selections']['russian_post_domestic:pickup']['point_code'] ?? '' ), 'Raw WC session key must keep CDEK and Russian Post canonical pickup buckets.' );
pickup_rest_assert( str_contains( $session_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/CheckoutSessionManager.php' ), 'function set_raw_session_array' ) && str_contains( $session_source, 'save_data' ) && str_contains( $session_source, 'wdc_platform_pickup_selections' ), 'Checkout session manager must use a raw array writer for canonical pickup selections.' );

WC()->session = new WC_Session_Handler();
$pek_session = new CheckoutSessionManager();
$pek_session->save_city_context( array( 'country_code' => 'RU', 'location_id' => 153912 ) );
$pek_snapshot = wdc_pickup_rest_pek_snapshot();
$stored_pek_rate = wdc_pickup_rest_store_pek_rate( $pek_session, $pek_snapshot );
pickup_rest_assert( isset( $stored_pek_rate['rate_meta']['pickup_provider_query'] ) && ! isset( $stored_pek_rate['meta'] ), 'PEK REST fixture must use production WooCommerce rate_meta shape.' );
WC()->session = null;
$pek_provider = new WdcPickupRestPekProvider(
	array(
		new PickupPoint( PekSettings::CARRIER_KEY, 'main-wh', 'Россия, Москва, терминал main-wh', '', 'Москва', '', 55.75, 37.61, 'terminal', 'Пн-Пт 09:00-18:00', '', null, true, array( 'source' => 'free', 'division_name' => '8c4eeef5-1f90-11f1-b8ab-00155d24b451' ) ),
		new PickupPoint( PekSettings::CARRIER_KEY, 'paid-wh', 'Россия, Москва, партнерский пункт paid-wh', '', 'Москва', '', 55.76, 37.62, 'pvz', 'Пн-Пт 10:00-19:00', '', null, true, array( 'source' => 'paid', 'division_name' => '8c4eeef5-1f90-11f1-b8ab-00155d24b451' ) ),
	)
);
$pek_registry = new CarrierPickupPointProviderRegistry( array( $pek_provider ) );
$pek_query_resolver = new CheckoutPickupPointProviderQueryResolver( $pek_session );
$pek_points_controller = new PickupPointsRestController( $repo, $type_settings, $address_search, null, null, null, null, null, $pek_registry, $pek_query_resolver, new PekCheckoutPickupPointFormatter(), $session_bootstrapper );
$pek_points = $pek_points_controller->points(
	new WdcPickupRestRequest(
		array(
			'carrier' => PekSettings::CARRIER_KEY,
			'shipping_method_id' => PekSettings::PICKUP_RATE_ID,
			'pickup_family' => PekSettings::PICKUP_FAMILY,
			'location_id' => '999999',
			'weight_g' => '999999',
		),
		array( 'X-WP-Nonce' => 'nonce' )
	)
);
pickup_rest_assert( is_array( $pek_points ) && 2 === count( $pek_points ) && 'main-wh' === (string) ( $pek_points[0]['point_code'] ?? '' ) && 'pek-destination-fp' === (string) ( $pek_points[0]['provider_destination_fingerprint'] ?? '' ), 'PEK /points must use trusted production rate_meta context and return provider points.' );
pickup_rest_assert( 'Собственный пункт выдачи ПЭК' === (string) ( $pek_points[0]['point_title'] ?? '' ) && 'Собственный пункт выдачи ПЭК' === (string) ( $pek_points[0]['point_type_label'] ?? '' ) && '' === (string) ( $pek_points[0]['presentation_comment'] ?? '' ), 'Free PEK point must use public own-terminal presentation without a surcharge warning.' );
pickup_rest_assert( 'Партнерский пункт выдачи ПЭК' === (string) ( $pek_points[1]['point_title'] ?? '' ) && 'Партнерский пункт выдачи ПЭК' === (string) ( $pek_points[1]['point_type_label'] ?? '' ) && 'Возможна небольшая доплата за доставку в этот пункт' === (string) ( $pek_points[1]['presentation_comment'] ?? '' ), 'Paid PEK point must use partner presentation with a warning.' );
pickup_rest_assert( ! str_contains( wp_json_encode( $pek_points, JSON_UNESCAPED_UNICODE ) ?: '', '8c4eeef5-1f90-11f1-b8ab-00155d24b451' ) && '' === (string) ( $pek_points[0]['point_name'] ?? '' ) && '' === (string) ( $pek_points[1]['point_name'] ?? '' ), 'PEK formatter must not expose internal UUID as public point name.' );
pickup_rest_assert( WC()->session instanceof WC_Session_Handler && WC()->session->initialized && WC()->session->cookie_set && isset( WC()->session->data['wdc_platform_rates'][ PekSettings::PICKUP_RATE_ID ] ), 'PEK /points must bootstrap the existing WooCommerce customer session and restore stored rates.' );
pickup_rest_assert( 153912 === $pek_provider->queries[0]->location_id && 1000 === $pek_provider->queries[0]->cargo->weight_g, 'PEK /points must ignore browser location/cargo authority and use stored rate snapshot.' );
$pek_search = $pek_points_controller->search( new WdcPickupRestRequest( array( 'carrier' => PekSettings::CARRIER_KEY, 'shipping_method_id' => PekSettings::PICKUP_RATE_ID, 'pickup_family' => PekSettings::PICKUP_FAMILY, 'q' => 'main-wh' ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_rest_assert( is_array( $pek_search ) && 1 === count( $pek_search ), 'PEK /points/search must resolve provider context from production rate_meta.' );
$pek_wrong_family = $pek_points_controller->points( new WdcPickupRestRequest( array( 'carrier' => PekSettings::CARRIER_KEY, 'shipping_method_id' => PekSettings::PICKUP_RATE_ID, 'pickup_family' => 'forged:pickup' ), array( 'X-WP-Nonce' => 'nonce' ) ) );
pickup_rest_assert( $pek_wrong_family instanceof WP_Error && 'provider_rate_context_mismatch' === $pek_wrong_family->get_error_code(), 'PEK /points must reject browser-forged pickup family.' );

$pek_checkout_controller = new CheckoutPickupPointRestController( $repo, $pek_session, null, null, null, null, null, $pek_registry, $pek_query_resolver, $session_bootstrapper );
$pek_save = $pek_checkout_controller->save(
	new WdcPickupRestRequest(
		array(
			'carrier' => PekSettings::CARRIER_KEY,
			'shipping_method_id' => PekSettings::PICKUP_RATE_ID,
			'point_code' => 'main-wh',
			'provider_destination_fingerprint' => 'forged-browser-fingerprint',
			'point' => array(
				'point_address' => 'forged browser address',
				'lat' => 1,
				'lng' => 2,
			),
		),
		array( 'X-WP-Nonce' => 'nonce' )
	)
);
pickup_rest_assert( 1 === count( $pek_provider->selection_queries ) && 'main-wh' === (string) ( $pek_save['pickup_selections'][ PekSettings::PICKUP_FAMILY ]['point_code'] ?? '' ), 'PEK save must reach fresh resolve_selection and store the selected warehouse in pek:pickup.' );
pickup_rest_assert( 'pek-destination-fp' === (string) ( $pek_save['pickup_selections'][ PekSettings::PICKUP_FAMILY ]['provider_destination_fingerprint'] ?? '' ) && 'country=RU|location_id=153912' === (string) ( $pek_save['pickup_selections'][ PekSettings::PICKUP_FAMILY ]['destination_fingerprint'] ?? '' ), 'PEK save must preserve trusted provider fingerprint separately from generic checkout location fingerprint.' );
pickup_rest_assert( 'Россия, Москва, терминал main-wh' === (string) ( $pek_save['pickup_selections'][ PekSettings::PICKUP_FAMILY ]['point_address'] ?? '' ), 'PEK save must ignore forged browser point presentation and use provider projection.' );

WC()->session = new WC_Session_Handler();
$pek_address_only_session = new CheckoutSessionManager();
$pek_address_only_session->save_city_context( array( 'country_code' => 'RU', 'location_id' => 153912 ) );
wdc_pickup_rest_store_pek_rate( $pek_address_only_session, wdc_pickup_rest_pek_snapshot( 'pek-address-only-fp', null, null ) );
WC()->session = null;
$pek_address_only_provider = new WdcPickupRestPekProvider(
	array(
		new PickupPoint( PekSettings::CARRIER_KEY, 'address-only-wh', 'Россия, Москва, address-only terminal', '', 'Москва', '', null, null, 'terminal', 'Пн-Пт 09:00-18:00', '', null, true, array( 'source' => 'free' ) ),
	)
);
$pek_address_only_registry = new CarrierPickupPointProviderRegistry( array( $pek_address_only_provider ) );
$pek_address_only_resolver = new CheckoutPickupPointProviderQueryResolver( $pek_address_only_session );
$pek_address_only_points = ( new PickupPointsRestController( $repo, $type_settings, $address_search, null, null, null, null, null, $pek_address_only_registry, $pek_address_only_resolver, new PekCheckoutPickupPointFormatter(), $session_bootstrapper ) )->points(
	new WdcPickupRestRequest(
		array(
			'carrier' => PekSettings::CARRIER_KEY,
			'shipping_method_id' => PekSettings::PICKUP_RATE_ID,
			'pickup_family' => PekSettings::PICKUP_FAMILY,
			'latitude' => '1',
			'longitude' => '2',
			'address' => 'forged browser address',
		),
		array( 'X-WP-Nonce' => 'nonce' )
	)
);
pickup_rest_assert( is_array( $pek_address_only_points ) && 1 === count( $pek_address_only_points ) && 'address-only-wh' === (string) ( $pek_address_only_points[0]['point_code'] ?? '' ), 'PEK /points must load terminals for address-only trusted snapshots.' );
pickup_rest_assert( 1 === count( $pek_address_only_provider->queries ) && 153912 === $pek_address_only_provider->queries[0]->location_id && null === $pek_address_only_provider->queries[0]->latitude && null === $pek_address_only_provider->queries[0]->longitude, 'PEK /points must ignore browser coordinates/address and pass null coordinates from trusted address-only snapshot.' );
$pek_address_only_save = ( new CheckoutPickupPointRestController( $repo, $pek_address_only_session, null, null, null, null, null, $pek_address_only_registry, $pek_address_only_resolver, $session_bootstrapper ) )->save(
	new WdcPickupRestRequest(
		array(
			'carrier' => PekSettings::CARRIER_KEY,
			'shipping_method_id' => PekSettings::PICKUP_RATE_ID,
			'point_code' => 'address-only-wh',
			'provider_destination_fingerprint' => 'forged-browser-fingerprint',
			'point' => array(
				'point_address' => 'forged browser address',
				'lat' => 1,
				'lng' => 2,
			),
		),
		array( 'X-WP-Nonce' => 'nonce' )
	)
);
pickup_rest_assert( 1 === count( $pek_address_only_provider->selection_queries ) && 'address-only-wh' === (string) ( $pek_address_only_save['pickup_selections'][ PekSettings::PICKUP_FAMILY ]['point_code'] ?? '' ), 'PEK address-only save must fresh-validate selected warehouse.' );
pickup_rest_assert( 'pek-address-only-fp' === (string) ( $pek_address_only_save['pickup_selections'][ PekSettings::PICKUP_FAMILY ]['provider_destination_fingerprint'] ?? '' ) && 'country=RU|location_id=153912' === (string) ( $pek_address_only_save['pickup_selections'][ PekSettings::PICKUP_FAMILY ]['destination_fingerprint'] ?? '' ), 'PEK address-only save must preserve provider fingerprint separately from generic location fingerprint.' );
pickup_rest_assert( 'Россия, Москва, address-only terminal' === (string) ( $pek_address_only_save['pickup_selections'][ PekSettings::PICKUP_FAMILY ]['point_address'] ?? '' ), 'PEK address-only save must ignore forged browser presentation.' );

WC()->session = new WC_Session_Handler();
$empty_fp_session = new CheckoutSessionManager();
wdc_pickup_rest_store_pek_rate( $empty_fp_session, wdc_pickup_rest_pek_snapshot( '' ) );
$empty_fp_save = ( new CheckoutPickupPointRestController( $repo, $empty_fp_session, null, null, null, null, null, $pek_registry, new CheckoutPickupPointProviderQueryResolver( $empty_fp_session ), $session_bootstrapper ) )->save(
	new WdcPickupRestRequest( array( 'carrier' => PekSettings::CARRIER_KEY, 'shipping_method_id' => PekSettings::PICKUP_RATE_ID, 'point_code' => 'main-wh' ), array( 'X-WP-Nonce' => 'nonce' ) )
);
pickup_rest_assert( $empty_fp_save instanceof WP_Error && 'provider_rate_context_missing' === $empty_fp_save->get_error_code(), 'PEK save must reject empty destination fingerprint in trusted rate context.' );

WC_Session_Handler::$throw_on_init = true;
WC()->session = null;
$bootstrap_failure = $pek_points_controller->points(
	new WdcPickupRestRequest(
		array(
			'carrier' => PekSettings::CARRIER_KEY,
			'shipping_method_id' => PekSettings::PICKUP_RATE_ID,
			'pickup_family' => PekSettings::PICKUP_FAMILY,
		),
		array( 'X-WP-Nonce' => 'nonce' )
	)
);
WC_Session_Handler::$throw_on_init = false;
pickup_rest_assert( $bootstrap_failure instanceof WP_Error && 'provider_session_unavailable' === $bootstrap_failure->get_error_code() && 503 === (int) ( $bootstrap_failure->get_error_data()['status'] ?? 0 ), 'PEK /points must return provider_session_unavailable 503 when WooCommerce session bootstrap fails.' );

echo "Pickup REST smoke test passed.\n";
