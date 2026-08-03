<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\Installation\PekSchemaIntegrityService;
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
			if ( ! in_array( $table, $GLOBALS['pek_schema_db_delta_fail_tables'] ?? array(), true ) ) {
				$GLOBALS['wpdb']->existing_tables[ $table ] = true;
			}
		}

		return array();
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public string $last_error = '';
		/** @var array<string,bool> */
		public array $existing_tables = array();
		/** @var array<int,string> */
		public array $queries = array();
		public bool $table_check_fails = false;

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

			return null;
		}
	}
}

function pek_schema_reset( array $tables = array() ): wpdb {
	$GLOBALS['wpdb'] = new wpdb();
	foreach ( $tables as $table ) {
		$GLOBALS['wpdb']->existing_tables[ 'wp_' . $table ] = true;
	}
	$GLOBALS['pek_schema_db_delta'] = array();
	$GLOBALS['pek_schema_db_delta_fail_tables'] = array();
	$GLOBALS['pek_schema_options'] = array();

	return $GLOBALS['wpdb'];
}

function pek_schema_repair(): array {
	return ( new PekSchemaIntegrityService() )->repair();
}

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

@unlink( $migration_dir . DIRECTORY_SEPARATOR . '0050_repair_pek_foundation_schema.php' );
@rmdir( $migration_dir );
pek_schema_assert( empty( $GLOBALS['pek_schema_http_requests'] ), 'PEK schema integrity smoke must not perform PEK API calls.' );
pek_schema_assert( ! property_exists( $wpdb, 'locations' ), 'PEK schema integrity must not mutate canonical locations.' );

echo "PEK schema integrity smoke OK\n";
