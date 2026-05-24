<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Admin\LocationsAdminPage;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Postcodes\DaDataPostcodeClient;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'wdc-postcode-smoke-key' );

/** @var array<string,mixed> $wdc_options */
$wdc_options = array();
/** @var array<string,mixed> $wdc_transients */
$wdc_transients = array();
/** @var array<int,array<string,mixed>> $wdc_http_queue */
$wdc_http_queue = array();

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		public int $insert_id = 0;

		/** @var array<int,array<string,mixed>> */
		public array $rows = array();

		public function prepare( string $query, mixed ...$args ): array {
			return array( 'query' => $query, 'args' => $args );
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function insert( string $table, array $data, array $format ): int {
			++$this->insert_id;
			$data['id'] = $this->insert_id;
			$this->rows[ $this->insert_id ] = $data;
			return 1;
		}

		public function update( string $table, array $data, array $where, array $format, array $where_format ): int {
			$id = (int) ( $where['id'] ?? 0 );
			if ( isset( $this->rows[ $id ] ) ) {
				$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
				return 1;
			}

			return 0;
		}

		public function get_var( mixed $query ): int {
			if ( is_string( $query ) && str_contains( $query, 'wdc_location_aliases' ) ) {
				return 0;
			}

			return count( $this->rows );
		}

		public function get_row( mixed $prepared, string $output ): ?array {
			return null;
		}

		public function get_results( mixed $prepared, string $output ): array {
			return array();
		}

		public function query( mixed $query ): int {
			return 1;
		}
	}
}

function current_time( string $type ): string {
	return '2026-05-24 12:00:00';
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
}

function wp_date( string $format ): string {
	return gmdate( $format, strtotime( '2026-05-24 12:00:00' ) );
}

function __( string $text, string $domain = '' ): string {
	return $text;
}

function esc_html__( string $text, string $domain = '' ): string {
	return $text;
}

function esc_attr__( string $text, string $domain = '' ): string {
	return $text;
}

