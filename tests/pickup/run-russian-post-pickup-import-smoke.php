<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'test-rp-otpravka-key' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function rp_pickup_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-05-28 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) ) ?? ''; }
function sanitize_file_name( mixed $value ): string { return preg_replace( '/[^A-Za-z0-9._-]/', '', basename( (string) $value ) ) ?: 'upload.zip'; }
function wp_unslash( mixed $value ): mixed { return $value; }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function wp_tempnam( string $filename = '' ): string { return tempnam( sys_get_temp_dir(), 'wdc-rp-' ) ?: ''; }
function wp_delete_file( string $file ): bool { $GLOBALS['wdc_deleted_files'][] = $file; return @unlink( $file ); }
function wp_upload_dir(): array { return array( 'basedir' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-uploads' ); }
function wp_mkdir_p( string $dir ): bool { return is_dir( $dir ) || mkdir( $dir, 0755, true ); }
function wp_unique_filename( string $dir, string $filename ): string { return $filename; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_response_message( array $response ): string { return (string) ( $response['response']['message'] ?? '' ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }
function wp_next_scheduled( string $hook ): int|false { return $GLOBALS['wdc_recurring_events'][ $hook ]['timestamp'] ?? false; }
function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool { $GLOBALS['wdc_recurring_events'][ $hook ] = compact( 'timestamp', 'recurrence', 'hook' ); return true; }
function wp_clear_scheduled_hook( string $hook ): bool { unset( $GLOBALS['wdc_recurring_events'][ $hook ] ); return true; }
function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
	if ( ! empty( $GLOBALS['wdc_force_schedule_failure'] ) ) {
		return false;
	}
	$GLOBALS['wdc_scheduled_events'][] = compact( 'timestamp', 'hook', 'args' );
	return true;
}
function rp_shift_event( string $hook ): array {
	foreach ( $GLOBALS['wdc_scheduled_events'] as $index => $event ) {
		if ( $hook === $event['hook'] ) {
			array_splice( $GLOBALS['wdc_scheduled_events'], $index, 1 );
			return $event;
		}
	}
	return array();
}

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $message ) {}
		public function get_error_message(): string { return $this->message; }
	}
}

$GLOBALS['wdc_options'] = array();
$GLOBALS['wdc_transients'] = array();
$GLOBALS['wdc_scheduled_events'] = array();
$GLOBALS['wdc_recurring_events'] = array();
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = true ): bool { $GLOBALS['wdc_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_options'][ $key ] ); return true; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['wdc_transients'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_transients'][ $key ] ?? false; }
function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_transients'][ $key ] ); return true; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<string,array<int,array<string,mixed>>> */
		public array $tables = array();
		/** @var array<int,string> */
		public array $analyzed_tables = array();
		public string $rename_mode = '';
		public bool $fail_analyze = false;

		public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $value, $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function query( string $query ): int|bool {
			if ( preg_match( '/CREATE TABLE IF NOT EXISTS ([A-Za-z0-9_]+)/', $query, $m ) ) {
				$this->tables[ $m[1] ] ??= array();
				return true;
			}
			if ( preg_match( '/DROP TABLE IF EXISTS ([A-Za-z0-9_]+)/', $query, $m ) ) {
				unset( $this->tables[ $m[1] ] );
				return true;
			}
			if ( preg_match( '/ANALYZE TABLE ([A-Za-z0-9_]+)/', $query, $m ) ) {
				$this->analyzed_tables[] = $m[1];
				return ! $this->fail_analyze;
			}
			if ( preg_match( '/RENAME TABLE (.+)$/', trim( $query ), $m ) ) {
				if ( str_contains( $m[1], ',' ) && in_array( $this->rename_mode, array( 'partial_swap_recover', 'partial_swap_recovery_fails' ), true ) ) {
					$parts = explode( ',', $m[1] );
					$this->rename_part( trim( $parts[0] ) );
					return true;
				}
				if ( 'partial_swap_recovery_fails' === $this->rename_mode && ! str_contains( $m[1], ',' ) ) {
					return false;
				}
				foreach ( explode( ',', $m[1] ) as $part ) {
					$this->rename_part( trim( $part ) );
				}
				return true;
			}
			return true;
		}
		private function rename_part( string $part ): void {
			if ( preg_match( '/([A-Za-z0-9_]+) TO ([A-Za-z0-9_]+)/', $part, $r ) ) {
				$this->tables[ $r[2] ] = $this->tables[ $r[1] ] ?? array();
				unset( $this->tables[ $r[1] ] );
			}
		}
		public function insert( string $table, array $data, array $format = array() ): bool {
			$this->tables[ $table ] ??= array();
			$data['id'] = ++$this->insert_id;
			$this->tables[ $table ][] = $data;
			return true;
		}
		public function get_var( string $query ): mixed {
			if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $m ) ) {
				return array_key_exists( $m[1], $this->tables ) ? $m[1] : null;
			}
			$rows = $this->filter_rows( $query );
			return count( $rows );
		}
		public function get_row( string $query, mixed $output = null ): ?array {
			$rows = $this->filter_rows( $query );
			if ( preg_match( '/WHERE id = ([0-9]+)/', $query, $m ) ) {
				foreach ( $rows as $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === (int) $m[1] ) {
						return $row;
					}
				}
			}
			return $rows[0] ?? null;
		}
		public function get_results( string $query, mixed $output = null ): array {
			$rows = $this->filter_rows( $query );
			if ( str_contains( $query, 'GROUP BY point_type' ) ) {
				$grouped = array();
				foreach ( $rows as $row ) {
					$type = (string) ( $row['point_type'] ?? '' );
					$grouped[ $type ] = ( $grouped[ $type ] ?? 0 ) + 1;
				}
				return array_map( static fn( string $type, int $total ): array => array( 'point_type' => $type, 'total' => $total ), array_keys( $grouped ), array_values( $grouped ) );
			}
			return $rows;
		}
		private function filter_rows( string $query ): array {
			$table = '';
			if ( preg_match( '/FROM ([A-Za-z0-9_]+)/', $query, $m ) ) {
				$table = $m[1];
			}
			$rows = $this->tables[ $table ] ?? array();
			if ( str_contains( $query, 'active = 1' ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => 1 === (int) ( $row['active'] ?? 0 ) ) );
			}
			if ( preg_match( "/point_type = '([^']+)'/", $query, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['point_type'] ?? '' ) === $m[1] ) );
			}
			return $rows;
		}
	}
}

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPassportPointNormalizer;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImporter;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImportStateService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostWorkTimeFormatter;

