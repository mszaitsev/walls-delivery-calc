<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Pickup\PickupPointSelection;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Admin\OrderShipmentsMetabox;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Contracts\ShipmentCarrierAdapterInterface;
use WallsShop\WDC\Shipments\RussianPost\RussianPostCreateRequestBuilder;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentProductMapper;
use WallsShop\WDC\Shipments\RussianPost\RussianPostAddressNormalizer;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
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
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\\-]/', '', (string) $value ) ?? '' );
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( mixed $value ): string {
		return trim( (string) $value );
	}
}

function shipments_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

class ShipmentsSmokeOrder {
	public function __construct( private array $data, private array $meta = array() ) {
	}

	public function get_id(): int {
		return (int) ( $this->data['id'] ?? 0 );
	}

	public function get_shipping_postcode(): string {
		return (string) ( $this->data['postcode'] ?? '' );
	}

	public function get_shipping_state(): string {
		return (string) ( $this->data['state'] ?? '' );
	}

	public function get_shipping_city(): string {
		return (string) ( $this->data['city'] ?? '' );
	}

	public function get_billing_city(): string {
		return (string) ( $this->data['billing_city'] ?? '' );
	}

	public function get_shipping_address_1(): string {
		return (string) ( $this->data['address_1'] ?? '' );
	}

	public function get_shipping_address_2(): string {
		return (string) ( $this->data['address_2'] ?? '' );
	}

	public function get_meta( string $key, bool $single = true ): mixed {
		return $this->meta[ $key ] ?? '';
	}
}

final class ShipmentsSmokeAdapter implements ShipmentCarrierAdapterInterface {
	public bool $called = false;

	public function carrier_key(): string {
		return RussianPostDomesticSettings::CARRIER_KEY;
	}

	public function supports( ShipmentCreateRequest $request ): bool {
		return true;
	}

	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		return array();
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		$this->called = true;
		return new ShipmentCreateResult( true );
	}
}

$builder = new RussianPostCreateRequestBuilder( new RussianPostShipmentProductMapper() );
$item = new PackageItem( 'SKU-1', 'Обои', 2, Money::from_rubles( 1000 ), Money::from_rubles( 2000 ), 500 );
$request = new ShipmentCreateRequest(
	order_id: 123,
	carrier_key: RussianPostDomesticSettings::CARRIER_KEY,
	delivery_type: DeliveryType::PICKUP,
	rate_id: RussianPostDomesticSettings::PICKUP_SERVICE_KEY,
	recipient_address: new Address( country_code: 'RU', region_name: 'Новосибирская область', city: 'Новосибирск', postcode: '630001', raw_address: 'Новосибирск' ),
	pickup_point: new PickupPointSelection( RussianPostDomesticSettings::CARRIER_KEY, RussianPostDomesticSettings::PICKUP_SERVICE_KEY, '630001', 'Новосибирск, тестовый ПВЗ', '2026-06-04 10:00:00' ),
	places: array(
		new ShipmentPlace( 1, 1200, 20, 20, 10, Money::from_kopecks( 0 ), array( $item ) ),
		new ShipmentPlace( 2, 1300, 21, 20, 10, Money::from_kopecks( 0 ), array( $item ) ),
	),
	declared_value: Money::from_kopecks( 0 ),
	services: array(
		'shelf_life_days' => 30,
		'send_goods_items' => false,
		'combine_goods_items' => true,
		'combined_goods_name' => 'Товары по заказу 123',
	),
	recipient: array(
		'name' => 'Иванов Иван Иванович',
		'phone' => '+7 (999) 000-00-00',
		'email' => 'buyer@example.test',
	),
	meta: array(
		'order_num' => '123',
		'postoffice_code' => '630005',
		'tariff_object' => '23030',
		'pickup_point_found' => true,
	)
);

