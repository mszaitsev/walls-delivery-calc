<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointRepository;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentDateResolver;
use WallsShop\WDC\Carriers\Dpd\Shipments\DpdShipmentPayloadBuilder;
use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Calendar\Services\YearGenerator;
use WallsShop\WDC\Calendar\Storage\CalendarRepository;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Dpd\DpdShipmentAdapter;

function dpd_shipment_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-18 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'dpd-shipment-smoke-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_shipment_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_shipment_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_dpd_shipment_options'][ $key ] ); return true; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( mixed $value ): string { return filter_var( trim( (string) $value ), FILTER_VALIDATE_EMAIL ) ? trim( (string) $value ) : ''; }
function wp_unslash( mixed $value ): mixed { return $value; }
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $dpd_pickup_points = array();
		/** @var array<string,array<string,mixed>> */
		public array $calendar_days = array();
		public function prepare( string $query, mixed ...$args ): string { return vsprintf( str_replace( array( '%d', '%s' ), array( '%d', "'%s'" ), $query ), $args ); }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
		public function get_row( string $query, string $output = '' ): ?array {
			if ( 1 === preg_match( "/calendar_type = '([^']+)'.*calendar_date = '([^']+)'/s", $query, $matches ) ) {
				return $this->calendar_days[ $matches[1] . '|' . $matches[2] ] ?? null;
			}
			return null;
		}
		public function get_results( string $query, string $output = '' ): array {
			if ( 1 === preg_match( "/calendar_type = '([^']+)'.*YEAR\\(calendar_date\\) = (\\d+)/s", $query, $matches ) ) {
				$prefix = $matches[1] . '|' . $matches[2] . '-';
				return array_values( array_filter( $this->calendar_days, static fn( array $row, string $key ): bool => str_starts_with( $key, $prefix ), ARRAY_FILTER_USE_BOTH ) );
			}
			return array();
		}
		public function get_var( string $query ): mixed {
			if ( 1 === preg_match( "/calendar_type = '([^']+)'.*YEAR\\(calendar_date\\) = (\\d+)/s", $query, $matches ) ) {
				$prefix = $matches[1] . '|' . $matches[2] . '-';
				return count( array_filter( array_keys( $this->calendar_days ), static fn( string $key ): bool => str_starts_with( $key, $prefix ) ) );
			}
			return 0;
		}
		public function replace( string $table, array $data, array $format = array() ): bool {
			if ( str_ends_with( $table, 'wdc_calendar_days' ) ) {
				$this->calendar_days[ (string) $data['calendar_type'] . '|' . (string) $data['calendar_date'] ] = array(
					'calendar_type' => (string) $data['calendar_type'],
					'calendar_date' => (string) $data['calendar_date'],
					'is_working' => (int) $data['is_working'],
					'reason' => (string) ( $data['reason'] ?? '' ),
				);
			}
			return true;
		}
		public function query( string $query ): int { return 0; }
	}
}

final class DpdShipmentFakeOrderItem {
	public function __construct( private string $name, private int $qty, private float $total, private DpdShipmentFakeProduct $product ) {}
	public function get_product(): DpdShipmentFakeProduct { return $this->product; }
	public function get_quantity(): int { return $this->qty; }
	public function get_total(): float { return $this->total; }
	public function get_name(): string { return $this->name; }
}

final class DpdShipmentFakeProduct {
	public function __construct( private string $sku, private float $weight, private int $length, private int $width, private int $height ) {}
	public function get_sku(): string { return $this->sku; }
	public function get_weight(): float { return $this->weight; }
	public function get_length(): int { return $this->length; }
	public function get_width(): int { return $this->width; }
	public function get_height(): int { return $this->height; }
}

final class DpdShipmentFakeOrder {
	/** @var array<string,mixed> */
	public array $meta = array();
	/** @var array<int,DpdShipmentFakeOrderItem> */
	private array $items = array();

