<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-js-bundle-source.php';

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\Tracking\RussianPostTrackingApiClient;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Admin\OrderShipmentsMetabox;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;
use WallsShop\WDC\Shipments\RussianPost\RussianPostTrackingStatusMapper;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );
defined( 'AUTH_KEY' ) || define( 'AUTH_KEY', 'shipment-status-smoke-auth-key' );
defined( 'SECURE_AUTH_KEY' ) || define( 'SECURE_AUTH_KEY', 'shipment-status-smoke-secure-auth-key' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function shipment_status_smoke_assert( bool $condition, string $message ): void {
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
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string { return '2026-06-06 12:34:56'; }
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( string $scheme = 'auth' ): string { return 'shipment-status-smoke-' . $scheme; }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value ) ?? '' ); }
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed { return $value; }
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool { return (bool) ( $GLOBALS['wdc_status_smoke_can'] ?? true ); }
}
if ( ! function_exists( 'check_ajax_referer' ) ) {
	function check_ajax_referer( string $action, mixed $query_arg = false, bool $stop = true ): bool { return (bool) ( $GLOBALS['wdc_status_smoke_nonce'] ?? true ); }
}
if ( ! function_exists( 'wp_remote_post' ) ) {
	function wp_remote_post( string $url, array $args = array() ): array {
		$GLOBALS['wdc_status_smoke_last_http_args'] = $args;
		return array(
			'response' => array( 'code' => (int) ( $GLOBALS['wdc_status_smoke_http_code'] ?? 200 ) ),
			'body' => (string) ( $GLOBALS['wdc_status_smoke_http_body'] ?? '' ),
		);
	}
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
if ( ! function_exists( 'wc_get_order' ) ) {
	function wc_get_order( int $order_id ): ?object { return $GLOBALS['wdc_status_smoke_orders'][ $order_id ] ?? null; }
}
if ( ! function_exists( 'wp_send_json_success' ) ) {
	function wp_send_json_success( mixed $data = null, int $status_code = 200 ): never { throw new ShipmentStatusAjaxResponse( true, $data, $status_code ); }
}
if ( ! function_exists( 'wp_send_json_error' ) ) {
	function wp_send_json_error( mixed $data = null, int $status_code = 400 ): never { throw new ShipmentStatusAjaxResponse( false, $data, $status_code ); }
}

final class ShipmentStatusAjaxResponse extends RuntimeException {
	public function __construct( public bool $success, public mixed $data, public int $status_code ) {
		parent::__construct( 'ajax response' );
	}
}

final class ShipmentStatusSmokeAdapter implements CarrierShipmentAdapterInterface {
	public function __construct( private ShipmentStatusUpdateService $status_updates ) {
	}

	public function carrier_key(): string { return RussianPostDomesticSettings::CARRIER_KEY; }
	public function supports( ShipmentCreateRequest $request ): bool { return RussianPostDomesticSettings::CARRIER_KEY === $request->carrier_key; }
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array { return array(); }
	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult { return new ShipmentCreateResult( false, error_code: 'not-supported', error_message: 'Not supported in smoke.' ); }
	public function presentation(): array { return array(); }
	public function status_payload( object $order, array $shipment ): array { return $this->status_updates->status_payload( $shipment, $order ); }
	public function update_status( object $order, string $shipment_key = '' ): array { return $this->status_updates->update_russian_post( $order, $shipment_key ?: RussianPostDomesticSettings::CARRIER_KEY ); }
	public function attach_manual( object $order, array $payload ): array { return array( 'success' => false ); }
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array { return array( 'success' => false ); }
	public function remove_from_order( object $order, string $shipment_key = '' ): array { return array( 'success' => false ); }
	public function label_actions( object $order, array $shipment ): array { return array(); }
	public function supports_status_auto_sync(): bool { return false; }
	public function tracking_identifier( array $shipment ): string { return (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ); }
	public function auto_sync_throttle_microseconds(): int { return 0; }
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
				foreach ( $this->services as $row ) {
					if ( str_contains( $query, "'" . (string) $row['service_key'] . "'" ) ) {
						return $row;
					}
				}
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
				return array_values( array_filter( $this->settings, static fn ( array $row ): bool => str_contains( $query, 'service_id = ' . (int) $row['service_id'] ) ) );
			}

			return array();
		}

		public function get_var( string $query ): mixed { return null; }
		public function insert( string $table, array $data, array $format = array() ): bool { return true; }
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool { return true; }
	}
}

