<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$table_name = $wpdb->prefix . 'wdc_rules';

	$wpdb->query( "UPDATE {$table_name} SET target_type = 'default', target_value = '' WHERE target_type IS NULL OR target_type = ''" );
};
