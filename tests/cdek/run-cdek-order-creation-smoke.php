<?php
declare(strict_types=1);

require_once dirname( __DIR__ ) . '/shipments/admin-js-bundle-source.php';

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiResponse;
use WallsShop\WDC\Carriers\Cdek\Api\CdekHttpClientInterface;
use WallsShop\WDC\Carriers\Cdek\Api\CdekOAuthTokenService;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\CdekLocationResolver;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffRepository;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\Tracking\RussianPostTrackingApiClient;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Pickup\PickupPointSelection;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAdminCarrierUiPayloadBuilder;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentActualCostAjaxController;
use WallsShop\WDC\Shipments\Admin\Ajax\ShipmentCreateAjaxController;
use WallsShop\WDC\Shipments\Admin\OrderShipmentsMetabox;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Cdek\CdekBarcodePrintService;
use WallsShop\WDC\Shipments\Cdek\CdekCreateRequestBuilder;
use WallsShop\WDC\Shipments\Cdek\CdekOrderStatusService;
use WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentAdapter;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentModalExtension;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentPersistenceMapper;
use WallsShop\WDC\Shipments\Cdek\CdekStatusMappingService;
use WallsShop\WDC\Shipments\Modal\ShipmentModalExtensionRegistry;
use WallsShop\WDC\Shipments\Presentation\ShipmentActualCostComparisonService;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\RussianPost\RussianPostTrackingStatusMapper;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function cdek_order_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function cdek_order_actual_cost_resolver(): ShipmentActualCostResolver {
	return new ShipmentActualCostResolver( new ShipmentActualCostComparisonService(), new ShipmentBaseApiCostResolver() );
}

function cdek_order_actual_cost_service( OrderShipmentRepository $repository ): ShipmentActualCostService {
	return new ShipmentActualCostService( $repository, cdek_order_actual_cost_resolver() );
}

function cdek_order_creation_service( OrderShipmentRepository $repository, CdekShipmentAdapter $adapter ): ShipmentCreationService {
	return new ShipmentCreationService( $repository, array( $adapter ), cdek_order_actual_cost_service( $repository ), null, null, array( new CdekShipmentPersistenceMapper() ) );
}

function cdek_order_status_service( OrderShipmentRepository $repository, CdekApiClient $client, ?CdekStatusMappingService $status_mapping = null ): CdekOrderStatusService {
	return new CdekOrderStatusService( $repository, $client, cdek_order_actual_cost_resolver(), cdek_order_actual_cost_service( $repository ), null, $status_mapping );
}

function cdek_order_item_row( string $item_key, int $place_number, string $name, string $sku, int $amount, int $unit_kopecks, int $weight, ?int $assessed_kopecks = null ): array {
	return array(
		'item_key' => $item_key,
		'ordered_quantity' => $amount,
		'place_number' => $place_number,
		'name' => $name,
		'sku' => $sku,
		'amount' => $amount,
		'unit_price_kopecks' => $unit_kopecks,
		'assessed_unit_price_kopecks' => $assessed_kopecks ?? $unit_kopecks,
		'weight' => $weight,
	);
}

