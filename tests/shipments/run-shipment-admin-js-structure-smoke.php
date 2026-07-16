<?php
declare(strict_types=1);

function shipment_admin_js_structure_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		fwrite( STDERR, $message . "\n" );
		exit( 1 );
	}
}

$root = dirname( __DIR__, 2 );
$files = array(
	'bootstrap'    => $root . '/assets/admin/shipments-admin.js',
	'core'         => $root . '/assets/admin/shipments/shipment-core.js',
	'preview'      => $root . '/assets/admin/shipments/shipment-preview.js',
	'status'       => $root . '/assets/admin/shipments/shipment-status.js',
	'polling'      => $root . '/assets/admin/shipments/shipment-polling.js',
	'allocation'   => $root . '/assets/admin/shipments/shipment-allocation.js',
	'picker'       => $root . '/assets/admin/shipments/shipment-picker.js',
	'cdek'         => $root . '/assets/admin/shipments/extensions/cdek.js',
	'dpd'          => $root . '/assets/admin/shipments/extensions/dpd.js',
	'russian_post' => $root . '/assets/admin/shipments/extensions/russian-post.js',
	'yandex'       => $root . '/assets/admin/shipments/extensions/yandex.js',
	'events'       => $root . '/assets/admin/shipments/shipment-events.js',
);

$source = array();
foreach ( $files as $key => $file ) {
	shipment_admin_js_structure_assert( is_file( $file ), 'Missing shipment admin JS module: ' . $key );
	$source[ $key ] = (string) file_get_contents( $file );
}

shipment_admin_js_structure_assert( str_contains( $source['bootstrap'], 'initializeShipmentAdmin()' ) && ! str_contains( $source['bootstrap'], 'data-wdc-' ) && ! str_contains( $source['bootstrap'], 'function renderShipmentStatus' ) && ! str_contains( $source['bootstrap'], 'function requestPreview' ), 'Bootstrap must only initialize the modular shipment admin runtime.' );
shipment_admin_js_structure_assert( ! preg_match( '/\\b(cdek|dpd|russian|yandex)\\b/i', $source['core'] ), 'Core module must not contain carrier-specific logic.' );
shipment_admin_js_structure_assert( str_contains( $source['preview'], 'function requestPreview' ) && str_contains( $source['preview'], 'function updateCreateAvailability' ), 'Preview module must own preview requests and create availability refresh.' );
shipment_admin_js_structure_assert( str_contains( $source['status'], 'function renderShipmentStatus' ) && str_contains( $source['status'], 'function updateShipmentButtons' ) && ! str_contains( $source['status'], 'fetch(' ), 'Status module must render status/buttons without AJAX fetch calls.' );
shipment_admin_js_structure_assert( str_contains( $source['polling'], 'function requestShipmentStatus' ) && str_contains( $source['polling'], 'function startShipmentRegistrationPolling' ) && str_contains( $source['polling'], 'function requestShipmentCancel' ), 'Polling module must own status polling, registration polling and cancellation requests.' );
shipment_admin_js_structure_assert( str_contains( $source['allocation'], 'function splitShipmentItemRow' ) && str_contains( $source['allocation'], 'function addManualShipmentItemRow' ) && str_contains( $source['allocation'], 'function updateShipmentPlaceOptions' ), 'Allocation module must own places/items/split/manual rows.' );
shipment_admin_js_structure_assert( str_contains( $source['picker'], 'function createPickupPicker' ) && str_contains( $source['picker'], 'window.WDCPickupApi.addressSearch' ), 'Picker module must own pickup picker and shared address search.' );
shipment_admin_js_structure_assert( str_contains( $source['cdek'], 'requestCdekBarcodeDownload' ) && str_contains( $source['cdek'], 'updateCdekDeliveryModeUi' ), 'CDEK extension must own CDEK UI hooks and barcode download.' );
shipment_admin_js_structure_assert( str_contains( $source['dpd'], 'requestDpdDocumentsDownload' ) && str_contains( $source['dpd'], 'syncDpdAddressFields' ) && str_contains( $source['dpd'], 'submitDpdRegistration' ), 'DPD extension must own DPD UI, documents and two-stage registration submit hook.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], 'requestYandexLabelDownload' ) && str_contains( $source['yandex'], 'yandexSourceDropoffContext' ), 'Yandex extension must own Yandex label and source drop-off hooks.' );
shipment_admin_js_structure_assert( str_contains( $source['russian_post'], 'Russian Post' ), 'Russian Post extension module must exist even when current behavior is shared.' );
shipment_admin_js_structure_assert( str_contains( $source['events'], 'function initializeShipmentAdmin' ) && str_contains( $source['events'], 'document.addEventListener' ), 'Events module must own DOM event wiring for the modular runtime.' );

$metabox_source = (string) file_get_contents( $root . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
foreach ( array( 'wdc-shipments-admin-core', 'wdc-shipments-admin-preview', 'wdc-shipments-admin-status', 'wdc-shipments-admin-polling', 'wdc-shipments-admin-picker', 'wdc-shipments-admin-yandex', 'wdc-shipments-admin-events' ) as $handle ) {
	shipment_admin_js_structure_assert( str_contains( $metabox_source, $handle ), 'Metabox enqueue must register script handle: ' . $handle );
}

echo "Shipment admin JS structure smoke passed.\n";
