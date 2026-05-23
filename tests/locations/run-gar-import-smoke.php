<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Locations\Admin\LocationsAdminPage;
use WallsShop\WDC\Locations\Import\GarPlacesCsvImporter;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Import\LocationsSnapshotExporter;
use WallsShop\WDC\Locations\Import\LocationsSnapshotImporter;
use WallsShop\WDC\Locations\Services\LocationAliasGenerator;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\Storage\RegionRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
$GLOBALS['wdc_gar_smoke_options'] = array();

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		public int $insert_id = 0;
		public array $locations = array();
		public array $regions = array();
		public array $stage = array();
		public array $stage_history = array();
		public array $aliases = array();
		public array $carrier_codes = array();
		public array $missing_tables = array();
		public array $stage_columns = array( 'region_code', 'region_name', 'region_type', 'region_fias_id', 'region_kladr_id', 'district_name', 'district_type', 'district_fias_id', 'district_kladr_id', 'district_gar_object_id', 'district_level', 'city_name', 'city_type', 'city_fias_id', 'city_kladr_id', 'place_name', 'place_type', 'place_level', 'display_name', 'fias_id', 'gar_object_id', 'kladr_id', 'okato', 'oktmo', 'postal_code' );
		public array $location_columns = array( 'id', 'gar_object_id', 'fias_id', 'kladr_id', 'gar_id', 'country_code', 'region_name', 'region_code', 'region_type', 'district_name', 'district_type', 'district_fias_id', 'district_kladr_id', 'district_gar_object_id', 'district_level', 'city_name', 'city_type', 'city_fias_id', 'city_kladr_id', 'settlement_name', 'settlement_type', 'place_name', 'place_type', 'place_level', 'display_name', 'postal_code', 'okato', 'oktmo', 'latitude', 'longitude', 'searchable_text', 'active', 'created_at', 'updated_at' );
		public array $indexes = array();
		public bool $force_sql_bulk = false;
		public bool $fail_next_query = false;
		public bool $fail_bulk_insert = false;
		public string $last_error = '';

		public function prepare( string $query, mixed ...$args ): array {
			return array( 'query' => $query, 'args' => $args );
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function insert( string $table, array $data, ?array $format = null ): int {
			if ( str_contains( $table, 'wdc_gar_places_stage' ) ) {
				$this->stage[] = $data;
				$this->stage_history[] = $data;
				return 1;
			}

			if ( str_contains( $table, 'wdc_regions' ) ) {
				$this->regions[ (string) $data['region_code'] ] = $data;
				return 1;
			}

			if ( str_contains( $table, 'wdc_location_aliases' ) ) {
				++$this->insert_id;
				$data['id'] = $this->insert_id;
				$this->aliases[ $this->insert_id ] = $data;
				return 1;
			}

			if ( str_contains( $table, 'wdc_location_carrier_codes' ) ) {
				++$this->insert_id;
				$data['id'] = $this->insert_id;
				$this->carrier_codes[ $this->insert_id ] = $data;
				return 1;
			}

			++$this->insert_id;
			$data['id'] = $this->insert_id;
			$this->locations[ $this->insert_id ] = $data;
			return 1;
		}

		public function update( string $table, array $data, array $where, array $format, array $where_format ): int {
			if ( str_contains( $table, 'wdc_regions' ) ) {
				$code = (string) ( $where['region_code'] ?? '' );
				$this->regions[ $code ] = array_merge( $this->regions[ $code ] ?? array(), $data );
				return 1;
			}

			$id = (int) ( $where['id'] ?? 0 );
			if ( isset( $this->locations[ $id ] ) ) {
				$this->locations[ $id ] = array_merge( $this->locations[ $id ], $data );
				$this->locations[ $id ]['id'] = $id;
				return 1;
			}

			return 0;
		}

		public function get_row( array $prepared, string $output ): ?array {
			$query = $prepared['query'];
			$value = (string) ( $prepared['args'][0] ?? '' );
			if ( str_contains( $query, 'FROM wdc_regions' ) ) {
				return $this->regions[ $value ] ?? null;
			}

			foreach ( $this->locations as $row ) {
				if ( str_contains( $query, 'gar_object_id' ) && (int) $row['gar_object_id'] === (int) $value ) {
					return $this->join_region( $row );
				}
				if ( str_contains( $query, 'fias_id' ) && (string) $row['fias_id'] === $value ) {
					return $this->join_region( $row );
				}
				if ( str_contains( $query, 'kladr_id' ) && (string) $row['kladr_id'] === $value ) {
					return $this->join_region( $row );
				}
				if ( ( str_contains( $query, 'WHERE l.id' ) || str_contains( $query, 'WHERE id =' ) ) && (int) $row['id'] === (int) $value ) {
					return $this->join_region( $row );
				}
			}

			return null;
		}

		public function get_results( mixed $query, string $output ): array {
			$sql = is_array( $query ) ? $query['query'] : (string) $query;
			$args = is_array( $query ) ? $query['args'] : array();

			if ( str_starts_with( $sql, 'SHOW COLUMNS FROM' ) ) {
				$parts = preg_split( '/\s+/', $sql );
				$table = (string) ( $parts[3] ?? '' );
				$column = (string) ( $args[0] ?? '' );
				$columns = $this->columns_for( $table );
				if ( '' !== $column ) {
					return in_array( $column, $columns, true ) ? array( array( 'Field' => $column ) ) : array();
				}

				return array_map( static fn( string $field ): array => array( 'Field' => $field ), $columns );
			}

			if ( str_starts_with( $sql, 'SHOW INDEX FROM' ) ) {
				$parts = preg_split( '/\s+/', $sql );
				$table = (string) ( $parts[3] ?? '' );
				$index = (string) ( $args[0] ?? '' );
				return in_array( $index, $this->indexes[ $table ] ?? array(), true ) ? array( array( 'Key_name' => $index ) ) : array();
			}

			if ( str_starts_with( $sql, 'DESCRIBE' ) ) {
				$table = trim( substr( $sql, 9 ) );
				return array_map( static fn( string $field ): array => array( 'Field' => $field ), $this->columns_for( $table ) );
			}

			if ( str_contains( $sql, 'GROUP BY region_code' ) ) {
				$regions = array();
				foreach ( $this->stage as $row ) {
					$code = (string) $row['region_code'];
					$regions[ $code ] = array(
						'region_code' => $code,
						'region_name' => $row['region_name'],
						'region_type' => $row['region_type'],
						'region_fias_id' => $row['region_fias_id'],
						'region_kladr_id' => $row['region_kladr_id'],
					);
				}
				ksort( $regions );
				return array_values( $regions );
			}

			if ( str_contains( $sql, 'FROM wdc_gar_places_stage' ) ) {
				return array_slice( $this->stage, (int) ( $args[1] ?? 0 ), (int) ( $args[0] ?? 1000 ) );
			}

			if ( str_contains( $sql, 'searchable_text LIKE' ) ) {
				$needle = trim( (string) ( $args[0] ?? '' ), '%' );
				$limit = (int) ( $args[1] ?? 100 );
				$rows = array_filter( $this->locations, static fn( array $row ): bool => 1 === (int) $row['active'] && str_contains( (string) $row['searchable_text'], $needle ) );
				$rows = array_map( fn( array $row ): array => $this->join_region( $row ), array_values( $rows ) );
				usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) $a['display_name'], (string) $b['display_name'] ) );
				return array_slice( $rows, 0, $limit );
			}

			if ( preg_match( '/FROM (wdc_[a-z_]+)/', $sql, $match ) ) {
				$rows = $this->rows_for_table( $match[1] );
				return array_slice( array_values( $rows ), (int) ( $args[1] ?? 0 ), (int) ( $args[0] ?? 1000 ) );
			}

			return array();
		}

		public function get_var( mixed $query ): int|string|null {
			$sql = is_array( $query ) ? $query['query'] : (string) $query;
			if ( str_contains( $sql, 'SHOW TABLES LIKE' ) ) {
				$table = is_array( $query ) ? (string) $query['args'][0] : '';
				return in_array( $table, $this->missing_tables, true ) ? 0 : $table;
			}
			if ( str_contains( $sql, 'wdc_regions' ) ) {
				return count( $this->regions );
			}
			if ( str_contains( $sql, 'wdc_location_aliases' ) ) {
				return count( $this->aliases );
			}
			if ( str_contains( $sql, 'wdc_location_carrier_codes' ) ) {
				return count( $this->carrier_codes );
			}
			return count( $this->locations );
		}

		public function query( mixed $query ): int|false {
			$sql = is_array( $query ) ? (string) $query['query'] : (string) $query;
			if ( $this->fail_next_query ) {
				$this->fail_next_query = false;
				$this->last_error = '' !== $this->last_error ? $this->last_error : 'simulated SQL failure';
				return false;
			}
			if ( $this->fail_bulk_insert && str_starts_with( $sql, 'INSERT' ) ) {
				$this->last_error = '' !== $this->last_error ? $this->last_error : 'simulated SQL insert failure';
				return false;
			}

			if ( preg_match( '/ALTER TABLE (wdc_[a-z_]+) ADD COLUMN ([a-z_]+)/', $sql, $match ) ) {
				if ( 'wdc_gar_places_stage' === $match[1] && ! in_array( $match[2], $this->stage_columns, true ) ) {
					$this->stage_columns[] = $match[2];
				}
				if ( 'wdc_locations' === $match[1] && ! in_array( $match[2], $this->location_columns, true ) ) {
					$this->location_columns[] = $match[2];
				}
				return 1;
			}

			if ( preg_match( '/ALTER TABLE (wdc_[a-z_]+) ADD KEY ([a-z_]+)/', $sql, $match ) ) {
				$this->indexes[ $match[1] ][] = $match[2];
				return 1;
			}

			if ( str_contains( $sql, 'wdc_gar_places_stage' ) ) {
				$this->stage = array();
			} elseif ( str_contains( $sql, 'wdc_location_aliases' ) ) {
				$this->aliases = array();
			} elseif ( str_contains( $sql, 'wdc_location_carrier_codes' ) ) {
				$this->carrier_codes = array();
			} elseif ( str_contains( $sql, 'wdc_locations' ) ) {
				$this->locations = array();
			} elseif ( str_contains( $sql, 'wdc_regions' ) ) {
				$this->regions = array();
			}
			return 1;
		}

		private function join_region( array $row ): array {
			$region = $this->regions[ (string) ( $row['region_code'] ?? '' ) ] ?? array();
			$row['joined_region_name'] = $region['region_name'] ?? $row['region_name'] ?? '';
			$row['joined_region_type'] = $region['region_type'] ?? '';
			return $row;
		}

		private function rows_for_table( string $table ): array {
			return match ( $table ) {
				'wdc_regions' => $this->regions,
				'wdc_locations' => $this->locations,
				'wdc_location_aliases' => $this->aliases,
				'wdc_location_carrier_codes' => $this->carrier_codes,
				default => array(),
			};
		}

		private function columns_for( string $table ): array {
			$rows = $this->rows_for_table( $table );
			$first = reset( $rows );
			if ( is_array( $first ) ) {
				return array_keys( $first );
			}

			if ( 'wdc_locations' === $table ) {
				return $this->location_columns;
			}

			if ( 'wdc_gar_places_stage' === $table ) {
				return $this->stage_columns;
			}

			return array( 'id', 'region_code', 'region_name', 'gar_object_id', 'fias_id', 'carrier_key', 'external_code', 'created_at', 'updated_at' );
		}
	}
}