function current_time( string $type ): string { return '2026-06-13 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'cdek-order-smoke-' . $scheme; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_cdek_order_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_cdek_order_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_cdek_order_options'][ $key ] ); return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_cdek_order_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { $GLOBALS['wdc_cdek_order_transients'][ $key ] = $value; return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_cdek_order_transients'][ $key ] ); return true; }
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( mixed $value ): string { return filter_var( trim( (string) $value ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $value ) : ''; }
function wp_unslash( mixed $value ): mixed { return $value; }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function esc_attr__( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_url( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_textarea( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
	$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}
function disabled( mixed $disabled, mixed $current = true, bool $display = true ): string {
	$result = (bool) $disabled === (bool) $current ? ' disabled="disabled"' : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}
function current_user_can( string $capability ): bool { return true; }
function check_ajax_referer( string $action, string|bool $query_arg = false, bool $stop = true ): bool { return true; }
function wc_get_dimension( mixed $dimension, string $to_unit ): float { return (float) str_replace( ',', '.', (string) $dimension ); }
function wc_get_weight( mixed $weight, string $to_unit ): float { return 'g' === $to_unit ? (float) str_replace( ',', '.', (string) $weight ) * 1000 : (float) str_replace( ',', '.', (string) $weight ); }
function wc_get_order( int $order_id ): ?object { return $GLOBALS['wdc_cdek_order_ajax_order'] ?? null; }
function wp_send_json_success( mixed $data = null, int $status_code = 200, int $flags = 0 ): never { throw new CdekOrderAjaxResponse( true, $data, $status_code ); }
function wp_send_json_error( mixed $data = null, int $status_code = 400, int $flags = 0 ): never { throw new CdekOrderAjaxResponse( false, $data, $status_code ); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $cdek_tariffs = array();
	}
}

final class CdekOrderAjaxResponse extends RuntimeException {
	public function __construct(
		public bool $success,
		public mixed $data,
		public int $status_code
	) {
		parent::__construct( 'ajax response' );
	}
}

final class CdekOrderFakeHttp implements CdekHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();
	/** @var array<int,array<string,mixed>> */
	public array $post_responses = array();
	/** @var array<int,array<string,mixed>> */
	public array $order_responses = array();
	/** @var array<int,array<string,mixed>> */
	public array $delete_responses = array();
	/** @var array<int,array<string,mixed>> */
	public array $city_responses = array();
	/** @var array<int,array<string,mixed>> */
	public array $barcode_create_responses = array();
	/** @var array<int,array<string,mixed>> */
	public array $barcode_status_responses = array();
	/** @var array<int,string|array<string,mixed>> */
	public array $barcode_pdf_responses = array();

	public function request( string $method, string $url, array $args = array() ): CdekApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		if ( str_contains( $url, '/v2/oauth/token' ) ) {
			return new CdekApiResponse( 200, json_encode( array( 'access_token' => 'token', 'expires_in' => 3600 ) ) ?: '{}' );
		}
		if ( 'POST' === $method && str_contains( $url, '/v2/orders' ) ) {
			$response = array_shift( $this->post_responses ) ?: array(
				'entity' => array( 'uuid' => 'order-uuid-1', 'recipient' => array( 'name' => 'Иван Иванов', 'email' => 'buyer@example.com' ) ),
				'requests' => array( array( 'request_uuid' => 'request-uuid-1', 'state' => 'ACCEPTED' ) ),
			);
			return new CdekApiResponse( 202, json_encode( $response ) ?: '{}' );
		}
		if ( 'DELETE' === $method && str_contains( $url, '/v2/orders/' ) ) {
			$response = array_shift( $this->delete_responses ) ?: array(
				'entity' => array( 'uuid' => 'deleted-uuid' ),
				'requests' => array( array( 'request_uuid' => 'delete-request-uuid', 'state' => 'ACCEPTED' ) ),
			);
			return new CdekApiResponse( 202, json_encode( $response ) ?: '{}' );
		}
		if ( 'GET' === $method && str_contains( $url, '/v2/location/cities' ) ) {
			$response = array() !== $this->city_responses ? array_shift( $this->city_responses ) : array( array( 'code' => 44, 'city' => 'Москва' ) );
			return new CdekApiResponse( 200, json_encode( $response ) ?: '[]' );
		}
		if ( 'POST' === $method && str_contains( $url, '/v2/print/barcodes' ) ) {
			$response = array_shift( $this->barcode_create_responses ) ?: array( 'entity' => array( 'uuid' => 'print-uuid-1' ) );
			return new CdekApiResponse( 202, json_encode( $response ) ?: '{}' );
		}
		if ( 'GET' === $method && str_contains( $url, '/v2/print/barcodes/' ) && str_ends_with( $url, '.pdf' ) ) {
			$response = array_shift( $this->barcode_pdf_responses ) ?: '%PDF-1.4 fake';
			if ( is_array( $response ) ) {
				return new CdekApiResponse(
					(int) ( $response['status'] ?? 200 ),
					(string) ( $response['body'] ?? '' ),
					is_array( $response['headers'] ?? null ) ? $response['headers'] : array( 'content-type' => (string) ( $response['content_type'] ?? 'application/pdf' ) )
				);
			}
			return new CdekApiResponse( 200, $response, array( 'content-type' => 'application/pdf' ) );
		}
		if ( 'GET' === $method && str_contains( $url, '/v2/print/barcodes/' ) ) {
			$response = array_shift( $this->barcode_status_responses ) ?: array( 'entity' => array( 'uuid' => 'print-uuid-1', 'statuses' => array( array( 'code' => 'READY', 'date_time' => '2026-06-13T10:00:00+0000' ) ) ) );
			return new CdekApiResponse( 200, json_encode( $response ) ?: '{}' );
		}
		$response = array_shift( $this->order_responses ) ?: array( 'entity' => array( 'uuid' => 'order-uuid-1', 'cdek_number' => '100500', 'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
		return new CdekApiResponse( 200, json_encode( $response ) ?: '{}' );
	}
}

final class CdekOrderFakeSuggestionClient implements AddressSuggestionClientInterface {
	/** @var array<int,array<string,mixed>> */
	public array $responses = array();
	/** @var array<int,array{stage:string,query:string,context:array<string,string>}> */
	public array $requests = array();

	public function suggest( string $stage, string $query, array $context = array() ): array {
		$this->requests[] = array( 'stage' => $stage, 'query' => $query, 'context' => $context );
		return array_shift( $this->responses ) ?: array(
			'success' => true,
			'suggestions' => array(
				array(
					'value' => '125252, г Москва, Ходынский б-р, д 13, кв 150',
					'unrestricted_value' => '125252, г Москва, Ходынский б-р, д 13, кв 150',
					'data' => array(
						'postal_code' => '125252',
						'region_with_type' => 'г Москва',
						'city_with_type' => 'г Москва',
						'street_with_type' => 'Ходынский б-р',
						'house' => '13',
						'flat' => '150',
						'fias_id' => 'fias-house',
						'kladr_id' => 'kladr-house',
						'geo_lat' => '55.790000',
						'geo_lon' => '37.530000',
					),
				),
			),
		);
	}
}

final class CdekOrderFakeOrder {
	public array $meta = array();
	public array $notes = array();
	public array $items = array();
	public function __construct( private int $id = 101 ) {}
	public function get_id(): int { return $this->id; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
	public function add_order_note( string $message ): void { $this->notes[] = $message; }
	public function get_order_number(): string { return 'WC-' . $this->id; }
	public function get_shipping_first_name(): string { return 'Иван'; }
	public function get_shipping_last_name(): string { return 'Иванов'; }
	public function get_billing_first_name(): string { return 'Иван'; }
	public function get_billing_last_name(): string { return 'Иванов'; }
	public function get_billing_phone(): string { return '9131234567'; }
	public function get_billing_email(): string { return 'buyer@example.com'; }
	public function get_shipping_postcode(): string { return '650000'; }
	public function get_shipping_state(): string { return 'Кемеровская область'; }
	public function get_shipping_city(): string { return 'Кемерово'; }
	public function get_shipping_address_1(): string { return 'Советский 10'; }
	public function get_shipping_address_2(): string { return ''; }
	public function get_items(): array { return $this->items; }
}

final class CdekOrderFakeProduct {
	public function __construct( private string $sku, private string $weight, private string $length, private string $width, private string $height ) {}
	public function get_sku(): string { return $this->sku; }
	public function get_weight(): string { return $this->weight; }
	public function get_length(): string { return $this->length; }
	public function get_width(): string { return $this->width; }
	public function get_height(): string { return $this->height; }
}

final class CdekOrderFakeOrderItem {
	public function __construct( private object $product, private string $name, private int $quantity, private float $total ) {}
	public function get_product(): object { return $this->product; }
	public function get_name(): string { return $this->name; }
	public function get_quantity(): int { return $this->quantity; }
	public function get_total(): float { return $this->total; }
}

function cdek_order_request( string $delivery_type, int $mode, array $overrides = array() ): ShipmentCreateRequest {
	$item = new PackageItem( 'SKU-1', 'Товар', 5, Money::from_rubles( $overrides['unit_cost'] ?? 1000 ), Money::from_rubles( ( $overrides['unit_cost'] ?? 1000 ) * 5 ), 100, 10, 8, 3 );
	$place = new ShipmentPlace( 1, (int) ( $overrides['place_weight'] ?? 1000 ), 20, 15, 10, Money::from_kopecks( 0 ), array( $item ) );
	$pickup = DeliveryType::PICKUP === $delivery_type ? new PickupPointSelection( CdekSettings::CARRIER_KEY, CdekSettings::SERVICE_KEY, 'KEM7', 'Kemerovo', '2026-06-13 12:00:00' ) : null;
	return new ShipmentCreateRequest(
		101,
		CdekSettings::CARRIER_KEY,
		$delivery_type,
		'cdek:' . $delivery_type . ':136',
		new Address( country_code: 'RU', region_name: 'Кемеровская область', city: 'Кемерово', postcode: '650000', raw_address: DeliveryType::COURIER === $delivery_type ? '650000, Кемерово, Советский 10' : 'KEM7' ),
		$pickup,
		array( $place ),
		Money::from_kopecks( 0 ),
		false,
		array(),
		array( 'name' => $overrides['name'] ?? 'Иван Иванов', 'phone' => $overrides['phone'] ?? '9131234567', 'email' => 'buyer@example.com' ),
		array(
			'service_key' => CdekSettings::SERVICE_KEY,
			'order_num' => 'WC-101',
			'tariff_code' => $overrides['tariff_code'] ?? '136',
			'tariff_title' => $overrides['tariff_title'] ?? 'Посылка склад-склад',
			'delivery_mode' => $mode,
			'shipment_point' => $overrides['shipment_point'] ?? '',
			'delivery_point' => $overrides['delivery_point'] ?? ( DeliveryType::PICKUP === $delivery_type ? 'KEM7' : '' ),
			'cdek_to_city_code' => $overrides['cdek_to_city_code'] ?? 44,
			'cdek_city_code' => $overrides['cdek_city_code'] ?? null,
			'cdek_city_name' => $overrides['cdek_city_name'] ?? '',
			'cdek_postal_code' => $overrides['cdek_postal_code'] ?? '',
			'cdek_delivery_address' => $overrides['cdek_delivery_address'] ?? '',
			'cdek_courier_comment' => $overrides['cdek_courier_comment'] ?? '',
			'shipment_item_rows' => $overrides['shipment_item_rows'] ?? array(
				cdek_order_item_row( '1', 1, 'Товар', 'SKU-1', 5, (int) round( (float) ( $overrides['unit_cost'] ?? 1000 ) * 100 ), 100 ),
			),
		)
	);
}

function cdek_order_dadata_settings( bool $enabled = true ): AddressSuggestionSettings {
	$repository = new SettingsRepository();
	$options = $repository->all();
	$options['dadata_suggestions_enabled'] = $enabled;
	$options['dadata_suggestions_tokens'] = $enabled ? array(
		array(
			'id' => 'test-token',
			'enabled' => true,
			'encrypted_token' => 'encrypted-for-smoke',
			'daily_limit' => 100,
		),
	) : array();
	$repository->replace( $options );
	$encryption = new EncryptionService();

	return new AddressSuggestionSettings( $repository, $encryption, new DaDataTokenPool( $repository, $encryption ) );
}

function cdek_order_address_service( AddressSuggestionClientInterface $suggestions, CdekApiClient $client, bool $dadata_enabled = true ): CdekRecipientAddressPreparationService {
	return new CdekRecipientAddressPreparationService(
		cdek_order_dadata_settings( $dadata_enabled ),
		$suggestions,
		new CdekLocationResolver( $client, new Logger() )
	);
}

$GLOBALS['wdc_cdek_order_options'] = array();
$GLOBALS['wdc_cdek_order_transients'] = array();
$settings = new CdekSettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin(
	array(
		CdekSettings::ENVIRONMENT_KEY => CdekSettings::ENV_TEST,
		CdekSettings::TEST_ACCOUNT_KEY => 'account',
		'cdek_test_secure_password' => 'secret',
		CdekSettings::SENDER_CITY_CODE_KEY => 270,
		CdekSettings::SENDER_POSTAL_CODE_KEY => '630000',
		CdekSettings::SENDER_CITY_NAME_KEY => 'Новосибирск',
		CdekSettings::SENDER_ADDRESS_KEY => 'Фабричная 1',
		CdekSettings::SHIPMENT_POINT_KEY => 'NSK69',
		CdekSettings::SHIPMENT_POINT_ADDRESS_KEY => 'Новосибирск, Красный проспект 1',
	)
);
cdek_order_assert( 'Новосибирск, Красный проспект 1' === $settings->shipment_point_address(), 'CDEK shipment point address setting must be saved and read.' );
$builder = new CdekCreateRequestBuilder( $settings );

$pickup_payload = $builder->build( cdek_order_request( DeliveryType::PICKUP, 4 ) );
cdek_order_assert( 1 === $pickup_payload['type'] && 'WC-101' === $pickup_payload['number'] && 136 === $pickup_payload['tariff_code'], 'CDEK pickup payload must include type, number and tariff_code.' );
cdek_order_assert( 'NSK69' === $pickup_payload['shipment_point'] && 'KEM7' === $pickup_payload['delivery_point'], 'CDEK pickup payload must use shipment_point and delivery_point.' );
cdek_order_assert( ! isset( $pickup_payload['from_location'], $pickup_payload['to_location'], $pickup_payload['services'], $pickup_payload['additional_order_types'], $pickup_payload['delivery_recipient_cost'], $pickup_payload['delivery_recipient_cost_adv'] ), 'CDEK pickup payload must omit forbidden fields.' );
cdek_order_assert( 'BARCODE' === $pickup_payload['print'], 'CDEK order payload must request BARCODE print.' );
cdek_order_assert( 0 === $pickup_payload['packages'][0]['items'][0]['payment']['value'] && 1000.0 === $pickup_payload['packages'][0]['items'][0]['cost'], 'CDEK item payment/cost mismatch.' );
cdek_order_assert( is_int( $pickup_payload['packages'][0]['length'] ) && is_int( $pickup_payload['packages'][0]['width'] ) && is_int( $pickup_payload['packages'][0]['height'] ), 'CDEK package dimensions must remain integer-only.' );
cdek_order_assert( ! isset( $pickup_payload['packages'][0]['items'][0]['length'], $pickup_payload['packages'][0]['items'][0]['width'], $pickup_payload['packages'][0]['items'][0]['height'], $pickup_payload['packages'][0]['items'][0]['length_cm'], $pickup_payload['packages'][0]['items'][0]['width_cm'], $pickup_payload['packages'][0]['items'][0]['height_cm'] ), 'CDEK package items must not send item-level dimensions.' );
$override_payload = $builder->build( cdek_order_request( DeliveryType::PICKUP, 4, array( 'shipment_point' => 'nsk70' ) ) );
cdek_order_assert( 'NSK70' === $override_payload['shipment_point'], 'CDEK order creation must use temporary sender shipment_point from modal meta.' );
$postcode_as_point_request = cdek_order_request( DeliveryType::PICKUP, 4, array( 'delivery_point' => '101000' ) );
cdek_order_assert( array() !== $builder->validate( $postcode_as_point_request ), 'CDEK order creation must not accept postcode as delivery_point.' );
$pickup_without_mode = cdek_order_request( DeliveryType::PICKUP, 0, array( 'delivery_point' => 'KEM7' ) );
$pickup_without_mode_errors = $builder->validate( $pickup_without_mode );
cdek_order_assert( ! in_array( 'Не удалось определить режим тарифа СДЭК. Проверьте тариф и повторите создание отправления.', $pickup_without_mode_errors, true ), 'CDEK pickup with tariff_code, shipment_point and delivery_point must not be blocked by missing delivery_mode.' );
cdek_order_assert( 'KEM7' === (string) ( $builder->build( $pickup_without_mode )['delivery_point'] ?? '' ), 'CDEK pickup delivery_type fallback must build shipment_point + delivery_point when delivery_mode is absent.' );
$pickup_without_point_errors = $builder->validate( cdek_order_request( DeliveryType::PICKUP, 0, array( 'delivery_point' => '' ) ) );
cdek_order_assert( ! in_array( 'Не удалось определить режим тарифа СДЭК. Проверьте тариф и повторите создание отправления.', $pickup_without_point_errors, true ) && in_array( 'Для CDEK pickup нужен код ПВЗ delivery_point.', $pickup_without_point_errors, true ), 'CDEK pickup without delivery_point must be blocked by missing pickup point, not tariff mode.' );
$pickup_without_tariff_errors = $builder->validate( cdek_order_request( DeliveryType::PICKUP, 0, array( 'tariff_code' => '' ) ) );
cdek_order_assert( in_array( 'Не выбран tariff_code СДЭК.', $pickup_without_tariff_errors, true ), 'CDEK pickup without tariff_code must still show tariff validation error.' );

$courier_payload = $builder->build( cdek_order_request( DeliveryType::COURIER, 3 ) );
cdek_order_assert( isset( $courier_payload['shipment_point'], $courier_payload['to_location'] ) && ! isset( $courier_payload['delivery_point'], $courier_payload['from_location'] ), 'Warehouse-door courier must use shipment_point and to_location only.' );
$door_payload = $builder->build( cdek_order_request( DeliveryType::COURIER, 1 ) );
cdek_order_assert( isset( $door_payload['from_location'], $door_payload['to_location'] ) && ! isset( $door_payload['shipment_point'], $door_payload['delivery_point'] ), 'Door-door courier must use from_location and to_location only.' );
$door_warehouse_payload = $builder->build( cdek_order_request( DeliveryType::PICKUP, 2, array( 'delivery_point' => 'KEM7' ) ) );
cdek_order_assert( isset( $door_warehouse_payload['from_location'], $door_warehouse_payload['delivery_point'] ) && ! isset( $door_warehouse_payload['shipment_point'], $door_warehouse_payload['to_location'] ), 'Door-warehouse CDEK order must use from_location and delivery_point only.' );
$warehouse_warehouse_payload = $builder->build( cdek_order_request( DeliveryType::PICKUP, 4, array( 'delivery_point' => 'KEM7' ) ) );
cdek_order_assert( isset( $warehouse_warehouse_payload['shipment_point'], $warehouse_warehouse_payload['delivery_point'] ) && ! isset( $warehouse_warehouse_payload['from_location'], $warehouse_warehouse_payload['to_location'] ), 'Warehouse-warehouse CDEK order must use shipment_point and delivery_point only.' );
$comment_payload = $builder->build( cdek_order_request( DeliveryType::COURIER, 3, array( 'cdek_courier_comment' => str_repeat( 'А', 300 ) ) ) );
cdek_order_assert( isset( $comment_payload['comment'] ) && 255 === mb_strlen( (string) $comment_payload['comment'] ), 'CDEK courier comment must be sent and trimmed to 255 characters.' );
$empty_comment_payload = $builder->build( cdek_order_request( DeliveryType::COURIER, 3, array( 'cdek_courier_comment' => '' ) ) );
cdek_order_assert( ! isset( $empty_comment_payload['comment'] ), 'Empty CDEK courier comment must not be sent.' );
$normalized_courier_payload = $builder->build(
	cdek_order_request(
		DeliveryType::COURIER,
		3,
		array(
			'cdek_to_city_code' => 0,
			'cdek_city_code' => 44,
			'cdek_city_name' => 'Москва',
			'cdek_postal_code' => '125252',
			'cdek_delivery_address' => 'Ходынский б-р, д 13, кв 150',
		)
	)
);
cdek_order_assert( array( 'code' => 44, 'city' => 'Москва', 'postal_code' => '125252', 'address' => 'Ходынский б-р, д 13, кв 150' ) === $normalized_courier_payload['to_location'], 'CDEK courier to_location must use prepared DaData/CDEK fields.' );
$missing_cdek_city_errors = $builder->validate( cdek_order_request( DeliveryType::COURIER, 3, array( 'cdek_to_city_code' => 0 ) ) );
cdek_order_assert( in_array( "Не удалось определить код города СДЭК для адреса получателя.\nПроверьте адрес и повторите обработку.", $missing_cdek_city_errors, true ), 'CDEK courier creation must be blocked when CDEK city code is missing.' );
$postcode_city_code_payload = $builder->build( cdek_order_request( DeliveryType::COURIER, 3, array( 'cdek_to_city_code' => 0, 'cdek_city_code' => 44, 'cdek_postal_code' => '125252', 'cdek_delivery_address' => 'Ходынский б-р, д 13' ) ) );
cdek_order_assert( 44 === (int) $postcode_city_code_payload['to_location']['code'] && 125252 !== (int) $postcode_city_code_payload['to_location']['code'], 'CDEK courier must not use postcode as city code.' );

$suggestions = new CdekOrderFakeSuggestionClient();
$location_http = new CdekOrderFakeHttp();
$location_http->city_responses[] = array( array( 'code' => 44, 'city' => 'Москва' ) );
$location_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $location_http ), $settings, $location_http );
$cdek_address_service = cdek_order_address_service( $suggestions, $location_client );
$prepared_address_without_flat = $cdek_address_service->prepare( new CdekOrderFakeOrder( 124 ), '125252, Москва, Ходынский б-р, д 13', array( 'city_name' => 'Москва', 'postal_code' => '125252', 'delivery_calculation_data' => array( 'api' => array( 'cdek_to_city_code' => 44 ) ) ), CdekSettings::SERVICE_KEY );
cdek_order_assert( ! empty( $prepared_address_without_flat['success'] ) && str_contains( (string) ( $prepared_address_without_flat['fields']['cdek_delivery_address'] ?? '' ), 'Ходынский б-р, д 13' ), 'CDEK courier address without flat must still prepare successfully.' );
$prepared_address = $cdek_address_service->prepare( new CdekOrderFakeOrder( 125 ), '125252, Москва, Ходынский б-р, д 13, кв 150', array( 'city_name' => 'Москва', 'postal_code' => '125252', 'delivery_calculation_data' => array( 'api' => array( 'cdek_to_city_code' => 44 ) ) ), CdekSettings::SERVICE_KEY );
cdek_order_assert( ! empty( $prepared_address['success'] ) && 44 === (int) ( $prepared_address['fields']['cdek_city_code'] ?? 0 ) && 'Москва' === (string) ( $prepared_address['fields']['cdek_city_name'] ?? '' ) && '125252' === (string) ( $prepared_address['fields']['cdek_postal_code'] ?? '' ) && 'Ходынский б-р, д 13, кв 150' === (string) ( $prepared_address['fields']['cdek_delivery_address'] ?? '' ), 'CDEK courier address preparation must normalize DaData address and resolve CDEK city code.' );
cdek_order_assert( '125252, Москва, Ходынский б-р, д 13' === (string) ( $suggestions->requests[1]['query'] ?? '' ), 'CDEK courier address preparation must send DaData query without flat suffix.' );
cdek_order_assert( str_contains( (string) ( $prepared_address['display'] ?? '' ), 'кв 150' ), 'CDEK courier normalized display must include flat.' );
cdek_order_assert( hash( 'sha256', '125252, Москва, Ходынский б-р, д 13, кв 150' ) === (string) ( $prepared_address['original_hash'] ?? '' ), 'CDEK courier original hash must use the full original address with flat.' );
$location_urls = implode( "\n", array_map( static fn( array $request ): string => (string) $request['url'], $location_http->requests ) );
cdek_order_assert( ! str_contains( $location_urls, '/v2/location/cities' ), 'Known delivery_calculation_data.api.cdek_to_city_code must skip CDEK location lookup.' );

$extracted_flat_suggestions = new CdekOrderFakeSuggestionClient();
$extracted_flat_suggestions->responses[] = array(
	'success' => true,
	'suggestions' => array(
		array(
			'value' => '125252, г Москва, Ходынский б-р, д 13',
			'unrestricted_value' => '125252, г Москва, Ходынский б-р, д 13',
			'data' => array(
				'postal_code' => '125252',
				'city_with_type' => 'г Москва',
				'street_with_type' => 'Ходынский б-р',
				'house' => '13',
				'geo_lat' => '55.790000',
				'geo_lon' => '37.530000',
			),
		),
	),
);
$extracted_flat_service = cdek_order_address_service( $extracted_flat_suggestions, new CdekApiClient( new CdekOAuthTokenService( $settings, new CdekOrderFakeHttp() ), $settings, new CdekOrderFakeHttp() ) );
$extracted_flat_prepared = $extracted_flat_service->prepare( new CdekOrderFakeOrder( 131 ), '125252, Москва, Ходынский б-р, д 13, кв. 150', array( 'city_name' => 'Москва', 'delivery_calculation_data' => array( 'api' => array( 'cdek_to_city_code' => 44 ) ) ), CdekSettings::SERVICE_KEY );
cdek_order_assert( ! empty( $extracted_flat_prepared['success'] ) && 'Ходынский б-р, д 13, кв 150' === (string) ( $extracted_flat_prepared['fields']['cdek_delivery_address'] ?? '' ), 'CDEK courier address preparation must restore extracted flat when DaData does not return it.' );

$dadata_flat_suggestions = new CdekOrderFakeSuggestionClient();
$dadata_flat_suggestions->responses[] = array(
	'success' => true,
	'suggestions' => array(
		array(
			'value' => '125252, г Москва, Ходынский б-р, д 13, кв 151',
			'unrestricted_value' => '125252, г Москва, Ходынский б-р, д 13, кв 151',
			'data' => array(
				'postal_code' => '125252',
				'city_with_type' => 'г Москва',
				'street_with_type' => 'Ходынский б-р',
				'house' => '13',
				'flat' => '151',
				'geo_lat' => '55.790000',
				'geo_lon' => '37.530000',
			),
		),
	),
);
$dadata_flat_service = cdek_order_address_service( $dadata_flat_suggestions, new CdekApiClient( new CdekOAuthTokenService( $settings, new CdekOrderFakeHttp() ), $settings, new CdekOrderFakeHttp() ) );
$dadata_flat_prepared = $dadata_flat_service->prepare( new CdekOrderFakeOrder( 132 ), '125252, Москва, Ходынский б-р, д 13, кв 150', array( 'city_name' => 'Москва', 'delivery_calculation_data' => array( 'api' => array( 'cdek_to_city_code' => 44 ) ) ), CdekSettings::SERVICE_KEY );
cdek_order_assert( ! empty( $dadata_flat_prepared['success'] ) && 'Ходынский б-р, д 13, кв 151' === (string) ( $dadata_flat_prepared['fields']['cdek_delivery_address'] ?? '' ), 'CDEK courier address preparation must prefer DaData flat when it is returned.' );

$rate_meta_suggestions = new CdekOrderFakeSuggestionClient();
$rate_meta_http = new CdekOrderFakeHttp();
$rate_meta_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $rate_meta_http ), $settings, $rate_meta_http );
$rate_meta_service = cdek_order_address_service( $rate_meta_suggestions, $rate_meta_client );
$rate_meta_prepared = $rate_meta_service->prepare( new CdekOrderFakeOrder( 128 ), '125252, Москва, Ходынский б-р, д 13, кв 150', array( 'city_name' => 'Москва', 'rate_meta' => array( 'location' => array( 'cdek_to_city_code' => 44 ) ) ), CdekSettings::SERVICE_KEY );
$rate_meta_urls = implode( "\n", array_map( static fn( array $request ): string => (string) $request['url'], $rate_meta_http->requests ) );
cdek_order_assert( ! empty( $rate_meta_prepared['success'] ) && 44 === (int) ( $rate_meta_prepared['fields']['cdek_city_code'] ?? 0 ) && ! str_contains( $rate_meta_urls, '/v2/location/cities' ), 'Known rate_meta.location.cdek_to_city_code must skip CDEK location lookup.' );

$lookup_suggestions = new CdekOrderFakeSuggestionClient();
$lookup_http = new CdekOrderFakeHttp();
$lookup_http->city_responses[] = array( array( 'code' => 44, 'city' => 'Москва' ) );
$lookup_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $lookup_http ), $settings, $lookup_http );
$lookup_service = cdek_order_address_service( $lookup_suggestions, $lookup_client );
$lookup_prepared = $lookup_service->prepare( new CdekOrderFakeOrder( 129 ), '125252, Москва, Ходынский б-р, д 13, кв 150', array( 'city_name' => 'Москва', 'postal_code' => '125252' ), CdekSettings::SERVICE_KEY );
$lookup_urls = implode( "\n", array_map( static fn( array $request ): string => (string) $request['url'], $lookup_http->requests ) );
cdek_order_assert( ! empty( $lookup_prepared['success'] ) && str_contains( $lookup_urls, '/v2/location/cities' ) && ( str_contains( $lookup_urls, 'latitude=55.79' ) || str_contains( $lookup_urls, 'latitude=55.790000' ) ), 'Missing city code must fall back to resolver lookup with DaData coordinates.' );

