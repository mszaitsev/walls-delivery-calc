<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\Tracking\RussianPostTrackingApiClient;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\ShipmentBacklogService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\RussianPost\RussianPostTrackingStatusMapper;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'AUTH_KEY' ) || define( 'AUTH_KEY', 'shipment-cancel-smoke-auth-key' );
defined( 'SECURE_AUTH_KEY' ) || define( 'SECURE_AUTH_KEY', 'shipment-cancel-smoke-secure-auth-key' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function russian_post_cancel_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string { return '2026-06-06 12:34:56'; }
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string { return 'shipment-cancel-smoke-' . $scheme; }
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string { return $url . ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $args ); }
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool { return false; }
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }
}
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( string $url, array $args = array() ): array {
		$GLOBALS['wdc_cancel_smoke_last_request'] = array( 'url' => $url, 'args' => $args );
		return array(
			'response' => array( 'code' => (int) ( $GLOBALS['wdc_cancel_smoke_request_code'] ?? 200 ) ),
			'body' => (string) ( $GLOBALS['wdc_cancel_smoke_request_body'] ?? '{"result-ids":[2285075494],"errors":[]}' ),
		);
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ): array {
		$GLOBALS['wdc_cancel_smoke_last_get'] = array( 'url' => $url, 'args' => $args );
		$GLOBALS['wdc_cancel_smoke_get_calls'][] = array( 'url' => $url, 'args' => $args );
		$body_key = str_contains( $url, '/1.0/shipment/search' ) ? 'wdc_cancel_smoke_shipment_get_body' : 'wdc_cancel_smoke_backlog_get_body';
		return array(
			'response' => array( 'code' => (int) ( $GLOBALS['wdc_cancel_smoke_get_code'] ?? 200 ) ),
			'body' => (string) ( $GLOBALS[ $body_key ] ?? $GLOBALS['wdc_cancel_smoke_get_body'] ?? '[]' ),
		);
	}
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ): array {
		$GLOBALS['wdc_cancel_smoke_last_post'] = array( 'url' => $url, 'args' => $args );
		return array(
			'response' => array( 'code' => (int) ( $GLOBALS['wdc_cancel_smoke_post_code'] ?? 200 ) ),
			'body' => (string) ( $GLOBALS['wdc_cancel_smoke_post_body'] ?? '' ),
		);
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $services = array();
		public array $settings = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( str_contains( $query, 'wdc_delivery_services' ) ) {
				return $this->services[0] ?? null;
			}
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) ) {
				foreach ( $this->settings as $row ) {
					if ( str_contains( $query, 'service_id = ' . (int) $row['service_id'] ) && str_contains( $query, "'" . (string) $row['setting_key'] . "'" ) ) {
						return $row;
					}
				}
			}

			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) ) {
				return $this->settings;
			}

			return array();
		}

		public function get_var( string $query ): mixed { return null; }
		public function insert( string $table, array $data, array $format = array() ): bool { return true; }
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool { return true; }
		public function delete( string $table, array $where, array $where_format = array() ): bool { return true; }
	}
}

final class RussianPostCancelSmokeOrder {
	public array $notes = array();
	public int $save_count = 0;

	public function __construct( private int $id, private array $meta = array() ) {
	}

	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void { $this->save_count++; }
	public function add_order_note( string $message ): void { $this->notes[] = $message; }
	public function meta_snapshot(): array { return $this->meta; }
}

function russian_post_cancel_smoke_envelope( string $type_id = '28', string $type_name = 'Присвоение идентификатора' ): string {
	return '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><ns2:getOperationHistoryResponse xmlns:ns2="http://russianpost.org/operationhistory"><OperationHistoryData><historyRecord><OperationParameters><OperDate>2026-06-06T10:00:00+07:00</OperDate><OperType><Id>' . $type_id . '</Id><Name>' . $type_name . '</Name></OperType><OperAttr><Id>0</Id><Name></Name></OperAttr></OperationParameters><AddressParameters><OperationAddress><Index>630001</Index><Description>Новосибирск</Description></OperationAddress></AddressParameters></historyRecord></OperationHistoryData></ns2:getOperationHistoryResponse></soap:Body></soap:Envelope>';
}

