<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/../../' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $delivery_codes = array();
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
	}
}

function current_time( string $type ): string {
	static $tick = 0;
	++$tick;

	return '2026-06-16 12:00:' . str_pad( (string) $tick, 2, '0', STR_PAD_LEFT );
}

require_once __DIR__ . '/../../src/Locations/Storage/LocationDeliveryCodeRepository.php';

use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;

function assert_true( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 1 ),
	array( 'id' => 2 ),
);

$repository = new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] );

assert_true( null === $repository->find_by_location_id( 1 ), 'find_by_location_id returns null before save' );
assert_true( null === $repository->get_dpd_city_id( 1 ), 'get_dpd_city_id returns null before save' );

assert_true( $repository->save_dpd_city_id( 1, '123456' ), 'save_dpd_city_id inserts row' );
assert_true( 1 === count( $GLOBALS['wpdb']->delivery_codes ), 'insert creates one row' );
assert_true( '123456' === $repository->get_dpd_city_id( 1 ), 'get_dpd_city_id returns saved value' );
$first_updated_at = (string) $GLOBALS['wpdb']->delivery_codes[0]['updated_at'];
assert_true( '' !== $first_updated_at, 'updated_at is set on insert' );

assert_true( $repository->save_dpd_city_id( 1, 654321 ), 'save_dpd_city_id updates row' );
assert_true( 1 === count( $GLOBALS['wpdb']->delivery_codes ), 'update keeps one row per location_id' );
assert_true( '654321' === $repository->get_dpd_city_id( 1 ), 'get_dpd_city_id returns updated value' );
assert_true( $first_updated_at !== (string) $GLOBALS['wpdb']->delivery_codes[0]['updated_at'], 'updated_at changes on update' );

assert_true( $repository->save_dpd_city_id( 99, '999999' ), 'test fixture can create orphan row' );
assert_true( 1 === $repository->cleanup_orphans(), 'cleanup_orphans removes rows without location_id in wdc_locations' );
assert_true( null === $repository->get_dpd_city_id( 99 ), 'orphan row is gone after cleanup' );

assert_true( $repository->delete_by_location_id( 1 ), 'delete_by_location_id deletes existing row' );
assert_true( null === $repository->get_dpd_city_id( 1 ), 'deleted location code is no longer readable' );

echo "Location delivery codes smoke OK\n";
