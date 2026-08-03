<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'OBJECT' ) || define( 'OBJECT', 'OBJECT' );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\Installation\PekSchemaIntegrityService;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationMappingRepository;
use WallsShop\WDC\Infrastructure\Database\MigrationManager;

function pek_schema_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$GLOBALS['pek_schema_options'] = array();
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_schema_options'][ $option ] ?? $default; }
	function update_option( string $option, mixed $value, bool $autoload = true ): bool { unset( $autoload ); $GLOBALS['pek_schema_options'][ $option ] = $value; return true; }
}

if ( ! function_exists( 'dbDelta' ) ) {
	function dbDelta( string|array $queries = '', bool $execute = true ): array {
		unset( $execute );
		$sql = is_array( $queries ) ? implode( "\n", $queries ) : $queries;
		$GLOBALS['pek_schema_db_delta'][] = $sql;
		if ( preg_match( '/CREATE TABLE\s+([^\s(]+)/i', $sql, $m ) ) {
			$table = trim( $m[1], '`' );
			if ( ! empty( $GLOBALS['pek_schema_fail_reserved_precision'] ) && preg_match( '/^\s*precision\s+/mi', $sql ) ) {
				$GLOBALS['wpdb']->last_error = 'You have an error in your SQL syntax near precision';
				return array();
			}
			if ( ! in_array( $table, $GLOBALS['pek_schema_db_delta_fail_tables'] ?? array(), true ) ) {
				$GLOBALS['wpdb']->existing_tables[ $table ] = true;
				$GLOBALS['wpdb']->columns[ $table ] = pek_schema_columns_from_create( $sql );
			}
		}

		return array();
	}
}

function pek_schema_columns_from_create( string $sql ): array {
	$columns = array();
	foreach ( preg_split( '/\R/', $sql ) ?: array() as $line ) {
		$line = trim( rtrim( $line, ',' ) );
		if ( preg_match( '/^`?([A-Za-z_][A-Za-z0-9_]*)`?\s+/', $line, $m ) && ! in_array( strtoupper( $m[1] ), array( 'CREATE', 'PRIMARY', 'UNIQUE', 'KEY' ), true ) ) {
			$columns[ $m[1] ] = true;
		}
	}

	return $columns;
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		/** @var array<string,bool> */
		public array $existing_tables = array();
		/** @var array<string,array<string,bool>> */
		public array $columns = array();
		/** @var array<int,string> */
		public array $queries = array();
		/** @var array<int,array{table:string,data:array<string,mixed>}> */
		public array $inserts = array();
		/** @var array<int,array{table:string,data:array<string,mixed>,where:array<string,mixed>}> */
		public array $updates = array();
		/** @var array<int,array<string,mixed>> */
		public array $selected_rows = array();
		public bool $table_check_fails = false;
		public bool $column_check_fails = false;
		public bool $alter_fails = false;
		public bool $update_query_fails = false;

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 ) ?? $query;
				$query = preg_replace( '/%d/', (string) (int) $arg, $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function get_var( string $query ): mixed {
			$this->queries[] = $query;
			if ( $this->table_check_fails ) {
				$this->last_error = 'simulated table check failure';
				return null;
			}
			if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $m ) ) {
				$table = stripcslashes( $m[1] );
				return ! empty( $this->existing_tables[ $table ] ) ? $table : null;
			}
			if ( $this->column_check_fails && str_contains( $query, 'SHOW COLUMNS' ) ) {
				$this->last_error = 'simulated column check failure';
				return null;
			}
			if ( preg_match( "/SHOW COLUMNS FROM `?([^`' ]+)`? LIKE '([^']+)'/", $query, $m ) ) {
				$table = stripcslashes( $m[1] );
				$column = stripcslashes( $m[2] );
				return ! empty( $this->columns[ $table ][ $column ] ) ? $column : null;
			}

			return null;
		}

		public function get_row( string $query, string $output = OBJECT ): mixed {
			unset( $output );
			$this->queries[] = $query;
			if ( preg_match( '/WHERE location_id = ([0-9]+)/', $query, $m ) ) {
				foreach ( $this->selected_rows as $row ) {
					if ( (int) ( $row['location_id'] ?? 0 ) === (int) $m[1] ) {
						return $row;
					}
				}
			}

			return null;
		}

		public function insert( string $table, array $data ): int|false {
			$this->inserts[] = array( 'table' => $table, 'data' => $data );
			$this->selected_rows[] = $data;

			return 1;
		}

		public function update( string $table, array $data, array $where ): int|false {
			$this->updates[] = array( 'table' => $table, 'data' => $data, 'where' => $where );

			return 1;
		}

		public function query( string $query ): int|false {
			$this->queries[] = $query;
			if ( str_starts_with( $query, 'ALTER TABLE' ) ) {
				if ( $this->alter_fails ) {
					$this->last_error = 'simulated alter failure';
					return false;
				}
				if ( preg_match( '/ALTER TABLE `([^`]+)` ADD COLUMN ([A-Za-z_][A-Za-z0-9_]*)\s+/i', $query, $m ) ) {
					$this->columns[ $m[1] ][ $m[2] ] = true;
				}
				return 1;
			}
			if ( str_starts_with( $query, 'UPDATE' ) ) {
				if ( $this->update_query_fails ) {
					$this->last_error = 'simulated update failure';
					return false;
				}
				foreach ( $this->selected_rows as &$row ) {
					if ( array_key_exists( 'precision', $row ) && ( ! array_key_exists( 'mapping_precision', $row ) || null === $row['mapping_precision'] ) ) {
						$row['mapping_precision'] = $row['precision'];
					}
				}
				unset( $row );
				return 1;
			}

			return 0;
		}
	}
}

