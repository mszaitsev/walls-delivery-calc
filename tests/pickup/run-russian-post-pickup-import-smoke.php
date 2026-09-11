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
function maybe_serialize( mixed $value ): mixed { return is_array( $value ) || is_object( $value ) ? serialize( $value ) : $value; }
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
function current_user_can( string $capability ): bool { return true; }
function check_ajax_referer( string $action, string|bool $query_arg = false, bool $stop = true ): int|false { return 1; }
function wp_send_json_success( mixed $data = null, ?int $status_code = null, int $flags = 0 ): never { $GLOBALS['wdc_json_response'] = $data; throw new RuntimeException( 'wdc-json-response' ); }
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
function add_option( string $key, mixed $value, string $deprecated = '', string|bool $autoload = 'yes' ): bool { if ( array_key_exists( $key, $GLOBALS['wdc_options'] ) ) { return false; } $GLOBALS['wdc_options'][ $key ] = $value; return true; }
function update_option( string $key, mixed $value, bool $autoload = true ): bool { $GLOBALS['wdc_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_options'][ $key ] ); return true; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['wdc_transients'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_transients'][ $key ] ?? false; }
function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_transients'][ $key ] ); return true; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public string $options = 'wp_options';
		public string $last_error = '';
		public int $insert_id = 0;
		/** @var array<int,mixed> */
		private array $prepared_args = array();
		/** @var array<string,array<int,array<string,mixed>>> */
		public array $tables = array();
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<int,string> */
		public array $analyzed_tables = array();
		public string $rename_mode = '';
		public bool $fail_analyze = false;

		public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
		public function prepare( string $query, mixed ...$args ): string {
			$this->prepared_args = $args;
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $value, $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function query( string $query ): int|bool {
			if ( preg_match( '/^INSERT INTO ([A-Za-z0-9_]+) \(([^)]+)\) VALUES (.+) ON DUPLICATE KEY UPDATE/s', trim( $query ), $insert_match ) ) {
				$table = $insert_match[1];
				$columns = array_map( 'trim', explode( ',', $insert_match[2] ) );
				preg_match_all( "/'(?:''|[^'])*'|-?[0-9]+(?:\\.[0-9]+)?|NULL/", $insert_match[3], $value_matches );
				$values = array_map(
					static function ( string $value ): mixed {
						if ( 'NULL' === $value ) {
							return null;
						}
						if ( str_starts_with( $value, "'" ) ) {
							return str_replace( "''", "'", substr( $value, 1, -1 ) );
						}

						return str_contains( $value, '.' ) ? (float) $value : (int) $value;
					},
					$value_matches[0]
				);
				$inserted = 0;
				foreach ( array_chunk( $values, count( $columns ) ) as $cells ) {
					if ( count( $cells ) !== count( $columns ) ) {
						continue;
					}
					$row = array_combine( $columns, $cells );
					$duplicate = array_filter( $this->tables[ $table ] ?? array(), static fn( array $existing ): bool => (string) ( $existing['point_code'] ?? '' ) === (string) ( $row['point_code'] ?? '' ) );
					if ( array() !== $duplicate ) {
						continue;
					}
					$row['id'] = ++$this->insert_id;
					$this->tables[ $table ][] = $row;
					++$inserted;
				}

				return $inserted;
			}
			if ( str_starts_with( trim( $query ), 'UPDATE wp_options SET option_value =' ) ) {
				$replacement = (string) ( $this->prepared_args[0] ?? '' );
				$key = (string) ( $this->prepared_args[1] ?? '' );
				$expected = (string) ( $this->prepared_args[2] ?? '' );
				if ( is_callable( $GLOBALS['wdc_before_option_cas'] ?? null ) ) {
					$before_option_cas = $GLOBALS['wdc_before_option_cas'];
					unset( $GLOBALS['wdc_before_option_cas'] );
					$before_option_cas( $key );
				}
				if ( ! array_key_exists( $key, $GLOBALS['wdc_options'] ) || (string) maybe_serialize( $GLOBALS['wdc_options'][ $key ] ) !== $expected ) {
					return 0;
				}
				$GLOBALS['wdc_options'][ $key ] = unserialize( $replacement, array( 'allowed_classes' => false ) );
				return 1;
			}
			if ( str_starts_with( trim( $query ), 'DELETE FROM wp_options WHERE option_name =' ) ) {
				$key = (string) ( $this->prepared_args[0] ?? '' );
				$expected = (string) ( $this->prepared_args[1] ?? '' );
				if ( is_callable( $GLOBALS['wdc_before_option_delete_cas'] ?? null ) ) {
					$before_option_delete_cas = $GLOBALS['wdc_before_option_delete_cas'];
					unset( $GLOBALS['wdc_before_option_delete_cas'] );
					$before_option_delete_cas( $key );
				}
				if ( ! array_key_exists( $key, $GLOBALS['wdc_options'] ) || (string) maybe_serialize( $GLOBALS['wdc_options'][ $key ] ) !== $expected ) {
					return 0;
				}
				unset( $GLOBALS['wdc_options'][ $key ] );
				return 1;
			}
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
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			$this->tables[ $table ] ??= array();
			foreach ( $this->tables[ $table ] as &$row ) {
				foreach ( $where as $key => $value ) {
					if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
						continue 2;
					}
				}
				$row = array_merge( $row, $data );
			}
			unset( $row );
			return true;
		}
		public function delete( string $table, array $where, array $where_format = array() ): bool {
			$this->tables[ $table ] = array_values(
				array_filter(
					$this->tables[ $table ] ?? array(),
					static function ( array $row ) use ( $where ): bool {
						foreach ( $where as $key => $value ) {
							if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
								return true;
							}
						}
						return false;
					}
				)
			);
			return true;
		}
		public function get_var( string $query ): mixed {
			if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $m ) ) {
				return array_key_exists( $m[1], $this->tables ) ? $m[1] : null;
			}
			if ( preg_match( '/SELECT id FROM ([A-Za-z0-9_]+) WHERE service_id = ([0-9]+) AND setting_key = \'([^\']+)\'/', $query, $m ) ) {
				foreach ( $this->tables[ $m[1] ] ?? array() as $row ) {
					if ( (int) ( $row['service_id'] ?? 0 ) === (int) $m[2] && (string) ( $row['setting_key'] ?? '' ) === $m[3] ) {
						return $row['id'] ?? null;
					}
				}
				return null;
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
			if ( preg_match( "/service_key = '([^']+)'/", $query, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['service_key'] ?? '' ) === $m[1] ) );
			}
			if ( preg_match( '/deleted = ([0-9]+)/', $query, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (int) ( $row['deleted'] ?? 0 ) === (int) $m[1] ) );
			}
			if ( preg_match( '/service_id = ([0-9]+)/', $query, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (int) ( $row['service_id'] ?? 0 ) === (int) $m[1] ) );
			}
			if ( preg_match( "/setting_key = '([^']+)'/", $query, $m ) ) {
				$rows = array_values( array_filter( $rows, static fn( array $row ): bool => (string) ( $row['setting_key'] ?? '' ) === $m[1] ) );
			}
			return $rows;
		}
	}
}

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPassportPointNormalizer;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImportLock;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImporter;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupSchedule;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImportStateService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupLocationResolver;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostWorkTimeFormatter;

class RpControlledExecutionBudget extends BackgroundExecutionBudget {
	/** @var callable|null */
	private $after_mark;

	/** @var callable|null */
	private $before_continue;

	public function __construct( int $max_units, ?callable $after_mark = null, ?callable $before_continue = null ) {
		$this->after_mark = $after_mark;
		$this->before_continue = $before_continue;
		parent::__construct( 999.0, $max_units, null );
	}

	public function can_continue(): bool {
		if ( is_callable( $this->before_continue ) ) {
			( $this->before_continue )( $this->units_processed() );
		}

		return parent::can_continue();
	}

	public function mark_unit_processed(): void {
		parent::mark_unit_processed();
		if ( is_callable( $this->after_mark ) ) {
			( $this->after_mark )( $this->units_processed() );
		}
	}
}

