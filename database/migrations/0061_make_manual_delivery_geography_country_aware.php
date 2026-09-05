<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$regions = $wpdb->prefix . 'wdc_manual_delivery_regions';
	$locations = $wpdb->prefix . 'wdc_manual_delivery_locations';

	$column_exists = static function ( string $table, string $column ) use ( $wpdb ): bool {
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
		return null !== $row;
	};
	$index_exists = static function ( string $table, string $index ) use ( $wpdb ): bool {
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ) );
		return null !== $row;
	};
	$throw_on_error = static function ( string $message ) use ( $wpdb ): void {
		$error = trim( (string) ( $wpdb->last_error ?? '' ) );
		if ( '' !== $error ) {
			throw new RuntimeException( $message . ' ' . $error );
		}
	};

	if ( ! $column_exists( $regions, 'country_code' ) ) {
		$wpdb->query( "ALTER TABLE {$regions} ADD COLUMN country_code varchar(2) NOT NULL DEFAULT 'RU' AFTER service_id" );
		$throw_on_error( 'Unable to add country_code to manual delivery regions.' );
	}
	if ( ! $column_exists( $locations, 'country_code' ) ) {
		$wpdb->query( "ALTER TABLE {$locations} ADD COLUMN country_code varchar(2) NOT NULL DEFAULT 'RU' AFTER service_id" );
		$throw_on_error( 'Unable to add country_code to manual delivery locations.' );
	}

	$wpdb->query( "UPDATE {$regions} SET country_code = 'RU' WHERE country_code IS NULL OR country_code = ''" );
	$throw_on_error( 'Unable to backfill manual delivery region country_code.' );
	$wpdb->query( "UPDATE {$locations} SET country_code = 'RU' WHERE country_code IS NULL OR country_code = ''" );
	$throw_on_error( 'Unable to backfill manual delivery location country_code.' );

	if ( $index_exists( $regions, 'ux_manual_region' ) ) {
		$wpdb->query( "ALTER TABLE {$regions} DROP INDEX ux_manual_region" );
		$throw_on_error( 'Unable to drop legacy manual delivery region unique index.' );
	}
	if ( ! $index_exists( $regions, 'ux_manual_region_country' ) ) {
		$wpdb->query( "ALTER TABLE {$regions} ADD UNIQUE KEY ux_manual_region_country (service_id, country_code, region_name)" );
		$throw_on_error( 'Unable to add country-aware manual delivery region unique index.' );
	}
	if ( ! $index_exists( $regions, 'country_region' ) ) {
		$wpdb->query( "ALTER TABLE {$regions} ADD KEY country_region (country_code, region_name)" );
		$throw_on_error( 'Unable to add manual delivery region country index.' );
	}

	if ( $index_exists( $locations, 'ux_manual_location' ) ) {
		$wpdb->query( "ALTER TABLE {$locations} DROP INDEX ux_manual_location" );
		$throw_on_error( 'Unable to drop legacy manual delivery location unique index.' );
	}
	if ( ! $index_exists( $locations, 'ux_manual_location_country' ) ) {
		$wpdb->query( "ALTER TABLE {$locations} ADD UNIQUE KEY ux_manual_location_country (service_id, country_code, location_name, region_name)" );
		$throw_on_error( 'Unable to add country-aware manual delivery location unique index.' );
	}
	if ( ! $index_exists( $locations, 'country_location' ) ) {
		$wpdb->query( "ALTER TABLE {$locations} ADD KEY country_location (country_code, location_name, region_name)" );
		$throw_on_error( 'Unable to add manual delivery location country index.' );
	}

	if ( ! $column_exists( $regions, 'country_code' ) || ! $column_exists( $locations, 'country_code' ) || ! $index_exists( $regions, 'ux_manual_region_country' ) || ! $index_exists( $locations, 'ux_manual_location_country' ) ) {
		throw new RuntimeException( 'Manual delivery country-aware geography migration postcondition failed.' );
	}
};