$wpdb = new wpdb();
$encryption = new EncryptionService();
$wpdb->services[] = array(
	'id' => 1,
	'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
	'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
	'service_type' => 'api',
	'title' => 'Почта России',
	'enabled' => 1,
	'deleted' => 0,
);
foreach (
	array(
		RussianPostOtpravkaApiSettings::ACCESS_TOKEN_KEY => array( 'token', 'string' ),
		RussianPostOtpravkaApiSettings::LOGIN_KEY => array( 'login', 'string' ),
		RussianPostOtpravkaApiSettings::PASSWORD_ENCRYPTED_KEY => array( $encryption->encrypt( 'password' ), 'string' ),
		RussianPostOtpravkaApiSettings::TRACKING_LOGIN_KEY => array( 'tracking-login', 'string' ),
		RussianPostOtpravkaApiSettings::TRACKING_PASSWORD_ENCRYPTED_KEY => array( $encryption->encrypt( 'tracking-password' ), 'string' ),
		RussianPostOtpravkaApiSettings::TIMEOUT_KEY => array( '30', 'number' ),
	) as $key => $setting
) {
	$wpdb->settings[] = array( 'service_id' => 1, 'setting_key' => $key, 'setting_value' => $setting[0], 'value_format' => $setting[1] );
}

$settings = new RussianPostOtpravkaApiSettings( new SettingsRepository(), $encryption, new DeliveryServiceRepository( $wpdb ), new DeliveryServiceSettingsRepository( $wpdb ) );
$otpravka_client = new RussianPostOtpravkaApiClient( $settings );
$tracking_client = new RussianPostTrackingApiClient( $settings );
$repository = new OrderShipmentRepository();
$status_service = new ShipmentStatusUpdateService( $repository, $tracking_client, new RussianPostTrackingStatusMapper() );
$backlog_service = new ShipmentBacklogService( $repository, $otpravka_client, $status_service );

$GLOBALS['wdc_cancel_smoke_request_body'] = '{"result-ids":[2285075494],"errors":[]}';
$delete = $otpravka_client->delete_backlog_orders( array( 2285075494 ) );
russian_post_cancel_smoke_assert( true === $delete['success'], 'delete_backlog_orders must succeed when result-ids contains id.' );
russian_post_cancel_smoke_assert( '[2285075494]' === (string) ( $GLOBALS['wdc_cancel_smoke_last_request']['args']['body'] ?? '' ), 'delete_backlog_orders must send JSON array body.' );

$GLOBALS['wdc_cancel_smoke_request_body'] = '{"result-ids":[],"errors":[{"error-code":"UNDEFINED","error-details":"bad"}]}';
$delete_error = $otpravka_client->delete_backlog_orders( array( 2285075494 ) );
russian_post_cancel_smoke_assert( false === $delete_error['success'] && array() !== $delete_error['errors'], 'delete_backlog_orders must fail when errors returned.' );

$GLOBALS['wdc_cancel_smoke_get_body'] = '[{"id":2285075494,"barcode":"80080822636218"}]';
$GLOBALS['wdc_cancel_smoke_get_calls'] = array();
$search = $otpravka_client->search_backlog_by_barcode( ' 8008 0822636218 ' );
russian_post_cancel_smoke_assert( true === $search['success'] && 2285075494 === (int) ( $search['orders'][0]['id'] ?? 0 ), 'search_backlog_by_barcode must parse array response and id.' );
russian_post_cancel_smoke_assert( str_contains( (string) ( $GLOBALS['wdc_cancel_smoke_last_get']['url'] ?? '' ), '/1.0/backlog/search' ) && str_contains( (string) ( $GLOBALS['wdc_cancel_smoke_last_get']['url'] ?? '' ), '80080822636218' ), 'search_backlog_by_barcode must use backlog search endpoint and normalize barcode.' );