	public function __construct( private int $id, private string $delivery_type = DeliveryType::PICKUP ) {
		$this->items[] = new DpdShipmentFakeOrderItem( 'Панель', 2, 3000.0, new DpdShipmentFakeProduct( 'PANEL', 1.2, 40, 30, 10 ) );
		$this->meta = array(
			'_wdc_platform_carrier_key' => DpdSettings::CARRIER_KEY,
			'_wdc_platform_delivery_type' => $delivery_type,
			'_wdc_platform_tariff_object' => 'ECN',
			'_wdc_platform_tariff_title' => 'DPD Эконом',
			'_wdc_platform_rate_meta' => array(
				'dpd_service_code' => 'ECN',
				'dpd_sender_city_id' => '49455627',
				'dpd_receiver_city_id' => '195300000',
				'dpd_pickup_terminal_code' => 'NSK-SENDER',
				'dpd_delivery_terminal_code' => 'MSK-RECEIVER',
				'dpd_delivery_terminal_source' => 'selected',
				'package' => array( 'parcels' => array( array( 'weight' => 99, 'width' => 99 ) ) ),
			),
			OrderShippingMetaPersister::CALCULATION_META_KEY => array(
				'carrier_key' => DpdSettings::CARRIER_KEY,
				'selected_tariff_object' => 'ECN',
				'selected_tariff_title' => 'DPD Эконом',
				'delivery_type' => $delivery_type,
			),
			'_wdc_dpd_pickup_terminal_code' => 'MSK-RECEIVER',
			'_wdc_dpd_pickup_type' => 'parcel_shop',
			'_wdc_dpd_pickup_name' => 'DPD Москва',
			'_wdc_dpd_pickup_address' => 'Москва, Тестовая, 1',
			'_wdc_dpd_pickup_city_name' => 'Москва',
			'_wdc_dpd_pickup_source' => 'test',
			'_wdc_pickup_point_snapshot' => array( 'terminal_code' => 'MSK-RECEIVER', 'address' => 'Москва, Тестовая, 1' ),
		);
	}

	public function get_id(): int { return $this->id; }
	public function get_order_number(): string { return 'WC-' . $this->id; }
	public function get_items(): array { return $this->items; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function get_shipping_first_name(): string { return 'Иван'; }
	public function get_shipping_last_name(): string { return 'Петров'; }
	public function get_billing_phone(): string { return '+79990000000'; }
	public function get_billing_email(): string { return 'buyer@example.test'; }
	public function get_shipping_country(): string { return 'RU'; }
	public function get_shipping_state(): string { return 'Москва'; }
	public function get_shipping_city(): string { return 'Москва'; }
	public function get_shipping_postcode(): string { return '101000'; }
	public function get_shipping_address_1(): string { return 'Тестовая, 1'; }
	public function get_shipping_address_2(): string { return ''; }
}

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wpdb']->dpd_pickup_points = array(
	array( 'terminal_code' => 'NSK-SENDER', 'type' => 'parcel_shop', 'city_id' => '49455627', 'city_name' => 'Новосибирск', 'name' => 'DPD НСК', 'address' => 'Новосибирск, Складская, 1', 'source' => 'test', 'is_active' => 1 ),
	array( 'terminal_code' => 'NSK-SENDER-2', 'type' => 'parcel_shop', 'city_id' => '49455627', 'city_name' => 'Новосибирск', 'name' => 'DPD НСК 2', 'address' => 'Новосибирск, Складская, 2', 'source' => 'test', 'is_active' => 1 ),
	array( 'terminal_code' => 'MSK-RECEIVER', 'type' => 'parcel_shop', 'city_id' => '195300000', 'city_name' => 'Москва', 'name' => 'DPD Москва', 'address' => 'Москва, Тестовая, 1', 'source' => 'test', 'is_active' => 1 ),
	array( 'terminal_code' => 'MSK-RECEIVER-2', 'type' => 'parcel_shop', 'city_id' => '195300000', 'city_name' => 'Москва', 'name' => 'DPD Москва 2', 'address' => 'Москва, Тестовая, 2', 'source' => 'test', 'is_active' => 1 ),
	array( 'terminal_code' => 'NSK-SELF', 'type' => 'terminal_self_delivery', 'city_id' => '49455627', 'city_name' => 'Новосибирск', 'name' => 'DPD терминал', 'address' => 'Новосибирск', 'source' => 'test', 'is_active' => 1 ),
);