$fallback_suggestions = new CdekOrderFakeSuggestionClient();
$fallback_suggestions->responses[] = array(
	'success' => true,
	'suggestions' => array(
		array(
			'value' => '125252, г Москва, Ходынский б-р, д 13, кв 150',
			'unrestricted_value' => '125252, г Москва, Ходынский б-р, д 13, кв 150',
			'data' => array( 'postal_code' => '125252', 'city_with_type' => 'г Москва', 'street_with_type' => 'Ходынский б-р', 'house' => '13', 'flat' => '150' ),
		),
	),
);
$fallback_http = new CdekOrderFakeHttp();
$fallback_http->city_responses[] = array( array( 'code' => 44, 'city' => 'Москва' ) );
$fallback_service = cdek_order_address_service( $fallback_suggestions, new CdekApiClient( new CdekOAuthTokenService( $settings, $fallback_http ), $settings, $fallback_http ) );
$fallback_prepared = $fallback_service->prepare( new CdekOrderFakeOrder( 126 ), '125252, Москва, Ходынский б-р, д 13, кв 150', array( 'city_name' => 'Москва', 'postal_code' => '125252', 'lat' => '55.75', 'lng' => '37.61' ), CdekSettings::SERVICE_KEY );
$fallback_urls = implode( "\n", array_map( static fn( array $request ): string => (string) $request['url'], $fallback_http->requests ) );
cdek_order_assert( ! empty( $fallback_prepared['success'] ) && str_contains( $fallback_urls, 'latitude=55.75' ), 'CDEK location lookup must fall back to recipient locality coordinates when DaData address has no coordinates.' );

$GLOBALS['wdc_cdek_order_transients'] = array();
$not_found_http = new CdekOrderFakeHttp();
$not_found_http->city_responses = array( array(), array(), array(), array(), array() );
$not_found_service = cdek_order_address_service( new CdekOrderFakeSuggestionClient(), new CdekApiClient( new CdekOAuthTokenService( $settings, $not_found_http ), $settings, $not_found_http ) );
$not_found_prepared = $not_found_service->prepare( new CdekOrderFakeOrder( 127 ), '125252, Москва, Ходынский б-р, д 13, кв 150', array( 'city_name' => 'Москва' ), CdekSettings::SERVICE_KEY );
cdek_order_assert( empty( $not_found_prepared['success'] ) && str_contains( (string) ( $not_found_prepared['message'] ?? '' ), 'Не удалось определить код города СДЭК' ), 'CDEK courier address preparation must fail when CDEK city code is not found.' );

$disabled_service = cdek_order_address_service( new CdekOrderFakeSuggestionClient(), new CdekApiClient( new CdekOAuthTokenService( $settings, new CdekOrderFakeHttp() ), $settings, new CdekOrderFakeHttp() ), false );
$disabled_prepared = $disabled_service->prepare( new CdekOrderFakeOrder( 130 ), '125252, Москва, Ходынский б-р, д 13, кв 150', array( 'city_name' => 'Москва' ), CdekSettings::SERVICE_KEY );
cdek_order_assert( empty( $disabled_prepared['success'] ) && 'Подсказки DaData не настроены. Невозможно проверить адрес СДЭК.' === (string) ( $disabled_prepared['message'] ?? '' ) && ! str_contains( (string) ( $disabled_prepared['message'] ?? '' ), 'Внешний нормализатор не настроен' ), 'CDEK courier address preparation must show DaData setup error instead of external normalizer error.' );

cdek_order_assert( array() !== $builder->validate( cdek_order_request( DeliveryType::PICKUP, 4, array( 'phone' => '' ) ) ), 'Missing phone must fail validation.' );
cdek_order_assert( array() !== $builder->validate( cdek_order_request( DeliveryType::PICKUP, 4, array( 'tariff_code' => '' ) ) ), 'Missing tariff_code must fail validation.' );
cdek_order_assert( array() !== $builder->validate( cdek_order_request( DeliveryType::PICKUP, 4, array( 'delivery_point' => '' ) ) ), 'Missing delivery_point for pickup must fail validation.' );
cdek_order_assert( array() !== $builder->validate( cdek_order_request( DeliveryType::COURIER, 1, array( 'place_weight' => 100 ) ) ), 'Package weight below item weight must fail validation.' );
$too_many = array_fill( 0, 127, cdek_order_item_row( 'x', 1, 'T', 'W', 1, 100, 1 ) );
cdek_order_assert( array() !== $builder->validate( cdek_order_request( DeliveryType::PICKUP, 4, array( 'shipment_item_rows' => $too_many ) ) ), 'More than 126 item rows must fail validation.' );

$split = CdekCreateRequestBuilder::split_item_rows( array( array( 'item_key' => 'A', 'ordered_quantity' => 5, 'amount' => 5 ) ), 'A', 1 );
cdek_order_assert( 4 === (int) $split[0]['amount'] && 1 === (int) $split[1]['amount'], 'Split must create original 4 + duplicate 1.' );
$split[1]['amount'] = 2;
$split = CdekCreateRequestBuilder::rebalance_split_rows( $split );
cdek_order_assert( 3 === (int) $split[0]['amount'] && 2 === (int) $split[1]['amount'], 'Changing duplicate to 2 must make original 3.' );

$http = new CdekOrderFakeHttp();
$client = new CdekApiClient( new CdekOAuthTokenService( $settings, $http ), $settings, $http );
$repository = new OrderShipmentRepository();
$creation = cdek_order_creation_service( $repository, new CdekShipmentAdapter( $client, $builder ) );
$order = new CdekOrderFakeOrder();
$result = $creation->create( $order, cdek_order_request( DeliveryType::PICKUP, 4 ) );
cdek_order_assert( $result->success, 'CDEK POST /v2/orders must be accepted.' );
$postamat_result = $builder->build(
	cdek_order_request(
		DeliveryType::PICKUP,
		4,
		array(
			'delivery_point' => 'MSK900',
			'pickup_point_row' => array(
				'point_type' => 'POSTAMAT',
				'point_title' => 'Постамат СДЭК',
			),
		)
	)
);
cdek_order_assert( 'MSK900' === (string) ( $postamat_result['delivery_point'] ?? '' ) && ! isset( $postamat_result['to_location'] ), 'CDEK postamat shipment creation must use delivery_point like pickup.' );
$stored = $repository->find_by_carrier( $order, CdekSettings::CARRIER_KEY );
cdek_order_assert( 'registration_pending' === (string) $stored['status'] && 'order-uuid-1' === (string) $stored['external_id'], 'Accepted CDEK order must be stored as registration_pending with UUID.' );
cdek_order_assert( array() === $order->notes, 'Accepted CDEK registration request must not add an order note before CREATED.' );
$request_snapshot_json = json_encode( $stored['request_snapshot'], JSON_UNESCAPED_UNICODE ) ?: '';
$response_snapshot_json = json_encode( $stored['response_snapshot'], JSON_UNESCAPED_UNICODE ) ?: '';
cdek_order_assert( ! str_contains( $request_snapshot_json, 'Иван Иванов' ) && ! str_contains( $request_snapshot_json, '+79131234567' ) && ! str_contains( $request_snapshot_json, 'buyer@example.com' ), 'CDEK request snapshot must redact recipient PII.' );
cdek_order_assert( ! str_contains( $response_snapshot_json, 'Иван Иванов' ) && ! str_contains( $response_snapshot_json, 'buyer@example.com' ), 'CDEK response snapshot must not keep recipient PII.' );
$blocked = $creation->create( $order, cdek_order_request( DeliveryType::PICKUP, 4 ) );
cdek_order_assert( ! $blocked->success && 'shipment_already_created' === $blocked->error_code, 'Repeated CDEK creation must be blocked while pending.' );

$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$cdek_modal_extension_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Cdek/CdekShipmentModalExtension.php' );
cdek_order_assert( str_contains( $metabox_source, 'render_pickup_fields' ) && str_contains( $cdek_modal_extension_source, 'Тип точки' ) && str_contains( $cdek_modal_extension_source, 'pickup_type_label' ) && str_contains( $cdek_modal_extension_source, 'data-wdc-cdek-pickup-type-label' ) && str_contains( $cdek_modal_extension_source, 'ПВЗ СДЭК' ), 'CDEK shipment modal extension must show known pickup point type label.' );

$http_post_invalid = new CdekOrderFakeHttp();
$http_post_invalid->post_responses[] = array( 'entity' => array( 'uuid' => 'invalid-uuid' ), 'requests' => array( array( 'request_uuid' => 'invalid-request-uuid', 'state' => 'INVALID', 'errors' => array( array( 'code' => 'v2_bad', 'message' => 'bad request' ) ) ) ) );
$invalid_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $http_post_invalid ), $settings, $http_post_invalid );
$invalid_repository = new OrderShipmentRepository();
$invalid_creation = cdek_order_creation_service( $invalid_repository, new CdekShipmentAdapter( $invalid_client, $builder ) );
$invalid_post_order = new CdekOrderFakeOrder();
$invalid_post_result = $invalid_creation->create( $invalid_post_order, cdek_order_request( DeliveryType::PICKUP, 4 ) );
cdek_order_assert( ! $invalid_post_result->success && 'cdek_registration_invalid' === $invalid_post_result->error_code, 'POST /v2/orders INVALID must fail ShipmentCreateResult.' );
cdek_order_assert( array() === $invalid_repository->find_by_carrier( $invalid_post_order, CdekSettings::CARRIER_KEY ), 'POST /v2/orders INVALID must not be stored as registration_pending.' );

$status = cdek_order_status_service( $repository, $client );
$created = $status->update( $order );
cdek_order_assert( $created['success'] && 'registered' === (string) $repository->find_by_carrier( $order, CdekSettings::CARRIER_KEY )['status'], 'GET /v2/orders CREATED must register shipment.' );
cdek_order_assert( array( 'Зарегистрировано отправление СДЭК 100500. Мест: 1.' ) === $order->notes, 'CDEK CREATED status update must add a single registered order note.' );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'order-uuid-1', 'cdek_number' => '100500', 'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$status->update( $order );
cdek_order_assert( 1 === count( $order->notes ), 'Repeated CDEK CREATED status update must not duplicate the registered order note.' );

