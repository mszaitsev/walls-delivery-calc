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
function wp_unslash( mixed $value ): mixed { return $value; }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function wp_tempnam( string $filename = '' ): string { return tempnam( sys_get_temp_dir(), 'wdc-rp-' ) ?: ''; }
function wp_delete_file( string $file ): bool { $GLOBALS['wdc_deleted_files'][] = $file; return @unlink( $file ); }
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
$state_service = new RussianPostPickupImportStateService();

function wp_remote_get( string $url, array $args = array() ): mixed {
	if ( 'wp_error' === ( $GLOBALS['rp_http_mode'] ?? '' ) ) {
		return new WP_Error( 'cURL error 28: Operation timed out' );
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

$main = $repo->main_table();
$normalized_base = $normalizer->normalize( $base_item, 'PVZ', '2026-05-28 10:00:00' );
rp_pickup_assert( is_array( $normalized_base ) && "Пн–Пт: 08:00–17:00\nПерерыв: 12:00–13:00\nСб–Вс: выходной" === $normalized_base['work_time'], 'Normalizer must store compact work_time.' );
rp_pickup_assert( ! array_key_exists( 'raw_reference', $normalized_base ) && ! array_key_exists( 'work_time_json', $normalized_base ), 'Normalizer must not output raw_reference or work_time_json.' );
$repo->insert_batch( array( $normalized_base ), $main );
$main_before = count( $GLOBALS['wpdb']->tables[ $main ] );
$importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings ), $repo, $normalizer, $state_service );
rp_pickup_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/RussianPost/RussianPostPickupImporter.php' ), 'private const BATCH_SIZE = 500' ), 'Importer batch size must be 500.' );
rp_pickup_assert( $importer->queue_background_import( 'ALL' ), 'Import must queue init job.' );
$init_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$init = $importer->run_import_init( (string) $init_event['args'][0], (string) $init_event['args'][1] );
$state = $state_service->current();
rp_pickup_assert( ! empty( $init['success'] ) && '' !== $state['staging_table'] && array_key_exists( $state['staging_table'], $GLOBALS['wpdb']->tables ), 'Init must create staging table.' );
rp_pickup_assert( $main_before === count( $GLOBALS['wpdb']->tables[ $main ] ), 'Main table must not change during init.' );

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
$GLOBALS['rp_http_mode'] = '';

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
rp_pickup_assert( str_contains( $admin_source, 'rows_inserted_to_staging' ) && str_contains( $admin_source, 'staging_table' ), 'Admin status output must include staging metrics.' );

echo "Russian Post pickup import smoke test passed.\n";