final class ShipmentStatusSmokeOrder {
	public array $notes = array();

	public function __construct( private int $id, private array $meta ) {
	}

	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
	public function add_order_note( string $message ): void { $this->notes[] = $message; }
	public function meta_snapshot(): array { return $this->meta; }
}

function shipment_status_smoke_envelope( string $records ): string {
	return '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><ns2:getOperationHistoryResponse xmlns:ns2="http://russianpost.org/operationhistory"><OperationHistoryData>' . $records . '</OperationHistoryData></ns2:getOperationHistoryResponse></soap:Body></soap:Envelope>';
}

function shipment_status_smoke_record( string $date, string $type_id, string $type_name, string $attr_id, string $attr_name, string $index = '630001', string $address = 'Новосибирск' ): string {
	return '<historyRecord><OperationParameters><OperDate>' . $date . '</OperDate><OperType><Id>' . $type_id . '</Id><Name>' . $type_name . '</Name></OperType><OperAttr><Id>' . $attr_id . '</Id><Name>' . $attr_name . '</Name></OperAttr></OperationParameters><AddressParameters><OperationAddress><Index>' . $index . '</Index><Description>' . $address . '</Description></OperationAddress></AddressParameters></historyRecord>';
}

$wpdb = new wpdb();
$wpdb->services[] = array(
	'id' => 1,
	'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
	'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
	'service_type' => 'api',
	'title' => 'Почта России',
	'enabled' => 1,
	'deleted' => 0,
);
$encryption = new EncryptionService();
$wpdb->settings[] = array( 'service_id' => 1, 'setting_key' => RussianPostOtpravkaApiSettings::TRACKING_LOGIN_KEY, 'setting_value' => 'tracking-login', 'value_format' => 'string' );
$wpdb->settings[] = array( 'service_id' => 1, 'setting_key' => RussianPostOtpravkaApiSettings::TRACKING_PASSWORD_ENCRYPTED_KEY, 'setting_value' => $encryption->encrypt( 'tracking-password' ), 'value_format' => 'string' );
$wpdb->settings[] = array( 'service_id' => 1, 'setting_key' => RussianPostOtpravkaApiSettings::TIMEOUT_KEY, 'setting_value' => '30', 'value_format' => 'number' );

$settings = new RussianPostOtpravkaApiSettings( new SettingsRepository(), $encryption, new DeliveryServiceRepository( $wpdb ), new DeliveryServiceSettingsRepository( $wpdb ) );
$client = new RussianPostTrackingApiClient( $settings );
$mapper = new RussianPostTrackingStatusMapper();

$universal_statuses = DeliveryStatus::all();
shipment_status_smoke_assert( DeliveryStatus::PENDING_CREATION_IN_CARRIER === $universal_statuses[0], 'pending_creation_in_carrier must be the first universal shipment status.' );
shipment_status_smoke_assert( DeliveryStatus::CREATED_IN_CARRIER === $universal_statuses[1], 'created_in_carrier must follow pending_creation_in_carrier.' );
shipment_status_smoke_assert( 'Попытка создания в ТК' === DeliveryStatus::label( DeliveryStatus::PENDING_CREATION_IN_CARRIER ), 'pending_creation_in_carrier label must be available.' );
$known = $mapper->map_record(
	array(
		'operation_type_id' => '2',
		'operation_type_name' => 'Вручение',
		'operation_attr_id' => '1',
		'operation_attr_name' => 'Вручение адресату',
		'oper_date' => '2026-06-06T10:00:00+07:00',
	)
);
shipment_status_smoke_assert( DeliveryStatus::DELIVERED === $known['universal_status_code'], 'Known Excel pair 2:1 must map to delivered.' );
shipment_status_smoke_assert( 'доставлен' === $known['universal_status_label'], 'Known Excel pair 2:1 must expose Russian label.' );
shipment_status_smoke_assert( true === $known['carrier_status_is_terminal'], 'Known Excel pair 2:1 must read terminal flag.' );