$successful_without_status_order = new CdekOrderFakeOrder( 115 );
$repository->save_for_carrier( $successful_without_status_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'successful-empty-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-115' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'successful-empty-uuid', 'statuses' => array() ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$successful_without_status = $status->update( $successful_without_status_order );
cdek_order_assert( 'registration_pending' === (string) $repository->find_by_carrier( $successful_without_status_order, CdekSettings::CARRIER_KEY )['status'], 'CDEK request_state SUCCESSFUL without order statuses must remain registration_pending.' );
cdek_order_assert( 'SUCCESSFUL' === (string) ( $successful_without_status['status']['carrier_status_title'] ?? '' ), 'CDEK request state may be displayed only when no order status exists.' );
cdek_order_assert( empty( $successful_without_status['terminal'] ), 'CDEK SUCCESSFUL without order statuses must keep polling active.' );

$successful_accepted_order = new CdekOrderFakeOrder( 121 );
$repository->save_for_carrier( $successful_accepted_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'successful-accepted-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-121' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'successful-accepted-uuid', 'statuses' => array( array( 'code' => 'ACCEPTED', 'name' => 'Принят', 'date_time' => '2026-06-13T05:48:42+0000' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$successful_accepted = $status->update( $successful_accepted_order );
$successful_accepted_shipment = $repository->find_by_carrier( $successful_accepted_order, CdekSettings::CARRIER_KEY );
cdek_order_assert( 'registration_pending' === (string) ( $successful_accepted_shipment['status'] ?? '' ) && 'регистрация' === (string) ( $successful_accepted['status']['shipment_status_label'] ?? '' ), 'CDEK order status ACCEPTED must remain registration_pending internally.' );
cdek_order_assert( empty( $successful_accepted['terminal'] ) && empty( $successful_accepted['status']['can_remove_from_order'] ), 'CDEK order status ACCEPTED must keep polling active and forbid local remove.' );

$successful_created_order = new CdekOrderFakeOrder( 116 );
$repository->save_for_carrier( $successful_created_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'successful-created-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-116' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'successful-created-uuid', 'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$successful_created = $status->update( $successful_created_order );
cdek_order_assert( 'registered' === (string) $repository->find_by_carrier( $successful_created_order, CdekSettings::CARRIER_KEY )['status'] && 'CREATED' === (string) ( $successful_created['status']['order_status_code'] ?? '' ), 'CDEK request_state SUCCESSFUL with CREATED order status must become registered.' );
cdek_order_assert( ! empty( $successful_created['terminal'] ) && ! empty( $successful_created['status']['can_cancel'] ) && empty( $successful_created['status']['can_remove_from_order'] ), 'CDEK order status CREATED must be terminal, cancellable and protected from local remove.' );

$successful_invalid_order = new CdekOrderFakeOrder( 122 );
$repository->save_for_carrier( $successful_invalid_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'successful-invalid-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-122' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'successful-invalid-uuid', 'statuses' => array( array( 'code' => 'INVALID', 'name' => 'Некорректный заказ', 'date_time' => '2026-06-13T05:48:45+0000' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$successful_invalid = $status->update( $successful_invalid_order );
cdek_order_assert( 'failed' === (string) $repository->find_by_carrier( $successful_invalid_order, CdekSettings::CARRIER_KEY )['status'] && ! empty( $successful_invalid['terminal'] ), 'CDEK order status INVALID must become failed and terminal.' );
cdek_order_assert( ! empty( $successful_invalid['status']['can_remove_from_order'] ) && 'Заказ СДЭК некорректен.' === (string) ( $successful_invalid['message'] ?? '' ), 'CDEK order status INVALID must allow local remove and use invalid-order message.' );

$successful_removed_order = new CdekOrderFakeOrder( 123 );
$repository->save_for_carrier( $successful_removed_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'successful-removed-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-123' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'successful-removed-uuid', 'statuses' => array( array( 'code' => 'REMOVED', 'name' => 'Удален', 'date_time' => '2026-06-13T05:48:46+0000' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$successful_removed = $status->update( $successful_removed_order );
cdek_order_assert( 'removed' === (string) $repository->find_by_carrier( $successful_removed_order, CdekSettings::CARRIER_KEY )['status'] && ! empty( $successful_removed['terminal'] ), 'CDEK order status REMOVED must become removed and terminal.' );
cdek_order_assert( ! empty( $successful_removed['status']['can_remove_from_order'] ) && 'удалено' === (string) ( $successful_removed['status']['shipment_status_label'] ?? '' ), 'CDEK order status REMOVED must allow local remove and render removed internal label.' );

$successful_ready_order = new CdekOrderFakeOrder( 117 );
$repository->save_for_carrier( $successful_ready_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'successful-ready-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-117' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'successful-ready-uuid', 'statuses' => array( array( 'code' => 'READY_FOR_SHIPMENT_IN_SENDER_CITY', 'name' => 'Готов к отправке', 'date_time' => '2026-06-13T10:04:41+0000' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$successful_ready = $status->update( $successful_ready_order );
cdek_order_assert( 'registered' === (string) $repository->find_by_carrier( $successful_ready_order, CdekSettings::CARRIER_KEY )['status'] && 'READY_FOR_SHIPMENT_IN_SENDER_CITY' === (string) ( $successful_ready['status']['order_status_code'] ?? '' ), 'CDEK later real order status must keep shipment registered and display order status.' );
cdek_order_assert( ! empty( $successful_ready['terminal'] ) && ! empty( $successful_ready['status']['can_remove_from_order'] ) && empty( $successful_ready['status']['can_cancel'] ), 'CDEK operational statuses must be terminal registered shipments, removable locally and not cancellable in CDEK.' );

$accepted_without_status_order = new CdekOrderFakeOrder( 118 );
$repository->save_for_carrier( $accepted_without_status_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'accepted-empty-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-118' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'accepted-empty-uuid', 'statuses' => array() ), 'requests' => array( array( 'state' => 'ACCEPTED' ) ) );
$accepted_without_status = $status->update( $accepted_without_status_order );
cdek_order_assert( 'registration_pending' === (string) $repository->find_by_carrier( $accepted_without_status_order, CdekSettings::CARRIER_KEY )['status'] && 'ACCEPTED' === (string) ( $accepted_without_status['status']['carrier_status_title'] ?? '' ), 'CDEK request_state ACCEPTED without order status must remain registration_pending.' );

$successful_created_latest_order = new CdekOrderFakeOrder( 119 );
$repository->save_for_carrier( $successful_created_latest_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'created-latest-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-119' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'created-latest-uuid',
		'statuses' => array(
			array( 'code' => 'ACCEPTED', 'name' => 'Принят', 'date_time' => '2026-06-13T05:48:42+0000' ),
			array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ),
		),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$successful_created_latest = $status->update( $successful_created_latest_order );
cdek_order_assert( 'CREATED' === (string) ( $successful_created_latest['status']['order_status_code'] ?? '' ) && 'Создан' === (string) ( $successful_created_latest['status']['carrier_status_title'] ?? '' ), 'CDEK actual status CREATED must come from entity.statuses even when request_state is SUCCESSFUL.' );

$latest_order = new CdekOrderFakeOrder( 110 );
$latest_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] = array( 'api' => array( 'api_base_price_rub' => 450.0 ) );
$repository->save_for_carrier( $latest_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'latest-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-110' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'latest-uuid',
		'cdek_number' => '100510',
		'planned_delivery_date' => '2026-06-15',
		'delivery_detail' => array( 'total_sum' => 450.18 ),
		'statuses' => array(
			array( 'code' => 'READY_FOR_SHIPMENT_IN_SENDER_CITY', 'name' => 'Готов к отправке', 'date_time' => '2026-06-13T10:04:41+0000' ),
			array( 'code' => 'RECEIVED_AT_SHIPMENT_WAREHOUSE', 'name' => 'Принят на складе', 'date_time' => '2026-06-13T10:04:33+0000' ),
			array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ),
			array( 'code' => 'ACCEPTED', 'name' => 'Принят', 'date_time' => '2026-06-13T05:48:42+0000' ),
		),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$latest = $status->update( $latest_order );
$latest_shipment = $repository->find_by_carrier( $latest_order, CdekSettings::CARRIER_KEY );
cdek_order_assert( 'READY_FOR_SHIPMENT_IN_SENDER_CITY' === (string) ( $latest_shipment['cdek_order_status_code'] ?? '' ), 'CDEK latest status must be selected by max date_time, not array tail.' );
cdek_order_assert( 'READY_FOR_SHIPMENT_IN_SENDER_CITY' === (string) ( $latest['status']['order_status_code'] ?? '' ), 'CDEK status payload must use latest order status, not request state.' );
cdek_order_assert( 'Готов к отправке' === (string) ( $latest['status']['carrier_status_title'] ?? '' ), 'CDEK displayed status must use entity.statuses name instead of request_state.' );
cdek_order_assert( '2026-06-15' === (string) ( $latest['status']['cdek_planned_delivery_date'] ?? '' ), 'CDEK planned_delivery_date must be saved in status payload.' );
cdek_order_assert( 45018 === (int) ( $latest_shipment['actual_cost_kopecks'] ?? 0 ) && 'carrier_status' === (string) ( $latest_shipment['actual_cost_source'] ?? '' ), 'CDEK delivery_detail.total_sum must be saved as canonical actual cost from status update.' );
cdek_order_assert( '450.18 руб.' === (string) ( $latest['status']['actual_cost_label'] ?? '' ) && 'ok' === (string) ( $latest['status']['actual_cost_compare_status'] ?? '' ), 'CDEK actual cost within 3 percent of base API cost must compare as ok.' );

$deleted_status_order = new CdekOrderFakeOrder( 111 );
$repository->save_for_carrier( $deleted_status_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'deleted-status-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-111' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'deleted-status-uuid',
		'statuses' => array(
			array( 'code' => 'DELIVERED', 'name' => 'Вручен', 'date_time' => '2026-06-14T10:04:41+0000', 'deleted' => true ),
			array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ),
		),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$deleted_status = $status->update( $deleted_status_order );
cdek_order_assert( 'CREATED' === (string) ( $deleted_status['status']['order_status_code'] ?? '' ), 'CDEK deleted statuses must be ignored when selecting current status.' );

$empty_status_order = new CdekOrderFakeOrder( 112 );
$repository->save_for_carrier( $empty_status_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'empty-status-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-112' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'empty-status-uuid',
		'statuses' => array(
			array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000', 'deleted' => true ),
		),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$empty_status = $status->update( $empty_status_order );
cdek_order_assert( $empty_status['success'] && 'registration_pending' === (string) $repository->find_by_carrier( $empty_status_order, CdekSettings::CARRIER_KEY )['status'] && '' === (string) ( $empty_status['status']['order_status_code'] ?? '' ), 'CDEK empty/all-deleted statuses must remain registration_pending and not break status payload.' );

$warning_cost_order = new CdekOrderFakeOrder( 113 );
$warning_cost_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] = array( 'api' => array( 'api_base_price_rub' => 450.0 ) );
$repository->save_for_carrier( $warning_cost_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'warning-cost-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-113' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'warning-cost-uuid',
		'delivery_detail' => array( 'total_sum' => 470 ),
		'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ) ),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$warning_cost = $status->update( $warning_cost_order );
cdek_order_assert( 'warning' === (string) ( $warning_cost['status']['actual_cost_compare_status'] ?? '' ), 'CDEK actual cost above 3 percent of base API cost must compare as warning.' );

$missing_cost_order = new CdekOrderFakeOrder( 114 );
$repository->save_for_carrier( $missing_cost_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'missing-cost-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-114' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'missing-cost-uuid',
		'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ) ),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$missing_cost = $status->update( $missing_cost_order );
cdek_order_assert( '' === (string) ( $missing_cost['status']['actual_cost_label'] ?? '' ) && '' === (string) ( $missing_cost['status']['actual_cost_compare_status'] ?? '' ), 'Missing CDEK delivery_detail.total_sum must not render actual cost comparison.' );

$manual_overwrite_order = new CdekOrderFakeOrder( 115 );
$repository->save_for_carrier( $manual_overwrite_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'manual-overwrite-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-115', 'actual_cost_kopecks' => 100000, 'actual_cost_source' => 'manual', 'actual_cost_updated_at' => '2026-06-01 10:00:00' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'manual-overwrite-uuid',
		'delivery_detail' => array( 'total_sum' => 1200 ),
		'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:48:44+0000' ) ),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$status->update( $manual_overwrite_order );
$manual_overwritten = $repository->find_by_carrier( $manual_overwrite_order, CdekSettings::CARRIER_KEY );
cdek_order_assert( 120000 === (int) ( $manual_overwritten['actual_cost_kopecks'] ?? 0 ) && 'carrier_status' === (string) ( $manual_overwritten['actual_cost_source'] ?? '' ), 'Positive CDEK status actual cost must overwrite manual actual cost through common service.' );

$zero_cost_keeps_order = new CdekOrderFakeOrder( 116 );
$repository->save_for_carrier( $zero_cost_keeps_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'zero-cost-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-116', 'actual_cost_kopecks' => 100000, 'actual_cost_source' => 'carrier_api', 'actual_cost_updated_at' => '2026-06-01 10:00:00' ) );
$http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'zero-cost-uuid',
		'delivery_detail' => array( 'total_sum' => 0 ),
		'statuses' => array( array( 'code' => 'RECEIVED_AT_SHIPMENT_WAREHOUSE', 'name' => 'Принят на складе', 'date_time' => '2026-06-13T10:04:33+0000' ) ),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$zero_cost_update = $status->update( $zero_cost_keeps_order );
$zero_cost_kept = $repository->find_by_carrier( $zero_cost_keeps_order, CdekSettings::CARRIER_KEY );
cdek_order_assert( 100000 === (int) ( $zero_cost_kept['actual_cost_kopecks'] ?? 0 ) && 'carrier_api' === (string) ( $zero_cost_kept['actual_cost_source'] ?? '' ) && '2026-06-01 10:00:00' === (string) ( $zero_cost_kept['actual_cost_updated_at'] ?? '' ), 'Zero CDEK status actual cost must not overwrite existing actual cost.' );
cdek_order_assert( 'RECEIVED_AT_SHIPMENT_WAREHOUSE' === (string) ( $zero_cost_kept['cdek_order_status_code'] ?? '' ) && 'Принят на складе' === (string) ( $zero_cost_update['status']['carrier_status_title'] ?? '' ), 'CDEK status fields must still update when status response has zero actual cost.' );

$order_invalid = new CdekOrderFakeOrder( 102 );
$repository->save_for_carrier( $order_invalid, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'bad-uuid', 'status' => 'registration_pending', 'order_num' => 'WC-102' ) );
$http->order_responses[] = array( 'entity' => array( 'uuid' => 'bad-uuid' ), 'requests' => array( array( 'state' => 'INVALID', 'errors' => array( array( 'message' => 'bad request' ) ) ) ) );
$invalid = $status->update( $order_invalid );
cdek_order_assert( $invalid['success'] && 'failed' === (string) $repository->find_by_carrier( $order_invalid, CdekSettings::CARRIER_KEY )['status'], 'GET /v2/orders INVALID must fail shipment.' );

cdek_order_assert( isset( $created['status'], $invalid['status'] ), 'Status AJAX payload data must contain status for toast/UI.' );
cdek_order_assert( CdekSettings::CARRIER_KEY === (string) $created['status']['carrier_key'], 'CDEK status payload must be carrier-aware.' );

$attach_http = new CdekOrderFakeHttp();
$attach_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $attach_http ), $settings, $attach_http );
$attach_repository = new OrderShipmentRepository();
$attach_status = cdek_order_status_service( $attach_repository, $attach_client );
$attach_order = new CdekOrderFakeOrder( 103 );
$attach_http->order_responses[] = array( 'entity' => array( 'uuid' => 'manual-uuid', 'cdek_number' => '100501', 'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$attached = $attach_status->attach_by_cdek_number( $attach_order, '100501' );
cdek_order_assert( $attached['success'] && CdekSettings::CARRIER_KEY === (string) ( $attached['status']['carrier_key'] ?? '' ), 'Manual attach CDEK must return status payload.' );
$attached_shipment = $attach_repository->find_by_carrier( $attach_order, CdekSettings::CARRIER_KEY );
cdek_order_assert( '100501' === (string) ( $attached_shipment['cdek_number'] ?? '' ) && 'manual-uuid' === (string) ( $attached_shipment['external_id'] ?? '' ), 'Manual attach CDEK must save cdek_number and uuid.' );
$attach_http->order_responses[] = array( 'entity' => array( 'uuid' => 'manual-uuid', 'cdek_number' => '100501', 'statuses' => array( array( 'code' => 'CREATED', 'name' => 'Создан' ) ) ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$manual_update = $attach_status->update( $attach_order );
cdek_order_assert( $manual_update['success'] && ! empty( $manual_update['status']['can_update_status'] ), 'Manual attached CDEK shipment must support update status.' );

$attach_pending_http = new CdekOrderFakeHttp();
$attach_pending_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $attach_pending_http ), $settings, $attach_pending_http );
$attach_pending_repository = new OrderShipmentRepository();
$attach_pending_status = cdek_order_status_service( $attach_pending_repository, $attach_pending_client );
$attach_pending_order = new CdekOrderFakeOrder( 120 );
$attach_pending_http->order_responses[] = array( 'entity' => array( 'uuid' => 'manual-pending-uuid', 'cdek_number' => '100520', 'statuses' => array() ), 'requests' => array( array( 'state' => 'SUCCESSFUL' ) ) );
$attach_pending = $attach_pending_status->attach_by_cdek_number( $attach_pending_order, '100520' );
$attach_pending_shipment = $attach_pending_repository->find_by_carrier( $attach_pending_order, CdekSettings::CARRIER_KEY );
cdek_order_assert( $attach_pending['success'] && 'registration_pending' === (string) ( $attach_pending_shipment['status'] ?? '' ), 'Manual attach shipment_from_body must keep SUCCESSFUL without order statuses as registration_pending.' );

$not_found_http = new CdekOrderFakeHttp();
$not_found_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $not_found_http ), $settings, $not_found_http );
$not_found_repository = new OrderShipmentRepository();
$not_found_status = cdek_order_status_service( $not_found_repository, $not_found_client );
$not_found_order = new CdekOrderFakeOrder( 104 );
$not_found_http->order_responses[] = array( 'entity' => array(), 'requests' => array( array( 'state' => 'INVALID', 'errors' => array( array( 'message' => 'not found' ) ) ) ) );
$not_found = $not_found_status->attach_by_cdek_number( $not_found_order, 'missing' );
cdek_order_assert( ! $not_found['success'] && array() === $not_found_repository->find_by_carrier( $not_found_order, CdekSettings::CARRIER_KEY ), 'Manual attach not found must not save shipment.' );

$cancel_http = new CdekOrderFakeHttp();
$cancel_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $cancel_http ), $settings, $cancel_http );
$cancel_repository = new OrderShipmentRepository();
$cancel_status = cdek_order_status_service( $cancel_repository, $cancel_client );
$cancel_order = new CdekOrderFakeOrder( 105 );
$cancel_repository->save_for_carrier( $cancel_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'created-uuid', 'cdek_number' => '100502', 'status' => 'registered', 'cdek_order_status_code' => 'CREATED', 'cdek_order_status_label' => 'Создан' ) );
$cancel_payload = $cancel_status->status_payload( $cancel_repository->find_by_carrier( $cancel_order, CdekSettings::CARRIER_KEY ) );
cdek_order_assert( ! empty( $cancel_payload['can_cancel'] ), 'CREATED CDEK shipment must allow cancel/delete in CDEK.' );
$cancel_http->delete_responses[] = array( 'entity' => array( 'uuid' => 'created-uuid' ), 'requests' => array( array( 'request_uuid' => 'delete-1', 'state' => 'ACCEPTED' ) ) );
$cancelled = $cancel_status->cancel_created_order( $cancel_order );
$delete_count = count( array_filter( $cancel_http->requests, static fn ( array $request ): bool => 'DELETE' === $request['method'] && str_contains( $request['url'], '/v2/orders/created-uuid' ) ) );
cdek_order_assert( $cancelled['success'] && 1 === $delete_count && array() === $cancel_repository->find_by_carrier( $cancel_order, CdekSettings::CARRIER_KEY ), 'CDEK cancel/delete must call API and remove local shipment on success.' );
cdek_order_assert( array( 'Отменено отправление СДЭК 100502. Мест: 1.' ) === $cancel_order->notes, 'Successful CDEK cancel/delete must add a cancellation order note.' );

$cancel_fail_http = new CdekOrderFakeHttp();
$cancel_fail_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $cancel_fail_http ), $settings, $cancel_fail_http );
$cancel_fail_repository = new OrderShipmentRepository();
$cancel_fail_status = cdek_order_status_service( $cancel_fail_repository, $cancel_fail_client );
$cancel_fail_order = new CdekOrderFakeOrder( 110 );
$cancel_fail_repository->save_for_carrier( $cancel_fail_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'created-fail-uuid', 'cdek_number' => '100506', 'status' => 'registered', 'cdek_order_status_code' => 'CREATED' ) );
$cancel_fail_http->delete_responses[] = array( 'entity' => array( 'uuid' => 'created-fail-uuid' ), 'requests' => array( array( 'request_uuid' => 'delete-fail', 'state' => 'INVALID', 'errors' => array( array( 'message' => 'delete failed' ) ) ) ) );
$cancel_failed = $cancel_fail_status->cancel_created_order( $cancel_fail_order );
cdek_order_assert( ! $cancel_failed['success'] && array() === $cancel_fail_order->notes && array() !== $cancel_fail_repository->find_by_carrier( $cancel_fail_order, CdekSettings::CARRIER_KEY ), 'Failed CDEK cancel/delete must not add a cancellation note or remove local shipment.' );

$forbidden_cancel_http = new CdekOrderFakeHttp();
$forbidden_cancel_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $forbidden_cancel_http ), $settings, $forbidden_cancel_http );
$forbidden_cancel_repository = new OrderShipmentRepository();
$forbidden_cancel_status = cdek_order_status_service( $forbidden_cancel_repository, $forbidden_cancel_client );
$forbidden_cancel_order = new CdekOrderFakeOrder( 106 );
$forbidden_cancel_repository->save_for_carrier( $forbidden_cancel_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'accepted-uuid', 'cdek_number' => '100503', 'status' => 'registered', 'cdek_order_status_code' => 'ACCEPTED', 'cdek_order_status_label' => 'Принят' ) );
$forbidden_cancel_payload = $forbidden_cancel_status->status_payload( $forbidden_cancel_repository->find_by_carrier( $forbidden_cancel_order, CdekSettings::CARRIER_KEY ) );
$forbidden_cancel = $forbidden_cancel_status->cancel_created_order( $forbidden_cancel_order );
$forbidden_delete_count = count( array_filter( $forbidden_cancel_http->requests, static fn ( array $request ): bool => 'DELETE' === $request['method'] ) );
cdek_order_assert( empty( $forbidden_cancel_payload['can_cancel'] ) && ! $forbidden_cancel['success'] && 0 === $forbidden_delete_count, 'CDEK cancel/delete must be forbidden outside CREATED and must not call API.' );

$remove_http = new CdekOrderFakeHttp();
$remove_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $remove_http ), $settings, $remove_http );
$remove_repository = new OrderShipmentRepository();
$remove_status = cdek_order_status_service( $remove_repository, $remove_client );
$remove_order = new CdekOrderFakeOrder( 107 );
$remove_repository->save_for_carrier( $remove_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => 'delivered-uuid', 'cdek_number' => '100504', 'status' => 'registered', 'cdek_order_status_code' => 'DELIVERED', 'cdek_order_status_label' => 'Вручен' ) );
$remove_payload = $remove_status->status_payload( $remove_repository->find_by_carrier( $remove_order, CdekSettings::CARRIER_KEY ) );
$removed = $remove_status->remove_local_if_allowed( $remove_order );
$remove_delete_count = count( array_filter( $remove_http->requests, static fn ( array $request ): bool => 'DELETE' === $request['method'] ) );
cdek_order_assert( ! empty( $remove_payload['can_remove_from_order'] ) && $removed['success'] && 0 === $remove_delete_count && array() === $remove_repository->find_by_carrier( $remove_order, CdekSettings::CARRIER_KEY ), 'Allowed CDEK local remove must not call API and must remove local shipment.' );
cdek_order_assert( array() === $remove_order->notes, 'CDEK local-only remove must not add a cancellation order note.' );

