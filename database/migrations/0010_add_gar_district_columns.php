<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$stage_table     = $wpdb->prefix . 'wdc_gar_places_stage';
	$locations_table = $wpdb->prefix . 'wdc_locations';

	$add_column = static function ( string $table, string $column, string $definition ) use ( $wpdb ): void {
		$exists = $wpdb->get_results( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ), ARRAY_A );
		if ( is_array( $exists ) && array() !== $exists ) {
			return;
		}

		$result = $wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
		if ( false === $result ) {
			$error = trim( (string) ( $wpdb->last_error ?? '' ) );
			throw new RuntimeException( trim( "Unable to add {$column} to {$table}. {$error}" ) );
		}
	};

	$add_index = static function ( string $table, string $index, string $columns ) use ( $wpdb ): void {
		$exists = $wpdb->get_results( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ), ARRAY_A );
		if ( is_array( $exists ) && array() !== $exists ) {
			return;
		}

		$result = $wpdb->query( "ALTER TABLE {$table} ADD KEY {$index} ({$columns})" );
		if ( false === $result ) {
			$error = trim( (string) ( $wpdb->last_error ?? '' ) );
			throw new RuntimeException( trim( "Unable to add {$index} index to {$table}. {$error}" ) );
		}
	};

	$add_column( $stage_table, 'district_name', 'varchar(160) NULL' );
	$add_column( $stage_table, 'district_type', 'varchar(30) NULL' );
	$add_column( $stage_table, 'district_fias_id', 'char(36) NULL' );
	$add_column( $stage_table, 'district_kladr_id', 'varchar(19) NULL' );
	$add_column( $stage_table, 'district_gar_object_id', 'bigint(20) unsigned NULL' );
	$add_column( $stage_table, 'district_level', 'smallint NULL' );
	$add_index( $stage_table, 'district_fias_id', 'district_fias_id' );
	$add_index( $stage_table, 'district_gar_object_id', 'district_gar_object_id' );

	$add_column( $locations_table, 'district_name', "varchar(160) NOT NULL DEFAULT ''" );
	$add_column( $locations_table, 'district_type', "varchar(30) NOT NULL DEFAULT ''" );
	$add_column( $locations_table, 'district_fias_id', 'char(36) NULL' );
	$add_column( $locations_table, 'district_kladr_id', 'varchar(19) NULL' );
	$add_column( $locations_table, 'district_gar_object_id', 'bigint(20) unsigned NULL' );
	$add_column( $locations_table, 'district_level', 'smallint unsigned NULL' );
	$add_index( $locations_table, 'ix_district_fias_id', 'district_fias_id' );
	$add_index( $locations_table, 'ix_district_gar_object_id', 'district_gar_object_id' );
	$add_index( $locations_table, 'ix_region_district_place', 'region_code, district_name, place_name' );
};
