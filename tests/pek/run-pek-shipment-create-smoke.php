<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Shipments\Pek\PekShipmentAdapter;
use WallsShop\WDC\Shipments\Pek\PekShipmentCargoBuilder;

function pek_shipment_create_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function pek_json_fixture( string $name ): array {
	$path = dirname( __DIR__ ) . '/pek/fixtures/' . $name;
	$data = json_decode( file_get_contents( $path ) ?: '', true );
	pek_shipment_create_assert( is_array( $data ), 'Fixture must be valid JSON: ' . $name );

	return $data;
}

function pek_assert_no_key_recursive( array $data, string $forbidden ): void {
	foreach ( $data as $key => $value ) {
		pek_shipment_create_assert( $key !== $forbidden, 'Forbidden key present: ' . $forbidden );
		if ( is_array( $value ) ) {
			pek_assert_no_key_recursive( $value, $forbidden );
		}
	}
}

$pickup = pek_json_fixture( 'preregistration-submit-pickup.json' );
$courier = pek_json_fixture( 'preregistration-submit-courier.json' );
$response = pek_json_fixture( 'preregistration-submit-response.json' );

foreach ( array( $pickup, $courier ) as $payload ) {
	pek_shipment_create_assert( isset( $payload['common'], $payload['sender'], $payload['cargos'] ), 'Request root hierarchy must be common/sender/cargos.' );
	pek_shipment_create_assert( ! isset( $payload['payer'] ), 'Root payer must not be sent.' );
	pek_shipment_create_assert( 0 === $payload['common']['orderType'], 'Root orderType must be 0.' );
	pek_shipment_create_assert( ! isset( $payload['common']['type'], $payload['common']['customerCorrelation'], $payload['common']['orderNumber'] ), 'Cargo fields must not be in root common.' );
	$cargo = $payload['cargos'][0];
	pek_shipment_create_assert( 3 === $cargo['common']['type'], 'Cargo common type must be LTL 3.' );
	pek_shipment_create_assert( isset( $cargo['common']['cargoPlaceList'] ), 'cargoPlaceList must live in cargos[].common.' );
	foreach ( $cargo['services'] as $service ) {
		pek_shipment_create_assert( is_array( $service['payer'] ?? null ) && 1 === (int) $service['payer']['type'], 'Every enabled service must use payer.type=1.' );
	}
	pek_shipment_create_assert( ! isset( $cargo['services']['smsRelease'] ), 'Invented smsRelease service field must not be sent.' );
	pek_shipment_create_assert( ! isset( $cargo['receiver']['phone'], $cargo['receiver']['individual']['name'], $cargo['receiver']['identityCard'] ), 'Receiver must use physical/SMS shape without passport aliases.' );
	pek_assert_no_key_recursive( $payload, 'position' );
	pek_assert_no_key_recursive( $payload, 'hardPacking' );
	pek_assert_no_key_recursive( $payload, 'bag' );
}

pek_shipment_create_assert( isset( $pickup['cargos'][0]['receiver']['warehouseId'] ) && ! isset( $pickup['cargos'][0]['services']['delivery'] ), 'Pickup must use receiver warehouse and no delivery service.' );
pek_shipment_create_assert( isset( $courier['cargos'][0]['receiver']['addressStock'], $courier['cargos'][0]['services']['delivery'] ) && ! isset( $courier['cargos'][0]['receiver']['warehouseId'] ), 'Courier must use addressStock and delivery service.' );
pek_shipment_create_assert( isset( $response['documentId'], $response['cargos'][0]['cargoCode'], $response['cargos'][0]['positions'][0]['barcode'] ), 'Create response fixture must use preregistration identifiers.' );

$settings = ( new ReflectionClass( PekSettings::class ) )->newInstanceWithoutConstructor();
$builder = new PekShipmentCargoBuilder( $settings );
$request = new ShipmentCreateRequest(
	1,
	PekSettings::CARRIER_KEY,
	DeliveryType::PICKUP,
	'pek:pickup',
	new Address( country_code: 'RU', city: 'Москва', raw_address: 'Москва' ),
	null,
	array(
		new ShipmentPlace( 1, 1241, 20, 20, 20, Money::from_kopecks( 0 ), array(), false, 'Товары интернет-магазина' ),
	),
	Money::from_kopecks( 0 ),
	true,
	array(),
	array(),
	array( 'order_num' => '1001', 'pek_correlation' => 'wdc-pek-1' )
);
$built = $builder->build( $request, 150000 );
$common = $built['payload']['common'];
$place = $common['cargoPlaceList'][0];
pek_shipment_create_assert( ! isset( $built['payload']['cargoPlaceList'], $built['payload']['cost'] ), 'Cargo payload must not use legacy top-level cargo aliases.' );
pek_shipment_create_assert( $common['weight'] === $place['weight'], 'Aggregate weight must equal transmitted place sum.' );
pek_shipment_create_assert( $common['volume'] === ceil( ( $place['length'] * $place['width'] * $place['height'] ) * 100 ) / 100, 'Aggregate volume must match transmitted place dimensions.' );
pek_shipment_create_assert( PekShipmentAdapter::class !== '', 'PEK adapter class must be autoloadable.' );

echo "PEK shipment create smoke passed.\n";
