<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function pickup_diagnostics_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string {
	return 'Y-m-d' === $type ? '2026-06-02' : '2026-06-02 12:00:00';
}

function sanitize_key( mixed $value ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ) ?? '';
}

function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
	return json_encode( $value, $flags );
}

function __( string $text, string $domain = '' ): string {
	return $text;
}

function current_user_can( string $capability ): bool {
	return in_array( $capability, array( 'manage_options', 'manage_woocommerce' ), true );
}

function wp_verify_nonce( string $nonce, string $action ): bool {
	return 'test-nonce' === $nonce && 'wdc_russian_post_pickup_diagnostics_rebind' === $action;
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $russian_post_pickup_rows = array();
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<string,bool> */
		public array $existing_tables = array();
		/** @var array<string,array<string,bool>> */
		public array $columns = array();
		/** @var array<string,array<string,bool>> */
		public array $indexes = array();
		/** @var array<int,string> */
		public array $queries = array();
		public int $suppress_errors_calls = 0;
		public string $schema_return_mode = 'string';
		public string $last_error = '';

		public function get_charset_collate(): string {
			return 'DEFAULT CHARSET=utf8mb4';
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $value, $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function get_row( string $query, mixed $output = null ): mixed {
			if ( preg_match( "/SHOW COLUMNS FROM ([A-Za-z0-9_]+) LIKE '([^']+)'/", $query, $m ) ) {
				return ! empty( $this->columns[ $m[1] ][ $m[2] ] ) ? $this->schema_row( $m[2], 'Field' ) : null;
			}
			if ( preg_match( "/SHOW INDEX FROM ([A-Za-z0-9_]+) WHERE Key_name = '([^']+)'/", $query, $m ) ) {
				return ! empty( $this->indexes[ $m[1] ][ $m[2] ] ) ? $this->schema_row( $m[2], 'Key_name' ) : null;
			}
			if ( preg_match( '/WHERE l?\.?id = ([0-9]+)/', $query, $m ) ) {
				foreach ( $this->locations as $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === (int) $m[1] ) {
						return $row;
					}
				}
			}
			if ( preg_match( "/fias_id = '([^']+)'/", $query, $m ) ) {
				foreach ( $this->locations as $row ) {
					if ( (string) ( $row['fias_id'] ?? '' ) === $m[1] ) {
						return $row;
					}
				}
			}
			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			return array();
		}

		public function get_var( string $query ): mixed {
			if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $m ) ) {
				return ! empty( $this->existing_tables[ $m[1] ] ) ? $m[1] : null;
			}
			if ( preg_match( "/SHOW COLUMNS FROM ([A-Za-z0-9_]+) LIKE '([^']+)'/", $query, $m ) ) {
				return ! empty( $this->columns[ $m[1] ][ $m[2] ] ) ? $this->schema_value( $m[2], 'Field' ) : null;
			}
			if ( preg_match( "/SHOW INDEX FROM ([A-Za-z0-9_]+) WHERE Key_name = '([^']+)'/", $query, $m ) ) {
				return ! empty( $this->indexes[ $m[1] ][ $m[2] ] ) ? $this->schema_value( $m[2], 'Key_name' ) : null;
			}

			return 0;
		}

		public function query( string $query ): int|bool {
			$this->queries[] = $query;
			if ( preg_match( '/ALTER TABLE ([A-Za-z0-9_]+) ADD COLUMN ([A-Za-z0-9_]+)/', $query, $m ) ) {
				$this->columns[ $m[1] ][ $m[2] ] = true;
				return true;
			}
			if ( preg_match( '/ALTER TABLE ([A-Za-z0-9_]+) ADD KEY ([A-Za-z0-9_]+)/', $query, $m ) ) {
				$this->indexes[ $m[1] ][ $m[2] ] = true;
				return true;
			}
			if ( preg_match( '/ALTER TABLE ([A-Za-z0-9_]+) DROP COLUMN ([A-Za-z0-9_]+)/', $query, $m ) ) {
				unset( $this->columns[ $m[1] ][ $m[2] ] );
				return true;
			}

			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			return true;
		}

		public function suppress_errors( bool $suppress = true ): bool {
			++$this->suppress_errors_calls;
			return false;
		}

		private function schema_value( string $value, string $key ): mixed {
			if ( 'object' === $this->schema_return_mode ) {
				return (object) array( $key => $value );
			}
			if ( 'array' === $this->schema_return_mode ) {
				return array( $key => $value );
			}

			return $value;
		}

		private function schema_row( string $value, string $key ): mixed {
			if ( 'object' === $this->schema_return_mode ) {
				return (object) array( $key => $value );
			}

			return array( $key => $value );
		}
	}
}