function esc_html( mixed $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_attr( mixed $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function esc_js( mixed $text ): string {
	return addslashes( (string) $text );
}

function current_user_can( string $capability ): bool {
	return 'manage_woocommerce' === $capability;
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', $value ) ?? '' );
}

function wp_unslash( mixed $value ): mixed {
	return $value;
}

function wp_verify_nonce( string $nonce, string $action ): bool {
	return 'test-nonce' === $nonce && 'wdc_locations_import_demo' === $action;
}

function wp_nonce_field( string $action, string $name ): void {
	printf( '<input type="hidden" name="%s" value="test-nonce">', esc_attr( $name ) );
}

function wp_create_nonce( string $action ): string {
	return 'test-nonce';
}

function admin_url( string $path ): string {
	return 'http://example.test/wp-admin/' . ltrim( $path, '/' );
}

function get_option( string $key, mixed $default = false ): mixed {
	global $wdc_options;
	return array_key_exists( $key, $wdc_options ) ? $wdc_options[ $key ] : $default;
}

function update_option( string $key, mixed $value, bool $autoload = true ): bool {
	global $wdc_options;
	$wdc_options[ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
	global $wdc_options;
	unset( $wdc_options[ $key ] );
	return true;
}

function get_transient( string $key ): mixed {
	global $wdc_transients;
	return $wdc_transients[ $key ] ?? false;
}

function set_transient( string $key, mixed $value, int $expiration ): bool {
	global $wdc_transients;
	$wdc_transients[ $key ] = $value;
	return true;
}

function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
	return json_encode( $value, $flags );
}

function wp_remote_post( string $url, array $args ): array {
	global $wdc_http_queue;
	$response = array_shift( $wdc_http_queue );
	return is_array( $response ) ? $response : array( 'response' => array( 'code' => 200 ), 'body' => '{"suggestions":[]}' );
}

function is_wp_error( mixed $value ): bool {
	return false;
}

function wp_remote_retrieve_response_code( array $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( array $response ): string {
	return (string) ( $response['body'] ?? '' );
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function postcode_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function postcode_response( string $name, string $postal_code, string $fias_id = 'fias-city' ): array {
	return array(
		'response' => array( 'code' => 200 ),
		'body' => json_encode(
			array(
				'suggestions' => array(
					array(
						'data' => array(
							'fias_id' => $fias_id,
							'city' => $name,
							'settlement' => '',
							'postal_code' => $postal_code,
						),
					),
				),
			),
			JSON_UNESCAPED_UNICODE
		),
	);
}

function postcode_token_pool(): DaDataTokenPool {
	$settings = new SettingsRepository();
	$encryption = new EncryptionService();
	$settings->replace(
		array(
			'dadata_suggestions_tokens' => array(
				array(
					'id' => 'token-one',
					'encrypted_token' => $encryption->encrypt( 'plain-token-one' ),
					'daily_limit' => 100,
					'enabled' => true,
				),
				array(
					'id' => 'token-two',
					'encrypted_token' => $encryption->encrypt( 'plain-token-two' ),
					'daily_limit' => 100,
					'enabled' => true,
				),
			),
		)
	);
	return new DaDataTokenPool( $settings, $encryption );
}

function postcode_admin( LocationRepository $repository, DaDataPostcodeClient $client ): LocationsAdminPage {
	return new LocationsAdminPage(
		new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.16.0' ),
		$repository,
		new LocationSearchService( $repository ),
		new LocationImportService( $repository ),
		null,
		null,
		null,
		null,
		null,
		null,
		null,
		null,
		$client
	);
}

function postcode_step( LocationsAdminPage $admin, array $job ): array {
	$method = new ReflectionMethod( LocationsAdminPage::class, 'step_dadata_postcode_job' );
	$method->setAccessible( true );
	$result = $method->invoke( $admin, $job );
	return is_array( $result ) ? $result : array();
}

$wpdb = new wpdb();
$repository = new LocationRepository( $wpdb );
$wpdb->rows = array(
	1 => array( 'id' => 1, 'fias_id' => 'fias-city', 'place_name' => 'Новосибирск', 'settlement_name' => 'Новосибирск', 'city_name' => 'Новосибирск', 'place_type' => 'г', 'display_name' => 'Новосибирск', 'postal_code' => '', 'active' => 1, 'searchable_text' => 'новосибирск' ),
	2 => array( 'id' => 2, 'fias_id' => 'fias-city-2', 'place_name' => 'Бердск', 'settlement_name' => 'Бердск', 'city_name' => 'Бердск', 'place_type' => 'г.', 'display_name' => 'Бердск', 'postal_code' => '', 'active' => 1, 'searchable_text' => 'бердск' ),
	3 => array( 'id' => 3, 'fias_id' => 'fias-village', 'place_name' => 'Барышево', 'settlement_name' => 'Барышево', 'city_name' => '', 'place_type' => 'с', 'display_name' => 'Барышево', 'postal_code' => '', 'active' => 1, 'searchable_text' => 'барышево' ),
	4 => array( 'id' => 4, 'fias_id' => 'fias-filled', 'place_name' => 'Искитим', 'settlement_name' => 'Искитим', 'city_name' => 'Искитим', 'place_type' => 'г', 'display_name' => 'Искитим', 'postal_code' => '633200', 'active' => 1, 'searchable_text' => 'искитим' ),
	5 => array( 'id' => 5, 'fias_id' => 'fias-marker', 'place_name' => 'Без индекса', 'settlement_name' => 'Без индекса', 'city_name' => '', 'place_type' => 'с', 'display_name' => 'Без индекса', 'postal_code' => '999999999', 'active' => 1, 'searchable_text' => 'без индекса' ),
);

postcode_smoke_assert( 2 === $repository->count_with_postal_code(), 'Repository must count filled postal_code including technical marker.' );
postcode_smoke_assert( 3 === $repository->count_without_postal_code(), 'Repository must count missing postal_code.' );
postcode_smoke_assert( 1 === $repository->count_technical_no_index_marker(), 'Repository must count technical marker.' );

$cities = $repository->next_postcode_batch( true, 20, 0 );
postcode_smoke_assert( array( 1, 2 ) === array_map( static fn( array $row ): int => (int) $row['id'], $cities ), 'Cities phase must return г/г. first by id.' );

$others = $repository->random_postcode_batch_for_non_cities( 20 );
postcode_smoke_assert( 1 === count( $others ) && 3 === (int) $others[0]['id'], 'Non-cities random batch must exclude filled postal_code and marker.' );

$client = new DaDataPostcodeClient( postcode_token_pool(), new Logger(), 3 );
global $wdc_http_queue;
$wdc_http_queue = array( postcode_response( 'Новосибирск', '630000' ) );
$result = $client->find_postal_code( $wpdb->rows[1] );
postcode_smoke_assert( ! empty( $result['success'] ) && '630000' === $result['postal_code'], 'Successful DaData response must return postal_code.' );
$repository->update_postal_code( 1, (string) $result['postal_code'] );
postcode_smoke_assert( '630000' === $wpdb->rows[1]['postal_code'], 'Successful DaData response must write postal_code.' );

$wdc_http_queue = array( postcode_response( 'Барышево', '', 'fias-village' ) );
$result = $client->find_postal_code( $wpdb->rows[3] );
postcode_smoke_assert( ! empty( $result['success'] ) && '' === $result['postal_code'], 'DaData response without postal_code must be a matched success.' );
$repository->update_postal_code( 3, '' === $result['postal_code'] ? '999999999' : (string) $result['postal_code'] );
postcode_smoke_assert( '999999999' === $wpdb->rows[3]['postal_code'], 'Response without postal_code must write technical marker.' );

$mismatch_db = new wpdb();
$mismatch_repository = new LocationRepository( $mismatch_db );
$mismatch_db->rows = array(
	1 => array( 'id' => 1, 'fias_id' => 'fias-mismatch', 'place_name' => 'Ожидаемое', 'settlement_name' => 'Ожидаемое', 'city_name' => '', 'place_type' => 'г', 'display_name' => 'Ожидаемое', 'postal_code' => '', 'active' => 1, 'searchable_text' => 'ожидаемое' ),
);
$wdc_http_queue = array( postcode_response( 'Другое', '111111', 'fias-mismatch' ) );
$job = postcode_step( postcode_admin( $mismatch_repository, new DaDataPostcodeClient( postcode_token_pool(), new Logger(), 3 ) ), array( 'phase' => 'running', 'processed' => 0, 'updated' => 0, 'marked_no_index' => 0, 'skipped' => 0, 'errors' => 0, 'consecutive_errors' => 0, 'last_id' => 0, 'current_priority' => 'cities', 'tokens_exhausted' => false ) );
postcode_smoke_assert( 1 === (int) $job['consecutive_errors'], 'Name mismatch must increment consecutive_errors.' );

$fail_db = new wpdb();
$fail_repository = new LocationRepository( $fail_db );
for ( $i = 1; $i <= 30; ++$i ) {
	$fail_db->rows[ $i ] = array( 'id' => $i, 'fias_id' => 'fias-fail-' . $i, 'place_name' => 'Ожидаемое ' . $i, 'settlement_name' => 'Ожидаемое ' . $i, 'city_name' => '', 'place_type' => 'г', 'display_name' => 'Ожидаемое ' . $i, 'postal_code' => '', 'active' => 1, 'searchable_text' => 'ожидаемое' );
	$wdc_http_queue[] = postcode_response( 'Другое', '111111', 'fias-fail-' . $i );
}
$admin = postcode_admin( $fail_repository, new DaDataPostcodeClient( postcode_token_pool(), new Logger(), 3 ) );
$job = array( 'phase' => 'running', 'processed' => 0, 'updated' => 0, 'marked_no_index' => 0, 'skipped' => 0, 'errors' => 0, 'consecutive_errors' => 0, 'last_id' => 0, 'current_priority' => 'cities', 'tokens_exhausted' => false );
while ( 'running' === (string) $job['phase'] ) {
	$job = postcode_step( $admin, $job );
}
postcode_smoke_assert( 'failed' === $job['phase'] && 30 === (int) $job['consecutive_errors'], '30 consecutive errors must stop job as failed.' );

$wdc_http_queue = array(
	array( 'response' => array( 'code' => 429 ), 'body' => '{"message":"daily limit exceeded"}' ),
	array( 'response' => array( 'code' => 429 ), 'body' => '{"message":"daily quota exceeded"}' ),
);
$limit_result = ( new DaDataPostcodeClient( postcode_token_pool(), new Logger(), 3 ) )->find_postal_code( array( 'id' => 99, 'fias_id' => 'fias-limit', 'place_name' => 'Лимит', 'settlement_name' => 'Лимит', 'city_name' => '', 'postal_code' => '' ) );
postcode_smoke_assert( empty( $limit_result['success'] ) && ! empty( $limit_result['tokens_exhausted'] ), 'All tokens exhausted must finish with daily_limit_exhausted reason.' );

$repository->clear_postal_code_marker();
postcode_smoke_assert( '' === $wpdb->rows[3]['postal_code'] && '' === $wpdb->rows[5]['postal_code'], 'Clear markers must reset 999999999 to empty.' );

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array();
$_POST = array();
ob_start();
postcode_admin( $repository, $client )->render_page();
$html = (string) ob_get_clean();
postcode_smoke_assert( str_contains( $html, 'Заполнение почтовых индексов через DaData' ), 'Admin markup must render postcode fill block.' );
postcode_smoke_assert( str_contains( $html, '<progress value="0" max="100">' ) && str_contains( $html, 'JSON status' ), 'Admin markup must include progress bar and JSON status.' );
postcode_smoke_assert( ! str_contains( $html, 'plain-token-one' ) && ! str_contains( $html, 'Authorization' ), 'API token must not be exposed to frontend.' );
postcode_smoke_assert( is_file( dirname( __DIR__, 2 ) . '/docs/wdc-dadata-postcode-fill-removal.md' ), 'Removal doc must exist.' );

echo "DaData postcode fill smoke test passed.\n";
