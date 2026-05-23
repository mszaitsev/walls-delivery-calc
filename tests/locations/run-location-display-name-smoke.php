<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Locations\Admin\LocationsAdminPage;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Import\LocationsSnapshotImporter;
use WallsShop\WDC\Locations\Services\LocationDisplayNameFormatter;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['wdc_display_smoke_options'] = array();

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		public int $insert_id = 0;
		public array $locations = array();
		public array $regions = array();
		public array $aliases = array();

		public function prepare( string $query, mixed ...$args ): array {
			return array( 'query' => $query, 'args' => $args );
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function insert( string $table, array $data, ?array $format = null ): int {
			++$this->insert_id;
			$data['id'] = $this->insert_id;
			if ( str_contains( $table, 'wdc_location_aliases' ) ) {
				$this->aliases[ $this->insert_id ] = $data;
				return 1;
			}
			$this->locations[ $this->insert_id ] = $data;
			return 1;
		}

		public function update( string $table, array $data, array $where, array $format, array $where_format ): int {
			$id = (int) ( $where['id'] ?? 0 );
			if ( isset( $this->locations[ $id ] ) ) {
				$this->locations[ $id ] = array_merge( $this->locations[ $id ], $data );
				return 1;
			}
			return 0;
		}

		public function get_row( array $prepared, string $output ): ?array {
			$value = (string) ( $prepared['args'][0] ?? '' );
			foreach ( $this->locations as $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === (int) $value ) {
					return $row;
				}
			}
			return null;
		}

		public function get_results( mixed $query, string $output ): array {
			$sql = is_array( $query ) ? (string) $query['query'] : (string) $query;
			if ( str_starts_with( $sql, 'DESCRIBE' ) ) {
				$table = trim( substr( $sql, 9 ) );
				$columns = match ( $table ) {
					'wdc_locations' => array( 'id', 'gar_object_id', 'fias_id', 'region_name', 'region_type', 'region_code', 'place_name', 'place_type', 'place_level', 'display_name', 'searchable_text', 'postal_code', 'active', 'created_at', 'updated_at' ),
					'wdc_regions' => array( 'region_code', 'region_name', 'region_type' ),
					'wdc_location_aliases' => array( 'id', 'location_id', 'alias', 'alias_normalized', 'source', 'created_at' ),
					'wdc_location_carrier_codes' => array( 'id', 'gar_object_id', 'fias_id', 'carrier_key', 'external_code', 'meta', 'created_at', 'updated_at' ),
					default => array(),
				};
				return array_map( static fn( string $field ): array => array( 'Field' => $field ), $columns );
			}
			return array();
		}

		public function get_var( mixed $query ): int {
			return count( $this->locations );
		}

		public function query( mixed $query ): int {
			if ( str_contains( is_array( $query ) ? $query['query'] : (string) $query, 'wdc_location_aliases' ) ) {
				$this->aliases = array();
			}
			return 1;
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

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function sanitize_key( string $value ): string {
	return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', $value ) ?? '' );
}

function wp_unslash( mixed $value ): mixed {
	return $value;
}

function current_user_can( string $capability ): bool {
	return 'manage_woocommerce' === $capability;
}

function wp_verify_nonce( string $nonce, string $action ): bool {
	return 'test-nonce' === $nonce;
}

function wp_nonce_field( string $action, string $name ): void {
	printf( '<input type="hidden" name="%s" value="test-nonce">', esc_attr( $name ) );
}

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['wdc_display_smoke_options'][ $key ] ?? $default;
}

