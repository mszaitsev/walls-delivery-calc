<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataSuggestionClient;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Postcodes\DaDataPostcodeResponseSync;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'test-dadata-postcode-response-sync-key' );

$GLOBALS['wdc_sync_options'] = array();
$GLOBALS['wdc_sync_transients'] = array();
$GLOBALS['wdc_sync_http_requests'] = array();
$GLOBALS['wdc_sync_http_response_queue'] = array();

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		public int $insert_id = 0;
		public bool $throw_on_get_row = false;

		/** @var array<int,array<string,mixed>> */
		public array $rows = array();

		public function prepare( string $query, mixed ...$args ): array {
			return array( 'query' => $query, 'args' => $args );
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function get_row( mixed $prepared, string $output ): ?array {
			if ( $this->throw_on_get_row ) {
				throw new RuntimeException( 'Synthetic postcode sync failure.' );
			}
			$query = is_array( $prepared ) ? (string) ( $prepared['query'] ?? '' ) : (string) $prepared;
			$value = is_array( $prepared ) ? (string) ( $prepared['args'][0] ?? '' ) : '';
			foreach ( $this->rows as $row ) {
				if ( str_contains( $query, 'WHERE l.fias_id' ) && $value === (string) ( $row['fias_id'] ?? '' ) ) {
					return $row;
				}
			}
			return null;
		}

		public function get_results( mixed $prepared, string $output ): array {
			return array();
		}

		public function get_var( mixed $query ): int {
			return count( $this->rows );
		}

		public function insert( string $table, array $data, array $format ): int {
			++$this->insert_id;
			$data['id'] = $this->insert_id;
			$this->rows[ $this->insert_id ] = $data;
			return 1;
		}

		public function update( string $table, array $data, array $where, array $format, array $where_format ): int {
			return 0;
		}

		public function query( mixed $query ): int {
			return 1;
		}
	}
}