foreach ( array( 'ACCEPTED', 'CREATED' ) as $protected_status ) {
	$protected_repository = new OrderShipmentRepository();
	$protected_http = new CdekOrderFakeHttp();
	$protected_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $protected_http ), $settings, $protected_http );
	$protected_service = cdek_order_status_service( $protected_repository, $protected_client );
	$protected_order = new CdekOrderFakeOrder( 'ACCEPTED' === $protected_status ? 108 : 109 );
	$protected_repository->save_for_carrier( $protected_order, CdekSettings::CARRIER_KEY, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'external_id' => strtolower( $protected_status ) . '-uuid', 'cdek_number' => '100505', 'status' => 'registered', 'cdek_order_status_code' => $protected_status ) );
	$protected_payload = $protected_service->status_payload( $protected_repository->find_by_carrier( $protected_order, CdekSettings::CARRIER_KEY ) );
	$protected_remove = $protected_service->remove_local_if_allowed( $protected_order );
	cdek_order_assert( empty( $protected_payload['can_remove_from_order'] ) && ! $protected_remove['success'], 'CDEK local remove must be forbidden for ' . $protected_status . '.' );
}

$tariff_db = new wpdb();
$tariff_db->cdek_tariffs = array(
	array( 'tariff_code' => '136', 'tariff_name_from_cdek' => 'Посылка склад-склад', 'custom_title' => 'Кастомный ПВЗ', 'delivery_type' => DeliveryType::PICKUP, 'is_active' => 1, 'created_at' => '2026-06-13 12:00:00', 'updated_at' => '2026-06-13 12:00:00' ),
	array( 'tariff_code' => '138', 'tariff_name_from_cdek' => 'Эконом склад-склад', 'custom_title' => '', 'delivery_type' => DeliveryType::PICKUP, 'is_active' => 1, 'created_at' => '2026-06-13 12:00:00', 'updated_at' => '2026-06-13 12:00:00' ),
	array( 'tariff_code' => '137', 'tariff_name_from_cdek' => 'Посылка склад-дверь', 'custom_title' => 'Курьер кастом', 'delivery_type' => DeliveryType::COURIER, 'is_active' => 1, 'created_at' => '2026-06-13 12:00:00', 'updated_at' => '2026-06-13 12:00:00' ),
	array( 'tariff_code' => '139', 'tariff_name_from_cdek' => 'Неактивный ПВЗ', 'custom_title' => '', 'delivery_type' => DeliveryType::PICKUP, 'is_active' => 0, 'created_at' => '2026-06-13 12:00:00', 'updated_at' => '2026-06-13 12:00:00' ),
);
$tariff_repository = new CdekTariffRepository( $tariff_db );
$services = new DeliveryServiceRepository( new wpdb() );
$drafts = new OrderShipmentDraftFactory( $services, new ShipmentServiceSettings(), null, null, null, $settings, $tariff_repository );
$draft_order = new CdekOrderFakeOrder( 130 );
$draft_order->meta['_wdc_platform_carrier_key'] = CdekSettings::CARRIER_KEY;
$draft_order->meta['_wdc_platform_delivery_type'] = DeliveryType::PICKUP;
$draft_order->meta['_wdc_delivery_calculation_data'] = array(
	'carrier_key' => CdekSettings::CARRIER_KEY,
	'selected_tariff_object' => '136',
	'selected_tariff_title' => 'Посылка склад-склад',
	'pickup' => array( 'cdek_code' => 'ISK1', 'point_code' => 'ISK1', 'point_address' => 'Искитим, ПВЗ', 'point_postcode' => '633209', 'city_name' => 'Искитим', 'region_name' => 'Новосибирская область' ),
	'api' => array( 'response_tariff_sanitized' => array( 'delivery_mode' => 4 ), 'cdek_to_city_code' => 270 ),
	'package' => array( 'products_weight_g' => 500, 'dimensions_cm' => array( 'length' => 20, 'width' => 15, 'height' => 10 ) ),
);
$draft = $drafts->draft_array( $draft_order );
$draft_request = $draft['request'];
$pickup_service = array_values( array_filter( $draft['services'], static fn ( array $service ): bool => DeliveryType::PICKUP === (string) ( $service['delivery_type'] ?? '' ) ) )[0] ?? array();
$pickup_tariffs = is_array( $pickup_service['tariffs'] ?? null ) ? $pickup_service['tariffs'] : array();
$pickup_codes = array_map( static fn ( array $row ): string => (string) ( $row['object_code'] ?? '' ), $pickup_tariffs );
cdek_order_assert( array( '136', '138' ) === $pickup_codes && '136' === (string) ( $draft_request['meta']['tariff_code'] ?? '' ), 'CDEK shipment modal pickup tariff select must include active pickup tariffs only and keep selected order tariff.' );
cdek_order_assert( str_contains( (string) ( $pickup_tariffs[0]['title'] ?? '' ), 'Кастомный ПВЗ' ) && str_contains( (string) ( $pickup_tariffs[1]['title'] ?? '' ), 'Эконом склад-склад' ), 'CDEK tariff titles must use custom_title first and CDEK name as fallback.' );
cdek_order_assert( ! in_array( '137', $pickup_codes, true ) && ! in_array( '139', $pickup_codes, true ), 'CDEK pickup modal tariffs must exclude courier and inactive tariffs.' );
$location_context = is_array( $draft_request['meta']['pickup_location_context'] ?? null ) ? $draft_request['meta']['pickup_location_context'] : array();
cdek_order_assert( CdekSettings::CARRIER_KEY === (string) ( $location_context['carrier_key'] ?? '' ) && 'cdek:pickup' === (string) ( $location_context['pickup_family'] ?? '' ), 'CDEK admin map context must use CDEK carrier and pickup family.' );
cdek_order_assert( 'Кемерово' === (string) ( $location_context['city_name'] ?? '' ) && 'Новосибирск' !== (string) ( $location_context['city_name'] ?? '' ), 'CDEK admin map location context must come from recipient city, not sender city.' );
$weight_hint_order = new CdekOrderFakeOrder( 136 );
$weight_hint_order->meta = $draft_order->meta;
$weight_hint_order->meta['_wdc_delivery_calculation_data']['package'] = array(
	'products_weight_g' => 2100,
	'packaging_weight_g' => 150,
	'dimensions_cm' => array( 'length' => 20, 'width' => 15, 'height' => 10 ),
);
$weight_hint_draft = $drafts->draft_array( $weight_hint_order );
cdek_order_assert( 2250 === (int) ( $weight_hint_draft['request']['meta']['place_weight_hint_g'] ?? 0 ), 'Shipment modal one-place weight hint must use products weight plus packaging weight.' );
$weight_hint_no_pack_order = new CdekOrderFakeOrder( 137 );
$weight_hint_no_pack_order->meta = $draft_order->meta;
$weight_hint_no_pack_order->meta['_wdc_delivery_calculation_data']['package'] = array(
	'products_weight_g' => 2100,
	'dimensions_cm' => array( 'length' => 20, 'width' => 15, 'height' => 10 ),
);
$weight_hint_no_pack_draft = $drafts->draft_array( $weight_hint_no_pack_order );
cdek_order_assert( 2100 === (int) ( $weight_hint_no_pack_draft['request']['meta']['place_weight_hint_g'] ?? 0 ), 'Shipment modal weight hint must fall back to products weight when packaging weight is missing.' );
$saved_pickup_order = new CdekOrderFakeOrder( 134 );
$saved_pickup_order->meta = $draft_order->meta;
$saved_pickup_order->meta['_wdc_pickup_point_code'] = 'MSK575';
$saved_pickup_order->meta['_wdc_platform_pickup_code'] = 'MSK575';
$saved_pickup_order->meta['_wdc_delivery_calculation_data']['pickup'] = array(
	'carrier_key' => CdekSettings::CARRIER_KEY,
	'service_key' => CdekSettings::SERVICE_KEY,
	'pickup_family' => 'cdek:pickup',
	'point_code' => 'MSK575',
	'cdek_code' => 'MSK575',
	'delivery_point' => 'MSK575',
	'point_address' => '101000, Россия, Москва, Москва, б-р. Чистопрудный, 13с1',
	'point_postcode' => '101000',
);
$saved_pickup_draft = $drafts->draft_array( $saved_pickup_order );
cdek_order_assert( 'MSK575' === (string) ( $saved_pickup_draft['request']['meta']['delivery_point'] ?? '' ) && 'MSK575' === (string) ( $saved_pickup_draft['request']['meta']['pickup_point_code'] ?? '' ), 'CDEK shipment draft must keep canonical saved pickup code for modal order creation.' );
$saved_pickup_request = $drafts->create_request_from_admin_data(
	$saved_pickup_order,
	array(
		'delivery_type' => DeliveryType::PICKUP,
		'tariff_object' => '136',
		'delivery_point' => '',
		'pickup_point_code' => '',
		'pickup_point_address' => '101000, Россия, Москва, Москва, б-р. Чистопрудный, 13с1',
		'pickup_point_postcode' => '101000',
		'places' => array( array( 'weight_g' => 2000, 'length_cm' => '20', 'width_cm' => '15', 'height_cm' => '10' ) ),
		'shipment_items' => array( array( 'item_key' => 'saved-pickup-item', 'ordered_quantity' => 1, 'place_number' => 1, 'name' => 'Товар', 'ware_key' => 'SKU-1', 'amount' => 1, 'cost' => 1000, 'weight' => 100 ) ),
	)
);
cdek_order_assert( 'MSK575' === (string) ( $saved_pickup_request->meta['delivery_point'] ?? '' ) && $saved_pickup_request->pickup_point instanceof PickupPointSelection && 'MSK575' === $saved_pickup_request->pickup_point->point_code, 'CDEK shipment admin request must fall back to canonical saved pickup code when modal sends only address.' );
$address_only_order = new CdekOrderFakeOrder( 135 );
$address_only_order->meta = $draft_order->meta;
$address_only_order->meta['_wdc_delivery_calculation_data']['pickup'] = array(
	'carrier_key' => CdekSettings::CARRIER_KEY,
	'service_key' => CdekSettings::SERVICE_KEY,
	'pickup_family' => 'cdek:pickup',
	'point_address' => '101000, Россия, Москва, Москва, б-р. Чистопрудный, 13с1',
	'point_postcode' => '101000',
);
$address_only_draft = $drafts->draft_array( $address_only_order );
cdek_order_assert( '' === (string) ( $address_only_draft['request']['meta']['delivery_point'] ?? '' ), 'CDEK shipment draft must not treat pickup address/postcode as delivery_point.' );
$address_only_request = $drafts->create_request_from_admin_data(
	$address_only_order,
	array(
		'delivery_type' => DeliveryType::PICKUP,
		'tariff_object' => '136',
		'delivery_point' => '101000',
		'pickup_point_code' => '101000',
		'pickup_point_address' => '101000, Россия, Москва, Москва, б-р. Чистопрудный, 13с1',
		'pickup_point_postcode' => '101000',
		'places' => array( array( 'weight_g' => 2000, 'length_cm' => '20', 'width_cm' => '15', 'height_cm' => '10' ) ),
		'shipment_items' => array( array( 'item_key' => 'address-only-item', 'ordered_quantity' => 1, 'place_number' => 1, 'name' => 'Товар', 'ware_key' => 'SKU-1', 'amount' => 1, 'cost' => 1000, 'weight' => 100 ) ),
	)
);
cdek_order_assert( '' === (string) ( $address_only_request->meta['delivery_point'] ?? '' ) && array() !== $builder->validate( $address_only_request ), 'CDEK shipment admin request must reject postcode-only pickup code.' );
$courier_order = new CdekOrderFakeOrder( 131 );
$courier_order->meta['_wdc_platform_carrier_key'] = CdekSettings::CARRIER_KEY;
$courier_order->meta['_wdc_platform_delivery_type'] = DeliveryType::COURIER;
$courier_order->meta['_wdc_delivery_calculation_data'] = array(
	'carrier_key' => CdekSettings::CARRIER_KEY,
	'selected_tariff_object' => '137',
	'selected_tariff_title' => 'Посылка склад-дверь',
	'api' => array( 'response_tariff_sanitized' => array( 'delivery_mode' => 3 ), 'cdek_to_city_code' => 44 ),
	'package' => array( 'products_weight_g' => 500, 'dimensions_cm' => array( 'length' => 20, 'width' => 15, 'height' => 10 ) ),
);
$courier_draft = $drafts->draft_array( $courier_order );
$courier_service = array_values( array_filter( $courier_draft['services'], static fn ( array $service ): bool => DeliveryType::COURIER === (string) ( $service['delivery_type'] ?? '' ) ) )[0] ?? array();
$courier_codes = array_map( static fn ( array $row ): string => (string) ( $row['object_code'] ?? '' ), is_array( $courier_service['tariffs'] ?? null ) ? $courier_service['tariffs'] : array() );
cdek_order_assert( array( '137' ) === $courier_codes, 'CDEK shipment modal courier tariff select must include active courier tariffs only.' );
$dimension_order = new CdekOrderFakeOrder( 133 );
$dimension_order->meta = $draft_order->meta;
$dimension_order->items = array( new CdekOrderFakeOrderItem( new CdekOrderFakeProduct( 'CAT-DIM', '0.4', '36', '12', '4' ), 'Товар с размерами', 2, 1600.0 ) );
$dimension_draft = $drafts->draft_array( $dimension_order );
$dimension_item = $dimension_draft['request']['places'][0]['items'][0] ?? array();
cdek_order_assert( 36 === (int) ( $dimension_item['length_cm'] ?? 0 ) && 12 === (int) ( $dimension_item['width_cm'] ?? 0 ) && 4 === (int) ( $dimension_item['height_cm'] ?? 0 ), 'Shipment modal initial item dimensions must come from WooCommerce product/variation dimensions.' );
$missing_tariff_order = new CdekOrderFakeOrder( 132 );
$missing_tariff_order->meta = $draft_order->meta;
$missing_tariff_order->meta['_wdc_delivery_calculation_data']['selected_tariff_object'] = '999';
$missing_draft = $drafts->draft_array( $missing_tariff_order );
$missing_pickup_service = array_values( array_filter( $missing_draft['services'], static fn ( array $service ): bool => DeliveryType::PICKUP === (string) ( $service['delivery_type'] ?? '' ) ) )[0] ?? array();
$missing_options = is_array( $missing_pickup_service['tariffs'] ?? null ) ? $missing_pickup_service['tariffs'] : array();
$missing_option = array_values( array_filter( $missing_options, static fn ( array $row ): bool => '999' === (string) ( $row['object_code'] ?? '' ) ) )[0] ?? array();
cdek_order_assert( ! empty( $missing_option['selected_missing'] ) && '999' === (string) ( $missing_draft['request']['meta']['tariff_code'] ?? '' ), 'CDEK modal must keep selected tariff value when it is absent from active managed tariffs.' );
$admin_request = $drafts->create_request_from_admin_data(
	$draft_order,
	array(
		'delivery_type' => DeliveryType::PICKUP,
		'tariff_object' => '136',
		'shipment_point' => 'NSK70',
		'shipment_point_address' => 'Новосибирск, новый ПВЗ',
		'delivery_point' => 'NEW1',
		'pickup_point_code' => 'NEW1',
		'pickup_point_address' => 'Новый ПВЗ',
		'pickup_point_city' => 'Кемерово',
		'pickup_point_region' => 'Кемеровская область',
		'places' => array( array( 'weight_g' => 2000, 'length_cm' => '20', 'width_cm' => '15', 'height_cm' => '10' ) ),
		'shipment_items' => array(
			array( 'item_key' => 'decimal-item', 'ordered_quantity' => 2, 'place_number' => 1, 'name' => 'Дробный товар', 'ware_key' => 'DEC-1', 'amount' => 2, 'cost' => '800,50', 'weight' => 250, 'length_cm' => '36,5', 'width_cm' => '12.5', 'height_cm' => '3,5' ),
		),
	)
);
cdek_order_assert( 'NEW1' === (string) ( $admin_request->meta['delivery_point'] ?? '' ) && 'NEW1' === (string) ( $admin_request->meta['pickup_point_code'] ?? '' ) && $admin_request->pickup_point instanceof PickupPointSelection && 'NEW1' === $admin_request->pickup_point->point_code, 'Choosing another CDEK pickup point in modal must update delivery_point and point_code.' );
cdek_order_assert( 'NSK70' === (string) ( $admin_request->meta['shipment_point'] ?? '' ) && 'Новосибирск, новый ПВЗ' === (string) ( $admin_request->meta['shipment_point_address'] ?? '' ), 'Choosing another sender CDEK pickup point in modal must update temporary shipment_point and address.' );
$decimal_rows = is_array( $admin_request->meta['shipment_item_rows'] ?? null ) ? $admin_request->meta['shipment_item_rows'] : array();
cdek_order_assert( 80050 === (int) ( $decimal_rows[0]['unit_price_kopecks'] ?? 0 ) && 80050 === (int) ( $decimal_rows[0]['assessed_unit_price_kopecks'] ?? 0 ) && 36.5 === (float) ( $decimal_rows[0]['length_cm'] ?? 0 ) && 12.5 === (float) ( $decimal_rows[0]['width_cm'] ?? 0 ) && 3.5 === (float) ( $decimal_rows[0]['height_cm'] ?? 0 ) && ! array_key_exists( 'cdek_item_rows', $admin_request->meta ), 'Shipment modal item rows must parse canonical shipment_items into canonical kopeck rows only.' );

