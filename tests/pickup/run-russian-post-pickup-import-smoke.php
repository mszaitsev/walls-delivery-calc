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

function current_time( string $type ): string { return '2026-05-27 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $value ) ) ?? ''; }
function wp_unslash( mixed $value ): mixed { return $value; }
function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
function wp_tempnam( string $filename = '' ): string { return tempnam( sys_get_temp_dir(), 'wdc-rp-' ) ?: ''; }
function wp_delete_file( string $file ): bool { $GLOBALS['wdc_deleted_files'][] = $file; return @unlink( $file ); }
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		public function __construct( private string $message ) {}
		public function get_error_message(): string { return $this->message; }
	}
}
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_response_message( array $response ): string { return (string) ( $response['response']['message'] ?? '' ); }
function wp_remote_retrieve_body( array $response ): string { return (string) ( $response['body'] ?? '' ); }

$GLOBALS['wdc_options'] = array();
$GLOBALS['wdc_transients'] = array();
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool $autoload = true ): bool { $GLOBALS['wdc_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_options'][ $key ] ); return true; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['wdc_transients'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_transients'][ $key ] ?? false; }
function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_transients'][ $key ] ); return true; }
function wp_schedule_single_event( int $timestamp, string $hook, array $args = array() ): bool {
	if ( ! empty( $GLOBALS['wdc_force_schedule_failure'] ) ) {
		return false;
	}
	$GLOBALS['wdc_scheduled_events'][] = array( 'timestamp' => $timestamp, 'hook' => $hook, 'args' => $args );
	return true;
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $pickup_rows = array();
		public bool $table_exists = true;
		/** @var array<string,bool> */
		public array $columns = array();
		/** @var array<string,bool> */
		public array $indexes = array();

		public function __construct() {
			foreach ( array( 'id', 'carrier_key', 'point_code', 'point_type', 'country_code', 'region_name', 'city_name', 'address', 'postcode', 'latitude', 'longitude', 'work_time', 'comment', 'extra_cost_kopecks', 'active', 'raw_reference', 'updated_at', 'created_at' ) as $column ) {
				$this->columns[ $column ] = true;
			}
		}
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
		public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $value, $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function insert( string $table, array $data, array $format = array() ): bool {
			$data['id'] = ++$this->insert_id;
			$this->pickup_rows[] = $data;
			return true;
		}
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			foreach ( $this->pickup_rows as $i => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? 0 ) ) {
					$this->pickup_rows[ $i ] = array_merge( $row, $data );
				}
			}
			return true;
		}
		public function query( string $query ): int|bool {
			if ( preg_match( '/ADD KEY ([a-z_]+)/i', $query, $m ) ) {
				$this->indexes[ $m[1] ] = true;
				return true;
			}
			if ( preg_match( '/ADD ([a-z_]+) /i', $query, $m ) ) {
				$this->columns[ $m[1] ] = true;
				return true;
			}
			if ( str_starts_with( strtoupper( trim( $query ) ), 'UPDATE' ) && str_contains( $query, 'last_seen_at' ) ) {
				$count = 0;
				foreach ( $this->pickup_rows as $i => $row ) {
					if ( (int) ( $row['active'] ?? 0 ) === 1 && ( (string) ( $row['last_seen_at'] ?? '' ) < '2026-05-27 12:00:00' ) ) {
						$this->pickup_rows[ $i ]['active'] = 0;
						++$count;
					}
				}
				return $count;
			}
			return true;
		}
		public function get_var( string $query ): mixed {
			if ( str_contains( $query, 'SHOW TABLES' ) ) {
				return $this->table_exists ? 'wp_wdc_pickup_points' : null;
			}
			if ( preg_match( "/SHOW COLUMNS .* LIKE '([^']+)'/", $query, $m ) ) {
				return ! empty( $this->columns[ $m[1] ] ) ? $m[1] : null;
			}
			if ( preg_match( "/SHOW INDEX .* Key_name = '([^']+)'/", $query, $m ) ) {
				return ! empty( $this->indexes[ $m[1] ] ) ? $m[1] : null;
			}
			if ( str_contains( $query, 'SHOW INDEX' ) ) {
				return null;
			}
			$rows = $this->filter_rows( $query );
			return count( $rows );
		}
		public function get_row( string $query, mixed $output = null ): ?array {
			if ( preg_match( '/WHERE id = ([0-9]+)/', $query, $m ) ) {
				foreach ( $this->pickup_rows as $row ) {
					if ( (int) $row['id'] === (int) $m[1] ) {
						return $row;
					}
				}
			}
			if ( preg_match( "/carrier_key = '([^']+)'.*point_code = '([^']+)'/", $query, $m ) ) {
				foreach ( $this->pickup_rows as $row ) {
					if ( $row['carrier_key'] === $m[1] && $row['point_code'] === $m[2] ) {
						return $row;
					}
				}
			}
			return null;
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
			$rows = $this->pickup_rows;
			if ( str_contains( $query, 'active = 1' ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (int) ( $row['active'] ?? 0 ) === 1 ) );
			}
			if ( preg_match( "/carrier_key = '([^']+)'/", $query, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['carrier_key'] ?? '' ) === $m[1] ) );
			}
			if ( preg_match( "/point_type = '([^']+)'/", $query, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['point_type'] ?? '' ) === $m[1] ) );
			}
			return $rows;
		}
	}
}