function current_time( string $type ): string { return '2026-05-24 12:00:00'; }
function wp_date( string $format ): string { return gmdate( $format, strtotime( '2026-05-24 12:00:00' ) ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_sync_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_sync_options'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_sync_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $expiration ): bool { $GLOBALS['wdc_sync_transients'][ $key ] = $value; return true; }
function __( string $text, string $domain = '' ): string { return $text; }
function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', $value ) ?? '' ); }
function sanitize_text_field( string $value ): string { return trim( strip_tags( $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags | JSON_UNESCAPED_UNICODE ); }
function is_wp_error( mixed $value ): bool { return false; }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
function wp_remote_post( string $url, array $args = array() ): array {
	$GLOBALS['wdc_sync_http_requests'][] = array( 'url' => $url, 'args' => $args );
	$response = array_shift( $GLOBALS['wdc_sync_http_response_queue'] );
	return is_array( $response ) ? $response : array( 'response' => array( 'code' => 200 ), 'body' => wp_json_encode( array( 'suggestions' => array() ) ) );
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function postcode_sync_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function postcode_sync_response( string $fias_id, string $postal_code ): array {
	return array(
		'response' => array( 'code' => 200 ),
		'body' => wp_json_encode(
			array(
				'suggestions' => array(
					array(
						'value' => 'safe test value',
						'data' => array(
							'fias_id' => $fias_id,
							'postal_code' => $postal_code,
						),
					),
				),
			)
		),
	);
}

function postcode_sync_client( DaDataPostcodeResponseSync $sync, DaDataTokenPool $pool ): DaDataSuggestionClient {
	$settings = new SettingsRepository();
	$settings->replace( array_merge( $settings->all(), array( 'dadata_suggestions_enabled' => true ) ) );
	return new DaDataSuggestionClient( new AddressSuggestionSettings( $settings, new EncryptionService(), $pool ), $pool, new Logger(), $sync );
}

function postcode_sync_pool( string $token_id = 'sync-token' ): DaDataTokenPool {
	$settings = new SettingsRepository();
	$settings->replace( array_merge( $settings->all(), array( 'dadata_suggestions_enabled' => true ) ) );
	$pool = new DaDataTokenPool( $settings, new EncryptionService() );
	$pool->save_tokens_from_admin(
		array(
			'id' => array( $token_id ),
			'label' => array( 'Sync token' ),
			'token' => array( 'sync-api-key' ),
			'daily_limit' => array( 10000 ),
			'enabled' => array( 0 => '1' ),
		)
	);
	return $pool;
}

$wpdb = new wpdb();
$wpdb->rows = array(
	1 => array( 'id' => 1, 'fias_id' => 'fias-empty', 'place_name' => 'Пустой', 'postal_code' => '', 'active' => 1 ),
	2 => array( 'id' => 2, 'fias_id' => 'fias-marker', 'place_name' => 'Маркер', 'postal_code' => '999999999', 'active' => 1 ),
	3 => array( 'id' => 3, 'fias_id' => 'fias-old', 'place_name' => 'Старый', 'postal_code' => '111111', 'active' => 1 ),
	4 => array( 'id' => 4, 'fias_id' => 'fias-same', 'place_name' => 'Такой же', 'postal_code' => '444444', 'active' => 1 ),
	5 => array( 'id' => 5, 'fias_id' => 'fias-invalid', 'place_name' => 'Невалидный', 'postal_code' => '', 'active' => 1 ),
);
$repository = new LocationRepository( $wpdb );
$sync = new DaDataPostcodeResponseSync( $repository );

$summary = $sync->sync_from_dadata_response(
	array(
		'suggestions' => array(
			array( 'data' => array( 'fias_id' => 'fias-empty', 'postal_code' => '630001' ) ),
			array( 'data' => array( 'fias_id' => 'fias-marker', 'postal_code' => '630002' ) ),
			array( 'data' => array( 'fias_id' => 'fias-old', 'postal_code' => '630003' ) ),
			array( 'data' => array( 'fias_id' => 'fias-same', 'postal_code' => '444444' ) ),
			array( 'data' => array( 'fias_id' => 'fias-empty', 'postal_code' => '' ) ),
			array( 'data' => array( 'postal_code' => '630004' ) ),
			array( 'data' => array( 'fias_id' => 'fias-missing', 'postal_code' => '630005' ) ),
			array( 'data' => array( 'fias_id' => 'fias-invalid', 'postal_code' => '12345' ) ),
		),
	)
);

postcode_sync_assert( 8 === $summary['checked'], 'Sync must check all suggestions.' );
postcode_sync_assert( 4 === $summary['matched'], 'Sync must match existing locations by fias_id.' );
postcode_sync_assert( 3 === $summary['updated'], 'Sync must count changed postal_code rows.' );
postcode_sync_assert( 1 === $summary['skipped_empty_postal_code'], 'Sync must skip empty postal_code.' );
postcode_sync_assert( 1 === $summary['skipped_missing_fias_id'], 'Sync must skip missing fias_id.' );
postcode_sync_assert( 1 === $summary['not_found'], 'Sync must not create new locations when fias_id is not found.' );
postcode_sync_assert( '630001' === $wpdb->rows[1]['postal_code'], 'Sync must overwrite empty postal_code.' );
postcode_sync_assert( '630002' === $wpdb->rows[2]['postal_code'], 'Sync must overwrite 999999999 with real postal_code.' );
postcode_sync_assert( '630003' === $wpdb->rows[3]['postal_code'], 'Sync must overwrite different existing postal_code.' );
postcode_sync_assert( '444444' === $wpdb->rows[4]['postal_code'], 'Sync may leave same postal_code unchanged.' );
postcode_sync_assert( '' === $wpdb->rows[5]['postal_code'], 'Sync must validate postal_code as 6 digits.' );
postcode_sync_assert( true === $sync->sync_from_suggestion_data( array( 'fias_id' => 'fias-empty', 'postal_code' => '630006' ) ), 'sync_from_suggestion_data must return true on update.' );

$GLOBALS['wdc_sync_http_requests'] = array();
$GLOBALS['wdc_sync_http_response_queue'] = array( postcode_sync_response( 'fias-empty', '630007' ) );
$pool = postcode_sync_pool( 'sync-token-success' );
$client_response = postcode_sync_client( $sync, $pool )->suggest( 'address', 'safe query' );
postcode_sync_assert( true === ( $client_response['success'] ?? false ), 'DaDataSuggestionClient must return successful suggestion response with sync enabled.' );
postcode_sync_assert( '630007' === $wpdb->rows[1]['postal_code'], 'DaDataSuggestionClient must call sync after successful DaData response.' );
postcode_sync_assert( 1 === count( $GLOBALS['wdc_sync_http_requests'] ), 'Sync must not make additional DaData HTTP requests.' );
postcode_sync_assert( 1 === $pool->usage_today( 'sync-token-success' ), 'Sync must not increment DaData usage counter separately.' );

$throwing_db = new wpdb();
$throwing_db->rows = array( 1 => array( 'id' => 1, 'fias_id' => 'fias-throw', 'place_name' => 'Throw', 'postal_code' => '', 'active' => 1 ) );
$throwing_db->throw_on_get_row = true;
$throwing_sync = new DaDataPostcodeResponseSync( new LocationRepository( $throwing_db ) );
$GLOBALS['wdc_sync_http_requests'] = array();
$GLOBALS['wdc_sync_http_response_queue'] = array( postcode_sync_response( 'fias-throw', '630008' ) );
$throwing_pool = postcode_sync_pool( 'sync-token-throwing' );
$throwing_response = postcode_sync_client( $throwing_sync, $throwing_pool )->suggest( 'address', 'safe query' );
postcode_sync_assert( true === ( $throwing_response['success'] ?? false ), 'Sync failure must not break suggestion response.' );
postcode_sync_assert( 1 === count( $GLOBALS['wdc_sync_http_requests'] ), 'Failing sync must not make additional DaData HTTP requests.' );
postcode_sync_assert( 1 === $throwing_pool->usage_today( 'sync-token-throwing' ), 'Failing sync must not increment usage beyond the original HTTP request.' );

echo "DaData postcode response sync smoke test passed.\n";