$GLOBALS['wdc_cancel_smoke_get_body'] = '[{"id":2285075495,"barcode":"80080822636219"}]';
$shipment_search = $otpravka_client->search_shipment_by_barcode( ' 8008 0822636219 ' );
russian_post_cancel_smoke_assert( true === $shipment_search['success'] && 2285075495 === (int) ( $shipment_search['orders'][0]['id'] ?? 0 ), 'search_shipment_by_barcode must parse array response and id.' );
russian_post_cancel_smoke_assert( str_contains( (string) ( $GLOBALS['wdc_cancel_smoke_last_get']['url'] ?? '' ), '/1.0/shipment/search' ) && str_contains( (string) ( $GLOBALS['wdc_cancel_smoke_last_get']['url'] ?? '' ), '80080822636219' ), 'search_shipment_by_barcode must use shipment search endpoint and normalize barcode.' );

$missing_order = new RussianPostCancelSmokeOrder( 1, array( OrderShipmentRepository::META_KEY => array( RussianPostDomesticSettings::CARRIER_KEY => array( 'status' => 'created', 'barcode' => '80080822636218' ) ) ) );
$missing_cancel = $backlog_service->cancel_russian_post( $missing_order );
russian_post_cancel_smoke_assert( false === $missing_cancel['success'] && str_contains( $missing_cancel['message'], 'внутреннего ID' ), 'Cancel without backlog_order_id must fail in Russian.' );

$wrong_status_order = new RussianPostCancelSmokeOrder( 2, array( OrderShipmentRepository::META_KEY => array( RussianPostDomesticSettings::CARRIER_KEY => array( 'status' => 'created', 'barcode' => '80080822636218', 'tracking_number' => '80080822636218', 'backlog_order_id' => 2285075494, 'carrier_operation_type_id' => '1' ) ) ) );
$wrong_status_cancel = $backlog_service->cancel_russian_post( $wrong_status_order );
russian_post_cancel_smoke_assert( false === $wrong_status_cancel['success'] && str_contains( $wrong_status_cancel['message'], 'Присвоение идентификатора' ), 'Cancel must be blocked when latest operation is not 28.' );

$cancel_order = new RussianPostCancelSmokeOrder( 3, array( OrderShipmentRepository::META_KEY => array( RussianPostDomesticSettings::CARRIER_KEY => array( 'status' => 'created', 'barcode' => '80080822636218', 'tracking_number' => '80080822636218', 'backlog_order_id' => 2285075494, 'carrier_operation_type_id' => '28', 'carrier_operation_type_name' => 'Присвоение идентификатора' ) ) ) );
$GLOBALS['wdc_cancel_smoke_request_body'] = '{"result-ids":[2285075494],"errors":[]}';
$cancel = $backlog_service->cancel_russian_post( $cancel_order );
$cancel_meta = $cancel_order->meta_snapshot()[ OrderShipmentRepository::META_KEY ] ?? array();
russian_post_cancel_smoke_assert( true === $cancel['success'] && ! isset( $cancel_meta[ RussianPostDomesticSettings::CARRIER_KEY ] ), 'Successful cancel must clear shipment state.' );

$failed_cancel_order = new RussianPostCancelSmokeOrder( 4, array( OrderShipmentRepository::META_KEY => array( RussianPostDomesticSettings::CARRIER_KEY => array( 'status' => 'created', 'barcode' => '80080822636218', 'tracking_number' => '80080822636218', 'backlog_order_id' => 2285075494, 'carrier_operation_type_id' => '28' ) ) ) );
$GLOBALS['wdc_cancel_smoke_request_body'] = '{"result-ids":[],"errors":[{"error-code":"UNDEFINED","error-details":"bad"}]}';
$failed_cancel = $backlog_service->cancel_russian_post( $failed_cancel_order );
$failed_cancel_meta = $failed_cancel_order->meta_snapshot()[ OrderShipmentRepository::META_KEY ][ RussianPostDomesticSettings::CARRIER_KEY ] ?? array();
russian_post_cancel_smoke_assert( false === $failed_cancel['success'] && '80080822636218' === (string) ( $failed_cancel_meta['barcode'] ?? '' ), 'Failed cancel must keep shipment state.' );