$settings_repo = new SettingsRepository();
$settings = new DpdSettings( $settings_repo, new EncryptionService() );
$settings->save_tariff_settings_from_admin(
	array(
		DpdSettings::TARIFF_SENDER_DPD_CITY_ID_KEY => '49455627',
		DpdSettings::TARIFF_DEFAULT_SENDER_TERMINAL_CODE_KEY => 'NSK-SENDER',
	)
);
$settings->save_runtime_tariffs_from_admin(
	array(
		'dpd_runtime_service_enabled' => array( 'ECN' => '1', 'CSM' => '1' ),
		'dpd_runtime_tariff_title' => array( 'ECN' => 'DPD Эконом', 'CSM' => 'DPD Оптимум' ),
	)
);
$pickup_service = new DpdPickupPointService( new DpdPickupPointRepository(), new LocationDeliveryCodeRepository() );
$calendar = new CalendarService( new CalendarRepository(), new YearGenerator(), $settings_repo, new TimezoneService() );
$date_resolver = new DpdShipmentDateResolver( $calendar, new TimezoneService() );
$factory = new OrderShipmentDraftFactory( new DeliveryServiceRepository(), new ShipmentServiceSettings(), null, null, null, null, null, $settings, $pickup_service, $date_resolver );
$builder = new DpdShipmentPayloadBuilder();
$adapter = new DpdShipmentAdapter( $builder );

$before_cutoff = $date_resolver->default_date( new DateTimeImmutable( '2026-06-18 16:30:00', new DateTimeZone( TimezoneService::TIMEZONE ) ) );
$after_cutoff = $date_resolver->default_date( new DateTimeImmutable( '2026-06-20 17:00:00', new DateTimeZone( TimezoneService::TIMEZONE ) ) );
dpd_shipment_assert( '2026-06-18' === $before_cutoff['date'], 'Default DPD datePickup before 17:00 must be today when today is a store working day.' );
dpd_shipment_assert( '2026-06-22' === $after_cutoff['date'], 'Default DPD datePickup at/after 17:00 must use the next store working day.' );