use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupDiagnosticsService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;

$GLOBALS['wpdb'] = new wpdb();

$migration_0022 = require dirname( __DIR__, 2 ) . '/database/migrations/0022_add_russian_post_pickup_location_id.php';
$migration_0023 = require dirname( __DIR__, 2 ) . '/database/migrations/0023_drop_unused_locations_postcode.php';
$pickup_table = 'wp_wdc_pickup_points_russian_post';
$locations_table = 'wp_wdc_locations';

$migration_0022();
pickup_diagnostics_assert( array() === $GLOBALS['wpdb']->queries, 'Migration 0022 must do nothing safely if Russian Post pickup table is missing.' );
pickup_diagnostics_assert( 0 === $GLOBALS['wpdb']->suppress_errors_calls, 'Migration 0022 must not call suppress_errors().' );

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->existing_tables[ $pickup_table ] = true;
$migration_0022();
pickup_diagnostics_assert( ! empty( $GLOBALS['wpdb']->columns[ $pickup_table ]['location_id'] ), 'Migration 0022 must add location_id if column is absent.' );
pickup_diagnostics_assert( ! empty( $GLOBALS['wpdb']->indexes[ $pickup_table ]['idx_location_id'] ), 'Migration 0022 must add idx_location_id if index is absent.' );

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->existing_tables[ $pickup_table ] = true;
$GLOBALS['wpdb']->columns[ $pickup_table ]['location_id'] = true;
$migration_0022();
$add_column_queries = array_values( array_filter( $GLOBALS['wpdb']->queries, static fn( string $query ): bool => str_contains( $query, 'ADD COLUMN location_id' ) ) );
pickup_diagnostics_assert( array() === $add_column_queries, 'Migration 0022 must skip ADD COLUMN when location_id exists.' );
pickup_diagnostics_assert( ! empty( $GLOBALS['wpdb']->indexes[ $pickup_table ]['idx_location_id'] ), 'Migration 0022 must still add idx_location_id when column exists and index is absent.' );

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->schema_return_mode = 'object';
$GLOBALS['wpdb']->existing_tables[ $pickup_table ] = true;
$GLOBALS['wpdb']->columns[ $pickup_table ]['location_id'] = true;
$GLOBALS['wpdb']->indexes[ $pickup_table ]['idx_location_id'] = true;
$migration_0022();
pickup_diagnostics_assert( array() === $GLOBALS['wpdb']->queries, 'Migration 0022 must skip ADD KEY when idx_location_id exists and handle object-like SHOW results.' );

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->schema_return_mode = 'array';
$GLOBALS['wpdb']->existing_tables[ $locations_table ] = true;
$GLOBALS['wpdb']->columns[ $locations_table ]['postcode'] = true;
$migration_0023();
pickup_diagnostics_assert( empty( $GLOBALS['wpdb']->columns[ $locations_table ]['postcode'] ) && ! empty( $GLOBALS['wpdb']->columns[ $locations_table ]['postal_code'] ), 'Migration 0023 must create postal_code for old schemas before dropping legacy postcode.' );
$old_schema_queries = implode( "\n", $GLOBALS['wpdb']->queries );
pickup_diagnostics_assert( str_contains( $old_schema_queries, 'ADD COLUMN postal_code' ) && str_contains( $old_schema_queries, 'UPDATE wp_wdc_locations SET postal_code = postcode' ) && str_contains( $old_schema_queries, 'DROP COLUMN postcode' ), 'Migration 0023 must add postal_code, preserve postcode values, then drop legacy postcode.' );

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->existing_tables[ $locations_table ] = true;
$GLOBALS['wpdb']->columns[ $locations_table ]['postal_code'] = true;
$GLOBALS['wpdb']->columns[ $locations_table ]['postcode'] = true;
$migration_0023();
pickup_diagnostics_assert( empty( $GLOBALS['wpdb']->columns[ $locations_table ]['postcode'] ) && ! empty( $GLOBALS['wpdb']->columns[ $locations_table ]['postal_code'] ), 'Migration 0023 must drop postcode when postal_code and postcode both exist.' );

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->existing_tables[ $locations_table ] = true;
$GLOBALS['wpdb']->columns[ $locations_table ]['postal_code'] = true;
$migration_0023();
pickup_diagnostics_assert( ! empty( $GLOBALS['wpdb']->columns[ $locations_table ]['postal_code'] ) && ! str_contains( implode( "\n", $GLOBALS['wpdb']->queries ), 'DROP COLUMN postcode' ), 'Migration 0023 must do nothing destructive when only postal_code exists.' );

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 1, 'fias_id' => '11111111-1111-1111-1111-111111111111', 'postal_code' => '630000', 'region_name' => 'Novosibirsk region', 'city_name' => 'Novosibirsk', 'settlement_name' => 'Novosibirsk', 'display_name' => 'Novosibirsk', 'latitude' => 55.0302, 'longitude' => 82.9204, 'active' => 1, 'country_code' => 'RU', 'searchable_text' => 'Novosibirsk region Novosibirsk' ),
	array( 'id' => 2, 'fias_id' => '22222222-2222-2222-2222-222222222222', 'postal_code' => '620000', 'region_name' => 'Sverdlovsk region', 'city_name' => 'Ekaterinburg', 'settlement_name' => 'Ekaterinburg', 'display_name' => 'Ekaterinburg', 'latitude' => 56.8389, 'longitude' => 60.6057, 'active' => 1, 'country_code' => 'RU', 'searchable_text' => 'Sverdlovsk region Ekaterinburg' ),
	array( 'id' => 3, 'fias_id' => '33333333-3333-3333-3333-333333333333', 'postal_code' => '123456', 'region_name' => 'Ambiguous region', 'city_name' => 'One', 'settlement_name' => 'One', 'display_name' => 'One', 'latitude' => 55.0, 'longitude' => 83.0, 'active' => 1, 'country_code' => 'RU', 'searchable_text' => 'Ambiguous region One' ),
	array( 'id' => 4, 'fias_id' => '44444444-4444-4444-4444-444444444444', 'postal_code' => '123456', 'region_name' => 'Ambiguous region', 'city_name' => 'Two', 'settlement_name' => 'Two', 'display_name' => 'Two', 'latitude' => 55.1, 'longitude' => 83.1, 'active' => 1, 'country_code' => 'RU', 'searchable_text' => 'Ambiguous region Two' ),
);
$GLOBALS['wpdb']->russian_post_pickup_rows = array(
	array( 'id' => 1, 'point_code' => 'OK-1', 'postcode' => '630000', 'region_name' => 'Novosibirsk region', 'city_name' => 'Novosibirsk', 'address' => 'Lenina 1', 'fias_location_guid' => '11111111-1111-1111-1111-111111111111', 'location_id' => 1, 'latitude' => 55.031, 'longitude' => 82.921, 'active' => 1, 'updated_at' => '2026-06-02 10:00:00', 'last_seen_at' => '2026-06-02 09:00:00' ),
	array( 'id' => 2, 'point_code' => 'MISS-COORD', 'postcode' => '630000', 'region_name' => 'Novosibirsk region', 'city_name' => 'Novosibirsk', 'address' => 'A', 'fias_location_guid' => '11111111-1111-1111-1111-111111111111', 'location_id' => 1, 'latitude' => null, 'longitude' => 82.9, 'active' => 1 ),
	array( 'id' => 3, 'point_code' => 'ZERO', 'postcode' => '630000', 'region_name' => 'Novosibirsk region', 'city_name' => 'Novosibirsk', 'address' => 'B', 'fias_location_guid' => '11111111-1111-1111-1111-111111111111', 'location_id' => 1, 'latitude' => 0, 'longitude' => 0, 'active' => 1 ),
	array( 'id' => 4, 'point_code' => 'NO-FIAS', 'postcode' => '630000', 'region_name' => 'Novosibirsk region', 'city_name' => 'Novosibirsk', 'address' => 'C', 'fias_location_guid' => '', 'location_id' => 1, 'latitude' => 55.03, 'longitude' => 82.92, 'active' => 1 ),
	array( 'id' => 5, 'point_code' => 'NO-PC', 'postcode' => '', 'region_name' => 'Novosibirsk region', 'city_name' => 'Novosibirsk', 'address' => 'D', 'fias_location_guid' => '11111111-1111-1111-1111-111111111111', 'location_id' => 1, 'latitude' => 55.03, 'longitude' => 82.92, 'active' => 1 ),
	array( 'id' => 6, 'point_code' => 'DUMMY', 'postcode' => '999999999', 'region_name' => 'Novosibirsk region', 'city_name' => 'Novosibirsk', 'address' => 'E', 'fias_location_guid' => '11111111-1111-1111-1111-111111111111', 'location_id' => 1, 'latitude' => 55.03, 'longitude' => 82.92, 'active' => 1 ),
	array( 'id' => 7, 'point_code' => 'NO-ADDR', 'postcode' => '630000', 'region_name' => 'Novosibirsk region', 'city_name' => 'Novosibirsk', 'address' => '', 'fias_location_guid' => '11111111-1111-1111-1111-111111111111', 'location_id' => 1, 'latitude' => 55.03, 'longitude' => 82.92, 'active' => 1 ),
	array( 'id' => 8, 'point_code' => 'NO-CITY', 'postcode' => '630000', 'region_name' => 'Novosibirsk region', 'city_name' => '', 'address' => 'F', 'fias_location_guid' => '11111111-1111-1111-1111-111111111111', 'location_id' => 1, 'latitude' => 55.03, 'longitude' => 82.92, 'active' => 1 ),
	array( 'id' => 9, 'point_code' => 'NO-REGION', 'postcode' => '630000', 'region_name' => '', 'city_name' => 'Novosibirsk', 'address' => 'G', 'fias_location_guid' => '11111111-1111-1111-1111-111111111111', 'location_id' => 1, 'latitude' => 55.03, 'longitude' => 82.92, 'active' => 1 ),
	array( 'id' => 10, 'point_code' => 'NO-LOC-FIAS', 'postcode' => '620000', 'region_name' => 'Sverdlovsk region', 'city_name' => 'Ekaterinburg', 'address' => 'H', 'fias_location_guid' => '22222222-2222-2222-2222-222222222222', 'location_id' => 0, 'latitude' => 56.84, 'longitude' => 60.61, 'active' => 1 ),
	array( 'id' => 11, 'point_code' => 'FAR', 'postcode' => '630000', 'region_name' => 'Novosibirsk region', 'city_name' => 'Novosibirsk', 'address' => 'I', 'fias_location_guid' => '11111111-1111-1111-1111-111111111111', 'location_id' => 1, 'latitude' => 59.93, 'longitude' => 30.31, 'active' => 1 ),
	array( 'id' => 12, 'point_code' => 'AMBIG', 'postcode' => '123456', 'region_name' => 'Ambiguous region', 'city_name' => 'Unknown', 'address' => 'J', 'fias_location_guid' => '', 'location_id' => 0, 'latitude' => 55.0, 'longitude' => 83.0, 'active' => 1 ),
	array( 'id' => 13, 'point_code' => 'NO-MATCH', 'postcode' => '000000', 'region_name' => 'Nowhere', 'city_name' => 'None', 'address' => 'K, quoted "value"', 'fias_location_guid' => '', 'location_id' => 0, 'latitude' => 55.0, 'longitude' => 83.0, 'active' => 1 ),
	array( 'id' => 14, 'point_code' => 'INACTIVE', 'postcode' => '630000', 'region_name' => 'Novosibirsk region', 'city_name' => 'Novosibirsk', 'address' => 'L', 'fias_location_guid' => '11111111-1111-1111-1111-111111111111', 'location_id' => 1, 'latitude' => 55.03, 'longitude' => 82.92, 'active' => 0 ),
);

