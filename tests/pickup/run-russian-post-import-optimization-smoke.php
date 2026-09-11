<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function rp_optimization_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-09-12 12:00:00'; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public int $num_queries = 0;
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<string,array<int,array<string,mixed>>> */
		public array $tables = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $value, $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function query( string $query ): int|false {
			++$this->num_queries;
			if ( ! preg_match( '/^INSERT INTO ([A-Za-z0-9_]+) \(([^)]+)\) VALUES (.+) ON DUPLICATE KEY UPDATE/s', trim( $query ), $match ) ) {
				return 0;
			}
			$table = $match[1];
			$columns = array_map( 'trim', explode( ',', $match[2] ) );
			preg_match_all( "/'(?:''|[^'])*'|-?[0-9]+(?:\\.[0-9]+)?|NULL/", $match[3], $value_matches );
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
				$row = array_combine( $columns, $cells );
				if ( false === $row || count( $cells ) !== count( $columns ) ) {
					continue;
				}
				$duplicate = array_filter( $this->tables[ $table ] ?? array(), static fn( array $existing ): bool => (string) ( $existing['point_code'] ?? '' ) === (string) ( $row['point_code'] ?? '' ) );
				if ( array() !== $duplicate ) {
					continue;
				}
				$this->tables[ $table ][] = $row;
				++$inserted;
			}

			return $inserted;
		}
	}
}

use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupLocationResolver;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;

/** @return array<string,mixed> */
function rp_optimization_location( int $id, string $fias, string $postcode, string $region, string $city, int $active = 1 ): array {
	return array(
		'id' => $id,
		'fias_id' => $fias,
		'postal_code' => $postcode,
		'region_name' => $region,
		'city_name' => $city,
		'settlement_name' => $city,
		'place_name' => $city,
		'display_name' => $city,
		'searchable_text' => $region . ' ' . $city,
		'active' => $active,
		'country_code' => 'RU',
	);
}