function pek_schema_reset( array $tables = array() ): wpdb {
	$GLOBALS['wpdb'] = new wpdb();
	foreach ( $tables as $table ) {
		$GLOBALS['wpdb']->existing_tables[ 'wp_' . $table ] = true;
		$GLOBALS['wpdb']->columns[ 'wp_' . $table ] = array();
	}
	$GLOBALS['pek_schema_db_delta'] = array();
	$GLOBALS['pek_schema_db_delta_fail_tables'] = array();
	$GLOBALS['pek_schema_fail_reserved_precision'] = false;
	$GLOBALS['pek_schema_options'] = array();

	return $GLOBALS['wpdb'];
}

function pek_schema_repair(): array {
	return ( new PekSchemaIntegrityService() )->repair();
}

function pek_schema_run_0051(): void {
	$migration = require dirname( __DIR__, 2 ) . '/database/migrations/0051_migrate_pek_mapping_precision_column.php';
	$migration();
}

$wpdb = pek_schema_reset();
$mapping_repository = new PekLocationMappingRepository( $wpdb );
$mapping_schema = $mapping_repository->schema();
pek_schema_assert( ! preg_match( '/^\s*precision\s+/mi', $mapping_schema ), 'PEK mapping schema must not use the MySQL reserved physical column precision.' );
pek_schema_assert( str_contains( $mapping_schema, 'mapping_precision varchar(16) NULL' ), 'PEK mapping schema must use physical mapping_precision column.' );
$wpdb->existing_tables['wp_wdc_pek_location_mappings'] = true;
$wpdb->columns['wp_wdc_pek_location_mappings'] = array( 'mapping_precision' => true );
$mapping_repository->upsert(
	array(
		'location_id' => 10,
		'country_code' => 'RU',
		'address_fingerprint' => str_repeat( 'a', 64 ),
		'resolution_method' => 'address',
		'precision' => 'exact',
		'mapping_state' => 'resolved',
		'checked_at' => '2026-08-04 10:00:00',
		'created_at' => '2026-08-04 10:00:00',
		'updated_at' => '2026-08-04 10:00:00',
	)
);
pek_schema_assert( isset( $wpdb->inserts[0]['data']['mapping_precision'] ) && 'exact' === $wpdb->inserts[0]['data']['mapping_precision'] && ! array_key_exists( 'precision', $wpdb->inserts[0]['data'] ), 'Production insert payload must write mapping_precision and must not write precision.' );
$wpdb->selected_rows = array(
	array(
		'location_id' => 11,
		'country_code' => 'RU',
		'address_fingerprint' => str_repeat( 'b', 64 ),
		'resolution_method' => 'address',
		'mapping_precision' => 'near',
		'mapping_state' => 'near',
	),
);
$selected = $mapping_repository->find_by_location_id( 11 );
pek_schema_assert( 'near' === (string) ( $selected['precision'] ?? '' ) && ! array_key_exists( 'mapping_precision', $selected ), 'Production read must expose domain precision and hide physical mapping_precision.' );