$pickup_order = new DpdShipmentFakeOrder( 630, DeliveryType::PICKUP );
$base_request = $factory->create_request_from_order( $pickup_order );
dpd_shipment_assert( 'ECN' === (string) ( $base_request->meta['service_code'] ?? '' ), 'Existing DPD serviceCode must be read from order/rate meta.' );
dpd_shipment_assert( '49455627' === (string) ( $base_request->meta['pickup_city_id'] ?? '' ), 'Existing sender pickup cityId must be read.' );
dpd_shipment_assert( '195300000' === (string) ( $base_request->meta['delivery_city_id'] ?? '' ), 'Existing delivery cityId must be read.' );
$draft = $factory->draft_array( $pickup_order );
$draft_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$draft_css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.css' );
dpd_shipment_assert( 'MSK-RECEIVER' === (string) ( $draft['request']['meta']['pickup_point_row']['point_code'] ?? '' ) && 'parcel_shop' === (string) ( $draft['request']['meta']['pickup_point_row']['point_type'] ?? '' ), 'DPD modal draft must expose recipient pickup point code/type.' );
dpd_shipment_assert( str_contains( $draft_source, 'data-wdc-open-pickup-picker' ) && str_contains( $draft_source, 'data-wdc-open-sender-pickup-picker' ), 'DPD modal must expose choose receiver/sender pickup buttons.' );
dpd_shipment_assert( ! str_contains( $draft_source, "|| \$is_dpd ) : ?>\n\t\t\t\t\t\t\t\t\t\t<p><strong><?php echo esc_html__( 'Тип точки'" ), 'DPD recipient pickup point visible block must not render point type.' );
dpd_shipment_assert( str_contains( $draft_source, 'data-wdc-cdek-pickup-type-label' ), 'CDEK pickup point type display must remain available.' );
dpd_shipment_assert( str_contains( $draft_source, 'В заказе тариф' ) && ! str_contains( $draft_source, 'serviceCode</strong>' ) && ! str_contains( $draft_source, 'pickup cityId</strong>' ), 'DPD modal must show order tariff and remove visible technical service block.' );
dpd_shipment_assert( ! str_contains( $draft_source, 'name="dpd_comment"' ), 'DPD modal must not render DPD comment field.' );
dpd_shipment_assert( str_contains( $draft_source, 'data-wdc-dpd-date-pickup' ) && str_contains( $draft_source, 'Дата отправки' ), 'DPD modal must render datePickup date input after sender pickup point block.' );
dpd_shipment_assert( str_contains( $draft_source, 'name="date_pickup"' ) && str_contains( $draft_source, 'type="date"' ), 'DPD modal must render date_pickup date input.' );
dpd_shipment_assert( str_contains( $draft_source, 'data-wdc-date-step="-1"' ) && str_contains( $draft_source, 'data-wdc-date-step="1"' ), 'DPD modal must render date step buttons.' );
dpd_shipment_assert( str_contains( $draft_source, 'wdc-dpd-date-row' ) && str_contains( $draft_css, '.wdc-shipment-modal .wdc-dpd-date-row input[type="date"]' ) && str_contains( $draft_css, 'width: auto;' ), 'DPD date input must use compact row styles instead of the full-width modal input pattern.' );
dpd_shipment_assert( array( DeliveryType::PICKUP, DeliveryType::COURIER ) === array_column( $draft['services'], 'delivery_type' ), 'DPD modal must allow pickup/courier delivery type switch.' );
dpd_shipment_assert( array( 'ECN', 'CSM' ) === array_column( $draft['services'][0]['tariffs'], 'object_code' ), 'DPD modal must allow active tariff switch.' );

$request = $factory->create_request_from_admin_data(
	$pickup_order,
	array(
		'places' => array( array( 'weight_g' => '2500', 'length_cm' => '40', 'width_cm' => '30', 'height_cm' => '20' ) ),
		'recipient_name' => 'Иван Петров',
		'recipient_phone' => '+79990000000',
		'recipient_email' => 'buyer@example.test',
		'pickup_point_code' => 'MSK-RECEIVER-2',
		'pickup_terminal_code' => 'NSK-SENDER-2',
		'tariff_object' => 'CSM',
		'date_pickup' => '2026-06-18',
	)
);
$preview = $adapter->build_safe_payload_preview( $request );
$body = $preview['body']['request']['order'] ?? array();
$header = $preview['body']['request']['header'] ?? array();
dpd_shipment_assert( '2026-06-18' === (string) ( $header['datePickup'] ?? '' ), 'DPD dry-run payload must include request.header.datePickup.' );
dpd_shipment_assert( ! isset( $body['comment'] ), 'DPD dry-run payload must not contain comment.' );
dpd_shipment_assert( 'CSM' === (string) ( $body['serviceCode'] ?? '' ), 'DPD pickup preview must use modal-selected serviceCode.' );
dpd_shipment_assert( '49455627' === (string) ( $body['pickup']['cityId'] ?? '' ), 'DPD pickup preview must contain pickup cityId.' );
dpd_shipment_assert( '195300000' === (string) ( $body['delivery']['cityId'] ?? '' ), 'DPD pickup preview must contain delivery cityId.' );
dpd_shipment_assert( 'NSK-SENDER-2' === (string) ( $body['pickup']['terminalCode'] ?? '' ), 'DPD pickup preview must use modal-selected sender terminalCode.' );
dpd_shipment_assert( 'MSK-RECEIVER-2' === (string) ( $body['delivery']['terminalCode'] ?? '' ), 'DPD pickup preview must use modal-selected receiver terminalCode.' );
dpd_shipment_assert( '+79990000000' === (string) ( $body['receiver']['phone'] ?? '' ), 'DPD pickup preview must contain recipient.' );
dpd_shipment_assert( 2.5 === (float) ( $body['parcel'][0]['weight'] ?? 0 ), 'DPD pickup preview must use parcels from modal input.' );
dpd_shipment_assert( 40 === (int) ( $body['parcel'][0]['length'] ?? 0 ), 'DPD pickup preview must not reuse checkout parcel[] dimensions.' );
dpd_shipment_assert( 3000.0 === (float) ( $body['cargoValue'] ?? 0 ), 'DPD declaredValue must be derived from order items total.' );
dpd_shipment_assert( false === (bool) ( $preview['live_api_call'] ?? true ), 'DPD preview must not make a live API call.' );
dpd_shipment_assert( 'MSK-RECEIVER' === (string) $pickup_order->meta['_wdc_dpd_pickup_terminal_code'] && 'NSK-SENDER' === $settings->tariff_default_sender_terminal_code(), 'Modal pickup changes must not be saved to order meta/settings.' );

