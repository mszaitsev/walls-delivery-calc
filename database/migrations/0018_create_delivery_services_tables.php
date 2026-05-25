<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	$services_table = $wpdb->prefix . 'wdc_delivery_services';
	$services_columns = "(
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		service_key varchar(120) NOT NULL,
		carrier_key varchar(120) NOT NULL DEFAULT '',
		service_type varchar(40) NOT NULL,
		title varchar(255) NOT NULL,
		enabled tinyint(1) NOT NULL DEFAULT 1,
		availability_mode varchar(40) NOT NULL DEFAULT 'selected_countries',
		use_default_rules_when_no_service_rules tinyint(1) NOT NULL DEFAULT 1,
		round_up_to_ruble tinyint(1) NOT NULL DEFAULT 1,
		minimum_price_rub decimal(12,4) NOT NULL DEFAULT 1.0000,
		include_packaging_weight tinyint(1) NOT NULL DEFAULT 1,
		packaging_weight_mode varchar(30) NOT NULL DEFAULT 'total_weight',
		pickup_customer_comment text NULL,
		courier_customer_comment text NULL,
		sort_order int NOT NULL DEFAULT 100,
		deleted tinyint(1) NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY service_key (service_key),
		KEY carrier_key (carrier_key),
		KEY enabled (enabled),
		KEY service_type (service_type),
		KEY deleted (deleted),
		KEY sort_order (sort_order)
	) {$charset_collate}";

	$settings_table = $wpdb->prefix . 'wdc_delivery_service_settings';
	$settings_columns = "(
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		service_id bigint(20) unsigned NOT NULL,
		setting_key varchar(120) NOT NULL,
		setting_value longtext NULL,
		value_format varchar(20) NOT NULL DEFAULT 'json',
		autoload tinyint(1) NOT NULL DEFAULT 0,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY service_setting (service_id, setting_key),
		KEY service_id (service_id),
		KEY setting_key (setting_key)
	) {$charset_collate}";

	$countries_table = $wpdb->prefix . 'wdc_delivery_service_countries';
	$countries_columns = "(
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		service_id bigint(20) unsigned NOT NULL,
		country_code varchar(2) NOT NULL,
		created_at datetime NOT NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY service_country (service_id, country_code),
		KEY country_code (country_code)
	) {$charset_collate}";

	if ( ! function_exists( 'dbDelta' ) && is_readable( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$services_table} {$services_columns};" );
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$settings_table} {$settings_columns};" );
	$wpdb->query( "CREATE TABLE IF NOT EXISTS {$countries_table} {$countries_columns};" );
	dbDelta( "CREATE TABLE {$services_table} {$services_columns};" );
	dbDelta( "CREATE TABLE {$settings_table} {$settings_columns};" );
	dbDelta( "CREATE TABLE {$countries_table} {$countries_columns};" );
};
