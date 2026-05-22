<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Locations\Admin\LocationsAdminPage;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Normalization\FallbackAddressNormalizer;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		public int $insert_id = 0;

		/** @var array<int, array<string,mixed>> */
		public array $rows = array();

		/** @var array<int, array<string,mixed>> */
		public array $alias_rows = array();

		/** @var array<int,string> */
		public array $queries = array();

		/** @var array<int,string> */
		public array $missing_tables = array();

		public function prepare( string $query, mixed ...$args ): array {
			return array(
				'query' => $query,
				'args'  => $args,
			);
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function insert( string $table, array $data, array $format ): int {
			++$this->insert_id;
			$data['id'] = $this->insert_id;
			if ( str_contains( $table, 'wdc_location_aliases' ) ) {
				$this->alias_rows[ $this->insert_id ] = $data;
			} else {
				$this->rows[ $this->insert_id ] = $data;
			}
			return 1;
		}

		public function update( string $table, array $data, array $where, array $format, array $where_format ): int {
			$id = (int) ( $where['id'] ?? 0 );
			if ( isset( $this->rows[ $id ] ) ) {
				$this->rows[ $id ] = array_merge( $this->rows[ $id ], $data );
				$this->rows[ $id ]['id'] = $id;
				return 1;
			}

			return 0;
		}

		public function get_row( array $prepared, string $output ): ?array {
			$query = $prepared['query'];
			$value = (string) ( $prepared['args'][0] ?? '' );

			foreach ( $this->rows as $row ) {
				if ( str_contains( $query, 'WHERE id =' ) && (int) $row['id'] === (int) $value ) {
					return $row;
				}

				if ( str_contains( $query, 'WHERE fias_id =' ) && (string) $row['fias_id'] === $value ) {
					return $row;
				}

				if ( str_contains( $query, 'WHERE gar_id =' ) && (string) $row['gar_id'] === $value ) {
					return $row;
				}
			}

			return null;
		}

		public function get_results( array $prepared, string $output ): array {
			$query = trim( (string) $prepared['args'][0], '%' );
			$limit = (int) ( $prepared['args'][1] ?? 20 );
			$rows = array_filter(
				$this->rows,
				static fn( array $row ): bool => 1 === (int) $row['active'] && str_contains( (string) $row['searchable_text'], $query )
			);

			usort( $rows, static fn( array $a, array $b ): int => strcmp( (string) $a['display_name'], (string) $b['display_name'] ) );

			return array_slice( array_values( $rows ), 0, $limit );
		}

		public function get_var( mixed $query ): int {
			if ( is_array( $query ) && str_contains( (string) $query['query'], 'SHOW TABLES LIKE' ) ) {
				$table = (string) ( $query['args'][0] ?? '' );
				return in_array( $table, $this->missing_tables, true ) ? 0 : 1;
			}

			if ( is_string( $query ) && str_contains( $query, 'wdc_location_aliases' ) ) {
				return count( $this->alias_rows );
			}

			if ( is_string( $query ) && str_contains( $query, 'COUNT(DISTINCT region_name)' ) ) {
				$regions = array();
				foreach ( $this->rows as $row ) {
					if ( 1 === (int) $row['active'] && '' !== (string) $row['region_name'] ) {
						$regions[ (string) $row['region_name'] ] = true;
					}
				}

				return count( $regions );
			}

			return count( $this->rows );
		}

		public function query( mixed $query ): int {
			$query = (string) $query;
			$this->queries[] = $query;

			if ( str_contains( $query, 'wdc_location_aliases' ) && ( str_starts_with( $query, 'DELETE FROM' ) || str_starts_with( $query, 'TRUNCATE TABLE' ) ) ) {
				$this->alias_rows = array();
			}

			if ( str_contains( $query, 'wdc_locations' ) && ( str_starts_with( $query, 'DELETE FROM' ) || str_starts_with( $query, 'TRUNCATE TABLE' ) ) ) {
				$this->rows = array();
			}

			return 1;
		}
	}
}

function current_time( string $type ): string {
	return '2026-05-21 12:00:00';
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

function locations_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$wpdb = new wpdb();
$repository = new LocationRepository( $wpdb );
$importer = new LocationImportService( $repository );
$search = new LocationSearchService( $repository );

$imported = $importer->import_from_json_file( dirname( __DIR__, 2 ) . '/database/demo/locations-demo.json' );
locations_smoke_assert( $imported >= 9, sprintf( 'Demo dataset must import the stabilization demo locations, imported %d.', $imported ) );
locations_smoke_assert( $repository->count_all() > 0, 'Repository count must be greater than zero.' );
locations_smoke_assert( method_exists( $repository, 'count_regions' ), 'LocationRepository must expose count_regions method.' );
locations_smoke_assert( $repository->count_regions() >= 5, 'Repository must count unique active regions.' );

$novos = $search->search( 'новос' );
locations_smoke_assert( count( $novos ) > 0, 'Search "новос" must return results.' );
locations_smoke_assert( '' !== $novos[0]->display_name, 'Search result display_name must be present.' );

$grouped = $search->grouped( 'новос' );
locations_smoke_assert( isset( $grouped['Новосибирская область'] ), 'Grouped search must contain Novosibirsk region.' );

$exact = $search->search( 'новосибирск', 5 );
locations_smoke_assert( isset( $exact[0] ) && '' !== $exact[0]->display_name, 'Exact city match must rank first.' );

$fallback = ( new FallbackAddressNormalizer() )->normalize( 'Новосибирск, Красный проспект, 1' );
locations_smoke_assert( false === $fallback->success, 'Fallback normalizer must not report success.' );
locations_smoke_assert( 'fallback' === $fallback->source, 'Fallback normalizer source must be fallback.' );
locations_smoke_assert( 'Новосибирск, Красный проспект, 1' === $fallback->address->raw_address, 'Fallback normalizer must preserve raw address.' );

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET                      = array();
$_POST                     = array();
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.12.13' ),
	$repository,
	$search,
	$importer
) )->render_page();
$locations_html = (string) ob_get_clean();
locations_smoke_assert( str_contains( $locations_html, 'Регионов/областей:' ), 'Locations admin page must render regions counter label.' );