$GLOBALS['wpdb'] = new wpdb();
$repo = new RussianPostPickupPointRepository( $GLOBALS['wpdb'] );
$migration = require dirname( __DIR__, 2 ) . '/database/migrations/0021_create_russian_post_pickup_points_table.php';
$migration();
rp_pickup_assert( array_key_exists( 'wp_wdc_pickup_points_russian_post', $GLOBALS['wpdb']->tables ), 'Migration must create Russian Post carrier-specific table.' );
$schema = $repo->schema_sql();
rp_pickup_assert( ! str_contains( $schema, 'raw_reference' ) && ! str_contains( $schema, 'work_time_json' ) && str_contains( $schema, 'work_time TEXT NULL' ), 'Russian Post pickup schema must store compact work_time without raw JSON fields.' );
$removed_fields = array( 'brand_name', 'ecom_options_json', 'services_json', 'phones_json', 'images_json', 'weight_limit_grams', 'size_limit_json', 'accepts_cash', 'accepts_card', 'partial_redemption', 'return_available', 'fitting_available', 'contents_checking', 'functionality_checking', 'raw_reference', 'work_time_json' );
foreach ( $removed_fields as $removed_field ) {
	rp_pickup_assert( ! str_contains( $schema, $removed_field ), 'Russian Post pickup schema must not contain removed field: ' . $removed_field );
}

