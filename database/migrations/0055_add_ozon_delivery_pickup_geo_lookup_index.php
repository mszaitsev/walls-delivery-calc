<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function ( \wpdb $wpdb ): void {
	$table = $wpdb->prefix . 'wdc_ozon_delivery_pickup_points';
	$index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'active_geo_lookup' ) );
	if ( null !== $index ) {
		return;
	}

	if ( false === $wpdb->query( "ALTER TABLE {$table} ADD KEY active_geo_lookup (generation_id,is_active,latitude,longitude)" ) ) {
		throw new RuntimeException( 'Ozon pickup geo lookup index migration failed.' );
	}
};
