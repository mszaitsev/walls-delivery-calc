<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook_name, callable|array $callback ): void {
		$GLOBALS['wdc_shipment_admin_ajax_registered_hooks'][] = array( $hook_name, $callback );
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		unset( $domain );
		return $text;
	}
}

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
	'ShipmentActualCostAjaxController' => 'handle_save',
	'ShipmentDocumentsAjaxController' => 'handle_cdek_barcode_prepare',
	'ShipmentProductsAjaxController' => 'handle_search_products',
);

$controller_properties = array(
	'ShipmentCreateAjaxController' => 'ajax_create_controller',
	'ShipmentPreviewAjaxController' => 'ajax_preview_controller',
	'ShipmentStatusAjaxController' => 'ajax_status_controller',
	'ShipmentLifecycleAjaxController' => 'ajax_lifecycle_controller',
	'ShipmentRemovalAjaxController' => 'ajax_removal_controller',
	'ShipmentManualAttachAjaxController' => 'ajax_manual_attach_controller',
	'ShipmentAddressAjaxController' => 'ajax_address_controller',
	'ShipmentActualCostAjaxController' => 'ajax_actual_cost_controller',
	'ShipmentDocumentsAjaxController' => 'ajax_documents_controller',
	'ShipmentProductsAjaxController' => 'ajax_products_controller',
);

