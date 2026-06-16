<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$table = $wpdb->prefix . 'wdc_location_delivery_codes';

	dbDelta(
		"CREATE TABLE {$table} (
			location_id bigint(20) unsigned NOT NULL,
			dpd_city_id bigint(20) unsigned NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (location_id),
			KEY dpd_city_id (dpd_city_id)
		) {$charset_collate};"
	);
};
