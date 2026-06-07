<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\ShipmentOrderStatusMappingService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function order_status_mapping_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ?? '' ); }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_order_status_mapping_options'][ $key ] ?? $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, bool|string|null $autoload = null ): bool {
		$GLOBALS['wdc_order_status_mapping_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wc_get_order_statuses' ) ) {
	function wc_get_order_statuses(): array {
		return array(
			'wc-processing' => 'Processing',
			'wc-completed' => 'Completed',
			'wc-cancelled' => 'Cancelled',
			'wc-returned' => 'Returned',
			'wc-custom-ready' => 'Custom Ready',
		);
	}
}
if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( array $args ): array {
		$GLOBALS['wdc_order_status_mapping_last_query'] = $args;
		return $GLOBALS['wdc_order_status_mapping_orders'] ?? array();
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string { return '2026-06-07 11:00:00'; }
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ): mixed { return false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool { return true; }
}

final class OrderStatusMappingSmokeOrder {
	public array $notes = array();
	public int $update_calls = 0;
	public bool $throw_on_update = false;

	public function __construct( private int $id, private string $status, private array $meta = array() ) {
	}

	public function get_id(): int { return $this->id; }
	public function get_status(): string { return $this->status; }
	public function update_status( string $status ): void {
		++$this->update_calls;
		if ( $this->throw_on_update ) {
			throw new RuntimeException( 'status rejected' );
		}
		$this->status = $status;
	}
	public function add_order_note( string $note, bool $is_customer_note = false, bool $added_by_user = false ): void {
		$this->notes[] = compact( 'note', 'is_customer_note', 'added_by_user' );
	}
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
}

$GLOBALS['wdc_order_status_mapping_options'] = array();
$settings = new SettingsRepository();
$mapping = new ShipmentOrderStatusMappingService( $settings );

order_status_mapping_assert( false === $mapping->enabled(), 'Order status mapping must be disabled by default.' );
order_status_mapping_assert( array() === $mapping->mapping(), 'Order status mapping must be empty by default.' );

$settings->set(
	ShipmentOrderStatusMappingService::MAPPING_KEY,
	array(
		DeliveryStatus::DELIVERED => 'wc-completed',
		DeliveryStatus::READY_FOR_PICKUP => 'wc-custom-ready',
		'bad_status' => 'wc-completed',
		DeliveryStatus::CANCELLED => 'wc-not-real',
	)
);
$settings->set( ShipmentOrderStatusMappingService::ENABLED_KEY, false );

$disabled_order = new OrderStatusMappingSmokeOrder( 1, 'processing' );
$disabled = $mapping->apply( $disabled_order, array( 'tracking_number' => '80080822636218' ), DeliveryStatus::DELIVERED );
order_status_mapping_assert( 'skipped' === $disabled['status'] && 0 === $disabled_order->update_calls, 'Disabled mapping must not change the order status.' );

$settings->set( ShipmentOrderStatusMappingService::ENABLED_KEY, true );
$no_mapping_order = new OrderStatusMappingSmokeOrder( 2, 'processing' );
$no_mapping = $mapping->apply( $no_mapping_order, array( 'tracking_number' => '80080822636218' ), DeliveryStatus::IN_TRANSIT );
order_status_mapping_assert( 'skipped' === $no_mapping['status'] && 0 === $no_mapping_order->update_calls, 'Shipment status without mapping must not change the order status.' );

$order = new OrderStatusMappingSmokeOrder( 3, 'processing' );
$changed = $mapping->apply(
	$order,
	array(
		'tracking_number' => '80080822636218',
		'universal_status_label' => 'доставлен',
	),
	DeliveryStatus::DELIVERED
);
order_status_mapping_assert( 'changed' === $changed['status'] && 'completed' === $order->get_status(), 'Mapped shipment status must update WooCommerce order status.' );
order_status_mapping_assert( 1 === $order->update_calls, 'Mapped shipment status must call update_status once.' );
$expected_note = "Посылка 80080822636218\nСтатус: доставлен.\nСтатус заказа изменён:\nprocessing → completed";
order_status_mapping_assert( 1 === count( $order->notes ) && false === $order->notes[0]['is_customer_note'] && $expected_note === $order->notes[0]['note'], 'Mapping must add a compact private WDC order note.' );
order_status_mapping_assert( ! str_contains( $order->notes[0]['note'], 'WDC: статус заказа автоматически изменен по статусу отправления' ) && ! str_contains( $order->notes[0]['note'], 'Статус отправления Почты России обновлен' ), 'Mapping note must not use old verbose note texts.' );