$unknown = $mapper->map_record( array( 'operation_type_id' => '999', 'operation_attr_id' => '999', 'operation_type_name' => 'Новая операция', 'operation_attr_name' => 'Новый атрибут' ) );
shipment_status_smoke_assert( DeliveryStatus::UNKNOWN === $unknown['universal_status_code'], 'Unknown pair must map to unknown.' );
shipment_status_smoke_assert( 'не определён' === $unknown['universal_status_label'], 'Unknown pair must expose Russian unknown label.' );
shipment_status_smoke_assert( 'Новая операция — Новый атрибут' === $unknown['carrier_status_title'], 'Unknown pair must keep carrier raw status.' );

$mapping_cases = array(
	'8:2' => array( DeliveryStatus::READY_FOR_PICKUP, 'ожидает самовывоза из ПВЗ/постамата' ),
	'8:9' => array( DeliveryStatus::READY_FOR_PICKUP, 'ожидает самовывоза из ПВЗ/постамата' ),
	'8:59' => array( DeliveryStatus::READY_FOR_PICKUP, 'ожидает самовывоза из ПВЗ/постамата' ),
	'12:1' => array( DeliveryStatus::READY_FOR_PICKUP, 'ожидает самовывоза из ПВЗ/постамата' ),
	'12:31' => array( DeliveryStatus::READY_FOR_PICKUP, 'ожидает самовывоза из ПВЗ/постамата' ),
	'42:1' => array( DeliveryStatus::READY_FOR_PICKUP, 'ожидает самовывоза из ПВЗ/постамата' ),
	'42:30' => array( DeliveryStatus::READY_FOR_PICKUP, 'ожидает самовывоза из ПВЗ/постамата' ),
	'8:15' => array( DeliveryStatus::HANDED_TO_COURIER, 'передан курьеру' ),
	'8:18' => array( DeliveryStatus::HANDED_TO_COURIER, 'передан курьеру' ),
	'28:0' => array( DeliveryStatus::CREATED_IN_CARRIER, 'создан в ТК' ),
	'28:' => array( DeliveryStatus::CREATED_IN_CARRIER, 'создан в ТК' ),
	'28:-' => array( DeliveryStatus::CREATED_IN_CARRIER, 'создан в ТК' ),
	'46:0' => array( DeliveryStatus::CANCELLED, 'отменён' ),
	'46:' => array( DeliveryStatus::CANCELLED, 'отменён' ),
	'999:999' => array( DeliveryStatus::UNKNOWN, 'не определён' ),
	'999:' => array( DeliveryStatus::UNKNOWN, 'не определён' ),
);
foreach ( $mapping_cases as $pair => $expected ) {
	[ $operation_type_id, $operation_attr_id ] = explode( ':', $pair, 2 );
	$mapped = $mapper->map_record(
		array(
			'operation_type_id' => $operation_type_id,
			'operation_type_name' => 'Тестовая операция',
			'operation_attr_id' => $operation_attr_id,
			'operation_attr_name' => 'Тестовый атрибут',
		)
	);
	shipment_status_smoke_assert( $expected[0] === $mapped['universal_status_code'], $pair . ' must map to ' . $expected[0] . '.' );
	shipment_status_smoke_assert( $expected[1] === $mapped['universal_status_label'], $pair . ' must expose label ' . $expected[1] . '.' );
	shipment_status_smoke_assert( false === $mapped['carrier_status_is_terminal'], $pair . ' must stay non-terminal.' );
}

