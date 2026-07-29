<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCourierTariffProbeService;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\Locations\LocationCoordinateEnricher;
use WallsShop\WDC\Locations\Admin\LocationsAdminPage;
use WallsShop\WDC\Locations\Coordinates\LocationCoordinatesDadataBatchUpdater;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Normalization\FallbackAddressNormalizer;
use WallsShop\WDC\Locations\Postcodes\RussianPostCourierCalcPostcodeFillStateService;
use WallsShop\WDC\Locations\Services\LocationCountryIndexService;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['wdc_locations_smoke_options'] = array();

function get_option( string $key, mixed $default = false ): mixed {
	return array_key_exists( $key, $GLOBALS['wdc_locations_smoke_options'] ) ? $GLOBALS['wdc_locations_smoke_options'][ $key ] : $default;
}

function update_option( string $key, mixed $value, bool|string $autoload = false ): bool {
	$GLOBALS['wdc_locations_smoke_options'][ $key ] = $value;
	return true;
}

function delete_option( string $key ): bool {
	unset( $GLOBALS['wdc_locations_smoke_options'][ $key ] );
	return true;
}

final class WdcLocationsSmokeWooCountries {
	/** @var array<string,string> */
	public array $countries = array(
		'RU' => 'Россия',
		'BY' => 'Беларусь',
	);
}

final class WdcLocationsSmokeWoo {
	public WdcLocationsSmokeWooCountries $countries;

	public function __construct() {
		$this->countries = new WdcLocationsSmokeWooCountries();
	}
}