$ajax_http = new CdekOrderFakeHttp();
$ajax_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $ajax_http ), $settings, $ajax_http );
$ajax_repository = new OrderShipmentRepository();
$ajax_creation = cdek_order_creation_service( $ajax_repository, new CdekShipmentAdapter( $ajax_client, $builder ) );
$rp_tracking = ( new ReflectionClass( RussianPostTrackingApiClient::class ) )->newInstanceWithoutConstructor();
$status_updates = new ShipmentStatusUpdateService( $ajax_repository, $rp_tracking, new RussianPostTrackingStatusMapper(), cdek_order_actual_cost_resolver() );
$ajax_status = cdek_order_status_service( $ajax_repository, $ajax_client );
$ajax_payloads = new ShipmentAdminCarrierUiPayloadBuilder(
	$ajax_repository,
	$services,
	$status_updates,
	cdek_status_updates: $ajax_status
);
$ajax_create_controller = new ShipmentCreateAjaxController( $ajax_repository, $drafts, $ajax_creation, $ajax_payloads );
$ajax_actual_costs = new ShipmentActualCostService(
	$ajax_repository,
	new ShipmentActualCostResolver( new ShipmentActualCostComparisonService(), new ShipmentBaseApiCostResolver() )
);
$ajax_actual_cost_controller = new ShipmentActualCostAjaxController( $ajax_actual_costs, $ajax_payloads );
$ajax_controller_double = static function ( string $class ): object {
	return ( new ReflectionClass( $class ) )->newInstanceWithoutConstructor();
};
$metabox = new OrderShipmentsMetabox(
	$ajax_repository,
	$drafts,
	$services,
	$status_updates,
	ajax_create_controller: $ajax_create_controller,
	ajax_lifecycle_controller: $ajax_controller_double( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentLifecycleAjaxController::class ),
	ajax_preview_controller: $ajax_controller_double( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentPreviewAjaxController::class ),
	ajax_status_controller: $ajax_controller_double( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentStatusAjaxController::class ),
	ajax_removal_controller: $ajax_controller_double( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentRemovalAjaxController::class ),
	ajax_manual_attach_controller: $ajax_controller_double( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentManualAttachAjaxController::class ),
	ajax_address_controller: $ajax_controller_double( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentAddressAjaxController::class ),
	ajax_actual_cost_controller: $ajax_actual_cost_controller,
	ajax_documents_controller: $ajax_controller_double( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentDocumentsAjaxController::class ),
	ajax_products_controller: $ajax_controller_double( \WallsShop\WDC\Shipments\Admin\Ajax\ShipmentProductsAjaxController::class ),
	cdek_status_updates: $ajax_status,
	modal_extensions: new ShipmentModalExtensionRegistry( array( new CdekShipmentModalExtension() ) )
);
$ajax_order = new CdekOrderFakeOrder();
$ajax_order->meta['_wdc_platform_carrier_key'] = CdekSettings::CARRIER_KEY;
$ajax_order->meta['_wdc_platform_delivery_type'] = DeliveryType::PICKUP;
$ajax_order->meta['_wdc_delivery_calculation_data'] = array(
	'carrier_key' => CdekSettings::CARRIER_KEY,
	'selected_tariff_object' => '136',
	'selected_tariff_title' => 'Посылка склад-склад',
	'pickup' => array( 'cdek_code' => 'KEM7', 'point_code' => 'KEM7', 'point_address' => 'Кемерово, ПВЗ', 'point_postcode' => '650000' ),
	'api' => array( 'response_tariff_sanitized' => array( 'delivery_mode' => 4 ), 'cdek_to_city_code' => 44 ),
	'package' => array( 'products_weight_g' => 500, 'dimensions_cm' => array( 'length' => 20, 'width' => 15, 'height' => 10 ) ),
);
$GLOBALS['wdc_cdek_order_ajax_order'] = $ajax_order;
$_POST = array(
	'order_id' => 101,
	'nonce' => 'ok',
	'delivery_type' => DeliveryType::PICKUP,
	'recipient_name' => 'Иван Иванов',
	'recipient_phone' => '9131234567',
	'recipient_email' => 'buyer@example.com',
	'tariff_object' => '136',
	'places' => array( array( 'weight_g' => 1000, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10 ) ),
	'shipment_items' => array( array( 'item_key' => '1', 'ordered_quantity' => 1, 'place_number' => 1, 'name' => 'Товар', 'ware_key' => 'SKU-1', 'amount' => 1, 'cost' => 1000, 'weight' => 100 ) ),
);
try {
	$ajax_create_controller->handle();
	throw new RuntimeException( 'ajax_create did not send JSON.' );
} catch ( CdekOrderAjaxResponse $response ) {
	cdek_order_assert( $response->success, 'ajax_create for CDEK must succeed.' );
	cdek_order_assert( CdekSettings::CARRIER_KEY === (string) ( $response->data['status']['carrier_key'] ?? '' ), 'ajax_create for CDEK must return CDEK status payload.' );
}

ob_start();
$metabox->render( $draft_order );
$modal_html = ob_get_clean() ?: '';
cdek_order_assert( ! str_contains( $modal_html, 'tariff_code:' ) && ! str_contains( $modal_html, 'delivery_mode:' ), 'CDEK shipment modal must not render technical tariff_code/delivery_mode labels.' );
cdek_order_assert( str_contains( $modal_html, 'В заказе тариф' ) && str_contains( $modal_html, 'Кастомный ПВЗ' ), 'CDEK shipment modal must render human selected tariff title.' );
cdek_order_assert( str_contains( $modal_html, 'ПВЗ отправителя' ) && str_contains( $modal_html, 'NSK69' ) && str_contains( $modal_html, 'Новосибирск, Красный проспект 1' ) && str_contains( $modal_html, 'Выбрать другой ПВЗ отправителя' ), 'CDEK shipment modal must render sender shipment_point code/address and temporary replacement button.' );
cdek_order_assert( str_contains( $modal_html, 'Код ПВЗ' ) && str_contains( $modal_html, 'ISK1' ), 'CDEK shipment modal must show recipient CDEK point code, not index label.' );
ob_start();
$metabox->render( $weight_hint_order );
$weight_hint_modal_html = ob_get_clean() ?: '';
cdek_order_assert( str_contains( $weight_hint_modal_html, 'data-wdc-weight-hint' ) && str_contains( $weight_hint_modal_html, '⚖️2250' ), 'Shipment modal one-place weight label must render calculated weight as a compact hint.' );
cdek_order_assert( 1 === preg_match( '/name="places\[0\]\[weight_g\]"[^>]*value=""/', $weight_hint_modal_html ) && 1 === preg_match( '/name="places\[0\]\[length_cm\]"[^>]*value=""/', $weight_hint_modal_html ) && 1 === preg_match( '/name="places\[0\]\[width_cm\]"[^>]*value=""/', $weight_hint_modal_html ) && 1 === preg_match( '/name="places\[0\]\[height_cm\]"[^>]*value=""/', $weight_hint_modal_html ), 'Shipment modal initial factual weight and dimensions must be empty for CDEK too.' );
cdek_order_assert( ! str_contains( $weight_hint_modal_html, 'name="places[0][weight_g]" value="2250"' ) && ! str_contains( $weight_hint_modal_html, 'name="places[0][length_cm]" value="20"' ), 'Shipment modal must not put calculated package values into editable place inputs.' );
cdek_order_assert( str_contains( $modal_html, 'Артикул' ) && str_contains( $modal_html, 'Кол-во' ) && str_contains( $modal_html, 'Цена' ) && ! str_contains( $modal_html, 'SKU / ware_key' ) && ! str_contains( $modal_html, '<td><code>' ), 'CDEK packages tab must render improved Russian item table headers without code-styled SKU.' );
cdek_order_assert( str_contains( $modal_html, 'data-wdc-weight-hint' ) && str_contains( $modal_html, '⚖️' ) && str_contains( $modal_html, 'Добавить товар' ) && str_contains( $modal_html, 'data-wdc-add-manual-shipment-item' ) && str_contains( $modal_html, 'data-wdc-shipment-items-table' ), 'Shipment packages UI must render calculated weight hint, universal table hook, and manual item add button.' );
cdek_order_assert( str_contains( $modal_html, 'name="pickup_carrier_key" value="cdek"' ) && str_contains( $modal_html, 'name="pickup_family" value="cdek:pickup"' ), 'CDEK shipment modal must render CDEK carrier context for admin pickup map.' );
cdek_order_assert( str_contains( $modal_html, 'name="recipient_location_city" value="Кемерово"' ) && ! str_contains( $modal_html, 'name="recipient_location_city" value="Новосибирск"' ), 'CDEK shipment modal map context must use recipient locality, not sender locality.' );