$GLOBALS['wdc_status_smoke_http_body'] = shipment_status_smoke_envelope(
	shipment_status_smoke_record( '2026-06-05T10:00:00+07:00', '1', 'Прием', '1', 'Единичный' )
	. shipment_status_smoke_record( '2026-06-06T10:00:00+07:00', '2', 'Вручение', '1', 'Вручение адресату' )
);
$history = $client->get_operation_history( '12345678901234' );
shipment_status_smoke_assert( true === $history['success'], 'Successful SOAP response must pass.' );
shipment_status_smoke_assert( 2 === count( $history['records'] ), 'Successful SOAP response must parse all historyRecord nodes.' );
shipment_status_smoke_assert( '2' === $history['latest_record']['operation_type_id'], 'Latest record must be selected by OperDate.' );
shipment_status_smoke_assert( ! str_contains( (string) ( $GLOBALS['wdc_status_smoke_last_http_args']['body'] ?? '' ), 'AccessToken' ), 'Tracking client must not use Otpravka AccessToken.' );

$GLOBALS['wdc_status_smoke_http_body'] = '<?xml version="1.0" encoding="UTF-8"?><soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope"><soap:Body><soap:Fault><soap:Reason><soap:Text>bad auth</soap:Text></soap:Reason></soap:Fault></soap:Body></soap:Envelope>';
$fault = $client->get_operation_history( '12345678901234' );
shipment_status_smoke_assert( false === $fault['success'] && str_contains( $fault['error_message'], 'SOAP Fault' ), 'SOAP fault must return Russian SOAP Fault error.' );

$GLOBALS['wdc_status_smoke_http_body'] = shipment_status_smoke_envelope( '' );
$empty = $client->get_operation_history( '12345678901234' );
shipment_status_smoke_assert( false === $empty['success'] && 'Почта России вернула пустую историю операций.' === $empty['error_message'], 'Empty history must return Russian empty-history error.' );

$repository = new OrderShipmentRepository();
$status_service = new ShipmentStatusUpdateService( $repository, $client, $mapper );
$no_barcode_order = new ShipmentStatusSmokeOrder( 10, array( OrderShipmentRepository::META_KEY => array( RussianPostDomesticSettings::CARRIER_KEY => array( 'status' => 'created' ) ) ) );
$no_barcode = $status_service->update_russian_post( $no_barcode_order );
shipment_status_smoke_assert( false === $no_barcode['success'] && 'У отправления нет ШПИ.' === $no_barcode['message'], 'Shipment without barcode must fail.' );

$order = new ShipmentStatusSmokeOrder(
	11,
	array(
		OrderShipmentRepository::META_KEY => array(
			RussianPostDomesticSettings::CARRIER_KEY => array(
				'status' => 'created',
				'tracking_number' => '12345678901234',
				'barcode' => '12345678901234',
			),
		),
	)
);
$GLOBALS['wdc_status_smoke_http_body'] = shipment_status_smoke_envelope( shipment_status_smoke_record( '2026-06-06T10:00:00+07:00', '2', 'Вручение', '1', 'Вручение адресату' ) );
$updated = $status_service->update_russian_post( $order );
$saved = $order->meta_snapshot()[ OrderShipmentRepository::META_KEY ][ RussianPostDomesticSettings::CARRIER_KEY ];
shipment_status_smoke_assert( true === $updated['success'], 'Known latest operation must update shipment.' );
shipment_status_smoke_assert( DeliveryStatus::DELIVERED === $saved['universal_status_code'], 'Service must save universal_status_code.' );
shipment_status_smoke_assert( 'Вручение — Вручение адресату' === $saved['carrier_status_title'], 'Service must save carrier status title.' );
shipment_status_smoke_assert( 'доставлен' === $updated['status']['shipment_status_label'], 'Service UI payload must expose Russian universal label.' );
shipment_status_smoke_assert( '' !== (string) ( $saved['tracking_checked_at'] ?? '' ) && 1 === preg_match( '/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/', (string) $saved['tracking_checked_at'] ), 'tracking_checked_at must be stored in Y-m-d H:i:s format.' );
shipment_status_smoke_assert( (string) $saved['tracking_checked_at'] === (string) ( $updated['status']['tracking_checked_at'] ?? '' ), 'Status payload must expose saved tracking_checked_at.' );
shipment_status_smoke_assert( '2026-06-06T10:00:00+07:00' === (string) ( $saved['carrier_operation_date'] ?? '' ) && '2026-06-06T10:00:00+07:00' === (string) ( $updated['status']['carrier_operation_date'] ?? '' ), 'carrier_operation_date must stay unchanged from Russian Post API.' );
shipment_status_smoke_assert( ! array_key_exists( 'backlog_order_id', $updated['status'] ), 'Status update payload must not expose backlog_order_id.' );
shipment_status_smoke_assert( ! array_key_exists( 'russian_post_tracking_login', $saved ) && ! array_key_exists( 'russian_post_tracking_password_encrypted', $saved ), 'Credentials must not be saved in order meta.' );
shipment_status_smoke_assert( array() === $order->notes, 'Successful shipment status refresh must not add an order note.' );

