<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function rp_cancel_lock_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function maybe_serialize( mixed $value ): string {
	return is_array( $value ) || is_object( $value ) ? serialize( $value ) : (string) $value;
}

$GLOBALS['rp_lock_database'] = null;
$GLOBALS['rp_lock_cache'] = null;
$GLOBALS['rp_legacy_lock'] = false;

function get_option( string $name, mixed $default = false ): mixed {
	return null !== $GLOBALS['rp_lock_cache'] ? $GLOBALS['rp_lock_cache'] : $default;
}

function delete_option( string $name ): bool {
	$GLOBALS['rp_lock_database'] = null;
	$GLOBALS['rp_lock_cache'] = null;
	return true;
}

function get_transient( string $name ): mixed {
	return $GLOBALS['rp_legacy_lock'];
}

function delete_transient( string $name ): bool {
	$GLOBALS['rp_legacy_lock'] = false;
	return true;
}

function wp_cache_delete( string $key, string $group = '' ): bool {
	if ( WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImportLock::OPTION_NAME === $key ) {
		$GLOBALS['rp_lock_cache'] = $GLOBALS['rp_lock_database'];
	}
	return true;
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $options = 'wp_options';
		public string $last_error = '';
		/** @var array<int,mixed> */
		private array $arguments = array();

		public function prepare( string $query, mixed ...$arguments ): string {
			$this->arguments = $arguments;
			return $query;
		}

		public function query( string $query ): int|false {
			if ( str_starts_with( trim( $query ), 'DELETE FROM' ) ) {
				$expected = (string) ( $this->arguments[1] ?? '' );
				if ( maybe_serialize( $GLOBALS['rp_lock_database'] ) !== $expected ) {
					return 0;
				}
				$GLOBALS['rp_lock_database'] = null;
				return 1;
			}
			return false;
		}
	}
}

use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupImportLock;

$old_cached_lock = array( 'job_id' => 'cancelled-job', 'token' => 'owner-token', 'acquired_at' => 100, 'expires_at' => 1000 );
$renewed_database_lock = array_merge( $old_cached_lock, array( 'expires_at' => 2000 ) );
$GLOBALS['rp_lock_cache'] = $old_cached_lock;
$GLOBALS['rp_lock_database'] = $renewed_database_lock;

$lock = new RussianPostPickupImportLock( new wpdb() );
$lock->release_terminal( 'cancelled-job' );
rp_cancel_lock_assert( null === $GLOBALS['rp_lock_database'], 'Terminal release must invalidate stale option cache, reread the renewed same-owner lock, and delete it.' );

$new_owner_lock = array( 'job_id' => 'new-job', 'token' => 'new-token', 'acquired_at' => 300, 'expires_at' => 3000 );
$GLOBALS['rp_lock_cache'] = $old_cached_lock;
$GLOBALS['rp_lock_database'] = $new_owner_lock;
$lock->release_terminal( 'cancelled-job' );
rp_cancel_lock_assert( $new_owner_lock === $GLOBALS['rp_lock_database'], 'Stale cancel cleanup must not delete a new owner lock after cache refresh.' );

echo "Russian Post cancel lock race smoke passed.\n";