$wpdb = pek_schema_reset( array( 'wdc_pek_terminals' ) );
$report = pek_schema_repair();
pek_schema_assert( true === $report['success'] && true === $report['repaired']['location_mappings'] && false === $report['repaired']['terminals'], 'Mappings-missing partial schema must repair only location mappings.' );
pek_schema_assert( ! empty( $wpdb->existing_tables['wp_wdc_pek_location_mappings'] ) && ! empty( $wpdb->existing_tables['wp_wdc_pek_terminals'] ) && 1 === count( $GLOBALS['pek_schema_db_delta'] ), 'Mappings repair must create exactly one missing table and preserve terminals.' );
$again = pek_schema_repair();
pek_schema_assert( false === $again['repaired']['location_mappings'] && false === $again['repaired']['terminals'] && 1 === count( $GLOBALS['pek_schema_db_delta'] ), 'Repeated schema integrity run must be idempotent and avoid dbDelta.' );

$wpdb = pek_schema_reset( array( 'wdc_pek_location_mappings' ) );
$report = pek_schema_repair();
pek_schema_assert( false === $report['repaired']['location_mappings'] && true === $report['repaired']['terminals'] && 1 === count( $GLOBALS['pek_schema_db_delta'] ), 'Reverse partial schema must repair only terminals.' );

$wpdb = pek_schema_reset( array( 'wdc_pek_location_mappings', 'wdc_pek_terminals' ) );
$report = pek_schema_repair();
pek_schema_assert( false === $report['repaired']['location_mappings'] && false === $report['repaired']['terminals'] && array() === $GLOBALS['pek_schema_db_delta'], 'Fully installed PEK schema must not call installers.' );

$wpdb = pek_schema_reset();
$report = pek_schema_repair();
pek_schema_assert( true === $report['repaired']['location_mappings'] && true === $report['repaired']['terminals'] && 2 === count( $GLOBALS['pek_schema_db_delta'] ), 'Fully missing PEK schema must install both tables.' );

$wpdb = pek_schema_reset( array( 'wdc_pek_terminals' ) );
$GLOBALS['pek_schema_db_delta_fail_tables'] = array( 'wp_wdc_pek_location_mappings' );
try {
	pek_schema_repair();
	pek_schema_assert( false, 'Mapping install failure must fail closed.' );
} catch ( RuntimeException ) {
	pek_schema_assert( empty( $wpdb->existing_tables['wp_wdc_pek_location_mappings'] ) && ! empty( $wpdb->existing_tables['wp_wdc_pek_terminals'] ), 'Mapping install failure must not damage existing terminals.' );
}
$GLOBALS['pek_schema_db_delta_fail_tables'] = array();
$retry = pek_schema_repair();
pek_schema_assert( true === $retry['repaired']['location_mappings'] && ! empty( $wpdb->existing_tables['wp_wdc_pek_location_mappings'] ), 'Failed repair must be retryable on the next controlled run.' );

$wpdb = pek_schema_reset( array( 'wdc_pek_terminals' ) );
$GLOBALS['pek_schema_fail_reserved_precision'] = true;
dbDelta( 'CREATE TABLE wp_wdc_pek_location_mappings (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	precision varchar(16) NULL,
	PRIMARY KEY  (id)
);' );
pek_schema_assert( empty( $wpdb->existing_tables['wp_wdc_pek_location_mappings'] ) && '' !== $wpdb->last_error, 'Fake dbDelta must reproduce MySQL reserved precision syntax failure.' );

