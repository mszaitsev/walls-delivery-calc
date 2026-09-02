<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

$root = dirname( __DIR__, 2 );
require_once $root . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', $root . '/src' ) )->register();
require_once __DIR__ . '/actual-cost-test-helpers.php';

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
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string {
		unset( $domain );
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr__' ) ) {
	function esc_attr__( string $text, string $domain = '' ): string {
		unset( $domain );
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( mixed $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		unset( $capability );
		return true;
	}
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( string $action, string $query_arg = '', bool $stop = true ): bool {
		unset( $action, $query_arg, $stop );
		return true;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ?? '' );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string {
		return trim( strip_tags( (string) $value ) );
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string {
		unset( $type );
		return '2026-08-31 12:00:00';
	}
}
if ( ! function_exists( 'selected' ) ) {
	function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
		$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}
if ( ! function_exists( 'checked' ) ) {
	function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
		$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}
if ( ! function_exists( 'disabled' ) ) {
	function disabled( mixed $disabled, mixed $current = true, bool $display = true ): string {
		$result = (string) $disabled === (string) $current ? ' disabled="disabled"' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( mixed $data = null, ?int $status_code = null ): never {
		throw new ShipmentAdminAjaxSmokeAjaxResponse( true, $data, $status_code ?? 200 );
	}
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( mixed $data = null, ?int $status_code = null ): never {
		throw new ShipmentAdminAjaxSmokeAjaxResponse( false, $data, $status_code ?? 400 );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}
}
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( int $order_id ): ?ShipmentAdminAjaxSmokeOrder {
		return $GLOBALS['wdc_shipment_admin_ajax_orders'][ $order_id ] ?? null;
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( string $hook, mixed ...$args ): void {
		$GLOBALS['wdc_shipment_admin_ajax_actions'][] = array( 'hook' => $hook, 'args' => $args );
	}
}

function shipment_admin_ajax_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class ShipmentAdminAjaxSmokeAjaxResponse extends RuntimeException {
	public function __construct(
		public readonly bool $success,
		public readonly mixed $data,
		public readonly int $status_code
	) {
		parent::__construct( 'AJAX response captured.' );
	}
}

final class ShipmentAdminAjaxSmokeOrder {
	/** @param array<string,mixed> $meta */
	public function __construct(
		private int $id,
		private array $meta = array()
	) {
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_meta( string $key, bool $single = true ): mixed {
		unset( $single );
		return $this->meta[ $key ] ?? array();
	}

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function save(): void {
	}

	public function get_order_number(): string {
		return (string) $this->id;
	}

	public function get_items(): array {
		return array();
	}

	public function get_shipping_country(): string {
		return 'RU';
	}

	public function get_shipping_city(): string {
		return 'Новосибирск';
	}

	public function get_shipping_state(): string {
		return 'Новосибирская обл';
	}

	public function get_shipping_postcode(): string {
		return '630005';
	}

	public function get_shipping_address_1(): string {
		return 'Красный проспект, 1';
	}

	public function get_shipping_address_2(): string {
		return '';
	}

	public function get_shipping_first_name(): string {
		return 'Иван';
	}

	public function get_shipping_last_name(): string {
		return 'Иванов';
	}

	public function get_billing_phone(): string {
		return '+79990000000';
	}

	public function get_billing_email(): string {
		return 'manager@example.test';
	}
}

final class ShipmentAdminAjaxSmokeAdapter implements \WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface {
	public int $attach_calls = 0;
	public int $status_calls = 0;
	/** @var array<string,mixed> */
	public array $status_overrides = array();
	/** @var array<string,string> */
	public array $presentation_overrides = array();

	public function __construct(
		private string $carrier_key,
		private \WallsShop\WDC\Shipments\Storage\OrderShipmentRepository $repository
	) {
	}

	public function carrier_key(): string {
		return $this->carrier_key;
	}

	public function supports( \WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest $request ): bool {
		unset( $request );
		return true;
	}

	public function build_safe_payload_preview( \WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest $request ): array {
		unset( $request );
		return array();
	}

	public function create( \WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest $request ): \WallsShop\WDC\Domain\Shipment\ShipmentCreateResult {
		unset( $request );
		throw new RuntimeException( 'Manual attach smoke must not create shipments.' );
	}

	public function presentation(): array {
		return array_merge(
			array(
				'carrier_label' => 'Fake Carrier',
				'status_title' => 'Fake status',
				'tracking_label' => 'Tracking',
				'created_toast' => 'Created',
			),
			$this->presentation_overrides
		);
	}

	public function status_payload( object $order, array $shipment ): array {
		++$this->status_calls;
		unset( $order );
		return array_merge(
			array(
				'has_shipment' => '' !== trim( (string) ( $shipment['tracking_number'] ?? '' ) ),
				'can_create' => false,
				'can_attach_manual' => false,
				'can_update_status' => true,
				'can_cancel' => false,
				'can_remove_from_order' => true,
				'tracking_number' => (string) ( $shipment['tracking_number'] ?? '' ),
			),
			$this->status_overrides
		);
	}

	public function update_status( object $order, string $shipment_key = '' ): array {
		unset( $order, $shipment_key );
		return array();
	}

	public function attach_manual( object $order, array $payload ): array {
		++$this->attach_calls;
		$barcode = (string) ( $payload['barcode'] ?? '' );
		$this->repository->save_for_carrier(
			$order,
			$this->carrier_key,
			array(
				'carrier_key' => $this->carrier_key,
				'service_key' => $this->carrier_key . '_service',
				'service_title' => 'Fake service',
				'tracking_number' => $barcode,
				'barcode' => $barcode,
				'status' => 'created',
			)
		);

		return array(
			'success' => true,
			'message' => 'Attached',
			'tracking_number' => $barcode,
		);
	}

	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array {
		unset( $order, $shipment_key );
		return array();
	}

	public function remove_from_order( object $order, string $shipment_key = '' ): array {
		unset( $order, $shipment_key );
		return array();
	}

	public function supports_status_auto_sync(): bool {
		return false;
	}

	public function tracking_identifier( array $shipment ): string {
		return (string) ( $shipment['tracking_number'] ?? '' );
	}

	public function auto_sync_throttle_microseconds(): int {
		return 0;
	}
}

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

foreach ( glob( $root . '/src/Shipments/Admin/Ajax/*.php' ) ?: array() as $ajax_file ) {
	$source = (string) file_get_contents( $ajax_file );
	if ( str_contains( $source, '->discard_preview_buffer(' ) ) {
		shipment_admin_ajax_assert( str_contains( $source, 'function discard_preview_buffer(' ), 'AJAX controller using discard_preview_buffer must declare it locally: ' . basename( $ajax_file ) );
	}
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
shipment_admin_ajax_assert( str_contains( $metabox, 'data-wdc-shipment-price-row' ) && str_contains( $metabox, 'data-wdc-shipment-actual-cost-control' ) && str_contains( $metabox, 'data-wdc-actual-cost-input-wrap' ), 'Metabox must keep price row as the only price presentation and expose conditional actual-cost controls.' );
shipment_admin_ajax_assert( ! str_contains( $metabox, 'data-wdc-actual-cost-state' ) && ! str_contains( $metabox, 'data-wdc-actual-cost-source' ), 'Metabox must not render duplicated actual-cost state/source rows.' );
shipment_admin_ajax_assert( str_contains( $metabox, '$has_created && ! $has_actual_cost' ) && str_contains( $metabox, '$has_created && $has_actual_cost' ), 'Metabox initial render must show input/save only without actual cost and clear only with actual cost.' );
$shipment_status_js = (string) file_get_contents( $root . '/assets/admin/shipments/shipment-status.js' );
shipment_admin_ajax_assert( str_contains( $shipment_status_js, 'data-wdc-shipment-actual-cost-control' ) && str_contains( $shipment_status_js, 'has_actual_cost' ) && str_contains( $shipment_status_js, 'setVisible(clear, hasShipment && hasActualCost)' ), 'Shipment status JS must refresh actual-cost controls from has_shipment/has_actual_cost.' );
shipment_admin_ajax_assert( ! str_contains( $shipment_status_js, 'actual_cost_source_label' ) && ! str_contains( $shipment_status_js, 'data-wdc-actual-cost-source' ), 'Shipment status JS must not render actual-cost source/date in the metabox.' );

$ajax_dir = $root . '/src/Shipments/Admin/Ajax/';
require_once $root . '/src/Domain/Common/MoneyParser.php';
foreach ( array_keys( $controllers ) as $class ) {
	require_once $ajax_dir . $class . '.php';
}
require_once $root . '/src/Shipments/Admin/OrderShipmentsMetabox.php';

/**
 * @param class-string $controller_class
 * @param array<string,mixed> $meta
 * @param array<string,mixed> $data
 * @return array<string,string>
 */
function shipment_admin_ajax_ozon_context( string $controller_class, array $meta, array $data = array() ): array {
	$order = new ShipmentAdminAjaxSmokeOrder( random_int( 10000, 20000 ), $meta );
	$reflection = new ReflectionClass( $controller_class );
	$controller = $reflection->newInstanceWithoutConstructor();
	$method = $reflection->getMethod( 'ozon_address_context_from_request' );
	$method->setAccessible( true );
	if ( 2 === $method->getNumberOfParameters() ) {
		return $method->invoke( $controller, $order, $data );
	}
	$_POST = $data;
	return $method->invoke( $controller, $order );
}

$forged_ozon_context_data = array(
	'recipient_location_id' => '999',
	'recipient_location_fias_id' => 'FAKE-FIAS',
	'location_id' => '998',
	'fias_id' => 'FAKE-FIAS-2',
);
$server_order_meta = array(
	'_wdc_platform_location_id' => '123',
	'_wdc_platform_location_fias_id' => 'REAL-FIAS',
);
foreach (
	array(
		\WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAddressAjaxController::class,
		\WallsShop\WDC\Shipments\Admin\Ajax\ShipmentPreviewAjaxController::class,
		\WallsShop\WDC\Shipments\Admin\Ajax\ShipmentCreateAjaxController::class,
	) as $ozon_context_controller
) {
	$context = shipment_admin_ajax_ozon_context( $ozon_context_controller, $server_order_meta, $forged_ozon_context_data );
	shipment_admin_ajax_assert( '123' === (string) ( $context['selected_location_id'] ?? '' ) && 'REAL-FIAS' === (string) ( $context['selected_location_fias_id'] ?? '' ), 'Ozon courier AJAX address context must ignore forged browser location identity for ' . $ozon_context_controller );
	shipment_admin_ajax_assert( ! in_array( '999', $context, true ) && ! in_array( 'FAKE-FIAS', $context, true ), 'Ozon courier AJAX address context must not contain forged browser location/FIAS for ' . $ozon_context_controller );
}

$trusted_snapshot_meta = array(
	\WallsShop\WDC\Shipments\Application\OrderStructuredAddress::META_KEY => array(
		'source' => 'dadata_checkout',
		'address_role' => 'shipping',
		'selected_location_id' => '123',
		'selected_location_fias_id' => 'REAL-FIAS',
		'country' => 'Россия',
		'country_code' => 'RU',
		'region' => 'Новосибирская обл',
		'city' => 'г Новосибирск',
		'street' => 'ул Ленина',
		'house' => '10',
		'postcode' => '630005',
		'geo_lat' => '55.0415',
		'geo_lon' => '82.9346',
	),
	'_wdc_platform_location_id' => '777',
	'_wdc_platform_location_fias_id' => 'STALE-FIAS',
);
$trusted_context = shipment_admin_ajax_ozon_context( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentPreviewAjaxController::class, $trusted_snapshot_meta, $forged_ozon_context_data );
shipment_admin_ajax_assert( '123' === (string) ( $trusted_context['selected_location_id'] ?? '' ) && 'REAL-FIAS' === (string) ( $trusted_context['selected_location_fias_id'] ?? '' ), 'Trusted structured order snapshot must have priority over stale legacy order location meta for Ozon courier normalization.' );

$no_identity_context = shipment_admin_ajax_ozon_context( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentCreateAjaxController::class, array(), $forged_ozon_context_data );
shipment_admin_ajax_assert( ! isset( $no_identity_context['selected_location_id'] ) && ! isset( $no_identity_context['selected_location_fias_id'] ), 'Ozon courier AJAX address context must not use browser location/FIAS when no server-owned identity exists.' );

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

$repository = new \WallsShop\WDC\Shipments\Storage\OrderShipmentRepository();
$alpha_adapter = new ShipmentAdminAjaxSmokeAdapter( 'alpha', $repository );
$beta_adapter = new ShipmentAdminAjaxSmokeAdapter( 'beta', $repository );
$adapter_registry = new \WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry( array( $alpha_adapter, $beta_adapter ) );
$delivery_services = ( new ReflectionClass( \WallsShop\WDC\DeliveryServices\DeliveryServiceRepository::class ) )->newInstanceWithoutConstructor();
$status_updates = ( new ReflectionClass( \WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService::class ) )->newInstanceWithoutConstructor();
$actual_cost_resolver = shipment_test_actual_cost_resolver();
$payloads = new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminCarrierUiPayloadBuilder( $repository, $delivery_services, $status_updates, $actual_cost_resolver, null, null, $adapter_registry );
$manual_attach = new \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentManualAttachAjaxController( $payloads );

$parity_order = new ShipmentAdminAjaxSmokeOrder(
	601,
	array(
		\WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister::CALCULATION_META_KEY => array(
			'api' => array( 'api_base_price_kopecks' => 10000 ),
		),
	)
);
$parity_shipment = array(
	'carrier_key' => 'alpha',
	'service_key' => 'alpha_service',
	'tracking_number' => 'TEST-1',
	'actual_cost_kopecks' => 10000,
	'actual_cost_source' => 'carrier_api',
	'actual_cost_source_detail' => 'fake_status',
	'actual_cost_updated_at' => '2026-07-21 13:35:38',
);
$repository->save_for_carrier( $parity_order, 'alpha', $parity_shipment );
$initial_status = $actual_cost_resolver->enrich_status_payload( $alpha_adapter->status_payload( $parity_order, $parity_shipment ), $parity_shipment, $parity_order );
$alpha_adapter->status_calls = 0;
$ajax_status = $payloads->carrier_ui_payload( $parity_order, 'alpha' )['status'];
shipment_admin_ajax_assert( 1 === $alpha_adapter->status_calls, 'Carrier UI payload dispatch must call the adapter status payload exactly once for the adapter path.' );
foreach ( array( 'actual_cost_kopecks', 'has_actual_cost', 'actual_cost_label', 'actual_cost_source', 'actual_cost_source_detail', 'actual_cost_updated_at', 'base_api_cost_kopecks', 'actual_cost_compare_status', 'actual_cost_compare_message' ) as $key ) {
	shipment_admin_ajax_assert( $initial_status[ $key ] === $ajax_status[ $key ], 'Initial render and AJAX payload must agree for actual-cost key: ' . $key );
}
shipment_admin_ajax_assert( true === $ajax_status['has_actual_cost'] && 'carrier_api' === $ajax_status['actual_cost_source'] && 'fake_status' === $ajax_status['actual_cost_source_detail'], 'Minimal carrier adapter must receive shared actual-cost presentation from canonical shipment fields.' );

$override_shipment = array_merge( $parity_shipment, array( 'actual_cost_kopecks' => 12000, 'actual_cost_source' => 'manual', 'actual_cost_source_detail' => 'manual_save' ) );
$override_status = $payloads->carrier_ui_payload( $parity_order, 'alpha', $override_shipment )['status'];
shipment_admin_ajax_assert( 12000 === $override_status['actual_cost_kopecks'] && '120.00' === substr( (string) $override_status['actual_cost_label'], 0, 6 ) && 'warning' === $override_status['actual_cost_compare_status'], 'AJAX payload must use fresh shipment_override actual cost instead of stale repository cost.' );
$cleared_shipment = $parity_shipment;
unset( $cleared_shipment['actual_cost_source'], $cleared_shipment['actual_cost_source_detail'], $cleared_shipment['actual_cost_updated_at'] );
$cleared_shipment['actual_cost_kopecks'] = null;
$cleared_status = $payloads->carrier_ui_payload( $parity_order, 'alpha', $cleared_shipment )['status'];
shipment_admin_ajax_assert( false === $cleared_status['has_actual_cost'] && null === $cleared_status['actual_cost_kopecks'] && '' === $cleared_status['actual_cost_label'], 'AJAX payload must use fresh clear override instead of stale repository cost.' );

$alpha_adapter->status_overrides = array(
	'actual_cost_kopecks' => 12000,
	'actual_cost_compare_status' => 'ok',
	'actual_cost_compare_message' => 'wrong carrier comparison',
);
$adapter_override_status = $payloads->carrier_ui_payload( $parity_order, 'alpha', $parity_shipment )['status'];
shipment_admin_ajax_assert( 12000 === $adapter_override_status['actual_cost_kopecks'] && 'warning' === $adapter_override_status['actual_cost_compare_status'] && 'wrong carrier comparison' !== $adapter_override_status['actual_cost_compare_message'], 'Shared presenter must override carrier-supplied comparison fields.' );
$alpha_adapter->status_overrides = array();

$fallback_order = new ShipmentAdminAjaxSmokeOrder(
	602,
	array(
		\WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister::CALCULATION_META_KEY => array(
			'api' => array( 'api_base_price_kopecks' => 10000 ),
		),
	)
);
$fallback_repository_shipment = array(
	'carrier_key' => 'gamma',
	'service_key' => 'gamma_service',
	'tracking_number' => 'TEST-FALLBACK',
	'actual_cost_kopecks' => 10000,
	'actual_cost_source' => 'carrier_api',
	'actual_cost_source_detail' => 'repository_status',
	'actual_cost_updated_at' => '2026-07-21 13:00:00',
);
$repository->save_for_carrier( $fallback_order, 'gamma', $fallback_repository_shipment );
$fallback_save_override = array_merge(
	$fallback_repository_shipment,
	array(
		'actual_cost_kopecks' => 12000,
		'actual_cost_source' => 'manual',
		'actual_cost_source_detail' => 'override_save',
	)
);
$fallback_save_status = $payloads->carrier_ui_payload( $fallback_order, 'gamma', $fallback_save_override )['status'];
shipment_admin_ajax_assert( 12000 === $fallback_save_status['actual_cost_kopecks'] && true === $fallback_save_status['has_actual_cost'] && 'override_save' === $fallback_save_status['actual_cost_source_detail'], 'Fallback payload must use fresh save override without re-reading repository shipment.' );
$fallback_clear_override = $fallback_repository_shipment;
unset( $fallback_clear_override['actual_cost_source'], $fallback_clear_override['actual_cost_source_detail'], $fallback_clear_override['actual_cost_updated_at'] );
$fallback_clear_override['actual_cost_kopecks'] = null;
$fallback_clear_status = $payloads->carrier_ui_payload( $fallback_order, 'gamma', $fallback_clear_override )['status'];
shipment_admin_ajax_assert( null === $fallback_clear_status['actual_cost_kopecks'] && false === $fallback_clear_status['has_actual_cost'] && '' === $fallback_clear_status['actual_cost_label'], 'Fallback payload must use fresh clear override and must not restore repository actual cost.' );

/**
 * @param array<string,mixed> $shipment
 * @param array<string,mixed> $status_overrides
 */
function shipment_admin_ajax_render_metabox_html( string $carrier_key, array $shipment, array $status_overrides = array(), array $presentation_overrides = array() ): string {
	$repository = new \WallsShop\WDC\Shipments\Storage\OrderShipmentRepository();
	$order = new ShipmentAdminAjaxSmokeOrder(
		random_int( 700, 999 ),
		array(
			'_wdc_platform_carrier_key' => $carrier_key,
			'_wdc_platform_delivery_type' => \WallsShop\WDC\Domain\Quote\DeliveryType::PICKUP,
			'_wdc_platform_rate_id' => $carrier_key . ':pickup',
			'_wdc_pickup_point_code' => 'POINT-1',
			'_wdc_pickup_point_address' => 'Новосибирск, Красный проспект, 1',
		)
	);
	$repository->save_for_carrier( $order, $carrier_key, array_merge( array( 'carrier_key' => $carrier_key, 'status' => 'created' ), $shipment ) );
	$adapter = new ShipmentAdminAjaxSmokeAdapter( $carrier_key, $repository );
	$adapter->status_overrides = $status_overrides;
	$adapter->presentation_overrides = $presentation_overrides;
	$delivery_services = ( new ReflectionClass( \WallsShop\WDC\DeliveryServices\DeliveryServiceRepository::class ) )->newInstanceWithoutConstructor();
	$status_updates = ( new ReflectionClass( \WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService::class ) )->newInstanceWithoutConstructor();
	$controller = static fn( string $class ): object => ( new ReflectionClass( '\\WallsShop\\WDC\\Shipments\\Admin\\Ajax\\' . $class ) )->newInstanceWithoutConstructor();
	$metabox = new \WallsShop\WDC\Shipments\Admin\OrderShipmentsMetabox(
		$repository,
		new \WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory( $delivery_services, new \WallsShop\WDC\Shipments\Application\ShipmentServiceSettings() ),
		$delivery_services,
		$status_updates,
		shipment_test_actual_cost_resolver(),
		$controller( 'ShipmentCreateAjaxController' ),
		$controller( 'ShipmentLifecycleAjaxController' ),
		$controller( 'ShipmentPreviewAjaxController' ),
		$controller( 'ShipmentStatusAjaxController' ),
		$controller( 'ShipmentRemovalAjaxController' ),
		$controller( 'ShipmentManualAttachAjaxController' ),
		$controller( 'ShipmentAddressAjaxController' ),
		$controller( 'ShipmentActualCostAjaxController' ),
		$controller( 'ShipmentDocumentsAjaxController' ),
		$controller( 'ShipmentProductsAjaxController' ),
		null,
		null,
		null,
		'https://example.test/wp-content/plugins/wdc/',
		'0.147.6',
		new \WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry( array( $adapter ) ),
		new \WallsShop\WDC\Shipments\Application\ShipmentMetaboxButtonPolicy()
	);

	ob_start();
	$metabox->render( $order );

	return (string) ob_get_clean();
}

$ozon_no_return_html = shipment_admin_ajax_render_metabox_html(
	'ozon_delivery',
	array( 'tracking_number' => 'OZON-P1', 'barcode' => 'OZON-P1' ),
	array(
		'has_shipment' => true,
		'can_update_status' => true,
		'can_remove_from_order' => true,
		'tracking_presentation' => array(
			'label' => 'Номер Ozon',
			'display_text' => 'OZON-P1',
			'copy_value' => 'OZON-P1',
		),
	)
);
shipment_admin_ajax_assert( ! str_contains( $ozon_no_return_html, 'Не удалось подготовить блок отправлений' ) && str_contains( $ozon_no_return_html, 'data-wdc-shipments-metabox' ), 'Metabox render must not fail when return_tracking_presentation is absent.' );
shipment_admin_ajax_assert( str_contains( $ozon_no_return_html, 'data-wdc-return-tracking-row hidden' ), 'Absent return presentation must keep the return tracking row hidden on initial render.' );
shipment_admin_ajax_assert( str_contains( $ozon_no_return_html, 'data-wdc-remove-shipment-from-order' ) && str_contains( $ozon_no_return_html, 'Удалить из заказа' ) && ! str_contains( $ozon_no_return_html, '>Удалить заказ<' ), 'Ozon local remove button must use the generic remove-from-order label.' );

$ozon_cancel_html = shipment_admin_ajax_render_metabox_html(
	'ozon_delivery',
	array( 'tracking_number' => 'OZON-CANCEL', 'barcode' => 'OZON-CANCEL' ),
	array(
		'has_shipment' => true,
		'can_cancel' => true,
		'can_remove_from_order' => false,
	),
	array( 'cancel_button_label' => 'Отменить заказ' )
);
shipment_admin_ajax_assert( str_contains( $ozon_cancel_html, 'data-wdc-cancel-shipment' ) && str_contains( $ozon_cancel_html, 'Отменить заказ' ) && preg_match( '/<button[^>]*data-wdc-remove-shipment-from-order[^>]*hidden[^>]*>/u', $ozon_cancel_html ) && preg_match( '/<button[^>]*data-wdc-remove-shipment-from-order[^>]*disabled/u', $ozon_cancel_html ), 'Carrier cancel label must remain unchanged and local remove must stay hidden when can_remove=false.' );

$ozon_return_html = shipment_admin_ajax_render_metabox_html(
	'ozon_delivery',
	array( 'tracking_number' => 'OZON-P1', 'barcode' => 'OZON-P1' ),
	array(
		'has_shipment' => true,
		'can_update_status' => true,
		'return_tracking_presentation' => array(
			'label' => 'Возврат Ozon',
			'display_text' => 'R1 (MOVING)',
			'copy_value' => 'R1',
		),
	)
);
shipment_admin_ajax_assert( ! str_contains( $ozon_return_html, 'Не удалось подготовить блок отправлений' ) && str_contains( $ozon_return_html, 'Возврат Ozon' ) && str_contains( $ozon_return_html, 'R1 (MOVING)' ) && str_contains( $ozon_return_html, 'data-tracking-number="R1"' ), 'Ozon single return presentation must render without Throwable and keep copy value.' );

$ozon_multi_return_html = shipment_admin_ajax_render_metabox_html(
	'ozon_delivery',
	array( 'tracking_number' => 'OZON-P1', 'barcode' => 'OZON-P1' ),
	array(
		'has_shipment' => true,
		'can_update_status' => true,
		'return_tracking_presentation' => array(
			'label' => 'Возвраты Ozon',
			'items' => array(
				array( 'label' => 'Возврат коробки 1', 'display_text' => 'R1 (RECEIVED)', 'copy_value' => 'R1' ),
				array( 'label' => 'Возврат коробки 2', 'display_text' => 'R2 (MOVING)', 'copy_value' => 'R2' ),
			),
		),
	)
);
shipment_admin_ajax_assert( ! str_contains( $ozon_multi_return_html, 'Не удалось подготовить блок отправлений' ) && str_contains( $ozon_multi_return_html, 'Возврат коробки 1' ) && str_contains( $ozon_multi_return_html, 'R1 (RECEIVED)' ) && str_contains( $ozon_multi_return_html, 'Возврат коробки 2' ) && str_contains( $ozon_multi_return_html, 'R2 (MOVING)' ), 'Ozon multi return presentation must render every item without PHP Error.' );

$cdek_html = shipment_admin_ajax_render_metabox_html(
	\WallsShop\WDC\Carriers\Cdek\CdekSettings::CARRIER_KEY,
	array( 'tracking_number' => 'CDEK123', 'barcode' => 'CDEK123' ),
	array(
		'has_shipment' => true,
		'can_update_status' => true,
		'tracking_presentation' => array(
			'label' => 'Отслеживание',
			'display_text' => 'CDEK123',
			'copy_value' => 'CDEK123',
		),
	)
);
shipment_admin_ajax_assert( ! str_contains( $cdek_html, 'Не удалось подготовить блок отправлений' ) && str_contains( $cdek_html, 'CDEK123' ) && str_contains( $cdek_html, 'data-wdc-return-tracking-row hidden' ), 'Non-Ozon carrier metabox render must keep primary tracking and hide absent return presentation.' );
shipment_admin_ajax_assert( str_contains( $cdek_html, 'data-wdc-remove-shipment-from-order' ) && str_contains( $cdek_html, 'Удалить из заказа' ) && ! str_contains( $cdek_html, '>Удалить заказ<' ), 'Non-Ozon generic remove button must use the same remove-from-order label.' );

$malformed_return_html = shipment_admin_ajax_render_metabox_html(
	'ozon_delivery',
	array( 'tracking_number' => 'OZON-P1', 'barcode' => 'OZON-P1' ),
	array(
		'has_shipment' => true,
		'can_update_status' => true,
		'return_tracking_presentation' => array(
			'label' => array( 'bad' ),
			'display_text' => null,
			'copy_value' => array( 'bad' ),
			'items' => array(
				null,
				array( 'label' => array( 'bad' ), 'display_text' => array( 'bad' ), 'copy_value' => array( 'bad' ) ),
				array( 'label' => 'Возврат коробки 1', 'display_text' => 'R1', 'copy_value' => 'R1' ),
			),
		),
	)
);
shipment_admin_ajax_assert( ! str_contains( $malformed_return_html, 'Не удалось подготовить блок отправлений' ) && str_contains( $malformed_return_html, 'Возврат коробки 1' ) && str_contains( $malformed_return_html, 'R1' ) && ! str_contains( $malformed_return_html, 'Array' ), 'Malformed secondary tracking presentation must fail safe and keep valid items.' );

$GLOBALS['wdc_shipment_admin_ajax_actions'] = array();
$GLOBALS['wdc_shipment_admin_ajax_orders'] = array(
	501 => new ShipmentAdminAjaxSmokeOrder( 501 ),
);
$initial_buffer_level = ob_get_level();
$_POST = array(
	'nonce' => 'valid',
	'order_id' => 501,
	'shipment_key' => 'alpha',
	'barcode' => 'TRACK-501',
);
$response = null;
try {
	$manual_attach->handle();
} catch ( ShipmentAdminAjaxSmokeAjaxResponse $captured ) {
	$response = $captured;
}
shipment_admin_ajax_assert( $response instanceof ShipmentAdminAjaxSmokeAjaxResponse && $response->success && 200 === $response->status_code, 'Manual attach controller must return JSON success without fatal error.' );
shipment_admin_ajax_assert( 1 === $alpha_adapter->attach_calls, 'Manual attach controller must call the selected carrier adapter.' );
shipment_admin_ajax_assert( 'TRACK-501' === (string) ( $response->data['tracking_number'] ?? '' ), 'Manual attach response must include tracking_number.' );
shipment_admin_ajax_assert( $initial_buffer_level === ob_get_level(), 'Manual attach controller must restore output buffer level before JSON response.' );
$saved = $repository->find_by_carrier( $GLOBALS['wdc_shipment_admin_ajax_orders'][501], 'alpha' );
shipment_admin_ajax_assert( 'TRACK-501' === (string) ( $saved['tracking_number'] ?? '' ), 'Manual attach must persist shipment through OrderShipmentRepository.' );
shipment_admin_ajax_assert( array() !== array_filter( $GLOBALS['wdc_shipment_admin_ajax_actions'], static fn( array $action ): bool => 'wdc_shipment_record_changed' === $action['hook'] ), 'Manual attach persistence must emit analytics shipment-changed hook.' );

$_POST = array(
	'nonce' => 'valid',
	'order_id' => 501,
	'shipment_key' => 'beta',
	'barcode' => 'TRACK-502',
);
$response = null;
try {
	$manual_attach->handle();
} catch ( ShipmentAdminAjaxSmokeAjaxResponse $captured ) {
	$response = $captured;
}
shipment_admin_ajax_assert( $response instanceof ShipmentAdminAjaxSmokeAjaxResponse && $response->success && 1 === $beta_adapter->attach_calls && 'TRACK-502' === (string) ( $response->data['tracking_number'] ?? '' ), 'Manual attach controller must stay carrier-agnostic across fake carriers.' );

echo "Shipment admin AJAX smoke passed.\n";