$payload = $builder->build( $request );
shipments_smoke_assert( 2 === count( $payload ), 'MMO payload must create one order object per place.' );
shipments_smoke_assert( true === $payload[0]['add-to-mmo'] && '123' === $payload[0]['group-name'], 'MMO fields must be set.' );
shipments_smoke_assert( array( '123-1', '123-2' ) === array_column( $payload, 'order-num' ), 'MMO order-num values must belong to the current order only.' );
shipments_smoke_assert( 'ONLINE_PARCEL' === $payload[0]['mail-type'], 'Object 23030 must map to ONLINE_PARCEL.' );
shipments_smoke_assert( 'ORDINARY' === $payload[0]['mail-category'], 'Ordinary parcel/courier/EMS variants must use ORDINARY category.' );
shipments_smoke_assert( ! array_key_exists( 'goods', $payload[0] ), 'goods must not be sent when disabled.' );
shipments_smoke_assert( 643 === $payload[0]['mail-direct'], 'Domestic Russian Post payload must set mail-direct=643.' );
shipments_smoke_assert( 'DEMAND' === $payload[0]['address-type-to'], 'Normal pickup/OPS payload must use DEMAND address type.' );
shipments_smoke_assert( '630001' === $payload[0]['index-to'], 'Normal pickup/OPS payload must include selected destination index.' );
shipments_smoke_assert( 'Новосибирская область' === $payload[0]['region-to'], 'Normal pickup/OPS payload must include region-to.' );
shipments_smoke_assert( 'Новосибирск' === $payload[0]['place-to'], 'Normal pickup/OPS payload must include place-to.' );
shipments_smoke_assert( ! array_key_exists( 'ecom-data', $payload[0] ), 'Normal pickup/OPS payload must not include ecom-data.' );
shipments_smoke_assert( '79990000000' === $payload[0]['tel-address'], 'tel-address must be digit-only.' );
shipments_smoke_assert( '630005' === $payload[0]['postoffice-code'], 'Postoffice code must be present.' );

$ecom_request = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	DeliveryType::PICKUP,
	$request->rate_id,
	$request->recipient_address,
	$request->pickup_point,
	array( new ShipmentPlace( 1, 1200, 20, 20, 10, Money::from_kopecks( 0 ), array( $item ) ) ),
	$request->declared_value,
	false,
	$request->services,
	$request->recipient,
	array( 'order_num' => '123', 'postoffice_code' => '630005', 'tariff_object' => '54020', 'tariff_is_ecom' => true, 'pickup_point_found' => true )
);
$ecom_payload = $builder->build( $ecom_request );
shipments_smoke_assert( '630001' === $ecom_payload[0]['ecom-data']['delivery-point-index'], 'ECOM pickup must use ecom-data delivery-point-index.' );
shipments_smoke_assert( ! array_key_exists( 'address-type-to', $ecom_payload[0] ) && ! array_key_exists( 'index-to', $ecom_payload[0] ), 'ECOM pickup must not include normal address schema.' );

$pickup_code_with_suffix = new PickupPointSelection( RussianPostDomesticSettings::CARRIER_KEY, RussianPostDomesticSettings::PICKUP_SERVICE_KEY, '630091-53b5939ce9', 'Новосибирск, тестовый ПВЗ', '2026-06-04 10:00:00' );
$pickup_suffix_request = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	DeliveryType::PICKUP,
	$request->rate_id,
	$request->recipient_address,
	$pickup_code_with_suffix,
	array( new ShipmentPlace( 1, 1200, 20, 20, 10, Money::from_kopecks( 0 ), array( $item ) ) ),
	$request->declared_value,
	false,
	$request->services,
	$request->recipient,
	array( 'order_num' => '123', 'postoffice_code' => '630005', 'tariff_object' => '23030', 'pickup_point_found' => true )
);
$pickup_suffix_payload = $builder->build( $pickup_suffix_request );
shipments_smoke_assert( '630091' === $pickup_suffix_payload[0]['index-to'], 'Normal pickup must extract 6-digit index from pickup point code with suffix.' );