foreach ( $controllers as $class => $method ) {
	$path = $root . '/src/Shipments/Admin/Ajax/' . $class . '.php';
	$source = (string) file_get_contents( $path );
	shipment_admin_ajax_assert( is_file( $path ) && str_contains( $source, 'final class ' . $class ) && str_contains( $source, 'public function ' . $method ), 'AJAX controller must exist and expose expected handler: ' . $class );
	shipment_admin_ajax_assert( str_contains( $plugin, '\\WallsShop\\WDC\\Shipments\\Admin\\Ajax\\' . $class . '::class' ) || str_contains( $plugin, $class . '::class' ), 'Plugin DI must register AJAX controller: ' . $class );
	shipment_admin_ajax_assert( ! preg_match( '/public function ' . preg_quote( $method, '/' ) . '\s*\([^)]*\)\s*:\s*void\s*\{\s*\$this->ajax->ajax_[a-z_]+\(\);\s*\}/', $source ), 'AJAX controller must not be a proxy wrapper: ' . $class );
	shipment_admin_ajax_assert( str_contains( $source, 'wp_send_json_' ) || str_contains( $source, 'current_user_can' ), 'AJAX controller must own endpoint response/access logic: ' . $class );
	$property = $controller_properties[ $class ];
	shipment_admin_ajax_assert( str_contains( $metabox, 'private ' . $class . ' $' . $property ), 'Metabox AJAX controller dependency must be required and non-nullable: ' . $class );
	shipment_admin_ajax_assert( ! str_contains( $metabox, 'private ?' . $class . ' $' . $property ) && ! preg_match( '/private\s+\??' . preg_quote( $class, '/' ) . '\s+\$' . preg_quote( $property, '/' ) . '\s*=\s*null/', $metabox ), 'Metabox AJAX controller dependency must not allow null/default null: ' . $class );
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
	'wdc_save_shipment_actual_cost',
	'wdc_clear_shipment_actual_cost',
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

$ajax_dir = $root . '/src/Shipments/Admin/Ajax/';
require_once $root . '/src/Domain/Common/MoneyParser.php';
foreach ( array_keys( $controllers ) as $class ) {
	require_once $ajax_dir . $class . '.php';
}
require_once $root . '/src/Shipments/Admin/OrderShipmentsMetabox.php';

$metabox_class = new ReflectionClass( \WallsShop\WDC\Shipments\Admin\OrderShipmentsMetabox::class );
$metabox_instance = $metabox_class->newInstanceWithoutConstructor();
foreach ( $controller_properties as $class => $property_name ) {
	$controller_class = new ReflectionClass( '\\WallsShop\\WDC\\Shipments\\Admin\\Ajax\\' . $class );
	$controller_instance = $controller_class->newInstanceWithoutConstructor();
	$property = $metabox_class->getProperty( $property_name );
	$property->setAccessible( true );
	$property->setValue( $metabox_instance, $controller_instance );
}

$GLOBALS['wdc_shipment_admin_ajax_registered_hooks'] = array();
$metabox_instance->register();

$registered = array();
foreach ( $GLOBALS['wdc_shipment_admin_ajax_registered_hooks'] as $hook ) {
	$registered[ $hook[0] ] = $hook[1];
}

$expected_callbacks = array(
	'wp_ajax_wdc_create_shipment' => array( 'ShipmentCreateAjaxController', 'handle' ),
	'wp_ajax_wdc_continue_shipment_lifecycle' => array( 'ShipmentLifecycleAjaxController', 'handle' ),
	'wp_ajax_wdc_preview_shipment' => array( 'ShipmentPreviewAjaxController', 'handle' ),
	'wp_ajax_wdc_update_shipment_status' => array( 'ShipmentStatusAjaxController', 'handle_update' ),
	'wp_ajax_wdc_mark_shipment_poll_exhausted' => array( 'ShipmentStatusAjaxController', 'handle_mark_poll_exhausted' ),
	'wp_ajax_wdc_cancel_shipment' => array( 'ShipmentRemovalAjaxController', 'handle_cancel' ),
	'wp_ajax_wdc_remove_shipment_from_order' => array( 'ShipmentRemovalAjaxController', 'handle_remove' ),
	'wp_ajax_wdc_attach_shipment_tracking_number' => array( 'ShipmentManualAttachAjaxController', 'handle' ),
	'wp_ajax_wdc_normalize_shipment_address' => array( 'ShipmentAddressAjaxController', 'handle_normalize' ),
	'wp_ajax_wdc_search_russian_post_pickup_points' => array( 'ShipmentAddressAjaxController', 'handle_search_pickup_points' ),
	'wp_ajax_wdc_save_shipment_actual_cost' => array( 'ShipmentActualCostAjaxController', 'handle_save' ),
	'wp_ajax_wdc_clear_shipment_actual_cost' => array( 'ShipmentActualCostAjaxController', 'handle_clear' ),
	'wp_ajax_wdc_search_products_for_shipment_item' => array( 'ShipmentProductsAjaxController', 'handle_search_products' ),
	'wp_ajax_wdc_cdek_barcode_prepare' => array( 'ShipmentDocumentsAjaxController', 'handle_cdek_barcode_prepare' ),
	'wp_ajax_wdc_dpd_courier_contact_history' => array( 'ShipmentProductsAjaxController', 'handle_dpd_contact_history' ),
);

foreach ( $expected_callbacks as $hook_name => $expected ) {
	shipment_admin_ajax_assert( array_key_exists( $hook_name, $registered ), 'Metabox register() must register AJAX hook: ' . $hook_name );
	shipment_admin_ajax_assert( is_callable( $registered[ $hook_name ] ), 'Registered AJAX callback must be callable: ' . $hook_name );
	shipment_admin_ajax_assert( is_array( $registered[ $hook_name ] ) && is_object( $registered[ $hook_name ][0] ) && $registered[ $hook_name ][1] === $expected[1], 'Registered AJAX callback must point to expected controller method: ' . $hook_name );
	shipment_admin_ajax_assert( $registered[ $hook_name ][0] instanceof ( '\\WallsShop\\WDC\\Shipments\\Admin\\Ajax\\' . $expected[0] ), 'Registered AJAX callback must point to expected controller instance: ' . $hook_name );
}

$actual_cost_controller = ( new ReflectionClass( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentActualCostAjaxController::class ) )->newInstanceWithoutConstructor();
$parse_actual_cost = new ReflectionMethod( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentActualCostAjaxController::class, 'parse_amount_kopecks' );
$parse_actual_cost->setAccessible( true );
shipment_admin_ajax_assert( 123456 === $parse_actual_cost->invoke( $actual_cost_controller, '1234.56' ), 'Actual cost parser must convert decimal rubles to exact kopecks.' );
shipment_admin_ajax_assert( 123450 === $parse_actual_cost->invoke( $actual_cost_controller, '1234,5' ), 'Actual cost parser must accept comma decimals without float rounding.' );
foreach ( array( '0', '0.00', '', '-1', '1.234' ) as $invalid_actual_cost ) {
	$rejected = false;
	try {
		$parse_actual_cost->invoke( $actual_cost_controller, $invalid_actual_cost );
	} catch ( InvalidArgumentException ) {
		$rejected = true;
	}
	shipment_admin_ajax_assert( $rejected, 'Actual cost parser must reject invalid value: ' . $invalid_actual_cost );
}

echo "Shipment admin AJAX smoke passed.\n";