/** @param array<string,mixed> $base_item */
function rp_worker_payload( array $base_item, int $count, string $prefix ): string {
	$items = array();
	for ( $i = 0; $i < $count; ++$i ) {
		$item = $base_item;
		$item['address'] = array( 'index' => (string) ( 100000 + $i ), 'region' => 'НСО', 'place' => 'Новосибирск', 'street' => $prefix, 'house' => (string) $i );
		$item['addressFias'] = array( 'ads' => 'Новосибирск, ' . $prefix . ', ' . $i, 'locationGarCode' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' );
		$item['latitude'] = 55.0 + ( $i % 100 ) / 1000;
		$item['longitude'] = 82.0 + ( $i % 100 ) / 1000;
		$items[] = (string) json_encode( $item, JSON_UNESCAPED_UNICODE );
	}

	return '{"passportElements":[' . implode( ',', $items ) . ']}';
}

/** @param array<string,mixed> $base_item @return array{payload:string,staging:string} */
function rp_prepare_worker_job( RussianPostPickupPointRepository $repository, RussianPostPickupImportStateService $state, RussianPostPickupImportLock $lock, array $base_item, int $count, string $job_id ): array {
	delete_option( RussianPostPickupImportLock::OPTION_NAME );
	delete_transient( 'wdc_russian_post_pickup_import_lock' );
	$payload = tempnam( sys_get_temp_dir(), 'wdc-rp-slice-' );
	if ( false === $payload ) {
		throw new RuntimeException( 'Unable to create worker-slice fixture payload.' );
	}
	file_put_contents( $payload, rp_worker_payload( $base_item, $count, $job_id ) );
	$staging = $repository->staging_table( $job_id );
	$repository->create_schema_if_needed( $staging );
	update_option(
		RussianPostPickupImportStateService::OPTION_NAME,
		array_merge(
			$state->defaults(),
			array(
				'status' => 'running',
				'stage' => 'parse',
				'import_id' => $job_id,
				'type' => 'ALL',
				'started_at' => current_time( 'mysql' ),
				'last_activity_at' => current_time( 'mysql' ),
				'payload_file' => $payload,
				'payload_size' => filesize( $payload ),
				'staging_table' => $staging,
				'main_table' => $repository->main_table(),
				'backup_table' => $repository->backup_table( $job_id ),
			)
		),
		false
	);
	rp_pickup_assert( $lock->acquire( $job_id ), 'Worker fixture must acquire its owner lock.' );

	return array( 'payload' => $payload, 'staging' => $staging );
}

$GLOBALS['wpdb'] = new wpdb();
$repo = new RussianPostPickupPointRepository( $GLOBALS['wpdb'] );
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 501, 'fias_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'postal_code' => '630001', 'region_name' => 'РќРЎРћ', 'city_name' => 'РќРѕРІРѕСЃРёР±РёСЂСЃРє', 'settlement_name' => 'РќРѕРІРѕСЃРёР±РёСЂСЃРє', 'display_name' => 'РќРѕРІРѕСЃРёР±РёСЂСЃРє', 'active' => 1, 'country_code' => 'RU', 'searchable_text' => 'РќРЎРћ РќРѕРІРѕСЃРёР±РёСЂСЃРє' ),
);
$pickup_location_resolver = new RussianPostPickupLocationResolver( new LocationRepository( $GLOBALS['wpdb'] ), $GLOBALS['wpdb'] );
$repo->create_schema_if_needed();
rp_pickup_assert( array_key_exists( 'wp_wdc_pickup_points_russian_post', $GLOBALS['wpdb']->tables ), 'Repository schema owner must create the Russian Post carrier-specific table.' );
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
$settings_db = new wpdb();
$service_repository = new DeliveryServiceRepository( $settings_db );
$service_repository->create_service(
	array(
		'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
		'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
		'service_type' => DeliveryService::TYPE_API,
		'title' => RussianPostDomesticSettings::TITLE,
		'enabled' => 1,
		'deleted' => 0,
	)
);
$settings = new RussianPostOtpravkaApiSettings( new SettingsRepository(), new EncryptionService(), $service_repository, new DeliveryServiceSettingsRepository( $settings_db ) );
$settings->save_from_admin( array( 'russian_post_otpravka_access_token' => 'token', 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_password' => 'password', 'russian_post_pickup_unload_type' => 'ALL' ) );
rp_pickup_assert( base64_encode( 'login:password' ) === $settings->basic_key(), 'Otpravka Basic authorization key must be computed from login and password.' );
$settings->save_from_admin( array( 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_password' => '', 'russian_post_otpravka_' . 'basic_key' => 'legacy-ready-key' ) );
rp_pickup_assert( base64_encode( 'login:password' ) === $settings->basic_key(), 'Legacy ready authorization input must not override login/password credentials.' );
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
$base_item['addressFias']['locationGarCode'] = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
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
foreach ( $items as &$item ) {
	$item['addressFias']['locationGarCode'] = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
}
unset( $item );
$GLOBALS['rp_passport_payload'] = '{"passportElements":[' . implode( ',', array_map( static fn( array $item ): string => (string) json_encode( $item, JSON_UNESCAPED_UNICODE ), $items ) ) . ']}';

if ( function_exists( 'curl_init' ) ) {
	$stream_source = tempnam( sys_get_temp_dir(), 'wdc-rp-curl-source-' );
	$stream_body = "PK\x03\x04russian-post-stream-test";
	file_put_contents( $stream_source, $stream_body );
	$stream_client = new RussianPostOtpravkaApiClient( $settings );
	$stream_method = ( new ReflectionClass( RussianPostOtpravkaApiClient::class ) )->getMethod( 'download_with_curl' );
	$stream_method->setAccessible( true );
	$stream_url = 'file:///' . str_replace( '\\', '/', ltrim( $stream_source, '\\/' ) );
	ob_start();
	$stream_result = $stream_method->invoke( $stream_client, $stream_url, 'ALL', 'token', 'basic', 10 );
	$stream_stdout = (string) ob_get_clean();
	rp_pickup_assert( '' === $stream_stdout && strlen( $stream_body ) === (int) $stream_result['temp_file_size'], 'Native cURL streaming must write response bytes only to the temp file, never stdout.' );
	wp_delete_file( $stream_source );
}

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
$importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, $state_service, null, $pickup_location_resolver );
rp_pickup_assert( str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/RussianPost/RussianPostPickupImporter.php' ), 'private const BATCH_SIZE = 500' ), 'Importer batch size must be 500.' );
$importer_reflection = new ReflectionClass( RussianPostPickupImporter::class );
$path_inside = $importer_reflection->getMethod( 'is_path_inside' );
$path_inside->setAccessible( true );
rp_pickup_assert( true === $path_inside->invoke( $importer, 'C:\\Users\\Admin\\AppData\\Local\\Temp\\WDC-RP-Extract\\passport.json', 'c:\\users\\admin\\appdata\\local\\temp\\wdc-rp-extract' ), 'Windows-style paths with different drive/path case must pass path containment.' );
rp_pickup_assert( true === $path_inside->invoke( $importer, '/tmp/base/nested/passport.json', '/tmp/base' ), 'Normal nested path must pass path containment.' );
rp_pickup_assert( false === $path_inside->invoke( $importer, '/tmp/base2/passport.json', '/tmp/base' ), 'Boundary sibling path must not pass path containment.' );

$settings->save_from_admin( array( 'russian_post_otpravka_clear_access_token' => '1', 'russian_post_otpravka_login' => '', 'russian_post_otpravka_clear_password' => '1' ) );
rp_pickup_assert( ! $importer->queue_background_import( 'ALL' ), 'Missing Otpravka credentials must reject API import before queue and lock acquisition.' );
$missing_credentials_state = $state_service->current();
rp_pickup_assert( 'failed' === (string) $missing_credentials_state['status'] && str_contains( implode( ' ', $missing_credentials_state['errors'] ), 'credentials are incomplete' ) && ! $importer->is_locked(), 'Missing credentials must remain visible as a terminal error without an active lock.' );
$settings->save_from_admin( array( 'russian_post_otpravka_access_token' => 'token', 'russian_post_otpravka_login' => 'login', 'russian_post_otpravka_password' => 'password' ) );
rp_pickup_assert( $importer->queue_background_import( 'ALL' ), 'Import must start after missing credentials are supplied.' );
$active_lock = get_option( RussianPostPickupImportLock::OPTION_NAME, array() );
rp_pickup_assert( is_array( $active_lock ) && '' !== (string) ( $active_lock['job_id'] ?? '' ), 'A queued import must persist an owned lock.' );
$active_state = $state_service->current();
$active_state['last_activity_at'] = date( 'Y-m-d H:i:s' );
update_option( RussianPostPickupImportStateService::OPTION_NAME, $active_state, false );
rp_pickup_assert( ! $importer->queue_background_import( 'ALL' ), 'A concurrent start must be rejected.' );
rp_pickup_assert( $active_lock === get_option( RussianPostPickupImportLock::OPTION_NAME, array() ), 'A concurrent start must not replace the active job lock.' );
$importer->reset_stale_or_running_import();
$GLOBALS['wdc_scheduled_events'] = array();

// A late init callback may pass its guard as job A, then resume after admin
// cancellation and job B queueing. Its failure must never inherit B ownership.
$late_init_importer = null;
$queued_b_state = array();
$queued_b_lock = array();
$late_init_client = new RussianPostOtpravkaApiClient(
	$settings,
	static function ( string $url, string $type ) use ( &$late_init_importer, &$queued_b_state, &$queued_b_lock ): array {
		if ( ! $late_init_importer instanceof RussianPostPickupImporter ) {
			throw new RuntimeException( 'Late-init race importer is unavailable.' );
		}
		$late_init_importer->reset_stale_or_running_import();
		if ( ! $late_init_importer->queue_background_import( 'ALL' ) ) {
			throw new RuntimeException( 'Unable to queue replacement job B during late init race.' );
		}
		$queued_b_state = ( new RussianPostPickupImportStateService() )->current();
		$queued_b_lock = get_option( RussianPostPickupImportLock::OPTION_NAME, array() );
		throw new RuntimeException( 'Injected late init A failure after job B was queued.' );
	}
);
$late_init_importer = new RussianPostPickupImporter( $settings, $late_init_client, $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, new RussianPostPickupImportLock( $GLOBALS['wpdb'] ) );
rp_pickup_assert( $late_init_importer->queue_background_import( 'ALL' ), 'Late-init race must queue old job A.' );
$late_init_a_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$late_init_a_thrown = false;
try {
	$late_init_importer->run_import_init( (string) $late_init_a_event['args'][0], (string) $late_init_a_event['args'][1] );
} catch ( RuntimeException $exception ) {
	$late_init_a_thrown = str_contains( $exception->getMessage(), 'lost state ownership' );
}
$after_late_init_state = $state_service->current();
$after_late_init_lock = get_option( RussianPostPickupImportLock::OPTION_NAME, array() );
rp_pickup_assert(
	$late_init_a_thrown && 'queued' === (string) ( $after_late_init_state['status'] ?? '' ) && (string) ( $queued_b_state['import_id'] ?? '' ) === (string) ( $after_late_init_state['import_id'] ?? '' ) && $queued_b_lock === $after_late_init_lock,
	sprintf( 'Late init A failure after job B queueing must leave B queued with its structured lock unchanged; actual status=%s state_job=%s lock_job=%s.', (string) ( $after_late_init_state['status'] ?? '' ), (string) ( $after_late_init_state['import_id'] ?? '' ), (string) ( $after_late_init_lock['job_id'] ?? '' ) )
);

$late_a_id = (string) $late_init_a_event['args'][0];
$queued_b_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$replacement_importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, new RussianPostPickupImportLock( $GLOBALS['wpdb'] ) );
$polled_b_state = $replacement_importer->refresh_state_for_status();
rp_pickup_assert( $after_late_init_state === $polled_b_state && $after_late_init_lock === get_option( RussianPostPickupImportLock::OPTION_NAME, array() ), 'Status polling between queue and init must preserve job B state and lock.' );
$second_poll_importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, new RussianPostPickupImportLock( $GLOBALS['wpdb'] ) );
$second_polled_b_state = $second_poll_importer->refresh_state_for_status();
rp_pickup_assert( $polled_b_state === $second_polled_b_state && $after_late_init_lock === get_option( RussianPostPickupImportLock::OPTION_NAME, array() ), 'Two independent immediate status polls must preserve a valid queued job and its lock.' );

$foreign_callbacks_thrown = array();
foreach ( array( 'init', 'batch', 'finalize' ) as $foreign_callback ) {
	try {
		match ( $foreign_callback ) {
			'init' => $replacement_importer->run_import_init( $late_a_id, 'ALL' ),
			'batch' => $replacement_importer->run_import_batch( $late_a_id, 'ALL', 0 ),
			'finalize' => $replacement_importer->run_import_finalize( $late_a_id, 'ALL' ),
		};
		$foreign_callbacks_thrown[ $foreign_callback ] = false;
	} catch ( RuntimeException $exception ) {
		$foreign_callbacks_thrown[ $foreign_callback ] = str_contains( $exception->getMessage(), 'does not own the active job' );
	}
	rp_pickup_assert( $after_late_init_state === $state_service->current() && $after_late_init_lock === get_option( RussianPostPickupImportLock::OPTION_NAME, array() ), 'Foreign late ' . $foreign_callback . ' A callback must not mutate job B state or structured lock.' );
}
rp_pickup_assert( ! in_array( false, $foreign_callbacks_thrown, true ), 'Late init, batch, and finalize A callbacks must use the same foreign-owner failure contract.' );

$replacement_init = $replacement_importer->run_import_init( (string) $queued_b_event['args'][0], (string) $queued_b_event['args'][1] );
$replacement_running_state = $state_service->current();
rp_pickup_assert( ! empty( $replacement_init['success'] ) && 'running' === (string) $replacement_running_state['status'] && 'parse' === (string) $replacement_running_state['stage'] && (string) $queued_b_state['import_id'] === (string) $replacement_running_state['import_id'] && $replacement_importer->is_locked(), 'Job B init must renew its unchanged lock and enter running/parse after arbitrary polling and late A callbacks.' );
$replacement_importer->reset_stale_or_running_import();
$GLOBALS['wdc_scheduled_events'] = array();

$cas_a_id = 'terminal-cas-a';
$cas_b_id = 'terminal-cas-b';
$state_service->queue( 'ALL', $cas_a_id );
$cas_b_state = array_merge( $state_service->defaults(), array( 'status' => 'queued', 'stage' => 'queued', 'import_id' => $cas_b_id, 'type' => 'ALL', 'last_activity_at' => current_time( 'mysql' ) ) );
$GLOBALS['wdc_before_option_cas'] = static function ( string $key ) use ( $cas_b_state ): void {
	if ( RussianPostPickupImportStateService::OPTION_NAME === $key ) {
		update_option( RussianPostPickupImportStateService::OPTION_NAME, $cas_b_state, false );
	}
};
$terminal_cas_thrown = false;
try {
	$state_service->failed_if_owned( $cas_a_id, array( 'import_id' => $cas_a_id, 'type' => 'ALL', 'errors' => array( 'Injected A failure.' ) ) );
} catch ( RuntimeException $exception ) {
	$terminal_cas_thrown = str_contains( $exception->getMessage(), 'ownership changed during terminal transition' );
}
rp_pickup_assert( $terminal_cas_thrown && $cas_b_state === $state_service->current(), 'Owner-scoped terminal state CAS must not overwrite job B when state changes during the write.' );

$GLOBALS['wdc_force_schedule_failure'] = true;
rp_pickup_assert( ! $importer->queue_background_import( 'ALL' ), 'Scheduler failure must reject import start.' );
$schedule_failed_state = $state_service->current();
unset( $GLOBALS['wdc_force_schedule_failure'] );
rp_pickup_assert( 'failed' === (string) $schedule_failed_state['status'] && str_contains( implode( ' ', $schedule_failed_state['errors'] ), 'Unable to schedule background import job.' ) && ! $importer->is_locked(), 'Scheduler failure after acquire must persist terminal failure and release the owned lock.' );

rp_pickup_assert( $importer->queue_background_import( 'ALL' ), 'Import must queue init job.' );
$init_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$init = $importer->run_import_init( (string) $init_event['args'][0], (string) $init_event['args'][1] );
$state = $state_service->current();
rp_pickup_assert( ! empty( $init['success'] ) && '' !== $state['staging_table'] && array_key_exists( $state['staging_table'], $GLOBALS['wpdb']->tables ), 'Init must create staging table.' );
rp_pickup_assert( $main_before === count( $GLOBALS['wpdb']->tables[ $main ] ), 'Main table must not change during init.' );
rp_pickup_assert( 200 === (int) $state['download_http_code'] && (int) $state['temp_file_size'] > 0 && '' !== (string) $state['download_url'] && isset( $GLOBALS['rp_last_http_args']['connect_timeout'] ) && 'wp_http' === (string) $state['download_backend'] && ! empty( $state['fallback_used'] ) && str_contains( (string) $state['first_backend_error'], 'Injected cURL failure' ), 'Successful fallback download must store backend diagnostics and use connect timeout.' );

// Reproduce the production status-poll race: a valid, unexpired owner lock must
// remain authoritative even when the stage activity timestamp crosses the
// short parse timeout between Action Scheduler requests.
$post_init_payload = (string) $state['payload_file'];
$state['last_activity_at'] = date( 'Y-m-d H:i:s', time() - 601 );
update_option( RussianPostPickupImportStateService::OPTION_NAME, $state, false );
$request_two_importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, new RussianPostPickupImportLock( $GLOBALS['wpdb'] ) );
$status_page_reflection = new ReflectionClass( DeliveryServicesAdminPage::class );
$status_page = $status_page_reflection->newInstanceWithoutConstructor();
$status_importer_property = $status_page_reflection->getProperty( 'pickup_importer' );
$status_importer_property->setAccessible( true );
$status_importer_property->setValue( $status_page, $request_two_importer );
try {
	$status_page->ajax_pickup_import_status();
} catch ( RuntimeException $exception ) {
	rp_pickup_assert( 'wdc-json-response' === $exception->getMessage(), 'Status AJAX must terminate through its JSON response.' );
}
$polled_state = (array) ( $GLOBALS['wdc_json_response'] ?? array() );
rp_pickup_assert( 'running' === (string) $polled_state['status'] && 'parse' === (string) $polled_state['stage'] && $request_two_importer->is_locked() && is_file( $post_init_payload ), 'Status polling in a fresh request must not fail an active post-init pipeline or release its unexpired owner lock.' );

$batch_event = rp_shift_event( RussianPostPickupImporter::BATCH_HOOK );
$request_three_importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, new RussianPostPickupImportLock( $GLOBALS['wpdb'] ) );
$batch = $request_three_importer->run_import_batch( (string) $batch_event['args'][0], (string) $batch_event['args'][1], (int) $batch_event['args'][2] );
$state = $state_service->current();
rp_pickup_assert( ! empty( $batch['success'] ) && 3 === count( $GLOBALS['wpdb']->tables[ $state['staging_table'] ] ) && $main_before === count( $GLOBALS['wpdb']->tables[ $main ] ), 'Batch must write only to staging, not main.' );
rp_pickup_assert( 3 === (int) $state['rows_inserted_to_staging'], 'State must track rows inserted to staging.' );
rp_pickup_assert( 3 === (int) $state['location_matched_fias'] && 0 === (int) $state['location_match_no_match'] && 501 === (int) $GLOBALS['wpdb']->tables[ $state['staging_table'] ][0]['location_id'], 'Import batch must resolve and store Russian Post pickup location_id before staging insert.' );
rp_pickup_assert( $request_three_importer->is_locked(), 'Owner lock must remain active after a successful batch request.' );

