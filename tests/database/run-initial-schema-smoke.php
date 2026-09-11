<?php
declare(strict_types=1);

$wdc_schema_wp_root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-schema-smoke-' . bin2hex( random_bytes( 6 ) ) . DIRECTORY_SEPARATOR;
mkdir( $wdc_schema_wp_root . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes', 0777, true );
file_put_contents( $wdc_schema_wp_root . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'upgrade.php', "<?php\n" );
defined( 'ABSPATH' ) || define( 'ABSPATH', $wdc_schema_wp_root );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

if ( ! class_exists( 'wpdb' ) ) {
	final class wpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		public int $insert_id = 0;
		/** @var array<int,array{table:string,data:array<string,mixed>}> */
		public array $inserts = array();

		public function get_charset_collate(): string { return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'; }
		public function prepare( string $query, mixed ...$args ): string { return vsprintf( str_replace( array( '%d', '%s', '%f' ), array( '%d', "'%s'", '%f' ), $query ), $args ); }
		public function get_row( string $query, string $output = '' ): ?array { return null; }
		public function get_results( string $query, string $output = '' ): array { return array(); }
		public function get_var( string $query ): mixed { return null; }
		public function query( string $query ): int|false {
			if ( 1 === preg_match( '/^\s*CREATE\s+TABLE/i', $query ) ) {
				$GLOBALS['wdc_schema_sql'][] = $query;
			}
			return 0;
		}
		public function insert( string $table, array $data, array $format = array() ): int|false {
			$this->inserts[] = array( 'table' => $table, 'data' => $data );
			$this->insert_id = count( $this->inserts );
			return 1;
		}
	}
}

/** @var array<int,string> $wdc_schema_sql */
$GLOBALS['wdc_schema_sql'] = array();
$GLOBALS['wdc_schema_options'] = array();
$GLOBALS['wpdb'] = new wpdb();

function dbDelta( string|array $queries ): array {
	foreach ( (array) $queries as $query ) {
		$GLOBALS['wdc_schema_sql'][] = (string) $query;
	}
	return array();
}

function get_option( string $name, mixed $default = false ): mixed {
	return $GLOBALS['wdc_schema_options'][ $name ] ?? $default;
}

function update_option( string $name, mixed $value, bool|string $autoload = false ): bool {
	$GLOBALS['wdc_schema_options'][ $name ] = $value;
	return true;
}

function current_time( string $type ): string { return '2026-09-11 12:00:00'; }

function wdc_schema_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$migration_files = glob( dirname( __DIR__, 2 ) . '/database/migrations/*.php' );
wdc_schema_assert( is_array( $migration_files ) && 1 === count( $migration_files ), 'Exactly one production migration must remain.' );
wdc_schema_assert( '0001_initial_schema.php' === basename( $migration_files[0] ), 'The production migration must be the 1.0 initial schema.' );

$migration = require $migration_files[0];
wdc_schema_assert( is_callable( $migration ), 'The initial schema migration must return a callable.' );
$migration();

$schemas = array();
foreach ( $GLOBALS['wdc_schema_sql'] as $sql ) {
	if ( 1 === preg_match( '/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?([^\s`(]+)`?/i', $sql, $matches ) ) {
		$schemas[ $matches[1] ] = $sql;
	}
}

$expected = array(
	'wp_wdc_calendar_days', 'wp_wdc_cdek_tariffs', 'wp_wdc_delivery_service_countries',
	'wp_wdc_delivery_service_settings', 'wp_wdc_delivery_services', 'wp_wdc_dpd_pickup_points',
	'wp_wdc_gar_places_stage', 'wp_wdc_jet_logistic_cities', 'wp_wdc_jet_logistic_location_overrides',
	'wp_wdc_jet_logistic_status_mappings', 'wp_wdc_location_delivery_codes', 'wp_wdc_locations',
	'wp_wdc_manual_delivery_locations', 'wp_wdc_manual_delivery_pickup_points', 'wp_wdc_manual_delivery_regions',
	'wp_wdc_manual_delivery_weight_ranges', 'wp_wdc_ozon_delivery_pickup_generations',
	'wp_wdc_ozon_delivery_pickup_ids', 'wp_wdc_ozon_delivery_pickup_points', 'wp_wdc_pek_location_mappings',
	'wp_wdc_pek_terminals', 'wp_wdc_pickup_points', 'wp_wdc_regions', 'wp_wdc_rule_conditions',
	'wp_wdc_rules', 'wp_wdc_russian_post_country_mappings', 'wp_wdc_pickup_points_russian_post',
	'wp_wdc_shipment_cost_analytics', 'wp_wdc_yandex_delivery_geo_v2', 'wp_wdc_yandex_location_manual_overrides_v2',
	'wp_wdc_yandex_location_mapping_v2', 'wp_wdc_yandex_delivery_pickup_points_v2',
	'wp_wdc_yandex_region_mapping_v2',
);
sort( $expected );
$actual = array_keys( $schemas );
sort( $actual );
wdc_schema_assert( $expected === $actual, 'Fresh schema table set differs from the 1.0 runtime contract: ' . json_encode( array( 'missing' => array_values( array_diff( $expected, $actual ) ), 'extra' => array_values( array_diff( $actual, $expected ) ) ) ) );

foreach ( array( 'wp_wdc_location_aliases', 'wp_wdc_gar_changes' ) as $retired ) {
	wdc_schema_assert( ! isset( $schemas[ $retired ] ), "Retired table {$retired} must not be created." );
}

$requirements = array(
	'wp_wdc_locations' => array( 'gar_object_id bigint(20) unsigned NULL', 'fias_id char(36) NULL', 'russianpost_courier_calc_postal_code', 'KEY idx_active_country_code' ),
	'wp_wdc_rules' => array( 'operation_text longtext NULL', 'condition_group_logic longtext NULL', 'condition_group_expression' ),
	'wp_wdc_ozon_delivery_pickup_generations' => array( 'lock_owner varchar(64) NULL', 'progress_updated_at datetime NULL', 'enrichment_processed_count' ),
	'wp_wdc_ozon_delivery_pickup_points' => array( 'schedule text NOT NULL', 'KEY active_geo_lookup' ),
	'wp_wdc_ozon_delivery_pickup_ids' => array( 'UNIQUE KEY generation_point', 'KEY generation_status_id' ),
	'wp_wdc_manual_delivery_regions' => array( 'country_code varchar(2)', 'UNIQUE KEY ux_manual_region_country' ),
	'wp_wdc_manual_delivery_locations' => array( 'country_code varchar(2)', 'UNIQUE KEY ux_manual_location_country' ),
	'wp_wdc_manual_delivery_weight_ranges' => array( 'from_weight_g', 'to_weight_g', 'price_kopecks' ),
	'wp_wdc_manual_delivery_pickup_points' => array( 'UNIQUE KEY ux_manual_pickup_service_code', 'KEY locality_lookup' ),
	'wp_wdc_pek_location_mappings' => array( 'mapping_precision', 'UNIQUE KEY location_id' ),
);
foreach ( $requirements as $table => $needles ) {
	foreach ( $needles as $needle ) {
		wdc_schema_assert( str_contains( $schemas[ $table ], $needle ), "{$table} is missing schema contract: {$needle}." );
	}
}

$combined_sql = implode( "\n", $GLOBALS['wdc_schema_sql'] );
wdc_schema_assert( 0 === preg_match( '/\b(DROP|TRUNCATE|DELETE)\b/i', $combined_sql ), 'Initial schema must not destructively modify an existing development database.' );
wdc_schema_assert( count( $GLOBALS['wpdb']->inserts ) > 0, 'Fresh schema must seed current Jet Logistic default status mappings.' );

echo "Initial schema smoke passed (33 tables; retired tables absent).\n";

unlink( $wdc_schema_wp_root . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'upgrade.php' );
rmdir( $wdc_schema_wp_root . 'wp-admin' . DIRECTORY_SEPARATOR . 'includes' );
rmdir( $wdc_schema_wp_root . 'wp-admin' );
rmdir( $wdc_schema_wp_root );
