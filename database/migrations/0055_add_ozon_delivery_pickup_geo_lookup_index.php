<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;
	$table = $wpdb->prefix . 'wdc_ozon_delivery_pickup_points';
	$quoted = '`' . str_replace( '`', '``', $table ) . '`';
	$index_exists = static function () use ( $wpdb, $quoted ): bool {
		return null !== $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$quoted} WHERE Key_name = %s", 'active_geo_lookup' ) );
	};

	if ( $index_exists() ) {
		return;
	}

	if ( false === $wpdb->query( "ALTER TABLE {$quoted} ADD KEY active_geo_lookup (generation_id,is_active,latitude,longitude)" ) ) {
		throw new RuntimeException( 'Ozon pickup geo lookup index migration failed.' );
	}

	if ( ! $index_exists() ) {
		throw new RuntimeException( 'Ozon pickup geo lookup index migration postcondition failed.' );
	}
};