$shipments_js = wdc_shipment_admin_js_bundle_source();
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'window.WDCPickupApi.addressSearch' ) && str_contains( $shipments_js, 'addressMarkerFromResult' ) && str_contains( $shipments_js, 'provider.setCenter(searchMarker.lat, searchMarker.lng, 15);' ), 'CDEK shipment modal pickup map must use shared DaData address search and focus the temporary marker.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, "data.append('order_id', fieldValue(form, 'input[name=\"order_id\"]') || '')" ), 'Shipment modal pickup point search must send order_id for Russian Post backend fallback.' );
cdek_order_assert( is_string( $shipments_js ) && ! str_contains( $shipments_js, 'через DaData' ) && str_contains( $shipments_js, "status.textContent = 'Ищем адрес...'" ) && str_contains( $shipments_js, "'Адрес найден.'" ) && str_contains( $shipments_js, "'Адрес не найден.'" ), 'CDEK shipment modal pickup map must use neutral address-search UI messages.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'data-wdc-pickup-picker-confirm' ) && ! str_contains( $shipments_js, 'data-wdc-pickup-picker-choose' ) && ! str_contains( $shipments_js, 'data-wdc-pickup-popup-select' ) && ! str_contains( $shipments_js, 'wdc-admin-pickup-picker__selected-grid' ), 'CDEK shipment modal pickup map must use one bottom select button and no duplicate per-card controls.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, "'ПВЗ СДЭК'" ) && str_contains( $shipments_js, "'Постамат СДЭК'" ), 'CDEK shipment modal pickup map must render CDEK pickup/postamat titles.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'function senderPickupContext' ) && str_contains( $shipments_js, 'Выбор ПВЗ отправителя СДЭК' ) && str_contains( $shipments_js, 'updateSenderPickupDraft' ), 'CDEK shipment modal must support temporary sender pickup point selection.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'const maxAttempts = 14' ) && str_contains( $shipments_js, 'const interval = 5000' ) && ! str_contains( $shipments_js, '10 минут' ), 'CDEK auto polling must run every 5 seconds up to 14 attempts and avoid old 10-minute text.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'setShipmentPollingIndicator' ) && str_contains( $modal_html, 'data-wdc-shipment-polling-indicator' ) && str_contains( $modal_html, 'data-wdc-cdek-polling-indicator' ), 'CDEK auto polling must expose and toggle the generic visible registration-check indicator while keeping legacy markup.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, "box.querySelector('[data-wdc-shipment-status-message]')" ) && str_contains( $shipments_js, "message.textContent = ''" ) && str_contains( $shipments_js, "message.dataset.status = ''" ) && str_contains( $shipments_js, 'setShipmentPollingIndicator(box, false)' ), 'Shipment UI reset after CDEK cancel must clear persistent status message and hide polling indicator.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'hint.hidden = places.length !== 1' ), 'Shipment modal weight hint must be hidden when there is more than one place.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'data-wdc-remove-shipment-split' ) && str_contains( $shipments_js, 'restoreOriginalBaseRow' ) && str_contains( $shipments_js, 'data-wdc-original-item' ) && str_contains( $shipments_js, 'rebalanceShipmentItemGroup' ) && ! str_contains( $shipments_js, 'rebalanceCdekGroup' ) && ! str_contains( $shipments_js, 'Date.now()' ) && ! str_contains( $shipments_js, 'data-wdc-cdek-minus' ), 'Shipment split UI must use stable row keys, generic function names, delete split rows, restore original base rows after place removal, and avoid +/- controls.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, "clone.setAttribute('data-wdc-split-row', '1')" ) && str_contains( $shipments_js, "clone.removeAttribute('data-wdc-base-row')" ) && str_contains( $shipments_js, "removeAttribute('data-wdc-shipment-item-split')" ) && ! str_contains( $shipments_js, "removeAttribute('data-wdc-cdek-split')" ) && str_contains( $shipments_js, 'data-wdc-remove-shipment-split' ) && ! str_contains( $shipments_js, 'data-wdc-remove-cdek-split' ) && str_contains( $shipments_js, '❌' ), 'Shipment split child row must remove split action hooks and render the generic delete action.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'wdc_search_products_for_shipment_item' ) && str_contains( $shipments_js, 'applyProductToManualRow' ) && str_contains( $shipments_js, 'data-wdc-product-search-input' ), 'CDEK manual item rows must support catalog product search.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'normalizeQtyRows' ) && str_contains( $shipments_js, 'targetTotal - 1' ) && str_contains( $shipments_js, 'rebalanceShipmentItemGroup(integerForm, row.getAttribute' ), 'Shipment split quantities must clamp row max to N-1 and rebalance when base or split quantity changes.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'function parseDecimalValue' ) && str_contains( $shipments_js, 'parseDecimalValue(cost' ) && str_contains( $shipments_js, 'cleanDecimalInput' ) && str_contains( $shipments_js, 'separatorMatch' ), 'Shipment package summary and decimal inputs must accept comma and dot decimal separators.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'focusout' ) && str_contains( $shipments_js, '_wdcProductSearchBlurTimer' ) && str_contains( $shipments_js, 'renderProductSearchResults(event.target, [])' ), 'Shipment product search dropdown must close on focus out without auto-filling a product.' );
$legacy_document_payload_key = 'label_' . 'actions';
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'documentActions' ) && str_contains( $shipments_js, 'document_actions' ) && ! str_contains( $shipments_js, $legacy_document_payload_key ) && str_contains( $shipments_js, 'data-wdc-shipment-document-download' ) && str_contains( $shipments_js, 'data-wdc-cdek-barcode-download' ) && ! str_contains( $shipments_js, 'canPrintBarcode' ) && ! str_contains( $shipments_js, 'data-wdc-cdek-barcode-inline' ), 'Shipment JS must toggle document buttons through normalized documentActions and the canonical document_actions payload key; CDEK extension owns the carrier label download click.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'requestCdekBarcodeDownload' ) && str_contains( $shipments_js, 'wdc_cdek_barcode_prepare' ) && str_contains( $shipments_js, 'CDEK_BARCODE_POLL_INTERVAL_MS = 2000' ) && str_contains( $shipments_js, 'CDEK_BARCODE_TIMEOUT_MS = 300000' ) && str_contains( $shipments_js, 'CDEK_BARCODE_RESET_MS = 1500' ), 'CDEK label download click must poll prepare AJAX and reset shortly after download starts.' );
cdek_order_assert( is_string( $shipments_js ) && ! str_contains( $shipments_js, 'iframe.src' ) && ! str_contains( $shipments_js, 'data-wdc-cdek-barcode-download-frame' ) && str_contains( $shipments_js, "replace(/&amp;/g, '&')" ) && str_contains( $shipments_js, 'fetch(downloadUrl' ) && str_contains( $shipments_js, 'response.ok' ) && str_contains( $shipments_js, 'application/pdf' ) && str_contains( $shipments_js, 'response.blob()' ) && str_contains( $shipments_js, 'blob.size <= 0' ) && str_contains( $shipments_js, 'URL.createObjectURL' ) && str_contains( $shipments_js, "document.createElement('a')" ) && str_contains( $shipments_js, 'anchor.download' ) && str_contains( $shipments_js, 'Сервер вернул не PDF-файл этикетки СДЭК.' ) && str_contains( $shipments_js, 'Не удалось скачать этикетку СДЭК.' ), 'CDEK label download must normalize escaped URLs, fetch a PDF blob, validate it, and start download through an object URL anchor.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'вес места ' ) && str_contains( $shipments_js, 'заполнено: товары ' ) && str_contains( $shipments_js, ' руб.' ), 'Shipment package summary must show place weight, item count, item weight and cost.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, 'data-wdc-shipment-items-table' ) && str_contains( $shipments_js, 'data-wdc-shipment-item-row' ) && str_contains( $shipments_js, 'data-wdc-shipment-place-select' ) && str_contains( $shipments_js, 'data-wdc-add-manual-shipment-item' ) && str_contains( $shipments_js, 'shipment_items[' ) && ! str_contains( $shipments_js, 'cdek_items[' ), 'Shipment packages JS must use carrier-neutral data attributes and canonical shipment_items names.' );
cdek_order_assert( is_string( $shipments_js ) && str_contains( $shipments_js, "mode !== 'location' && !value" ) && str_contains( $shipments_js, "mode === 'location' ? 2000 : 100" ) && str_contains( $shipments_js, "context.city || context.postcode || context.address || context.locationId || context.fiasId || context.garId" ), 'Shipment pickup modal must load location points for Russian Post without requiring a typed query and without a 300-row cap.' );
$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$products_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentProductsAjaxController.php' );
$address_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentAddressAjaxController.php' );
$documents_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentDocumentsAjaxController.php' );
$russian_post_modal_extension_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/RussianPost/RussianPostShipmentModalExtension.php' );
cdek_order_assert( str_contains( $metabox_source, 'AJAX_SEARCH_PRODUCTS' ) && str_contains( $products_controller_source, 'wc_get_products' ) && str_contains( $products_controller_source, 'shipment_product_search_row' ), 'Shipment modal must expose secured WooCommerce product search for manual package items.' );
cdek_order_assert( str_contains( $metabox_source, 'inputmode="decimal"' ) && str_contains( $metabox_source, 'wdc-icon-action--split' ) && ! str_contains( $metabox_source, 'button wdc-icon-button' ), 'Shipment item decimal fields must allow typed separators and split icon must be borderless.' );
cdek_order_assert( str_contains( $products_controller_source, 'product_ids_by_partial_sku' ) && str_contains( $products_controller_source, "meta_key = '_sku'" ) && str_contains( $products_controller_source, 'LIKE %s' ) && str_contains( $products_controller_source, 'LIMIT %d' ), 'Shipment product search must support partial SKU matching for products and variations.' );
cdek_order_assert( str_contains( $metabox_source, 'render_shipment_item_rows' ) && str_contains( $metabox_source, 'data-wdc-shipment-item-row' ) && str_contains( $metabox_source, 'data-wdc-original-item' ) && ! str_contains( $metabox_source, 'Пока используется только для СДЭК' ), 'Shipment packages tab must be carrier-neutral and base rows must expose original item data for forced merge.' );
cdek_order_assert( str_contains( $russian_post_modal_extension_source, 'RussianPostDomesticSettings::CARRIER_KEY . \':pickup\'' ) && str_contains( $metabox_source, 'pickupPointTypes' ) && str_contains( $address_controller_source, "'location' === \$mode ? 2000 : 100" ), 'Shipment modal backend and Russian Post extension must keep pickup family/type config and location search limit for admin maps.' );
cdek_order_assert( str_contains( $russian_post_modal_extension_source, '$order_shipping_city' ) && str_contains( $russian_post_modal_extension_source, '$order_shipping_postcode' ) && str_contains( $russian_post_modal_extension_source, '$recipient_address_context' ) && str_contains( $russian_post_modal_extension_source, '$pickup_context[\'postal_code\'] ?? $pickup_context[\'postcode\'] ?? $context[\'pickup_location_postcode\']' ), 'Russian Post modal extension must fall back to WooCommerce shipping recipient context for map loading.' );
cdek_order_assert( str_contains( $address_controller_source, '$order_id = (int) ( $_POST[\'order_id\'] ?? 0 )' ) && str_contains( $address_controller_source, '$location_context[\'city_name\']' ) && str_contains( $address_controller_source, 'get_shipping_city' ) && str_contains( $address_controller_source, 'get_shipping_postcode' ), 'Shipment modal pickup search backend must use order_id to restore missing Russian Post location context.' );
cdek_order_assert( str_contains( $metabox_source, 'AJAX_CDEK_BARCODE_PREPARE' ) && str_contains( $documents_controller_source, 'handle_cdek_barcode_prepare' ) && str_contains( $documents_controller_source, 'document_download_url' ), 'CDEK shipment metabox must expose prepare AJAX and delegate final PDF download to the common document endpoint.' );
cdek_order_assert( str_contains( $documents_controller_source, 'CdekBarcodePrintService' ) && str_contains( $documents_controller_source, "\$result['download_url'] = \$this->payloads->document_download_url( \$order_id, CdekSettings::CARRIER_KEY, 'download_label' );" ) && ! str_contains( $documents_controller_source, 'ACTION_CDEK_BARCODE_PDF' ), 'CDEK barcode AJAX download_url must use the common shipment document endpoint.' );
$cdek_document_provider_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Cdek/CdekShipmentDocumentProvider.php' );
$document_download_service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Documents/ShipmentDocumentDownloadService.php' );
cdek_order_assert( str_contains( $cdek_document_provider_source, 'data-wdc-cdek-barcode-download' ) && str_contains( $cdek_document_provider_source, 'data-prepare-action' ) && str_contains( $metabox_source, 'data-download-url' ) && str_contains( $cdek_document_provider_source, 'Скачать этикетку' ) && ! str_contains( $metabox_source, 'Открыть этикетку' ) && ! str_contains( $metabox_source, 'data-wdc-cdek-barcode-inline' ), 'CDEK shipment document provider must keep only the managed label download action and common renderer must expose its download URL.' );
cdek_order_assert( str_contains( $cdek_document_provider_source, 'download_ready_pdf_for_order' ) && str_contains( $cdek_document_provider_source, 'data-wdc-cdek-barcode-download' ) && str_contains( $cdek_document_provider_source, 'data-prepare-action' ), 'CDEK document provider must keep barcode preparation policy and final PDF service call.' );
cdek_order_assert( str_contains( $metabox_source, 'data-download-url' ) && str_contains( $metabox_source, 'href' ) && str_contains( $metabox_source, 'esc_url( $value )' ), 'Common document HTML output must escape document download URLs.' );
cdek_order_assert( str_contains( $document_download_service_source, 'Content-Disposition: attachment' ) && ! str_contains( $document_download_service_source, "'inline' === \$mode ? 'inline' : 'attachment'" ), 'Common document endpoint must return attachment only and must not create print forms.' );
$draft_factory_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/OrderShipmentDraftFactory.php' );
cdek_order_assert( str_contains( $draft_factory_source, 'product_dimension_cm' ) && str_contains( $draft_factory_source, "\$length_cm" ) && str_contains( $draft_factory_source, "\$height_cm" ), 'Shipment draft factory must load item dimensions from WooCommerce product/variation data.' );
$modal_mapper_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/ShipmentModalRequestMapper.php' );
$cdek_builder_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Cdek/CdekCreateRequestBuilder.php' );
$yandex_registration_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/YandexDelivery/YandexShipmentRegistrationService.php' );
cdek_order_assert( str_contains( $draft_factory_source, 'ShipmentModalRequestMapper' ) && str_contains( $modal_mapper_source, 'decimal_string' ) && str_contains( $modal_mapper_source, "str_replace( ',', '.'" ) && str_contains( $modal_mapper_source, "'length_cm' => \$this->decimal_string" ), 'Shared shipment modal mapper must parse item cost/dimensions as decimals with comma and dot support.' );
cdek_order_assert( ! str_contains( $modal_mapper_source, '$data[\'cdek_items\']' ) && ! str_contains( $modal_html, 'data-wdc-cdek-item-row' ), 'Production modal/parser must not keep CDEK item allocation aliases.' );
cdek_order_assert( ! str_contains( $draft_factory_source, 'cdek_item_rows' ) && ! str_contains( $cdek_builder_source, 'cdek_item_rows' ) && ! str_contains( $yandex_registration_source, 'yandex_item_rows' ) && ! str_contains( $yandex_registration_source, 'cdek_item_rows' ), 'Production code must use only shipment_item_rows for internal shipment item meta.' );
cdek_order_assert( ! str_contains( $yandex_registration_source, 'CdekShipmentAllocationAdapter' ) && ! str_contains( $yandex_registration_source, 'from_cdek_rows' ) && str_contains( $yandex_registration_source, 'ShipmentAllocationBuilder' ), 'Yandex registration must use the neutral ShipmentAllocationBuilder.' );
$shipments_css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.css' );
cdek_order_assert( str_contains( $shipments_css, '.wdc-icon-action' ) && str_contains( $shipments_css, 'border: 0' ) && str_contains( $shipments_css, 'background: transparent' ) && str_contains( $shipments_css, 'min-width: min(520px, 70vw)' ) && str_contains( $shipments_css, 'overflow-x: hidden' ), 'Shipment package table CSS must use borderless icons and a wider product search dropdown without horizontal scrolling.' );
cdek_order_assert( str_contains( $shipments_css, 'th:nth-child(2)' ) && str_contains( $shipments_css, 'width: 92px' ) && str_contains( $shipments_css, '.wdc-cdek-input-weight' ) && str_contains( $shipments_css, 'width: 76px' ) && str_contains( $shipments_css, 'width: 54px' ), 'Shipment package table CSS must keep SKU compact, weight wider, and dimensions/place compact.' );
cdek_order_assert( str_contains( $shipments_css, '.wdc-cdek-barcode-download--busy' ) && str_contains( $shipments_css, 'animation: wdc-spin 0.8s linear infinite' ), 'CDEK label download busy state must render a spinner.' );

$render = new ReflectionMethod( OrderShipmentsMetabox::class, 'render_status_block' );
$render->setAccessible( true );
ob_start();
$render->invoke( $metabox, array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'carrier_status_title' => 'Регистрация', 'cdek_planned_delivery_date' => '2026-06-15' ) );
$status_html = ob_get_clean() ?: '';
cdek_order_assert( str_contains( $status_html, 'Статус СДЭК' ) && ! str_contains( $status_html, 'Статус Почты России' ), 'CDEK status block must use CDEK label.' );
cdek_order_assert( str_contains( $status_html, 'Плановая дата доставки' ) && str_contains( $status_html, '2026-06-15' ), 'CDEK status block must render planned_delivery_date when present.' );

$mapping_service = new CdekStatusMappingService( new SettingsRepository() );
$default_mapping = $mapping_service->mapping();
cdek_order_assert( DeliveryStatus::DELIVERED === $default_mapping['DELIVERED'] && DeliveryStatus::REJECTED === $default_mapping['INVALID'] && DeliveryStatus::CANCELLED === $default_mapping['REMOVED'], 'CDEK status mapping defaults must map delivered/invalid/removed to universal statuses.' );
$appendix_statuses = array( 'ACCEPTED', 'CREATED', 'REMOVED', 'RECEIVED_AT_SHIPMENT_WAREHOUSE', 'DELIVERED', 'NOT_DELIVERED', 'READY_FOR_SHIPMENT_IN_SENDER_CITY', 'TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY', 'SENT_TO_RECIPIENT_CITY', 'ACCEPTED_IN_RECIPIENT_CITY', 'ACCEPTED_AT_RECIPIENT_CITY_WAREHOUSE', 'TAKEN_BY_COURIER', 'ACCEPTED_AT_PICK_UP_POINT', 'ACCEPTED_AT_TRANSIT_WAREHOUSE', 'RETURNED_TO_SENDER_CITY_WAREHOUSE', 'RETURNED_TO_TRANSIT_WAREHOUSE', 'RETURNED_TO_RECIPIENT_CITY_WAREHOUSE', 'READY_FOR_SHIPMENT_IN_TRANSIT_CITY', 'TAKEN_BY_TRANSPORTER_FROM_TRANSIT_CITY', 'SENT_TO_TRANSIT_CITY', 'ACCEPTED_IN_TRANSIT_CITY', 'SENT_TO_SENDER_CITY', 'ACCEPTED_IN_SENDER_CITY', 'ENTERED_TO_TRANSIT_WAREHOUSE', 'ENTERED_TO_RECIPIENT_CITY_WAREHOUSE', 'ENTERED_TO_PICK_UP_POINT', 'IN_CUSTOMS_INTERNATIONAL', 'SHIPPED_TO_DESTINATION', 'PASSED_TO_TRANSIT_CARRIER', 'IN_CUSTOMS_LOCAL', 'CUSTOMS_COMPLETE', 'POSTOMAT_POSTED', 'POSTOMAT_SEIZED', 'POSTOMAT_RECEIVED', 'INVALID' );
$status_labels = CdekStatusMappingService::status_labels();
foreach ( $appendix_statuses as $status_code ) {
	cdek_order_assert( isset( $status_labels[ $status_code ], $default_mapping[ $status_code ] ), 'Every CDEK Appendix 1 status must have label and default mapping: ' . $status_code );
	cdek_order_assert( DeliveryStatus::is_valid( (string) $default_mapping[ $status_code ] ), 'CDEK default mapping must use valid DeliveryStatus for ' . $status_code );
}
cdek_order_assert( DeliveryStatus::READY_FOR_PICKUP === $default_mapping['ACCEPTED_AT_PICK_UP_POINT'] && DeliveryStatus::HANDED_TO_COURIER === $default_mapping['TAKEN_BY_COURIER'] && DeliveryStatus::DELIVERED === $default_mapping['POSTOMAT_RECEIVED'], 'CDEK pickup/courier/postamat defaults must map to specific universal statuses.' );
$GLOBALS['wdc_cdek_order_options']['wdc_core_settings'][ CdekStatusMappingService::MAPPING_KEY ] = array( 'DELIVERED' => DeliveryStatus::READY_FOR_PICKUP );
cdek_order_assert( DeliveryStatus::READY_FOR_PICKUP === $mapping_service->universal_status_for( 'DELIVERED' ), 'CDEK status mapping must read saved admin overrides.' );
$autosync_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/ShipmentStatusAutoSyncService.php' );
$cdek_adapter_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Cdek/CdekShipmentAdapter.php' );
cdek_order_assert( str_contains( $autosync_source, '$adapter->update_status' ) && str_contains( $cdek_adapter_source, '$this->status_updates->update( $order )' ), 'Shipment autosync must dispatch CDEK shipments through the CDEK shipment adapter and CdekOrderStatusService.' );
cdek_order_assert( str_contains( $autosync_source, 'find_by_carrier( $order, $carrier_key )' ) && str_contains( $autosync_source, '$this->order_status_mapping->apply' ), 'CDEK autosync must apply universal status to WooCommerce order status mapping after status update.' );
cdek_order_assert( str_contains( $autosync_source, '$adapter->tracking_identifier' ) && str_contains( $cdek_adapter_source, "'cdek_number'" ) && str_contains( $cdek_adapter_source, "'external_id'" ) && str_contains( $cdek_adapter_source, "'uuid'" ), 'CDEK autosync must treat CDEK number/uuid as valid tracking identifiers through the adapter.' );

$mapped_status_http = new CdekOrderFakeHttp();
$mapped_status_http->order_responses[] = array(
	'entity' => array(
		'uuid' => 'mapped-order-uuid',
		'cdek_number' => '100501',
		'statuses' => array(
			array( 'code' => 'DELIVERED', 'name' => 'Доставлен', 'date_time' => '2026-06-13T10:04:41+0000' ),
			array( 'code' => 'CREATED', 'name' => 'Создан', 'date_time' => '2026-06-13T05:04:41+0000' ),
		),
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$mapped_status_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $mapped_status_http ), $settings, $mapped_status_http );
$mapped_status_repository = new OrderShipmentRepository();
$mapped_status_order = new CdekOrderFakeOrder( 151 );
$mapped_status_repository->save_for_carrier(
	$mapped_status_order,
	CdekSettings::CARRIER_KEY,
	array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'status' => 'registered', 'external_id' => 'mapped-order-uuid', 'cdek_number' => '100501', 'cdek_order_status_code' => 'CREATED' )
);
$mapped_status_service = cdek_order_status_service( $mapped_status_repository, $mapped_status_client, $mapping_service );
$mapped_update = $mapped_status_service->update( $mapped_status_order );
cdek_order_assert( $mapped_update['success'] && 'DELIVERED' === (string) ( $mapped_update['status']['order_status_code'] ?? '' ) && DeliveryStatus::READY_FOR_PICKUP === (string) ( $mapped_update['status']['universal_status_code'] ?? '' ), 'CDEK update status must select max date_time raw status and apply saved universal mapping.' );

