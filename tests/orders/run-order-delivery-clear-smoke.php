<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Orders\Admin\OrderDeliveryMetabox;
use WallsShop\WDC\Orders\Application\OrderDeliveryDataClearService;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function current_user_can( string $capability ): bool { return 'manage_woocommerce' === $capability; }
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
function esc_attr__( string $text, string $domain = 'default' ): string { return $text; }
function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function do_action( string $hook, mixed ...$args ): void { $GLOBALS['wdc_actions'][] = array( $hook, $args ); }
function delivery_clear_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }

final class DeliveryClearSmokeItem {
	public function __construct( public string $method_id = 'wdc_platform_delivery', public string $instance_id = '118', public string $title = 'Яндекс до ПВЗ', public float $total = 385.0, public array $meta = array( 'Планируемая* дата доставки' => '23.09.2026' ) ) {}
}
final class DeliveryClearSmokeOrder {
	public int $save_calls = 0;
	public DeliveryClearSmokeItem $shipping_item;
	public function __construct( public array $meta ) { $this->shipping_item = new DeliveryClearSmokeItem(); }
	public function get_id(): int { return 123; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
	public function save(): void { ++$this->save_calls; }
}

$technical = array_fill_keys( OrderDeliveryDataClearService::ORDER_META_KEYS, 'technical' );
$technical['_wdc_delivery_calculation_data'] = array( 'carrier_key' => 'yandex_delivery' );
$technical['_wdc_platform_rate_meta'] = array( 'pickup' => true );
$technical['_wdc_pickup_point_snapshot'] = '{"point_code":"station-A"}';
$technical['_unrelated_order_meta'] = 'keep';
$order = new DeliveryClearSmokeOrder( $technical );
$item_before = clone $order->shipping_item;
$service = new OrderDeliveryDataClearService( new OrderShipmentRepository() );
$result = $service->clear( $order );
delivery_clear_assert( true === $result['success'] && 1 === $order->save_calls, 'Clear must delete through WC_Order API and save once.' );
foreach ( OrderDeliveryDataClearService::ORDER_META_KEYS as $key ) {
	delivery_clear_assert( ! array_key_exists( $key, $order->meta ), 'Technical delivery meta must be removed: ' . $key );
}
delivery_clear_assert( 'keep' === $order->meta['_unrelated_order_meta'], 'Unrelated order metadata must remain.' );
delivery_clear_assert( $item_before == $order->shipping_item, 'Shipping item identity, method, title, total and visible planned delivery meta must remain untouched.' );
$draft_factory = ( new ReflectionClass( OrderShipmentDraftFactory::class ) )->newInstanceWithoutConstructor();
delivery_clear_assert( false === $draft_factory->supports_order( $order ), 'Shipment Draft Factory must not support the cleared order.' );
ob_start();
( new OrderDeliveryMetabox( new OrderShipmentRepository() ) )->render( $order );
$html = (string) ob_get_clean();
delivery_clear_assert( str_contains( $html, 'Данные WDC для заказа не сохранены.' ) && str_contains( $html, 'Пересчитать доставку' ) && ! str_contains( $html, 'Очистить данные доставки' ), 'Cleared calculator must render canonical empty state and keep recalculation available.' );
$shipment_metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
delivery_clear_assert( str_contains( $shipment_metabox_source, 'supports_order( $order )' ) && str_contains( $shipment_metabox_source, 'Добавьте стандартную службу доставки' ), 'Cleared unsupported order must use the existing canonical shipment empty-state renderer.' );
$order->meta['_wdc_platform_carrier_key'] = 'yandex_delivery';
delivery_clear_assert( true === $draft_factory->supports_order( $order ), 'A subsequent saved recalculation must restore normal shipment eligibility through canonical metadata.' );
unset( $order->meta['_wdc_platform_carrier_key'] );

$blocked_meta = array( '_wdc_platform_carrier_key' => 'ozon_delivery', OrderShipmentRepository::META_KEY => array( 'ozon_delivery' => array( 'status' => 'reconciliation_required' ) ) );
$blocked = new DeliveryClearSmokeOrder( $blocked_meta );
$blocked_result = $service->clear( $blocked );
delivery_clear_assert( false === $blocked_result['success'] && $blocked_meta === $blocked->meta && 0 === $blocked->save_calls, 'Any partial shipment record must block clear without changing metadata.' );
$manual = new DeliveryClearSmokeOrder( array( '_wdc_platform_carrier_key' => 'manual', OrderShipmentRepository::META_KEY => array( 'manual' => array( 'status' => 'attached' ) ) ) );
delivery_clear_assert( false === $service->clear( $manual )['success'], 'Manual attachment must block clear.' );

$controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Orders/Admin/OrderDeliveryRecalculationAdminController.php' );
$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/order-delivery-recalculation.js' );
delivery_clear_assert( str_contains( $controller_source, 'current_user_can( OrderDeliveryMetabox::CAPABILITY )' ) && str_contains( $controller_source, 'check_ajax_referer' ) && str_contains( $controller_source, 'wc_get_order' ), 'AJAX clear must enforce capability, nonce and server-side order lookup.' );
delivery_clear_assert( str_contains( $js_source, "window.confirm( 'Удалить сохранённые данные расчёта доставки?\\nДля создания отправления потребуется заново пересчитать доставку.'" ) && str_contains( $js_source, "method: 'POST'" ) && str_contains( $js_source, 'window.location.reload()' ), 'Clear UI must confirm, POST and reload both canonical metaboxes after success.' );

echo "Order delivery clear smoke OK\n";
