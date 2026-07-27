<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table = $wpdb->prefix . 'wdc_locations';
	$exists = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	if ( $exists !== $table ) {
		return;
	}

	$safe_table = '`' . str_replace( '`', '``', $table ) . '`';
	$run = static function ( string $sql, string $message ) use ( $wpdb ): void {
		$result = $wpdb->query( $sql );
		if ( false === $result ) {
			$error = trim( (string) ( $wpdb->last_error ?? '' ) );
			$error = preg_replace( '/[\r\n\t]+/', ' ', $error ) ?? $error;
			throw new RuntimeException( trim( $message . ': ' . ( '' !== $error ? $error : 'unknown SQL error' ) ) );
		}
	};

	$run( "ALTER TABLE {$safe_table} MODIFY gar_object_id BIGINT(20) UNSIGNED NULL", 'Unable to make wdc_locations.gar_object_id nullable' );
	$run( "ALTER TABLE {$safe_table} MODIFY fias_id CHAR(36) NULL", 'Unable to make wdc_locations.fias_id nullable' );
	$run( "UPDATE {$safe_table} SET gar_object_id = NULL WHERE gar_object_id = 0", 'Unable to normalize empty wdc_locations.gar_object_id placeholders' );
	$run( "UPDATE {$safe_table} SET fias_id = NULL WHERE fias_id IS NOT NULL AND TRIM(fias_id) = ''", 'Unable to normalize empty wdc_locations.fias_id placeholders' );
	$run( "UPDATE {$safe_table} SET gar_id = '' WHERE gar_id = '0' AND gar_object_id IS NULL", 'Unable to normalize empty wdc_locations.gar_id placeholders' );
};