$courier_order = new DpdShipmentFakeOrder( 631, DeliveryType::COURIER );
$normalized = array(
	'success' => true,
	'service_key' => DpdSettings::SERVICE_KEY,
	'source' => 'dadata+dpd',
	'original_hash' => hash( 'sha256', '101000, Москва, Тестовая, 1' ),
	'display' => '101000, Москва, Тестовая, 9',
	'fields' => array(
		'cdek_city_name' => 'Москва',
		'cdek_postal_code' => '101000',
		'cdek_delivery_address' => 'Тестовая, 9',
	),
);
$courier_request = $factory->create_request_from_admin_data(
	$courier_order,
	array( 'places' => array( array( 'weight_g' => '1100', 'length_cm' => '20', 'width_cm' => '15', 'height_cm' => '10' ) ), 'courier_original_address' => '101000, Москва, Тестовая, 1', 'normalized_address_json' => wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE ), 'recipient_phone' => '+79990000000', 'date_pickup' => '2026-06-18' )
);
$courier_body = $adapter->build_safe_payload_preview( $courier_request )['body']['request']['order'] ?? array();
dpd_shipment_assert( 'NSK-SENDER' === (string) ( $courier_body['pickup']['terminalCode'] ?? '' ) && ! isset( $courier_body['delivery']['terminalCode'] ), 'DPD courier preview must contain pickup terminalCode and no delivery terminalCode.' );
dpd_shipment_assert( 'Тестовая, 9' === (string) ( $courier_body['delivery']['address'] ?? '' ), 'DPD courier payload must use normalized address when provided.' );
dpd_shipment_assert( str_contains( $draft_source, 'Оригинальный адрес покупателя' ) && str_contains( $draft_source, 'Нормализованный адрес DPD' ), 'DPD courier modal must expose address normalization fields.' );

$settings_repo->set( DpdSettings::TARIFF_DEFAULT_SENDER_TERMINAL_CODE_KEY, '' );
$warning_request = ( new OrderShipmentDraftFactory( new DeliveryServiceRepository(), new ShipmentServiceSettings(), null, null, null, null, null, $settings, $pickup_service ) )->create_request_from_admin_data( $pickup_order, array( 'places' => array( array( 'weight_g' => '1000', 'length_cm' => '10', 'width_cm' => '10', 'height_cm' => '10' ) ), 'recipient_phone' => '+79990000000' ) );
$warning_preview = $adapter->build_safe_payload_preview( $warning_request );
dpd_shipment_assert( in_array( 'ПВЗ отправителя по умолчанию не задан.', $warning_preview['warnings'] ?? array(), true ), 'Missing sender default terminalCode must produce a warning.' );

