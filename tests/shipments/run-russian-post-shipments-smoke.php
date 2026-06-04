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
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;
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

function shipments_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$builder = new RussianPostCreateRequestBuilder( new RussianPostShipmentProductMapper() );
$item = new PackageItem( 'SKU-1', 'Обои', 2, Money::from_rubles( 1000 ), Money::from_rubles( 2000 ), 500 );
$request = new ShipmentCreateRequest(
	order_id: 123,
	carrier_key: RussianPostDomesticSettings::CARRIER_KEY,
	delivery_type: DeliveryType::PICKUP,
	rate_id: RussianPostDomesticSettings::PICKUP_SERVICE_KEY,
	recipient_address: new Address( country_code: 'RU', city: 'Новосибирск', postcode: '630001', raw_address: 'Новосибирск' ),
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
		'phone' => '+79990000000',
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
shipments_smoke_assert( ! array_key_exists( 'goods', $payload[0] ), 'goods must not be sent when disabled.' );
shipments_smoke_assert( '630001' === $payload[0]['ecom-data']['delivery-point-index'], 'Pickup code must become ecom-data delivery-point-index.' );
shipments_smoke_assert( '630005' === $payload[0]['postoffice-code'], 'Postoffice code must be present.' );

$goods_request = new ShipmentCreateRequest(
	$request->order_id,
	$request->carrier_key,
	DeliveryType::COURIER,
	RussianPostDomesticSettings::COURIER_SERVICE_KEY,
	new Address( country_code: 'RU', city: 'Новосибирск', postcode: '630099', raw_address: '630099, Новосибирск, Красный проспект 1' ),
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
shipments_smoke_assert( isset( $goods_payload[0]['goods']['items'][0] ), 'goods.items must be present when enabled.' );

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

echo "Russian Post shipments smoke OK\n";