$db = new wpdb();
$db->locations = array(
	rp_optimization_location( 1, 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', '100001', 'Регион A', 'Город A' ),
	rp_optimization_location( 2, 'bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb', '200001', 'Регион B', 'Общий город' ),
	rp_optimization_location( 3, 'cccccccc-cccc-cccc-cccc-cccccccccccc', '200001', 'Регион B', 'Общий город' ),
	rp_optimization_location( 4, 'dddddddd-dddd-dddd-dddd-dddddddddddd', '300001', 'Регион C', 'Город C', 0 ),
	rp_optimization_location( 5, 'eeeeeeee-eeee-eeee-eeee-eeeeeeeeeeee', '400001', 'Регион D', 'Город D' ),
);
$location_repository = new LocationRepository( $db );
$reference = new RussianPostPickupLocationResolver( $location_repository, $db );
$optimized = new RussianPostPickupLocationResolver( $location_repository, $db );
$parity_rows = array(
	array( 'fias_location_guid' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'postcode' => '999999', 'region_name' => 'Нет', 'city_name' => 'Нет' ),
	array( 'fias_location_guid' => 'ffffffff-ffff-ffff-ffff-ffffffffffff', 'postcode' => '200001', 'region_name' => 'Регион B', 'city_name' => 'Общий город' ),
	array( 'fias_location_guid' => '', 'postcode' => '400001', 'region_name' => 'Регион D', 'city_name' => 'Город D' ),
	array( 'fias_location_guid' => 'missing', 'postcode' => '', 'region_name' => 'Регион B', 'city_name' => 'Общий город' ),
	array( 'fias_location_guid' => 'dddddddd-dddd-dddd-dddd-dddddddddddd', 'postcode' => '', 'region_name' => 'Нет', 'city_name' => 'Нет' ),
);
$reference_results = array_map( static fn( array $row ): array => $reference->resolve( $row ), $parity_rows );
$optimized->prefetch_fias_for_rows( $parity_rows );
$optimized_results = array_map( static fn( array $row ): array => $optimized->resolve( $row ), $parity_rows );
foreach ( $reference_results as $index => $expected ) {
	$actual = $optimized_results[ $index ];
	rp_optimization_assert(
		array_intersect_key( $actual, array_flip( array( 'status', 'strategy', 'location_id' ) ) ) === array_intersect_key( $expected, array_flip( array( 'status', 'strategy', 'location_id' ) ) ),
		'Prefetch must preserve FIAS, postcode, region/city, ambiguity, fallback, and inactive-row matching decisions.'
	);
}

$db->locations = array();
$fias_rows = array();
for ( $index = 1; $index <= 500; ++$index ) {
	$fias = sprintf( '00000000-0000-0000-0000-%012x', $index );
	$db->locations[] = rp_optimization_location( $index, $fias, (string) ( 500000 + $index ), 'Регион', 'Город ' . $index );
	$fias_rows[] = array( 'fias_location_guid' => $fias, 'postcode' => '', 'region_name' => '', 'city_name' => '' );
}
$fias_resolver = new RussianPostPickupLocationResolver( new LocationRepository( $db ), $db );
$fias_resolver->prefetch_fias_for_rows( $fias_rows );
$fias_matches = 0;
foreach ( $fias_rows as $row ) {
	$fias_matches += 'fias' === $fias_resolver->resolve( $row )['strategy'] ? 1 : 0;
}
$fias_stats = $fias_resolver->cache_stats();
rp_optimization_assert( 500 === $fias_matches, 'All 500 exact FIAS fixtures must retain their location mapping.' );
rp_optimization_assert( (int) $fias_stats['fias_queries'] <= 3, '500 exact FIAS values must use at most three bounded prefetch queries.' );

$repeated_rows = array_merge( ...array_map( static fn( int $offset ): array => array_slice( $fias_rows, $offset, 10 ), array_fill( 0, 50, 0 ) ) );
$repeated_resolver = new RussianPostPickupLocationResolver( new LocationRepository( $db ), $db );
$repeated_resolver->prefetch_fias_for_rows( $repeated_rows );
rp_optimization_assert( 1 === (int) $repeated_resolver->cache_stats()['fias_queries'], '500 rows containing ten repeated FIAS keys must use one prefetch query.' );

$point_repository = new RussianPostPickupPointRepository( $db );
$staging_rows = array();
for ( $index = 1; $index <= 500; ++$index ) {
	$staging_rows[] = array(
		'point_code' => 'POINT-' . $index,
		'point_type' => 0 === $index % 3 ? 'APS' : ( 0 === $index % 2 ? 'PVZ' : 'OPS' ),
		'postcode' => (string) ( 600000 + $index ),
		'country_code' => 'RU',
		'region_name' => 'Регион ' . $index,
		'city_name' => 'Город ' . $index,
		'address' => "Адрес '" . $index,
		'fias_location_guid' => sprintf( '00000000-0000-0000-0000-%012x', $index ),
		'location_id' => $index,
		'latitude' => 50.0 + $index / 10000,
		'longitude' => 80.0 + $index / 10000,
		'active' => 1,
		'source_hash' => sha1( 'POINT-' . $index ),
	);
}
$queries_before_write = $db->num_queries;
$write_stats = $point_repository->insert_batch( $staging_rows, 'wp_wdc_pickup_points_russian_post_stage_test' );
$write_queries = $db->num_queries - $queries_before_write;
rp_optimization_assert( 500 === $write_stats['inserted'] && 0 === $write_stats['updated'] && 0 === $write_stats['skipped'], 'All valid staging fixtures must be inserted without changing update/skip counters.' );
rp_optimization_assert( 5 === $write_queries, '500 staging rows must use five bounded multi-row INSERT statements.' );
rp_optimization_assert( 500 === count( $db->tables['wp_wdc_pickup_points_russian_post_stage_test'] ?? array() ), 'Bulk SQL fixture must persist all 500 rows.' );
foreach ( $staging_rows as $index => $source ) {
	$stored = $db->tables['wp_wdc_pickup_points_russian_post_stage_test'][ $index ];
	$expected_columns = array( 'point_code', 'point_type', 'postcode', 'country_code', 'region_name', 'city_name', 'street', 'house', 'address', 'fias_location_guid', 'fias_address_guid', 'gar_region_id', 'location_id', 'latitude', 'longitude', 'geohash', 'description', 'work_time', 'active', 'source_hash', 'last_seen_at', 'created_at', 'updated_at' );
	$stored_columns = array_keys( $stored );
	sort( $expected_columns );
	sort( $stored_columns );
	rp_optimization_assert( $expected_columns === $stored_columns, 'Bulk staging row must preserve the complete persisted column contract.' );
	foreach ( $source as $column => $value ) {
		rp_optimization_assert( (string) $value === (string) ( $stored[ $column ] ?? '' ), 'Bulk staging parity failed for column ' . $column . '.' );
	}
}

$duplicate = $staging_rows[0];
$duplicate['city_name'] = 'Другая строка не должна заменить первую';
$duplicate_stats = $point_repository->insert_batch( array( $duplicate ), 'wp_wdc_pickup_points_russian_post_stage_test' );
rp_optimization_assert( 0 === $duplicate_stats['inserted'] && 1 === $duplicate_stats['skipped'] && 'Город 1' === $db->tables['wp_wdc_pickup_points_russian_post_stage_test'][0]['city_name'], 'Duplicate point_code must retain the first staged row and count the duplicate as skipped.' );

echo "Russian Post import optimization smoke test passed: FIAS 500=>3 queries max (10 repeated=>1), staging 500=>5 writes, parity preserved.\n";