$ecom_suffix_request = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	DeliveryType::PICKUP,
	$request->rate_id,
	$request->recipient_address,
	$pickup_code_with_suffix,
	array( new ShipmentPlace( 1, 1200, 20, 20, 10, Money::from_kopecks( 0 ), array( $item ) ) ),
	$request->declared_value,
	false,
	$request->services,
	$request->recipient,
	array( 'order_num' => '123', 'postoffice_code' => '630005', 'tariff_object' => '54020', 'tariff_is_ecom' => true, 'pickup_point_found' => true )
);
$ecom_suffix_payload = $builder->build( $ecom_suffix_request );
shipments_smoke_assert( '630091-53b5939ce9' === $ecom_suffix_payload[0]['ecom-data']['delivery-point-index'], 'ECOM pickup must keep full delivery-point-index code.' );

$metabox_reflection = new ReflectionClass( OrderShipmentsMetabox::class );
$metabox = $metabox_reflection->newInstanceWithoutConstructor();
$pickup_destination_index = $metabox_reflection->getMethod( 'pickup_destination_index' );
$pickup_destination_index->setAccessible( true );
$ui_index = $pickup_destination_index->invoke( $metabox, '630091-53b5939ce9', '630001', array() );
$ui_demand_address = implode( ', ', array_filter( array( $ui_index, 'Новосибирская область', 'Новосибирск', 'до востребования' ) ) );
shipments_smoke_assert( '630091, Новосибирская область, Новосибирск, до востребования' === $ui_demand_address, 'Admin pickup demand address must use normalized 6-digit destination index.' );

$goods_request = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	DeliveryType::COURIER,
	RussianPostDomesticSettings::COURIER_SERVICE_KEY,
	new Address( country_code: 'RU', region_name: 'Новосибирская область', city: 'Новосибирск', postcode: '630099', raw_address: '630099, Новосибирская область, Новосибирск, Красный проспект 1' ),
	null,
	array( new ShipmentPlace( 1, 1200, 20, 20, 10, Money::from_kopecks( 10000 ), array( $item ) ) ),
	Money::from_kopecks( 10000 ),
	false,
	array( 'send_goods_items' => true, 'combine_goods_items' => true, 'combined_goods_name' => 'Товары по заказу 123' ),
	$request->recipient,
	array( 'order_num' => '123', 'postoffice_code' => '630005', 'tariff_object' => '24030', 'allow_failed_normalization_preview' => true )
);
$goods_payload = $builder->build( $goods_request );
shipments_smoke_assert( ! array_key_exists( 'courier', $goods_payload[0] ) && ! array_key_exists( 'delivery-to-door', $goods_payload[0] ), 'Courier payload must not include courier or delivery-to-door flags.' );
shipments_smoke_assert( 643 === $goods_payload[0]['mail-direct'] && 'DEFAULT' === $goods_payload[0]['address-type-to'], 'Courier payload must set domestic mail-direct and DEFAULT address type.' );
shipments_smoke_assert( '630099' === $goods_payload[0]['index-to'] && 'Новосибирская область' === $goods_payload[0]['region-to'] && 'Новосибирск' === $goods_payload[0]['place-to'] && ! isset( $goods_payload[0]['ecom-data'] ), 'Courier payload must include address fields and omit ecom-data.' );
shipments_smoke_assert( ! array_key_exists( 'raw-address', $goods_payload[0] ), 'Courier fallback payload must not include raw-address.' );
shipments_smoke_assert( ! array_key_exists( 'insr-value', $goods_payload[0] ), 'Ordinary courier tariff must ignore declared value input.' );
shipments_smoke_assert( isset( $goods_payload[0]['goods']['items'][0] ), 'goods.items must be present when enabled.' );

