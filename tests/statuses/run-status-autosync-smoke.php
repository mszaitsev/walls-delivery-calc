<?php
declare(strict_types=1);

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Admin\ShipmentStatusesAdminPage;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncCron;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function status_autosync_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string { return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'checked' ) ) {
	function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
		$result = $checked === $current ? 'checked="checked"' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}
if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string $action, string $name ): void { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">'; }
}
if ( ! function_exists( 'wp_verify_nonce' ) ) {
	function wp_verify_nonce( string $nonce, string $action ): bool { return true; }
}
if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( string $text = 'Save', string $type = 'primary', string $name = 'submit', bool $wrap = true ): void { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ?? '' ); }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed { return $value; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool { return AdminMenu::CAPABILITY === $capability; }
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . ltrim( $path, '/' ); }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['wdc_status_autosync_actions'][ $hook ][] = $callback; }
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void { $GLOBALS['wdc_status_autosync_filters'][ $hook ][] = $callback; }
}
if ( ! function_exists( 'add_submenu_page' ) ) {
	function add_submenu_page( mixed ...$args ): void { $GLOBALS['wdc_status_autosync_submenus'][] = $args; }
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( string $hook ): int|false { return $GLOBALS['wdc_status_autosync_events'][ $hook ]['timestamp'] ?? false; }
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
		$GLOBALS['wdc_status_autosync_events'][ $hook ] = compact( 'timestamp', 'recurrence', 'hook' );
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $key ): mixed { return $GLOBALS['wdc_status_autosync_transients'][ $key ]['value'] ?? false; }
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['wdc_status_autosync_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_status_autosync_transients'][ $key ] ); return true; }
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_status_autosync_options'][ $key ] ?? $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, bool|string|null $autoload = null ): bool { $GLOBALS['wdc_status_autosync_options'][ $key ] = $value; return true; }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string { return '2026-06-07 10:00:00'; }
}
if ( ! function_exists( 'wc_get_order_statuses' ) ) {
	function wc_get_order_statuses(): array {
		$GLOBALS['wdc_status_autosync_wc_statuses_called'] = true;
		return array(
			'wc-processing' => 'Processing',
			'wc-completed' => 'Completed',
			'wc-custom-shipping' => 'Custom Shipping',
		);
	}
}
if ( ! function_exists( 'wc_get_orders' ) ) {
	function wc_get_orders( array $args ): array {
		$GLOBALS['wdc_status_autosync_last_order_query'] = $args;
		return $GLOBALS['wdc_status_autosync_orders'] ?? array();
	}
}

final class StatusAutoSyncSmokeOrder {
	public array $notes = array();

	public function __construct( private int $id, private string $status, private array $meta ) {
	}

