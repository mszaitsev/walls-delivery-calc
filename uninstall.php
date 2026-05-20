<?php
/**
 * Cleanup legacy plugin data on uninstall.
 *
 * @package Walls_Delivery_Calc
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wdc_settings' );
delete_site_option( 'wdc_settings' );

global $wpdb;

if ( isset( $wpdb ) && $wpdb instanceof wpdb ) {
	$transient_prefixes = array(
		'_transient_wdc_',
		'_transient_timeout_wdc_',
		'_site_transient_wdc_',
		'_site_transient_timeout_wdc_',
	);

	foreach ( $transient_prefixes as $prefix ) {
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $prefix ) . '%'
			)
		);
	}

	if ( ! empty( $wpdb->sitemeta ) ) {
		foreach ( $transient_prefixes as $prefix ) {
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$wpdb->sitemeta} WHERE meta_key LIKE %s",
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
		}
	}
}