$final_event = rp_shift_event( RussianPostPickupImporter::FINALIZE_HOOK );
$request_four_importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, new RussianPostPickupImportLock( $GLOBALS['wpdb'] ) );
$final = $request_four_importer->run_import_finalize( (string) $final_event['args'][0], (string) $final_event['args'][1] );
$state = $state_service->current();
rp_pickup_assert( ! empty( $final['success'] ) && 3 === count( $GLOBALS['wpdb']->tables[ $main ] ) && ! array_key_exists( (string) $state['staging_table'], $GLOBALS['wpdb']->tables ), 'Finalize must atomically swap staging to main.' );
rp_pickup_assert( '' !== (string) $state['swap_started_at'] && '' !== (string) $state['swap_finished_at'], 'State must store swap timestamps.' );
rp_pickup_assert( in_array( $main, $GLOBALS['wpdb']->analyzed_tables, true ), 'Successful finalize must analyze main table.' );
rp_pickup_assert( 501 === (int) $GLOBALS['wpdb']->tables[ $main ][0]['location_id'], 'Final imported Russian Post pickup rows must keep resolved location_id after staging swap.' );
rp_pickup_assert( ! $request_four_importer->is_locked(), 'Successful terminal finalize must release the owner lock.' );
rp_pickup_assert( array() === (array) ( $state['errors'] ?? array() ), 'A successful import must not contain performance warnings or other errors.' );

