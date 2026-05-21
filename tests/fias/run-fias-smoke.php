<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\Address\CheckoutAddressNormalizer;
use WallsShop\WDC\Checkout\Address\CheckoutAddressRuntime;
use WallsShop\WDC\Checkout\Address\DaDataAddressNormalizer;
use WallsShop\WDC\Checkout\Address\FiasAddressNormalizer;
use WallsShop\WDC\Checkout\Locations\CheckoutCityResolver;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Fias\FiasEndpoints;
use WallsShop\WDC\Locations\Fias\FiasHttpClient;
use WallsShop\WDC\Locations\Fias\FiasLogger;
use WallsShop\WDC\Locations\Fias\FiasRateLimiter;
use WallsShop\WDC\Locations\Gar\GarChangesClient;
use WallsShop\WDC\Locations\Gar\GarSyncManager;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Normalization\FallbackAddressNormalizer;
use WallsShop\WDC\Locations\Services\LocationAliasGenerator;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['wdc_fias_options'] = array();
$GLOBALS['wdc_fias_transients'] = array();
$GLOBALS['wdc_fias_http_mode'] = 'success';

function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_fias_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_fias_options'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_fias_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $ttl ): bool { $GLOBALS['wdc_fias_transients'][ $key ] = $value; return true; }
function current_time( string $type ): string { return '2026-05-21 12:00:00'; }
function __( string $text, string $domain = '' ): string { return $text; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags | JSON_UNESCAPED_UNICODE ); }
function is_wp_error( mixed $value ): bool { return is_object( $value ) && method_exists( $value, 'get_error_message' ); }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }

function wp_remote_post( string $url, array $args = array() ): array|object {
	if ( 'timeout' === $GLOBALS['wdc_fias_http_mode'] ) {
		return new class { public function get_error_message(): string { return 'cURL error 28: Operation timed out'; } };
	}

	return array(
		'response' => array( 'code' => 200 ),
		'body'     => wp_json_encode( array( 'suggestions' => array( array( 'data' => array( 'city' => 'Новосибирск', 'region' => 'Новосибирская область', 'postal_code' => '630099', 'fias_id' => 'api-fias-nsk', 'gar_id' => 'api-gar-nsk' ) ) ) ) ),
	);
}

function wp_remote_get( string $url, array $args = array() ): array|object {
	return new class { public function get_error_message(): string { return 'cURL error 28: Operation timed out'; } };
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		public int $insert_id = 0;
		/** @var array<string,array<int,array<string,mixed>>> */
		public array $tables = array();
		public function prepare( string $query, mixed ...$args ): array { return array( 'query' => $query, 'args' => $args ); }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
		public function insert( string $table, array $data, array $format ): int { ++$this->insert_id; $data['id'] = $this->insert_id; $this->tables[ $table ][ $this->insert_id ] = $data; return 1; }
		public function update( string $table, array $data, array $where, array $format, array $where_format ): int { return 1; }
		public function get_row( array $prepared, string $output ): ?array {
			$query = $prepared['query']; $value = (string) ( $prepared['args'][0] ?? '' );
			foreach ( $this->tables['wdc_locations'] ?? array() as $row ) {
				if ( str_contains( $query, 'WHERE id =' ) && (int) $row['id'] === (int) $value ) { return $row; }
				if ( str_contains( $query, 'WHERE fias_id =' ) && (string) $row['fias_id'] === $value ) { return $row; }
				if ( str_contains( $query, 'WHERE gar_id =' ) && (string) $row['gar_id'] === $value ) { return $row; }
			}
			return null;
		}
		public function get_results( array $prepared, string $output ): array {
			$query = trim( (string) ( $prepared['args'][0] ?? '' ), '%' ); $limit = (int) ( $prepared['args'][1] ?? 20 );
			$rows = array_filter( $this->tables['wdc_locations'] ?? array(), static fn ( array $row ): bool => 1 === (int) $row['active'] && str_contains( (string) $row['searchable_text'], $query ) );
			usort( $rows, static fn ( array $a, array $b ): int => strcmp( (string) $a['display_name'], (string) $b['display_name'] ) );
			return array_slice( array_values( $rows ), 0, $limit );
		}
		public function get_var( mixed $query ): int { return str_contains( (string) $query, 'wdc_location_aliases' ) ? count( $this->tables['wdc_location_aliases'] ?? array() ) : count( $this->tables['wdc_locations'] ?? array() ); }
		public function query( mixed $query ): int { return 1; }
	}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function fias_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$wpdb = new wpdb();
