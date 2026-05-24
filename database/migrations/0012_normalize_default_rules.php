<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table_name = $wpdb->prefix . 'wdc_rules';

	$table_exists = static function ( string $table ) use ( $wpdb ): bool {
		$result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		return ! in_array( $result, array( null, '', 0, '0' ), true );
	};

	if ( ! $table_exists( $table_name ) ) {
		return;
	}

	$wpdb->query( "UPDATE {$table_name} SET target_type = 'default', target_value = '' WHERE target_type IS NULL OR target_type = ''" );
};
