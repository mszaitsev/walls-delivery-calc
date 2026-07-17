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
	shipment_admin_ajax_assert( str_contains( $service, $action ), 'AJAX service must preserve action contract: ' . $action );
}

shipment_admin_ajax_assert( ! preg_match( '/function\s+ajax_[a-z_]+\s*\(/', $metabox ), 'OrderShipmentsMetabox must not contain AJAX endpoint methods.' );
shipment_admin_ajax_assert( str_contains( $metabox, '$this->ajax_create_controller' ) && str_contains( $metabox, '$this->ajax_status_controller' ) && str_contains( $metabox, '$this->ajax_address_controller' ), 'OrderShipmentsMetabox must delegate AJAX hooks to controller dependencies.' );
shipment_admin_ajax_assert( str_contains( $service, 'function ajax_create(' ) && str_contains( $service, 'function ajax_preview(' ) && str_contains( $service, 'function ajax_update_status(' ) && str_contains( $service, 'function ajax_search_pickup_points(' ), 'ShipmentAdminAjaxService must own moved AJAX orchestration methods.' );
shipment_admin_ajax_assert( str_contains( $service, 'current_user_can( AdminMenu::CAPABILITY )' ) && str_contains( $service, "check_ajax_referer( self::NONCE_ACTION, 'nonce', false )" ), 'AJAX service must keep capability and nonce checks.' );
shipment_admin_ajax_assert( ! str_contains( $metabox, 'CarrierShipmentLifecycleContinuationInterface' ), 'Metabox must not own lifecycle continuation business contract.' );

echo "Shipment admin AJAX smoke passed.\n";
