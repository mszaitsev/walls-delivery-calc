<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$temporary = sys_get_temp_dir() . '/wdc-alias-migration-' . bin2hex( random_bytes( 6 ) );
mkdir( $temporary . '/wp-admin/includes', 0777, true );
mkdir( $temporary . '/migrations' );
file_put_contents( $temporary . '/wp-admin/includes/upgrade.php', "<?php\n" );
define( 'ABSPATH', $temporary . '/' );
require $root . '/src/Infrastructure/Database/MigrationManager.php';
use WallsShop\WDC\Infrastructure\Database\MigrationManager;

function check( bool $value, string $message ): void { if ( ! $value ) { throw new RuntimeException( $message ); } }
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, ...$args ) { $GLOBALS['options'][$key] = $value; return true; }
function dbDelta( $sql ) { $GLOBALS['wpdb']->query( $sql ); }
$wpdb = new class {
	public string $prefix = 'shop_42_';
	public string $last_error = '';
	public bool $fail = false;
	public array $tables = array( 'shop_42_wdc_locations' => 'canonical', 'shop_42_wdc_location_aliases_backup_20260909_120000' => 'legacy' );
	public function get_charset_collate(): string { return ''; }
	public function query( string $sql ): int|false {
		if ( preg_match( '/^CREATE TABLE (?:IF NOT EXISTS )?([^\s]+)/', $sql, $m ) ) { $this->tables[$m[1]] = 'schema'; }
		elseif ( preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`$/D', $sql, $m ) ) {
			if ( $this->fail ) { $this->last_error = 'denied'; return false; }
			unset( $this->tables[$m[1]] );
		} else { throw new RuntimeException( 'Unexpected SQL: ' . $sql ); }
		return 1;
	}
};
$files = array( '0003_create_location_aliases_table.php', '0009_add_location_alias_unique_key.php', '0064_drop_location_aliases.php' );
try {
	foreach ( $files as $file ) { copy( $root . '/database/migrations/' . $file, $temporary . '/migrations/' . $file ); }
	$GLOBALS['options'] = array();
	$manager = new MigrationManager( '0.155.16', $temporary . '/migrations' );
	$manager->run();
	check( ! isset( $wpdb->tables['shop_42_wdc_location_aliases'] ), 'Fresh alias migration chain ends without alias table' );
	check( 'canonical' === $wpdb->tables['shop_42_wdc_locations'] && 'legacy' === $wpdb->tables['shop_42_wdc_location_aliases_backup_20260909_120000'], 'Locations and dynamic backups untouched' );
	check( $manager->is_current(), 'All migration files applied' );
	$manager->run();
	$drop = require $root . '/database/migrations/' . $files[2];
	$drop();
	check( ! isset( $wpdb->tables['shop_42_wdc_location_aliases'] ), 'Repeated DROP is idempotent' );
	$GLOBALS['options']['wdc_applied_migrations'] = array_slice( $files, 0, 2 );
	$wpdb->tables['shop_42_wdc_location_aliases'] = 'old';
	check( ! $manager->is_current(), 'Pending migration detected at unchanged plugin version' );
	$wpdb->fail = true;
	try { $manager->run(); throw new LogicException( 'Expected migration refusal' ); } catch ( RuntimeException $expected ) {}
	check( ! in_array( $files[2], $manager->applied_migrations(), true ), 'Failed migration not marked applied' );
	$wpdb->fail = false;
	$manager->run();
	check( ! isset( $wpdb->tables['shop_42_wdc_location_aliases'] ) && $manager->is_current(), 'Retry upgrades existing schema' );
	$references = array();
	foreach ( glob( $root . '/database/migrations/*.php' ) as $path ) {
		if ( str_contains( file_get_contents( $path ), 'wdc_location_aliases' ) ) { $references[] = basename( $path ); }
	}
	check( $files === $references, 'No later migration recreates aliases; historical migrations retained' );
	echo "Location alias-removal migration smoke passed.\n";
} finally {
	foreach ( $files as $file ) { if ( is_file( $temporary . '/migrations/' . $file ) ) { unlink( $temporary . '/migrations/' . $file ); } }
	unlink( $temporary . '/wp-admin/includes/upgrade.php' );
	rmdir( $temporary . '/migrations' ); rmdir( $temporary . '/wp-admin/includes' ); rmdir( $temporary . '/wp-admin' ); rmdir( $temporary );
}
