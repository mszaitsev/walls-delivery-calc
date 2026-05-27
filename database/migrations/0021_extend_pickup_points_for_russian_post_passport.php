<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_pickup_points';
	$like  = $wpdb->esc_like( $table );
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );
	if ( (string) $found !== $table ) {
		return;
	}

	$columns = array(
		'source_hash' => 'CHAR(40) NULL',
		'last_seen_at' => 'DATETIME NULL',
		'brand_name' => 'VARCHAR(255) NULL',
		'description' => 'TEXT NULL',
		'street' => 'VARCHAR(255) NULL',
		'house' => 'VARCHAR(64) NULL',
		'fias_location_guid' => 'VARCHAR(64) NULL',
		'fias_address_guid' => 'VARCHAR(64) NULL',
		'gar_region_id' => 'VARCHAR(64) NULL',
		'geohash' => 'VARCHAR(16) NULL',
		'work_time_json' => 'LONGTEXT NULL',
		'ecom_options_json' => 'LONGTEXT NULL',
		'services_json' => 'LONGTEXT NULL',
		'phones_json' => 'LONGTEXT NULL',
		'images_json' => 'LONGTEXT NULL',
		'weight_limit_grams' => 'INT UNSIGNED NULL',
		'size_limit_json' => 'LONGTEXT NULL',
		'accepts_cash' => 'TINYINT(1) NULL',
		'accepts_card' => 'TINYINT(1) NULL',
		'partial_redemption' => 'TINYINT(1) NULL',
		'return_available' => 'TINYINT(1) NULL',
		'fitting_available' => 'TINYINT(1) NULL',
		'contents_checking' => 'TINYINT(1) NULL',
		'functionality_checking' => 'TINYINT(1) NULL',
	);

	foreach ( $columns as $column => $definition ) {
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM ' . $table . ' LIKE %s', $column ) );
		if ( null === $exists ) {
			$wpdb->query( "ALTER TABLE {$table} ADD {$column} {$definition}" );
		}
	}

	$indexes = array(
		'idx_carrier_type_active' => '(carrier_key, point_type, active)',
		'idx_city_active' => '(city_name, active)',
		'idx_postcode' => '(postcode)',
		'idx_lat_lng' => '(latitude, longitude)',
		'idx_geohash' => '(geohash)',
		'idx_carrier_source_hash' => '(carrier_key, source_hash)',
		'idx_carrier_point_code' => '(carrier_key, point_code)',
	);

	foreach ( $indexes as $name => $definition ) {
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW INDEX FROM ' . $table . ' WHERE Key_name = %s', $name ) );
		if ( null === $exists ) {
			$wpdb->query( "ALTER TABLE {$table} ADD KEY {$name} {$definition}" );
		}
	}
};
