<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		unset( $key );
		return $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, bool $autoload = true ): bool {
		unset( $key, $value, $autoload );
		return true;
	}
}

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Shipments\Pek\PekShipmentAdapter;
use WallsShop\WDC\Shipments\Pek\PekShipmentCargoBuilder;
use WallsShop\WDC\Shipments\Pek\PekShipmentCreateResponseParser;
use WallsShop\WDC\Shipments\Pek\PekShipmentProductWeightResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentRecipientBuilder;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

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
	pek_assert_no_key_recursive( $payload, 'declaredCost' );
	pek_shipment_create_assert( ! isset( $cargo['services']['transporting']['enabled'] ), 'Mandatory transporting service must not send enabled flag.' );
	pek_shipment_create_assert( isset( $cargo['receiver']['title'], $cargo['receiver']['person'] ), 'Physical receiver must include title and person.' );
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

$settings = new PekSettings( new SettingsRepository() );
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

$multi_request = new ShipmentCreateRequest(
	1,
	PekSettings::CARRIER_KEY,
	DeliveryType::PICKUP,
	'pek:pickup',
	new Address( country_code: 'RU', city: 'Москва', raw_address: 'Москва' ),
	null,
	array(
		new ShipmentPlace( 1, 100, 10, 10, 10, Money::from_kopecks( 0 ), array(), false, 'Товары интернет-магазина' ),
		new ShipmentPlace( 2, 100, 10, 10, 10, Money::from_kopecks( 0 ), array(), false, 'Товары интернет-магазина' ),
	),
	Money::from_kopecks( 0 ),
	true,
	array(),
	array(),
	array( 'order_num' => '1001', 'pek_correlation' => 'wdc-pek-1' )
);
$multi = $builder->build( $multi_request, 150000 )['payload']['common'];
pek_shipment_create_assert( 0.01 === $multi['volume'], 'Aggregate volume must round after summing raw place volumes, not per-place rounded volumes.' );

$items_light = array(
	new PackageItem( 'a', 'A', 1, Money::from_kopecks( 1000 ), Money::from_kopecks( 1000 ), 1000 ),
	new PackageItem( 'b', 'B', 1, Money::from_kopecks( 1000 ), Money::from_kopecks( 1000 ), 1500 ),
);
$items_heavy = array(
	new PackageItem( 'a', 'A', 1, Money::from_kopecks( 1000 ), Money::from_kopecks( 1000 ), 1600 ),
	new PackageItem( 'b', 'B', 1, Money::from_kopecks( 1000 ), Money::from_kopecks( 1000 ), 1600 ),
);
$weight_resolver = new PekShipmentProductWeightResolver( $settings );
$light_request = new ShipmentCreateRequest( 1, PekSettings::CARRIER_KEY, DeliveryType::PICKUP, 'pek:pickup', new Address( country_code: 'RU', city: 'Москва', raw_address: 'Москва' ), null, array( new ShipmentPlace( 1, 4000, 20, 20, 20, Money::from_kopecks( 0 ), $items_light ) ), Money::from_kopecks( 0 ) );
$heavy_request = new ShipmentCreateRequest( 1, PekSettings::CARRIER_KEY, DeliveryType::PICKUP, 'pek:pickup', new Address( country_code: 'RU', city: 'Москва', raw_address: 'Москва' ), null, array( new ShipmentPlace( 1, 5000, 20, 20, 20, Money::from_kopecks( 0 ), $items_heavy ) ), Money::from_kopecks( 0 ) );
pek_shipment_create_assert( 2500 === $weight_resolver->product_weight_g( $light_request ) && $weight_resolver->sealing_required( $light_request ), 'Product weight fallback must sum all items and enable sealing below threshold.' );
pek_shipment_create_assert( 3200 === $weight_resolver->product_weight_g( $heavy_request ) && ! $weight_resolver->sealing_required( $heavy_request ), 'Product weight fallback must sum all items and disable sealing at/above threshold.' );

$receiver_order = new class {
	public function get_shipping_phone(): string { return '89100000000'; }
	public function get_billing_phone(): string { return ''; }
	public function get_shipping_last_name(): string { return 'Петров'; }
	public function get_shipping_first_name(): string { return 'Петр'; }
	public function get_billing_email(): string { return 'receiver@example.test'; }
	public function get_shipping_state(): string { return 'Москва'; }
	public function get_shipping_city(): string { return 'Москва'; }
	public function get_shipping_address_1(): string { return 'Тверская улица, дом 1'; }
	public function get_shipping_address_2(): string { return ''; }
	public function get_meta( string $key, bool $single = true ): string { unset( $key, $single ); return ''; }
};
$receiver = ( new PekShipmentRecipientBuilder() )->build_physical_recipient( $receiver_order, $request, 'receiver-warehouse-guid' );
pek_shipment_create_assert( 'Петров Петр' === $receiver['title'] && 'Петров Петр' === $receiver['person'] && '+79100000000' === $receiver['personPhones'][0]['phone'], 'Receiver builder must produce title/person and one normalized phone.' );

$parser = new PekShipmentCreateResponseParser();
$parsed = $parser->parse( $response );
pek_shipment_create_assert( '136' === $parsed['document_id'] && '999940950644' === $parsed['cargo_code'], 'Create response parser must parse official preregistration identifiers.' );
try {
	$parser->parse( array( 'documentId' => 136, 'cargos' => array( array() ) ) );
	pek_shipment_create_assert( false, 'Missing cargoCode must fail parser.' );
} catch ( RuntimeException ) {
	pek_shipment_create_assert( true, 'Missing cargoCode fails parser.' );
}

$adapter_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentAdapter.php' ) ?: '';
$status_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentStatusService.php' ) ?: '';
pek_shipment_create_assert( str_contains( $adapter_source, "'method' => 'POST'" ) && str_contains( $adapter_source, "'path' => '/preregistration/submit/'" ) && str_contains( $adapter_source, "'body' => \$built['preview']" ), 'Preview must return canonical framework envelope.' );
pek_shipment_create_assert( str_contains( $adapter_source, '$submitted' ) && str_contains( $adapter_source, "error_code: 'pek_uncertain_submit'" ) && str_contains( $adapter_source, 'safe_summary' ), 'Post-submit parser failures must become uncertain with safe summary.' );
pek_shipment_create_assert( strpos( $status_source, 'unset( $status[\'actual_cost_candidate\'] );' ) < strpos( $status_source, 'save_for_carrier' ), 'Status service must unset actual cost candidate before persistence.' );
pek_shipment_create_assert( PekShipmentAdapter::class !== '', 'PEK adapter class must be autoloadable.' );

echo "PEK shipment create smoke passed.\n";
