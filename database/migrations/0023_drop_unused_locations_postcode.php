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
	$postcode = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'postcode' ) );
	if ( 'postal_code' !== $schema_value( $postal_code, 'Field' ) ) {
		$result = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN postal_code varchar(32) NOT NULL DEFAULT '' AFTER display_name" );
		if ( false === $result ) {
			$error = trim( (string) ( $wpdb->last_error ?? '' ) );
			throw new RuntimeException( trim( "Unable to add postal_code to {$table}. {$error}" ) );
		}
		$postal_code = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", 'postal_code' ) );
	}

	$postal_code_index = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", 'postal_code' ) );
	if ( 'postal_code' !== $schema_value( $postal_code_index, 'Key_name' ) ) {
		$result = $wpdb->query( "ALTER TABLE {$table} ADD KEY postal_code (postal_code)" );
		if ( false === $result ) {
			$error = trim( (string) ( $wpdb->last_error ?? '' ) );
			throw new RuntimeException( trim( "Unable to add postal_code index to {$table}. {$error}" ) );
		}
	}

	if ( 'postal_code' !== $schema_value( $postal_code, 'Field' ) || 'postcode' !== $schema_value( $postcode, 'Field' ) ) {
		return;
	}

	$result = $wpdb->query( "UPDATE {$table} SET postal_code = postcode WHERE (postal_code IS NULL OR postal_code = '') AND postcode IS NOT NULL AND postcode != ''" );
	if ( false === $result ) {
		$error = trim( (string) ( $wpdb->last_error ?? '' ) );
		throw new RuntimeException( trim( "Unable to copy postcode into postal_code for {$table}. {$error}" ) );
	}

	$result = $wpdb->query( "ALTER TABLE {$table} DROP COLUMN postcode" );
	if ( false === $result ) {
		$error = trim( (string) ( $wpdb->last_error ?? '' ) );
		throw new RuntimeException( trim( "Unable to drop unused postcode from {$table}. {$error}" ) );
	}
};