$GLOBALS['wpdb'] = new wpdb();

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPassportPointNormalizer;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImportStateService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImporter;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;

$migration = require dirname( __DIR__, 2 ) . '/database/migrations/0021_extend_pickup_points_for_russian_post_passport.php';
$GLOBALS['wpdb']->table_exists = false;
$migration();
rp_pickup_assert( empty( $GLOBALS['wpdb']->indexes ), 'Migration must quietly exit when pickup table is missing.' );
$GLOBALS['wpdb']->table_exists = true;
$migration();
$migration();
foreach ( array( 'source_hash', 'last_seen_at', 'work_time_json', 'accepts_card', 'functionality_checking' ) as $column ) {
	rp_pickup_assert( ! empty( $GLOBALS['wpdb']->columns[ $column ] ), 'Migration must add column ' . $column );
}
foreach ( array( 'idx_carrier_type_active', 'idx_city_active', 'idx_carrier_source_hash' ) as $index ) {
	rp_pickup_assert( ! empty( $GLOBALS['wpdb']->indexes[ $index ] ), 'Migration must add index ' . $index );
}

$normalizer = new RussianPostPassportPointNormalizer();
$item = array(
	'address' => array( 'index' => '630001', 'region' => 'Новосибирская область', 'place' => 'Новосибирск', 'street' => 'Ленина', 'house' => '1' ),
	'addressFias' => array( 'ads' => 'Новосибирск, Ленина, 1', 'locationGarCode' => 'loc', 'addGarCode' => 'addr', 'regGarId' => '54' ),
	'brandName' => 'Почта России',
	'ecomOptions' => array( 'cardPayment' => true, 'cashPayment' => false, 'withFitting' => true, 'returnAvailable' => true, 'contentsChecking' => true, 'functionalityChecking' => false, 'weightLimit' => 10 ),
	'latitude' => 55.1,
	'longitude' => 82.9,
	'type' => 'ПВЗ',
	'workTime' => array( array( 'day' => 'Пн', 'beginWorkTime' => '09:00', 'endWorkTime' => '18:00' ) ),
);
$row = $normalizer->normalize( $item, 'PVZ', '2026-05-27 12:00:00' );
rp_pickup_assert( is_array( $row ) && 'PVZ' === $row['point_type'], 'Normalizer must detect PVZ.' );
rp_pickup_assert( str_starts_with( (string) $row['point_code'], '630001-' ) && '630001' === $row['postcode'], 'Normalizer must keep postcode separate and make point_code postcode-hash.' );
rp_pickup_assert( 'Новосибирск, Ленина, 1' === $row['address'], 'Normalizer must prefer addressFias.ads.' );
rp_pickup_assert( 10000 === $row['weight_limit_grams'] && true === $row['accepts_card'] && false === $row['functionality_checking'], 'Normalizer must map ecom options.' );
rp_pickup_assert( null === $normalizer->normalize( array( 'address' => array() ), 'OPS' ), 'Normalizer must skip points without coordinates.' );
rp_pickup_assert( $row['source_hash'] === $normalizer->normalize( $item, 'PVZ', '2026-05-27 12:05:00' )['source_hash'], 'Source hash must be stable.' );
rp_pickup_assert( 'APS' === $normalizer->normalize( array_merge( $item, array( 'type' => 'Почтомат', 'address' => array( 'index' => '630002' ) ) ), 'ALL' )['point_type'], 'Normalizer must detect APS.' );
rp_pickup_assert( 'OPS' === $normalizer->normalize( array_merge( $item, array( 'type' => 'ОПС', 'address' => array( 'index' => '630003' ) ) ), 'OPS' )['point_type'], 'Normalizer must detect OPS.' );