// One Action Scheduler callback must process multiple durable 500-object units
// and schedule only one continuation when its unit budget is exhausted.
$slice_lock = new RussianPostPickupImportLock( $GLOBALS['wpdb'] );
rp_prepare_worker_job( $repo, $state_service, $slice_lock, $base_item, 1601, 'slice-unit-budget' );
$GLOBALS['wdc_scheduled_events'] = array();
$slice_importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, $slice_lock, static fn(): BackgroundExecutionBudget => new RpControlledExecutionBudget( 3 ) );
$slice_result = $slice_importer->run_import_batch( 'slice-unit-budget', 'ALL', 0 );
$slice_state = $state_service->current();
$slice_continuations = array_values( array_filter( $GLOBALS['wdc_scheduled_events'], static fn( array $event ): bool => RussianPostPickupImporter::BATCH_HOOK === $event['hook'] ) );
rp_pickup_assert( ! empty( $slice_result['success'] ) && 1500 === (int) $slice_state['objects_processed'] && 3 === (int) $slice_state['worker_slice_batches'] && 1500 === (int) $slice_state['worker_slice_objects'], 'One worker callback must process three atomic batches and more than 500 objects.' );
rp_pickup_assert( 'unit_budget' === (string) $slice_state['worker_slice_stop_reason'] && 1 === count( $slice_continuations ) && $slice_importer->is_locked(), 'Unit-budget stop must keep the owner lock and schedule exactly one continuation.' );
echo sprintf( "Russian Post worker slice fixture: batches=%d objects=%d duration_ms=%d stop_reason=%s.\n", (int) $slice_state['worker_slice_batches'], (int) $slice_state['worker_slice_objects'], (int) $slice_state['worker_slice_duration_ms'], (string) $slice_state['worker_slice_stop_reason'] );
$slice_importer->reset_stale_or_running_import();

// The same worker loop must stop deterministically on elapsed time after three units.
rp_prepare_worker_job( $repo, $state_service, $slice_lock, $base_item, 1601, 'slice-time-budget' );
$GLOBALS['wdc_scheduled_events'] = array();
$clock_values = array( 0.0, 0.0, 5.0, 10.0, 18.0 );
$clock_last = 18.0;
$time_budget_factory = static function () use ( &$clock_values, &$clock_last ): BackgroundExecutionBudget {
	return new BackgroundExecutionBudget(
		18.0,
		15,
		null,
		static function () use ( &$clock_values, &$clock_last ): float {
			if ( array() !== $clock_values ) {
				$clock_last = (float) array_shift( $clock_values );
			}
			return $clock_last;
		}
	);
};
$time_importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, $slice_lock, $time_budget_factory );
$time_result = $time_importer->run_import_batch( 'slice-time-budget', 'ALL', 0 );
$time_state = $state_service->current();
rp_pickup_assert( ! empty( $time_result['success'] ) && 3 === (int) $time_state['worker_slice_batches'] && 'time_budget' === (string) $time_state['worker_slice_stop_reason'] && 1 === count( $GLOBALS['wdc_scheduled_events'] ), 'Time budget must stop after the deterministic third batch and schedule one continuation.' );
$time_importer->reset_stale_or_running_import();

// EOF inside a slice schedules finalize exactly once and no batch continuation.
rp_prepare_worker_job( $repo, $state_service, $slice_lock, $base_item, 1001, 'slice-eof' );
$GLOBALS['wdc_scheduled_events'] = array();
$eof_importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, $slice_lock, static fn(): BackgroundExecutionBudget => new RpControlledExecutionBudget( 15 ) );
$eof_result = $eof_importer->run_import_batch( 'slice-eof', 'ALL', 0 );
$eof_state = $state_service->current();
rp_pickup_assert( ! empty( $eof_result['success'] ) && 1001 === (int) $eof_state['objects_processed'] && 3 === (int) $eof_state['worker_slice_batches'] && 'eof' === (string) $eof_state['worker_slice_stop_reason'], 'EOF must be reached within one multi-batch callback.' );
rp_pickup_assert( 1 === count( $GLOBALS['wdc_scheduled_events'] ) && RussianPostPickupImporter::FINALIZE_HOOK === $GLOBALS['wdc_scheduled_events'][0]['hook'], 'EOF must schedule finalize exactly once without a batch continuation.' );
$eof_importer->reset_stale_or_running_import();

// Cancellation and lock loss between units must prevent the next batch.
rp_prepare_worker_job( $repo, $state_service, $slice_lock, $base_item, 1001, 'slice-cancel' );
$GLOBALS['wdc_scheduled_events'] = array();
$cancel_worker = null;
$cancel_budget = static function () use ( &$cancel_worker ): BackgroundExecutionBudget {
	return new RpControlledExecutionBudget( 15, static function ( int $units ) use ( &$cancel_worker ): void {
		if ( 1 === $units && $cancel_worker instanceof RussianPostPickupImporter ) {
			$cancel_worker->reset_stale_or_running_import();
		}
	} );
};
$cancel_worker = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, $slice_lock, $cancel_budget );
$cancel_result = $cancel_worker->run_import_batch( 'slice-cancel', 'ALL', 0 );
$cancel_state = $state_service->current();
rp_pickup_assert( empty( $cancel_result['success'] ) && ! empty( $cancel_result['cancelled'] ) && 500 === (int) $cancel_state['objects_processed'] && 'cancelled' === (string) $cancel_state['worker_slice_stop_reason'] && array() === $GLOBALS['wdc_scheduled_events'], 'Admin cancellation after batch one must prevent batch two and schedule nothing.' );

rp_prepare_worker_job( $repo, $state_service, $slice_lock, $base_item, 1001, 'slice-lock-loss' );
$GLOBALS['wdc_scheduled_events'] = array();
$lock_loss_budget = static fn(): BackgroundExecutionBudget => new RpControlledExecutionBudget( 15, static function ( int $units ): void {
	if ( 1 === $units ) {
		delete_option( RussianPostPickupImportLock::OPTION_NAME );
	}
} );
$lock_loss_importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, $slice_lock, $lock_loss_budget );
$lock_loss_result = $lock_loss_importer->run_import_batch( 'slice-lock-loss', 'ALL', 0 );
$lock_loss_state = $state_service->current();
rp_pickup_assert( empty( $lock_loss_result['success'] ) && 'failed' === (string) $lock_loss_state['status'] && 500 === (int) $lock_loss_state['objects_processed'] && 'lock_lost' === (string) $lock_loss_state['worker_slice_stop_reason'] && array() === $GLOBALS['wdc_scheduled_events'], 'Owner lock loss after batch one must fail explicitly before batch two.' );