function WC(): WdcLocationsSmokeWoo {
	static $woocommerce = null;
	if ( null === $woocommerce ) {
		$woocommerce = new WdcLocationsSmokeWoo();
	}

	return $woocommerce;
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		public int $insert_id = 0;

		/** @var array<int, array<string,mixed>> */
		public array $rows = array();

		/** @var array<int, array<string,mixed>> */
		public array $alias_rows = array();

		/** @var array<int, array<string,mixed>> */
		public array $russian_post_pickup_rows = array();

		/** @var array<int,string> */
		public array $queries = array();

		/** @var array<int,string> */
		public array $get_var_queries = array();

		/** @var array<int,string> */
		public array $get_results_queries = array();

		/** @var array<int,string> */
		public array $missing_tables = array();

		public int $distinct_country_codes_calls = 0;

		public int $country_counts_calls = 0;

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

				if ( str_contains( $query, 'WHERE gar_object_id =' ) && (int) $row['gar_object_id'] === (int) $value ) {
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
			$this->get_results_queries[] = (string) ( $prepared['query'] ?? '' );
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
			$this->get_var_queries[] = is_array( $query ) ? (string) ( $query['query'] ?? '' ) : (string) $query;

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

function add_query_arg( array $params, string $url ): string {
	return $url . '?' . http_build_query( $params );
}

function is_wp_error( mixed $value ): bool {
	return false;
}

function wp_remote_retrieve_response_code( mixed $response ): int {
	return (int) ( $response['response']['code'] ?? 0 );
}

function wp_remote_retrieve_body( mixed $response ): string {
	return (string) ( $response['body'] ?? '' );
}

function wp_remote_get( string $url, array $args = array() ): array {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
	$GLOBALS['wdc_locations_probe_requests'][] = $params;
	$to = (string) ( $params['to'] ?? '' );
	if ( in_array( $to, $GLOBALS['wdc_locations_probe_api_error_postcodes'] ?? array(), true ) ) {
		return array(
			'response' => array( 'code' => 400 ),
			'body' => json_encode( array( 'errors' => array( array( 'code' => 9999, 'msg' => 'unexpected tariff error' ) ) ) ),
		);
	}

	return array(
		'response' => array( 'code' => in_array( $to, $GLOBALS['wdc_locations_probe_success_postcodes'] ?? array(), true ) ? 200 : 400 ),
		'body' => in_array( $to, $GLOBALS['wdc_locations_probe_success_postcodes'] ?? array(), true )
			? json_encode( array( 'paynds' => 12345, 'pay' => 12000 ) )
			: json_encode( array( 'errors' => array( array( 'code' => 2007, 'msg' => 'no courier delivery' ) ) ) ),
	);
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
	return 'test-nonce' === $nonce && 'wdc_locations_admin' === $action;
}

function wp_nonce_field( string $action, string $name ): void {
	printf( '<input type="hidden" name="%s" value="test-nonce">', esc_attr( $name ) );
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

final class WdcLocationsSmokeSuggestionClient implements AddressSuggestionClientInterface {
	public int $calls = 0;

	public function __construct(
		private ?float $lat = 55.7558000,
		private ?float $lng = 37.6173000
	) {
	}

	public function suggest( string $stage, string $query, array $context = array() ): array {
		++$this->calls;
		$data = array();
		if ( null !== $this->lat && null !== $this->lng ) {
			$data = array(
				'geo_lat' => (string) $this->lat,
				'geo_lon' => (string) $this->lng,
			);
		}

		return array(
			'success' => true,
			'suggestions' => array( array( 'data' => $data ) ),
		);
	}
}

final class WdcLocationsSmokeQueuedSuggestionClient implements AddressSuggestionClientInterface {
	public int $calls = 0;
	/** @var array<int,string> */
	public array $queries = array();

	/**
	 * @param array<int,mixed> $responses
	 */
	public function __construct( private array $responses ) {
	}

	public function suggest( string $stage, string $query, array $context = array() ): array {
		$this->queries[] = $query;
		$response = $this->responses[ $this->calls ] ?? array(
			'success' => true,
			'suggestions' => array(),
		);
		++$this->calls;
		if ( $response instanceof Throwable ) {
			throw $response;
		}
		return is_array( $response ) ? $response : array(
			'success' => true,
			'suggestions' => array(),
		);
	}
}

function locations_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function locations_smoke_location( array $data ): Location {
	return Location::from_array(
		array_merge(
			array(
				'country_code' => 'RU',
				'active' => true,
				'region_type' => 'обл',
				'place_type' => 'село',
				'postal_code' => '',
			),
			$data
		)
	);
}

$wpdb = new wpdb();
$repository = new LocationRepository( $wpdb );
$importer = new LocationImportService( $repository );
$search = new LocationSearchService( $repository );
$country_index = new LocationCountryIndexService( $repository );

$imported = $importer->import_from_json_file( dirname( __DIR__ ) . '/fixtures/demo/locations-demo.json' );
locations_smoke_assert( $imported >= 9, sprintf( 'Demo dataset must import the stabilization demo locations, imported %d.', $imported ) );
$initial_countries = $country_index->rebuild();
locations_smoke_assert( in_array( 'RU', $initial_countries, true ), 'LocationCountryIndex rebuild returns RU for demo locations: ' . implode( ',', $initial_countries ) );
$initial_country_option = get_option( LocationCountryIndexService::OPTION, array() );
locations_smoke_assert( isset( $initial_country_option['counts']['RU'] ) && $initial_country_option['counts']['RU'] >= 1, 'LocationCountryIndex rebuild stores RU count.' );
locations_smoke_assert( $country_index->has_country( 'RU' ), 'LocationCountryIndex has_country detects RU.' );
locations_smoke_assert( ! $country_index->has_country( 'PL' ), 'LocationCountryIndex has_country rejects missing PL.' );
$repository->save( locations_smoke_location( array( 'country_code' => 'BY', 'gar_object_id' => 880001, 'fias_id' => 'fias-by-minsk', 'region_code' => 'BY-MI', 'region_name' => 'Минская', 'place_name' => 'Минск', 'display_name' => 'Минск' ) ) );
$repository->save( locations_smoke_location( array( 'country_code' => 'KZ', 'gar_object_id' => 880002, 'fias_id' => 'fias-kz-almaty', 'region_code' => 'KZ-ALA', 'region_name' => 'Алматы', 'place_name' => 'Алматы', 'display_name' => 'Алматы' ) ) );
$multi_countries = $country_index->rebuild();
locations_smoke_assert( array() === array_diff( array( 'RU', 'BY', 'KZ' ), $multi_countries ), 'LocationCountryIndex rebuild returns RU/BY/KZ when fixtures include them.' );
$multi_countries_with_counts = $country_index->countries_with_counts();
$multi_country_counts_by_code = array_column( $multi_countries_with_counts, 'count', 'country_code' );
$multi_country_names_by_code = array_column( $multi_countries_with_counts, 'country_name', 'country_code' );
locations_smoke_assert( isset( $multi_country_counts_by_code['BY'] ) && $multi_country_counts_by_code['BY'] >= 1, 'LocationCountryIndex countries_with_counts returns BY count.' );
locations_smoke_assert( 'Россия' === ( $multi_country_names_by_code['RU'] ?? '' ), 'LocationCountryIndex countries_with_counts uses WooCommerce country names.' );
locations_smoke_assert( '' === ( $multi_country_names_by_code['KZ'] ?? null ), 'LocationCountryIndex countries_with_counts falls back to empty name when WooCommerce has no name.' );
locations_smoke_assert( $repository->count_all() > 0, 'Repository count must be greater than zero.' );
locations_smoke_assert( method_exists( $repository, 'count_regions' ), 'LocationRepository must expose count_regions method.' );
locations_smoke_assert( $repository->count_regions() >= 5, 'Repository must count unique active regions.' );
locations_smoke_assert( method_exists( $repository, 'update_coordinates' ), 'LocationRepository must expose update_coordinates method.' );

$coordinate_id = $repository->save( locations_smoke_location( array( 'gar_object_id' => 881001, 'fias_id' => 'fias-coordinate-test', 'region_code' => '77', 'region_name' => 'Москва', 'place_name' => 'Москва', 'display_name' => 'Москва', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$coordinate_client = new WdcLocationsSmokeSuggestionClient( 55.7558000, 37.6173000 );
$coordinate_enricher = new LocationCoordinateEnricher( $repository, $coordinate_client );
$enriched_location = $coordinate_enricher->enrich( array( 'id' => $coordinate_id, 'country_code' => 'RU', 'display_name' => 'Москва' ) );
locations_smoke_assert( 1 === $coordinate_client->calls, 'Selected city without usable coordinates must trigger DaData enrichment once.' );
locations_smoke_assert( 55.7558000 === (float) $enriched_location['lat'] && 37.6173000 === (float) $enriched_location['lng'], 'DaData enrichment must add lat/lng to city context payload.' );
locations_smoke_assert( 55.7558000 === (float) $wpdb->rows[ $coordinate_id ]['latitude'] && 37.6173000 === (float) $wpdb->rows[ $coordinate_id ]['longitude'], 'DaData enrichment must save coordinates in the location repository.' );
$no_coordinate_id = $repository->save( locations_smoke_location( array( 'gar_object_id' => 881002, 'fias_id' => 'fias-coordinate-empty', 'region_code' => '77', 'region_name' => 'Москва', 'place_name' => 'Пусто', 'display_name' => 'Пусто', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$no_coordinate_client = new WdcLocationsSmokeSuggestionClient( null, null );
$no_coordinate_enricher = new LocationCoordinateEnricher( $repository, $no_coordinate_client );
$not_enriched_location = $no_coordinate_enricher->enrich( array( 'id' => $no_coordinate_id, 'country_code' => 'RU', 'display_name' => 'Пусто', 'latitude' => 0.0, 'longitude' => 0.0 ) );
locations_smoke_assert( 1 === $no_coordinate_client->calls && ! isset( $not_enriched_location['lat'], $not_enriched_location['lng'] ), 'Missing DaData coordinates must not crash checkout enrichment and must leave search fallback available.' );
$address_runtime_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/Address/CheckoutAddressRuntime.php' );
locations_smoke_assert( str_contains( $address_runtime_source, 'LocationCoordinateEnricher' ) && str_contains( $address_runtime_source, "\$context['lat']" ) && str_contains( $address_runtime_source, "\$context['lng']" ), 'Checkout city_context must receive enriched lat/lng coordinates.' );

$coordinates_wpdb = new wpdb();
$coordinates_repository = new LocationRepository( $coordinates_wpdb );
$city_missing_id = $coordinates_repository->save( locations_smoke_location( array( 'gar_object_id' => 882001, 'fias_id' => 'fias-coord-city', 'region_code' => '38', 'region_name' => 'Иркутская', 'place_type' => 'г', 'place_name' => 'Иркутск', 'display_name' => 'Иркутск', 'latitude' => null, 'longitude' => null ) ) );
$valid_coordinates_id = $coordinates_repository->save( locations_smoke_location( array( 'gar_object_id' => 882002, 'fias_id' => 'fias-coord-valid', 'region_code' => '54', 'region_name' => 'Новосибирская', 'place_type' => 'г', 'place_name' => 'Новосибирск', 'display_name' => 'Новосибирск', 'latitude' => 55.0302, 'longitude' => 82.9204 ) ) );
$other_missing_id = $coordinates_repository->save( locations_smoke_location( array( 'gar_object_id' => 882003, 'fias_id' => 'fias-coord-other', 'region_code' => '42', 'region_name' => 'Кемеровская', 'place_type' => 'село', 'place_name' => 'Тест', 'display_name' => 'Тест', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$coordinates_repository->save( locations_smoke_location( array( 'country_code' => 'BY', 'gar_object_id' => 882004, 'fias_id' => 'fias-coord-by', 'region_code' => 'BY-MI', 'region_name' => 'Минская', 'place_type' => 'г', 'place_name' => 'Минск', 'display_name' => 'Минск', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
locations_smoke_assert( 2 === $coordinates_repository->count_locations_missing_coordinates(), 'Missing coordinates counter must include NULL and 0.0000000 RU coordinates only.' );
locations_smoke_assert( 1 === $coordinates_repository->count_locations_with_coordinates(), 'Coordinates present counter must exclude NULL/zero and non-RU rows.' );
$missing_cities = $coordinates_repository->find_locations_missing_coordinates( 10, 0, 'cities' );
$missing_others = $coordinates_repository->find_locations_missing_coordinates( 10, 0, 'others' );
locations_smoke_assert( 1 === count( $missing_cities ) && $city_missing_id === (int) $missing_cities[0]['id'], 'Missing coordinates city phase must select city rows first.' );
locations_smoke_assert( 1 === count( $missing_others ) && $other_missing_id === (int) $missing_others[0]['id'], 'Missing coordinates others phase must select non-city rows after cities.' );
locations_smoke_assert( ! in_array( $valid_coordinates_id, array_map( static fn( array $row ): int => (int) $row['id'], $coordinates_repository->find_locations_missing_coordinates( 10, 0, 'all' ) ), true ), 'Existing valid coordinates must not be selected for coordinate fill.' );
locations_smoke_assert( $coordinates_repository->update_coordinates( $city_missing_id, 52.286348, 104.280679 ) && 52.286348 === (float) $coordinates_wpdb->rows[ $city_missing_id ]['latitude'], 'update_coordinates must save latitude/longitude after columns exist.' );

$resume_wpdb = new wpdb();
$resume_repository = new LocationRepository( $resume_wpdb );
$resume_wpdb->insert_id = 4;
$resume_missing_before_id = $resume_repository->save( locations_smoke_location( array( 'gar_object_id' => 882101, 'fias_id' => 'fias-resume-before', 'region_name' => 'Resume test', 'place_name' => 'Before', 'display_name' => 'Before', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$resume_wpdb->insert_id = 9;
$resume_repository->save( locations_smoke_location( array( 'gar_object_id' => 882102, 'fias_id' => 'fias-resume-10', 'region_name' => 'Resume test', 'place_name' => 'Ten', 'display_name' => 'Ten', 'latitude' => 55.0, 'longitude' => 82.0 ) ) );
$resume_wpdb->insert_id = 19;
$resume_repository->save( locations_smoke_location( array( 'gar_object_id' => 882103, 'fias_id' => 'fias-resume-20', 'region_name' => 'Resume test', 'place_name' => 'Twenty', 'display_name' => 'Twenty', 'latitude' => 56.0, 'longitude' => 83.0 ) ) );
$resume_wpdb->insert_id = 24;
$resume_missing_inside_id = $resume_repository->save( locations_smoke_location( array( 'gar_object_id' => 882104, 'fias_id' => 'fias-resume-inside', 'region_name' => 'Resume test', 'place_name' => 'Inside', 'display_name' => 'Inside', 'latitude' => null, 'longitude' => null ) ) );
$resume_wpdb->insert_id = 29;
$resume_repository->save( locations_smoke_location( array( 'gar_object_id' => 882105, 'fias_id' => 'fias-resume-30', 'region_name' => 'Resume test', 'place_name' => 'Thirty', 'display_name' => 'Thirty', 'latitude' => 57.0, 'longitude' => 84.0 ) ) );
$resume_wpdb->insert_id = 30;
$resume_missing_after_id = $resume_repository->save( locations_smoke_location( array( 'gar_object_id' => 882106, 'fias_id' => 'fias-resume-after', 'region_name' => 'Resume test', 'place_name' => 'After', 'display_name' => 'After', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$resume_batch = $resume_repository->find_locations_missing_coordinates( 10, 0, 'all' );
$resume_batch_ids = array_map( static fn( array $row ): int => (int) $row['id'], $resume_batch );
locations_smoke_assert( array( $resume_missing_before_id, $resume_missing_inside_id, $resume_missing_after_id ) === $resume_batch_ids, 'Missing coordinates batch from start must include empty rows before and after valid coordinates.' );

$resume_search = new LocationSearchService( $resume_repository );
$resume_importer = new LocationImportService( $resume_repository );
$_POST = array( 'wdc_locations_nonce' => 'test-nonce' );
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.26.10' ),
	$resume_repository,
	$resume_search,
	$resume_importer
) )->ajax_dadata_coordinates_fill_start();
$resume_start_payload = json_decode( (string) ob_get_clean(), true );
$resume_start_job = is_array( $resume_start_payload ) ? (array) ( $resume_start_payload['data'] ?? array() ) : array();
locations_smoke_assert( 0 === (int) ( $resume_start_job['resume_after_id'] ?? -1 ) && 'from_start_missing_only' === (string) ( $resume_start_job['resume_strategy'] ?? '' ), 'Coordinate batch start must use from-start missing-only strategy.' );
locations_smoke_assert( 0 === (int) ( $resume_start_job['last_id'] ?? -1 ) && 0 === (int) ( $resume_start_job['cursor'] ?? -1 ), 'Coordinate batch start must begin from id 0.' );
locations_smoke_assert( 3 === (int) ( $resume_start_job['total'] ?? 0 ), 'Coordinate batch start total must count all missing coordinates across the database.' );
$resume_client = new WdcLocationsSmokeQueuedSuggestionClient(
	array(
		array( 'success' => true, 'suggestions' => array( array( 'data' => array( 'geo_lat' => '58.000000', 'geo_lon' => '85.000000' ) ) ) ),
		array( 'success' => true, 'suggestions' => array( array( 'data' => array( 'geo_lat' => '59.000000', 'geo_lon' => '86.000000' ) ) ) ),
		array( 'success' => true, 'suggestions' => array( array( 'data' => array( 'geo_lat' => '60.000000', 'geo_lon' => '87.000000' ) ) ) ),
	)
);
$resume_step_job = ( new LocationCoordinatesDadataBatchUpdater( $resume_repository, $resume_client ) )->step( $resume_start_job, 10 );
locations_smoke_assert( in_array( $resume_missing_before_id, (array) ( $resume_step_job['current_batch'] ?? array() ), true ) && in_array( $resume_missing_after_id, (array) ( $resume_step_job['current_batch'] ?? array() ), true ), 'Coordinate batch step must include missing rows from the beginning and end of the table.' );
locations_smoke_assert( 58.0 === (float) $resume_wpdb->rows[ $resume_missing_before_id ]['latitude'] && 59.0 === (float) $resume_wpdb->rows[ $resume_missing_inside_id ]['latitude'] && 60.0 === (float) $resume_wpdb->rows[ $resume_missing_after_id ]['latitude'], 'Coordinate batch step must update all missing rows while skipping valid coordinates.' );
locations_smoke_assert( 55.0 === (float) $resume_wpdb->rows[10]['latitude'] && 57.0 === (float) $resume_wpdb->rows[30]['latitude'], 'Coordinate batch step must not modify rows that already have valid coordinates.' );

update_option(
	'wdc_dadata_postcode_fill_job',
	array(
		'phase' => 'running',
		'status' => 'running',
		'processed' => 4,
	)
);
update_option(
	'wdc_dadata_coordinates_fill_job',
	array(
		'phase' => 'running',
		'status' => 'running',
		'processed' => 99,
		'updated' => 88,
		'last_id' => 999,
		'cursor' => 999,
		'current_batch' => array( 999 ),
	)
);
$_POST = array( 'wdc_locations_nonce' => 'test-nonce' );
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.26.10' ),
	$resume_repository,
	$resume_search,
	$resume_importer
) )->ajax_dadata_coordinates_fill_reset();
$resume_reset_json = (string) ob_get_clean();
$resume_reset_payload = json_decode( $resume_reset_json, true );
locations_smoke_assert( ! empty( $resume_reset_payload['success'] ) && 'idle' === (string) ( $resume_reset_payload['data']['phase'] ?? '' ), 'Coordinate reset must stop a running job and return idle.' );
locations_smoke_assert( ! str_contains( $resume_reset_json, 'Остановите текущую задачу' ), 'Coordinate reset must not return the old stop-before-reset error.' );
locations_smoke_assert( ! array_key_exists( 'wdc_dadata_coordinates_fill_job', $GLOBALS['wdc_locations_smoke_options'] ), 'Coordinate reset must clear job state before the next start.' );
locations_smoke_assert( isset( $GLOBALS['wdc_locations_smoke_options']['wdc_dadata_postcode_fill_job'] ) && 'running' === (string) $GLOBALS['wdc_locations_smoke_options']['wdc_dadata_postcode_fill_job']['phase'], 'Coordinate reset must not touch DaData postcode job state.' );
$resume_reset_missing_id = $resume_repository->save( locations_smoke_location( array( 'gar_object_id' => 882107, 'fias_id' => 'fias-reset-new-missing', 'region_name' => 'Resume test', 'place_name' => 'New missing', 'display_name' => 'New missing', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
locations_smoke_assert( 60.0 === (float) $resume_wpdb->rows[ $resume_missing_after_id ]['latitude'] && 87.0 === (float) $resume_wpdb->rows[ $resume_missing_after_id ]['longitude'], 'Coordinate reset must not delete coordinates already saved in the locations table.' );
$_POST = array( 'wdc_locations_nonce' => 'test-nonce' );
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.26.10' ),
	$resume_repository,
	$resume_search,
	$resume_importer
) )->ajax_dadata_coordinates_fill_start();
$resume_restart_payload = json_decode( (string) ob_get_clean(), true );
locations_smoke_assert( 0 === (int) ( $resume_restart_payload['data']['resume_after_id'] ?? -1 ) && 'from_start_missing_only' === (string) ( $resume_restart_payload['data']['resume_strategy'] ?? '' ), 'Coordinate batch start after reset must keep resume_after_id at 0.' );
locations_smoke_assert( 0 === (int) ( $resume_restart_payload['data']['last_id'] ?? -1 ) && 0 === (int) ( $resume_restart_payload['data']['cursor'] ?? -1 ) && array() === (array) ( $resume_restart_payload['data']['current_batch'] ?? array( 999 ) ), 'Coordinate batch start after reset must not continue old cursor or current batch.' );
locations_smoke_assert( 1 === (int) ( $resume_restart_payload['data']['total'] ?? 0 ) && $resume_reset_missing_id > 0, 'Coordinate batch start after reset must count current missing rows from the beginning.' );

$from_start_wpdb = new wpdb();
$from_start_repository = new LocationRepository( $from_start_wpdb );
$from_start_missing_id = $from_start_repository->save( locations_smoke_location( array( 'gar_object_id' => 882201, 'fias_id' => 'fias-from-start', 'region_name' => 'Resume test', 'place_name' => 'From start', 'display_name' => 'From start', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
locations_smoke_assert( array( $from_start_missing_id ) === array_map( static fn( array $row ): int => (int) $row['id'], $from_start_repository->find_locations_missing_coordinates( 10, 0, 'all' ) ), 'Coordinate batch must start from the beginning when no coordinates exist.' );

$nothing_after_wpdb = new wpdb();
$nothing_after_repository = new LocationRepository( $nothing_after_wpdb );
$nothing_after_wpdb->insert_id = 4;
$nothing_after_repository->save( locations_smoke_location( array( 'gar_object_id' => 882301, 'fias_id' => 'fias-nothing-before', 'region_name' => 'Resume test', 'place_name' => 'Before empty', 'display_name' => 'Before empty', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$nothing_after_wpdb->insert_id = 9;
$nothing_after_repository->save( locations_smoke_location( array( 'gar_object_id' => 882302, 'fias_id' => 'fias-nothing-last', 'region_name' => 'Resume test', 'place_name' => 'Last filled', 'display_name' => 'Last filled', 'latitude' => 55.0, 'longitude' => 82.0 ) ) );
$_POST = array( 'wdc_locations_nonce' => 'test-nonce' );
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.26.10' ),
	$nothing_after_repository,
	new LocationSearchService( $nothing_after_repository ),
	new LocationImportService( $nothing_after_repository )
) )->ajax_dadata_coordinates_fill_start();
$nothing_after_payload = json_decode( (string) ob_get_clean(), true );
locations_smoke_assert( ! empty( $nothing_after_payload['success'] ) && 'running' === (string) ( $nothing_after_payload['data']['phase'] ?? '' ) && 1 === (int) ( $nothing_after_payload['data']['total'] ?? -1 ), 'Coordinate batch start must include missing rows before valid coordinate rows.' );

$batch_wpdb = new wpdb();
$batch_repository = new LocationRepository( $batch_wpdb );
$batch_success_id = $batch_repository->save( locations_smoke_location( array( 'gar_object_id' => 883001, 'fias_id' => 'fias-batch-success', 'region_name' => 'Иркутская', 'place_type' => 'г', 'place_name' => 'Не должен попасть в query', 'display_name' => 'г Иркутск', 'postal_code' => '664000', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$batch_empty_query_id = $batch_repository->save( locations_smoke_location( array( 'gar_object_id' => 883002, 'fias_id' => 'fias-batch-empty-query', 'region_name' => 'Томская', 'place_type' => 'г', 'place_name' => 'Пустое имя', 'display_name' => '', 'postal_code' => '634000', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$batch_wpdb->rows[ $batch_empty_query_id ]['display_name'] = '';
$batch_no_success_id = $batch_repository->save( locations_smoke_location( array( 'gar_object_id' => 883003, 'fias_id' => 'fias-batch-no-success', 'region_name' => 'Карелия', 'place_type' => 'г', 'place_name' => 'Сортавала', 'display_name' => 'респ Карелия, г Сортавала, поселок Уусикюля', 'postal_code' => '186752', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$batch_no_coordinates_id = $batch_repository->save( locations_smoke_location( array( 'gar_object_id' => 883004, 'fias_id' => 'fias-batch-empty', 'region_name' => 'Томская', 'place_type' => 'г', 'place_name' => 'Пусто', 'display_name' => 'Пусто', 'postal_code' => '999999999', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$batch_invalid_id = $batch_repository->save( locations_smoke_location( array( 'gar_object_id' => 883005, 'fias_id' => 'fias-batch-invalid', 'region_name' => 'Саха', 'place_type' => 'г', 'place_name' => 'Нерюнгри', 'display_name' => 'респ Саха (Якутия), Нерюнгринский р-н, г Нерюнгри', 'postal_code' => '678960', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$batch_failed_id = $batch_repository->save( locations_smoke_location( array( 'gar_object_id' => 883006, 'fias_id' => 'fias-batch-error', 'region_name' => 'Красноярский', 'place_type' => 'г', 'place_name' => 'Ошибка', 'display_name' => 'Ошибка', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$batch_client = new WdcLocationsSmokeQueuedSuggestionClient(
	array(
		array( 'success' => true, 'suggestions' => array( array( 'data' => array( 'geo_lat' => '52.286348', 'geo_lon' => '104.280679' ) ) ) ),
		array( 'success' => false, 'error_message' => 'DaData rejected query.' ),
		array( 'success' => true, 'suggestions' => array() ),
		array( 'success' => true, 'suggestions' => array( array( 'data' => array( 'geo_lat' => '0', 'geo_lon' => '0' ) ) ) ),
		new RuntimeException( 'DaData temporary error.' ),
	)
);
$batch_updater = new LocationCoordinatesDadataBatchUpdater( $batch_repository, $batch_client );
$batch_job = $batch_updater->step(
	array(
		'phase' => 'running',
		'status' => 'running',
		'processed' => 0,
		'updated' => 0,
		'skipped' => 0,
		'failed' => 0,
		'errors' => 0,
		'last_id' => 0,
		'cursor' => 0,
		'current_priority' => 'cities',
		'started_at' => current_time( 'mysql' ),
	),
	10
);
locations_smoke_assert( 6 === $batch_job['processed'] && 1 === $batch_job['updated'] && 4 === $batch_job['skipped'] && 1 === $batch_job['failed'] && 1 === $batch_job['errors'], 'Coordinate batch updater must update, skip explainable DaData responses, and continue after one error.' );
locations_smoke_assert( 1 === $batch_job['skipped_empty_query'] && 1 === $batch_job['skipped_no_dadata_success'] && 1 === $batch_job['skipped_no_coordinates'] && 1 === $batch_job['skipped_invalid_coordinates'], 'Coordinate batch updater must expose per-reason skipped counters.' );
locations_smoke_assert( 52.286348 === (float) $batch_wpdb->rows[ $batch_success_id ]['latitude'] && 104.280679 === (float) $batch_wpdb->rows[ $batch_success_id ]['longitude'], 'Coordinate batch updater must persist valid DaData coordinates.' );
locations_smoke_assert( array( '664000, г Иркутск', '186752, респ Карелия, г Сортавала, поселок Уусикюля', 'Пусто', '678960, респ Саха (Якутия), Нерюнгринский р-н, г Нерюнгри', 'Ошибка' ) === $batch_client->queries, 'Coordinate DaData query must use postal_code only when it is present and not the 999999999 marker.' );
locations_smoke_assert( ! str_contains( implode( ' | ', $batch_client->queries ), 'Не должен попасть в query' ), 'Coordinate DaData query must not append place_name/region fields separately.' );
locations_smoke_assert( 'invalid_coordinates' === $batch_job['last_skip_reason'] || '' !== (string) $batch_job['last_error'], 'Coordinate batch updater must keep last skip/error diagnostics in job state.' );
locations_smoke_assert( $batch_empty_query_id > 0 && $batch_no_success_id > 0 && $batch_no_coordinates_id > 0 && $batch_invalid_id > 0 && $batch_failed_id > 0 && (int) $batch_job['cursor'] >= $batch_failed_id, 'Coordinate batch updater must advance cursor through current batch.' );

$limit_wpdb = new wpdb();
$limit_repository = new LocationRepository( $limit_wpdb );
$limit_updated_id = $limit_repository->save( locations_smoke_location( array( 'gar_object_id' => 884001, 'fias_id' => 'fias-limit-updated', 'region_name' => 'Limit test', 'place_type' => 'г', 'place_name' => 'First', 'display_name' => 'First', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$limit_stopped_id = $limit_repository->save( locations_smoke_location( array( 'gar_object_id' => 884002, 'fias_id' => 'fias-limit-stopped', 'region_name' => 'Limit test', 'place_type' => 'г', 'place_name' => 'Limit', 'display_name' => 'Limit', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$limit_untouched_id = $limit_repository->save( locations_smoke_location( array( 'gar_object_id' => 884003, 'fias_id' => 'fias-limit-untouched', 'region_name' => 'Limit test', 'place_type' => 'г', 'place_name' => 'Third', 'display_name' => 'Third', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$limit_client = new WdcLocationsSmokeQueuedSuggestionClient(
	array(
		array( 'success' => true, 'suggestions' => array( array( 'data' => array( 'geo_lat' => '54.983333', 'geo_lon' => '82.900000' ) ) ) ),
		array( 'success' => false, 'error_code' => 'dadata_daily_limit_exhausted', 'error_message' => 'All DaData tokens are exhausted for today.' ),
		array( 'success' => true, 'suggestions' => array( array( 'data' => array( 'geo_lat' => '55.000000', 'geo_lon' => '83.000000' ) ) ) ),
	)
);
$limit_updater = new LocationCoordinatesDadataBatchUpdater( $limit_repository, $limit_client );
$limit_job = $limit_updater->step(
	array(
		'phase' => 'running',
		'status' => 'running',
		'processed' => 0,
		'updated' => 0,
		'skipped' => 0,
		'failed' => 0,
		'errors' => 0,
		'last_id' => 0,
		'cursor' => 0,
		'current_priority' => 'cities',
		'started_at' => current_time( 'mysql' ),
	),
	10
);
locations_smoke_assert( 2 === $limit_job['processed'] && 1 === $limit_job['updated'] && 0 === $limit_job['skipped'] && 2 === $limit_client->calls, 'Coordinate batch must stop immediately when all DaData daily limits are exhausted.' );
locations_smoke_assert( 'finished' === $limit_job['phase'] && 'daily_limit_exhausted' === (string) $limit_job['stopped_reason'] && ! empty( $limit_job['tokens_exhausted'] ), 'Coordinate exhausted limit must be recorded as a terminal non-running job state.' );
locations_smoke_assert( $limit_updated_id === (int) $limit_job['last_id'] && $limit_updated_id === (int) $limit_job['cursor'], 'Coordinate exhausted limit must keep cursor on the previous processed id so the stopped row is retried.' );
locations_smoke_assert( 54.983333 === (float) $limit_wpdb->rows[ $limit_updated_id ]['latitude'], 'Coordinate exhausted limit must preserve progress saved before the stop.' );
locations_smoke_assert( 0.0 === (float) $limit_wpdb->rows[ $limit_stopped_id ]['latitude'] && 0.0 === (float) $limit_wpdb->rows[ $limit_untouched_id ]['latitude'], 'Coordinate exhausted limit must not update or skip the stopped and remaining rows.' );
$limit_job_after_repeat = $limit_updater->step( $limit_job, 10 );
locations_smoke_assert( 2 === $limit_client->calls && $limit_job_after_repeat === $limit_job, 'Coordinate exhausted state must not continue automatically on the next step.' );
$limit_resume_client = new WdcLocationsSmokeQueuedSuggestionClient(
	array(
		array( 'success' => true, 'suggestions' => array( array( 'data' => array( 'geo_lat' => '55.000000', 'geo_lon' => '83.000000' ) ) ) ),
	)
);
$limit_resume_job = $limit_job;
$limit_resume_job['phase'] = 'running';
$limit_resume_job['status'] = 'running';
$limit_resume_job['reason'] = '';
$limit_resume_job['stopped_reason'] = '';
$limit_resume_job['tokens_exhausted'] = false;
$limit_resume_job['current_batch'] = array();
$limit_resume_result = ( new LocationCoordinatesDadataBatchUpdater( $limit_repository, $limit_resume_client ) )->step( $limit_resume_job, 1 );
locations_smoke_assert( array( $limit_stopped_id ) === (array) ( $limit_resume_result['current_batch'] ?? array() ) && 55.0 === (float) $limit_wpdb->rows[ $limit_stopped_id ]['latitude'], 'Coordinate resumed batch must retry the row that hit daily limit exhaustion.' );

$limit_admin_repository = new LocationRepository( new wpdb() );
$limit_admin_search = new LocationSearchService( $limit_admin_repository );
$limit_admin_importer = new LocationImportService( $limit_admin_repository );
update_option(
	'wdc_dadata_coordinates_fill_job',
	array(
		'phase' => 'finished',
		'status' => 'finished',
		'reason' => 'daily_limit_exhausted',
		'stopped_reason' => 'daily_limit_exhausted',
		'tokens_exhausted' => true,
		'processed' => 12,
		'updated' => 7,
		'skipped' => 2,
		'failed' => 1,
		'errors' => 1,
		'last_id' => 100,
		'cursor' => 100,
		'current_priority' => 'others',
		'current_batch' => array( 101 ),
		'total' => 200,
		'message' => 'All DaData tokens are exhausted for today.',
		'last_error' => 'All DaData tokens are exhausted for today.',
	)
);
$_POST = array( 'wdc_locations_nonce' => 'test-nonce' );
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.26.10' ),
	$limit_admin_repository,
	$limit_admin_search,
	$limit_admin_importer
) )->ajax_dadata_coordinates_fill_start();
$limit_admin_resume_payload = json_decode( (string) ob_get_clean(), true );
$limit_admin_resume_job = is_array( $limit_admin_resume_payload ) ? (array) ( $limit_admin_resume_payload['data'] ?? array() ) : array();
locations_smoke_assert( 'running' === (string) ( $limit_admin_resume_job['phase'] ?? '' ) && 'running' === (string) ( $limit_admin_resume_job['status'] ?? '' ), 'Coordinate start must resume a job stopped by daily limits.' );
locations_smoke_assert( 100 === (int) ( $limit_admin_resume_job['last_id'] ?? 0 ) && 100 === (int) ( $limit_admin_resume_job['cursor'] ?? 0 ) && 'others' === (string) ( $limit_admin_resume_job['current_priority'] ?? '' ), 'Coordinate start after daily limits must preserve cursor and priority.' );
locations_smoke_assert( empty( $limit_admin_resume_job['tokens_exhausted'] ) && '' === (string) ( $limit_admin_resume_job['reason'] ?? '' ) && '' === (string) ( $limit_admin_resume_job['stopped_reason'] ?? '' ) && '' === (string) ( $limit_admin_resume_job['message'] ?? '' ), 'Coordinate start after daily limits must clear limit diagnostics.' );
locations_smoke_assert( array() === (array) ( $limit_admin_resume_job['current_batch'] ?? array( 101 ) ) && 12 === (int) ( $limit_admin_resume_job['processed'] ?? 0 ), 'Coordinate start after daily limits must clear current batch while preserving progress counters.' );

$novos = $search->search( 'новос' );
locations_smoke_assert( count( $novos ) > 0, 'Search "новос" must return results.' );
locations_smoke_assert( '' !== $novos[0]->display_name, 'Search result display_name must be present.' );

$grouped = $search->grouped( 'новос' );
locations_smoke_assert( isset( $grouped['Новосибирская область'] ), 'Grouped search must contain Novosibirsk region.' );

$exact = $search->search( 'новосибирск', 5 );
locations_smoke_assert( isset( $exact[0] ) && '' !== $exact[0]->display_name, 'Exact city match must rank first.' );

$repository->save( locations_smoke_location( array( 'gar_object_id' => 900001, 'gar_id' => 'gar-admin-gb', 'fias_id' => 'fias-admin-gb', 'kladr_id' => 'kladr-admin-gb', 'region_code' => '54', 'region_name' => 'Новосибирская', 'district_name' => 'Новосибирский', 'district_type' => 'р-н', 'place_name' => 'Гусиный Брод', 'place_type' => 'село', 'display_name' => 'Новосибирская обл., Новосибирский р-н, село Гусиный Брод', 'postal_code' => '630555' ) ) );
$repository->save( locations_smoke_location( array( 'gar_object_id' => 900002, 'fias_id' => 'fias-admin-verh', 'region_code' => '54', 'region_name' => 'Новосибирская', 'place_name' => 'Верхобродово', 'place_type' => 'д', 'display_name' => 'Новосибирская обл., деревня Верхобродово' ) ) );
$repository->save( locations_smoke_location( array( 'gar_object_id' => 900003, 'fias_id' => 'fias-admin-brod', 'region_code' => '54', 'region_name' => 'Новосибирская', 'place_name' => 'Брод', 'place_type' => 'д', 'display_name' => 'Новосибирская обл., деревня Брод' ) ) );
$repository->save( locations_smoke_location( array( 'gar_object_id' => 900004, 'fias_id' => 'fias-admin-mo-ivan', 'region_code' => '50', 'region_name' => 'Московская', 'place_name' => 'Ивановка', 'place_type' => 'село', 'display_name' => 'Московская обл., село Ивановка', 'postal_code' => '140000' ) ) );
$repository->save( locations_smoke_location( array( 'gar_object_id' => 900005, 'fias_id' => 'fias-admin-mo-postal', 'region_code' => '50', 'region_name' => 'Московская', 'place_name' => 'Почтовый', 'place_type' => 'село', 'display_name' => 'Московская обл., село Почтовый', 'postal_code' => '140000' ) ) );
$admin_hierarchy = new CheckoutLocationSearch( $search );
$admin_gusiny = $admin_hierarchy->search_paginated( 'гусиный брод', 1, 20 )['items'];
locations_smoke_assert( isset( $admin_gusiny[0] ) && 'fias-admin-gb' === $admin_gusiny[0]->fias_id, 'Admin hierarchy search must find Гусиный Брод first.' );
$admin_brod_ids = array_map( static fn( Location $location ): string => $location->fias_id, $admin_hierarchy->search_paginated( 'брод', 1, 20 )['items'] );
locations_smoke_assert( in_array( 'fias-admin-brod', $admin_brod_ids, true ) && ! in_array( 'fias-admin-verh', $admin_brod_ids, true ), 'Admin hierarchy search must use exact/prefix only and not show Верхобродово for брод.' );
locations_smoke_assert( '50' === (string) ( $admin_hierarchy->search_paginated( 'мо', 1, 20 )['items'][0]->region_code ?? '' ), 'Admin hierarchy search must support МО alias.' );
locations_smoke_assert( 'fias-admin-mo-ivan' === ( $admin_hierarchy->search_paginated( 'мо ивановка', 1, 20 )['items'][0]->fias_id ?? '' ), 'Admin hierarchy search must prioritize Ивановка in Moscow region for МО.' );
locations_smoke_assert( 1 === count( $admin_hierarchy->search_paginated( 'fias-admin-gb', 1, 20 )['items'] ) && 'fias-admin-gb' === $admin_hierarchy->search_paginated( 'fias-admin-gb', 1, 20 )['items'][0]->fias_id, 'Admin exact fias_id lookup must return only one location.' );
locations_smoke_assert( 1 === count( $admin_hierarchy->search_paginated( 'gar-admin-gb', 1, 20 )['items'] ) && 'fias-admin-gb' === $admin_hierarchy->search_paginated( 'gar-admin-gb', 1, 20 )['items'][0]->fias_id, 'Admin exact gar_id lookup must return only one location.' );
locations_smoke_assert( 1 === count( $admin_hierarchy->search_paginated( 'kladr-admin-gb', 1, 20 )['items'] ) && 'fias-admin-gb' === $admin_hierarchy->search_paginated( 'kladr-admin-gb', 1, 20 )['items'][0]->fias_id, 'Admin exact kladr_id lookup must return only one location.' );
$admin_postal = $admin_hierarchy->search_paginated( '140000', 1, 20 )['items'];
locations_smoke_assert( 2 === count( $admin_postal ) && array() === array_filter( $admin_postal, static fn( Location $location ): bool => '140000' !== $location->postal_code ), 'Admin exact postal_code lookup must return only matching postal_code rows.' );

$fallback = ( new FallbackAddressNormalizer() )->normalize( 'Новосибирск, Красный проспект, 1' );
locations_smoke_assert( false === $fallback->success, 'Fallback normalizer must not report success.' );
locations_smoke_assert( 'fallback' === $fallback->source, 'Fallback normalizer source must be fallback.' );
locations_smoke_assert( 'Новосибирск, Красный проспект, 1' === $fallback->address->raw_address, 'Fallback normalizer must preserve raw address.' );

$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET                      = array();
$_POST                     = array();
update_option(
	LocationCountryIndexService::OPTION,
	array(
		'countries'  => array( 'RU', 'BY', 'ZZ' ),
		'counts'     => array(
			'RU' => 123,
			'BY' => 456,
			'ZZ' => 7,
		),
		'stale'      => false,
		'rebuilt_at' => '2026-05-21 12:00:00',
	)
);
$wpdb->get_var_queries = array();
$wpdb->get_results_queries = array();
$wpdb->country_counts_calls = 0;
unset( $GLOBALS['wdc_locations_smoke_options']['wdc_locations_display_name_rebuild_job'], $GLOBALS['wdc_locations_smoke_options']['wdc_dadata_coordinates_fill_job'] );
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.12.13' ),
	$repository,
	$search,
	$importer,
	country_index: $country_index
) )->render_page();
$locations_default_html = (string) ob_get_clean();
locations_smoke_assert( str_contains( $locations_default_html, 'wdc_locations_deep_counts=1' ), 'Locations admin default page must render an explicit deep counters link.' );
locations_smoke_assert( ! str_contains( $locations_default_html, 'RU Р РѕСЃСЃРёСЏ (123)' ), 'Locations admin default page must not calculate country summary counts.' );
locations_smoke_assert( ! str_contains( $locations_default_html, 'РєРѕРѕСЂРґРёРЅР°С‚ РЅРµС‚:' ), 'Locations admin default page must not calculate coordinate counters.' );
locations_smoke_assert( array() === $wpdb->get_results_queries, 'Opening locations admin without search must not call paginated/full location search.' );
$default_get_var_sql = implode( "\n", $wpdb->get_var_queries );
locations_smoke_assert( ! str_contains( $default_get_var_sql, 'wdc_location_aliases' ), 'Opening locations admin without deep counts must not count aliases.' );
locations_smoke_assert( ! str_contains( $default_get_var_sql, 'postal_code IS NULL' ) && ! str_contains( $default_get_var_sql, 'postal_code IS NOT NULL' ), 'Opening locations admin without deep counts must not run postal_code fill counters.' );
locations_smoke_assert( ! str_contains( $default_get_var_sql, 'latitude IS NULL' ) && ! str_contains( $default_get_var_sql, 'latitude IS NOT NULL' ), 'Opening locations admin without deep counts must not run coordinate counters.' );
locations_smoke_assert( 0 === $wpdb->country_counts_calls, 'Opening locations admin without deep counts must not rebuild/load country count index.' );
locations_smoke_assert( ! array_key_exists( 'wdc_locations_display_name_rebuild_job', $GLOBALS['wdc_locations_smoke_options'] ), 'Opening locations admin must not trigger display-name/alias rebuild jobs.' );
locations_smoke_assert( ! array_key_exists( 'wdc_dadata_coordinates_fill_job', $GLOBALS['wdc_locations_smoke_options'] ), 'Opening locations admin must not trigger coordinate jobs.' );
$_GET = array( 'wdc_locations_deep_counts' => '1' );
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.12.13' ),
	$repository,
	$search,
	$importer,
	country_index: $country_index
) )->render_page();
$locations_html = (string) ob_get_clean();
locations_smoke_assert( str_contains( $locations_html, 'Страны в базе:' ), 'Locations admin page must render country summary label.' );
locations_smoke_assert( str_contains( $locations_html, 'RU Россия (123)' ), 'Locations admin country summary must render RU name and count.' );
locations_smoke_assert( str_contains( $locations_html, 'BY Беларусь (456)' ), 'Locations admin country summary must render BY name and count.' );
locations_smoke_assert( str_contains( $locations_html, 'ZZ (7)' ), 'Locations admin country summary must fall back to country code without WooCommerce name.' );
locations_smoke_assert( str_contains( $locations_html, 'Регионов/областей:' ), 'Locations admin page must render regions counter label.' );
locations_smoke_assert( str_contains( $locations_html, 'Заполнение информации через DaData' ), 'Locations admin DaData block must use the shared information fill heading.' );
locations_smoke_assert( str_contains( $locations_html, 'Получить координаты через DaData' ) && str_contains( $locations_html, 'координат нет:' ), 'Locations admin page must render coordinate fill button and counters.' );
locations_smoke_assert( str_contains( $locations_html, 'Обнулить задачу координат' ) && str_contains( $locations_html, 'wdc_dadata_coordinates_fill_reset' ), 'Locations admin page must render coordinate reset button and AJAX action.' );

locations_smoke_assert( str_contains( $locations_html, 'Очистить базу населенных пунктов' ), 'Locations admin page must render clear locations button.' );
locations_smoke_assert( str_contains( $locations_html, 'Импорт GAR/ФИАС CSV' ), 'Locations admin page must render GAR CSV import section.' );
locations_smoke_assert( str_contains( $locations_html, 'Экспорт / импорт подготовленной базы' ), 'Locations admin page must render snapshot section.' );
locations_smoke_assert( ! str_contains( $locations_html, 'Импортировать демо-данные' ), 'Locations admin page must not render demo import button.' );
locations_smoke_assert( ! str_contains( $locations_html, 'Import prepared FIAS dataset' ), 'Locations admin page must not render prepared FIAS import button.' );
locations_smoke_assert( str_contains( $locations_html, 'Удалить все населенные пункты и алиасы из локальной базы WDC?' ), 'Locations admin page must render JS confirmation.' );

$reset_wpdb = new wpdb();
$reset_repository = new LocationRepository( $reset_wpdb );
$reset_importer = new LocationImportService( $reset_repository );
$reset_search = new LocationSearchService( $reset_repository );
$reset_coordinate_id = $reset_repository->save( locations_smoke_location( array( 'gar_object_id' => 885001, 'fias_id' => 'fias-reset-coordinates', 'region_name' => 'Reset test', 'place_type' => 'г', 'place_name' => 'Saved', 'display_name' => 'Saved', 'latitude' => 56.0, 'longitude' => 84.0 ) ) );
update_option(
	'wdc_dadata_coordinates_fill_job',
	array(
		'phase' => 'finished',
		'status' => 'finished',
		'processed' => 12,
		'updated' => 7,
		'skipped' => 3,
	)
);
$_POST = array( 'wdc_locations_nonce' => 'test-nonce' );
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.25.14' ),
	$reset_repository,
	$reset_search,
	$reset_importer
) )->ajax_dadata_coordinates_fill_reset();
$reset_json = (string) ob_get_clean();
$reset_payload = json_decode( $reset_json, true );
locations_smoke_assert( is_array( $reset_payload ) && ! empty( $reset_payload['success'] ) && 'idle' === (string) ( $reset_payload['data']['phase'] ?? '' ), 'Coordinate reset AJAX must return idle status.' );
locations_smoke_assert( ! array_key_exists( 'wdc_dadata_coordinates_fill_job', $GLOBALS['wdc_locations_smoke_options'] ), 'Coordinate reset AJAX must delete only the coordinate job option.' );
locations_smoke_assert( 56.0 === (float) $reset_wpdb->rows[ $reset_coordinate_id ]['latitude'] && 84.0 === (float) $reset_wpdb->rows[ $reset_coordinate_id ]['longitude'], 'Coordinate reset AJAX must not delete saved coordinates.' );
update_option(
	'wdc_dadata_coordinates_fill_job',
	array(
		'phase' => 'canceled',
		'status' => 'canceled',
		'processed' => 2,
	)
);
$_POST = array( 'wdc_locations_nonce' => 'test-nonce' );
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.26.10' ),
	$reset_repository,
	$reset_search,
	$reset_importer
) )->ajax_dadata_coordinates_fill_reset();
$reset_canceled_payload = json_decode( (string) ob_get_clean(), true );
locations_smoke_assert( is_array( $reset_canceled_payload ) && ! empty( $reset_canceled_payload['success'] ) && 'idle' === (string) ( $reset_canceled_payload['data']['phase'] ?? '' ), 'Coordinate reset AJAX must also reset canceled jobs to idle.' );
locations_smoke_assert( ! array_key_exists( 'wdc_dadata_coordinates_fill_job', $GLOBALS['wdc_locations_smoke_options'] ), 'Coordinate reset AJAX must delete canceled coordinate job state.' );

$rp_wpdb = new wpdb();
$rp_repository = new LocationRepository( $rp_wpdb );
$rp_city_id = $rp_repository->save( locations_smoke_location( array( 'gar_object_id' => 886001, 'fias_id' => 'fias-rp-city', 'region_name' => 'Новосибирская', 'country_code' => 'RU', 'city_type' => 'г', 'place_type' => 'г', 'place_name' => 'Новосибирск', 'display_name' => 'г Новосибирск', 'postal_code' => '630000' ) ) );
$rp_duplicate_id = $rp_repository->save( locations_smoke_location( array( 'gar_object_id' => 886002, 'fias_id' => 'fias-rp-city-copy', 'region_name' => 'Новосибирская', 'country_code' => 'RU', 'city_type' => 'г', 'place_type' => 'г', 'place_name' => 'Новосибирск copy', 'display_name' => 'г Новосибирск copy', 'postal_code' => '630000' ) ) );
$rp_settlement_id = $rp_repository->save( locations_smoke_location( array( 'gar_object_id' => 886003, 'fias_id' => 'fias-rp-village', 'region_name' => 'Новосибирская', 'country_code' => 'RU', 'place_type' => 'село', 'place_name' => 'Гусиный Брод', 'display_name' => 'село Гусиный Брод', 'postal_code' => '630555' ) ) );
$rp_wpdb->russian_post_pickup_rows = array(
	array( 'active' => 1, 'location_id' => $rp_city_id, 'fias_location_guid' => 'ignored', 'postcode' => '630099' ),
	array( 'active' => 1, 'location_id' => 0, 'fias_location_guid' => 'fias-rp-village', 'postcode' => '630777' ),
);
$GLOBALS['wdc_locations_probe_requests'] = array();
$GLOBALS['wdc_locations_probe_success_postcodes'] = array( '630099', '630777' );
$rp_service = new RussianPostCourierCalcPostcodeFillStateService( $rp_repository, new RussianPostCourierTariffProbeService( new Logger() ), $rp_wpdb );
$rp_job = $rp_service->create_job();
$rp_job = $rp_service->step( $rp_job );
locations_smoke_assert( $rp_city_id === (int) ( $GLOBALS['wdc_locations_probe_requests'][0]['to'] === '630000' ? $rp_city_id : 0 ), 'Russian Post courier postcode fill must probe city base postcode first.' );
locations_smoke_assert( '630099' === (string) $rp_wpdb->rows[ $rp_city_id ]['russianpost_courier_calc_postal_code'] && '630099' === (string) $rp_wpdb->rows[ $rp_duplicate_id ]['russianpost_courier_calc_postal_code'], 'Russian Post courier postcode fill must save first successful candidate for all empty rows with same postal_code.' );
locations_smoke_assert( (int) ( $rp_job['step_probes'] ?? 0 ) <= RussianPostCourierCalcPostcodeFillStateService::MAX_PROBES_PER_STEP, 'Russian Post courier postcode fill step must not exceed probe request limit.' );
$rp_job = $rp_service->step( $rp_job );
locations_smoke_assert( '630777' === (string) $rp_wpdb->rows[ $rp_settlement_id ]['russianpost_courier_calc_postal_code'], 'Russian Post courier postcode fill must use fias_location_guid fallback candidates.' );
locations_smoke_assert( 0 === (int) ( $rp_job['failed'] ?? 0 ) && 0 === (int) ( $rp_job['errors'] ?? 0 ) && 0 === (int) ( $rp_job['consecutive_errors'] ?? 0 ), 'Russian Post courier postcode fill must not count unavailable candidates as failed/errors.' );

$rp_sequence_wpdb = new wpdb();
$rp_sequence_repository = new LocationRepository( $rp_sequence_wpdb );
$rp_sequence_id = $rp_sequence_repository->save( locations_smoke_location( array( 'gar_object_id' => 886201, 'fias_id' => 'fias-rp-sequence', 'region_name' => 'Sequence', 'country_code' => 'RU', 'city_type' => 'г', 'place_type' => 'г', 'place_name' => 'Sequence', 'display_name' => 'Sequence', 'postal_code' => '640000' ) ) );
$rp_sequence_wpdb->russian_post_pickup_rows = array(
	array( 'active' => 1, 'location_id' => $rp_sequence_id, 'fias_location_guid' => '', 'postcode' => '640001' ),
	array( 'active' => 1, 'location_id' => $rp_sequence_id, 'fias_location_guid' => '', 'postcode' => '640002' ),
);
$GLOBALS['wdc_locations_probe_requests'] = array();
$GLOBALS['wdc_locations_probe_success_postcodes'] = array( '640002' );
$GLOBALS['wdc_locations_probe_api_error_postcodes'] = array();
$rp_sequence_service = new RussianPostCourierCalcPostcodeFillStateService( $rp_sequence_repository, new RussianPostCourierTariffProbeService( new Logger() ), $rp_sequence_wpdb );
$rp_sequence_job = $rp_sequence_service->step( $rp_sequence_service->create_job() );
locations_smoke_assert( '640002' === (string) $rp_sequence_wpdb->rows[ $rp_sequence_id ]['russianpost_courier_calc_postal_code'] && 0 === (int) ( $rp_sequence_job['failed'] ?? 0 ), 'Russian Post courier postcode fill must save first successful candidate after unavailable candidates.' );

$rp_unavailable_wpdb = new wpdb();
$rp_unavailable_repository = new LocationRepository( $rp_unavailable_wpdb );
$rp_unavailable_id = $rp_unavailable_repository->save( locations_smoke_location( array( 'gar_object_id' => 886211, 'fias_id' => 'fias-rp-unavailable', 'region_name' => 'Unavailable', 'country_code' => 'RU', 'city_type' => 'г', 'place_type' => 'г', 'place_name' => 'Unavailable', 'display_name' => 'Unavailable', 'postal_code' => '650000' ) ) );
$rp_after_unavailable_id = $rp_unavailable_repository->save( locations_smoke_location( array( 'gar_object_id' => 886212, 'fias_id' => 'fias-rp-after-unavailable', 'region_name' => 'Unavailable', 'country_code' => 'RU', 'city_type' => 'г', 'place_type' => 'г', 'place_name' => 'After', 'display_name' => 'After', 'postal_code' => '650100' ) ) );
$GLOBALS['wdc_locations_probe_requests'] = array();
$GLOBALS['wdc_locations_probe_success_postcodes'] = array( '650100' );
$GLOBALS['wdc_locations_probe_api_error_postcodes'] = array();
$rp_unavailable_service = new RussianPostCourierCalcPostcodeFillStateService( $rp_unavailable_repository, new RussianPostCourierTariffProbeService( new Logger() ), $rp_unavailable_wpdb );
$rp_unavailable_job = $rp_unavailable_service->step( $rp_unavailable_service->create_job() );
locations_smoke_assert( 1 === (int) ( $rp_unavailable_job['marked_no_index'] ?? 0 ) && 0 === (int) ( $rp_unavailable_job['failed'] ?? 0 ) && '' === (string) ( $rp_unavailable_wpdb->rows[ $rp_unavailable_id ]['russianpost_courier_calc_postal_code'] ?? '' ), 'Russian Post courier postcode fill must treat all-unavailable candidates as no-index, not failed.' );
$rp_unavailable_job = $rp_unavailable_service->step( $rp_unavailable_job );
locations_smoke_assert( '650100' === (string) $rp_unavailable_wpdb->rows[ $rp_after_unavailable_id ]['russianpost_courier_calc_postal_code'], 'Russian Post courier postcode fill must continue to next location after all candidates are unavailable.' );

$rp_api_error_wpdb = new wpdb();
$rp_api_error_repository = new LocationRepository( $rp_api_error_wpdb );
$rp_api_error_repository->save( locations_smoke_location( array( 'gar_object_id' => 886221, 'fias_id' => 'fias-rp-api-error', 'region_name' => 'ApiError', 'country_code' => 'RU', 'city_type' => 'г', 'place_type' => 'г', 'place_name' => 'ApiError', 'display_name' => 'ApiError', 'postal_code' => '660000' ) ) );
$GLOBALS['wdc_locations_probe_requests'] = array();
$GLOBALS['wdc_locations_probe_success_postcodes'] = array();
$GLOBALS['wdc_locations_probe_api_error_postcodes'] = array( '660000' );
$rp_api_error_service = new RussianPostCourierCalcPostcodeFillStateService( $rp_api_error_repository, new RussianPostCourierTariffProbeService( new Logger() ), $rp_api_error_wpdb );
$rp_api_error_job = $rp_api_error_service->step( $rp_api_error_service->create_job() );
locations_smoke_assert( 1 === (int) ( $rp_api_error_job['failed'] ?? 0 ) && 1 === (int) ( $rp_api_error_job['errors'] ?? 0 ) && 1 === (int) ( $rp_api_error_job['consecutive_errors'] ?? 0 ), 'Russian Post courier postcode fill must increment failed/errors/consecutive_errors for API errors.' );

$rp_marker_wpdb = new wpdb();
$rp_marker_repository = new LocationRepository( $rp_marker_wpdb );
$rp_marker_id = $rp_marker_repository->save( locations_smoke_location( array( 'gar_object_id' => 886101, 'fias_id' => 'fias-rp-marker', 'region_name' => 'Marker', 'country_code' => 'RU', 'city_type' => 'Рі', 'place_type' => 'Рі', 'place_name' => 'Marker', 'display_name' => 'Marker', 'postal_code' => '999999999' ) ) );
$rp_normal_id = $rp_marker_repository->save( locations_smoke_location( array( 'gar_object_id' => 886102, 'fias_id' => 'fias-rp-normal', 'region_name' => 'Normal', 'country_code' => 'RU', 'city_type' => 'Рі', 'place_type' => 'Рі', 'place_name' => 'Normal', 'display_name' => 'Normal', 'postal_code' => '630100' ) ) );
$rp_marker_service = new RussianPostCourierCalcPostcodeFillStateService( $rp_marker_repository, new RussianPostCourierTariffProbeService( new Logger() ), $rp_marker_wpdb );
$marker_next = $rp_marker_repository->next_russianpost_courier_calc_postcode_location( 0, 'cities' );
locations_smoke_assert( is_array( $marker_next ) && $rp_normal_id === (int) ( $marker_next['id'] ?? 0 ), 'Russian Post courier postcode queue must skip locations with postal_code=999999999.' );
$GLOBALS['wdc_locations_probe_requests'] = array();
$forced_marker_job = $rp_marker_service->step(
	array(
		'phase' => 'running',
		'status' => 'running',
		'step_probes' => 0,
		'probes' => 0,
		'processed' => 0,
		'skipped' => 0,
		'failed' => 0,
		'updated' => 0,
		'bulk_updated' => 0,
		'last_id' => 0,
		'candidate_offset' => 0,
		'current_priority' => 'cities',
		'current_location' => $rp_marker_wpdb->rows[ $rp_marker_id ],
		'current_candidates' => array(),
	)
);
locations_smoke_assert( 0 === count( $GLOBALS['wdc_locations_probe_requests'] ) && 1 === (int) ( $forced_marker_job['skipped'] ?? 0 ), 'Russian Post courier postcode fill must not probe postal_code=999999999.' );
$marker_updated = $rp_marker_repository->update_russianpost_courier_calc_postal_code_for_postal_code( '999999999', '630100', true );
locations_smoke_assert( 0 === $marker_updated && '' === (string) ( $rp_marker_wpdb->rows[ $rp_marker_id ]['russianpost_courier_calc_postal_code'] ?? '' ), 'Russian Post courier postcode update helper must ignore postal_code=999999999.' );
$normal_updated = $rp_marker_repository->update_russianpost_courier_calc_postal_code_for_postal_code( '630100', '630101', true );
locations_smoke_assert( 1 === $normal_updated && '630101' === (string) $rp_marker_wpdb->rows[ $rp_normal_id ]['russianpost_courier_calc_postal_code'], 'Russian Post courier postcode update helper must still update normal postal_code values.' );

update_option( 'wdc_russianpost_courier_calc_postcode_fill_job', $rp_job );
$_POST = array( 'wdc_locations_nonce' => 'test-nonce' );
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.33.0' ),
	$rp_repository,
	new LocationSearchService( $rp_repository ),
	new LocationImportService( $rp_repository ),
	russianpost_courier_calc_postcode_fill: $rp_service
) )->ajax_russianpost_courier_calc_postcode_fill_reset();
$rp_reset_payload = json_decode( (string) ob_get_clean(), true );
locations_smoke_assert( is_array( $rp_reset_payload ) && ! empty( $rp_reset_payload['success'] ) && '630099' === (string) $rp_wpdb->rows[ $rp_city_id ]['russianpost_courier_calc_postal_code'], 'Russian Post courier postcode reset must not clear filled values.' );
$_POST = array( 'wdc_locations_nonce' => 'test-nonce' );
ob_start();
( new LocationsAdminPage(
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ) . '/', 'http://example.test/wp-content/plugins/walls-delivery-calc/', '0.33.0' ),
	$rp_repository,
	new LocationSearchService( $rp_repository ),
	new LocationImportService( $rp_repository ),
	russianpost_courier_calc_postcode_fill: $rp_service
) )->ajax_russianpost_courier_calc_postcode_fill_clear_all();
$rp_clear_payload = json_decode( (string) ob_get_clean(), true );
locations_smoke_assert( is_array( $rp_clear_payload ) && ! empty( $rp_clear_payload['success'] ) && '' === (string) $rp_wpdb->rows[ $rp_city_id ]['russianpost_courier_calc_postal_code'] && '' === (string) $rp_wpdb->rows[ $rp_settlement_id ]['russianpost_courier_calc_postal_code'], 'Russian Post courier postcode clear all must clear filled values and return success.' );

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
locations_smoke_assert( array() === $country_index->rebuild(), 'LocationCountryIndex rebuild returns empty list after clear_all.' );
unset( $GLOBALS['wdc_locations_smoke_options'][ LocationCountryIndexService::OPTION ] );
$empty_wpdb = new wpdb();
$empty_repository = new LocationRepository( $empty_wpdb );
$empty_index = new LocationCountryIndexService( $empty_repository );
locations_smoke_assert( array() === $empty_index->countries(), 'Empty locations table first countries() call rebuilds and returns empty list.' );
$empty_country_option = get_option( LocationCountryIndexService::OPTION, array() );
locations_smoke_assert( isset( $empty_country_option['counts'] ) && array() === $empty_country_option['counts'] && false === $empty_country_option['stale'], 'Empty locations table rebuild stores empty counts with stale=false.' );
locations_smoke_assert( array() === $empty_index->countries_with_counts(), 'Empty locations table countries_with_counts returns empty list.' );
locations_smoke_assert( 1 === $empty_wpdb->country_counts_calls, 'Empty locations table first countries() call runs one country counts lookup.' );
locations_smoke_assert( array() === $empty_index->countries(), 'Empty locations table second countries() call returns cached empty list.' );
locations_smoke_assert( 1 === $empty_wpdb->country_counts_calls, 'Empty cached countries() call must not rebuild again.' );
$empty_index->mark_stale();
locations_smoke_assert( array() === $empty_index->countries(), 'Marked stale empty country index rebuilds and still returns empty list.' );
locations_smoke_assert( 2 === $empty_wpdb->country_counts_calls, 'mark_stale allows the next countries() call to rebuild once.' );
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
$admin_importer->import_from_json_file( dirname( __DIR__ ) . '/fixtures/demo/locations-demo.json' );
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
locations_smoke_assert( str_contains( $locations_admin_source, 'CheckoutLocationSearch' ) && str_contains( $locations_admin_source, 'search_paginated( $query' ), 'Locations admin page must use hierarchy-aware search.' );
locations_smoke_assert( str_contains( $locations_admin_source, 'should_show_deep_counts' ) && str_contains( $locations_admin_source, 'count_with_postal_code()' ), 'Locations admin must keep expensive counters behind an explicit deep-counts request.' );
$repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Locations/Storage/LocationRepository.php' );
locations_smoke_assert( ! str_contains( $repository_source, 'pickup' ) && ! str_contains( $repository_source, 'rules' ) && ! str_contains( $repository_source, 'calendar' ) && ! str_contains( $repository_source, 'options' ), 'clear_all must not target pickup/rules/calendar/settings storage.' );
locations_smoke_assert( str_contains( $repository_source, 'find_exact_admin_identifier_matches' ) && str_contains( $repository_source, 'postal_code' ), 'LocationRepository must expose exact admin identifier lookup.' );
locations_smoke_assert( str_contains( $repository_source, 'find_first_by_postal_code' ), 'LocationRepository must expose postcode lookup for pickup address-search fallback.' );
locations_smoke_assert( method_exists( LocationRepository::class, 'find_active_by_place_and_region_matches' ) && str_contains( $repository_source, 'find_active_by_place_and_region_matches' ) && str_contains( $repository_source, "'place_name', 'settlement_name', 'city_name'" ) && str_contains( $repository_source, 'joined_region_name' ), 'LocationRepository must expose active place+region lookup across countries without relying on city-only matching.' );
$locations_schema_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0002_create_locations_table.php' );
locations_smoke_assert( str_contains( $locations_schema_source, 'KEY country_code (country_code)' ) && str_contains( $locations_schema_source, 'KEY active (active)' ) && str_contains( $locations_schema_source, 'KEY postal_code (postal_code)' ), 'Fresh locations schema must keep country_code, active, and postal_code indexes.' );
locations_smoke_assert( str_contains( $locations_schema_source, 'KEY idx_active_country_code (active, country_code)' ), 'Fresh locations schema must include the active/country_code compound index.' );
locations_smoke_assert( str_contains( $locations_schema_source, 'russianpost_courier_calc_postal_code varchar(32) NOT NULL DEFAULT' ) && str_contains( $locations_schema_source, 'KEY postal_code_rp_courier_calc' ), 'Fresh locations schema must include Russian Post courier calc postcode column and compound index.' );
$locations_index_migration_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0024_add_locations_active_country_index.php' );
locations_smoke_assert( str_contains( $locations_index_migration_source, 'SHOW TABLES LIKE' ) && str_contains( $locations_index_migration_source, 'SHOW COLUMNS' ) && str_contains( $locations_index_migration_source, 'SHOW INDEX' ) && str_contains( $locations_index_migration_source, 'ADD KEY idx_active_country_code' ), '0024 must add the active/country_code index idempotently.' );
$locations_rp_courier_migration_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0025_add_locations_russianpost_courier_calc_postal_code.php' );
locations_smoke_assert( str_contains( $locations_rp_courier_migration_source, 'SHOW TABLES LIKE' ) && str_contains( $locations_rp_courier_migration_source, 'SHOW COLUMNS' ) && str_contains( $locations_rp_courier_migration_source, 'SHOW INDEX' ) && str_contains( $locations_rp_courier_migration_source, 'ADD COLUMN russianpost_courier_calc_postal_code' ), '0025 must add Russian Post courier calc postcode column idempotently.' );
$locations_postcode_migration_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0023_drop_unused_locations_postcode.php' );
locations_smoke_assert( str_contains( $locations_postcode_migration_source, 'DROP COLUMN postcode' ) && str_contains( $locations_postcode_migration_source, 'postal_code' ), '0023 must remove only legacy postcode after preserving postal_code.' );
$dadata_client_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/AddressSuggestions/DaDataSuggestionClient.php' );
locations_smoke_assert( str_contains( $dadata_client_source, 'location_fias_id' ) && str_contains( $dadata_client_source, 'restrict_value' ), 'DaData address search must support current-location filters.' );

echo "Locations smoke test passed.\n";
