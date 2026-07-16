<?php
declare(strict_types=1);

if ( ! function_exists( 'wdc_shipment_admin_js_bundle_source' ) ) {
	function wdc_shipment_admin_js_bundle_source(): string {
		$root = dirname( __DIR__, 2 );
		$files = array(
			$root . '/assets/admin/shipments/shipment-core.js',
			$root . '/assets/admin/shipments/shipment-allocation.js',
			$root . '/assets/admin/shipments/shipment-preview.js',
			$root . '/assets/admin/shipments/shipment-status.js',
			$root . '/assets/admin/shipments/shipment-polling.js',
			$root . '/assets/admin/shipments/shipment-picker.js',
			$root . '/assets/admin/shipments/extensions/cdek.js',
			$root . '/assets/admin/shipments/extensions/dpd.js',
			$root . '/assets/admin/shipments/extensions/russian-post.js',
			$root . '/assets/admin/shipments/extensions/yandex.js',
			$root . '/assets/admin/shipments/shipment-events.js',
			$root . '/assets/admin/shipments-admin.js',
		);
		$source = array();
		foreach ( $files as $file ) {
			$source[] = (string) file_get_contents( $file );
		}
		return implode( "\n", $source );
	}
}