$owner_race_fixture = rp_prepare_worker_job( $repo, $state_service, $slice_lock, $base_item, 1001, 'slice-old-owner' );
$GLOBALS['wdc_scheduled_events'] = array();
$new_owner_state = array_merge( $state_service->defaults(), array( 'status' => 'running', 'stage' => 'parse', 'import_id' => 'slice-new-owner', 'last_activity_at' => current_time( 'mysql' ) ) );
$new_owner_lock = array( 'job_id' => 'slice-new-owner', 'token' => 'slice-new-token', 'acquired_at' => time(), 'expires_at' => time() + 10800 );
$owner_race_budget = static fn(): BackgroundExecutionBudget => new RpControlledExecutionBudget( 15, static function ( int $units ) use ( $new_owner_state, $new_owner_lock ): void {
	if ( 1 === $units ) {
		update_option( RussianPostPickupImportStateService::OPTION_NAME, $new_owner_state, false );
		update_option( RussianPostPickupImportLock::OPTION_NAME, $new_owner_lock, false );
	}
} );
$owner_race_importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, $slice_lock, $owner_race_budget );
$owner_race_thrown = false;
try {
	$owner_race_importer->run_import_batch( 'slice-old-owner', 'ALL', 0 );
} catch ( RuntimeException $exception ) {
	$owner_race_thrown = str_contains( $exception->getMessage(), 'lost state ownership' );
}
rp_pickup_assert( $owner_race_thrown && $new_owner_state === get_option( RussianPostPickupImportStateService::OPTION_NAME, array() ) && $new_owner_lock === get_option( RussianPostPickupImportLock::OPTION_NAME, array() ) && array() === $GLOBALS['wdc_scheduled_events'], 'A stale worker must fail its callback without mutating or unlocking a new active owner.' );
wp_delete_file( (string) $owner_race_fixture['payload'] );
unset( $GLOBALS['wpdb']->tables[ $owner_race_fixture['staging'] ] );
delete_option( RussianPostPickupImportLock::OPTION_NAME );

// A crash before unit three must retain the checkpoint written after unit two.
rp_prepare_worker_job( $repo, $state_service, $slice_lock, $base_item, 1601, 'slice-crash' );
$GLOBALS['wdc_scheduled_events'] = array();
$crash_budget = static fn(): BackgroundExecutionBudget => new RpControlledExecutionBudget( 15, null, static function ( int $units ): void {
	if ( 2 === $units ) {
		throw new RuntimeException( 'Injected crash before batch three.' );
	}
} );
$crash_importer = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, $slice_lock, $crash_budget );
$crash_result = $crash_importer->run_import_batch( 'slice-crash', 'ALL', 0 );
$crash_state = $state_service->current();
rp_pickup_assert( empty( $crash_result['success'] ) && 'failed' === (string) $crash_state['status'] && 1000 === (int) $crash_state['objects_processed'] && (int) $crash_state['payload_offset'] > 0 && 'error' === (string) $crash_state['worker_slice_stop_reason'], 'Failure before batch three must preserve the durable checkpoint after batch two.' );

// Read-only status polling between batches must preserve the active owner and allow the slice to continue.
rp_prepare_worker_job( $repo, $state_service, $slice_lock, $base_item, 1201, 'slice-status-poll' );
$GLOBALS['wdc_scheduled_events'] = array();
$poll_worker = null;
$poll_budget = static function () use ( &$poll_worker ): BackgroundExecutionBudget {
	return new RpControlledExecutionBudget( 2, static function ( int $units ) use ( &$poll_worker ): void {
		if ( 1 === $units && $poll_worker instanceof RussianPostPickupImporter ) {
			$polled = $poll_worker->refresh_state_for_status();
			rp_pickup_assert( 'running' === (string) $polled['status'] && $poll_worker->is_locked(), 'Status poll inside a slice must preserve its owner lock.' );
		}
	} );
};
$poll_worker = new RussianPostPickupImporter( $settings, new RussianPostOtpravkaApiClient( $settings, rp_curl_failure_downloader() ), $repo, $normalizer, new RussianPostPickupImportStateService(), null, $pickup_location_resolver, $slice_lock, $poll_budget );
$poll_result = $poll_worker->run_import_batch( 'slice-status-poll', 'ALL', 0 );
$poll_state = $state_service->current();
rp_pickup_assert( ! empty( $poll_result['success'] ) && 1000 === (int) $poll_state['objects_processed'] && $poll_worker->is_locked() && 1 === count( $GLOBALS['wdc_scheduled_events'] ), 'Status polling must not interrupt subsequent internal batches or duplicate continuation.' );
$poll_worker->reset_stale_or_running_import();
$GLOBALS['wdc_scheduled_events'] = array();

$guard_payload = tempnam( sys_get_temp_dir(), 'wdc-rp-guard-' );
file_put_contents( $guard_payload, '{"passportElements":[]}' );
$guard_state = array_merge( $state_service->defaults(), array( 'status' => 'running', 'stage' => 'parse', 'import_id' => 'missing-lock-job', 'type' => 'ALL', 'payload_file' => $guard_payload, 'payload_offset' => 17 ) );
update_option( RussianPostPickupImportStateService::OPTION_NAME, $guard_state, false );
delete_option( RussianPostPickupImportLock::OPTION_NAME );
$guard_result = $importer->run_import_batch( 'missing-lock-job', 'ALL', 17 );
$guard_failed_state = $state_service->current();
$guard_diagnostic = end( $guard_failed_state['guard_diagnostics'] );
rp_pickup_assert( empty( $guard_result['success'] ) && 'failed' === (string) $guard_failed_state['status'] && str_contains( implode( ' ', $guard_failed_state['errors'] ), 'invariant failed' ) && 'batch' === (string) ( $guard_diagnostic['callback'] ?? '' ) && false === (bool) ( $guard_diagnostic['lock_exists'] ?? true ) && ! empty( $guard_diagnostic['payload_file_exists'] ) && ! empty( $guard_diagnostic['payload_file_readable'] ) && 17 === (int) ( $guard_diagnostic['payload_offset'] ?? -1 ) && ! file_exists( $guard_payload ), 'A same-job callback without its owner lock must become explicit failed state with bounded diagnostics and cleanup.' );

rp_pickup_assert( $importer->queue_background_import( 'ALL' ), 'Wrong-owner callback race fixture must queue an active job.' );
$race_state = $state_service->current();
$race_lock = get_option( RussianPostPickupImportLock::OPTION_NAME, array() );
$race_thrown = false;
try {
	$importer->run_import_init( 'stale-foreign-job', 'ALL' );
} catch ( RuntimeException $exception ) {
	$race_thrown = str_contains( $exception->getMessage(), 'does not own the active job' );
}
$race_after = $state_service->current();
rp_pickup_assert( $race_thrown && 'running' !== (string) $race_after['status'] && 'queued' === (string) $race_after['status'] && (string) $race_state['import_id'] === (string) $race_after['import_id'] && $race_lock === get_option( RussianPostPickupImportLock::OPTION_NAME, array() ), 'A stale foreign callback must fail its Action Scheduler action without changing or unlocking the current job.' );
$importer->reset_stale_or_running_import();
$GLOBALS['wdc_scheduled_events'] = array();

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
rp_pickup_assert( ! file_exists( $cancel_uploaded_zip ) && ! $importer->is_locked(), 'Cancel/reset must delete queued uploaded ZIP and unlock.' );
$GLOBALS['wdc_scheduled_events'] = array();

$admin_reflection = new ReflectionClass( DeliveryServicesAdminPage::class );
$admin_page = $admin_reflection->newInstanceWithoutConstructor();
foreach ( array( 'pickup_importer' => $importer, 'pickup_import_state' => $state_service, 'otpravka_settings' => $settings ) as $property => $value ) {
	$ref_property = $admin_reflection->getProperty( $property );
	$ref_property->setAccessible( true );
	$ref_property->setValue( $admin_page, $value );
}
$upload_handler = $admin_reflection->getMethod( 'handle_russian_post_pickup_file_upload' );
$upload_handler->setAccessible( true );

$upload_tmp_locked = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-admin-locked-' . uniqid() . '.zip';
rp_write_passport_zip( $upload_tmp_locked );
$active_upload_state = array_merge( $state_service->defaults(), array( 'status' => 'running', 'stage' => 'upsert', 'import_id' => 'active-upload-job', 'last_activity_at' => date( 'Y-m-d H:i:s' ), 'errors' => array() ) );
update_option( RussianPostPickupImportStateService::OPTION_NAME, $active_upload_state, false );
set_transient( 'wdc_russian_post_pickup_import_lock', 1, 3600 );
$_FILES['russian_post_pickup_file'] = array( 'name' => 'locked.txt', 'tmp_name' => $upload_tmp_locked, 'error' => UPLOAD_ERR_OK );
$upload_handler->invoke( $admin_page );
$locked_upload_state = $state_service->current();
$locked_target = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-uploads' . DIRECTORY_SEPARATOR . 'wdc-imports' . DIRECTORY_SEPARATOR . 'locked.txt';
rp_pickup_assert( ! file_exists( $upload_tmp_locked ) && ! file_exists( $locked_target ) && 'running' === (string) $locked_upload_state['status'] && 'active-upload-job' === (string) $locked_upload_state['import_id'] && false !== get_transient( RussianPostPickupImportLock::OPTION_NAME ), 'Rejected concurrent admin upload must delete its stored file without changing the active job state or lock.' );
$importer->reset_stale_or_running_import();
$GLOBALS['wdc_scheduled_events'] = array();