$wpdb = pek_schema_reset( array( 'wdc_pek_location_mappings' ) );
$GLOBALS['pek_schema_db_delta_fail_tables'] = array( 'wp_wdc_pek_terminals' );
try {
	pek_schema_repair();
	pek_schema_assert( false, 'Terminal install failure must fail closed.' );
} catch ( RuntimeException ) {
	pek_schema_assert( ! empty( $wpdb->existing_tables['wp_wdc_pek_location_mappings'] ) && empty( $wpdb->existing_tables['wp_wdc_pek_terminals'] ), 'Partial repair failure must preserve the table already present.' );
}

$wpdb = pek_schema_reset();
$wpdb->table_check_fails = true;
try {
	pek_schema_repair();
	pek_schema_assert( false, 'Table existence SQL failure must fail closed.' );
} catch ( RuntimeException ) {
	pek_schema_assert( array() === $GLOBALS['pek_schema_db_delta'], 'Table existence SQL failure must not trigger blind dbDelta.' );
}

$migration_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-pek-schema-integrity-' . uniqid();
mkdir( $migration_dir );
copy( dirname( __DIR__, 2 ) . '/database/migrations/0050_repair_pek_foundation_schema.php', $migration_dir . DIRECTORY_SEPARATOR . '0050_repair_pek_foundation_schema.php' );
$wpdb = pek_schema_reset( array( 'wdc_pek_terminals' ) );
$GLOBALS['pek_schema_options']['wdc_applied_migrations'] = array( '0048_create_pek_location_mappings.php', '0049_create_pek_terminals.php' );
$GLOBALS['pek_schema_options']['wdc_db_version'] = '0.131.5';
( new MigrationManager( '0.131.6-test', $migration_dir ) )->run();
pek_schema_assert( ! empty( $wpdb->existing_tables['wp_wdc_pek_location_mappings'] ) && ! empty( $wpdb->existing_tables['wp_wdc_pek_terminals'] ), 'Migration 0050 must repair schema drift even when 0048/0049 are already marked applied.' );
pek_schema_assert( in_array( '0050_repair_pek_foundation_schema.php', (array) get_option( 'wdc_applied_migrations', array() ), true ) && '0.131.6-test' === get_option( 'wdc_db_version', '' ), 'Migration 0050 must be marked applied only after postconditions succeed.' );

$wpdb = pek_schema_reset( array( 'wdc_pek_terminals' ) );
$GLOBALS['pek_schema_options']['wdc_applied_migrations'] = array( '0048_create_pek_location_mappings.php', '0049_create_pek_terminals.php' );
$GLOBALS['pek_schema_options']['wdc_db_version'] = '0.131.5';
$GLOBALS['pek_schema_db_delta_fail_tables'] = array( 'wp_wdc_pek_location_mappings' );
try {
	( new MigrationManager( '0.131.6-test', $migration_dir ) )->run();
	pek_schema_assert( false, 'Migration 0050 must not be marked applied after failed repair.' );
} catch ( RuntimeException ) {
	pek_schema_assert( ! in_array( '0050_repair_pek_foundation_schema.php', (array) get_option( 'wdc_applied_migrations', array() ), true ) && '0.131.5' === get_option( 'wdc_db_version', '' ), 'Failed migration repair must leave migration marker/version unchanged.' );
}