$GLOBALS['wdc_cancel_smoke_backlog_get_body'] = '[{"id":2285075494,"barcode":"80080822636218"}]';
$GLOBALS['wdc_cancel_smoke_shipment_get_body'] = '[{"id":999,"barcode":"80080822636218"}]';
$GLOBALS['wdc_cancel_smoke_get_calls'] = array();
$GLOBALS['wdc_cancel_smoke_post_body'] = russian_post_cancel_smoke_envelope();
$attach_order = new RussianPostCancelSmokeOrder( 5 );
$attach = $backlog_service->attach_tracking_number( $attach_order, ' 8008 0822636218 ' );
$attach_state = $attach_order->meta_snapshot()[ OrderShipmentRepository::META_KEY ][ RussianPostDomesticSettings::CARRIER_KEY ] ?? array();
russian_post_cancel_smoke_assert( true === $attach['success'] && '80080822636218' === (string) ( $attach_state['barcode'] ?? '' ), 'Manual attach must normalize and save barcode.' );
russian_post_cancel_smoke_assert( 2285075494 === (int) ( $attach_state['backlog_order_id'] ?? 0 ), 'Manual attach must save backlog_order_id.' );
russian_post_cancel_smoke_assert( 'manual_tracking_attach' === (string) ( $attach_state['source'] ?? '' ), 'Manual attach must mark source.' );
russian_post_cancel_smoke_assert( 'backlog_search' === (string) ( $attach_state['source_lookup'] ?? '' ), 'Manual attach must mark backlog lookup source.' );
russian_post_cancel_smoke_assert( 1 === count( array_filter( $GLOBALS['wdc_cancel_smoke_get_calls'], static fn ( array $call ): bool => str_contains( (string) $call['url'], '/1.0/backlog/search' ) ) ) && 0 === count( array_filter( $GLOBALS['wdc_cancel_smoke_get_calls'], static fn ( array $call ): bool => str_contains( (string) $call['url'], '/1.0/shipment/search' ) ) ), 'Manual attach must not call shipment search when backlog search found a result.' );
russian_post_cancel_smoke_assert( isset( $GLOBALS['wdc_cancel_smoke_last_post'] ), 'Manual attach must attempt automatic status update.' );

$GLOBALS['wdc_cancel_smoke_backlog_get_body'] = '[]';
$GLOBALS['wdc_cancel_smoke_shipment_get_body'] = '[{"id":2285075496,"barcode":"80080822636220"}]';
$GLOBALS['wdc_cancel_smoke_get_calls'] = array();
$shipment_attach_order = new RussianPostCancelSmokeOrder( 6 );
$shipment_attach = $backlog_service->attach_tracking_number( $shipment_attach_order, ' 8008 0822636220 ' );
$shipment_attach_state = $shipment_attach_order->meta_snapshot()[ OrderShipmentRepository::META_KEY ][ RussianPostDomesticSettings::CARRIER_KEY ] ?? array();
russian_post_cancel_smoke_assert( true === $shipment_attach['success'] && '80080822636220' === (string) ( $shipment_attach_state['tracking_number'] ?? '' ), 'Manual attach must save tracking_number when shipment search found a result.' );
russian_post_cancel_smoke_assert( 2285075496 === (int) ( $shipment_attach_state['backlog_order_id'] ?? 0 ) && 'shipment_search' === (string) ( $shipment_attach_state['source_lookup'] ?? '' ), 'Manual attach must save shipment search id and lookup source.' );
russian_post_cancel_smoke_assert( 1 === count( array_filter( $GLOBALS['wdc_cancel_smoke_get_calls'], static fn ( array $call ): bool => str_contains( (string) $call['url'], '/1.0/backlog/search' ) ) ) && 1 === count( array_filter( $GLOBALS['wdc_cancel_smoke_get_calls'], static fn ( array $call ): bool => str_contains( (string) $call['url'], '/1.0/shipment/search' ) ) ), 'Manual attach must call shipment search after empty backlog search.' );