$declared_value_request = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	DeliveryType::COURIER,
	RussianPostDomesticSettings::COURIER_SERVICE_KEY,
	$goods_request->recipient_address,
	null,
	array( new ShipmentPlace( 1, 1200, 20, 20, 10, Money::from_kopecks( 100000 ), array( $item ) ) ),
	Money::from_kopecks( 100000 ),
	false,
	array( 'send_goods_items' => false ),
	$request->recipient,
	array( 'order_num' => '123', 'postoffice_code' => '630005', 'tariff_object' => '24020', 'allow_failed_normalization_preview' => true )
);
$declared_value_payload = $builder->build( $declared_value_request );
shipments_smoke_assert( 100000 === $declared_value_payload[0]['insr-value'], 'Declared-value tariff must pass insurance in kopecks.' );

$empty_place_request = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	DeliveryType::PICKUP,
	$request->rate_id,
	$request->recipient_address,
	$request->pickup_point,
	array( new ShipmentPlace( 1, 0, 0, 0, 0, Money::from_kopecks( 0 ), array( $item ) ) ),
	$request->declared_value,
	false,
	$request->services,
	$request->recipient,
	array( 'order_num' => '123', 'postoffice_code' => '630005', 'tariff_object' => '23030', 'pickup_point_found' => true )
);
$empty_place_errors = $builder->validate( $empty_place_request );
shipments_smoke_assert( in_array( 'Место 1: вес обязателен.', $empty_place_errors, true ), 'Empty place validation must require weight in Russian.' );
shipments_smoke_assert( in_array( 'Место 1: длина обязательна.', $empty_place_errors, true ), 'Empty place validation must require length in Russian.' );
shipments_smoke_assert( in_array( 'Место 1: ширина обязательна.', $empty_place_errors, true ), 'Empty place validation must require width in Russian.' );
shipments_smoke_assert( in_array( 'Место 1: высота обязательна.', $empty_place_errors, true ), 'Empty place validation must require height in Russian.' );

$normalizer_reflection = new ReflectionClass( RussianPostAddressNormalizer::class );
$normalizer = $normalizer_reflection->newInstanceWithoutConstructor();
$normalized_snapshot = $normalizer->normalize_row(
	array(
		'quality-code' => 'GOOD',
		'validation-code' => 'VALIDATED',
		'index' => '630099',
		'region' => 'Новосибирская область',
		'area' => '',
		'place' => 'Новосибирск',
		'street' => 'Красный проспект',
		'house' => '1',
		'room' => '23',
		'address-type' => 'DEFAULT',
	),
	'630099, Новосибирская область, Новосибирск, Красный проспект 1'
);
shipments_smoke_assert( ! empty( $normalized_snapshot['success'] ), 'GOOD + VALIDATED clean-address result must be accepted.' );
shipments_smoke_assert( str_contains( (string) $normalized_snapshot['display'], 'Красный проспект' ) && ! str_contains( (string) $normalized_snapshot['display'], 'ул. Красный проспект' ), 'Normalized address display must not prefix street with ul.' );
shipments_smoke_assert( str_contains( (string) $normalized_snapshot['display'], '23' ) && ! str_contains( (string) $normalized_snapshot['display'], 'кв/оф. 23' ), 'Normalized address display must not prefix room.' );
$normalized_request = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	DeliveryType::COURIER,
	RussianPostDomesticSettings::COURIER_SERVICE_KEY,
	new Address( country_code: 'RU', region_name: 'Новосибирская область', city: 'Новосибирск', postcode: '630099' ),
	null,
	array( new ShipmentPlace( 1, 1200, 20, 20, 10, Money::from_kopecks( 0 ), array( $item ) ) ),
	Money::from_kopecks( 0 ),
	false,
	array( 'send_goods_items' => false ),
	$request->recipient,
	array(
		'order_num' => '123',
		'postoffice_code' => '630005',
		'tariff_object' => '24030',
		'normalized_address' => $normalized_snapshot,
		'normalization_required' => true,
		'normalization_attempted' => true,
		'normalization_valid' => true,
	)
);
$normalized_payload = $builder->build( $normalized_request );
shipments_smoke_assert( 'Новосибирская область' === $normalized_payload[0]['region-to'] && 'Красный проспект' === $normalized_payload[0]['street-to'] && '23' === $normalized_payload[0]['room-to'], 'Courier normalized payload must use clean-address fields.' );