$service = new RussianPostPickupDiagnosticsService(
	new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ),
	new LocationRepository( $GLOBALS['wpdb'] ),
	$GLOBALS['wpdb']
);

$summary = $service->summary();
pickup_diagnostics_assert( 14 === $summary['total'] && 13 === $summary['active'], 'summary must count total and active Russian Post pickup rows.' );
pickup_diagnostics_assert( 1 === $summary['missing_coordinates'], 'summary must catch null coordinates.' );
pickup_diagnostics_assert( 1 === $summary['zero_coordinates'], 'summary must catch zero coordinates.' );
pickup_diagnostics_assert( 3 === $summary['missing_fias'], 'summary must catch empty fias_location_guid.' );
pickup_diagnostics_assert( 1 === $summary['missing_postal_code'], 'summary must catch empty postal code.' );
pickup_diagnostics_assert( 1 === $summary['dummy_postal_code'], 'summary must catch 999999999 postal code.' );
pickup_diagnostics_assert( 1 === $summary['missing_address'], 'summary must catch empty address.' );
pickup_diagnostics_assert( 1 === $summary['missing_city'], 'summary must catch empty city.' );
pickup_diagnostics_assert( 1 === $summary['missing_region'], 'summary must catch empty region.' );
pickup_diagnostics_assert( 3 === $summary['missing_location'], 'summary must catch missing location_id.' );
pickup_diagnostics_assert( null === $summary['suspicious_coordinates'] && 'filter_only' === $summary['suspicious_coordinates_note'], 'summary must not calculate expensive suspicious coordinates on default diagnostics page.' );