$wpdb = pek_schema_reset( array( 'wdc_pek_terminals' ) );
$GLOBALS['pek_schema_options']['wdc_applied_migrations'] = array( '0048_create_pek_location_mappings.php', '0049_create_pek_terminals.php' );
$GLOBALS['pek_schema_options']['wdc_db_version'] = '0.131.6';
copy( dirname( __DIR__, 2 ) . '/database/migrations/0051_migrate_pek_mapping_precision_column.php', $migration_dir . DIRECTORY_SEPARATOR . '0051_migrate_pek_mapping_precision_column.php' );
( new MigrationManager( '0.131.7-test', $migration_dir ) )->run();
pek_schema_assert( ! empty( $wpdb->columns['wp_wdc_pek_location_mappings']['mapping_precision'] ), 'Recovered 0050 mapping table must contain mapping_precision.' );
pek_schema_assert( ! empty( $wpdb->existing_tables['wp_wdc_pek_location_mappings'] ) && in_array( '0050_repair_pek_foundation_schema.php', (array) get_option( 'wdc_applied_migrations', array() ), true ) && in_array( '0051_migrate_pek_mapping_precision_column.php', (array) get_option( 'wdc_applied_migrations', array() ), true ) && '0.131.7-test' === get_option( 'wdc_db_version', '' ), '0.131.7 migration run must rerun unapplied 0050, run 0051, and update DB version after success.' );

$wpdb = pek_schema_reset( array( 'wdc_pek_location_mappings' ) );
pek_schema_run_0051();
pek_schema_assert( ! empty( $wpdb->columns['wp_wdc_pek_location_mappings']['mapping_precision'] ), '0051 must add mapping_precision when table exists without it.' );

$wpdb = pek_schema_reset( array( 'wdc_pek_location_mappings' ) );
$wpdb->columns['wp_wdc_pek_location_mappings'] = array( 'precision' => true );
$wpdb->selected_rows = array( array( 'location_id' => 1, 'precision' => 'exact', 'mapping_precision' => null ) );
pek_schema_run_0051();
pek_schema_assert( ! empty( $wpdb->columns['wp_wdc_pek_location_mappings']['mapping_precision'] ) && 'exact' === (string) $wpdb->selected_rows[0]['mapping_precision'], '0051 must add and backfill mapping_precision from legacy precision.' );

$wpdb = pek_schema_reset( array( 'wdc_pek_location_mappings' ) );
$wpdb->columns['wp_wdc_pek_location_mappings'] = array( 'precision' => true, 'mapping_precision' => true );
$wpdb->selected_rows = array( array( 'location_id' => 1, 'precision' => 'exact', 'mapping_precision' => 'near' ) );
pek_schema_run_0051();
pek_schema_assert( 'near' === (string) $wpdb->selected_rows[0]['mapping_precision'], '0051 must not overwrite existing mapping_precision values.' );

$wpdb = pek_schema_reset();
pek_schema_run_0051();
pek_schema_assert( array() === $GLOBALS['pek_schema_db_delta'], '0051 must no-op when mapping table is absent.' );

$wpdb = pek_schema_reset( array( 'wdc_pek_location_mappings' ) );
$wpdb->alter_fails = true;
try {
	pek_schema_run_0051();
	pek_schema_assert( false, '0051 ALTER failure must throw.' );
} catch ( RuntimeException ) {
	pek_schema_assert( empty( $wpdb->columns['wp_wdc_pek_location_mappings']['mapping_precision'] ), '0051 ALTER failure must leave postcondition unsatisfied for retry.' );
}

$wpdb = pek_schema_reset( array( 'wdc_pek_location_mappings' ) );
$wpdb->columns['wp_wdc_pek_location_mappings'] = array( 'precision' => true, 'mapping_precision' => true );
$wpdb->update_query_fails = true;
try {
	pek_schema_run_0051();
	pek_schema_assert( false, '0051 UPDATE failure must throw.' );
} catch ( RuntimeException ) {
}

@unlink( $migration_dir . DIRECTORY_SEPARATOR . '0050_repair_pek_foundation_schema.php' );
@unlink( $migration_dir . DIRECTORY_SEPARATOR . '0051_migrate_pek_mapping_precision_column.php' );
@rmdir( $migration_dir );
pek_schema_assert( empty( $GLOBALS['pek_schema_http_requests'] ), 'PEK schema integrity smoke must not perform PEK API calls.' );
pek_schema_assert( ! property_exists( $wpdb, 'locations' ), 'PEK schema integrity must not mutate canonical locations.' );

echo "PEK schema integrity smoke OK\n";