$upload_tmp_success = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-admin-success-' . uniqid() . '.zip';
rp_write_passport_zip( $upload_tmp_success );
$_FILES['russian_post_pickup_file'] = array( 'name' => 'success.zip', 'tmp_name' => $upload_tmp_success, 'error' => UPLOAD_ERR_OK );
$upload_handler->invoke( $admin_page );
$success_upload_state = $state_service->current();
rp_pickup_assert( 'queued' === (string) $success_upload_state['status'] && 'uploaded_zip' === (string) $success_upload_state['source'] && is_file( (string) $success_upload_state['temp_zip_file'] ), 'Admin .zip upload must route to ZIP queue and keep file for init job.' );
$importer->reset_stale_or_running_import();
$GLOBALS['wdc_scheduled_events'] = array();

$upload_tmp_txt = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-admin-payload-' . uniqid() . '.txt';
file_put_contents( $upload_tmp_txt, (string) $GLOBALS['rp_passport_payload'] );
$_FILES['russian_post_pickup_file'] = array( 'name' => 'payload.txt', 'tmp_name' => $upload_tmp_txt, 'error' => UPLOAD_ERR_OK );
$upload_handler->invoke( $admin_page );
$txt_upload_state = $state_service->current();
rp_pickup_assert( 'queued' === (string) $txt_upload_state['status'] && 'uploaded_payload' === (string) $txt_upload_state['source'] && is_file( (string) $txt_upload_state['payload_file'] ), 'Admin .txt upload must route to payload queue and keep file for init job.' );
$txt_init_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$txt_init = $importer->run_import_init( (string) $txt_init_event['args'][0], (string) $txt_init_event['args'][1] );
$txt_state = $state_service->current();
rp_pickup_assert( ! empty( $txt_init['success'] ) && 'uploaded_payload' === (string) $txt_state['source'] && 'parse' === (string) $txt_state['stage'] && '' === (string) $txt_state['download_backend'] && '' === (string) $txt_state['extract_backend'] && (int) $txt_state['payload_size'] > 0, 'Uploaded payload init must skip download/extract and schedule batch.' );
$txt_batch_event = rp_shift_event( RussianPostPickupImporter::BATCH_HOOK );
$txt_batch = $importer->run_import_batch( (string) $txt_batch_event['args'][0], (string) $txt_batch_event['args'][1], (int) $txt_batch_event['args'][2] );
$txt_finalize_event = rp_shift_event( RussianPostPickupImporter::FINALIZE_HOOK );
$txt_final = $importer->run_import_finalize( (string) $txt_finalize_event['args'][0], (string) $txt_finalize_event['args'][1] );
rp_pickup_assert( ! empty( $txt_batch['success'] ) && ! empty( $txt_final['success'] ) && ! file_exists( (string) $txt_state['payload_file'] ), 'Uploaded payload batch/staging/swap must work and cleanup payload on finalize.' );

delete_transient( 'wdc_russian_post_pickup_import_lock' );
$GLOBALS['wdc_scheduled_events'] = array();
$upload_tmp_json = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-admin-payload-' . uniqid() . '.json';
file_put_contents( $upload_tmp_json, (string) $GLOBALS['rp_passport_payload'] );
$_FILES['russian_post_pickup_file'] = array( 'name' => 'payload.json', 'tmp_name' => $upload_tmp_json, 'error' => UPLOAD_ERR_OK );
$upload_handler->invoke( $admin_page );
$json_upload_state = $state_service->current();
rp_pickup_assert( 'queued' === (string) $json_upload_state['status'] && 'uploaded_payload' === (string) $json_upload_state['source'] && str_ends_with( (string) $json_upload_state['payload_file'], '.json' ), 'Admin .json upload must route to payload queue.' );
$importer->reset_stale_or_running_import();
$GLOBALS['wdc_scheduled_events'] = array();

$upload_tmp_invalid_ext = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-admin-invalid-' . uniqid() . '.csv';
file_put_contents( $upload_tmp_invalid_ext, 'bad' );
$_FILES['russian_post_pickup_file'] = array( 'name' => 'payload.csv', 'tmp_name' => $upload_tmp_invalid_ext, 'error' => UPLOAD_ERR_OK );
$upload_handler->invoke( $admin_page );
$invalid_ext_state = $state_service->current();
rp_pickup_assert( 'failed' === (string) $invalid_ext_state['status'] && str_contains( implode( ' ', $invalid_ext_state['errors'] ), 'Only ZIP, TXT, or JSON files are allowed' ) && file_exists( $upload_tmp_invalid_ext ), 'Invalid extension must fail before storing uploaded file.' );
@unlink( $upload_tmp_invalid_ext );
unset( $_FILES['russian_post_pickup_file'] );

delete_transient( 'wdc_russian_post_pickup_import_lock' );
$missing_payload = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-rp-missing-' . uniqid() . '.json';
rp_pickup_assert( ! $importer->queue_background_import_from_payload( $missing_payload, 'ALL', 'missing.json' ), 'Missing uploaded payload must fail queue.' );
$missing_payload_state = $state_service->current();
rp_pickup_assert( 'failed' === (string) $missing_payload_state['status'] && str_contains( implode( ' ', $missing_payload_state['errors'] ), 'Uploaded TXT/JSON payload file is missing' ), 'Missing payload must save failed state.' );

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
rp_pickup_assert( empty( $unavailable_result['success'] ) && 'failed' === (string) $unavailable_state['status'] && empty( $unavailable_state['ziparchive_available'] ) && str_contains( implode( ' ', $unavailable_state['errors'] ), 'PHP ZipArchive extension is not available.' ) && ! $importer->is_locked() && ! file_exists( $unavailable_zip ) && ! array_key_exists( (string) $unavailable_state['staging_table'], $GLOBALS['wpdb']->tables ), 'ZipArchive unavailable must fail state, clear lock, and cleanup.' );

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
$direct_lock = new RussianPostPickupImportLock( $GLOBALS['wpdb'] );
rp_pickup_assert( $direct_lock->acquire( $importer_recovery_id ), 'Direct finalize recovery fixture must own the import lock.' );
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
rp_pickup_assert( $direct_lock->acquire( $analyze_warning_id ), 'Direct analyze finalize fixture must own the import lock.' );
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
rp_pickup_assert( 'failed' === $failed_state['status'] && ! $importer->is_locked() && str_contains( (string) $failed_state['download_error'], 'cURL error 28' ), 'WP_Error download failure must fail state, clear lock, and store message.' );
$GLOBALS['rp_http_mode'] = '';

$GLOBALS['rp_http_mode'] = 'http_500';
delete_transient( 'wdc_russian_post_pickup_import_lock' );
rp_pickup_assert( $importer->queue_background_import( 'ALL' ), 'HTTP failure test must queue.' );
$http_failed_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$http_failed = $importer->run_import_init( (string) $http_failed_event['args'][0], (string) $http_failed_event['args'][1] );
$http_failed_state = $state_service->current();
rp_pickup_assert( empty( $http_failed['success'] ) && 500 === (int) $http_failed_state['download_http_code'] && str_contains( implode( ' ', $http_failed_state['errors'] ), 'Internal Server Error' ) && str_contains( implode( ' ', $http_failed_state['errors'] ), 'temporary failure body' ), 'HTTP download failure must store code/message/body excerpt.' );
$GLOBALS['rp_http_mode'] = '';

$throwing_client = new RussianPostOtpravkaApiClient(
	$settings,
	static function (): array {
		throw new RuntimeException( 'Injected failure after lock acquisition.' );
	}
);
$throwing_importer = new RussianPostPickupImporter( $settings, $throwing_client, $repo, $normalizer, $state_service, null, $pickup_location_resolver );
rp_pickup_assert( $throwing_importer->queue_background_import( 'ALL' ), 'Unexpected failure test must acquire the lock and queue init.' );
$throwing_event = rp_shift_event( RussianPostPickupImporter::INIT_HOOK );
$throwing_result = $throwing_importer->run_import_init( (string) $throwing_event['args'][0], (string) $throwing_event['args'][1] );
$throwing_state = $state_service->current();
rp_pickup_assert( empty( $throwing_result['success'] ) && 'failed' === (string) $throwing_state['status'] && str_contains( implode( ' ', $throwing_state['errors'] ), 'Injected failure after lock acquisition.' ) && ! $throwing_importer->is_locked(), 'Unexpected failure after acquire must set terminal failed state and release the owned lock.' );

$legacy_failed_id = 'legacy-failed-import';
$state_service->failed( array( 'import_id' => $legacy_failed_id, 'type' => 'ALL', 'finished_at' => current_time( 'mysql' ), 'errors' => array( 'Russian Post Otpravka credentials are incomplete.' ) ) );
set_transient( RussianPostPickupImportLock::OPTION_NAME, 1, 3600 );
rp_pickup_assert( $importer->queue_background_import( 'ALL' ), 'A terminal failed job must safely recover its legacy lock and allow a new start.' );
$recovered_state = $state_service->current();
rp_pickup_assert( 'queued' === (string) $recovered_state['status'] && $legacy_failed_id !== (string) $recovered_state['import_id'] && false === get_transient( RussianPostPickupImportLock::OPTION_NAME ), 'Legacy stale-lock recovery must replace the failed job with a newly owned queued import.' );
$importer->reset_stale_or_running_import();
$GLOBALS['wdc_scheduled_events'] = array();

