<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\Cache\DeliveryQuoteCacheManager;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Locations\Admin\LocationDatabaseBackupAdmin;
use WallsShop\WDC\Locations\Admin\LocationsAdminPage;
use WallsShop\WDC\Locations\Services\LocationCountryIndexService;
use WallsShop\WDC\Locations\Services\LocationDatabaseBackupService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\Storage\LocationWriteLock;

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
define( 'ARRAY_N', 'ARRAY_N' );
require_once ABSPATH . 'src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC', ABSPATH . 'src' ) )->register();
$GLOBALS['options'] = array();
$GLOBALS['updates'] = array();
$GLOBALS['allowed'] = true;
$GLOBALS['nonce_valid'] = true;
function get_option( $key, $default = false ) { return $GLOBALS['options'][$key] ?? $default; }
function update_option( $key, $value, $autoload = false ) { $GLOBALS['options'][$key] = $value; $GLOBALS['updates'][] = $key; return true; }
function delete_option( $key ) { unset( $GLOBALS['options'][$key] ); return true; }
function current_datetime() { return new DateTimeImmutable( '2026-09-08 22:05:30', new DateTimeZone( 'Asia/Novosibirsk' ) ); }
function wc_get_logger() { return new class { public function log( ...$args ) {} }; }
function current_user_can( $cap ) { check( 'manage_woocommerce' === $cap, 'Capability contract' ); return $GLOBALS['allowed']; }
function check_admin_referer( $action ) { if ( ! $GLOBALS['nonce_valid'] ) { throw new RuntimeException( 'nonce' ); } $GLOBALS['nonce_actions'][] = $action; }
function check_ajax_referer( ...$args ) { check_admin_referer( $args[0] ); return true; }
function wp_verify_nonce( ...$args ) { return $GLOBALS['nonce_valid']; }
function wp_die( $message, ...$args ) { throw new RuntimeException( $message ); }
function esc_html( $value ) { return htmlspecialchars( $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return esc_html( $value ); }
function esc_url( $value ) { return esc_html( $value ); }
function esc_js( $value ) { return addslashes( $value ); }
function esc_html__( $value, $domain = '' ) { return esc_html( $value ); }
function __( $value, $domain = '' ) { return $value; }
function trailingslashit( $value ) { return rtrim( $value, '/\\' ) . '/'; }
function admin_url( $value ) { return '/wp-admin/' . $value; }
function wp_nonce_field( $action ) { echo '<input name="_wpnonce" value="' . esc_attr( $action ) . '">'; }
function wp_nonce_url( $url, $action ) { return $url . '&_wpnonce=' . $action; }
function add_query_arg( $key, $value, $url = '' ) { return is_array( $key ) ? $value . '?' . http_build_query( $key ) : $url . '?' . urlencode( $key ) . '=' . urlencode( $value ); }
function nocache_headers() {}
function add_action( $hook, $callback ) { $GLOBALS['hooks'][$hook] = $callback; }
final class JsonResponse extends Exception { public function __construct( public array $data, public bool $success ) {} }
final class RedirectResponse extends Exception { public function __construct( public string $url, public int $status ) {} }
function wp_safe_redirect( $url, $status ) { throw new RedirectResponse( $url, $status ); }
function wp_send_json_success( $data ) { throw new JsonResponse( $data, true ); }
function wp_send_json_error( $data ) { throw new JsonResponse( $data, false ); }
function check( bool $value, string $message ): void { if ( ! $value ) { throw new RuntimeException( $message ); } }
function refused( callable $callback, ?int $code = null ): void {
    try { $callback(); } catch ( RuntimeException $error ) { if ( null !== $code ) { check( $code === $error->getCode(), 'Refusal code' ); } return; }
    throw new RuntimeException( 'Expected refusal' );
}

// SQL-shaped fake: copy/rename operate on separate tables and fail before mutation.
class wpdb {
    public string $prefix = 'shop_42_';
    public string $last_error = '';
    public array $options = array();
    public array $tables = array();
    public array $sql = array();
    public string $fail = '';
    public bool $held = false;
    public int $released = 0;
    public bool $short_copy = false;
    public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
    public function esc_like( $value ) { return addcslashes( $value, '_%\\' ); }
    public function get_col( $query ) {
        $this->last_error = '';
        $prefix = stripslashes( rtrim( $query[1][0], '%' ) );
        return array_values( array_filter( array_keys( $this->tables ), fn( $name ) => str_starts_with( $name, $prefix ) ) );
    }
    public function get_var( $query ) {
        $this->last_error = '';
        $sql = is_array( $query ) ? $query[0] : $query;
        $this->sql[] = $sql;
        if ( str_contains( $sql, 'GET_LOCK' ) ) { check( $query[1][0] === 'wdc_locations_write_lock', 'Fixed lock name' ); if ( $this->held ) { return '0'; } $this->held = true; return '1'; }
        if ( str_contains( $sql, 'RELEASE_LOCK' ) ) { $this->held = false; ++$this->released; return '1'; }
        if ( str_contains( $sql, 'information_schema' ) ) { return '0'; }
        if ( str_starts_with( $sql, 'SHOW TABLES' ) ) { $name = stripslashes( $query[1][0] ); return isset( $this->tables[$name] ) ? $name : null; }
        if ( preg_match( '/^SELECT COUNT\(\*\) FROM `([^`]+)`$/', $sql, $m ) ) { return (string) count( $this->tables[$m[1]]['rows'] ); }
        throw new RuntimeException( 'Unexpected scalar SQL: ' . $sql );
    }
    public function get_row( $sql, $format ) {
        $this->last_error = '';
        preg_match( '/`([^`]+)`/', $sql, $m );
        return isset( $this->tables[$m[1]] ) ? array( $m[1], 'CREATE TABLE `' . $m[1] . '` ' . $this->tables[$m[1]]['schema'] ) : null;
    }
    public function query( $sql ) {
        $this->sql[] = $sql;
        $this->last_error = '';
        check( $this->held, 'Every mutation holds shared lock' );
        if ( '' !== $this->fail && str_contains( $sql, $this->fail ) ) { $this->last_error = 'injected failure'; return false; }
        if ( preg_match( '/^CREATE TABLE `([^`]+)` LIKE `([^`]+)`$/', $sql, $m ) ) { check( ! isset( $this->tables[$m[1]] ), 'No table overwrite' ); $this->tables[$m[1]] = array( 'schema' => $this->tables[$m[2]]['schema'], 'rows' => array() ); }
        elseif ( preg_match( '/^INSERT INTO `([^`]+)` SELECT \* FROM `([^`]+)`$/', $sql, $m ) ) { $this->tables[$m[1]]['rows'] = $this->short_copy ? array() : $this->tables[$m[2]]['rows']; }
        elseif ( str_starts_with( $sql, 'ALTER TABLE' ) ) { /* next-ID preservation is asserted in the query log */ }
        elseif ( str_starts_with( $sql, 'RENAME TABLE' ) ) {
            preg_match_all( '/`([^`]+)` TO `([^`]+)`/', $sql, $snapshots, PREG_SET_ORDER );
            $next = $this->tables;
            foreach ( $snapshots as $snapshot ) { unset( $next[$snapshot[1]] ); }
            foreach ( $snapshots as $snapshot ) { $next[$snapshot[2]] = $this->tables[$snapshot[1]]; }
            $this->tables = $next;
        } elseif ( preg_match( '/^DROP TABLE IF EXISTS `([^`]+)`$/', $sql, $m ) ) { unset( $this->tables[$m[1]] ); }
        else { throw new RuntimeException( 'Unexpected SQL: ' . $sql ); }
        return 1;
    }
}
function fixture(): array {
    $db = new wpdb();
    $names = array( $db->prefix . 'wdc_locations' );
    foreach ( $names as $i => $name ) { $db->tables[$name] = array( 'schema' => '(id bigint NOT NULL AUTO_INCREMENT, value text, PRIMARY KEY (id)) ENGINE=InnoDB AUTO_INCREMENT=90', 'rows' => array( array( 'id' => 1, 'value' => 'historical-' . $i ) ) ); }
    $GLOBALS['options'] = $GLOBALS['updates'] = array();
    $lock = new LocationWriteLock( $db );
    $service = new LocationDatabaseBackupService( $lock, new LocationCountryIndexService( new LocationRepository( $db ) ), new DeliveryQuoteCacheManager( null, $db ), new Logger(), $db );
    return array( $db, $service, $lock, $names );
}
function old_snapshot( wpdb $db, array $names, string $stamp ): void { foreach ( $names as $name ) { $db->tables[$name . '_backup_' . $stamp] = $db->tables[$name]; } }

[$db, $service, $lock, $names] = fixture();
$admin = new LocationDatabaseBackupAdmin( $service, new PluginEnvironment( ABSPATH . 'walls-delivery-calc.php', ABSPATH, '/', '0.155.16' ), new Logger() );
if ( in_array( '--download', $argv, true ) ) { $_GET['path'] = 'not-allowed'; $admin->download(); }

check( array() === $service->status(), 'Initially no backup' );
old_snapshot( $db, $names, '20260906_080000' );
old_snapshot( $db, $names, '20260907_080000' );
$db->tables[$names[0] . '_backup_20260908_010000'] = $db->tables[$names[0]];
$db->tables[$names[0] . '_backup_20269999_999999'] = $db->tables[$names[0]];
check( '20260908_010000' === $service->status()['timestamp'], 'Newest location backup is sufficient' );
$service->create();
check( ! $db->held && 1 === $db->released, 'Lock released after create' );
check( '08.09.2026 22:05' === $service->status()['date'], 'Site time in status' );
foreach ( $names as $name ) {
    check( $db->tables[$name] === $db->tables[$name . '_backup_20260908_220530'], 'Schema/data copied' );
    check( ! isset( $db->tables[$name . '_backup_20260907_080000'] ), 'Previous snapshot removed' );
}
check( isset( $db->tables[$names[0] . '_backup_20269999_999999'] ), 'Unrelated names untouched' );
$sql = implode( "\n", $db->sql );
check( strpos( $sql, 'RENAME TABLE' ) < strpos( $sql, 'DROP TABLE' ), 'Old cleanup only after publication' );
check( str_contains( $sql, 'AUTO_INCREMENT=90' ), 'Next ID preserved' );
check( array() === $GLOBALS['updates'], 'Create does not invalidate caches' );
$historical = array_map( fn( $name ) => $db->tables[$name], $names );
foreach ( $names as $name ) { $db->tables[$name]['rows'] = array( array( 'id' => 9, 'value' => 'changed' ) ); }
$service->restore();
foreach ( $names as $i => $name ) { check( $historical[$i] === $db->tables[$name], 'Historical dataset restored' ); check( isset( $db->tables[$name . '_backup_20260908_220530'] ), 'Backup retained' ); }
$swaps = array_values( array_filter( $db->sql, fn( $q ) => str_starts_with( $q, 'RENAME TABLE' ) ) );
check( 2 === substr_count( $swaps[1], ' TO ' ), 'One atomic locations-only restore statement' );
check( $GLOBALS['updates'] === array( LocationCountryIndexService::OPTION, DeliveryQuoteCacheManager::CACHE_VERSION_OPTION ), 'Successful restore invalidates actual caches once' );
$service->restore();
check( ! $db->held, 'Repeat restore possible' );

foreach ( array( 'INSERT INTO `shop_42_wdc_locations_backup_tmp_', 'RENAME TABLE', 'CREATE TABLE' ) as $failure ) {
    [$db, $service, $lock, $names] = fixture(); old_snapshot( $db, $names, '20260907_080000' ); $before = $db->tables; $db->fail = $failure;
    refused( fn() => $service->create() ); check( $before === $db->tables, 'Create failure preserves old snapshot and cleans new staging' ); check( ! $db->held, 'Failure releases lock' );
}
[$db, $service, $lock, $names] = fixture(); $db->short_copy = true; $before = $db->tables;
refused( fn() => $service->create() ); check( $before === $db->tables, 'Count mismatch cleanup' );
[$db, $service, $lock, $names] = fixture(); foreach ( $names as $name ) { $db->tables[$name]['rows'] = array(); } $service->create(); check( ! empty( $service->status() ), 'Empty snapshot valid' );
[$db, $service, $lock, $names] = fixture();
$db->tables[$names[0] . '_backup_20260907_080000'] = $db->tables[$names[0]];
check( '20260907_080000' === $service->status()['timestamp'], 'Location-only legacy backup is restorable' ); $service->restore();
$legacy = $db->prefix . 'wdc_location_aliases_backup_20260907_080000';
$db->tables[$legacy] = array( 'schema' => 'incompatible legacy schema', 'rows' => array() );
$service->restore();
check( isset( $db->tables[$legacy] ) && ! isset( $db->tables[$db->prefix . 'wdc_location_aliases'] ), 'Legacy companion ignored; restore never recreates live alias table' );
$service->create();
check( isset( $db->tables[$legacy] ), 'Legacy alias backup remains inert after new backup' );
[$db, $service, $lock, $names] = fixture(); old_snapshot( $db, $names, '20260907_080000' ); $db->fail = 'DROP TABLE';
check( ! empty( $service->create()['warnings'] ), 'Old cleanup failure is reported' );
check( '20260908_220530' === $service->status()['timestamp'], 'New valid backup survives old DROP failure' );
foreach ( array( 'INSERT INTO `shop_42_wdc_locations_restore_tmp_', 'RENAME TABLE' ) as $failure ) {
    [$db, $service, $lock, $names] = fixture(); old_snapshot( $db, $names, '20260907_080000' ); $before = $db->tables; $db->fail = $failure;
    refused( fn() => $service->restore() ); check( $before === $db->tables, 'Restore failure preserves live and backup snapshots' ); check( array() === $GLOBALS['updates'], 'No cache invalidation after failure' ); check( ! $db->held, 'Failed restore releases lock' );
}
foreach ( array( 0 ) as $i ) {
    [$db, $service, $lock, $names] = fixture(); old_snapshot( $db, $names, '20260907_080000' ); $db->tables[$names[$i] . '_backup_20260907_080000']['schema'] .= ' COMMENT="old schema"'; $before = $db->tables;
    refused( fn() => $service->restore(), 422 ); check( $before === $db->tables, 'Either schema mismatch refuses whole restore' );
}
foreach ( array( 'wdc_gar_import_job', 'wdc_locations_incremental_update_job', 'wdc_locations_snapshot_import_job', 'wdc_dpd_geography_import_state' ) as $key ) {
    [$db, $service] = fixture(); $GLOBALS['options'][$key] = array( 'phase' => 'processing' );
    refused( fn() => $service->create(), 423 ); refused( fn() => $service->restore(), 423 ); check( ! $db->held, 'Job refusal releases lock' );
}
[$db, $service, $lock] = fixture(); $db->held = true; $before = $db->tables;
refused( fn() => $service->create(), 409 ); refused( fn() => $service->restore(), 409 ); check( $before === $db->tables && $db->held, 'Other writer lock prevents backup without releasing its lock' );
$db->held = false;
refused( fn() => $lock->run( fn() => throw new RuntimeException( 'writer failure' ) ) ); check( ! $db->held, 'Writer exception releases lock' );

$dpd = ( new ReflectionClass( \WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportService::class ) )->newInstanceWithoutConstructor();
( new ReflectionProperty( $dpd, 'locations_write_lock' ) )->setValue( $dpd, $lock );
( new ReflectionProperty( $dpd, 'state' ) )->setValue( $dpd, new \WallsShop\WDC\Carriers\Dpd\Geography\DpdGeographyImportStateService() );
$db->held = true;
check( 'busy' === $dpd->step()['step_control']['outcome'], 'DPD batch refuses shared lock contention' );
$result = ( new ReflectionMethod( $dpd, 'run_locked_start' ) )->invoke( $dpd, 'manual', fn() => throw new LogicException( 'Must not run' ) );
check( 'busy' === $result['operation_control']['outcome'], 'DPD start refuses before job mutation' );
$db->held = false;

// Exercise actual registered AJAX wrapper, not a direct call that skips hook ownership.
$page = ( new ReflectionClass( LocationsAdminPage::class ) )->newInstanceWithoutConstructor();
( new ReflectionProperty( $page, 'write_lock' ) )->setValue( $page, $lock );
$register = new ReflectionMethod( $page, 'register_write_action' );
foreach ( array( 'ajax_gar_import_start', 'ajax_gar_import_step', 'ajax_incremental_update_step' ) as $method ) {
    $register->invoke( $page, $method, $method ); $db->held = true; $before = $db->tables;
    try { $GLOBALS['hooks'][$method](); } catch ( JsonResponse $response ) { check( ! $response->success, 'Busy import refused' ); }
    check( $before === $db->tables, 'Busy handler refuses before clear or mutation' );
}
$db->held = false;
$register->invoke( $page, 'cancel', 'ajax_gar_import_cancel' );
try { $GLOBALS['hooks']['cancel'](); } catch ( JsonResponse $response ) { check( ! $db->held, 'Lock released before terminating JSON response' ); }

foreach ( array( 'create', 'restore', 'download' ) as $method ) {
    $_SERVER['REQUEST_METHOD'] = 'POST'; $GLOBALS['allowed'] = false; refused( fn() => $admin->$method() );
    $GLOBALS['allowed'] = true; $GLOBALS['nonce_valid'] = false; refused( fn() => $admin->$method() ); $GLOBALS['nonce_valid'] = true;
}
$_SERVER['REQUEST_METHOD'] = 'GET'; refused( fn() => $admin->create() ); refused( fn() => $admin->restore() );
[$db, $service] = fixture();
$admin = new LocationDatabaseBackupAdmin( $service, new PluginEnvironment( ABSPATH . 'walls-delivery-calc.php', ABSPATH, '/', '0.155.16' ), new Logger() );
$_SERVER['REQUEST_METHOD'] = 'POST';
foreach ( array( 'create' => 'created', 'restore' => 'restored' ) as $method => $notice ) {
    try { $admin->$method(); throw new LogicException( 'Expected redirect' ); } catch ( RedirectResponse $response ) {
        check( 303 === $response->status && str_contains( $response->url, 'wdc_backup_notice=' . $notice ), 'Successful action redirects to notice' );
        check( ! $db->held, 'Lock released before redirect' );
    }
}
check( in_array( 'wdc_locations_backup_create', $GLOBALS['nonce_actions'], true ) && in_array( 'wdc_locations_backup_restore', $GLOBALS['nonce_actions'], true ), 'Action-specific nonces' );
ob_start(); $admin->render_script(); $html = ob_get_clean();
$expected = "pwsh -ExecutionPolicy Bypass -File \"D:\\FIAS\\Export-GarPlaces.ps1\" `\n  -Archive \"D:\\FIAS\\gar_xml_full.zip\" `\n  -OutCsv \"D:\\FIAS\\out\\gar_places.csv\" `\n  -IncludeOptionalCodes";
check( str_contains( str_replace( "\r\n", "\n", html_entity_decode( $html, ENT_QUOTES, 'UTF-8' ) ), '<pre><code>' . $expected . '</code></pre>' ), 'Exact escaped multiline command' );
ob_start(); $admin->render(); $html = ob_get_clean(); check( str_contains( $html, 'Резервная копия базы населенных пунктов' ), 'Backup section renders' );
$process = proc_open( array( PHP_BINARY, __FILE__, '--download' ), array( 1 => array( 'pipe', 'wb' ), 2 => array( 'pipe', 'wb' ) ), $pipes, null, null, array( 'bypass_shell' => true ) );
check( is_resource( $process ), 'Download child process starts' );
$download = stream_get_contents( $pipes[1] );
$download_error = stream_get_contents( $pipes[2] );
fclose( $pipes[1] ); fclose( $pipes[2] );
check( 0 === proc_close( $process ), 'Download process succeeds: ' . $download_error );
check( $download === file_get_contents( ABSPATH . 'src/Export-GarPlaces.ps1' ), 'Download exact fixed file bytes, no HTML/user path' );
$source = file_get_contents( ABSPATH . 'src/Locations/Admin/LocationDatabaseBackupAdmin.php' );
check( str_contains( $source, 'Content-Disposition: attachment; filename="Export-GarPlaces.ps1"' ) && str_contains( $source, 'Content-Type: application/octet-stream' ), 'Fixed attachment headers' );
$missing = new LocationDatabaseBackupAdmin( $service, new PluginEnvironment( '', ABSPATH . 'missing-script-directory', '/', '0.155.16' ), new Logger() );
refused( fn() => $missing->download() );
$guard = new \WallsShop\WDC\Locations\Services\LocationMaintenanceJobGuard();
[$db, $service] = fixture();
$lock = new LocationWriteLock( $db );
foreach ( array( 'staging', 'candidate_seed', 'enrich_coordinates', 'waiting_dadata_limit', 'aliases_build', 'applying', 'cleanup' ) as $phase ) {
    update_option( $guard::UPDATE_OPTION, array( 'job_id' => 'owner', 'phase' => $phase ) );
    refused( fn() => $service->create(), 423 );
    refused( fn() => $service->restore(), 423 );
    refused( fn() => $lock->run( fn() => throw new LogicException( 'Foreign writer must not execute' ) ), 423 );
    refused( fn() => $lock->run( fn() => null, 'other-job' ), 423 );
    check( 'own-step' === $lock->run( fn() => 'own-step', 'owner' ), 'Own incremental steps pass logical lock' );
    check( ! $db->held, 'Logical refusal releases physical lock' );
}
foreach ( array( 'finished', 'failed', 'canceled' ) as $phase ) {
    update_option( $guard::UPDATE_OPTION, array( 'job_id' => 'owner', 'phase' => $phase ) );
    check( 'writer' === $lock->run( fn() => 'writer' ), 'Terminal job releases logical ownership' );
}
delete_option( $guard::UPDATE_OPTION );
$lock->run( function () use ( $guard ) {
    $guard->assert_no_active_jobs();
    update_option( $guard::UPDATE_OPTION, array( 'job_id' => 'reserved', 'phase' => 'staging' ) );
} );
refused( fn() => $lock->run( fn() => $guard->assert_no_active_jobs() ), 423 );
check( 'reserved' === get_option( $guard::UPDATE_OPTION )['job_id'], 'Second start cannot replace reserved job' );
delete_option( $guard::UPDATE_OPTION );
echo "Locations-only backup, logical/physical lock and admin smoke passed.\n";
