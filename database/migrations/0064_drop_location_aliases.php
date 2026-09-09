<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = str_replace( '`', '``', $wpdb->prefix . 'wdc_location_aliases' );
	if ( false === $wpdb->query( 'DROP TABLE IF EXISTS `' . $table . '`' ) ) {
		throw new RuntimeException( 'Unable to remove the retired location alias table: ' . $wpdb->last_error );
	}
};