$owned_failed_id = 'owned-failed-import';
$state_service->failed( array( 'import_id' => $owned_failed_id, 'type' => 'ALL', 'finished_at' => current_time( 'mysql' ), 'errors' => array( 'Injected terminal failure.' ) ) );
$owned_failed_lock = new RussianPostPickupImportLock( $GLOBALS['wpdb'] );
rp_pickup_assert( $owned_failed_lock->acquire( $owned_failed_id ), 'Owned stale-lock recovery fixture must acquire its failed job lock.' );
rp_pickup_assert( $importer->queue_background_import( 'ALL' ), 'A terminal failed job must release its matching owner lock and allow a new start.' );
$importer->reset_stale_or_running_import();
$GLOBALS['wdc_scheduled_events'] = array();

$race_lock = new RussianPostPickupImportLock( $GLOBALS['wpdb'] );
$new_owner = array( 'job_id' => 'new-job', 'token' => 'new-token', 'acquired_at' => time(), 'expires_at' => time() + 5 );
update_option( RussianPostPickupImportLock::OPTION_NAME, $new_owner, false );
$renewed_before = (int) $new_owner['expires_at'];
rp_pickup_assert( $race_lock->renew( 'new-job' ), 'The active owner must be able to renew its pipeline lease.' );
$new_owner = get_option( RussianPostPickupImportLock::OPTION_NAME, array() );
rp_pickup_assert( 'new-token' === (string) ( $new_owner['token'] ?? '' ) && (int) ( $new_owner['expires_at'] ?? 0 ) > $renewed_before, 'Lease renewal must preserve the owner token and extend expiry.' );
$race_lock->release( 'old-job' );
rp_pickup_assert( $new_owner === get_option( RussianPostPickupImportLock::OPTION_NAME, array() ), 'A late release from an old job must not remove the new job lock.' );
delete_option( RussianPostPickupImportLock::OPTION_NAME );

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

$expired_payload = tempnam( sys_get_temp_dir(), 'wdc-expired-owner-' );
$expired_staging = $repo->staging_table( 'expired-owner' );
$GLOBALS['wpdb']->tables[ $expired_staging ] = array( array( 'id' => 3 ) );
$expired_state = array_merge( $state_service->defaults(), array( 'status' => 'running', 'stage' => 'parse', 'import_id' => 'expired-owner-job', 'last_activity_at' => date( 'Y-m-d H:i:s', time() - 601 ), 'payload_file' => $expired_payload, 'staging_table' => $expired_staging, 'errors' => array() ) );
update_option( RussianPostPickupImportStateService::OPTION_NAME, $expired_state, false );
update_option( RussianPostPickupImportLock::OPTION_NAME, array( 'job_id' => 'expired-owner-job', 'token' => 'expired-token', 'acquired_at' => time() - 10801, 'expires_at' => time() - 1 ), false );
$expired_result = $importer->refresh_state_for_status();
rp_pickup_assert( 'failed' === (string) $expired_result['status'] && ! get_option( RussianPostPickupImportLock::OPTION_NAME, false ) && ! file_exists( $expired_payload ) && ! array_key_exists( $expired_staging, $GLOBALS['wpdb']->tables ), 'A truly stale running job with an expired owner lease must fail, cleanup, and release only its expired lease.' );

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
$cancel_batch_event = rp_shift_event( RussianPostPickupImporter::BATCH_HOOK );
rp_pickup_assert( array_key_exists( $cancel_state['staging_table'], $GLOBALS['wpdb']->tables ), 'Cancel test must have staging table before reset.' );
$cancelled_state = $importer->reset_stale_or_running_import();
rp_pickup_assert( 'failed' === (string) $cancelled_state['status'] && 'failed' === (string) $cancelled_state['stage'] && '' !== (string) $cancelled_state['finished_at'] && ! file_exists( (string) $cancel_state['payload_file'] ) && ! array_key_exists( $cancel_state['staging_table'], $GLOBALS['wpdb']->tables ) && ! $importer->is_locked(), 'Cancel/reset must persist terminal state, cleanup payload/staging, and release its lock.' );

$terminal_before_late_update = $state_service->current();
$state_service->update( 'upsert', array( 'import_id' => (string) $cancel_state['import_id'], 'payload_file' => (string) $cancel_state['payload_file'], 'parsed' => 999999 ) );
rp_pickup_assert( $terminal_before_late_update === $state_service->current(), 'A late in-flight checkpoint must not overwrite terminal cancel state or restore payload metadata.' );

$late_batch = $importer->run_import_batch( (string) $cancel_batch_event['args'][0], (string) $cancel_batch_event['args'][1], (int) $cancel_batch_event['args'][2] );
$late_finalize = $importer->run_import_finalize( (string) $cancel_state['import_id'], 'ALL' );
$terminal_after_late_callbacks = $state_service->current();
rp_pickup_assert( empty( $late_batch['success'] ) && empty( $late_finalize['success'] ) && 'failed' === (string) $terminal_after_late_callbacks['status'] && 'failed' === (string) $terminal_after_late_callbacks['stage'] && (string) $terminal_before_late_update['import_id'] === (string) $terminal_after_late_callbacks['import_id'] && (int) $terminal_before_late_update['parsed'] === (int) $terminal_after_late_callbacks['parsed'] && ! $importer->is_locked() && array() === $GLOBALS['wdc_scheduled_events'], 'Queued late batch/finalize callbacks after cancel must remain terminal, harmless, and schedule no continuation.' );

rp_pickup_assert( $importer->queue_background_import( 'ALL' ), 'A new import must start immediately after cancel without manual lock cleanup.' );
$new_after_cancel_state = $state_service->current();
$new_after_cancel_lock = get_option( RussianPostPickupImportLock::OPTION_NAME, array() );
$late_batch_thrown = false;
$late_finalize_thrown = false;
try {
	$importer->run_import_batch( (string) $cancel_state['import_id'], 'ALL', (int) ( $cancel_state['payload_offset'] ?? 0 ) );
} catch ( RuntimeException ) {
	$late_batch_thrown = true;
}
try {
	$importer->run_import_finalize( (string) $cancel_state['import_id'], 'ALL' );
} catch ( RuntimeException ) {
	$late_finalize_thrown = true;
}
$new_after_late_callbacks = $state_service->current();
rp_pickup_assert( $late_batch_thrown && $late_finalize_thrown && 'queued' === (string) $new_after_late_callbacks['status'] && (string) $new_after_cancel_state['import_id'] === (string) $new_after_late_callbacks['import_id'] && $new_after_cancel_lock === get_option( RussianPostPickupImportLock::OPTION_NAME, array() ), 'Late callbacks from the cancelled job must not mutate lifecycle state or unlock a new active owner.' );
$importer->reset_stale_or_running_import();
$GLOBALS['wdc_scheduled_events'] = array();

$orphan_job_id = 'terminal-orphan-lock';
$state_service->failed( array( 'import_id' => $orphan_job_id, 'type' => 'ALL', 'finished_at' => current_time( 'mysql' ), 'errors' => array( 'Injected terminal failure.' ) ) );
$orphan_lock = new RussianPostPickupImportLock( $GLOBALS['wpdb'] );
rp_pickup_assert( $orphan_lock->acquire( $orphan_job_id ), 'Terminal self-heal fixture must acquire a matching structured lock.' );
$orphan_refreshed = $importer->refresh_state_for_status();
rp_pickup_assert( 'failed' === (string) $orphan_refreshed['status'] && ! $importer->is_locked(), 'Status refresh must remove a matching structured orphan lock from terminal failed state.' );

$legacy_terminal_job = 'terminal-legacy-lock';
$state_service->failed( array( 'import_id' => $legacy_terminal_job, 'type' => 'ALL', 'finished_at' => current_time( 'mysql' ), 'errors' => array( 'Legacy terminal fixture.' ) ) );
set_transient( 'wdc_russian_post_pickup_import_lock', 1, 3600 );
$legacy_refreshed = $importer->refresh_state_for_status();
rp_pickup_assert( 'failed' === (string) $legacy_refreshed['status'] && false === get_transient( 'wdc_russian_post_pickup_import_lock' ), 'Status refresh must retain legacy scalar terminal-lock recovery.' );