	public function get_id(): int { return $this->id; }
	public function get_status(): string { return $this->status; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
	public function meta_snapshot(): array { return $this->meta; }
}

$GLOBALS['wdc_status_autosync_options'] = array();
$GLOBALS['wdc_status_autosync_transients'] = array();
$GLOBALS['wdc_status_autosync_events'] = array();
$GLOBALS['wdc_status_autosync_actions'] = array();
$GLOBALS['wdc_status_autosync_filters'] = array();

$settings = new SettingsRepository();
$repository = new OrderShipmentRepository();
$status_updates = ( new ReflectionClass( ShipmentStatusUpdateService::class ) )->newInstanceWithoutConstructor();
$dispatches = array();
$service = new ShipmentStatusAutoSyncService(
	$settings,
	$repository,
	$status_updates,
	function ( string $carrier_key, object $order, string $shipment_key ) use ( &$dispatches ): array {
		$dispatches[] = array( $carrier_key, $order->get_id(), $shipment_key );
		return array( 'success' => true, 'message' => 'ok' );
	}
);

status_autosync_assert( true === $service->enabled(), 'Autosync must be enabled by default.' );
status_autosync_assert( in_array( 'wc-processing', $service->selected_order_statuses(), true ), 'Default selected statuses must include processing.' );

$cron = new ShipmentStatusAutoSyncCron( $service );
$schedule = $cron->add_schedule( array() );
status_autosync_assert( ShipmentStatusAutoSyncService::INTERVAL_SECONDS === (int) $schedule[ ShipmentStatusAutoSyncCron::SCHEDULE ]['interval'], 'Custom schedule must use a 6-hour interval.' );
$cron->ensure_scheduled();
status_autosync_assert( ShipmentStatusAutoSyncCron::SCHEDULE === $GLOBALS['wdc_status_autosync_events'][ ShipmentStatusAutoSyncCron::HOOK ]['recurrence'], 'Cron event must be registered with custom schedule.' );

set_transient( ShipmentStatusAutoSyncService::LOCK_KEY, 1, ShipmentStatusAutoSyncService::LOCK_TTL );
$locked = $service->run( 'manual' );
status_autosync_assert( 'locked' === $locked['status'] && 0 === count( $dispatches ), 'Second run must be blocked while the universal lock exists.' );
delete_transient( ShipmentStatusAutoSyncService::LOCK_KEY );

$settings->replace(
	array_merge(
		$settings->all(),
		array(
			ShipmentStatusAutoSyncService::ORDER_STATUSES_KEY => array( 'wc-processing', 'wc-custom-shipping' ),
		)
	)
);

$GLOBALS['wdc_status_autosync_orders'] = array(
	new StatusAutoSyncSmokeOrder(
		101,
		'processing',
		array(
			OrderShipmentRepository::META_KEY => array(
				RussianPostDomesticSettings::CARRIER_KEY => array(
					'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
					'status' => 'created',
					'tracking_number' => '12345678901234',
					'universal_status_code' => DeliveryStatus::DELIVERED,
				),
			),
		)
	),
	new StatusAutoSyncSmokeOrder(
		102,
		'processing',
		array(
			OrderShipmentRepository::META_KEY => array(
				RussianPostDomesticSettings::CARRIER_KEY => array(
					'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
					'status' => 'created',
					'tracking_number' => '22345678901234',
					'universal_status_code' => DeliveryStatus::UNKNOWN,
				),
			),
		)
	),
	new StatusAutoSyncSmokeOrder(
		103,
		'processing',
		array(
			OrderShipmentRepository::META_KEY => array(
				RussianPostDomesticSettings::CARRIER_KEY => array(
					'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
					'status' => 'created',
					'universal_status_code' => DeliveryStatus::IN_TRANSIT,
				),
			),
		)
	),
	new StatusAutoSyncSmokeOrder(
		104,
		'processing',
		array(
			OrderShipmentRepository::META_KEY => array(
				'boxberry' => array(
					'carrier_key' => 'boxberry',
					'status' => 'created',
					'tracking_number' => 'BB123',
					'universal_status_code' => DeliveryStatus::IN_TRANSIT,
				),
			),
		)
	),
);

$stats = $service->run( 'cron' );
status_autosync_assert( 4 === $stats['orders_scanned'], 'Autosync must scan WooCommerce orders in selected statuses.' );
status_autosync_assert( array( 'wc-processing', 'wc-custom-shipping' ) === $GLOBALS['wdc_status_autosync_last_order_query']['status'], 'Order query must use selected WooCommerce statuses.' );
status_autosync_assert( 4 === $stats['shipments_found'], 'Autosync must count discovered shipments.' );
status_autosync_assert( 1 === $stats['shipments_updated'], 'Only the non-terminal supported shipment with tracking must update.' );
status_autosync_assert( 1 === count( $dispatches ) && RussianPostDomesticSettings::CARRIER_KEY === $dispatches[0][0] && 102 === $dispatches[0][1], 'russian_post_domestic must dispatch through the status updater and unknown must be processed.' );
status_autosync_assert( 1 === (int) $stats['skip_reasons']['terminal_status'], 'Terminal universal statuses must be skipped.' );
status_autosync_assert( 1 === (int) $stats['skip_reasons']['missing_tracking_number'], 'Shipments without tracking number or barcode must be skipped.' );
status_autosync_assert( 1 === (int) $stats['skip_reasons']['unsupported_carrier'], 'Unsupported carriers must be skipped.' );

$stored = $settings->get_array( ShipmentStatusAutoSyncService::DIAGNOSTICS_KEY );
status_autosync_assert( 'cron' === (string) $stored['trigger_type'] && 1 === (int) $stored['shipments_updated'], 'Diagnostics stats must be stored after run.' );

$page = new ShipmentStatusesAdminPage( $settings, $service );
ob_start();
$page->add_menu_page();
$page->render_page();
$html = ob_get_clean();
status_autosync_assert( ! empty( $GLOBALS['wdc_status_autosync_wc_statuses_called'] ), 'Settings page must load statuses through wc_get_order_statuses().' );
status_autosync_assert( str_contains( $html, 'Статусы отправлений' ) && str_contains( $html, 'wc-custom-shipping' ), 'Settings page must render the Statuses screen and custom WooCommerce statuses.' );

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
	'wdc_statuses_action' => 'run_now',
	'wdc_shipment_statuses_nonce' => 'nonce',
);
$_GET = array( 'tab' => 'diagnostics' );
ob_start();
$page->render_page();
$manual_html = ob_get_clean();
$manual_stats = $settings->get_array( ShipmentStatusAutoSyncService::DIAGNOSTICS_KEY );
status_autosync_assert( 'manual' === (string) $manual_stats['trigger_type'], 'Manual run must execute the same autosync service.' );
status_autosync_assert( str_contains( $manual_html, 'Обработано заказов' ) && str_contains( $manual_html, 'Запустить синхронизацию сейчас' ), 'Manual run result and diagnostics button must render.' );

echo "Status autosync smoke passed\n";
