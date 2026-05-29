<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\Locations\LocationCoordinateEnricher;
use WallsShop\WDC\Locations\Admin\LocationsAdminPage;
use WallsShop\WDC\Locations\Coordinates\LocationCoordinatesDadataBatchUpdater;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Normalization\FallbackAddressNormalizer;
use WallsShop\WDC\Locations\Services\LocationCountryIndexService;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

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

		/** @var array<int,string> */
		public array $queries = array();

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

$batch_wpdb = new wpdb();
$batch_repository = new LocationRepository( $batch_wpdb );
$batch_success_id = $batch_repository->save( locations_smoke_location( array( 'gar_object_id' => 883001, 'fias_id' => 'fias-batch-success', 'region_name' => 'Иркутская', 'place_type' => 'г', 'place_name' => 'Не должен попасть в query', 'display_name' => 'г Иркутск', 'postal_code' => '664000', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$batch_empty_query_id = $batch_repository->save( locations_smoke_location( array( 'gar_object_id' => 883002, 'fias_id' => 'fias-batch-empty-query', 'region_name' => 'Томская', 'place_type' => 'г', 'place_name' => 'Пустое имя', 'display_name' => '', 'postal_code' => '634000', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$batch_wpdb->rows[ $batch_empty_query_id ]['display_name'] = '';
$batch_no_success_id = $batch_repository->save( locations_smoke_location( array( 'gar_object_id' => 883003, 'fias_id' => 'fias-batch-no-success', 'region_name' => 'Карелия', 'place_type' => 'г', 'place_name' => 'Сортавала', 'display_name' => 'респ Карелия, г Сортавала, поселок Уусикюля', 'postal_code' => '186752', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
$batch_no_coordinates_id = $batch_repository->save( locations_smoke_location( array( 'gar_object_id' => 883004, 'fias_id' => 'fias-batch-empty', 'region_name' => 'Томская', 'place_type' => 'г', 'place_name' => 'Пусто', 'display_name' => 'Пусто', 'latitude' => 0.0, 'longitude' => 0.0 ) ) );
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
locations_smoke_assert( array( '664000, г Иркутск', '186752, респ Карелия, г Сортавала, поселок Уусикюля', 'Пусто', '678960, респ Саха (Якутия), Нерюнгринский р-н, г Нерюнгри', 'Ошибка' ) === $batch_client->queries, 'Coordinate DaData query must use only postal_code plus display_name.' );
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
locations_smoke_assert( 54.983333 === (float) $limit_wpdb->rows[ $limit_updated_id ]['latitude'], 'Coordinate exhausted limit must preserve progress saved before the stop.' );
locations_smoke_assert( 0.0 === (float) $limit_wpdb->rows[ $limit_stopped_id ]['latitude'] && 0.0 === (float) $limit_wpdb->rows[ $limit_untouched_id ]['latitude'], 'Coordinate exhausted limit must not update or skip the stopped and remaining rows.' );
$limit_job_after_repeat = $limit_updater->step( $limit_job, 10 );
locations_smoke_assert( 2 === $limit_client->calls && $limit_job_after_repeat === $limit_job, 'Coordinate exhausted state must not continue automatically on the next step.' );

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
$repository_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Locations/Storage/LocationRepository.php' );
locations_smoke_assert( ! str_contains( $repository_source, 'pickup' ) && ! str_contains( $repository_source, 'rules' ) && ! str_contains( $repository_source, 'calendar' ) && ! str_contains( $repository_source, 'options' ), 'clear_all must not target pickup/rules/calendar/settings storage.' );
locations_smoke_assert( str_contains( $repository_source, 'find_exact_admin_identifier_matches' ) && str_contains( $repository_source, 'postal_code' ), 'LocationRepository must expose exact admin identifier lookup.' );
locations_smoke_assert( str_contains( $repository_source, 'find_first_by_postal_code' ), 'LocationRepository must expose postcode lookup for pickup address-search fallback.' );
$dadata_client_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/AddressSuggestions/DaDataSuggestionClient.php' );
locations_smoke_assert( str_contains( $dadata_client_source, 'location_fias_id' ) && str_contains( $dadata_client_source, 'restrict_value' ), 'DaData address search must support current-location filters.' );

echo "Locations smoke test passed.\n";
