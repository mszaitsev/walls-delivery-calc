<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Admin\OrderShipmentsMetabox;
use WallsShop\WDC\Shipments\Application\RussianPostShipmentActualCostExtractor;
use WallsShop\WDC\Shipments\Application\RussianPostShipmentActualCostLookupService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

defined( 'WDC_SECRET_KEY' ) || define( 'WDC_SECRET_KEY', 'shipment-price-smoke-secret' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public array $settings_rows = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_numeric( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[dfs]/', $value, $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( str_contains( $query, 'wdc_delivery_services' ) ) {
				return array(
					'id' => 1,
					'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
					'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
					'service_type' => 'api',
					'title' => 'Почта России',
					'enabled' => 1,
					'deleted' => 0,
				);
			}

			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) ) {
				return $this->settings_rows;
			}

			return array();
		}
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type = 'mysql' ): string {
		return '2026-06-08 12:00:00';
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['rp_shipment_price_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, mixed $autoload = null ): bool {
		$GLOBALS['rp_shipment_price_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false {
		return json_encode( $value, $flags, $depth );
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( array $args, string $url ): string {
		return $url . '?' . http_build_query( $args );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( mixed $value ): bool {
		return false;
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( string $url, array $args = array() ): array {
		$GLOBALS['rp_shipment_price_remote_get_calls'][] = array( 'url' => $url, 'args' => $args );
		return $GLOBALS['rp_shipment_price_remote_get_response'] ?? array( 'response' => array( 'code' => 200 ), 'body' => '[]' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( mixed $response ): int {
		return (int) ( is_array( $response ) ? ( $response['response']['code'] ?? 0 ) : 0 );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( mixed $response ): string {
		return (string) ( is_array( $response ) ? ( $response['body'] ?? '' ) : '' );
	}
}

function rp_shipment_price_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class RussianPostShipmentPriceOrder {
	public int $save_count = 0;

	public function __construct( private array|string $calculation = array(), private array $meta = array(), private int $id = 1001 ) {
	}

	public function get_id(): int {
		return $this->id;
	}

	public function get_meta( string $key, bool $single = true ): mixed {
		if ( OrderShippingMetaPersister::CALCULATION_META_KEY === $key ) {
			return $this->calculation;
		}

		return $this->meta[ $key ] ?? '';
	}

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function save(): void {
		$this->save_count++;
	}

	public function meta_snapshot(): array {
		return $this->meta;
	}
}

final class RussianPostShipmentPriceAdapter implements CarrierShipmentAdapterInterface {
	public function __construct( private bool $success = true ) {
	}

	public function carrier_key(): string {
		return RussianPostDomesticSettings::CARRIER_KEY;
	}

	public function supports( ShipmentCreateRequest $request ): bool {
		return RussianPostDomesticSettings::CARRIER_KEY === $request->carrier_key;
	}

	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		return array( 'preview' => true );
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		return new ShipmentCreateResult(
			$this->success,
			external_id: '2285075494',
			tracking_number: '80080822636157',
			backlog_order_id: '2285075494',
			raw_reference: array( 'group_name' => '' )
		);
	}

	public function presentation(): array {
		return array( 'carrier_label' => 'Почта России' );
	}

	public function status_payload( object $order, array $shipment ): array {
		return array();
	}

	public function update_status( object $order, string $shipment_key = '' ): array {
		return array( 'success' => false );
	}

	public function attach_manual( object $order, array $payload ): array {
		return array( 'success' => false );
	}

	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array {
		return array( 'success' => false );
	}

	public function remove_from_order( object $order, string $shipment_key = '' ): array {
		return array( 'success' => false );
	}

	public function label_actions( object $order, array $shipment ): array {
		return array();
	}

	public function supports_status_auto_sync(): bool {
		return false;
	}

	public function tracking_identifier( array $shipment ): string {
		return (string) ( $shipment['barcode'] ?? '' );
	}

	public function auto_sync_throttle_microseconds(): int {
		return 0;
	}
}

function rp_shipment_price_request(): ShipmentCreateRequest {
	return new ShipmentCreateRequest(
		order_id: 1001,
		carrier_key: RussianPostDomesticSettings::CARRIER_KEY,
		delivery_type: DeliveryType::COURIER,
		rate_id: RussianPostDomesticSettings::checkout_group_id( DeliveryType::COURIER ),
		recipient_address: new Address( country_code: 'RU', city: 'Новосибирск', postcode: '630099' ),
		pickup_point: null,
		places: array(),
		declared_value: Money::from_kopecks( 0 ),
		meta: array(
			'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
			'order_num' => '1001',
		)
	);
}

function rp_shipment_price_lookup_service(): RussianPostShipmentActualCostLookupService {
	$encryption = new EncryptionService();
	$db = new wpdb();
	$db->settings_rows = array(
		array( 'service_id' => 1, 'setting_key' => RussianPostOtpravkaApiSettings::ACCESS_TOKEN_KEY, 'setting_value' => 'token', 'value_format' => 'string' ),
		array( 'service_id' => 1, 'setting_key' => RussianPostOtpravkaApiSettings::LOGIN_KEY, 'setting_value' => 'login', 'value_format' => 'string' ),
		array( 'service_id' => 1, 'setting_key' => RussianPostOtpravkaApiSettings::PASSWORD_ENCRYPTED_KEY, 'setting_value' => $encryption->encrypt( 'password' ), 'value_format' => 'string' ),
		array( 'service_id' => 1, 'setting_key' => RussianPostOtpravkaApiSettings::TIMEOUT_KEY, 'setting_value' => '30', 'value_format' => 'number' ),
	);
	$settings = new RussianPostOtpravkaApiSettings( new SettingsRepository(), $encryption, new DeliveryServiceRepository( $db ), new DeliveryServiceSettingsRepository( $db ) );
	$client = new RussianPostOtpravkaApiClient( $settings );

	return new RussianPostShipmentActualCostLookupService( $client, new RussianPostShipmentActualCostExtractor() );
}

$extractor = new RussianPostShipmentActualCostExtractor();

$fields = $extractor->fields_from_row(
	array(
		'barcode' => '80080822636157',
		'total-rate-wo-vat' => 32785,
		'total-vat' => 7213,
	),
	'backlog_search'
);
rp_shipment_price_assert( 39998 === (int) ( $fields['russian_post_actual_cost_kopecks'] ?? 0 ), 'backlog/search total-rate-wo-vat + total-vat must be saved as kopecks.' );
rp_shipment_price_assert( 399.98 === (float) ( $fields['russian_post_actual_cost_rub'] ?? 0.0 ), 'Actual Russian Post cost must also be stored in rubles.' );
rp_shipment_price_assert( 'backlog_search' === (string) ( $fields['russian_post_actual_cost_source'] ?? '' ), 'Actual cost source must be backlog_search.' );

$missing = $extractor->fields_from_row( array( 'barcode' => '80080822636157' ), 'backlog_search' );
rp_shipment_price_assert( array() === $missing, 'Missing total-rate-wo-vat / total-vat must not create price fields.' );

$manual_fields = $extractor->fields_from_row( array( 'total-rate-wo-vat' => 32785, 'total-vat' => 7213 ), 'backlog_search' );
rp_shipment_price_assert( 'backlog_search' === (string) ( $manual_fields['russian_post_actual_cost_source'] ?? '' ), 'Manual attach must continue to use backlog_search source.' );

$GLOBALS['rp_shipment_price_remote_get_calls'] = array();
$GLOBALS['rp_shipment_price_remote_get_response'] = array(
	'response' => array( 'code' => 200 ),
	'body' => '[{"barcode":"80080822636157","total-rate-wo-vat":32785,"total-vat":7213}]',
);
$create_order = new RussianPostShipmentPriceOrder();
$lookup_service = rp_shipment_price_lookup_service();
$creation = new ShipmentCreationService( new OrderShipmentRepository(), array( new RussianPostShipmentPriceAdapter() ), null, null, null, array( new RussianPostShipmentPersistenceMapper( $lookup_service ) ) );
$create_result = $creation->create( $create_order, rp_shipment_price_request() );
$created_shipment = $create_order->meta_snapshot()[ OrderShipmentRepository::META_KEY ][ RussianPostDomesticSettings::CARRIER_KEY ] ?? array();
rp_shipment_price_assert( $create_result->success, 'Automatic shipment create must remain successful.' );
rp_shipment_price_assert( 39998 === (int) ( $created_shipment['russian_post_actual_cost_kopecks'] ?? 0 ), 'Automatic create must save actual cost kopecks from backlog/search.' );
rp_shipment_price_assert( 399.98 === (float) ( $created_shipment['russian_post_actual_cost_rub'] ?? 0.0 ), 'Automatic create must save actual cost rubles from backlog/search.' );
rp_shipment_price_assert( 'backlog_search_after_create' === (string) ( $created_shipment['russian_post_actual_cost_source'] ?? '' ), 'Automatic create must use backlog_search_after_create source.' );
rp_shipment_price_assert( 1 === count( $GLOBALS['rp_shipment_price_remote_get_calls'] ), 'Automatic create must call backlog/search once by barcode.' );

$GLOBALS['rp_shipment_price_remote_get_response'] = array( 'response' => array( 'code' => 500 ), 'body' => '{"error":"temporary"}' );
$error_order = new RussianPostShipmentPriceOrder();
$error_result = $creation->create( $error_order, rp_shipment_price_request() );
$error_shipment = $error_order->meta_snapshot()[ OrderShipmentRepository::META_KEY ][ RussianPostDomesticSettings::CARRIER_KEY ] ?? array();
rp_shipment_price_assert( $error_result->success && '80080822636157' === (string) ( $error_shipment['barcode'] ?? '' ), 'Create must stay successful when actual cost lookup fails.' );
rp_shipment_price_assert( ! isset( $error_shipment['russian_post_actual_cost_kopecks'] ), 'Failed actual cost lookup must not save price fields.' );

$GLOBALS['rp_shipment_price_remote_get_response'] = array( 'response' => array( 'code' => 200 ), 'body' => '[{"barcode":"80080822636157"}]' );
$no_cost_order = new RussianPostShipmentPriceOrder();
$no_cost_result = $creation->create( $no_cost_order, rp_shipment_price_request() );
$no_cost_shipment = $no_cost_order->meta_snapshot()[ OrderShipmentRepository::META_KEY ][ RussianPostDomesticSettings::CARRIER_KEY ] ?? array();
rp_shipment_price_assert( $no_cost_result->success && ! isset( $no_cost_shipment['russian_post_actual_cost_kopecks'] ), 'Create must stay successful when backlog/search has no total fields.' );

$status_reflection = new ReflectionClass( ShipmentStatusUpdateService::class );
$status_service = $status_reflection->newInstanceWithoutConstructor();
$shipment = array( 'russian_post_actual_cost_kopecks' => 39998 );

$payload_equal = $status_service->status_payload(
	$shipment,
	new RussianPostShipmentPriceOrder( array( 'api' => array( 'api_base_price_rub' => 399.98 ) ) )
);
rp_shipment_price_assert( '399.98 руб.' === (string) ( $payload_equal['actual_cost_label'] ?? '' ), 'Status payload must format actual cost as rubles.' );
rp_shipment_price_assert( 39998 === (int) ( $payload_equal['base_api_cost_kopecks'] ?? 0 ), 'Base API rubles must be converted to kopecks.' );
rp_shipment_price_assert( 'ok' === (string) ( $payload_equal['actual_cost_compare_status'] ?? '' ), 'Equal actual and base API costs must be ok.' );

$payload_within_three_percent = $status_service->status_payload(
	$shipment,
	new RussianPostShipmentPriceOrder( array( 'api' => array( 'api_base_price_rub' => 390.00 ) ) )
);
rp_shipment_price_assert( 'ok' === (string) ( $payload_within_three_percent['actual_cost_compare_status'] ?? '' ), 'Actual cost within 3% over base API cost must be ok.' );

$payload_warning = $status_service->status_payload(
	$shipment,
	new RussianPostShipmentPriceOrder( array( 'api' => array( 'api_base_price_rub' => 380.00 ) ) )
);
rp_shipment_price_assert( 'warning' === (string) ( $payload_warning['actual_cost_compare_status'] ?? '' ), 'Actual cost more than 3% over base API cost must be warning.' );

$payload_neutral = $status_service->status_payload( $shipment, new RussianPostShipmentPriceOrder() );
rp_shipment_price_assert( 'neutral' === (string) ( $payload_neutral['actual_cost_compare_status'] ?? '' ), 'Missing base API cost must produce neutral compare status.' );

$payload_no_price = $status_service->status_payload( array(), new RussianPostShipmentPriceOrder( array( 'api' => array( 'api_base_price_rub' => 399.98 ) ) ) );
rp_shipment_price_assert( '' === (string) ( $payload_no_price['actual_cost_label'] ?? '' ), 'Missing actual cost must not show a price label.' );
rp_shipment_price_assert( '' === (string) ( $payload_no_price['actual_cost_compare_status'] ?? '' ), 'Missing actual cost must not set a compare status.' );

$payload_json_meta = $status_service->status_payload(
	$shipment,
	new RussianPostShipmentPriceOrder( '{"api":{"api_base_price_kopecks":39000}}' )
);
rp_shipment_price_assert( 'ok' === (string) ( $payload_json_meta['actual_cost_compare_status'] ?? '' ), 'JSON calculation meta with kopecks must be supported.' );

$metabox_reflection = new ReflectionClass( OrderShipmentsMetabox::class );
$metabox = $metabox_reflection->newInstanceWithoutConstructor();
$price_class = $metabox_reflection->getMethod( 'shipment_price_class' );
$price_class->setAccessible( true );
rp_shipment_price_assert( 'wdc-shipment-price-ok' === $price_class->invoke( $metabox, 'ok' ), 'Metabox must expose ok price class.' );
rp_shipment_price_assert( 'wdc-shipment-price-warning' === $price_class->invoke( $metabox, 'warning' ), 'Metabox must expose warning price class.' );
rp_shipment_price_assert( 'wdc-shipment-price-neutral' === $price_class->invoke( $metabox, 'neutral' ), 'Metabox must expose neutral price class.' );

$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.js' );
rp_shipment_price_assert( is_string( $js ) && str_contains( $js, 'actual_cost_label' ), 'Shipments admin JS must render actual cost from status payload.' );
$css = file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.css' );
rp_shipment_price_assert( is_string( $css ) && str_contains( $css, 'wdc-shipment-price-warning' ), 'Shipments admin CSS must include price warning class.' );

echo "Russian Post shipment price smoke OK\n";
