<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$regions = $wpdb->prefix . 'wdc_regions';
	$stage   = $wpdb->prefix . 'wdc_gar_places_stage';
	$places  = $wpdb->prefix . 'wdc_locations';
	$codes   = $wpdb->prefix . 'wdc_location_carrier_codes';

	dbDelta(
		"CREATE TABLE {$regions} (
			region_code char(2) NOT NULL,
			region_name varchar(120) NOT NULL,
			region_type varchar(30) NULL,
			region_fias_id char(36) NULL,
			region_kladr_id varchar(19) NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (region_code),
			KEY region_fias_id (region_fias_id),
			KEY region_kladr_id (region_kladr_id)
		) {$charset_collate};"
	);

	dbDelta(
		"CREATE TABLE {$stage} (
			region_code varchar(2) NULL,
			region_name varchar(120) NULL,
			region_type varchar(30) NULL,
			region_fias_id char(36) NULL,
			region_kladr_id varchar(19) NULL,
			district_name varchar(160) NULL,
			district_type varchar(30) NULL,
			district_fias_id char(36) NULL,
			district_kladr_id varchar(19) NULL,
			district_gar_object_id bigint(20) unsigned NULL,
			district_level smallint NULL,
			city_name varchar(120) NULL,
			city_type varchar(30) NULL,
			city_fias_id char(36) NULL,
			city_kladr_id varchar(19) NULL,
			place_name varchar(160) NULL,
			place_type varchar(30) NULL,
			place_level smallint NULL,
			display_name varchar(400) NULL,
			fias_id char(36) NULL,
			gar_object_id bigint(20) unsigned NULL,
			kladr_id varchar(19) NULL,
			okato varchar(20) NULL,
			oktmo varchar(20) NULL,
			postal_code varchar(10) NULL,
			KEY gar_object_id (gar_object_id),
			KEY fias_id (fias_id),
			KEY region_code (region_code),
			KEY kladr_id (kladr_id),
			KEY district_fias_id (district_fias_id),
			KEY district_gar_object_id (district_gar_object_id)
		) {$charset_collate};"
	);

	dbDelta(
		"CREATE TABLE {$places} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			gar_object_id bigint(20) unsigned NOT NULL,
			fias_id char(36) NOT NULL,
			kladr_id varchar(19) NULL,
			gar_id varchar(64) NOT NULL DEFAULT '',
			country_code varchar(8) NOT NULL DEFAULT 'RU',
			region_name varchar(255) NOT NULL DEFAULT '',
			region_code char(2) NOT NULL,
			region_type varchar(30) NOT NULL DEFAULT '',
			district_name varchar(160) NOT NULL DEFAULT '',
			district_type varchar(30) NOT NULL DEFAULT '',
			district_fias_id char(36) NULL,
			district_kladr_id varchar(19) NULL,
			district_gar_object_id bigint(20) unsigned NULL,
			district_level smallint unsigned NULL,
			city_name varchar(120) NULL,
			city_type varchar(30) NULL,
			city_fias_id char(36) NULL,
			city_kladr_id varchar(19) NULL,
			settlement_name varchar(255) NOT NULL DEFAULT '',
			settlement_type varchar(64) NOT NULL DEFAULT '',
			place_name varchar(160) NOT NULL,
			place_type varchar(30) NULL,
			place_level smallint unsigned NOT NULL,
			display_name varchar(400) NOT NULL,
			searchable_text longtext NOT NULL,
			okato varchar(20) NULL,
			oktmo varchar(20) NULL,
			postal_code varchar(10) NULL,
			latitude decimal(10,7) NULL,
			longitude decimal(10,7) NULL,
			active tinyint(1) NOT NULL DEFAULT 1,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ux_gar_object_id (gar_object_id),
			UNIQUE KEY ux_fias_id (fias_id),
			KEY ix_kladr_id (kladr_id),
			KEY ix_region_code (region_code),
			KEY ix_region_type (region_type),
			KEY ix_region_place (region_code, place_name),
			KEY ix_district_fias_id (district_fias_id),
			KEY ix_district_gar_object_id (district_gar_object_id),
			KEY ix_region_district_place (region_code, district_name, place_name),
			KEY ix_city_fias_id (city_fias_id),
			KEY ix_city_place (city_name, place_name),
			KEY ix_active (active),
			KEY country_code (country_code),
			KEY region_name (region_name),
			KEY city_name (city_name),
			KEY settlement_name (settlement_name)
		) {$charset_collate};"
	);

	dbDelta(
		"CREATE TABLE {$codes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			location_id bigint(20) unsigned NULL,
			gar_object_id bigint(20) unsigned NOT NULL,
			fias_id char(36) NULL,
			carrier_key varchar(64) NOT NULL,
			external_code varchar(128) NOT NULL,
			meta longtext NULL,
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY ux_carrier_gar_code (carrier_key, gar_object_id, external_code),
			KEY ix_carrier_key (carrier_key),
			KEY ix_gar_object_id (gar_object_id),
			KEY ix_fias_id (fias_id)
		) {$charset_collate};"
	);
};