$missing_delivery_order = new DpdShipmentFakeOrder( 632, DeliveryType::PICKUP );
$missing_delivery_order->meta['_wdc_dpd_pickup_terminal_code'] = '';
$missing_delivery_order->meta['_wdc_platform_rate_meta']['dpd_delivery_terminal_code'] = '';
$missing_delivery_request = $factory->create_request_from_admin_data( $missing_delivery_order, array( 'places' => array( array( 'weight_g' => '1000', 'length_cm' => '10', 'width_cm' => '10', 'height_cm' => '10' ) ), 'recipient_phone' => '+79990000000' ) );
dpd_shipment_assert( in_array( 'DPD delivery terminalCode получателя обязателен для доставки до ПВЗ.', $builder->validate( $missing_delivery_request ), true ), 'Missing pickup delivery terminalCode must produce validation error.' );
dpd_shipment_assert( in_array( 'Добавьте хотя бы одно грузоместо.', $builder->validate( $base_request ), true ), 'Missing parcels must produce validation error.' );
dpd_shipment_assert( in_array( 'Адрес DPD курьер нужно обработать перед предпросмотром payload.', $builder->validate( $factory->create_request_from_admin_data( $courier_order, array( 'places' => array( array( 'weight_g' => '1100', 'length_cm' => '20', 'width_cm' => '15', 'height_cm' => '10' ) ), 'courier_original_address' => '101000, Москва, Тестовая, 1', 'recipient_phone' => '+79990000000' ) ) ), true ), 'DPD courier preview must require address normalization.' );
$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipments-admin.js' );
dpd_shipment_assert( str_contains( $draft_source, 'data-wdc-weight-hint' ) && str_contains( $js_source, 'hint.hidden = places.length !== 1' ), 'Single-place weight hint must be common and hidden for multi-place mode.' );
dpd_shipment_assert( str_contains( $js_source, 'cityCodeRow.hidden = isDpd || !cityCode' ), 'DPD courier modal must not display CDEK city code after address normalization.' );
dpd_shipment_assert( str_contains( $draft_source, 'data-wdc-cdek-city-code-row <?php echo ( $is_cdek' ), 'CDEK courier modal must still display CDEK city code when normalization has it.' );

$missing_date_request = $factory->create_request_from_admin_data( $pickup_order, array( 'places' => array( array( 'weight_g' => '1000', 'length_cm' => '10', 'width_cm' => '10', 'height_cm' => '10' ) ), 'recipient_phone' => '+79990000000', 'date_pickup' => '' ) );
dpd_shipment_assert( in_array( 'Дата отправки DPD обязательна.', $builder->validate( $missing_date_request ), true ), 'Missing datePickup must produce validation error.' );
$invalid_date_request = $factory->create_request_from_admin_data( $pickup_order, array( 'places' => array( array( 'weight_g' => '1000', 'length_cm' => '10', 'width_cm' => '10', 'height_cm' => '10' ) ), 'recipient_phone' => '+79990000000', 'date_pickup' => '2026/06/18' ) );
dpd_shipment_assert( in_array( 'Дата отправки DPD должна быть в формате YYYY-MM-DD.', $builder->validate( $invalid_date_request ), true ), 'Invalid datePickup must produce validation error.' );
$past_date_request = $factory->create_request_from_admin_data( $pickup_order, array( 'places' => array( array( 'weight_g' => '1000', 'length_cm' => '10', 'width_cm' => '10', 'height_cm' => '10' ) ), 'recipient_phone' => '+79990000000', 'date_pickup' => '2020-01-01' ) );
dpd_shipment_assert( in_array( 'Дата отправки DPD не может быть в прошлом.', $builder->validate( $past_date_request ), true ), 'Past datePickup must be rejected.' );

$create_result = $adapter->create( $request );
dpd_shipment_assert( ! $create_result->success && 'dpd_create_disabled' === $create_result->error_code, 'DPD create shipment action must be disabled.' );
$registry = new CarrierShipmentAdapterRegistry( array( $adapter ) );
dpd_shipment_assert( $registry->has( DpdSettings::CARRIER_KEY ) && ! $registry->get( DpdSettings::CARRIER_KEY )->supports_status_auto_sync(), 'DPD adapter must be registered only for manual preparation/dry-run.' );

echo "DPD shipment preparation smoke passed\n";