$schedule_timezone = new WallsShop\WDC\Calendar\Services\TimezoneService();
$pickup_schedule = new RussianPostPickupSchedule( $settings, $schedule_timezone );
$monday_before = new DateTimeImmutable( '2026-09-14 08:00:00', new DateTimeZone( 'Asia/Novosibirsk' ) );
$monday_after = new DateTimeImmutable( '2026-09-14 10:00:00', new DateTimeZone( 'Asia/Novosibirsk' ) );
rp_pickup_assert( '2026-09-14 09:00' === $schedule_timezone->format_timestamp( $pickup_schedule->next_timestamp( 1, '09:00', $monday_before ), 'Y-m-d H:i' ), 'Monday before target time must schedule today.' );
rp_pickup_assert( '2026-09-21 09:00' === $schedule_timezone->format_timestamp( $pickup_schedule->next_timestamp( 1, '09:00', $monday_after ), 'Y-m-d H:i' ), 'Monday after target time must schedule next Monday.' );
rp_pickup_assert( '2026-09-14 09:00' === $schedule_timezone->format_timestamp( $pickup_schedule->next_timestamp( 1, '09:00', new DateTimeImmutable( '2026-09-13 12:00:00', new DateTimeZone( 'Asia/Novosibirsk' ) ) ), 'Y-m-d H:i' ), 'Sunday must schedule the following Monday.' );
rp_pickup_assert( '2026-09-20 00:00' === $schedule_timezone->format_timestamp( $pickup_schedule->next_timestamp( 7, '00:00', $monday_before ), 'Y-m-d H:i' ), 'Target Sunday and midnight must be supported.' );
rp_pickup_assert( '2026-09-14 23:45' === $schedule_timezone->format_timestamp( $pickup_schedule->next_timestamp( 1, '23:45', $monday_before ), 'Y-m-d H:i' ), 'Last quarter-hour slot must be supported.' );
rp_pickup_assert( 96 === count( RussianPostPickupSchedule::time_options() ), 'Russian Post schedule must expose exactly 96 quarter-hour options.' );

$legacy_next = ( new DateTimeImmutable( '2026-09-16 03:37:00', new DateTimeZone( 'Asia/Novosibirsk' ) ) )->getTimestamp();
$GLOBALS['wdc_recurring_events'][ RussianPostPickupImporter::SCHEDULE_HOOK ] = array( 'timestamp' => $legacy_next, 'recurrence' => 'weekly', 'hook' => RussianPostPickupImporter::SCHEDULE_HOOK );
$settings->save_from_admin( array( 'russian_post_otpravka_login' => 'login', 'russian_post_pickup_schedule_enabled' => '1' ) );
$importer->sync_schedule();
rp_pickup_assert( $legacy_next === wp_next_scheduled( RussianPostPickupImporter::SCHEDULE_HOOK ), 'Existing weekly event must remain unchanged while explicit weekday/time settings are absent.' );

$settings->save_from_admin( array( 'wdc_delivery_services_action' => 'save_russian_post_pickup', 'russian_post_pickup_schedule_enabled' => '1', 'russian_post_pickup_schedule_weekday' => '5', 'russian_post_pickup_schedule_time' => '18:15' ) );
$importer->sync_schedule();
$configured_next = (int) wp_next_scheduled( RussianPostPickupImporter::SCHEDULE_HOOK );
rp_pickup_assert( '5 18:15' === $schedule_timezone->format_timestamp( $configured_next, 'N H:i' ) && 'weekly' === ( $GLOBALS['wdc_recurring_events'][ RussianPostPickupImporter::SCHEDULE_HOOK ]['recurrence'] ?? '' ), 'Explicit Friday 18:15 schedule must replace the legacy event.' );
$importer->sync_schedule();
rp_pickup_assert( $configured_next === wp_next_scheduled( RussianPostPickupImporter::SCHEDULE_HOOK ), 'Repeated schedule sync must not create or move an already matching event.' );
$settings->save_from_admin( array( 'wdc_delivery_services_action' => 'save_russian_post_pickup', 'russian_post_pickup_schedule_enabled' => '1', 'russian_post_pickup_schedule_weekday' => '2', 'russian_post_pickup_schedule_time' => '18:15' ) );
$importer->sync_schedule();
$weekday_changed_next = (int) wp_next_scheduled( RussianPostPickupImporter::SCHEDULE_HOOK );
rp_pickup_assert( $weekday_changed_next !== $configured_next && '2 18:15' === $schedule_timezone->format_timestamp( $weekday_changed_next, 'N H:i' ), 'Changing weekday must replace the old event with exactly one matching weekly event.' );
$settings->save_from_admin( array( 'wdc_delivery_services_action' => 'save_russian_post_pickup', 'russian_post_pickup_schedule_enabled' => '1', 'russian_post_pickup_schedule_weekday' => '2', 'russian_post_pickup_schedule_time' => '03:30' ) );
$importer->sync_schedule();
$time_changed_next = (int) wp_next_scheduled( RussianPostPickupImporter::SCHEDULE_HOOK );
rp_pickup_assert( $time_changed_next !== $weekday_changed_next && '2 03:30' === $schedule_timezone->format_timestamp( $time_changed_next, 'N H:i' ), 'Changing time must replace the old event with exactly one matching weekly event.' );
$settings->save_from_admin( array( 'wdc_delivery_services_action' => 'save_russian_post_pickup', 'russian_post_pickup_schedule_enabled' => '1', 'russian_post_pickup_schedule_weekday' => '5', 'russian_post_pickup_schedule_time' => '10:07' ) );
rp_pickup_assert( '03:30' === $settings->schedule_time(), 'Invalid non-quarter-hour input must preserve the current valid setting.' );

$settings->save_from_admin( array( 'wdc_delivery_services_action' => 'save_russian_post_pickup', 'russian_post_otpravka_login' => 'login', 'russian_post_pickup_schedule_enabled' => '', 'russian_post_pickup_schedule_weekday' => '5', 'russian_post_pickup_schedule_time' => '18:15' ) );
$importer->sync_schedule();
rp_pickup_assert( ! isset( $GLOBALS['wdc_recurring_events'][ RussianPostPickupImporter::SCHEDULE_HOOK ] ), 'Schedule disabled must clear weekly import.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
rp_pickup_assert( str_contains( $admin_source, 'rows_inserted_to_staging' ) && str_contains( $admin_source, 'staging_table' ) && str_contains( $admin_source, 'upload_russian_post_pickup_file_import' ) && str_contains( $admin_source, 'accept=".zip,.txt,.json"' ) && str_contains( $admin_source, 'ВАШ_ACCESS_TOKEN' ) && str_contains( $admin_source, 'Expand-Archive' ), 'Admin status output must include staging metrics and unified ZIP/TXT/JSON upload instructions.' );
rp_pickup_assert( str_contains( $admin_source, '<details><summary data-wdc-rp-status-summary>' ) && str_contains( $admin_source, 'data-wdc-rp-status="' ) && str_contains( $admin_source, 'Статус: %s; этап: %s; обработано: %d; записано: %d' ), 'Admin import status block must render collapsible status markup with compact summary.' );
rp_pickup_assert( str_contains( $admin_source, 'wp_next_scheduled( RussianPostPickupImporter::SCHEDULE_HOOK )' ) && str_contains( $admin_source, 'Расписание включено, но следующий запуск пока не запланирован.' ), 'Admin UI must show next scheduled weekly import or a warning when missing.' );
rp_pickup_assert( str_contains( $admin_source, 'russian_post_otpravka_timeout' ) && str_contains( $admin_source, 'Таймаут API, сек.' ), 'Admin timeout field must keep Russian label and remain visible.' );
rp_pickup_assert( ! str_contains( $admin_source, 'russian_post_otpravka_basic_key' ) && ! str_contains( $admin_source, 'Basic key' ) && ! str_contains( $admin_source, 'BasicKey' ), 'Admin UI must not render a Basic key field.' );
rp_pickup_assert( str_contains( $admin_source, 'Автоматическая загрузка из API' ) && str_contains( $admin_source, 'Загруженный ZIP' ) && str_contains( $admin_source, 'Загруженный TXT/JSON' ), 'Admin status values must be localized.' );
rp_pickup_assert( ! str_contains( $admin_source, 'Временный журнал блокировки' ) && ! str_contains( $admin_source, 'Профилирование batch' ), 'Temporary Russian Post forensic/profiling UI must be removed after production acceptance.' );
rp_pickup_assert( str_contains( $admin_source, 'День недели' ) && str_contains( $admin_source, 'Время Новосибирска (GMT+7)' ), 'Russian Post admin must expose explicit weekly weekday and quarter-hour time controls.' );

$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/russian-post-pickup-import.js' );
rp_pickup_assert( str_contains( $js_source, 'data-wdc-rp-status-summary' ) && str_contains( $js_source, 'Автоматическая загрузка из API' ) && str_contains( $js_source, 'Не удалось поставить импорт в очередь. Возможно, уже выполняется другой импорт.' ), 'Status polling JS must update the collapsed summary and render localized status values/messages.' );

$legacy_profile_state = array_merge( $state_service->defaults(), array( 'last_batch_profile' => array( 'total_batch_ms' => 1 ), 'batch_profile_aggregate' => array( 'total_profiled_batches' => 1 ), 'slow_batch_profiles' => array( array( 'total_batch_ms' => 10001 ) ) ) );
update_option( RussianPostPickupImportStateService::OPTION_NAME, $legacy_profile_state, false );
$state_service->queue( 'ALL', 'post-profiler-normalization' );
$normalized_state = get_option( RussianPostPickupImportStateService::OPTION_NAME, array() );
rp_pickup_assert( ! array_key_exists( 'last_batch_profile', $normalized_state ) && ! array_key_exists( 'batch_profile_aggregate', $normalized_state ) && ! array_key_exists( 'slow_batch_profiles', $normalized_state ), 'The next normal state save must discard retired profiler fields without a migration.' );

delete_option( RussianPostPickupImportLock::OPTION_NAME );

echo "Russian Post pickup import smoke test passed.\n";
