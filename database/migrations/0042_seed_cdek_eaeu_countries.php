<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function ( \wpdb $wpdb ): void {
	$services_table = $wpdb->prefix . 'wdc_delivery_services';
	$countries_table = $wpdb->prefix . 'wdc_delivery_service_countries';
	$service_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$services_table} WHERE service_key = %s AND deleted = 0 ORDER BY id ASC LIMIT 1", 'cdek' ) );
	if ( $service_id <= 0 ) {
		return;
	}

	$current = $wpdb->get_col( $wpdb->prepare( "SELECT country_code FROM {$countries_table} WHERE service_id = %d ORDER BY country_code ASC", $service_id ) );
	$current = array_values( array_unique( array_map( static fn( mixed $country ): string => strtoupper( trim( (string) $country ) ), is_array( $current ) ? $current : array() ) ) );
	sort( $current );
	if ( array() !== $current && array( 'RU' ) !== $current ) {
		return;
	}

	$wpdb->delete( $countries_table, array( 'service_id' => $service_id ), array( '%d' ) );
	$now = current_time( 'mysql' );
	foreach ( array( 'RU', 'AM', 'BY', 'KZ', 'KG' ) as $country ) {
		$wpdb->insert(
			$countries_table,
			array(
				'service_id' => $service_id,
				'country_code' => $country,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s' )
		);
	}
};