$all = $service->list_problematic( 'all_problematic', 1, 100 );
$all_codes = array_column( $all['items'], 'point_code' );
pickup_diagnostics_assert( ! in_array( 'OK-1', $all_codes, true ), 'normal pickup point must not be listed as problematic.' );
pickup_diagnostics_assert( ! in_array( 'FAR', $all_codes, true ), 'all_problematic must not include suspicious-only rows by default.' );
pickup_diagnostics_assert( in_array( 'MISS-COORD', array_column( $service->list_problematic( 'missing_coordinates', 1, 50 )['items'], 'point_code' ), true ), 'list_problematic must respect missing_coordinates filter.' );
pickup_diagnostics_assert( in_array( 'FAR', array_column( $service->list_problematic( 'suspicious_coordinates', 1, 50 )['items'], 'point_code' ), true ), 'list_problematic must respect suspicious_coordinates filter.' );
pickup_diagnostics_assert( 2 === count( $service->list_problematic( 'all_problematic', 1, 2 )['items'] ) && 2 === count( $service->list_problematic( 'all_problematic', 2, 2 )['items'] ), 'list_problematic must paginate.' );

$csv = $service->export_csv( 'missing_location' );
pickup_diagnostics_assert( str_starts_with( $csv, 'ID,point_code,postal_code,region,city,address,fias_location_guid,location_id,lat,lng,problem_flags,distance_to_location_km,updated_at,imported_at' ), 'CSV export must contain expected headers.' );
pickup_diagnostics_assert( str_contains( $csv, '"K, quoted ""value"""' ), 'CSV export must escape commas and quotes.' );
pickup_diagnostics_assert( 'wdc-russian-post-pickup-diagnostics-2026-06-02.csv' === $service->filename(), 'CSV filename must include current date.' );