$GLOBALS['wdc_cancel_smoke_backlog_get_body'] = '[]';
$GLOBALS['wdc_cancel_smoke_shipment_get_body'] = '[{"barcode":"80080822636221"}]';
$shipment_attach_without_id_order = new RussianPostCancelSmokeOrder( 7 );
$shipment_attach_without_id = $backlog_service->attach_tracking_number( $shipment_attach_without_id_order, '80080822636221' );
$shipment_attach_without_id_state = $shipment_attach_without_id_order->meta_snapshot()[ OrderShipmentRepository::META_KEY ][ RussianPostDomesticSettings::CARRIER_KEY ] ?? array();
russian_post_cancel_smoke_assert( true === $shipment_attach_without_id['success'] && '80080822636221' === (string) ( $shipment_attach_without_id_state['barcode'] ?? '' ), 'Manual attach must save tracking when shipment search has no id.' );
russian_post_cancel_smoke_assert( ! isset( $shipment_attach_without_id_state['backlog_order_id'] ) && false === $backlog_service->can_cancel( $shipment_attach_without_id_state ), 'Manual attach without shipment id must keep cancel disabled.' );

$GLOBALS['wdc_cancel_smoke_backlog_get_body'] = '[]';
$GLOBALS['wdc_cancel_smoke_shipment_get_body'] = '[]';
$empty_attach = $backlog_service->attach_tracking_number( new RussianPostCancelSmokeOrder( 8 ), '80080822636218' );
russian_post_cancel_smoke_assert( false === $empty_attach['success'] && str_contains( $empty_attach['message'], 'не найдено' ), 'Manual attach empty search must fail in Russian.' );

$GLOBALS['wdc_cancel_smoke_backlog_get_body'] = '[{"id":1},{"id":2}]';
$GLOBALS['wdc_cancel_smoke_shipment_get_body'] = '[]';
$ambiguous_attach = $backlog_service->attach_tracking_number( new RussianPostCancelSmokeOrder( 9 ), '80080822636218' );
russian_post_cancel_smoke_assert( false === $ambiguous_attach['success'] && str_contains( $ambiguous_attach['message'], 'несколько' ), 'Manual attach ambiguous search must fail in Russian.' );

$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.js' );
russian_post_cancel_smoke_assert( ! str_contains( $metabox_source, 'Скачать документы' ), 'Russian Post documents button must be absent.' );
russian_post_cancel_smoke_assert( ! str_contains( $metabox_source, 'Статус WDC' ) && str_contains( $metabox_source, 'Статус посылки' ), 'Metabox must rename WDC status label.' );
russian_post_cancel_smoke_assert( ! str_contains( $metabox_source, '<strong>ШПИ:</strong>' ) && str_contains( $metabox_source, 'Отслеживание' ), 'Metabox must rename tracking label.' );
russian_post_cancel_smoke_assert( ! str_contains( $metabox_source, 'Внести ШПИ вручную' ) && str_contains( $metabox_source, 'Внести отслеживание вручную' ), 'Metabox must rename manual tracking button.' );
russian_post_cancel_smoke_assert( ! str_contains( $metabox_source, 'ШПИ / barcode' ) && str_contains( $metabox_source, 'Номер отслеживания' ), 'Metabox must rename manual tracking input label.' );
russian_post_cancel_smoke_assert( str_contains( $metabox_source, 'data-wdc-copy-tracking' ) && str_contains( $metabox_source, 'aria-label="' ) && str_contains( $metabox_source, 'fa-light fa-copy' ) && str_contains( $js_source, 'copyText' ), 'Metabox must provide accessible icon copy tracking button.' );
russian_post_cancel_smoke_assert( ! str_contains( $js_source, 'ШПИ сохранен' ) && ! str_contains( $js_source, 'Не удалось сохранить ШПИ' ), 'Manual attach JS messages must use tracking number wording.' );
russian_post_cancel_smoke_assert( ! str_contains( $metabox_source, 'data-wdc-status-plugin' ) && ! str_contains( $metabox_source, 'data-wdc-status-barcode' ), 'Status block must not duplicate plugin status or barcode.' );
russian_post_cancel_smoke_assert( ! str_contains( $metabox_source, 'Backlog ID' ) && ! str_contains( $metabox_source, 'Служебные данные' ), 'Backlog ID must not be visible.' );

echo "Russian Post cancel/manual attach smoke OK\n";
