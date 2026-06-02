<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_locations';
	$schema_value = static function ( mixed $value, string $key ): string {
		if ( is_object( $value ) && isset( $value->{$key} ) ) {
			return (string) $value->{$key};
		}
		if ( is_array( $value ) && isset( $value[ $key ] ) ) {
			return (string) $value[ $key ];
		}

		return (string) $value;
	};

	$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( (string) $table_exists !== $table ) {
		return;
	}

	$active = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'active' ) );
	$country_code = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'country_code' ) );
	if ( 'active' !== $schema_value( $active, 'Field' ) || 'country_code' !== $schema_value( $country_code, 'Field' ) ) {
		return;
	}

	$index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'idx_active_country_code' ) );
	if ( 'idx_active_country_code' === $schema_value( $index, 'Key_name' ) ) {
		return;
	}

	$result = $wpdb->query( "ALTER TABLE {$table} ADD KEY idx_active_country_code (active, country_code)" );
	if ( false === $result ) {
		$error = trim( (string) ( $wpdb->last_error ?? '' ) );
		throw new RuntimeException( trim( "Unable to add idx_active_country_code to {$table}. {$error}" ) );
	}
};