$same_postcode_other_point = $normalizer->normalize(
	array_merge(
		$item,
		array(
			'address' => array( 'index' => '630001', 'region' => 'Новосибирская область', 'place' => 'Новосибирск', 'street' => 'Советская', 'house' => '2' ),
			'addressFias' => array( 'ads' => 'Новосибирск, Советская, 2' ),
			'latitude' => 55.2,
			'longitude' => 82.8,
		)
	),
	'PVZ',
	'2026-05-27 12:00:00'
);
rp_pickup_assert( is_array( $same_postcode_other_point ) && $row['point_code'] !== $same_postcode_other_point['point_code'], 'Points with one postcode must get different point_code values.' );

$repo = new PickupPointRepository( $GLOBALS['wpdb'] );
$stats = $repo->upsert_passport_batch( 'russian_post', array( $row ) );
rp_pickup_assert( array( 'inserted' => 1, 'updated' => 0, 'skipped' => 0 ) === $stats, 'First upsert must insert.' );
$row['brand_name'] = 'Updated brand';
$stats = $repo->upsert_passport_batch( 'russian_post', array( $row ) );
rp_pickup_assert( 0 === $stats['inserted'] && 1 === $stats['updated'], 'Second upsert must update.' );
rp_pickup_assert( 1 === $repo->count_active( 'russian_post' ) && array( 'PVZ' => 1 ) === $repo->count_by_type( 'russian_post' ), 'Counts must work.' );
rp_pickup_assert( $repo->find_by_id( 1 ) !== null && count( $repo->search_points( 'russian_post', '630001' ) ) === 1, 'Find/search methods must work.' );
$GLOBALS['wpdb']->pickup_rows[0]['last_seen_at'] = '2026-05-26 10:00:00';
rp_pickup_assert( 1 === $repo->mark_missing_inactive( 'russian_post', '2026-05-27 12:00:00' ) && 0 === $repo->count_active( 'russian_post' ), 'Missing points must be marked inactive.' );

$GLOBALS['wpdb']->pickup_rows = array();
$GLOBALS['wpdb']->insert_id = 0;
$same_postcode_stats = $repo->upsert_passport_batch( 'russian_post', array( $row, $same_postcode_other_point ) );
rp_pickup_assert( 2 === $same_postcode_stats['inserted'] && 0 === $same_postcode_stats['updated'] && 2 === count( $GLOBALS['wpdb']->pickup_rows ), 'Two points with one postcode must insert two rows instead of overwriting.' );

$settings = new RussianPostOtpravkaApiSettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin( array( 'russian_post_otpravka_access_token' => 'token', 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_password' => 'password', 'russian_post_pickup_unload_type' => 'ALL' ) );
$encrypted = get_option( 'wdc_core_settings', array() )[ RussianPostOtpravkaApiSettings::PASSWORD_ENCRYPTED_KEY ] ?? '';
$settings->save_from_admin( array( 'russian_post_otpravka_access_token' => '', 'russian_post_otpravka_login' => 'login' ) );
rp_pickup_assert( $encrypted === get_option( 'wdc_core_settings', array() )[ RussianPostOtpravkaApiSettings::PASSWORD_ENCRYPTED_KEY ], 'Empty secret must preserve existing secret.' );
$settings->save_from_admin( array( 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_clear_password' => '1' ) );
rp_pickup_assert( '' === get_option( 'wdc_core_settings', array() )[ RussianPostOtpravkaApiSettings::PASSWORD_ENCRYPTED_KEY ], 'Clear secret must remove saved secret.' );
$settings->save_from_admin( array( 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_timeout' => '5' ) );
rp_pickup_assert( 30 === $settings->timeout(), 'Otpravka timeout must sanitize to minimum 30 seconds.' );
$settings->save_from_admin( array( 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_timeout' => '1200' ) );
rp_pickup_assert( 900 === $settings->timeout(), 'Otpravka timeout must sanitize to maximum 900 seconds.' );
$settings->save_from_admin( array( 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_timeout' => '300' ) );
rp_pickup_assert( 300 === $settings->timeout(), 'Otpravka timeout default target must allow 300 seconds.' );

$state_service = new RussianPostPickupImportStateService();
$queued_state = $state_service->queue( 'PVZ' );
rp_pickup_assert( 'queued' === $queued_state['status'] && 'queued' === $queued_state['stage'] && 'PVZ' === $queued_state['type'], 'State service must save queued state.' );
$running_state = $state_service->start( 'OPS' );
rp_pickup_assert( 'running' === $running_state['status'] && 'download' === $running_state['stage'] && 'OPS' === $running_state['type'], 'State service must save running state.' );
$progress_state = $state_service->update( 'upsert', array( 'parsed' => 5, 'inserted' => 3, 'updated' => 1, 'skipped' => 1, 'errors' => array( 'sample error' ) ) );
rp_pickup_assert( 'running' === $progress_state['status'] && 'upsert' === $progress_state['stage'] && 5 === $progress_state['parsed'] && 3 === $progress_state['inserted'], 'State service must update progress counters.' );
$success_state = $state_service->success( array( 'type' => 'OPS', 'parsed' => 5, 'inserted' => 3, 'updated' => 1, 'skipped' => 1, 'finished_at' => '2026-05-27 12:01:00', 'errors' => array() ) );
rp_pickup_assert( 'success' === $success_state['status'] && 'finished' === $success_state['stage'], 'State service must save success state.' );
$failed_state = $state_service->failed( array( 'type' => 'OPS', 'parsed' => 2, 'finished_at' => '2026-05-27 12:02:00', 'errors' => array( 'failed sample' ) ) );
rp_pickup_assert( 'failed' === $failed_state['status'] && 'failed' === $failed_state['stage'] && array( 'failed sample' ) === $failed_state['errors'], 'State service must save failed state.' );

$settings->save_from_admin( array( 'russian_post_otpravka_access_token' => 'token', 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_password' => 'password', 'russian_post_pickup_unload_type' => 'ALL' ) );
function wp_remote_get( string $url, array $args = array() ): mixed {
	if ( 'wp_error' === ( $GLOBALS['rp_http_mode'] ?? '' ) ) {
		if ( ! empty( $args['stream'] ) && is_string( $args['filename'] ?? null ) ) {
			file_put_contents( $args['filename'], 'partial zip bytes' );
		}
		return new WP_Error( 'cURL error 28: Operation timed out' );
	}
	if ( 'http_error' === ( $GLOBALS['rp_http_mode'] ?? '' ) ) {
		if ( ! empty( $args['stream'] ) && is_string( $args['filename'] ?? null ) ) {
			file_put_contents( $args['filename'], 'partial zip bytes' );
		}
		return array( 'response' => array( 'code' => 504, 'message' => 'Gateway Timeout' ), 'body' => str_repeat( 'timeout-body-', 120 ) );
	}
	$zip_file = tempnam( sys_get_temp_dir(), 'wdc-rp-zip-' );
	$zip = new ZipArchive();
	$zip->open( $zip_file, ZipArchive::OVERWRITE );
	$zip->addFromString( 'passport.txt', (string) $GLOBALS['rp_passport_payload'] );
	$zip->close();
	if ( ! empty( $args['stream'] ) && is_string( $args['filename'] ?? null ) ) {
		copy( $zip_file, $args['filename'] );
		$body = '';
	} else {
		$body = (string) file_get_contents( $zip_file );
	}
	unlink( $zip_file );
	return array( 'response' => array( 'code' => 200 ), 'body' => $body );
}
$stream_items = array();
for ( $i = 0; $i < 260; ++$i ) {
	$stream_items[] = array_merge(
		$item,
		array(
			'address' => array( 'index' => '630001', 'region' => 'Новосибирская область', 'place' => 'Новосибирск', 'street' => 'Стрим', 'house' => (string) $i ),
			'addressFias' => array( 'ads' => 'Новосибирск, Стрим, ' . $i ),
			'latitude' => 55.0 + ( $i / 10000 ),
			'longitude' => 82.0 + ( $i / 10000 ),
			'ecomOptions' => array_merge( $item['ecomOptions'], array( 'getto' => 'nested {braces} and escaped "quotes" #' . $i ) ),
		)
	);
}
$items_json = implode(
	',',
	array_map(
		static fn( array $stream_item ): string => (string) json_encode( $stream_item, JSON_UNESCAPED_UNICODE ),
		$stream_items
	)
);
$GLOBALS['rp_passport_payload'] = '{"passportElements":[' . $items_json . ',{"latitude":},{"address":{"index":"630001"},"latitude":55.9,"longitude":82.9}],"tail":{"ok":true}}';
$GLOBALS['wpdb']->pickup_rows = array();
$GLOBALS['wpdb']->insert_id = 0;
$GLOBALS['wdc_scheduled_events'] = array();
$importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings ), $repo, $normalizer, $state_service );
set_transient( 'wdc_russian_post_pickup_import_lock', 1, 60 );
$locked_result = $importer->import( 'ALL' );
rp_pickup_assert( empty( $locked_result['success'] ) && str_contains( implode( ';', $locked_result['errors'] ), 'already running' ), 'Importer must refuse parallel run.' );
delete_transient( 'wdc_russian_post_pickup_import_lock' );
$result = $importer->import( 'ALL' );
rp_pickup_assert( ! empty( $result['success'] ) && 261 === $result['parsed'] && 261 === $result['inserted'] && $result['skipped'] >= 1, 'Importer must stream ZIP passportElements in batches and skip invalid items.' );
$import_state = $state_service->current();
rp_pickup_assert( 'success' === $import_state['status'] && 'finished' === $import_state['stage'] && 261 === $import_state['parsed'] && 261 === $import_state['inserted'], 'Importer must persist final success state.' );
rp_pickup_assert( count( $GLOBALS['wpdb']->pickup_rows ) === 261, 'Streaming import must preserve distinct same-postcode points across batches.' );
rp_pickup_assert( '' !== $settings->last_success_at(), 'last_success_at must update only after successful import.' );
rp_pickup_assert( count( $GLOBALS['wdc_deleted_files'] ?? array() ) >= 2 && ! is_file( end( $GLOBALS['wdc_deleted_files'] ) ), 'Importer must delete temporary ZIP and extracted payload files after reading.' );
rp_pickup_assert( in_array( 'Invalid passport item JSON: Syntax error', $result['errors'], true ), 'Importer must report invalid item JSON without failing the whole import.' );

$GLOBALS['rp_http_mode'] = 'wp_error';
delete_transient( 'wdc_russian_post_pickup_import_lock' );
$download_failed = $importer->import( 'ALL' );
$download_failed_state = $state_service->current();
rp_pickup_assert( empty( $download_failed['success'] ) && 'failed' === $download_failed_state['status'] && 'failed' === $download_failed_state['stage'], 'Failed download must persist failed state instead of running.' );
rp_pickup_assert( str_contains( implode( ';', $download_failed_state['errors'] ), 'cURL error 28' ) && ! in_array( $download_failed_state['status'], array( 'queued', 'running' ), true ), 'Failed download must store useful WP_Error diagnostics.' );
$GLOBALS['rp_http_mode'] = 'http_error';
delete_transient( 'wdc_russian_post_pickup_import_lock' );
$http_failed = $importer->import( 'ALL' );
rp_pickup_assert( empty( $http_failed['success'] ) && str_contains( implode( ';', $http_failed['errors'] ), 'Gateway Timeout' ) && str_contains( implode( ';', $http_failed['errors'] ), 'Body excerpt:' ) && str_contains( implode( ';', $http_failed['errors'] ), 'Temp file size:' ), 'HTTP failed download must store response message, body excerpt, and temp file size.' );
$GLOBALS['rp_http_mode'] = '';

delete_transient( 'wdc_russian_post_pickup_import_lock' );
$GLOBALS['wdc_force_schedule_failure'] = true;
$failed_queue = $importer->queue_background_import( 'PVZ' );
$failed_queue_state = $state_service->current();
rp_pickup_assert( ! $failed_queue && 'failed' === $failed_queue_state['status'] && 'failed' === $failed_queue_state['stage'] && 'PVZ' === $failed_queue_state['type'], 'Failed background scheduling must persist failed state instead of queued.' );
rp_pickup_assert( in_array( 'Unable to schedule background import job.', $failed_queue_state['errors'], true ), 'Failed background scheduling must store a readable error.' );
rp_pickup_assert( 0 === count( $GLOBALS['wdc_scheduled_events'] ), 'Failed background scheduling must not record a scheduled job.' );
$GLOBALS['wdc_force_schedule_failure'] = false;
$queued = $importer->queue_background_import( 'APS' );
rp_pickup_assert( $queued && 'queued' === $state_service->current()['status'] && 'APS' === $state_service->current()['type'], 'Background import must queue persistent state.' );
rp_pickup_assert( 1 === count( $GLOBALS['wdc_scheduled_events'] ) && RussianPostPickupImporter::SCHEDULE_HOOK === $GLOBALS['wdc_scheduled_events'][0]['hook'] && array( 'APS' ) === $GLOBALS['wdc_scheduled_events'][0]['args'], 'Background import must schedule a single event with type.' );
rp_pickup_assert( ! $importer->queue_background_import( 'OPS' ), 'Background import must refuse duplicate queued jobs.' );
update_option( RussianPostPickupImportStateService::OPTION_NAME, array_merge( $state_service->defaults(), array( 'status' => 'running', 'stage' => 'download', 'last_activity_at' => '2000-01-01 00:00:00', 'type' => 'ALL' ) ), false );
set_transient( 'wdc_russian_post_pickup_import_lock', 1, 60 );
rp_pickup_assert( ! $importer->is_locked() && false === get_transient( 'wdc_russian_post_pickup_import_lock' ) && 'failed' === $state_service->current()['status'] && in_array( 'Download stage timed out/stale.', $state_service->current()['errors'], true ), 'Stale download older than 15 minutes must fail state and clear lock.' );
update_option( RussianPostPickupImportStateService::OPTION_NAME, array_merge( $state_service->defaults(), array( 'status' => 'running', 'stage' => 'parse', 'last_activity_at' => '2000-01-01 00:00:00', 'type' => 'ALL' ) ), false );
set_transient( 'wdc_russian_post_pickup_import_lock', 1, 60 );
rp_pickup_assert( ! $importer->is_locked() && false === get_transient( 'wdc_russian_post_pickup_import_lock' ) && 'failed' === $state_service->current()['status'], 'Stale lock recovery must clear old locks and mark state failed.' );
update_option( RussianPostPickupImportStateService::OPTION_NAME, array_merge( $state_service->defaults(), array( 'status' => 'running', 'stage' => 'upsert', 'last_activity_at' => '2026-05-27 12:00:00', 'type' => 'ALL' ) ), false );
set_transient( 'wdc_russian_post_pickup_import_lock', 1, 60 );
$cancelled_state = $importer->reset_stale_or_running_import();
rp_pickup_assert( false === get_transient( 'wdc_russian_post_pickup_import_lock' ) && 'failed' === $cancelled_state['status'] && in_array( 'Import was manually cancelled/reset by admin.', $cancelled_state['errors'], true ), 'Manual cancel/reset must fail state and clear lock without deleting points.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
rp_pickup_assert( str_contains( $admin_source, 'russian_post_otpravka_access_token" value=""' ) && str_contains( $admin_source, 'has_access_token()' ), 'Admin must not render AccessToken back into HTML.' );
rp_pickup_assert( str_contains( $admin_source, 'russian_post_otpravka_password" value=""' ) && str_contains( $admin_source, 'has_password()' ), 'Admin must not render password back into HTML.' );
rp_pickup_assert( str_contains( $admin_source, 'queue_background_import' ) && ! str_contains( $admin_source, '->import( $this->otpravka_settings->unload_type() )' ), 'Admin action must queue import instead of running importer synchronously.' );
rp_pickup_assert( str_contains( $admin_source, 'reset_russian_post_pickup_import' ) && str_contains( $admin_source, 'reset_stale_or_running_import' ), 'Admin must expose manual cancel/reset action.' );
rp_pickup_assert( str_contains( $admin_source, 'wp_ajax_wdc_russian_post_pickup_import_status' ) && str_contains( $admin_source, 'ajax_pickup_import_status' ) && str_contains( $admin_source, 'wp_send_json_success' ), 'Admin must expose AJAX status endpoint.' );
rp_pickup_assert( str_contains( $admin_source, 'disabled( $is_busy )' ), 'Admin run button must be disabled while queued/running.' );
$admin_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/russian-post-pickup-import.js' );
rp_pickup_assert( str_contains( $admin_js, 'setInterval(requestStatus, 3000)' ) && str_contains( $admin_js, 'wdc_russian_post_pickup_import_status' ), 'Admin JS must poll import status every 3 seconds.' );

echo "Russian Post pickup import smoke test passed.\n";