$changed_address_request = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	DeliveryType::COURIER,
	RussianPostDomesticSettings::COURIER_SERVICE_KEY,
	new Address( country_code: 'RU', region_name: 'Новосибирская область', city: 'Новосибирск', postcode: '630099', street: 'Красный проспект 2' ),
	null,
	array( new ShipmentPlace( 1, 1200, 20, 20, 10, Money::from_kopecks( 0 ), array( $item ) ) ),
	Money::from_kopecks( 0 ),
	false,
	array( 'send_goods_items' => false ),
	$request->recipient,
	array(
		'order_num' => '123',
		'postoffice_code' => '630005',
		'tariff_object' => '24030',
		'normalization_required' => true,
		'normalization_attempted' => false,
	)
);
$normalization_error = 'Адрес курьерской доставки нужно успешно обработать через Почту России перед созданием отправления.';
shipments_smoke_assert( in_array( $normalization_error, $builder->validate( $changed_address_request ), true ), 'Courier create must be blocked when original address changed before re-normalization.' );

$failed_snapshot = $normalizer->normalize_row(
	array(
		'quality-code' => 'UNDEF_01',
		'validation-code' => 'NOT_VALIDATED',
		'index' => '630099',
		'region' => 'Новосибирская область',
		'place' => 'Новосибирск',
	),
	'630099, Новосибирская область, Новосибирск'
);
$failed_normalization_request = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	DeliveryType::COURIER,
	RussianPostDomesticSettings::COURIER_SERVICE_KEY,
	new Address( country_code: 'RU', region_name: 'Новосибирская область', city: 'Новосибирск', postcode: '630099' ),
	null,
	array( new ShipmentPlace( 1, 1200, 20, 20, 10, Money::from_kopecks( 0 ), array( $item ) ) ),
	Money::from_kopecks( 0 ),
	false,
	array( 'send_goods_items' => false ),
	$request->recipient,
	array(
		'order_num' => '123',
		'postoffice_code' => '630005',
		'tariff_object' => '24030',
		'normalized_address' => $failed_snapshot,
		'normalization_required' => true,
		'normalization_attempted' => true,
		'normalization_valid' => false,
	)
);
shipments_smoke_assert( in_array( $normalization_error, $builder->validate( $failed_normalization_request ), true ), 'Courier create must be blocked when normalization failed.' );
$failed_preview_request = new ShipmentCreateRequest(
	$failed_normalization_request->order_id,
	$failed_normalization_request->carrier_key,
	$failed_normalization_request->delivery_type,
	$failed_normalization_request->rate_id,
	$failed_normalization_request->recipient_address,
	$failed_normalization_request->pickup_point,
	$failed_normalization_request->places,
	$failed_normalization_request->declared_value,
	$failed_normalization_request->insurance_enabled,
	$failed_normalization_request->services,
	$failed_normalization_request->recipient,
	array_merge( $failed_normalization_request->meta, array( 'allow_failed_normalization_preview' => true ) )
);
$failed_preview_payload = $builder->build( $failed_preview_request );
shipments_smoke_assert( '630099' === $failed_preview_payload[0]['index-to'] && ! array_key_exists( 'raw-address', $failed_preview_payload[0] ), 'Failed courier normalization preview must use safe fallback without raw-address.' );

