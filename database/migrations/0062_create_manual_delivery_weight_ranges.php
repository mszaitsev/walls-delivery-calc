<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	$table = $wpdb->prefix . 'wdc_manual_delivery_weight_ranges';

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			service_id bigint(20) unsigned NOT NULL,
			from_weight_g int unsigned NOT NULL DEFAULT 0,
			to_weight_g int unsigned NOT NULL DEFAULT 0,
			price_kopecks bigint(20) unsigned NOT NULL DEFAULT 0,
			sort_order int NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ux_manual_weight_range (service_id, from_weight_g, to_weight_g),
			KEY service_id (service_id),
			KEY weight_lookup (service_id, from_weight_g, to_weight_g)
		) {$charset_collate};"
	);

	$index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'ux_manual_weight_range' ) );
	if ( null === $index ) {
		throw new RuntimeException( 'Manual delivery weight ranges migration postcondition failed.' );
	}
};
