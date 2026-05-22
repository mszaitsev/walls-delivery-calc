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
				if ( str_contains( $query, 'WHERE l.id' ) && (int) $row['id'] === (int) $value ) {
					return $this->join_region( $row );
				}
			}

			return null;
		}

		public function get_results( mixed $query, string $output ): array {
			$sql = is_array( $query ) ? $query['query'] : (string) $query;
			$args = is_array( $query ) ? $query['args'] : array();

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

		public function query( string $query ): int {
			if ( str_contains( $query, 'wdc_gar_places_stage' ) ) {
				$this->stage = array();
			} elseif ( str_contains( $query, 'wdc_location_aliases' ) ) {
				$this->aliases = array();
			} elseif ( str_contains( $query, 'wdc_location_carrier_codes' ) ) {
				$this->carrier_codes = array();
			} elseif ( str_contains( $query, 'wdc_locations' ) ) {
				$this->locations = array();
			} elseif ( str_contains( $query, 'wdc_regions' ) ) {
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
				return array( 'id', 'gar_object_id', 'fias_id', 'kladr_id', 'gar_id', 'country_code', 'region_name', 'region_code', 'district_name', 'district_type', 'district_fias_id', 'district_kladr_id', 'district_gar_object_id', 'district_level', 'city_name', 'city_type', 'city_fias_id', 'city_kladr_id', 'settlement_name', 'settlement_type', 'place_name', 'place_type', 'place_level', 'display_name', 'postal_code', 'okato', 'oktmo', 'latitude', 'longitude', 'searchable_text', 'active', 'created_at', 'updated_at' );
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

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function gar_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

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

$novosibirsk = $locations->find_by_gar_object_id( 1001 );
gar_smoke_assert( null !== $novosibirsk && 1001 === $novosibirsk->gar_object_id, 'gar_object_id must be stored.' );
gar_smoke_assert( '' === $novosibirsk->postal_code, 'Empty postal_code must be preserved.' );
gar_smoke_assert( 'Новосибирская обл, г Новосибирск' === $novosibirsk->display_name, 'display_name must be imported as-is.' );
gar_smoke_assert( null !== $locations->find_by_fias_id( '22222222-2222-2222-2222-222222222001' ), 'fias_id must be searchable and unique-compatible.' );
gar_smoke_assert( null !== $locations->find_by_kladr_id( '5400000100000' ), 'Search by kladr_id must find row.' );
gar_smoke_assert( count( $search_service->search( 'Новос' ) ) > 0, 'Search "Новос" must find Novosibirsk.' );
gar_smoke_assert( count( $search_service->search( '5400000100000' ) ) > 0, 'Search must include kladr_id.' );
gar_smoke_assert( null !== ( new CheckoutLocationSearch( $search_service ) )->best_match( 'Новосибирск' ), 'Local city picker search must still work.' );

$gusiny_brod = $locations->find_by_gar_object_id( 1002 );
gar_smoke_assert( null !== $gusiny_brod && 'Новосибирский' === $gusiny_brod->district_name, 'district_* must be imported to locations.' );
gar_smoke_assert( '630555' === $gusiny_brod->postal_code, 'postal_code must be imported.' );
gar_smoke_assert( str_contains( $gusiny_brod->display_name, 'Новосибирский р-н' ), 'Search result display must include district from CSV display_name.' );
gar_smoke_assert( count( $search_service->search( 'Новосибирский' ) ) > 0, 'Search must find by district_name.' );
gar_smoke_assert( count( $search_service->search( 'Гусиный Брод' ) ) > 0, 'Search must find Gusinny Brod.' );
gar_smoke_assert( count( $search_service->search( '33333333-3333-3333-3333-333333333001' ) ) > 0, 'Search must find by district_fias_id.' );
gar_smoke_assert( count( $search_service->search( '5400100000000' ) ) > 0, 'Search must find by district_kladr_id.' );

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
$exported = ( new LocationsSnapshotExporter( $wpdb ) )->export_to_file( $snapshot, '0.15.1' );
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

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array();
$_POST = array();
ob_start();
( new LocationsAdminPage(
	new WallsShop\WDC\Core\PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.15.1' ),
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

echo "GAR import smoke test passed.\n";
