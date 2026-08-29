<?php
declare(strict_types=1);
defined( 'ABSPATH' ) || exit;
return static function (): void {
	global $wpdb;
	if ( ! function_exists( 'dbDelta' ) ) { require_once ABSPATH . 'wp-admin/includes/upgrade.php'; }
	if ( ! function_exists( 'dbDelta' ) ) { throw new \RuntimeException( 'WordPress dbDelta function is unavailable.' ); }
	$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
	$generations = $wpdb->prefix . 'wdc_ozon_delivery_pickup_generations'; $points = $wpdb->prefix . 'wdc_ozon_delivery_pickup_points';
	\dbDelta( "CREATE TABLE {$generations} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,state varchar(16) NOT NULL,job_id char(64) NOT NULL,started_at datetime NULL,completed_at datetime NULL,cursor_value varchar(255) NULL,page_count int unsigned NOT NULL DEFAULT 0,downloaded_count int unsigned NOT NULL DEFAULT 0,accepted_count int unsigned NOT NULL DEFAULT 0,rejected_count int unsigned NOT NULL DEFAULT 0,duplicate_count int unsigned NOT NULL DEFAULT 0,conflict_count int unsigned NOT NULL DEFAULT 0,safe_error_code varchar(80) NULL,safe_error_message varchar(300) NULL,PRIMARY KEY(id),UNIQUE KEY job_id(job_id),KEY state(state),KEY completed_at(completed_at)) {$charset};" );
	\dbDelta( "CREATE TABLE {$points} (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,generation_id bigint(20) unsigned NOT NULL,point_id bigint(20) unsigned NOT NULL,name varchar(255) NOT NULL,point_number varchar(100) NULL,type varchar(32) NOT NULL,full_address text NOT NULL,latitude decimal(10,7) NULL,longitude decimal(10,7) NULL,schedule_json longtext NOT NULL,is_active tinyint(1) NOT NULL,is_bulky tinyint(1) NOT NULL,storage_period_days int NULL,fitting_rooms_count int NULL,min_weight_g int NULL,max_weight_g int NULL,max_width_mm int NULL,max_length_mm int NULL,max_height_mm int NULL,fingerprint char(64) NOT NULL,PRIMARY KEY(id),UNIQUE KEY generation_point(generation_id,point_id),KEY generation_id(generation_id),KEY active_lookup(generation_id,is_active,type)) {$charset};" );
};
