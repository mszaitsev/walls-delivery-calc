<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

function shipment_admin_ajax_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$root = dirname( __DIR__, 2 );
$metabox = (string) file_get_contents( $root . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$plugin = (string) file_get_contents( $root . '/src/Core/Plugin.php' );
$service = (string) file_get_contents( $root . '/src/Shipments/Admin/Ajax/ShipmentAdminAjaxService.php' );
$payload_builder = (string) file_get_contents( $root . '/src/Shipments/Admin/Ajax/ShipmentAdminCarrierUiPayloadBuilder.php' );

$controllers = array(
	'ShipmentCreateAjaxController' => 'handle',
	'ShipmentPreviewAjaxController' => 'handle',
	'ShipmentStatusAjaxController' => 'handle_update',
	'ShipmentLifecycleAjaxController' => 'handle',
	'ShipmentRemovalAjaxController' => 'handle_cancel',
	'ShipmentManualAttachAjaxController' => 'handle',
	'ShipmentAddressAjaxController' => 'handle_normalize',
	'ShipmentDocumentsAjaxController' => 'handle_cdek_barcode_prepare',
	'ShipmentProductsAjaxController' => 'handle_search_products',
);

foreach ( $controllers as $class => $method ) {
	$path = $root . '/src/Shipments/Admin/Ajax/' . $class . '.php';
	$source = (string) file_get_contents( $path );
	shipment_admin_ajax_assert( is_file( $path ) && str_contains( $source, 'final class ' . $class ) && str_contains( $source, 'public function ' . $method ), 'AJAX controller must exist and expose expected handler: ' . $class );
	shipment_admin_ajax_assert( str_contains( $plugin, '\\WallsShop\\WDC\\Shipments\\Admin\\Ajax\\' . $class . '::class' ), 'Plugin DI must register AJAX controller: ' . $class );
	shipment_admin_ajax_assert( ! preg_match( '/public function ' . preg_quote( $method, '/' ) . '\s*\([^)]*\)\s*:\s*void\s*\{\s*\$this->ajax->ajax_[a-z_]+\(\);\s*\}/', $source ), 'AJAX controller must not be a proxy wrapper: ' . $class );
	shipment_admin_ajax_assert( str_contains( $source, 'wp_send_json_' ) || str_contains( $source, 'current_user_can' ), 'AJAX controller must own endpoint response/access logic: ' . $class );
}

foreach ( array(
	'wdc_create_shipment',
	'wdc_continue_shipment_lifecycle',
	'wdc_preview_shipment',
	'wdc_update_shipment_status',
	'wdc_mark_shipment_poll_exhausted',
	'wdc_cancel_shipment',
	'wdc_remove_shipment_from_order',
	'wdc_attach_shipment_tracking_number',
	'wdc_normalize_shipment_address',
	'wdc_search_russian_post_pickup_points',
	'wdc_search_products_for_shipment_item',
	'wdc_cdek_barcode_prepare',
	'wdc_dpd_courier_contact_history',
) as $action ) {
	shipment_admin_ajax_assert( str_contains( $metabox, $action ), 'Metabox registration/localization must preserve AJAX action name: ' . $action );
}

shipment_admin_ajax_assert( ! preg_match( '/function\s+ajax_[a-z_]+\s*\(/', $metabox ), 'OrderShipmentsMetabox must not contain AJAX endpoint methods.' );
shipment_admin_ajax_assert( str_contains( $metabox, '$this->ajax_create_controller' ) && str_contains( $metabox, '$this->ajax_status_controller' ) && str_contains( $metabox, '$this->ajax_address_controller' ), 'OrderShipmentsMetabox must delegate AJAX hooks to controller dependencies.' );
shipment_admin_ajax_assert( ! preg_match( '/function\s+ajax_[a-z_]+\s*\(/', $service ), 'ShipmentAdminAjaxService must not contain AJAX endpoint methods.' );
shipment_admin_ajax_assert( ! str_contains( $service, 'function render(' ) && ! str_contains( $service, 'function enqueue_assets' ) && ! str_contains( $service, 'function add_meta_box' ), 'ShipmentAdminAjaxService must not contain render/enqueue/metabox methods.' );
shipment_admin_ajax_assert( ! str_contains( $service, 'ShipmentCreationService' ) && ! str_contains( $service, 'ShipmentStatusUpdateService' ) && ! str_contains( $service, 'CarrierShipmentAdapterRegistry' ) && ! str_contains( $service, 'AddressSuggestionService' ) && ! str_contains( $service, 'CdekBarcodePrintService' ), 'ShipmentAdminAjaxService must not depend on endpoint application/carrier services.' );
shipment_admin_ajax_assert( str_contains( $service, 'function assert_access(' ) && str_contains( $service, 'function resolve_order_from_request(' ) && str_contains( $service, 'function carrier_key_from_request(' ), 'ShipmentAdminAjaxService must remain a small shared AJAX helper.' );
shipment_admin_ajax_assert( str_contains( $payload_builder, 'function carrier_ui_payload(' ) && ! str_contains( $payload_builder, 'function ajax_' ), 'Carrier UI payload builder must own shared UI payload without endpoint methods.' );
shipment_admin_ajax_assert( ! str_contains( $metabox, 'CarrierShipmentLifecycleContinuationInterface' ), 'Metabox must not own lifecycle continuation business contract.' );

echo "Shipment admin AJAX smoke passed.\n";