$repository = new LocationRepository( $wpdb );
( new LocationImportService( $repository ) )->import_from_array(
	array(
		array( 'country_code' => 'RU', 'region_name' => 'Новосибирская область', 'city_name' => 'Новосибирск', 'postcode' => '630000', 'fias_id' => 'local-fias-nsk', 'gar_id' => 'local-gar-nsk' ),
		array( 'country_code' => 'RU', 'region_name' => 'Новосибирская область', 'city_name' => 'Бердск', 'postcode' => '633010', 'fias_id' => 'local-fias-berdsk', 'gar_id' => 'local-gar-berdsk' ),
	)
);

$settings = new SettingsRepository();
$settings->replace( array_merge( $settings->defaults(), array( 'fias_api_enabled' => true, 'fias_api_minute_limit' => 1, 'fias_api_daily_limit' => 10 ) ) );
$logger = new FiasLogger( new Logger() );
$limiter = new FiasRateLimiter( $settings, $logger );
fias_smoke_assert( $limiter->can_request(), 'Limiter must allow first request.' );
$limiter->increment();
fias_smoke_assert( ! $limiter->can_request(), 'Limiter must block after minute limit.' );
$GLOBALS['wdc_fias_transients'] = array();

$search = new CheckoutLocationSearch( new LocationSearchService( $repository ) );
$resolver = new CheckoutCityResolver( $repository, $search );
$http = new FiasHttpClient( 1, $logger );
$fias = new FiasAddressNormalizer( $resolver, $settings, new FiasEndpoints(), $http, $limiter, $logger );
$normalizer = new CheckoutAddressNormalizer( $fias, new DaDataAddressNormalizer(), new FallbackAddressNormalizer() );
$runtime = new CheckoutAddressRuntime( $normalizer, $resolver, new CheckoutSessionManager() );

$known = $runtime->resolve_checkout_address( array( 'shipping_country' => 'RU', 'shipping_city' => 'Новосибирск' ) );
fias_smoke_assert( $known->success && '630000' === $known->address->postcode, 'Known exact city must normalize locally and fill postcode.' );

$settings->set( 'fias_api_enabled', false );
$disabled = $runtime->resolve_checkout_address( array( 'shipping_country' => 'RU', 'shipping_city' => 'Unknown City' ) );
fias_smoke_assert( ! $disabled->success && $disabled->address->fallback, 'API disabled unknown city must fall back.' );

$settings->set( 'fias_api_enabled', true );
$GLOBALS['wdc_fias_http_mode'] = 'timeout';
$timeout = $runtime->resolve_checkout_address( array( 'shipping_country' => 'RU', 'shipping_city' => 'Unknown Timeout City' ) );
fias_smoke_assert( ! $timeout->success && $timeout->address->fallback, 'API timeout must fall back and keep checkout alive.' );

$GLOBALS['wdc_fias_http_mode'] = 'success';
$GLOBALS['wdc_fias_transients'] = array();
$api = $runtime->resolve_checkout_address( array( 'shipping_country' => 'RU', 'shipping_city' => 'Новос', 'shipping_postcode' => '630000' ) );
fias_smoke_assert( $api->success, 'Uncertain local city must normalize through API.' );
fias_smoke_assert( '630099' === $api->address->postcode, 'API postcode must overwrite local/checkout postcode.' );
fias_smoke_assert( 'api-fias-nsk' === $api->address->fias_id, 'API FIAS id must be used.' );

$GLOBALS['wdc_fias_transients'] = array();
$unknown = $runtime->resolve_checkout_address( array( 'shipping_country' => 'RU', 'shipping_city' => 'Completely Unknown' ) );
fias_smoke_assert( $unknown->success, 'Successful fake API can normalize unknown city.' );

$aliases = ( new LocationAliasGenerator() )->generate( new Location( city_name: 'Новосибирск', display_name: 'Новосибирск' ) );
fias_smoke_assert( in_array( 'новосиб', $aliases, true ) && in_array( 'нск', $aliases, true ), 'Alias generator must create Novosibirsk aliases.' );

$gar = new GarSyncManager( new ActionScheduler( new Logger() ), new GarChangesClient( $http ), new Logger(), $wpdb );
$GLOBALS['wdc_fias_http_mode'] = 'timeout';
$gar_status = $gar->check_for_changes();
fias_smoke_assert( false === $gar_status['ok'], 'GAR sync safe failure must return non-ok status.' );

$survived = $runtime->resolve_checkout_address( array( 'shipping_country' => 'RU', 'shipping_city' => 'Checkout Survives Failure' ) );
fias_smoke_assert( ! $survived->success && $survived->address->fallback, 'Checkout must survive API failure with fallback address.' );

echo "FIAS smoke test passed.\n";