function current_time( string $type ): string {
	return '2026-05-23 12:00:00';
}

function trailingslashit( string $value ): string {
	return rtrim( $value, '/\\' ) . '/';
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

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['wdc_gar_smoke_options'][ $key ] ?? $default;
}

function update_option( string $key, mixed $value, bool|string $autoload = false ): bool {
	$GLOBALS['wdc_gar_smoke_options'][ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
	unset( $GLOBALS['wdc_gar_smoke_options'][ $key ] );
	return true;
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function gar_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$old_schema_db = new wpdb();
$old_schema_db->stage_columns = array_values(
	array_diff(
		$old_schema_db->stage_columns,
		array( 'district_name', 'district_type', 'district_fias_id', 'district_kladr_id', 'district_gar_object_id', 'district_level' )
	)
);
$old_schema_db->location_columns = array_values(
	array_diff(
		$old_schema_db->location_columns,
		array( 'district_name', 'district_type', 'district_fias_id', 'district_kladr_id', 'district_gar_object_id', 'district_level' )
	)
);
$GLOBALS['wpdb'] = $old_schema_db;
$migration_0010 = require dirname( __DIR__, 2 ) . '/database/migrations/0010_add_gar_district_columns.php';
$migration_0010();
foreach ( array( 'district_name', 'district_type', 'district_fias_id', 'district_kladr_id', 'district_gar_object_id', 'district_level' ) as $column ) {
	gar_smoke_assert( in_array( $column, $old_schema_db->stage_columns, true ), '0010 migration must add missing stage district columns.' );
	gar_smoke_assert( in_array( $column, $old_schema_db->location_columns, true ), '0010 migration must add missing location district columns.' );
}
foreach ( array( 'district_fias_id', 'district_gar_object_id' ) as $index ) {
	gar_smoke_assert( in_array( $index, $old_schema_db->indexes['wdc_gar_places_stage'] ?? array(), true ), '0010 migration must add stage district indexes.' );
}
foreach ( array( 'ix_district_fias_id', 'ix_district_gar_object_id', 'ix_region_district_place' ) as $index ) {
	gar_smoke_assert( in_array( $index, $old_schema_db->indexes['wdc_locations'] ?? array(), true ), '0010 migration must add location district indexes.' );
}
$old_region_type_db = new wpdb();
$old_region_type_db->location_columns = array_values( array_diff( $old_region_type_db->location_columns, array( 'region_type' ) ) );
$GLOBALS['wpdb'] = $old_region_type_db;
$migration_0011 = require dirname( __DIR__, 2 ) . '/database/migrations/0011_add_location_region_type.php';
$migration_0011();
gar_smoke_assert( in_array( 'region_type', $old_region_type_db->location_columns, true ), '0011 migration must add missing location region_type column.' );
gar_smoke_assert( in_array( 'ix_region_type', $old_region_type_db->indexes['wdc_locations'] ?? array(), true ), '0011 migration must add region_type index.' );

$outdated_db = new wpdb();
$outdated_db->stage_columns = array_values( array_diff( $outdated_db->stage_columns, array( 'district_name' ) ) );
$outdated_importer = new GarPlacesCsvImporter( new LocationRepository( $outdated_db ), new RegionRepository( $outdated_db ), new LocationAliasGenerator(), $outdated_db );
$outdated_result = $outdated_importer->import_from_file( dirname( __DIR__ ) . '/fixtures/gar_places_sample.csv' );
gar_smoke_assert( ! $outdated_result->success && str_contains( implode( ' ', $outdated_result->errors ), 'GAR staging table schema is outdated. Run plugin migrations.' ), 'Outdated stage schema must fail with clear preflight error.' );

$failing_db = new wpdb();
$failing_db->force_sql_bulk = true;
$failing_db->fail_bulk_insert = true;
$failing_db->last_error = 'Unknown column district_name';
$failing_importer = new GarPlacesCsvImporter( new LocationRepository( $failing_db ), new RegionRepository( $failing_db ), new LocationAliasGenerator(), $failing_db );
$failing_job = $failing_importer->create_job( dirname( __DIR__ ) . '/fixtures/gar_places_sample.csv', 'failing-job' );
$failing_job = $failing_importer->step_job( $failing_job );
gar_smoke_assert( 'failed' === $failing_job['phase'], 'Failed stage bulk insert must set GAR job phase to failed.' );
gar_smoke_assert( 0 === (int) $failing_job['stage_rows'], 'Failed stage bulk insert must not increment stage_rows.' );
gar_smoke_assert( array() !== $failing_job['errors'] && str_contains( implode( ' ', $failing_job['errors'] ), 'GAR stage bulk insert failed: Unknown column district_name' ), 'Failed stage bulk insert must expose SQL error in job errors.' );

$direct_failure_db = new wpdb();
$direct_failure_db->fail_bulk_insert = true;
$direct_failure_db->last_error = 'simulated stage insert failure';
$direct_failure_importer = new GarPlacesCsvImporter( new LocationRepository( $direct_failure_db ), new RegionRepository( $direct_failure_db ), new LocationAliasGenerator(), $direct_failure_db );
$bulk_stage = new ReflectionMethod( GarPlacesCsvImporter::class, 'bulk_insert_stage_rows' );
$bulk_stage->setAccessible( true );
$thrown = false;
try {
	$bulk_stage->invoke(
		$direct_failure_importer,
		array(
			array(
				'region_code' => '54',
				'region_name' => 'Новосибирская обл',
				'place_name' => 'Новосибирск',
				'fias_id' => 'fias',
				'gar_object_id' => 1,
			),
		)
	);
} catch ( ReflectionException $exception ) {
	throw $exception;
} catch ( Throwable $exception ) {
	$thrown = str_contains( $exception->getMessage(), 'GAR stage bulk insert failed: simulated stage insert failure' );
}
gar_smoke_assert( $thrown, 'bulk_insert_stage_rows must throw RuntimeException when $wpdb->query() returns false.' );

$wpdb = new wpdb();
$locations = new LocationRepository( $wpdb );
$regions = new RegionRepository( $wpdb );
$search_service = new LocationSearchService( $locations );
$importer = new GarPlacesCsvImporter( $locations, $regions, new LocationAliasGenerator(), $wpdb );
$result = $importer->import_from_file( dirname( __DIR__ ) . '/fixtures/gar_places_sample.csv' );

gar_smoke_assert( $result->success, 'GAR import must succeed.' );
gar_smoke_assert( 4 === $result->rows_read, 'CSV delimiter ; must be parsed and header skipped.' );
gar_smoke_assert( 4 === $result->locations_imported, 'Locations must be imported.' );
gar_smoke_assert( 2 === $result->regions_imported, 'Regions must be deduplicated by region_code.' );
gar_smoke_assert( 0 === count( $wpdb->stage ), 'Stage table must be cleared after success.' );
gar_smoke_assert( 'Новосибирский' === (string) ( $wpdb->stage_history[1]['district_name'] ?? '' ), 'district_* must be imported to staging.' );
$repeat = $importer->import_from_file( dirname( __DIR__ ) . '/fixtures/gar_places_sample.csv' );
gar_smoke_assert( $repeat->success && 4 === count( $wpdb->locations ), 'Repeated import must update existing locations, not duplicate them.' );

$novosibirsk = $locations->find_by_gar_object_id( 1001 );
gar_smoke_assert( null !== $novosibirsk && 1001 === $novosibirsk->gar_object_id, 'gar_object_id must be stored.' );
gar_smoke_assert( 'обл' === $novosibirsk->region_type, 'region_type must be imported from CSV and visible in Location.' );
gar_smoke_assert( '' === $novosibirsk->postal_code, 'Empty postal_code must be preserved.' );
gar_smoke_assert( 'Новосибирская обл, г Новосибирск' === $novosibirsk->display_name, 'display_name must be imported as-is.' );
gar_smoke_assert( null !== $locations->find_by_fias_id( '22222222-2222-2222-2222-222222222001' ), 'fias_id must be searchable and unique-compatible.' );
gar_smoke_assert( null !== $locations->find_by_kladr_id( '5400000100000' ), 'Search by kladr_id must find row.' );
gar_smoke_assert( count( $search_service->search( 'Новос' ) ) > 0, 'Search "Новос" must find Novosibirsk.' );
gar_smoke_assert( count( $search_service->search( '5400000100000' ) ) > 0, 'Search must include kladr_id.' );
gar_smoke_assert( null !== ( new CheckoutLocationSearch( $search_service ) )->best_match( 'Новосибирск' ), 'Local city picker search must still work.' );

$paginated = $locations->search_paginated( 'Новос', 1, 20 );
gar_smoke_assert( 20 === $paginated['per_page'] && 1 === $paginated['page'], 'Search pagination must default to page 1 and per_page 20-compatible output.' );
gar_smoke_assert( $paginated['total'] >= 1 && $paginated['total_pages'] >= 1, 'Search pagination must return total and total_pages.' );
gar_smoke_assert( 20 === $locations->search_paginated( 'Новос', 1, 999 )['per_page'], 'Search pagination must clamp invalid per_page to default 20.' );
foreach ( array( 10, 20, 50, 100 ) as $per_page ) {
	gar_smoke_assert( $per_page === $locations->search_paginated( 'Новос', 1, $per_page )['per_page'], 'Search pagination must support configured per_page values.' );
}

$gusiny_brod = $locations->find_by_gar_object_id( 1002 );
gar_smoke_assert( null !== $gusiny_brod && 'Новосибирский' === $gusiny_brod->district_name, 'district_* must be imported to locations.' );
gar_smoke_assert( '630555' === $gusiny_brod->postal_code, 'postal_code must be imported.' );
gar_smoke_assert( str_contains( $gusiny_brod->display_name, 'Новосибирский р-н' ), 'Search result display must include district from CSV display_name.' );
gar_smoke_assert( count( $search_service->search( 'Новосибирский' ) ) > 0, 'Search must find by district_name.' );
gar_smoke_assert( count( $search_service->search( 'Гусиный Брод' ) ) > 0, 'Search must find Gusinny Brod.' );
gar_smoke_assert( count( $search_service->search( '33333333-3333-3333-3333-333333333001' ) ) > 0, 'Search must find by district_fias_id.' );
gar_smoke_assert( count( $search_service->search( '5400100000000' ) ) > 0, 'Search must find by district_kladr_id.' );

$locations->save(
	WallsShop\WDC\Locations\ValueObjects\Location::from_array(
		array(
			'gar_object_id' => 1999,
			'fias_id' => '99999999-9999-9999-9999-999999999999',
			'region_code' => '54',
			'region_name' => 'Новосибирская обл',
			'district_name' => 'Гусиный Брод',
			'district_type' => 'р-н',
			'place_name' => 'Тестовый',
			'place_type' => 'поселок',
			'place_level' => 6,
			'display_name' => 'Новосибирская обл, Гусиный Брод р-н, поселок Тестовый',
			'active' => true,
		)
	)
);
$ranked_gusiny = $locations->search_paginated( 'Гусиный Брод', 1, 10 )['items'];
gar_smoke_assert( 1002 === $ranked_gusiny[0]->gar_object_id, 'Ranking must put exact place match above district-only match.' );
$locations->save(
	WallsShop\WDC\Locations\ValueObjects\Location::from_array(
		array(
			'gar_object_id' => 1998,
			'fias_id' => '99999999-9999-9999-9999-999999999998',
			'region_code' => '54',
			'region_name' => 'Новосибирская обл',
			'district_name' => 'Новосибирск',
			'district_type' => 'р-н',
			'place_name' => 'Районный',
			'place_type' => 'поселок',
			'place_level' => 6,
			'display_name' => 'Новосибирская обл, Новосибирск р-н, поселок Районный',
			'active' => true,
		)
	)
);
$ranked_nsk = $locations->search_paginated( 'Новосибирск', 1, 10 )['items'];
gar_smoke_assert( 1001 === $ranked_nsk[0]->gar_object_id, 'Ranking must put exact city/place match above district-only match.' );

$fallback = $locations->find_by_gar_object_id( 1004 );
gar_smoke_assert( null !== $fallback && 'Новосибирская обл, р-н Новосибирский, г Новосибирск, рп Краснообск' === $fallback->display_name, 'Fallback display_name must include district.' );

$wpdb->insert(
	'wdc_location_carrier_codes',
	array(
		'location_id' => null,
		'gar_object_id' => 1001,
		'fias_id' => $novosibirsk->fias_id,
		'carrier_key' => 'cdek',
		'external_code' => '344',
		'meta' => null,
		'created_at' => current_time( 'mysql' ),
		'updated_at' => current_time( 'mysql' ),
	)
);
gar_smoke_assert( 1 === count( $wpdb->carrier_codes ), 'carrier_codes table foundation must accept rows.' );

$snapshot = tempnam( sys_get_temp_dir(), 'wdc-snapshot-' );
gar_smoke_assert( is_string( $snapshot ), 'Snapshot temp file must be created.' );
$exported = ( new LocationsSnapshotExporter( $wpdb ) )->export_to_file( $snapshot, '0.15.6' );
gar_smoke_assert( $exported > 0, 'Snapshot export must include rows from 4 tables.' );
$snapshot_text = (string) file_get_contents( $snapshot );
gar_smoke_assert( str_contains( $snapshot_text, '"table":"wdc_regions"' ) && str_contains( $snapshot_text, '"table":"wdc_location_carrier_codes"' ), 'Snapshot export must include all foundation tables.' );
gar_smoke_assert( str_contains( $snapshot_text, '"district_name":"Новосибирский"' ) && str_contains( $snapshot_text, '"postal_code":"630555"' ), 'Snapshot export must preserve district_* and postal_code.' );
gar_smoke_assert( ! str_contains( $snapshot_text, '"postcode"' ), 'Snapshot export must not include postcode as a location field.' );

$restored_db = new wpdb();
$restored = ( new LocationsSnapshotImporter( $restored_db ) )->import_from_file( $snapshot );
gar_smoke_assert( $restored === $exported, 'Snapshot import must restore exported rows.' );
gar_smoke_assert( count( $restored_db->locations ) === count( $wpdb->locations ), 'Snapshot import must restore locations.' );
$restored_has_district = false;
foreach ( $restored_db->locations as $restored_location ) {
	if ( 'Новосибирский' === (string) ( $restored_location['district_name'] ?? '' ) ) {
		$restored_has_district = true;
		break;
	}
}
gar_smoke_assert( $restored_has_district, 'Snapshot import must restore district fields.' );
@unlink( $snapshot );

$snapshot_job_file = tempnam( sys_get_temp_dir(), 'wdc-snapshot-job-' );
gar_smoke_assert( is_string( $snapshot_job_file ), 'Snapshot job temp file must be created.' );
$snapshot_exporter = new LocationsSnapshotExporter( $wpdb );
$snapshot_job = $snapshot_exporter->create_job( $snapshot_job_file, '0.15.6' );
for ( $i = 0; $i < 100 && 'finished' !== $snapshot_job['phase']; $i++ ) {
	$snapshot_job = $snapshot_exporter->step_job( $snapshot_job, 2 );
}
gar_smoke_assert( 'finished' === $snapshot_job['phase'] && (int) $snapshot_job['rows_exported'] > 0, 'Snapshot export job must write JSONL in chunks.' );
$snapshot_import_job_db = new wpdb();
$snapshot_importer = new LocationsSnapshotImporter( $snapshot_import_job_db );
$snapshot_import_job = $snapshot_importer->create_job( $snapshot_job_file );
for ( $i = 0; $i < 100 && 'finished' !== $snapshot_import_job['phase']; $i++ ) {
	$snapshot_import_job = $snapshot_importer->step_job( $snapshot_import_job, 2 );
}
gar_smoke_assert( 'finished' === $snapshot_import_job['phase'] && (int) $snapshot_import_job['imported'] > 0, 'Snapshot import job must read JSONL in chunks.' );
@unlink( $snapshot_job_file );

$bad_header = tempnam( sys_get_temp_dir(), 'wdc-gar-bad-header-' );
gar_smoke_assert( is_string( $bad_header ), 'Bad header temp file must be created.' );
file_put_contents( $bad_header, "\"region_code\";\"region_name\";\"place_name\";\"fias_id\"\n\"54\";\"Новосибирская обл\";\"Новосибирск\";\"fias\"\n" );
$bad_result = $importer->import_from_file( $bad_header );
gar_smoke_assert( ! $bad_result->success && str_contains( implode( ' ', $bad_result->errors ), 'GAR CSV missing required column: gar_object_id' ), 'Missing required header must return clear error.' );
@unlink( $bad_header );

$missing_stage_db = new wpdb();
$missing_stage_db->missing_tables = array( 'wdc_gar_places_stage' );
$missing_stage = new GarPlacesCsvImporter( new LocationRepository( $missing_stage_db ), new RegionRepository( $missing_stage_db ), new LocationAliasGenerator(), $missing_stage_db );
$missing_stage_result = $missing_stage->import_from_file( dirname( __DIR__ ) . '/fixtures/gar_places_sample.csv' );
gar_smoke_assert( ! $missing_stage_result->success && str_contains( implode( ' ', $missing_stage_result->errors ), 'GAR staging table does not exist. Run plugin migrations first.' ), 'clear_stage must report missing staging table.' );

$job_db = new wpdb();
$job_importer = new GarPlacesCsvImporter( new LocationRepository( $job_db ), new RegionRepository( $job_db ), new LocationAliasGenerator(), $job_db );
$job = $job_importer->create_job( dirname( __DIR__ ) . '/fixtures/gar_places_sample.csv', 'test-job' );
gar_smoke_assert( 'staging' === $job['phase'], 'GAR progress job must start in staging phase.' );
for ( $i = 0; $i < 10 && 'finished' !== $job['phase'] && 'failed' !== $job['phase']; $i++ ) {
	$job = $job_importer->step_job( $job );
}
gar_smoke_assert( 'finished' === $job['phase'], 'GAR progress job must advance staging -> processing -> finished.' );
gar_smoke_assert( (int) $job['rows_read'] > 0 && (int) $job['locations_imported'] > 0, 'GAR progress counters must increase.' );
gar_smoke_assert( 4 === (int) $job['rows_read'], 'Successful sample job must read all 4 data rows.' );
gar_smoke_assert( 4 === (int) $job['stage_rows'], 'Successful sample job must stage all 4 valid rows.' );
gar_smoke_assert( 4 === (int) $job['processed_rows'], 'Successful sample job must process all 4 staged rows.' );
gar_smoke_assert( 4 === (int) $job['locations_imported'], 'Successful sample job must import all 4 locations.' );
gar_smoke_assert( (int) $job['regions_imported'] > 0, 'Successful sample job must import regions.' );

$_SERVER['REQUEST_METHOD'] = 'GET';
update_option(
	'wdc_location_type_display_rules',
	array(
		'region' => array(
			'обл' => array( 'display' => 'обл.', 'position' => 'after' ),
			'респ' => array( 'display' => 'Республика', 'position' => 'before' ),
		),
		'city' => array(),
		'place' => array(),
	),
	false
);
$_GET = array( 'location_query' => 'Новос' );
$_POST = array();
ob_start();
( new LocationsAdminPage(
	new WallsShop\WDC\Core\PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.15.6' ),
	$locations,
	$search_service,
	new LocationImportService( $locations ),
	null,
	null,
	null,
	null,
	null,
	$importer,
	new LocationsSnapshotExporter( $wpdb ),
	new LocationsSnapshotImporter( $wpdb )
) )->render_page();
$html = (string) ob_get_clean();
gar_smoke_assert( ! str_contains( $html, 'Импортировать демо-данные' ), 'Admin demo buttons must be removed.' );
gar_smoke_assert( ! str_contains( $html, 'Import prepared FIAS dataset' ), 'Prepared FIAS button must be removed.' );
gar_smoke_assert( str_contains( $html, 'Импорт GAR/ФИАС CSV' ), 'Admin GAR CSV import block must be rendered.' );
gar_smoke_assert( str_contains( $html, 'wdc_gar_import_start' ) && str_contains( $html, 'wdc_locations_snapshot_export_start' ), 'Admin page must include chunked progress AJAX actions.' );
gar_smoke_assert( str_contains( $html, 'wdc-location-details-toggle' ) && str_contains( $html, 'wdc_location_details' ), 'Admin search must include details button/action.' );
gar_smoke_assert( str_contains( $html, 'wdc-locations-pagination' ) && str_contains( $html, 'Найдено всего:' ) && str_contains( $html, 'location_per_page' ), 'Admin search must include pagination controls.' );
gar_smoke_assert( str_contains( $html, 'Пересобрать display_name' ) && str_contains( $html, 'wdc-display-name-rebuild-progress' ) && str_contains( $html, 'JSON status' ), 'Admin page must include display_name rebuild progress UI.' );
gar_smoke_assert( str_contains( $html, 'Отображение типов населенных пунктов' ) && str_contains( $html, '<details class="wdc-type-rules-group" open' ) && str_contains( $html, 'Регион —' ), 'Admin page must include collapsible type display rules table.' );
gar_smoke_assert( str_contains( $html, 'Новосибирская обл' ), 'Admin group header must include region_name and visual region_type.' );
$main_pos = strpos( $html, 'wdc-location-row-main' );
$button_pos = strpos( $html, 'wdc-location-details-toggle' );
$title_pos = strpos( $html, 'wdc-location-title' );
$details_pos = strpos( $html, 'wdc-location-details" hidden' );
gar_smoke_assert( false !== $main_pos, 'Admin search row must render wdc-location-row-main wrapper.' );
gar_smoke_assert( false !== $button_pos && false !== $title_pos && $button_pos < $title_pos, 'Details button must render before location title.' );
gar_smoke_assert( false !== $details_pos && $main_pos < $details_pos, 'Details panel must render after row main wrapper.' );
gar_smoke_assert( str_contains( $html, "button.closest('.wdc-location-row')" ) && ! str_contains( $html, "button.parentElement.querySelector('.wdc-location-details')" ), 'Details JS must find row with closest().');

$_POST = array( 'wdc_locations_nonce' => 'test-nonce', 'location_id' => (string) $novosibirsk->id );
ob_start();
( new LocationsAdminPage(
	new WallsShop\WDC\Core\PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.15.6' ),
	$locations,
	$search_service,
	new LocationImportService( $locations )
) )->ajax_location_details();
$details_payload = json_decode( (string) ob_get_clean(), true );
$details_data = $details_payload['data'] ?? array();
gar_smoke_assert( isset( $details_data['display_name'], $details_data['region_type'], $details_data['city_type'], $details_data['place_type'], $details_data['district_type'], $details_data['searchable_text'], $details_data['postal_code'] ), 'Details payload must contain expected location fields.' );
gar_smoke_assert( 'обл' === (string) ( $details_data['region_type'] ?? '' ), 'Details payload must include imported region_type.' );
gar_smoke_assert( ! array_key_exists( 'postcode', $details_data ), 'Details payload must not contain postcode.' );

echo "GAR import smoke test passed.\n";