$repeat = $mapping->apply( $order, array( 'tracking_number' => '80080822636218' ), DeliveryStatus::DELIVERED );
order_status_mapping_assert( 'skipped' === $repeat['status'] && 1 === $order->update_calls, 'Repeating target status must not call update_status again.' );

$custom_order = new OrderStatusMappingSmokeOrder( 4, 'processing' );
$custom = $mapping->apply( $custom_order, array( 'tracking_number' => '80080822636219' ), DeliveryStatus::READY_FOR_PICKUP );
order_status_mapping_assert( 'changed' === $custom['status'] && 'custom-ready' === $custom_order->get_status(), 'Custom statuses from wc_get_order_statuses() must be supported.' );

$bad_target_order = new OrderStatusMappingSmokeOrder( 5, 'processing' );
$bad_target = $mapping->apply( $bad_target_order, array(), DeliveryStatus::CANCELLED );
order_status_mapping_assert( 'skipped' === $bad_target['status'] && 0 === $bad_target_order->update_calls, 'Unavailable target status must be skipped.' );

$error_order = new OrderStatusMappingSmokeOrder( 6, 'processing' );
$error_order->throw_on_update = true;
$error = $mapping->apply( $error_order, array(), DeliveryStatus::DELIVERED );
order_status_mapping_assert( 'error' === $error['status'] && str_contains( $error['message'], 'status rejected' ), 'WooCommerce update_status errors must be captured.' );

$repository = new OrderShipmentRepository();
$status_updates = ( new ReflectionClass( ShipmentStatusUpdateService::class ) )->newInstanceWithoutConstructor();

$terminal_dispatches = 0;
$terminal_order = new OrderStatusMappingSmokeOrder(
	70,
	'processing',
	array(
		OrderShipmentRepository::META_KEY => array(
			RussianPostDomesticSettings::CARRIER_KEY => array(
				'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
				'status' => 'created',
				'tracking_number' => '80080822636270',
				'universal_status_code' => DeliveryStatus::DELIVERED,
				'universal_status_label' => 'доставлен',
			),
		),
	)
);
$GLOBALS['wdc_order_status_mapping_orders'] = array( $terminal_order );
$terminal_autosync = new ShipmentStatusAutoSyncService(
	$settings,
	$repository,
	$status_updates,
	$mapping,
	function () use ( &$terminal_dispatches ): array {
		++$terminal_dispatches;
		return array( 'success' => true );
	}
);
$terminal_stats = $terminal_autosync->run( 'manual' );
order_status_mapping_assert( 0 === $terminal_dispatches, 'Terminal delivered shipment must not call dispatcher or Tracking API.' );
order_status_mapping_assert( 'completed' === $terminal_order->get_status() && 1 === $terminal_order->update_calls, 'Terminal delivered shipment with mapping must update WooCommerce order status from saved universal status.' );
order_status_mapping_assert( 1 === (int) $terminal_stats['skip_reasons']['terminal_status_no_tracking_update'] && 1 === (int) $terminal_stats['order_statuses_changed'], 'Terminal mapping change must be visible in skip reasons and order status diagnostics.' );