$guard_adapter = new ShipmentsSmokeAdapter();
$creation_service = new ShipmentCreationService( new OrderShipmentRepository(), array( $guard_adapter ) );
$wrong_order_result = $creation_service->create(
	new ShipmentsSmokeOrder( array( 'id' => 935 ) ),
	new ShipmentCreateRequest(
		936,
		$request->carrier_key,
		DeliveryType::PICKUP,
		$request->rate_id,
		$request->recipient_address,
		$request->pickup_point,
		array( new ShipmentPlace( 1, 1200, 20, 20, 10, Money::from_kopecks( 0 ), array( $item ) ) ),
		$request->declared_value,
		false,
		$request->services,
		$request->recipient,
		array( 'order_num' => '936', 'postoffice_code' => '630005', 'tariff_object' => '23030', 'pickup_point_found' => true )
	)
);
shipments_smoke_assert( ! $wrong_order_result->success && 'shipment_order_mismatch' === $wrong_order_result->error_code && ! $guard_adapter->called, 'Shipment creation service must block wrong order_id before API adapter call.' );

$missing_pickup = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	DeliveryType::PICKUP,
	$request->rate_id,
	$request->recipient_address,
	null,
	$request->places,
	$request->declared_value,
	false,
	$request->services,
	$request->recipient,
	array( 'order_num' => '123', 'postoffice_code' => '630005', 'tariff_object' => '23030', 'pickup_point_found' => true )
);
shipments_smoke_assert( in_array( 'Код ПВЗ/почтомата обязателен.', $builder->validate( $missing_pickup ), true ), 'Pickup validation must require delivery-point-index.' );

$missing_phone = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	$request->delivery_type,
	$request->rate_id,
	$request->recipient_address,
	$request->pickup_point,
	$request->places,
	$request->declared_value,
	false,
	$request->services,
	array( 'name' => 'Иванов Иван', 'phone' => '++ --', 'email' => '' ),
	$request->meta
);
shipments_smoke_assert( in_array( 'Телефон получателя обязателен.', $builder->validate( $missing_phone ), true ), 'Phone validation must use digit-only normalized value.' );

$missing_tariff = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	$request->delivery_type,
	$request->rate_id,
	$request->recipient_address,
	$request->pickup_point,
	$request->places,
	$request->declared_value,
	false,
	$request->services,
	$request->recipient,
	array( 'order_num' => '123', 'postoffice_code' => '630005' )
);
shipments_smoke_assert( in_array( 'Выберите тариф для создания отправления.', $builder->validate( $missing_tariff ), true ), 'Builder validation must reject empty tariff_object.' );

$settings = ShipmentServiceSettings::sanitize_from_post(
	array(
		'shelf_life_days_default' => '90',
		'send_goods_items' => '1',
		'combine_goods_items_default' => '',
		'combined_goods_name_template' => 'Заказ {order_number}',
	),
	RussianPostDomesticSettings::PICKUP_SERVICE_KEY
);
shipments_smoke_assert( 60 === $settings['shelf_life_days_default']['value'], 'Shelf life must clamp to 15..60.' );
shipments_smoke_assert( true === $settings['send_goods_items']['value'], 'send_goods_items must sanitize as bool.' );

$factory_reflection = new ReflectionClass( OrderShipmentDraftFactory::class );
$factory = $factory_reflection->newInstanceWithoutConstructor();
foreach ( array( 'domestic_settings', 'otpravka_settings' ) as $property_name ) {
	$property = $factory_reflection->getProperty( $property_name );
	$property->setAccessible( true );
	$property->setValue( $factory, null );
}