$barcode_http = new CdekOrderFakeHttp();
$barcode_http->barcode_create_responses[] = array( 'entity' => array( 'uuid' => 'print-ready-uuid' ) );
$barcode_http->barcode_status_responses[] = array( 'entity' => array( 'uuid' => 'print-ready-uuid', 'statuses' => array( array( 'code' => 'READY', 'date_time' => '2026-06-13T10:00:00+0000' ) ) ) );
$barcode_http->barcode_pdf_responses[] = '%PDF-1.4 barcode';
$barcode_client = new CdekApiClient( new CdekOAuthTokenService( $settings, $barcode_http ), $settings, $barcode_http );
$barcode_repository = new OrderShipmentRepository();
$barcode_order = new CdekOrderFakeOrder( 152 );
$barcode_repository->save_for_carrier(
	$barcode_order,
	CdekSettings::CARRIER_KEY,
	array( 'carrier_key' => CdekSettings::CARRIER_KEY, 'status' => 'registered', 'external_id' => 'order-uuid-barcode', 'cdek_number' => '10280157676', 'cdek_order_status_code' => 'CREATED' )
);
$barcode_service = new CdekBarcodePrintService( $barcode_repository, $barcode_client, static function (): void {}, 1, 0 );
$barcode_prepared = $barcode_service->prepare_for_order( $barcode_order );
cdek_order_assert( ! empty( $barcode_prepared['success'] ) && 'READY' === (string) ( $barcode_prepared['status'] ?? '' ), 'CDEK BARCODE prepare must return READY when CDEK print form is ready.' );
$barcode_pdf = $barcode_service->download_ready_pdf_for_order( $barcode_order );
cdek_order_assert( ! empty( $barcode_pdf['success'] ) && '%PDF-1.4 barcode' === (string) ( $barcode_pdf['body'] ?? '' ), 'CDEK BARCODE ready cache must allow final PDF download.' );
$barcode_post_request = array_values( array_filter( $barcode_http->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && str_contains( $request['url'], '/v2/print/barcodes' ) ) )[0] ?? array();
$barcode_payload = json_decode( (string) ( $barcode_post_request['args']['body'] ?? '{}' ), true );
cdek_order_assert( 'A6' === (string) ( $barcode_payload['format'] ?? '' ) && 'RUS' === (string) ( $barcode_payload['lang'] ?? '' ) && 1 === (int) ( $barcode_payload['copy_count'] ?? 0 ) && '10280157676' === (string) ( $barcode_payload['orders'][0]['cdek_number'] ?? '' ), 'CDEK BARCODE payload must use A6/RUS/one copy and cdek_number.' );
cdek_order_assert( 1 === count( array_filter( $barcode_http->requests, static fn ( array $request ): bool => 'GET' === $request['method'] && str_contains( $request['url'], '/v2/print/barcodes/print-ready-uuid.pdf' ) ) ), 'CDEK BARCODE service must download ready PDF by print uuid.' );
$barcode_post_count = count( array_filter( $barcode_http->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && str_contains( $request['url'], '/v2/print/barcodes' ) ) );
$barcode_prepared_again = $barcode_service->prepare_for_order( $barcode_order );
$barcode_post_count_again = count( array_filter( $barcode_http->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && str_contains( $request['url'], '/v2/print/barcodes' ) ) );
cdek_order_assert( 'READY' === (string) ( $barcode_prepared_again['status'] ?? '' ) && $barcode_post_count === $barcode_post_count_again, 'CDEK BARCODE READY cache must avoid repeated print-form POST requests.' );

$GLOBALS['wdc_cdek_order_transients'] = array();
$barcode_same_time_http = new CdekOrderFakeHttp();
$barcode_same_time_http->barcode_create_responses[] = array( 'entity' => array( 'uuid' => 'print-same-time-uuid' ) );
$barcode_same_time_http->barcode_status_responses[] = array(
	'entity' => array(
		'uuid' => 'print-same-time-uuid',
		'statuses' => array(
			array( 'code' => 'ACCEPTED', 'date_time' => '2026-06-15T19:16:12+0000' ),
			array( 'code' => 'PROCESSING', 'date_time' => '2026-06-15T19:16:12+0000' ),
			array( 'code' => 'READY', 'date_time' => '2026-06-15T19:16:12+0000' ),
		),
		'url' => '',
	),
	'requests' => array( array( 'state' => 'SUCCESSFUL' ) ),
);
$same_time_prepare = ( new CdekBarcodePrintService( $barcode_repository, new CdekApiClient( new CdekOAuthTokenService( $settings, $barcode_same_time_http ), $settings, $barcode_same_time_http ), static function (): void {}, 1, 0 ) )->prepare_for_order( $barcode_order );
cdek_order_assert( ! empty( $same_time_prepare['success'] ) && 'READY' === (string) ( $same_time_prepare['status'] ?? '' ), 'CDEK BARCODE print status must prefer READY when statuses share the same date_time.' );

$GLOBALS['wdc_cdek_order_transients'] = array();
$barcode_url_http = new CdekOrderFakeHttp();
$barcode_url_http->barcode_create_responses[] = array( 'entity' => array( 'uuid' => 'print-url-uuid' ) );
$barcode_url_http->barcode_status_responses[] = array( 'entity' => array( 'uuid' => 'print-url-uuid', 'url' => 'https://api.cdek.ru/v2/print/barcodes/print-url-uuid.pdf', 'statuses' => array( array( 'code' => 'ACCEPTED', 'date_time' => '2026-06-15T19:16:12+0000' ) ) ) );
$url_prepare = ( new CdekBarcodePrintService( $barcode_repository, new CdekApiClient( new CdekOAuthTokenService( $settings, $barcode_url_http ), $settings, $barcode_url_http ), static function (): void {}, 1, 0 ) )->prepare_for_order( $barcode_order );
cdek_order_assert( ! empty( $url_prepare['success'] ) && 'READY' === (string) ( $url_prepare['status'] ?? '' ), 'CDEK BARCODE print status must treat a PDF entity.url as READY even when the first status is ACCEPTED.' );

$GLOBALS['wdc_cdek_order_transients'] = array();
$barcode_processing_http = new CdekOrderFakeHttp();
$barcode_processing_http->barcode_create_responses[] = array( 'entity' => array( 'uuid' => 'print-processing-uuid' ) );
$barcode_processing_http->barcode_status_responses[] = array( 'entity' => array( 'uuid' => 'print-processing-uuid', 'statuses' => array( array( 'code' => 'ACCEPTED', 'date_time' => '2026-06-15T19:16:12+0000' ), array( 'code' => 'PROCESSING', 'date_time' => '2026-06-15T19:16:12+0000' ) ) ) );
$processing_prepare = ( new CdekBarcodePrintService( $barcode_repository, new CdekApiClient( new CdekOAuthTokenService( $settings, $barcode_processing_http ), $settings, $barcode_processing_http ), static function (): void {}, 1, 0 ) )->prepare_for_order( $barcode_order );
cdek_order_assert( ! empty( $processing_prepare['success'] ) && 'PROCESSING' === (string) ( $processing_prepare['status'] ?? '' ), 'CDEK BARCODE print status must prefer PROCESSING over ACCEPTED when READY is absent.' );

$GLOBALS['wdc_cdek_order_transients'] = array();
$barcode_invalid_http = new CdekOrderFakeHttp();
$barcode_invalid_http->barcode_status_responses[] = array( 'entity' => array( 'uuid' => 'print-invalid-uuid', 'statuses' => array( array( 'code' => 'ACCEPTED', 'date_time' => '2026-06-13T10:00:00+0000' ), array( 'code' => 'INVALID', 'date_time' => '2026-06-13T10:00:00+0000' ), array( 'code' => 'PROCESSING', 'date_time' => '2026-06-13T10:00:00+0000' ) ) ) );
$barcode_invalid_service = new CdekBarcodePrintService( $barcode_repository, new CdekApiClient( new CdekOAuthTokenService( $settings, $barcode_invalid_http ), $settings, $barcode_invalid_http ), static function (): void {}, 1, 0 );
$invalid_prepare = $barcode_invalid_service->prepare_for_order( $barcode_order );
cdek_order_assert( empty( $invalid_prepare['success'] ) && str_contains( (string) ( $invalid_prepare['message'] ?? '' ), 'СДЭК не смог сформировать этикетку' ), 'CDEK BARCODE INVALID status must return a readable error and clear cache.' );

$GLOBALS['wdc_cdek_order_transients'] = array();
$barcode_removed_http = new CdekOrderFakeHttp();
$barcode_removed_http->barcode_create_responses[] = array( 'entity' => array( 'uuid' => 'print-removed-uuid' ) );
$barcode_removed_http->barcode_create_responses[] = array( 'entity' => array( 'uuid' => 'print-recreated-uuid' ) );
$barcode_removed_http->barcode_status_responses[] = array( 'entity' => array( 'uuid' => 'print-removed-uuid', 'statuses' => array( array( 'code' => 'ACCEPTED', 'date_time' => '2026-06-13T10:00:00+0000' ), array( 'code' => 'REMOVED', 'date_time' => '2026-06-13T10:00:00+0000' ) ) ) );
$barcode_removed_http->barcode_status_responses[] = array( 'entity' => array( 'uuid' => 'print-recreated-uuid', 'statuses' => array( array( 'code' => 'READY', 'date_time' => '2026-06-13T10:00:02+0000' ) ) ) );
$barcode_removed_http->barcode_pdf_responses[] = '%PDF-1.4 recreated';
$removed_prepare = ( new CdekBarcodePrintService( $barcode_repository, new CdekApiClient( new CdekOAuthTokenService( $settings, $barcode_removed_http ), $settings, $barcode_removed_http ), static function (): void {}, 2, 0 ) )->prepare_for_order( $barcode_order );
cdek_order_assert( ! empty( $removed_prepare['success'] ) && 'ACCEPTED' === (string) ( $removed_prepare['status'] ?? '' ) && 'print-recreated-uuid' === (string) ( $removed_prepare['print_uuid'] ?? '' ) && 2 === count( array_filter( $barcode_removed_http->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && str_contains( $request['url'], '/v2/print/barcodes' ) ) ), 'CDEK BARCODE REMOVED status must clear cache and create a fresh print form.' );

$GLOBALS['wdc_cdek_order_transients'] = array();
$barcode_timeout_http = new CdekOrderFakeHttp();
$barcode_timeout_http->barcode_status_responses[] = array( 'entity' => array( 'uuid' => 'print-timeout-uuid', 'statuses' => array( array( 'code' => 'PROCESSING', 'date_time' => '2026-06-13T10:00:00+0000' ) ) ) );
$timeout_service = new CdekBarcodePrintService( $barcode_repository, new CdekApiClient( new CdekOAuthTokenService( $settings, $barcode_timeout_http ), $settings, $barcode_timeout_http ), static function (): void {}, 1, 0 );
$timeout_prepare = $timeout_service->prepare_for_order( $barcode_order );
$timeout_pdf = $timeout_service->download_ready_pdf_for_order( $barcode_order );
cdek_order_assert( ! empty( $timeout_prepare['success'] ) && 'PROCESSING' === (string) ( $timeout_prepare['status'] ?? '' ), 'CDEK BARCODE prepare must return PROCESSING without downloading while the print form is still being created.' );
cdek_order_assert( empty( $timeout_pdf['success'] ) && 'Этикетка СДЭК еще не готова. Нажмите "Скачать этикетку" еще раз.' === (string) ( $timeout_pdf['message'] ?? '' ), 'CDEK BARCODE final download must fail while READY cache is absent.' );

$GLOBALS['wdc_cdek_order_transients'] = array(
	'wdc_cdek_barcode_152_10280157676' => array(
		'print_uuid' => 'print-stuck-uuid',
		'status' => 'ACCEPTED',
		'cdek_number' => '10280157676',
		'created_at' => time() - 61,
		'last_checked_at' => time() - 2,
		'checked_count' => 30,
		'ready_at' => null,
	),
);
$barcode_stuck_http = new CdekOrderFakeHttp();
$barcode_stuck_http->barcode_create_responses[] = array( 'entity' => array( 'uuid' => 'print-recovered-uuid' ) );
$barcode_stuck_http->barcode_status_responses[] = array( 'entity' => array( 'uuid' => 'print-stuck-uuid', 'statuses' => array( array( 'code' => 'ACCEPTED', 'date_time' => '2026-06-13T10:00:00+0000' ) ) ) );
$stuck_prepare = ( new CdekBarcodePrintService( $barcode_repository, new CdekApiClient( new CdekOAuthTokenService( $settings, $barcode_stuck_http ), $settings, $barcode_stuck_http ), static function (): void {}, 1, 0 ) )->prepare_for_order( $barcode_order );
$stuck_post_count = count( array_filter( $barcode_stuck_http->requests, static fn ( array $request ): bool => 'POST' === $request['method'] && str_contains( $request['url'], '/v2/print/barcodes' ) ) );
cdek_order_assert( ! empty( $stuck_prepare['success'] ) && ! empty( $stuck_prepare['recreated'] ) && 'print-recovered-uuid' === (string) ( $stuck_prepare['print_uuid'] ?? '' ) && 1 === $stuck_post_count, 'CDEK BARCODE stuck ACCEPTED cache must be recreated after the recovery threshold.' );

$GLOBALS['wdc_cdek_order_transients'] = array();
$barcode_empty_pdf_http = new CdekOrderFakeHttp();
$barcode_empty_pdf_http->barcode_create_responses[] = array( 'entity' => array( 'uuid' => 'print-empty-pdf-uuid' ) );
$barcode_empty_pdf_http->barcode_status_responses[] = array( 'entity' => array( 'uuid' => 'print-empty-pdf-uuid', 'statuses' => array( array( 'code' => 'READY', 'date_time' => '2026-06-13T10:00:00+0000' ) ) ) );
$barcode_empty_pdf_http->barcode_pdf_responses[] = array( 'body' => '', 'content_type' => 'application/pdf' );
$empty_pdf_service = new CdekBarcodePrintService( $barcode_repository, new CdekApiClient( new CdekOAuthTokenService( $settings, $barcode_empty_pdf_http ), $settings, $barcode_empty_pdf_http ), static function (): void {}, 1, 0 );
$empty_pdf_service->prepare_for_order( $barcode_order );
$empty_pdf_result = $empty_pdf_service->download_ready_pdf_for_order( $barcode_order );
cdek_order_assert( empty( $empty_pdf_result['success'] ) && str_contains( (string) ( $empty_pdf_result['message'] ?? '' ), 'пустой PDF' ), 'CDEK BARCODE final download must reject an empty PDF body.' );

$GLOBALS['wdc_cdek_order_transients'] = array();
$barcode_non_pdf_http = new CdekOrderFakeHttp();
$barcode_non_pdf_http->barcode_create_responses[] = array( 'entity' => array( 'uuid' => 'print-html-uuid' ) );
$barcode_non_pdf_http->barcode_status_responses[] = array( 'entity' => array( 'uuid' => 'print-html-uuid', 'statuses' => array( array( 'code' => 'READY', 'date_time' => '2026-06-13T10:00:00+0000' ) ) ) );
$barcode_non_pdf_http->barcode_pdf_responses[] = array( 'body' => '<html>not pdf</html>', 'content_type' => 'text/html' );
$non_pdf_service = new CdekBarcodePrintService( $barcode_repository, new CdekApiClient( new CdekOAuthTokenService( $settings, $barcode_non_pdf_http ), $settings, $barcode_non_pdf_http ), static function (): void {}, 1, 0 );
$non_pdf_service->prepare_for_order( $barcode_order );
$non_pdf_result = $non_pdf_service->download_ready_pdf_for_order( $barcode_order );
cdek_order_assert( empty( $non_pdf_result['success'] ) && 'Сервер вернул не PDF-файл этикетки СДЭК.' === (string) ( $non_pdf_result['message'] ?? '' ), 'CDEK BARCODE final download must reject explicit non-PDF content type.' );

$barcode_service_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Cdek/CdekBarcodePrintService.php' );
cdek_order_assert( str_contains( $barcode_service_source, 'CACHE_TTL_SECONDS = 50 * 60' ) && str_contains( $barcode_service_source, 'STUCK_STATUS_SECONDS = 60' ) && str_contains( $barcode_service_source, 'STUCK_STATUS_CHECKS = 30' ) && str_contains( $barcode_service_source, 'prepare_for_order' ) && str_contains( $barcode_service_source, 'download_ready_pdf_for_order' ) && str_contains( $barcode_service_source, '$http_code < 200 || $http_code >= 300' ) && str_contains( $barcode_service_source, "str_contains( \$content_type, 'application/pdf' )" ), 'CDEK BARCODE service must cache prepared labels, recover stuck ACCEPTED/PROCESSING forms, split prepare/download responsibilities, and reject failed/non-PDF downloads.' );

echo "CDEK order creation smoke test passed.\n";