$dry_run = $service->rebind_dry_run( 'missing_location' );
pickup_diagnostics_assert( 1 === $dry_run['planned'], 'rebind dry-run must find unique fias match.' );
pickup_diagnostics_assert( 1 === $dry_run['skipped']['ambiguous'], 'rebind dry-run must skip ambiguous postal_code.' );
pickup_diagnostics_assert( 1 === $dry_run['skipped']['no_match'], 'rebind dry-run must skip no match.' );
pickup_diagnostics_assert( 0 === (int) $GLOBALS['wpdb']->russian_post_pickup_rows[9]['location_id'], 'dry-run must not write location_id.' );

$admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Admin/PickupAdminPage.php' );
pickup_diagnostics_assert( str_contains( $admin_source, "wp_verify_nonce" ) && str_contains( $admin_source, "current_user_can( 'manage_options' )" ) && str_contains( $admin_source, 'method="post"' ), 'admin rebind action must require POST, nonce, and manage_options capability.' );

$diagnostics_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/RussianPost/RussianPostPickupDiagnosticsService.php' );
$summary_source = substr( $diagnostics_source, (int) strpos( $diagnostics_source, 'public function summary' ), 1400 );
pickup_diagnostics_assert( ! str_contains( $summary_source, 'count_joined_problem_sql' ) && ! str_contains( $summary_source, 'count_suspicious_sql' ) && str_contains( $summary_source, "'suspicious_coordinates' => null" ), 'summary() must use cheap counters only and skip joined suspicious count.' );
$problem_where_source = substr( $diagnostics_source, (int) strpos( $diagnostics_source, 'private function problem_where_sql' ), 2400 );
pickup_diagnostics_assert( str_contains( $problem_where_source, 'default => $cheap' ), 'all_problematic WHERE must use cheap checks only.' );
$suspicious_where_source = substr( $problem_where_source, (int) strpos( $problem_where_source, "'suspicious_coordinates'" ), 420 );
pickup_diagnostics_assert( str_contains( $suspicious_where_source, 'distance_sql' ), 'suspicious_coordinates filter must contain distance SQL.' );
$count_problem_source = substr( $diagnostics_source, (int) strpos( $diagnostics_source, 'private function count_problem_sql' ), 700 );
pickup_diagnostics_assert( ! str_contains( $count_problem_source, 'all_problematic' ) && ! str_contains( $count_problem_source, 'count_joined_problem_sql' ), 'list all_problematic must use cheap WHERE count only.' );
$select_source = substr( $diagnostics_source, (int) strpos( $diagnostics_source, 'private function select_sql' ), 700 );
pickup_diagnostics_assert( str_contains( $select_source, 'NULL AS distance_to_location_km' ) && str_contains( $select_source, 'if ( ! $with_location_distance )' ), 'default diagnostics page must list with cheap select only.' );
$locations_by_postcode_source = substr( $diagnostics_source, (int) strpos( $diagnostics_source, 'private function locations_by_postcode' ), 1200 );
pickup_diagnostics_assert( str_contains( $locations_by_postcode_source, 'postal_code' ) && ! str_contains( $locations_by_postcode_source, 'WHERE active = 1 AND postcode' ), 'diagnostics locations_by_postcode must use canonical locations.postal_code.' );