$backlog_status_order = new ShipmentStatusSmokeOrder(
	15,
	array(
		OrderShipmentRepository::META_KEY => array(
			RussianPostDomesticSettings::CARRIER_KEY => array(
				'status' => 'created',
				'tracking_number' => '12345678901234',
				'barcode' => '12345678901234',
				'backlog_order_id' => 2285075494,
			),
		),
	)
);
$GLOBALS['wdc_status_smoke_http_body'] = shipment_status_smoke_envelope( shipment_status_smoke_record( '2026-06-06T10:00:00+07:00', '28', 'Присвоение идентификатора', '0', '' ) );
$backlog_status = $status_service->update_russian_post( $backlog_status_order );
$backlog_status_saved = $backlog_status_order->meta_snapshot()[ OrderShipmentRepository::META_KEY ][ RussianPostDomesticSettings::CARRIER_KEY ];
shipment_status_smoke_assert( true === $backlog_status['success'], 'Status update must keep working when backlog_order_id exists in shipment state.' );
shipment_status_smoke_assert( '12345678901234' === (string) ( $backlog_status['status']['barcode'] ?? '' ), 'Status update payload must continue using barcode.' );
shipment_status_smoke_assert( 2285075494 === (int) ( $backlog_status_saved['backlog_order_id'] ?? 0 ), 'Status update must preserve backlog_order_id in shipment state.' );
shipment_status_smoke_assert( ! array_key_exists( 'backlog_order_id', $backlog_status['status'] ), 'Status update AJAX payload must not include backlog_order_id.' );

$GLOBALS['wdc_status_smoke_http_body'] = shipment_status_smoke_envelope( shipment_status_smoke_record( '2026-06-06T10:00:00+07:00', '999', 'Новая операция', '999', 'Новый атрибут' ) );
$unknown_order = new ShipmentStatusSmokeOrder( 12, array( OrderShipmentRepository::META_KEY => array( RussianPostDomesticSettings::CARRIER_KEY => array( 'status' => 'created', 'tracking_number' => '12345678901234' ) ) ) );
$status_service->update_russian_post( $unknown_order );
$unknown_saved = $unknown_order->meta_snapshot()[ OrderShipmentRepository::META_KEY ][ RussianPostDomesticSettings::CARRIER_KEY ];
shipment_status_smoke_assert( DeliveryStatus::UNKNOWN === $unknown_saved['universal_status_code'], 'Unknown latest operation must save unknown.' );
shipment_status_smoke_assert( 'Новая операция — Новый атрибут' === $unknown_saved['carrier_status_title'], 'Unknown latest operation must keep carrier status.' );

