<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	if ( ! function_exists( 'dbDelta' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}

	if ( ! function_exists( 'dbDelta' ) ) {
		throw new \RuntimeException( 'WordPress dbDelta function is unavailable.' );
	}

	$table = $wpdb->prefix . 'wdc_jet_logistic_cities';
	$charset = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : '';
	\dbDelta( "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		source_identity varchar(64) NOT NULL,
		import_token varchar(64) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		UNIQUE KEY source_identity (source_identity),
		KEY import_token (import_token)
	) {$charset};" );
};
