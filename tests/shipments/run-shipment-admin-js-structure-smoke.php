<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-js-bundle-source.php';

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
shipment_admin_js_structure_assert( ! preg_match( '/\\b(cdek|dpd|russian|yandex)\\b/i', $source['polling'] ), 'Polling module must remain carrier-neutral.' );
shipment_admin_js_structure_assert( ! preg_match( '/\\b(cdek|dpd|russian|yandex)\\b/i', $source['status'] ), 'Status module must remain carrier-neutral.' );
shipment_admin_js_structure_assert( str_contains( $source['allocation'], 'function splitShipmentItemRow' ) && str_contains( $source['allocation'], 'function addManualShipmentItemRow' ) && str_contains( $source['allocation'], 'function updateShipmentPlaceOptions' ), 'Allocation module must own places/items/split/manual rows.' );
shipment_admin_js_structure_assert( str_contains( $source['picker'], 'function createPickupPicker' ) && str_contains( $source['picker'], 'window.WDCPickupApi.addressSearch' ), 'Picker module must own pickup picker and shared address search.' );
shipment_admin_js_structure_assert( str_contains( $source['cdek'], 'requestCdekBarcodeDownload' ) && str_contains( $source['cdek'], 'updateCdekDeliveryModeUi' ), 'CDEK extension must own CDEK UI hooks and barcode download.' );
shipment_admin_js_structure_assert( str_contains( $source['dpd'], 'requestDpdDocumentsDownload' ) && str_contains( $source['dpd'], 'syncDpdAddressFields' ) && str_contains( $source['dpd'], 'submitDpdRegistration' ), 'DPD extension must own DPD UI, documents and two-stage registration submit hook.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], 'requestYandexLabelDownload' ) && str_contains( $source['yandex'], 'yandexSourceDropoffContext' ), 'Yandex extension must own Yandex label and source drop-off hooks.' );
shipment_admin_js_structure_assert( str_contains( $source['russian_post'], 'Russian Post' ), 'Russian Post extension module must exist even when current behavior is shared.' );
shipment_admin_js_structure_assert( str_contains( $source['events'], 'function initializeShipmentAdmin' ) && str_contains( $source['events'], 'document.addEventListener' ), 'Events module must own DOM event wiring for the modular runtime.' );
shipment_admin_js_structure_assert( ! preg_match( '/\\b(cdek|dpd|russian|yandex)\\b/i', $source['events'] ), 'Events module must remain carrier-neutral.' );
shipment_admin_js_structure_assert( str_contains( $source['core'], 'function dispatchShipmentCarrierHook' ) && str_contains( $source['core'], 'registerShipmentCarrierHooks' ), 'Core module must expose the small carrier hook registry.' );

shipment_admin_js_structure_assert( str_contains( $source['dpd'], 'data-wdc-dpd-contact-choice' ) && str_contains( $source['dpd'], 'data-wdc-dpd-contact-remove' ) && str_contains( $source['dpd'], 'data-wdc-dpd-documents-download' ) && str_contains( $source['dpd'], 'data-wdc-dpd-date-pickup' ), 'DPD extension must own DPD DOM selectors.' );
shipment_admin_js_structure_assert( str_contains( $source['cdek'], 'data-wdc-cdek-barcode-download' ), 'CDEK extension must own CDEK barcode selector.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], 'data-wdc-yandex-label-download' ) && str_contains( $source['yandex'], 'data-wdc-open-yandex-source-dropoff-picker' ) && str_contains( $source['yandex'], 'data-wdc-reset-yandex-source-dropoff' ), 'Yandex extension must own Yandex document/source selectors.' );

shipment_admin_js_structure_assert( str_contains( $source['events'], 'afterAddressNormalized' ) && ! str_contains( $source['events'], 'syncDpdAddressFields' ) && ! str_contains( $source['events'], 'syncYandexAddressFields' ) && ! str_contains( $source['events'], 'data-wdc-cdek-city-code' ), 'Events module must dispatch address normalization hooks without carrier post-processing.' );
shipment_admin_js_structure_assert( str_contains( $source['dpd'], 'syncDpdAddressFields' ) && str_contains( $source['dpd'], 'afterAddressNormalized' ), 'DPD extension must own DPD address normalization hook.' );
shipment_admin_js_structure_assert( str_contains( $source['cdek'], 'data-wdc-cdek-city-code' ) && str_contains( $source['cdek'], 'afterAddressNormalized' ), 'CDEK extension must own CDEK city-code address hook.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], 'syncYandexAddressFields' ) && str_contains( $source['yandex'], 'afterAddressNormalized' ), 'Yandex extension must own Yandex address normalization hook.' );

shipment_admin_js_structure_assert( ! str_contains( $source['polling'], 'submitDpdRegistration' ) && ! str_contains( $source['polling'], 'startDpdRegistrationPolling' ), 'Polling module must not own DPD lifecycle wrappers.' );
shipment_admin_js_structure_assert( str_contains( $source['dpd'], 'submitDpdRegistration' ) && str_contains( $source['dpd'], 'startDpdRegistrationPolling' ) && str_contains( $source['dpd'], 'dpd_places_summary' ) && str_contains( $source['dpd'], 'renderStatus' ), 'DPD extension must own DPD lifecycle and places status presentation.' );
shipment_admin_js_structure_assert( str_contains( $source['yandex'], 'cancellationPollingProgressMessage' ) && str_contains( $source['yandex'], 'cancellationPollingExhaustedMessage' ) && str_contains( $source['yandex'], 'yandex_self_pickup_node_code' ) && str_contains( $source['yandex'], 'renderStatus' ) && str_contains( $source['yandex'], 'handlePollingStatus' ) && str_contains( $source['yandex'], 'handlePollingExhausted' ), 'Yandex extension must own cancellation polling and self-pickup status presentation.' );
shipment_admin_js_structure_assert( str_contains( $source['cdek'], 'startCdekPolling' ) && str_contains( $source['cdek'], 'handleDefaultRegistrationPolling' ), 'CDEK extension must own the CDEK default registration polling wrapper.' );
shipment_admin_js_structure_assert( str_contains( $source['status'], 'label_actions' ) && str_contains( $source['status'], 'data-wdc-shipment-document-download' ) && ! str_contains( $source['status'], 'can_download_dpd_documents' ) && ! str_contains( $source['status'], 'can_download_yandex_label' ) && ! str_contains( $source['status'], 'data-wdc-cdek-barcode-download' ) && ! str_contains( $source['status'], 'data-wdc-dpd-documents-download' ) && ! str_contains( $source['status'], 'data-wdc-yandex-label-download' ), 'Status module must drive document visibility through normalized label_actions and generic document selectors.' );

$bundle_source = wdc_shipment_admin_js_bundle_source();
preg_match_all( '/\\bfunction\\s+([A-Za-z_$][A-Za-z0-9_$]*)\\s*\\(/', $bundle_source, $function_matches );
$function_counts = array_count_values( $function_matches[1] ?? array() );
$duplicates = array_filter(
	$function_counts,
	static fn ( int $count ): bool => $count > 1
);
shipment_admin_js_structure_assert( array() === $duplicates, 'Admin JS bundle must not contain duplicate function declarations: ' . implode( ', ', array_keys( $duplicates ) ) );
shipment_admin_js_structure_assert( 1 === ( $function_counts['submitDpdRegistration'] ?? 0 ), 'submitDpdRegistration must be declared exactly once in the production admin JS bundle.' );
shipment_admin_js_structure_assert( 1 === ( $function_counts['startDpdRegistrationPolling'] ?? 0 ), 'startDpdRegistrationPolling must be declared exactly once in the production admin JS bundle.' );

$metabox_source = (string) file_get_contents( $root . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
foreach ( array( 'wdc-shipments-admin-core', 'wdc-shipments-admin-preview', 'wdc-shipments-admin-status', 'wdc-shipments-admin-polling', 'wdc-shipments-admin-picker', 'wdc-shipments-admin-yandex', 'wdc-shipments-admin-events' ) as $handle ) {
	shipment_admin_js_structure_assert( str_contains( $metabox_source, $handle ), 'Metabox enqueue must register script handle: ' . $handle );
}

echo "Shipment admin JS structure smoke passed.\n";
