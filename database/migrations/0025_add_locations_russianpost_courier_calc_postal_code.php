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

	$postal_code = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'postal_code' ) );
	if ( 'postal_code' !== $schema_value( $postal_code, 'Field' ) ) {
		return;
	}

	$column = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'russianpost_courier_calc_postal_code' ) );
	if ( 'russianpost_courier_calc_postal_code' !== $schema_value( $column, 'Field' ) ) {
		$result = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN russianpost_courier_calc_postal_code varchar(32) NOT NULL DEFAULT '' AFTER postal_code" );
		if ( false === $result ) {
			$error = trim( (string) ( $wpdb->last_error ?? '' ) );
			throw new RuntimeException( trim( "Unable to add russianpost_courier_calc_postal_code to {$table}. {$error}" ) );
		}
	}

	$index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'postal_code_rp_courier_calc' ) );
	if ( 'postal_code_rp_courier_calc' === $schema_value( $index, 'Key_name' ) ) {
		return;
	}

	$result = $wpdb->query( "ALTER TABLE {$table} ADD KEY postal_code_rp_courier_calc (postal_code, russianpost_courier_calc_postal_code)" );
	if ( false === $result ) {
		$error = trim( (string) ( $wpdb->last_error ?? '' ) );
		throw new RuntimeException( trim( "Unable to add postal_code_rp_courier_calc to {$table}. {$error}" ) );
	}
};