$settings->set( ShipmentOrderStatusMappingService::MAPPING_KEY, array() );
$terminal_without_mapping = new OrderStatusMappingSmokeOrder(
	71,
	'processing',
	array(
		OrderShipmentRepository::META_KEY => array(
			RussianPostDomesticSettings::CARRIER_KEY => array(
				'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
				'status' => 'created',
				'tracking_number' => '80080822636271',
				'universal_status_code' => DeliveryStatus::DELIVERED,
			),
		),
	)
);
$GLOBALS['wdc_order_status_mapping_orders'] = array( $terminal_without_mapping );
$terminal_stats = $terminal_autosync->run( 'manual' );
order_status_mapping_assert( 'processing' === $terminal_without_mapping->get_status() && 0 === $terminal_without_mapping->update_calls, 'Terminal delivered shipment without mapping must not change WooCommerce order status.' );
order_status_mapping_assert( 1 === (int) $terminal_stats['order_statuses_skipped'], 'Terminal delivered shipment without mapping must increment order_statuses_skipped.' );

$settings->set( ShipmentOrderStatusMappingService::MAPPING_KEY, array( DeliveryStatus::DELIVERED => 'wc-completed' ) );
$settings->set( ShipmentOrderStatusMappingService::ENABLED_KEY, false );
$terminal_disabled = new OrderStatusMappingSmokeOrder(
	72,
	'processing',
	array(
		OrderShipmentRepository::META_KEY => array(
			RussianPostDomesticSettings::CARRIER_KEY => array(
				'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
				'status' => 'created',
				'tracking_number' => '80080822636272',
				'universal_status_code' => DeliveryStatus::DELIVERED,
			),
		),
	)
);
$GLOBALS['wdc_order_status_mapping_orders'] = array( $terminal_disabled );
$terminal_stats = $terminal_autosync->run( 'manual' );
order_status_mapping_assert( 'processing' === $terminal_disabled->get_status() && 0 === $terminal_disabled->update_calls, 'Terminal delivered shipment with disabled mapping must not change WooCommerce order status.' );
order_status_mapping_assert( 1 === (int) $terminal_stats['order_statuses_skipped'], 'Terminal delivered shipment with disabled mapping must increment order_statuses_skipped.' );

$settings->set( ShipmentOrderStatusMappingService::ENABLED_KEY, true );
$autosync = new ShipmentStatusAutoSyncService(
	$settings,
	$repository,
	$status_updates,
	$mapping,
	function ( string $carrier_key, object $order, string $shipment_key ): array {
		return array(
			'success' => true,
			'order_status_mapping' => array(
				'status' => 'changed',
				'target_status' => 'wc-completed',
			),
		);
	}
);
$GLOBALS['wdc_order_status_mapping_orders'] = array(
	new OrderStatusMappingSmokeOrder(
		7,
		'processing',
		array(
			OrderShipmentRepository::META_KEY => array(
				RussianPostDomesticSettings::CARRIER_KEY => array(
					'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
					'status' => 'created',
					'tracking_number' => '80080822636220',
					'universal_status_code' => DeliveryStatus::IN_TRANSIT,
				),
			),
		)
	),
);
$stats = $autosync->run( 'manual' );
order_status_mapping_assert( 1 === (int) $stats['shipments_updated'] && 1 === (int) $stats['order_statuses_changed'], 'Autosync must collect order status mapping changes from the shared status update result.' );

$autosync_error = new ShipmentStatusAutoSyncService(
	$settings,
	$repository,
	$status_updates,
	$mapping,
	function ( string $carrier_key, object $order, string $shipment_key ): array {
		return array(
			'success' => true,
			'order_status_mapping' => array(
				'status' => 'error',
				'message' => 'status rejected',
			),
		);
	}
);
$stats = $autosync_error->run( 'manual' );
order_status_mapping_assert( 1 === (int) $stats['order_status_change_errors'] && str_contains( (string) $stats['error_samples'][0]['message'], 'status rejected' ), 'Autosync diagnostics must collect order status mapping errors.' );

$status_service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/ShipmentStatusUpdateService.php' );
order_status_mapping_assert( str_contains( $status_service_source, '->save_for_carrier(' ) && str_contains( $status_service_source, '->apply( $order, $updated' ), 'ShipmentStatusUpdateService must apply order status mapping after saving shipment status.' );

echo "Order status mapping smoke passed\n";