$GLOBALS['wdc_status_smoke_http_body'] = shipment_status_smoke_envelope( shipment_status_smoke_record( '2026-06-06T10:00:00+07:00', '28', 'Присвоение идентификатора', '0', '' ) );
$created_in_carrier_order = new ShipmentStatusSmokeOrder( 13, array( OrderShipmentRepository::META_KEY => array( RussianPostDomesticSettings::CARRIER_KEY => array( 'status' => 'created', 'tracking_number' => '12345678901234' ) ) ) );
$created_in_carrier = $status_service->update_russian_post( $created_in_carrier_order );
$created_in_carrier_saved = $created_in_carrier_order->meta_snapshot()[ OrderShipmentRepository::META_KEY ][ RussianPostDomesticSettings::CARRIER_KEY ];
shipment_status_smoke_assert( DeliveryStatus::CREATED_IN_CARRIER === $created_in_carrier_saved['universal_status_code'], 'Operation 28 without attr must save created_in_carrier.' );
shipment_status_smoke_assert( 'создан в ТК' === $created_in_carrier['status']['shipment_status_label'], 'Operation 28 without attr must expose Russian created label.' );

$GLOBALS['wdc_status_smoke_http_body'] = shipment_status_smoke_envelope( shipment_status_smoke_record( '2026-06-06T10:00:00+07:00', '46', 'Отмена присвоения идентификатора', '0', '' ) );
$cancelled_order = new ShipmentStatusSmokeOrder( 14, array( OrderShipmentRepository::META_KEY => array( RussianPostDomesticSettings::CARRIER_KEY => array( 'status' => 'created', 'tracking_number' => '12345678901234' ) ) ) );
$cancelled = $status_service->update_russian_post( $cancelled_order );
$cancelled_saved = $cancelled_order->meta_snapshot()[ OrderShipmentRepository::META_KEY ][ RussianPostDomesticSettings::CARRIER_KEY ];
shipment_status_smoke_assert( DeliveryStatus::CANCELLED === $cancelled_saved['universal_status_code'], 'Operation 46 without attr must save cancelled.' );
shipment_status_smoke_assert( 'отменён' === $cancelled['status']['shipment_status_label'], 'Operation 46 without attr must expose Russian cancelled label.' );

$GLOBALS['wdc_status_smoke_orders'] = array( 11 => $order );
$metabox = new OrderShipmentsMetabox(
	$repository,
	( new ReflectionClass( OrderShipmentDraftFactory::class ) )->newInstanceWithoutConstructor(),
	( new ReflectionClass( ShipmentCreationService::class ) )->newInstanceWithoutConstructor(),
	new DeliveryServiceRepository( $wpdb ),
	$status_service,
	carrier_adapters: new CarrierShipmentAdapterRegistry( array( new ShipmentStatusSmokeAdapter( $status_service ) ) )
);
$_POST = array( 'order_id' => 11, 'shipment_key' => RussianPostDomesticSettings::CARRIER_KEY );
$GLOBALS['wdc_status_smoke_can'] = false;
try {
	$metabox->ajax_update_status();
	shipment_status_smoke_assert( false, 'AJAX without capability must be rejected.' );
} catch ( ShipmentStatusAjaxResponse $response ) {
	shipment_status_smoke_assert( false === $response->success && 403 === $response->status_code, 'AJAX without capability must return 403.' );
}
$GLOBALS['wdc_status_smoke_can'] = true;
$GLOBALS['wdc_status_smoke_nonce'] = true;
$GLOBALS['wdc_status_smoke_http_body'] = shipment_status_smoke_envelope( shipment_status_smoke_record( '2026-06-06T10:00:00+07:00', '2', 'Вручение', '1', 'Вручение адресату' ) );
try {
	$metabox->ajax_update_status();
	shipment_status_smoke_assert( false, 'Valid AJAX must return JSON success.' );
} catch ( ShipmentStatusAjaxResponse $response ) {
	shipment_status_smoke_assert( true === $response->success, 'Valid AJAX must succeed: ' . json_encode( $response->data, JSON_UNESCAPED_UNICODE ) );
	shipment_status_smoke_assert( isset( $response->data['status']['universal_status_label'] ), 'Valid AJAX must return status payload for JS.' );
}

