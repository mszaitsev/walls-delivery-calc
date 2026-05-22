<?php
declare(strict_types=1);

use WallsShop\WDC\Admin\SettingsAdminPage;
use WallsShop\WDC\Checkout\Address\CheckoutAddressNormalizer;
use WallsShop\WDC\Checkout\Address\CheckoutAddressRuntime;
use WallsShop\WDC\Checkout\Address\FiasAddressNormalizer;
use WallsShop\WDC\Checkout\Locations\CheckoutCityResolver;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutAddressRenderer;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Fias\FiasCredentials;
use WallsShop\WDC\Locations\Fias\FiasEndpoints;
use WallsShop\WDC\Locations\Fias\FiasHttpClient;
use WallsShop\WDC\Locations\Fias\FiasLogger;
use WallsShop\WDC\Locations\Fias\FiasRateLimiter;
use WallsShop\WDC\Locations\Gar\GarChangesClient;
use WallsShop\WDC\Locations\Gar\GarSyncManager;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Normalization\FallbackAddressNormalizer;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'test-fias-encryption-key' );

$GLOBALS['wdc_fias_options'] = array();
$GLOBALS['wdc_fias_transients'] = array();
$GLOBALS['wdc_fias_http_requests'] = 0;

function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_fias_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_fias_options'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_fias_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $ttl ): bool { $GLOBALS['wdc_fias_transients'][ $key ] = $value; return true; }
function current_time( string $type ): string { return '2026-05-21 12:00:00'; }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function esc_attr__( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags | JSON_UNESCAPED_UNICODE ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function sanitize_key( string $value ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ) ?: ''; }
function checked( mixed $checked, mixed $current = true, bool $display = true ): string { $result = (string) $checked === (string) $current ? ' checked="checked"' : ''; if ( $display ) { echo $result; } return $result; }
function selected( mixed $selected, mixed $current = true, bool $display = true ): string { $result = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ( $display ) { echo $result; } return $result; }
function current_user_can( string $capability ): bool { return true; }
function wp_verify_nonce( string $nonce, string $action ): bool { return true; }
function wp_nonce_field( string $action, string $name ): void { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">'; }
function submit_button( string $text ): void { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }
function is_wp_error( mixed $value ): bool { return is_object( $value ) && method_exists( $value, 'get_error_message' ); }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
function wp_remote_post( string $url, array $args = array() ): array { ++$GLOBALS['wdc_fias_http_requests']; return array( 'response' => array( 'code' => 500 ), 'body' => '{}' ); }
function wp_remote_get( string $url, array $args = array() ): array { ++$GLOBALS['wdc_fias_http_requests']; return array( 'response' => array( 'code' => 500 ), 'body' => '{}' ); }

final class WdcFiasSmokeSession {
	private array $data = array();
	public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
	public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
	public function __unset( string $key ): void { unset( $this->data[ $key ] ); }
}

final class WdcFiasSmokeWooCommerce {
	public WdcFiasSmokeSession $session;
	public function __construct() { $this->session = new WdcFiasSmokeSession(); }
}

function WC(): WdcFiasSmokeWooCommerce {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new WdcFiasSmokeWooCommerce();
	}

	return $wc;
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
		public function get_var( mixed $query ): int { return count( $this->tables['wdc_locations'] ?? array() ); }
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

$settings = new SettingsRepository();
$encryption = new EncryptionService();
$credentials = new FiasCredentials( $settings, $encryption );
fias_smoke_assert( $credentials->save_token( 'raw-secret-token' ), 'Saving token must succeed with APP_ENCRYPTION_KEY.' );
$all_settings = $settings->all();
fias_smoke_assert( isset( $all_settings['fias_api_token_encrypted'], $all_settings['fias_api_token_masked'] ), 'Encrypted and masked token settings must be stored.' );
fias_smoke_assert( 'raw-secret-token' !== $all_settings['fias_api_token_encrypted'], 'Encrypted token must not equal raw token.' );
fias_smoke_assert( '********' === $credentials->masked_token(), 'Masked token must be stars only.' );
fias_smoke_assert( ! str_contains( $credentials->masked_token(), 'raw-secret-token' ), 'Raw token must not appear in masked output.' );

ob_start();
( new SettingsAdminPage( $settings, $credentials ) )->render_page();
$settings_html = (string) ob_get_clean();
fias_smoke_assert( ! str_contains( $settings_html, 'raw-secret-token' ), 'Raw token must never appear in settings UI.' );
fias_smoke_assert( str_contains( $settings_html, '********' ), 'Settings UI must show only token mask.' );

$credentials->save_token( '' );
fias_smoke_assert( ! $credentials->has_token(), 'Empty token save must clear token.' );

$wpdb = new wpdb();
$repository = new LocationRepository( $wpdb );
( new LocationImportService( $repository ) )->import_from_array(
	array(
		array( 'country_code' => 'RU', 'region_name' => 'Новосибирская область', 'city_name' => 'Новосибирск', 'postcode' => '630000', 'fias_id' => 'local-fias-nsk', 'gar_id' => 'local-gar-nsk' ),
	)
);

$search = new CheckoutLocationSearch( new LocationSearchService( $repository ) );
$resolver = new CheckoutCityResolver( $repository, $search );
$logger = new FiasLogger( new Logger() );
$limiter = new FiasRateLimiter( $settings, $logger );
$http = new FiasHttpClient( 1, $logger );
$fias = new FiasAddressNormalizer( $resolver, $settings, new FiasEndpoints(), $http, $limiter, $logger, $credentials );

$missing = $fias->normalize( 'Новосибирск, Ленина, 1', array( 'country_code' => 'RU', 'city' => 'Новосибирск' ) );
fias_smoke_assert( ! $missing->success && 'fias_token_missing' === $missing->error_code, 'No token must return fias_token_missing.' );

$credentials->save_token( 'raw-secret-token' );
$disabled = $fias->normalize( 'Новосибирск, Ленина, 1', array( 'country_code' => 'RU', 'city' => 'Новосибирск' ) );
fias_smoke_assert( ! $disabled->success && 'fias_runtime_disabled' === $disabled->error_code, 'Saved token must return runtime disabled placeholder.' );
fias_smoke_assert( 0 === $GLOBALS['wdc_fias_http_requests'], 'FIAS normalizer must not execute runtime HTTP requests.' );

$session = new CheckoutSessionManager();
$normalizer = new CheckoutAddressNormalizer( $fias, new FallbackAddressNormalizer() );
$runtime = new CheckoutAddressRuntime( $normalizer, $resolver, $session );
$known_city = (string) $wpdb->tables['wdc_locations'][1]['city_name'];
$result = $runtime->resolve_checkout_address( array( 'shipping_country' => 'RU', 'shipping_city' => $known_city, 'shipping_address_1' => 'Ленина', 'shipping_address_2' => '1' ) );
fias_smoke_assert( ! $result->success && $result->address->fallback, 'Checkout chain must continue to manual fallback after disabled FIAS and DaData.' );
fias_smoke_assert( '630000' === $result->address->postcode, 'Local city DB must still provide postcode context.' );
fias_smoke_assert( array() !== $session->selected_city(), 'Local city DB must still provide selected city context.' );
fias_smoke_assert( 'local_db' === ( $session->city_context()['source'] ?? '' ), 'Local city DB must set local_db city source.' );

ob_start();
( new CheckoutAddressRenderer( $session ) )->render();
$local_city_html = (string) ob_get_clean();
fias_smoke_assert( str_contains( $local_city_html, 'Населенный пункт выбран из справочника' ), 'Renderer must show dictionary city for local city context.' );
fias_smoke_assert( ! str_contains( $local_city_html, 'Используется введенный вручную населенный пункт' ), 'Renderer must not show manual city for local city context.' );

$manual = $runtime->resolve_checkout_address( array( 'shipping_country' => 'RU', 'shipping_city' => 'Berlin', 'shipping_address_1' => 'Manual street' ) );
fias_smoke_assert( 'manual' === ( $session->city_context()['source'] ?? '' ), 'Unknown city must set manual city source.' );
ob_start();
( new CheckoutAddressRenderer( $session ) )->render();
$manual_city_html = (string) ob_get_clean();
fias_smoke_assert( str_contains( $manual_city_html, 'Используется введенный вручную населенный пункт' ), 'Renderer must show manual city state for manual fallback city.' );
fias_smoke_assert( ! $manual->success, 'Manual city chain must remain unsuccessful normalization.' );

$gar = new GarSyncManager( new ActionScheduler( new Logger() ), new GarChangesClient( $http ), new Logger(), $settings, $wpdb );
$before_gar_requests = $GLOBALS['wdc_fias_http_requests'];
$gar_status = $gar->check_for_changes();
fias_smoke_assert( ! empty( $gar_status['disabled'] ), 'GAR runtime requests must be disabled by default.' );
fias_smoke_assert( $before_gar_requests === $GLOBALS['wdc_fias_http_requests'], 'GAR disabled check must not execute HTTP requests.' );

echo "FIAS smoke test passed.\n";