$tariffs_for_service = $factory_reflection->getMethod( 'tariffs_for_service' );
$tariffs_for_service->setAccessible( true );
$pickup_tariffs = $tariffs_for_service->invoke(
	$factory,
	new \WallsShop\WDC\DeliveryServices\DeliveryService(
		1,
		RussianPostDomesticSettings::PICKUP_SERVICE_KEY,
		RussianPostDomesticSettings::CARRIER_KEY,
		'api',
		'Почта России до отделения'
	)
);
shipments_smoke_assert( array() !== $pickup_tariffs, 'Draft factory must expose enabled tariffs for selected Russian Post pickup service.' );
shipments_smoke_assert( '4030' === (string) ( $pickup_tariffs[0]['object_code'] ?? '' ), 'Draft tariff list must fall back to default enabled domestic tariff variants.' );
shipments_smoke_assert( array_key_exists( 'has_declared_value', $pickup_tariffs[0] ), 'Draft tariff list must expose declared-value flag for admin UI.' );
shipments_smoke_assert( false === (bool) ( $pickup_tariffs[0]['has_declared_value'] ?? true ), 'Ordinary pickup tariff must not require declared-value UI.' );
$declared_tariff_rows = array_values( array_filter( $pickup_tariffs, static fn ( array $row ): bool => '23020' === (string) ( $row['object_code'] ?? '' ) ) );
shipments_smoke_assert( array() !== $declared_tariff_rows && ! empty( $declared_tariff_rows[0]['has_declared_value'] ), 'Declared-value pickup tariff must expose declared-value UI flag.' );

$shipping_address = $factory_reflection->getMethod( 'shipping_address' );
$shipping_address->setAccessible( true );

$full_address = $shipping_address->invoke(
	$factory,
	new ShipmentsSmokeOrder(
		array(
			'postcode' => '630005',
			'state' => 'Новосибирская область',
			'city' => 'Новосибирск',
			'address_1' => 'ул. Ленина 15',
			'address_2' => 'кв. 23',
		)
	)
);
shipments_smoke_assert( '630005, Новосибирская область, Новосибирск, ул. Ленина 15, кв. 23' === $full_address, 'Courier raw-address must include postcode, state, city and address lines.' );

$short_address = $shipping_address->invoke(
	$factory,
	new ShipmentsSmokeOrder(
		array(
			'postcode' => '630005',
			'city' => 'Новосибирск',
			'address_1' => 'ул. Ленина 15',
		)
	)
);
shipments_smoke_assert( '630005, Новосибирск, ул. Ленина 15' === $short_address, 'Courier raw-address must skip empty state/address_2 fragments.' );

$pickup_code_address = $shipping_address->invoke(
	$factory,
	new ShipmentsSmokeOrder(
		array(
			'postcode' => '630005',
			'state' => 'Новосибирская область',
			'city' => 'Новосибирск',
			'address_1' => 'ул. Ленина 15',
			'address_2' => 'Код ПВЗ 630001',
		)
	)
);
shipments_smoke_assert( '630005, Новосибирская область, Новосибирск, ул. Ленина 15' === $pickup_code_address, 'Courier raw-address must skip address_2 when it starts with pickup code marker.' );

$declared_value_method = $factory_reflection->getMethod( 'declared_value_from_place_row' );
$declared_value_method->setAccessible( true );
$insurance_1000 = $declared_value_method->invoke( $factory, array( 'declared_value_rub' => '1000' ) );
$insurance_2500 = $declared_value_method->invoke( $factory, array( 'declared_value_rub' => '2500' ) );
$insurance_spaced = $declared_value_method->invoke( $factory, array( 'declared_value_rub' => '2 500 руб.' ) );
$insurance_decimal = $declared_value_method->invoke( $factory, array( 'declared_value_rub' => '12,5' ) );
shipments_smoke_assert( 100000 === $insurance_1000->get_kopecks(), 'Insurance 1000 rub must become 100000 kopecks.' );
shipments_smoke_assert( 250000 === $insurance_2500->get_kopecks(), 'Insurance 2500 rub must become 250000 kopecks.' );
shipments_smoke_assert( 250000 === $insurance_spaced->get_kopecks(), 'Insurance rub input must be cleaned to integer digits on backend.' );
shipments_smoke_assert( 12500 === $insurance_decimal->get_kopecks(), 'Insurance 12,5 rub safety fallback must become 125 rub / 12500 kopecks.' );

shipments_smoke_assert( 1 === count( $pickup_suffix_payload ), 'Normal pickup payload built from one submitted place must contain one order object.' );

echo "Russian Post shipments smoke OK\n";