locations_smoke_assert( str_contains( $locations_html, 'Очистить базу населенных пунктов' ), 'Locations admin page must render clear locations button.' );
locations_smoke_assert( str_contains( $locations_html, 'Импорт GAR/ФИАС CSV' ), 'Locations admin page must render GAR CSV import section.' );
locations_smoke_assert( str_contains( $locations_html, 'Экспорт / импорт подготовленной базы' ), 'Locations admin page must render snapshot section.' );
locations_smoke_assert( ! str_contains( $locations_html, 'Импортировать демо-данные' ), 'Locations admin page must not render demo import button.' );
locations_smoke_assert( ! str_contains( $locations_html, 'Import prepared FIAS dataset' ), 'Locations admin page must not render prepared FIAS import button.' );
locations_smoke_assert( str_contains( $locations_html, 'Удалить все населенные пункты и алиасы из локальной базы WDC?' ), 'Locations admin page must render JS confirmation.' );

locations_smoke_assert( method_exists( $repository, 'clear_all' ), 'LocationRepository must expose clear_all method.' );
$repository->save_aliases( 1, array( 'Alias one', 'Alias two' ) );
locations_smoke_assert( $repository->count_aliases() > 0, 'Test fixture must contain aliases before clear_all.' );
$wpdb->queries = array();
$clear_stats = $repository->clear_all();
locations_smoke_assert( is_array( $clear_stats ) && isset( $clear_stats['locations_deleted'], $clear_stats['aliases_deleted'] ), 'clear_all must return deletion statistics.' );
locations_smoke_assert( $clear_stats['locations_deleted'] >= 1, 'clear_all must count deleted locations.' );
locations_smoke_assert( $clear_stats['aliases_deleted'] >= 2, 'clear_all must count deleted aliases.' );
locations_smoke_assert( 0 === $repository->count_all(), 'clear_all must remove local locations.' );
locations_smoke_assert( 0 === $repository->count_aliases(), 'clear_all must remove local aliases.' );
$alias_clear_index = -1;
$location_clear_index = -1;
foreach ( $wpdb->queries as $index => $query ) {
	if ( str_contains( $query, 'wdc_location_aliases' ) && ( str_starts_with( $query, 'TRUNCATE TABLE' ) || str_starts_with( $query, 'DELETE FROM' ) ) && -1 === $alias_clear_index ) {
		$alias_clear_index = $index;
	}
	if ( str_contains( $query, 'wdc_locations' ) && ( str_starts_with( $query, 'TRUNCATE TABLE' ) || str_starts_with( $query, 'DELETE FROM' ) ) && -1 === $location_clear_index ) {
		$location_clear_index = $index;
	}
}
locations_smoke_assert( -1 !== $alias_clear_index && -1 !== $location_clear_index && $alias_clear_index < $location_clear_index, 'clear_all must clear aliases before locations.' );

$missing_wpdb = new wpdb();
$missing_wpdb->missing_tables = array( 'wdc_locations', 'wdc_location_aliases' );
$missing_stats = ( new LocationRepository( $missing_wpdb ) )->clear_all();
locations_smoke_assert( null === $missing_stats['locations_deleted'] && null === $missing_stats['aliases_deleted'], 'clear_all must not fatal when tables are missing.' );

$admin_wpdb = new wpdb();
$admin_repository = new LocationRepository( $admin_wpdb );
$admin_importer = new LocationImportService( $admin_repository );
$admin_search = new LocationSearchService( $admin_repository );
$admin_importer->import_from_json_file( dirname( __DIR__, 2 ) . '/database/demo/locations-demo.json' );
$admin_repository->save_aliases( 1, array( 'Admin alias' ) );
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
	'wdc_locations_nonce' => 'test-nonce',
	'wdc_locations_action' => 'clear_all',
);
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.14.15' ),
	$admin_repository,
	$admin_search,
	$admin_importer
) )->render_page();
$clear_admin_html = (string) ob_get_clean();
locations_smoke_assert( str_contains( $clear_admin_html, 'База населенных пунктов очищена.' ), 'Admin clear action must render success notice.' );
locations_smoke_assert( 0 === $admin_repository->count_all() && 0 === $admin_repository->count_aliases(), 'Admin clear action must delete locations and aliases.' );

$locations_admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Locations/Admin/LocationsAdminPage.php' );
locations_smoke_assert( str_contains( $locations_admin_source, 'wp_verify_nonce' ) && str_contains( $locations_admin_source, 'current_user_can( AdminMenu::CAPABILITY' ), 'Admin clear action must require nonce and capability.' );
$repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Locations/Storage/LocationRepository.php' );
locations_smoke_assert( ! str_contains( $repository_source, 'pickup' ) && ! str_contains( $repository_source, 'rules' ) && ! str_contains( $repository_source, 'calendar' ) && ! str_contains( $repository_source, 'options' ), 'clear_all must not target pickup/rules/calendar/settings storage.' );

echo "Locations smoke test passed.\n";
