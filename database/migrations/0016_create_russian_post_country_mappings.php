<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_russian_post_country_mappings';

	$charset_collate = '';
	if ( method_exists( $wpdb, 'get_charset_collate' ) ) {
		$charset_collate = $wpdb->get_charset_collate();
	}

	if ( ! function_exists( 'dbDelta' ) && is_readable( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}

	if ( ! function_exists( 'dbDelta' ) ) {
		$wpdb->query(
			"CREATE TABLE IF NOT EXISTS {$table} (
				id bigint unsigned NOT NULL AUTO_INCREMENT,
				wc_country_code varchar(2) NOT NULL,
				wc_country_name varchar(190) NOT NULL DEFAULT '',
				rp_country_id varchar(50) NOT NULL DEFAULT '',
				rp_country_name varchar(190) NOT NULL DEFAULT '',
				rp_iso2 varchar(2) NOT NULL DEFAULT '',
				has_parcel tinyint(1) NOT NULL DEFAULT 0,
				parcel_block tinyint(1) NOT NULL DEFAULT 0,
				api_available tinyint(1) NOT NULL DEFAULT 0,
				matched tinyint(1) NOT NULL DEFAULT 0,
				manual_mode varchar(20) NOT NULL DEFAULT 'auto',
				effective_enabled tinyint(1) NOT NULL DEFAULT 0,
				last_checked_at datetime NULL,
				manual_comment varchar(255) NOT NULL DEFAULT '',
				raw_json longtext NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY wc_country_code (wc_country_code),
				KEY rp_country_id (rp_country_id),
				KEY rp_iso2 (rp_iso2),
				KEY matched (matched),
				KEY effective_enabled (effective_enabled),
				KEY manual_mode (manual_mode)
			) {$charset_collate};"
		);
		return;
	}

	dbDelta(
		"CREATE TABLE {$table} (
			id bigint unsigned NOT NULL AUTO_INCREMENT,
			wc_country_code varchar(2) NOT NULL,
			wc_country_name varchar(190) NOT NULL DEFAULT '',
			rp_country_id varchar(50) NOT NULL DEFAULT '',
			rp_country_name varchar(190) NOT NULL DEFAULT '',
			rp_iso2 varchar(2) NOT NULL DEFAULT '',
			has_parcel tinyint(1) NOT NULL DEFAULT 0,
			parcel_block tinyint(1) NOT NULL DEFAULT 0,
			api_available tinyint(1) NOT NULL DEFAULT 0,
			matched tinyint(1) NOT NULL DEFAULT 0,
			manual_mode varchar(20) NOT NULL DEFAULT 'auto',
			effective_enabled tinyint(1) NOT NULL DEFAULT 0,
			last_checked_at datetime NULL,
			manual_comment varchar(255) NOT NULL DEFAULT '',
			raw_json longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY wc_country_code (wc_country_code),
			KEY rp_country_id (rp_country_id),
			KEY rp_iso2 (rp_iso2),
			KEY matched (matched),
			KEY effective_enabled (effective_enabled),
			KEY manual_mode (manual_mode)
		) {$charset_collate};"
	);
};
