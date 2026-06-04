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
use WallsShop\WDC\Shipments\Admin\OrderShipmentsMetabox;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\RussianPost\RussianPostCreateRequestBuilder;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentProductMapper;

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
	)
);

$payload = $builder->build( $request );
shipments_smoke_assert( 2 === count( $payload ), 'MMO payload must create one order object per place.' );
shipments_smoke_assert( true === $payload[0]['add-to-mmo'] && '123' === $payload[0]['group-name'], 'MMO fields must be set.' );
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
	array( 'order_num' => '123', 'postoffice_code' => '630005', 'tariff_object' => '54020', 'tariff_is_ecom' => true )
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
	array( 'order_num' => '123', 'postoffice_code' => '630005', 'tariff_object' => '23030' )
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
	array( 'order_num' => '123', 'postoffice_code' => '630005', 'tariff_object' => '54020', 'tariff_is_ecom' => true )
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
	array( 'order_num' => '123', 'postoffice_code' => '630005', 'tariff_object' => '24030' )
);
$goods_payload = $builder->build( $goods_request );
shipments_smoke_assert( true === $goods_payload[0]['courier'] && true === $goods_payload[0]['delivery-to-door'], 'Courier flags must be set.' );
shipments_smoke_assert( 643 === $goods_payload[0]['mail-direct'] && 'DEFAULT' === $goods_payload[0]['address-type-to'], 'Courier payload must set domestic mail-direct and DEFAULT address type.' );
shipments_smoke_assert( '630099' === $goods_payload[0]['index-to'] && 'Новосибирская область' === $goods_payload[0]['region-to'] && 'Новосибирск' === $goods_payload[0]['place-to'] && ! isset( $goods_payload[0]['ecom-data'] ), 'Courier payload must include address fields and omit ecom-data.' );
shipments_smoke_assert( '630099, Новосибирская область, Новосибирск, Красный проспект 1' === $goods_payload[0]['raw-address'], 'Courier payload must include raw-address.' );
shipments_smoke_assert( isset( $goods_payload[0]['goods']['items'][0] ), 'goods.items must be present when enabled.' );

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
	array( 'order_num' => '123', 'postoffice_code' => '630005', 'tariff_object' => '23030' )
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

$recipient_city = $factory_reflection->getMethod( 'recipient_city' );
$recipient_city->setAccessible( true );
$city_from_display = $recipient_city->invoke(
	$factory,
	new ShipmentsSmokeOrder(
		array( 'city' => '' ),
		array( '_wdc_platform_city_display_name' => 'Новосибирск — Новосибирская область' )
	)
);
shipments_smoke_assert( 'Новосибирск' === $city_from_display, 'Recipient city fallback must use _wdc_platform_city_display_name without region suffix.' );

$city_from_billing = $recipient_city->invoke(
	$factory,
	new ShipmentsSmokeOrder(
		array( 'city' => '', 'billing_city' => 'Искитим' )
	)
);
shipments_smoke_assert( 'Искитим' === $city_from_billing, 'Recipient city fallback must use billing city.' );

$city_from_shipping_meta = $recipient_city->invoke(
	$factory,
	new ShipmentsSmokeOrder(
		array( 'city' => '', 'billing_city' => '' ),
		array( '_shipping_city' => 'г Бердск' )
	)
);
shipments_smoke_assert( 'г Бердск' === $city_from_shipping_meta, 'Recipient city fallback must use _shipping_city meta.' );

$city_from_billing_meta = $recipient_city->invoke(
	$factory,
	new ShipmentsSmokeOrder(
		array( 'city' => '', 'billing_city' => '' ),
		array( '_billing_city' => 'г Бердск' )
	)
);
shipments_smoke_assert( 'г Бердск' === $city_from_billing_meta, 'Recipient city fallback must use _billing_city meta.' );

$city_from_pickup_address_plain = $recipient_city->invoke(
	$factory,
	new ShipmentsSmokeOrder(
		array( 'city' => '', 'billing_city' => '' ),
		array( '_wdc_pickup_point_address' => '633010 обл. Новосибирская Бердск г. Ленина ул., д. 67' )
	)
);
shipments_smoke_assert( 'г Бердск' === $city_from_pickup_address_plain, 'Recipient city fallback must parse city from Russian Post pickup address without commas.' );

$city_from_pickup_address_comma = $recipient_city->invoke(
	$factory,
	new ShipmentsSmokeOrder(
		array( 'city' => '', 'billing_city' => '' ),
		array( '_wdc_pickup_point_address' => '633010, Новосибирская область, г Бердск, ул. Ленина, д. 67' )
	)
);
shipments_smoke_assert( 'г Бердск' === $city_from_pickup_address_comma, 'Recipient city fallback must parse city from comma-separated pickup address.' );

$city_from_calculation = $recipient_city->invoke(
	$factory,
	new ShipmentsSmokeOrder(
		array( 'city' => '' ),
		array( '_wdc_delivery_calculation_data' => array( 'destination' => array( 'settlement' => 'Бердск' ) ) )
	)
);
shipments_smoke_assert( 'Бердск' === $city_from_calculation, 'Recipient city fallback must use settlement/city from WDC calculation data.' );

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

$fallback_region_address = $shipping_address->invoke(
	$factory,
	new ShipmentsSmokeOrder(
		array(
			'postcode' => '630005',
			'city' => 'Новосибирск',
			'address_1' => 'ул. Ленина 15',
		),
		array(
			'_wdc_platform_city_display_name' => 'Новосибирск — Новосибирская область',
		)
	)
);
shipments_smoke_assert( '630005, Новосибирская область, Новосибирск, ул. Ленина 15' === $fallback_region_address, 'Courier raw-address must use WDC location metadata when shipping state is empty.' );

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