function update_option( string $key, mixed $value, bool|string $autoload = false ): bool {
	$GLOBALS['wdc_display_smoke_options'][ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
	unset( $GLOBALS['wdc_display_smoke_options'][ $key ] );
	return true;
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function display_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$rules = array(
	'region' => array(
		'обл' => array( 'display' => 'обл', 'position' => 'after' ),
		'респ' => array( 'display' => 'Республика', 'position' => 'before' ),
		'край' => array( 'display' => '', 'position' => 'hidden' ),
	),
	'city' => array(
		'г' => array( 'display' => 'г.', 'position' => 'before' ),
		'пос' => array( 'display' => 'пос.', 'position' => 'after' ),
		'город' => array( 'display' => '', 'position' => 'hidden' ),
	),
	'place' => array(
		'село' => array( 'display' => 'село', 'position' => 'before' ),
		'деревня' => array( 'display' => 'д.', 'position' => 'before' ),
		'рп' => array( 'display' => '', 'position' => 'hidden' ),
	),
);
$formatter = LocationDisplayNameFormatter::from_rules( $rules );
display_smoke_assert( 'Новосибирская обл' === $formatter->format_part( 'region', 'обл', 'Новосибирская' ), 'Formatter must support region type after name.' );
display_smoke_assert( 'Республика Башкортостан' === $formatter->format_part( 'region', 'респ', 'Башкортостан' ), 'Formatter must support region type before name.' );
display_smoke_assert( 'Алтайский' === $formatter->format_part( 'region', 'край', 'Алтайский' ), 'Formatter must hide region type.' );
display_smoke_assert( 'г. Новосибирск' === $formatter->format_part( 'city', 'г', 'Новосибирск' ), 'Formatter must support city type before name.' );
display_smoke_assert( 'Красный пос.' === $formatter->format_part( 'city', 'пос', 'Красный' ), 'Formatter must support city type after name.' );
display_smoke_assert( 'Москва' === $formatter->format_part( 'city', 'город', 'Москва' ), 'Formatter must hide city type.' );
display_smoke_assert( 'село Гусиный Брод' === $formatter->format_part( 'place', 'село', 'Гусиный Брод' ), 'Formatter must support place type before name.' );
display_smoke_assert( 'Краснообск' === $formatter->format_part( 'place', 'рп', 'Краснообск' ), 'Formatter must hide place type.' );
display_smoke_assert( 'Новосибирская обл' === $formatter->format_region_group_header( 'Новосибирская', 'обл' ), 'Group header must append mapped region type after name.' );
display_smoke_assert( 'Башкортостан Республика' === $formatter->format_region_group_header( 'Башкортостан', 'респ' ), 'Group header must ignore before position and append type after name.' );
display_smoke_assert( 'Алтайский' === $formatter->format_region_group_header( 'Алтайский', 'край' ), 'Group header must hide region type when rule is hidden.' );

$location = Location::from_array(
	array(
		'id' => 1,
		'gar_object_id' => 1002,
		'fias_id' => '22222222-2222-2222-2222-222222222002',
		'region_name' => 'Новосибирская',
		'region_type' => 'обл',
		'region_code' => '54',
		'district_name' => 'Новосибирский',
		'district_type' => 'р-н',
		'city_name' => '',
		'place_name' => 'Гусиный Брод',
		'place_type' => 'село',
		'place_level' => 6,
		'display_name' => 'old',
	)
);
display_smoke_assert( 'Новосибирская обл, Новосибирский р-н, село Гусиный Брод' === $formatter->format_location( $location ), 'Formatter must build region, district, city, place formula.' );

$wpdb = new wpdb();
$repository = new LocationRepository( $wpdb );
$repository->save( Location::from_array( array_merge( $location->to_array(), array( 'id' => null ) ) ) );
update_option( 'wdc_location_type_display_rules', $rules, false );

$admin = new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.15.10' ),
	$repository,
	new LocationSearchService( $repository ),
	new LocationImportService( $repository )
);

$_POST = array( 'wdc_locations_nonce' => 'test-nonce' );
ob_start();
$admin->ajax_display_name_rebuild_start();
$start_payload = json_decode( (string) ob_get_clean(), true );
display_smoke_assert( 'running' === ( $start_payload['data']['phase'] ?? '' ) && isset( $start_payload['data']['job_id'] ), 'Display_name rebuild start must return JSON job state.' );

ob_start();
$admin->ajax_display_name_rebuild_step();
$step_payload = json_decode( (string) ob_get_clean(), true );
$job = $step_payload['data'] ?? array();
display_smoke_assert( 'finished' === ( $job['phase'] ?? '' ), 'Display_name rebuild job must finish for one-row fixture.' );
display_smoke_assert( 1 === (int) ( $job['updated'] ?? 0 ), 'Display_name rebuild must update rows.' );
display_smoke_assert( (int) ( $job['aliases_updated'] ?? 0 ) > 0, 'Display_name rebuild must update aliases.' );
$rebuilt_row = reset( $wpdb->locations );
display_smoke_assert( is_array( $rebuilt_row ) && 'Новосибирская обл, Новосибирский р-н, село Гусиный Брод' === ( $rebuilt_row['display_name'] ?? '' ), 'Display_name rebuild must update display_name, got: ' . ( is_array( $rebuilt_row ) ? (string) ( $rebuilt_row['display_name'] ?? '' ) : 'no row' ) );
display_smoke_assert( is_array( $rebuilt_row ) && str_contains( (string) ( $rebuilt_row['searchable_text'] ?? '' ), 'гусиный брод' ), 'Display_name rebuild must update searchable_text.' );

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = array( 'location_query' => 'Гусиный', 'location_per_page' => '10' );
ob_start();
$admin->render_page();
$html = (string) ob_get_clean();
display_smoke_assert( str_contains( $html, 'Пересобрать display_name' ) && str_contains( $html, 'wdc-display-name-rebuild-progress' ), 'Admin page must render display_name rebuild button and progress.' );
display_smoke_assert( str_contains( $html, 'JSON status' ), 'Admin page must render JSON status block.' );
display_smoke_assert( str_contains( $html, 'Отображение типов населенных пунктов' ), 'Admin page must render type rules settings.' );
display_smoke_assert( str_contains( $html, '<details class="wdc-type-rules-group">' ) && ! str_contains( $html, '<details class="wdc-type-rules-group" open' ) && str_contains( $html, '<summary>Регион —' ) && str_contains( $html, '<summary>Город —' ) && str_contains( $html, '<summary>Населенный пункт —' ), 'Type rules settings must render collapsed details/summary blocks with counts.' );
display_smoke_assert( str_contains( $html, 'Новосибирская обл' ), 'Admin group header must include region name plus visual region type.' );
display_smoke_assert( ! str_contains( $html, "panel.innerHTML = '<table" ), 'Details JS must not render DB values through innerHTML table concatenation.' );
display_smoke_assert( str_contains( $html, 'function renderDetailsTable(row)' ) && str_contains( $html, "document.createElement('table')" ) && str_contains( $html, "document.createElement('tr')" ) && str_contains( $html, 'textContent' ), 'Details JS must render table with createElement/textContent.' );
display_smoke_assert( str_contains( $html, 'td.textContent = safeText(row[key])' ), 'Potential XSS payloads from details payload must be assigned as text nodes.' );

$pagination = new ReflectionMethod( LocationsAdminPage::class, 'render_search_pagination' );
$pagination->setAccessible( true );
ob_start();
$pagination->invoke( $admin, 'Гусиный', array( 'items' => array(), 'total' => 400, 'page' => 6, 'per_page' => 20, 'total_pages' => 20 ) );
$pagination_html = (string) ob_get_clean();
display_smoke_assert( str_contains( $pagination_html, 'wdc-page-number current' ) && str_contains( $pagination_html, '>6<' ), 'Pagination must mark current page number.' );
display_smoke_assert( str_contains( $pagination_html, 'wdc-page-ellipsis' ), 'Pagination must render ellipsis for many pages.' );
display_smoke_assert( str_contains( $pagination_html, '>1<' ) && str_contains( $pagination_html, '>20<' ), 'Pagination must include first and last page numbers.' );

$group_method = new ReflectionMethod( LocationsAdminPage::class, 'group_locations_by_region' );
$group_method->setAccessible( true );
$groups = $group_method->invoke(
	$admin,
	array(
		Location::from_array( array( 'gar_object_id' => 901, 'fias_id' => 'fias-901', 'region_name' => 'Ямало-Ненецкий', 'region_type' => 'край', 'region_code' => '89', 'place_name' => 'А', 'place_level' => 1, 'display_name' => 'А' ) ),
		Location::from_array( array( 'gar_object_id' => 902, 'fias_id' => 'fias-902', 'region_name' => 'Башкортостан', 'region_type' => 'респ', 'region_code' => '02', 'place_name' => 'Б', 'place_level' => 1, 'display_name' => 'Б' ) ),
		Location::from_array( array( 'gar_object_id' => 903, 'fias_id' => 'fias-903', 'region_name' => 'Новосибирская', 'region_type' => 'обл', 'region_code' => '54', 'place_name' => 'В', 'place_level' => 1, 'display_name' => 'В' ) ),
	)
);
display_smoke_assert( 'Башкортостан Республика' === (string) ( $groups[0]['label'] ?? '' ) && 'Новосибирская обл' === (string) ( $groups[1]['label'] ?? '' ), 'Admin group headers must sort by region_name and append mapped region_type after name.' );
display_smoke_assert( 'Ямало-Ненецкий' === (string) ( $groups[2]['label'] ?? '' ), 'Admin group header must hide region_type when rule is hidden.' );

$postcode_location = Location::from_array( array( 'fias_id' => 'fias-postcode-test', 'gar_object_id' => 777, 'region_code' => '54', 'place_name' => 'Тест', 'place_level' => 1, 'display_name' => 'Тест', 'postcode' => '123456' ) );
display_smoke_assert( '' === $postcode_location->postal_code, 'Location::from_array must ignore legacy postcode.' );

$snapshot_file = tempnam( sys_get_temp_dir(), 'wdc-old-postcode-' );
display_smoke_assert( is_string( $snapshot_file ), 'Snapshot temp file must be created.' );
file_put_contents(
	$snapshot_file,
	json_encode( array( 'type' => 'meta', 'version' => 'old', 'tables' => array( 'wdc_locations' ), 'created_at' => current_time( 'mysql' ) ), JSON_UNESCAPED_UNICODE ) . "\n" .
	json_encode( array( 'type' => 'row', 'table' => 'wdc_locations', 'data' => array( 'gar_object_id' => 778, 'fias_id' => 'fias-old-postcode', 'region_code' => '54', 'place_name' => 'Старый', 'place_level' => 1, 'display_name' => 'Старый', 'postcode' => '654321' ) ), JSON_UNESCAPED_UNICODE ) . "\n"
);
$snapshot_db = new wpdb();
( new LocationsSnapshotImporter( $snapshot_db ) )->import_from_file( $snapshot_file );
$snapshot_row = reset( $snapshot_db->locations );
display_smoke_assert( is_array( $snapshot_row ) && '654321' === (string) ( $snapshot_row['postal_code'] ?? '' ) && ! array_key_exists( 'postcode', $snapshot_row ), 'SnapshotImporter must map legacy postcode to postal_code before insert.' );
@unlink( $snapshot_file );

echo "Location display_name smoke test passed.\n";