$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$status_service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/ShipmentStatusUpdateService.php' );
$js_source = wdc_shipment_admin_js_bundle_source();
$adapter_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/RussianPost/RussianPostShipmentAdapter.php' );
shipment_status_smoke_assert( str_contains( $status_service_source, 'Asia/Novosibirsk' ) && str_contains( $status_service_source, '7 * $hour' ), 'tracking_checked_at helper must use Asia/Novosibirsk with a GMT+7 fallback.' );
shipment_status_smoke_assert( ! str_contains( $status_service_source, 'Статус отправления Почты России обновлен' ) && ! str_contains( implode( "\n", $order->notes ), 'Статус отправления Почты России обновлен' ), 'Shipment status update must not use the old Russian Post status refresh order note.' );
shipment_status_smoke_assert( str_contains( $metabox_source, 'data-wdc-update-shipment-status' ) && str_contains( $metabox_source, "\$show_update = ! empty( \$button_policy['show_update'] )" ) && str_contains( $js_source, 'setVisible(updateButton, canUpdate)' ), 'Metabox update button must be visible only when carrier status updates are allowed.' );
shipment_status_smoke_assert( ! str_contains( $metabox_source, 'data-wdc-status-plugin' ) && str_contains( $metabox_source, 'data-wdc-status-carrier' ), 'Metabox status block must render carrier status without duplicating plugin status.' );
shipment_status_smoke_assert( ! str_contains( $metabox_source, 'Result ID' ) && ! str_contains( $js_source, 'Result ID' ), 'Result ID must not be shown in metabox or JS create result.' );
shipment_status_smoke_assert( ! str_contains( $metabox_source, 'Backlog ID' ) && str_contains( $metabox_source, 'data-wdc-backlog-order-id' ), 'Metabox must keep backlog_order_id hidden.' );
shipment_status_smoke_assert( str_contains( $js_source, 'renderShipmentTechnicalInfo' ) && str_contains( $js_source, 'data-wdc-backlog-order-id' ), 'Admin JS must update hidden backlog id after shipment create.' );
shipment_status_smoke_assert( str_contains( $js_source, 'renderShipmentStatus(box, statusPayload' ) && str_contains( $js_source, 'setTrackingDisplay(box, trackingPresentation(status' ) && ! str_contains( $js_source, "' Backlog ID: '" ), 'Shipment create flow must update tracking display and not show backlog_order_id in toast.' );
shipment_status_smoke_assert( ! str_contains( $adapter_source, "'result_ids'" ), 'Adapter success raw reference must not save result-id list in shipment state.' );
shipment_status_smoke_assert( str_contains( $metabox_source, 'shipment_status_label' ) && str_contains( $metabox_source, 'создано' ) && str_contains( $metabox_source, 'не определено' ), 'Metabox must expose Russian shipment status labels.' );
shipment_status_smoke_assert( str_contains( $js_source, 'renderShipmentStatus' ) && str_contains( $js_source, 'updateStatusAction' ), 'Admin JS must update status block from AJAX response.' );
shipment_status_smoke_assert( str_contains( $js_source, 'showShipmentToast' ) && str_contains( $js_source, '10000' ), 'Admin JS must show auto-hiding shipment toast.' );
shipment_status_smoke_assert( str_contains( $js_source, 'modal.hidden = true' ), 'Admin JS must close shipment modal after successful create.' );
shipment_status_smoke_assert( str_contains( $js_source, 'requestShipmentStatus(updateButton, { auto: true })' ), 'Admin JS must trigger automatic first status update after create.' );
shipment_status_smoke_assert( str_contains( $js_source, 'Статус пока не обновлен:' ), 'Admin JS must warn when automatic first status update fails.' );

echo "Shipment status smoke test passed.\n";