$normalizer = new RussianPostPassportPointNormalizer();
$formatter = new RussianPostWorkTimeFormatter();
$standard_work_time = array(
	'пн, открыто: 08:00 - 17:00, перерыв: 12:00 - 13:00',
	'вт, открыто: 08:00 - 17:00, перерыв: 12:00 - 13:00',
	'ср, открыто: 08:00 - 17:00, перерыв: 12:00 - 13:00',
	'чт, открыто: 08:00 - 17:00, перерыв: 12:00 - 13:00',
	'пт, открыто: 08:00 - 17:00, перерыв: 12:00 - 13:00',
	'сб, выходной',
	'вс, выходной',
);
rp_pickup_assert( "Пн–Пт: 08:00–17:00\nПерерыв: 12:00–13:00\nСб–Вс: выходной" === $formatter->format( $standard_work_time ), 'Formatter must group standard week with break.' );
rp_pickup_assert( "Пн–Пт: 09:00–18:00\nСб–Вс: выходной" === $formatter->format( array( 'пн, открыто: 09:00 - 18:00', 'вт, открыто: 09:00 - 18:00', 'ср, открыто: 09:00 - 18:00', 'чт, открыто: 09:00 - 18:00', 'пт, открыто: 09:00 - 18:00', 'сб, выходной', 'вс, выходной' ) ), 'Formatter must omit break when absent.' );
rp_pickup_assert( "Пн–Ср: 08:00–17:00\nПерерыв: 12:00–13:00\nЧт–Пт: 09:00–18:00\nПерерыв: 13:00–14:00\nСб–Вс: выходной" === $formatter->format( array( 'пн, открыто: 08:00 - 17:00, перерыв: 12:00 - 13:00', 'вт, открыто: 08:00 - 17:00, перерыв: 12:00 - 13:00', 'ср, открыто: 08:00 - 17:00, перерыв: 12:00 - 13:00', 'чт, открыто: 09:00 - 18:00, перерыв: 13:00 - 14:00', 'пт, открыто: 09:00 - 18:00, перерыв: 13:00 - 14:00', 'сб, выходной', 'вс, выходной' ) ), 'Formatter must split groups with different breaks.' );
rp_pickup_assert( "непонятный график\nещё строка" === $formatter->format( array( 'непонятный график', 'ещё строка' ) ), 'Formatter fallback must not fail on unknown format.' );
rp_pickup_assert( '' === $formatter->format( array() ), 'Formatter must return empty string for empty workTime.' );
$settings = new RussianPostOtpravkaApiSettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin( array( 'russian_post_otpravka_access_token' => 'token', 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_password' => 'password', 'russian_post_pickup_unload_type' => 'ALL' ) );
rp_pickup_assert( 120 === $settings->timeout(), 'Otpravka timeout default must be 120 seconds.' );
$settings->save_from_admin( array( 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_timeout' => '999' ) );
rp_pickup_assert( 300 === $settings->timeout(), 'Otpravka timeout max must be 300 seconds.' );
$settings->save_from_admin( array( 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_timeout' => '1' ) );
rp_pickup_assert( 30 === $settings->timeout(), 'Otpravka timeout min must be 30 seconds.' );
$settings->save_from_admin( array( 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_timeout' => '120' ) );
$state_service = new RussianPostPickupImportStateService();

function wp_remote_get( string $url, array $args = array() ): mixed {
	$GLOBALS['rp_last_http_args'] = $args;
	if ( 'wp_error' === ( $GLOBALS['rp_http_mode'] ?? '' ) ) {
		return new WP_Error( 'cURL error 28: Operation timed out' );
	}
	if ( 'http_500' === ( $GLOBALS['rp_http_mode'] ?? '' ) ) {
		file_put_contents( (string) $args['filename'], 'partial' );
		return array( 'response' => array( 'code' => 500, 'message' => 'Internal Server Error' ), 'body' => 'temporary failure body' );
	}
	$zip_file = tempnam( sys_get_temp_dir(), 'wdc-rp-zip-' );
	$zip = new ZipArchive();
	$zip->open( $zip_file, ZipArchive::OVERWRITE );
	$zip->addFromString( 'passport.json', (string) $GLOBALS['rp_passport_payload'] );
	$zip->close();
	copy( $zip_file, (string) $args['filename'] );
	unlink( $zip_file );
	return array( 'response' => array( 'code' => 200 ), 'body' => '' );
}

function rp_write_passport_zip( string $target ): void {
	$zip_file = tempnam( sys_get_temp_dir(), 'wdc-rp-zip-' );
	$zip = new ZipArchive();
	$zip->open( $zip_file, ZipArchive::OVERWRITE );
	$zip->addFromString( 'passport.json', (string) $GLOBALS['rp_passport_payload'] );
	$zip->close();
	copy( $zip_file, $target );
	unlink( $zip_file );
}

function rp_curl_success_downloader(): callable {
	return static function ( string $url, string $type ): array {
		$temp = wp_tempnam( 'wdc-russian-post-passport.zip' );
		rp_write_passport_zip( $temp );
		return array( 'success' => true, 'url' => $url, 'type' => $type, 'http_code' => 200, 'response_message' => '', 'temp_file' => $temp, 'temp_file_size' => filesize( $temp ), 'duration_ms' => 7, 'download_backend' => 'curl', 'curl_errno' => 0, 'curl_error' => '' );
	};
}

function rp_curl_failure_downloader( string $message = 'Injected cURL failure', int $errno = 28 ): callable {
	return static function ( string $url, string $type ) use ( $message, $errno ): array {
		$temp = wp_tempnam( 'wdc-russian-post-passport.zip' );
		file_put_contents( $temp, 'partial-curl' );
		return array( 'success' => false, 'url' => $url, 'type' => $type, 'http_code' => 0, 'error' => $message, 'temp_file' => $temp, 'temp_file_size' => filesize( $temp ), 'duration_ms' => 3, 'download_backend' => 'curl', 'curl_errno' => $errno, 'curl_error' => $message );
	};
}

$base_item = array(
	'address' => array( 'index' => '630001', 'region' => 'НСО', 'place' => 'Новосибирск', 'street' => 'Ленина', 'house' => '1' ),
	'addressFias' => array( 'ads' => 'Новосибирск, Ленина, 1' ),
	'brandName' => 'Почта России',
	'ecomOptions' => array( 'cardPayment' => true, 'cashPayment' => false, 'weightLimit' => 10 ),
	'latitude' => 55.1,
	'longitude' => 82.9,
	'type' => 'ПВЗ',
	'workTime' => $standard_work_time,
);
$items = array();
for ( $i = 0; $i < 3; ++$i ) {
	$items[] = array_merge(
		$base_item,
		array(
			'address' => array( 'index' => '63000' . $i, 'region' => 'НСО', 'place' => 'Новосибирск', 'street' => 'Ленина', 'house' => (string) $i ),
			'addressFias' => array( 'ads' => 'Новосибирск, Ленина, ' . $i ),
			'latitude' => 55.1 + $i / 100,
			'longitude' => 82.9 + $i / 100,
		)
	);
}
$GLOBALS['rp_passport_payload'] = '{"passportElements":[' . implode( ',', array_map( static fn( array $item ): string => (string) json_encode( $item, JSON_UNESCAPED_UNICODE ), $items ) ) . ']}';

$curl_client = new RussianPostOtpravkaApiClient( $settings, rp_curl_success_downloader() );
$curl_download = $curl_client->download_passport_zip( 'ALL' );
rp_pickup_assert( ! empty( $curl_download['success'] ) && 'curl' === $curl_download['download_backend'] && empty( $curl_download['fallback_used'] ) && is_file( (string) $curl_download['temp_file'] ), 'cURL backend success must return download_backend=curl.' );
$curl_temp = (string) $curl_download['temp_file'];
$curl_probe = $curl_client->probe_passport_download( 'ALL' );
rp_pickup_assert( ! empty( $curl_probe['success'] ) && '' === (string) $curl_probe['temp_file'] && ! file_exists( (string) ( $curl_probe['temp_file'] ?? '' ) ), 'Probe must delete temp file after successful download.' );
wp_delete_file( $curl_temp );

$GLOBALS['rp_http_mode'] = '';
$fallback_client = new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() );
$fallback_download = $fallback_client->download_passport_zip( 'ALL' );
rp_pickup_assert( ! empty( $fallback_download['success'] ) && 'wp_http' === $fallback_download['download_backend'] && ! empty( $fallback_download['fallback_used'] ) && 28 === (int) $fallback_download['curl_errno'] && str_contains( (string) $fallback_download['first_backend_error'], 'Injected cURL failure' ), 'cURL failure must fall back to WP HTTP and keep first backend diagnostic.' );
wp_delete_file( (string) $fallback_download['temp_file'] );

$GLOBALS['rp_http_mode'] = 'wp_error';
$both_failed_client = new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader( 'Injected cURL timeout', 28 ) );
$both_failed = $both_failed_client->download_passport_zip( 'ALL' );
$GLOBALS['rp_http_mode'] = '';
rp_pickup_assert( empty( $both_failed['success'] ) && str_contains( (string) $both_failed['error'], 'cURL failed: Injected cURL timeout' ) && str_contains( (string) $both_failed['error'], 'WP HTTP failed:' ) && ! file_exists( (string) ( $both_failed['temp_file'] ?? '' ) ), 'Both backend failure must return combined diagnostic and delete temp files.' );

$main = $repo->main_table();
$normalized_base = $normalizer->normalize( $base_item, 'PVZ', '2026-05-28 10:00:00' );
rp_pickup_assert( is_array( $normalized_base ) && "Пн–Пт: 08:00–17:00\nПерерыв: 12:00–13:00\nСб–Вс: выходной" === $normalized_base['work_time'], 'Normalizer must store compact work_time.' );
rp_pickup_assert( ! array_key_exists( 'raw_reference', $normalized_base ) && ! array_key_exists( 'work_time_json', $normalized_base ), 'Normalizer must not output raw_reference or work_time_json.' );
foreach ( $removed_fields as $removed_field ) {
	rp_pickup_assert( ! array_key_exists( $removed_field, $normalized_base ), 'Normalizer must not output removed field: ' . $removed_field );
}
$repo->insert_batch( array( $normalized_base ), $main );
$main_before = count( $GLOBALS['wpdb']->tables[ $main ] );
$importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, $state_service );
rp_pickup_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/RussianPost/RussianPostPickupImporter.php' ), 'private const BATCH_SIZE = 500' ), 'Importer batch size must be 500.' );
$importer_reflection = new ReflectionClass( RussianPostPickupImporter::class );
$path_inside = $importer_reflection->getMethod( 'is_path_inside' );
$path_inside->setAccessible( true );
rp_pickup_assert( true === $path_inside->invoke( $importer, 'C:\\Users\\Admin\\AppData\\Local\\Temp\\WDC-RP-Extract\\passport.json', 'c:\\users\\admin\\appdata\\local\\temp\\wdc-rp-extract' ), 'Windows-style paths with different drive/path case must pass path containment.' );
rp_pickup_assert( true === $path_inside->invoke( $importer, '/tmp/base/nested/passport.json', '/tmp/base' ), 'Normal nested path must pass path containment.' );
rp_pickup_assert( false === $path_inside->invoke( $importer, '/tmp/base2/passport.json', '/tmp/base' ), 'Boundary sibling path must not pass path containment.' );
rp_pickup_assert( $importer->queue_background_import( 'ALL' ), 'Import must queue init job.' );
$init_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$init = $importer->run_import_init( (string) $init_event['args'][0], (string) $init_event['args'][1] );
$state = $state_service->current();
rp_pickup_assert( ! empty( $init['success'] ) && '' !== $state['staging_table'] && array_key_exists( $state['staging_table'], $GLOBALS['wpdb']->tables ), 'Init must create staging table.' );
rp_pickup_assert( $main_before === count( $GLOBALS['wpdb']->tables[ $main ] ), 'Main table must not change during init.' );
rp_pickup_assert( 200 === (int) $state['download_http_code'] && (int) $state['temp_file_size'] > 0 && '' !== (string) $state['download_url'] && isset( $GLOBALS['rp_last_http_args']['connect_timeout'] ) && 'wp_http' === (string) $state['download_backend'] && ! empty( $state['fallback_used'] ) && str_contains( (string) $state['first_backend_error'], 'Injected cURL failure' ), 'Successful fallback download must store backend diagnostics and use connect timeout.' );

$batch_event = rp_shift_event( RussianPostPickupImporter::BATCH_HOOK );
$batch = $importer->run_import_batch( (string) $batch_event['args'][0], (string) $batch_event['args'][1], (int) $batch_event['args'][2] );
$state = $state_service->current();
rp_pickup_assert( ! empty( $batch['success'] ) && 3 === count( $GLOBALS['wpdb']->tables[ $state['staging_table'] ] ) && $main_before === count( $GLOBALS['wpdb']->tables[ $main ] ), 'Batch must write only to staging, not main.' );
rp_pickup_assert( 3 === (int) $state['rows_inserted_to_staging'], 'State must track rows inserted to staging.' );

$final_event = rp_shift_event( RussianPostPickupImporter::FINALIZE_HOOK );
$final = $importer->run_import_finalize( (string) $final_event['args'][0], (string) $final_event['args'][1] );
$state = $state_service->current();
rp_pickup_assert( ! empty( $final['success'] ) && 3 === count( $GLOBALS['wpdb']->tables[ $main ] ) && ! array_key_exists( (string) $state['staging_table'], $GLOBALS['wpdb']->tables ), 'Finalize must atomically swap staging to main.' );
rp_pickup_assert( '' !== (string) $state['swap_started_at'] && '' !== (string) $state['swap_finished_at'], 'State must store swap timestamps.' );
rp_pickup_assert( in_array( $main, $GLOBALS['wpdb']->analyzed_tables, true ), 'Successful finalize must analyze main table.' );

$main_after_success = $GLOBALS['wpdb']->tables[ $main ];
delete_transient( 'wdc_russian_post_pickup_import_lock' );
$uploaded_zip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-uploaded-' . uniqid() . '.zip';
rp_write_passport_zip( $uploaded_zip );
$uploaded_size = filesize( $uploaded_zip );
rp_pickup_assert( $importer->queue_background_import_from_zip( $uploaded_zip, 'ALL', 'passport-all.zip' ), 'Uploaded ZIP import must queue.' );
$uploaded_state = $state_service->current();
rp_pickup_assert( 'queued' === (string) $uploaded_state['status'] && 'uploaded_zip' === (string) $uploaded_state['source'] && 'passport-all.zip' === (string) $uploaded_state['original_upload_name'] && $uploaded_size === (int) $uploaded_state['uploaded_file_size'], 'Uploaded ZIP queue must store source/name/size state.' );
$uploaded_init_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$uploaded_init = $importer->run_import_init( (string) $uploaded_init_event['args'][0], (string) $uploaded_init_event['args'][1] );
$uploaded_state = $state_service->current();
rp_pickup_assert( ! empty( $uploaded_init['success'] ) && 'uploaded_zip' === (string) $uploaded_state['source'] && '' === (string) $uploaded_state['download_backend'] && '' === (string) $uploaded_state['download_url'] && ! file_exists( $uploaded_zip ) && '' !== (string) $uploaded_state['payload_file'], 'Uploaded ZIP init must skip API download, extract payload, and delete uploaded ZIP.' );
rp_pickup_assert( ! empty( $uploaded_state['extract_success'] ) && ! empty( $uploaded_state['ziparchive_available'] ) && (int) $uploaded_state['extract_zip_size'] > 0 && 'passport.json' === (string) $uploaded_state['extracted_payload_entry_name'] && (int) $uploaded_state['extracted_payload_size'] > 0, 'Uploaded ZIP init must store extract diagnostics.' );
$uploaded_batch_event = rp_shift_event( RussianPostPickupImporter::BATCH_HOOK );
$uploaded_batch = $importer->run_import_batch( (string) $uploaded_batch_event['args'][0], (string) $uploaded_batch_event['args'][1], (int) $uploaded_batch_event['args'][2] );
$uploaded_finalize_event = rp_shift_event( RussianPostPickupImporter::FINALIZE_HOOK );
$uploaded_final = $importer->run_import_finalize( (string) $uploaded_finalize_event['args'][0], (string) $uploaded_finalize_event['args'][1] );
$uploaded_state = $state_service->current();
rp_pickup_assert( ! empty( $uploaded_batch['success'] ) && ! empty( $uploaded_final['success'] ) && 'success' === (string) $uploaded_state['status'] && 3 === count( $GLOBALS['wpdb']->tables[ $main ] ), 'Uploaded ZIP batch/staging/swap pipeline must work.' );
$main_after_success = $GLOBALS['wpdb']->tables[ $main ];

delete_transient( 'wdc_russian_post_pickup_import_lock' );
$cancel_uploaded_zip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-cancel-' . uniqid() . '.zip';
rp_write_passport_zip( $cancel_uploaded_zip );
rp_pickup_assert( $importer->queue_background_import_from_zip( $cancel_uploaded_zip, 'ALL', 'cancel.zip' ), 'Uploaded ZIP cancel test must queue.' );
$importer->reset_stale_or_running_import();
rp_pickup_assert( ! file_exists( $cancel_uploaded_zip ) && false === get_transient( 'wdc_russian_post_pickup_import_lock' ), 'Cancel/reset must delete queued uploaded ZIP and unlock.' );
$GLOBALS['wdc_scheduled_events'] = array();

$admin_reflection = new ReflectionClass( DeliveryServicesAdminPage::class );
$admin_page = $admin_reflection->newInstanceWithoutConstructor();
foreach ( array( 'pickup_importer' => $importer, 'pickup_import_state' => $state_service, 'otpravka_settings' => $settings ) as $property => $value ) {
	$ref_property = $admin_reflection->getProperty( $property );
	$ref_property->setAccessible( true );
	$ref_property->setValue( $admin_page, $value );
}
$upload_handler = $admin_reflection->getMethod( 'handle_russian_post_pickup_zip_upload' );
$upload_handler->setAccessible( true );

$upload_tmp_locked = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-admin-locked-' . uniqid() . '.zip';
rp_write_passport_zip( $upload_tmp_locked );
set_transient( 'wdc_russian_post_pickup_import_lock', 1, 3600 );
$_FILES['russian_post_pickup_zip'] = array( 'name' => 'locked.zip', 'tmp_name' => $upload_tmp_locked, 'error' => UPLOAD_ERR_OK );
$upload_handler->invoke( $admin_page );
$locked_upload_state = $state_service->current();
$locked_target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-uploads' . DIRECTORY_SEPARATOR . 'wdc-imports' . DIRECTORY_SEPARATOR . 'locked.zip';
rp_pickup_assert( ! file_exists( $upload_tmp_locked ) && ! file_exists( $locked_target ) && 'failed' === (string) $locked_upload_state['status'] && str_contains( implode( ' ', $locked_upload_state['errors'] ), 'Unable to queue ZIP import. Another import may be running.' ), 'Active lock + admin ZIP upload must delete stored file and save failed state.' );
delete_transient( 'wdc_russian_post_pickup_import_lock' );

$upload_tmp_success = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-admin-success-' . uniqid() . '.zip';
rp_write_passport_zip( $upload_tmp_success );
$_FILES['russian_post_pickup_zip'] = array( 'name' => 'success.zip', 'tmp_name' => $upload_tmp_success, 'error' => UPLOAD_ERR_OK );
$upload_handler->invoke( $admin_page );
$success_upload_state = $state_service->current();
rp_pickup_assert( 'queued' === (string) $success_upload_state['status'] && 'uploaded_zip' === (string) $success_upload_state['source'] && is_file( (string) $success_upload_state['temp_zip_file'] ), 'Successful admin ZIP upload must keep file for init job.' );
$importer->reset_stale_or_running_import();
$GLOBALS['wdc_scheduled_events'] = array();
unset( $_FILES['russian_post_pickup_zip'] );

delete_transient( 'wdc_russian_post_pickup_import_lock' );
$invalid_uploaded_zip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-invalid-' . uniqid() . '.zip';
file_put_contents( $invalid_uploaded_zip, 'not a zip' );
rp_pickup_assert( $importer->queue_background_import_from_zip( $invalid_uploaded_zip, 'ALL', 'invalid.zip' ), 'Invalid uploaded ZIP must still queue so init can fail cleanly.' );
$invalid_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$invalid_result = $importer->run_import_init( (string) $invalid_event['args'][0], (string) $invalid_event['args'][1] );
$invalid_state = $state_service->current();
rp_pickup_assert( empty( $invalid_result['success'] ) && 'failed' === (string) $invalid_state['status'] && ! file_exists( $invalid_uploaded_zip ) && str_contains( implode( ' ', $invalid_state['errors'] ), 'Unable to open ZIP archive' ) && '' !== (string) $invalid_state['extract_error'], 'Invalid uploaded ZIP must fail state with open diagnostic and delete uploaded file.' );

delete_transient( 'wdc_russian_post_pickup_import_lock' );
$GLOBALS['wdc_scheduled_events'] = array();
$unavailable_zip = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-nozip-' . uniqid() . '.zip';
rp_write_passport_zip( $unavailable_zip );
$GLOBALS['wdc_rp_force_ziparchive_unavailable'] = true;
rp_pickup_assert( $importer->queue_background_import_from_zip( $unavailable_zip, 'ALL', 'nozip.zip' ), 'ZipArchive unavailable test must queue.' );
$unavailable_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$unavailable_result = $importer->run_import_init( (string) $unavailable_event['args'][0], (string) $unavailable_event['args'][1] );
$unavailable_state = $state_service->current();
unset( $GLOBALS['wdc_rp_force_ziparchive_unavailable'] );
rp_pickup_assert( empty( $unavailable_result['success'] ) && 'failed' === (string) $unavailable_state['status'] && empty( $unavailable_state['ziparchive_available'] ) && str_contains( implode( ' ', $unavailable_state['errors'] ), 'PHP ZipArchive extension is not available.' ) && false === get_transient( 'wdc_russian_post_pickup_import_lock' ) && ! file_exists( $unavailable_zip ) && ! array_key_exists( (string) $unavailable_state['staging_table'], $GLOBALS['wpdb']->tables ), 'ZipArchive unavailable must fail state, clear lock, and cleanup.' );

$backup_for_direct_swap = $repo->backup_table( 'direct-swap' );
$staging_for_direct_swap = $repo->staging_table( 'direct-swap' );
$successful_staging = $repo->staging_table( 'direct-success' );
$successful_backup = $repo->backup_table( 'direct-success' );
$GLOBALS['wpdb']->tables[ $successful_staging ] = array( array( 'id' => 99, 'point_code' => 'swap-ok' ) );
$swap_ok = $repo->swap_staging_to_main( $successful_staging, $successful_backup );
rp_pickup_assert( ! empty( $swap_ok['success'] ) && array_key_exists( $main, $GLOBALS['wpdb']->tables ) && ! array_key_exists( $successful_backup, $GLOBALS['wpdb']->tables ), 'Successful swap must promote staging to main and delete backup.' );
$GLOBALS['wpdb']->tables[ $main ] = $main_after_success;
$missing_swap = $repo->swap_staging_to_main( $staging_for_direct_swap, $backup_for_direct_swap );
rp_pickup_assert( empty( $missing_swap['success'] ) && $main_after_success === $GLOBALS['wpdb']->tables[ $main ], 'Missing staging swap must fail without changing main.' );
$GLOBALS['wpdb']->tables[ $staging_for_direct_swap ] = array( array( 'id' => 100, 'point_code' => 'staging' ) );
$GLOBALS['wpdb']->rename_mode = 'partial_swap_recover';
$recovered_swap = $repo->swap_staging_to_main( $staging_for_direct_swap, $backup_for_direct_swap );
rp_pickup_assert( empty( $recovered_swap['success'] ) && ! empty( $recovered_swap['recovered'] ) && $main_after_success === $GLOBALS['wpdb']->tables[ $main ], 'Failed partial swap must recover main from backup.' );
$GLOBALS['wpdb']->tables[ $staging_for_direct_swap ] = array( array( 'id' => 101, 'point_code' => 'staging' ) );
$GLOBALS['wpdb']->rename_mode = 'partial_swap_recovery_fails';
$failed_recovery_swap = $repo->swap_staging_to_main( $staging_for_direct_swap, $backup_for_direct_swap );
rp_pickup_assert( empty( $failed_recovery_swap['success'] ) && array_key_exists( $backup_for_direct_swap, $GLOBALS['wpdb']->tables ) && str_contains( (string) $failed_recovery_swap['message'], 'backup recovery failed' ), 'Failed recovery must keep backup and return a useful error.' );
$GLOBALS['wpdb']->rename_mode = '';
$GLOBALS['wpdb']->tables[ $main ] = $main_after_success;
unset( $GLOBALS['wpdb']->tables[ $backup_for_direct_swap ], $GLOBALS['wpdb']->tables[ $staging_for_direct_swap ] );

$importer_recovery_id = 'importer-recover';
$importer_recovery_staging = $repo->staging_table( $importer_recovery_id );
$importer_recovery_backup = $repo->backup_table( $importer_recovery_id );
$GLOBALS['wpdb']->tables[ $importer_recovery_staging ] = array( array( 'id' => 102, 'point_code' => 'staging' ) );
$state_service->start( 'ALL', $importer_recovery_id );
$state_service->update( 'deactivate', array( 'staging_table' => $importer_recovery_staging, 'main_table' => $main, 'backup_table' => $importer_recovery_backup ) );
$GLOBALS['wpdb']->rename_mode = 'partial_swap_recover';
$recovered_final = $importer->run_import_finalize( $importer_recovery_id, 'ALL' );
$recovered_final_state = $state_service->current();
rp_pickup_assert( empty( $recovered_final['success'] ) && 'failed' === $recovered_final_state['status'] && str_contains( implode( ' ', $recovered_final_state['errors'] ), 'recovered from backup' ) && $main_after_success === $GLOBALS['wpdb']->tables[ $main ], 'Importer finalize must store recovered swap failure message and keep main restored.' );
$GLOBALS['wpdb']->rename_mode = '';
unset( $GLOBALS['wpdb']->tables[ $importer_recovery_staging ], $GLOBALS['wpdb']->tables[ $importer_recovery_backup ] );

$analyze_warning_id = 'analyze-warning';
$analyze_staging = $repo->staging_table( $analyze_warning_id );
$analyze_backup = $repo->backup_table( $analyze_warning_id );
$GLOBALS['wpdb']->tables[ $analyze_staging ] = $main_after_success;
$state_service->start( 'ALL', $analyze_warning_id );
$state_service->update( 'deactivate', array( 'staging_table' => $analyze_staging, 'main_table' => $main, 'backup_table' => $analyze_backup ) );
$GLOBALS['wpdb']->fail_analyze = true;
$analyze_final = $importer->run_import_finalize( $analyze_warning_id, 'ALL' );
$GLOBALS['wpdb']->fail_analyze = false;
rp_pickup_assert( ! empty( $analyze_final['success'] ) && str_contains( implode( ' ', $analyze_final['errors'] ), 'ANALYZE TABLE' ), 'Failed ANALYZE must keep import successful and store warning.' );
$main_after_success = $GLOBALS['wpdb']->tables[ $main ];

$GLOBALS['rp_http_mode'] = 'wp_error';
delete_transient( 'wdc_russian_post_pickup_import_lock' );
rp_pickup_assert( $importer->queue_background_import( 'ALL' ), 'Failed import test must queue.' );
$failed_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$failed = $importer->run_import_init( (string) $failed_event['args'][0], (string) $failed_event['args'][1] );
rp_pickup_assert( empty( $failed['success'] ) && $main_after_success === $GLOBALS['wpdb']->tables[ $main ], 'Failed import must not touch main table.' );
$failed_state = $state_service->current();
rp_pickup_assert( 'failed' === $failed_state['status'] && false === get_transient( 'wdc_russian_post_pickup_import_lock' ) && str_contains( (string) $failed_state['download_error'], 'cURL error 28' ), 'WP_Error download failure must fail state, clear lock, and store message.' );
$GLOBALS['rp_http_mode'] = '';

$GLOBALS['rp_http_mode'] = 'http_500';
delete_transient( 'wdc_russian_post_pickup_import_lock' );
rp_pickup_assert( $importer->queue_background_import( 'ALL' ), 'HTTP failure test must queue.' );
$http_failed_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$http_failed = $importer->run_import_init( (string) $http_failed_event['args'][0], (string) $http_failed_event['args'][1] );
$http_failed_state = $state_service->current();
rp_pickup_assert( empty( $http_failed['success'] ) && 500 === (int) $http_failed_state['download_http_code'] && str_contains( implode( ' ', $http_failed_state['errors'] ), 'Internal Server Error' ) && str_contains( implode( ' ', $http_failed_state['errors'] ), 'temporary failure body' ), 'HTTP download failure must store code/message/body excerpt.' );
$GLOBALS['rp_http_mode'] = '';

$fresh_state = array_merge( $state_service->defaults(), array( 'status' => 'running', 'stage' => 'download', 'last_activity_at' => date( 'Y-m-d H:i:s' ), 'errors' => array() ) );
update_option( RussianPostPickupImportStateService::OPTION_NAME, $fresh_state, false );
set_transient( 'wdc_russian_post_pickup_import_lock', 1, 3600 );
$fresh_result = $importer->refresh_state_for_status();
rp_pickup_assert( 'running' === (string) $fresh_result['status'] && false !== get_transient( 'wdc_russian_post_pickup_import_lock' ), 'Fresh running/download status refresh must not reset import.' );

$stale_zip = tempnam( sys_get_temp_dir(), 'wdc-stale-zip-' );
$stale_payload = tempnam( sys_get_temp_dir(), 'wdc-stale-payload-' );
$stale_staging = $repo->staging_table( 'stale-download' );
$GLOBALS['wpdb']->tables[ $stale_staging ] = array( array( 'id' => 1 ) );
$stale_state = array_merge( $state_service->defaults(), array( 'status' => 'running', 'stage' => 'download', 'last_activity_at' => date( 'Y-m-d H:i:s', time() - 601 ), 'temp_zip_file' => $stale_zip, 'payload_file' => $stale_payload, 'staging_table' => $stale_staging, 'errors' => array() ) );
update_option( RussianPostPickupImportStateService::OPTION_NAME, $stale_state, false );
set_transient( 'wdc_russian_post_pickup_import_lock', 1, 3600 );
$stale_result = $importer->refresh_state_for_status();
rp_pickup_assert( 'failed' === (string) $stale_result['status'] && str_contains( implode( ' ', $stale_result['errors'] ), 'Download stage timed out/stale.' ) && false === get_transient( 'wdc_russian_post_pickup_import_lock' ) && ! file_exists( (string) $stale_zip ) && ! file_exists( (string) $stale_payload ) && ! array_key_exists( $stale_staging, $GLOBALS['wpdb']->tables ), 'Status refresh must fail stale download, unlock, and cleanup files/staging.' );

$stale_extract_zip = tempnam( sys_get_temp_dir(), 'wdc-stale-extract-zip-' );
$stale_extract_payload = tempnam( sys_get_temp_dir(), 'wdc-stale-extract-payload-' );
$stale_extract_staging = $repo->staging_table( 'stale-extract' );
$GLOBALS['wpdb']->tables[ $stale_extract_staging ] = array( array( 'id' => 2 ) );
$stale_extract_state = array_merge( $state_service->defaults(), array( 'status' => 'running', 'stage' => 'extract', 'last_activity_at' => date( 'Y-m-d H:i:s', time() - 601 ), 'temp_zip_file' => $stale_extract_zip, 'payload_file' => $stale_extract_payload, 'staging_table' => $stale_extract_staging, 'errors' => array() ) );
update_option( RussianPostPickupImportStateService::OPTION_NAME, $stale_extract_state, false );
set_transient( 'wdc_russian_post_pickup_import_lock', 1, 3600 );
$stale_extract_result = $importer->refresh_state_for_status();
rp_pickup_assert( 'failed' === (string) $stale_extract_result['status'] && str_contains( implode( ' ', $stale_extract_result['errors'] ), 'Extract stage timed out/stale.' ) && false === get_transient( 'wdc_russian_post_pickup_import_lock' ) && ! file_exists( (string) $stale_extract_zip ) && ! file_exists( (string) $stale_extract_payload ) && ! array_key_exists( $stale_extract_staging, $GLOBALS['wpdb']->tables ), 'Status refresh must fail stale extract, unlock, and cleanup files/staging.' );
$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
rp_pickup_assert( str_contains( $admin_source, 'refresh_state_for_status()' ), 'Status AJAX handler must refresh stale state before responding.' );

delete_transient( 'wdc_russian_post_pickup_import_lock' );
rp_pickup_assert( $importer->queue_background_import( 'ALL' ), 'Cancel test must queue.' );
$cancel_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$importer->run_import_init( (string) $cancel_event['args'][0], (string) $cancel_event['args'][1] );
$cancel_state = $state_service->current();
rp_pickup_assert( array_key_exists( $cancel_state['staging_table'], $GLOBALS['wpdb']->tables ), 'Cancel test must have staging table before reset.' );
$importer->reset_stale_or_running_import();
rp_pickup_assert( ! array_key_exists( $cancel_state['staging_table'], $GLOBALS['wpdb']->tables ) && false === get_transient( 'wdc_russian_post_pickup_import_lock' ), 'Cancel/reset must drop staging and unlock.' );

$settings->save_from_admin( array( 'russian_post_otpravka_login' => 'login', 'russian_post_pickup_schedule_enabled' => '1' ) );
$importer->sync_schedule();
rp_pickup_assert( 'weekly' === ( $GLOBALS['wdc_recurring_events'][ RussianPostPickupImporter::SCHEDULE_HOOK ]['recurrence'] ?? '' ), 'Schedule enabled must create weekly import.' );
$settings->save_from_admin( array( 'russian_post_otpravka_login' => 'login' ) );
$importer->sync_schedule();
rp_pickup_assert( ! isset( $GLOBALS['wdc_recurring_events'][ RussianPostPickupImporter::SCHEDULE_HOOK ] ), 'Schedule disabled must clear weekly import.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
rp_pickup_assert( str_contains( $admin_source, 'rows_inserted_to_staging' ) && str_contains( $admin_source, 'staging_table' ) && str_contains( $admin_source, 'upload_russian_post_pickup_zip_import' ) && str_contains( $admin_source, 'ВАШ_ACCESS_TOKEN' ), 'Admin status output must include staging metrics and manual ZIP upload instructions.' );

echo "Russian Post pickup import smoke test passed.\n";