$locations_migration_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0002_create_locations_table.php' );
pickup_diagnostics_assert( str_contains( $locations_migration_source, 'postal_code varchar' ) && ! str_contains( $locations_migration_source, 'postcode varchar' ), 'fresh locations schema must create postal_code instead of legacy postcode.' );
$coordinate_enricher_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/Locations/LocationCoordinateEnricher.php' );
pickup_diagnostics_assert( ! str_contains( $coordinate_enricher_source, "\$location['postcode']" ) && ! str_contains( $coordinate_enricher_source, 'locations.postcode' ) && ! str_contains( $coordinate_enricher_source, 'l.postcode' ), 'Location code must not expect legacy locations.postcode.' );

$location_repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Locations/Storage/LocationRepository.php' );
$gar_import_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Locations/Import/GarPlacesCsvImporter.php' );
$fias_import_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Locations/Import/FiasImportManager.php' );
$snapshot_exporter_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Locations/Import/LocationsSnapshotExporter.php' );
$snapshot_importer_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Locations/Import/LocationsSnapshotImporter.php' );
pickup_diagnostics_assert(
	str_contains( $location_repository_source, 'bulk_save_aliases' )
	&& str_contains( $gar_import_source, 'bulk_save_aliases' )
	&& str_contains( $fias_import_source, 'save_aliases' )
	&& str_contains( $snapshot_exporter_source, "'wdc_location_aliases'" )
	&& str_contains( $snapshot_importer_source, "'wdc_location_aliases'" ),
	'audit must document actual wdc_location_aliases usage by imports, repository, and snapshots.'
);

echo "Pickup diagnostics smoke test passed.\n";
