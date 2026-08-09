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
use WallsShop\WDC\Carriers\Pek\Quote\PekLightCargoSurchargePolicy;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Shipments\Pek\PekShipmentAdapter;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Pek\PekShipmentCargoBuilder;
use WallsShop\WDC\Shipments\Pek\PekShipmentCourierAddressResolver;
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

function pek_assert_common_services_contract( array $services ): void {
	pek_shipment_create_assert( isset( $services['transporting']['payer']['type'] ) && 1 === (int) $services['transporting']['payer']['type'], 'Transporting must keep payer.type=1 and no enabled flag.' );
	pek_shipment_create_assert( ! isset( $services['transporting']['enabled'] ), 'Mandatory transporting service must not send enabled flag.' );
	pek_shipment_create_assert( array( 'enabled' => false ) === $services['hardPacking'], 'hardPacking must be explicit disabled PEK service state.' );
	pek_shipment_create_assert( array( 'enabled' => false ) === $services['strapping'], 'strapping must be explicit disabled PEK service state.' );
	pek_shipment_create_assert( array( 'enabled' => false ) === $services['documentsReturning'], 'documentsReturning must be explicit disabled PEK service state.' );
	pek_shipment_create_assert( isset( $services['insurance']['enabled'], $services['insurance']['payer']['type'], $services['insurance']['cost'] ) && true === $services['insurance']['enabled'] && 1 === (int) $services['insurance']['payer']['type'], 'Insurance must stay enabled with payer.type=1 and cost.' );
	foreach ( array( 'hardPacking', 'strapping', 'documentsReturning' ) as $disabled ) {
		pek_shipment_create_assert( ! isset( $services[ $disabled ]['payer'] ), $disabled . ' disabled service must not carry payer.' );
	}
	pek_shipment_create_assert( ! isset( $services['smsRelease'], $services['storing'] ), 'Unrelated PEK service fields must not be sent.' );
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
	pek_assert_common_services_contract( $cargo['services'] );
	pek_shipment_create_assert( isset( $cargo['receiver']['title'], $cargo['receiver']['person'] ), 'Physical receiver must include title and person.' );
	foreach ( $cargo['services'] as $service ) {
		if ( true === ( $service['enabled'] ?? true ) ) {
			pek_shipment_create_assert( is_array( $service['payer'] ?? null ) && 1 === (int) $service['payer']['type'], 'Every enabled service must use payer.type=1.' );
		}
	}
	pek_shipment_create_assert( ! isset( $cargo['receiver']['phone'], $cargo['receiver']['individual']['name'], $cargo['receiver']['identityCard'] ), 'Receiver must use physical/SMS shape without passport aliases.' );
	pek_assert_no_key_recursive( $payload, 'position' );
	pek_assert_no_key_recursive( $payload, 'bag' );
	pek_assert_no_key_recursive( $payload, 'smallBag' );
	pek_assert_no_key_recursive( $payload, 'packageType' );
	pek_assert_no_key_recursive( $payload, 'packagingType' );
}

pek_shipment_create_assert( isset( $pickup['cargos'][0]['receiver']['warehouseId'] ) && array( 'enabled' => false ) === $pickup['cargos'][0]['services']['delivery'], 'Pickup must use receiver warehouse and explicit disabled delivery service.' );
pek_shipment_create_assert( ! isset( $pickup['cargos'][0]['services']['delivery']['payer'] ), 'Pickup disabled delivery must not carry payer.' );
pek_shipment_create_assert( isset( $courier['cargos'][0]['receiver']['addressStock'], $courier['cargos'][0]['services']['delivery'] ) && ! isset( $courier['cargos'][0]['receiver']['warehouseId'] ), 'Courier must use addressStock and delivery service.' );
pek_shipment_create_assert( true === $courier['cargos'][0]['services']['delivery']['enabled'] && 1 === (int) $courier['cargos'][0]['services']['delivery']['payer']['type'], 'Courier delivery must be enabled with payer.type=1.' );
pek_shipment_create_assert( isset( $response['documentId'], $response['cargos'][0]['cargoCode'], $response['cargos'][0]['positions'][0]['barcode'] ), 'Create response fixture must use preregistration identifiers.' );

$settings = new PekSettings( new SettingsRepository(), new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() );
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
pek_shipment_create_assert( 1.25 === $common['weight'] && 0.2 === $place['length'] && 0.2 === $place['width'] && 0.2 === $place['height'], '1241 g and 20 cm measures must serialize to exact PEK hundredths.' );
pek_shipment_create_assert( 0.01 === $common['volume'], '0.2 x 0.2 x 0.2 aggregate volume must be 0.01.' );

$measure_request = new ShipmentCreateRequest(
	1,
	PekSettings::CARRIER_KEY,
	DeliveryType::PICKUP,
	'pek:pickup',
	new Address( country_code: 'RU', city: 'Москва', raw_address: 'Москва' ),
	null,
	array(
		new ShipmentPlace( 1, 70, 7, 14, 28, Money::from_kopecks( 0 ), array(), false, 'Товары интернет-магазина' ),
		new ShipmentPlace( 2, 140, 55, 56, 7, Money::from_kopecks( 0 ), array(), false, 'Товары интернет-магазина' ),
		new ShipmentPlace( 3, 550, 14, 14, 14, Money::from_kopecks( 0 ), array(), false, 'Товары интернет-магазина' ),
		new ShipmentPlace( 4, 560, 28, 28, 7, Money::from_kopecks( 0 ), array(), false, 'Товары интернет-магазина' ),
		new ShipmentPlace( 5, 1090, 100, 100, 100, Money::from_kopecks( 0 ), array(), false, 'Товары интернет-магазина' ),
	),
	Money::from_kopecks( 0 ),
	true,
	array(),
	array(),
	array( 'order_num' => '1001', 'pek_correlation' => 'wdc-pek-1' )
);
$measures = $builder->build( $measure_request, 150000 )['payload']['common'];
pek_shipment_create_assert( array( 0.07, 0.14, 0.55, 0.56, 1.09 ) === array_column( $measures['cargoPlaceList'], 'weight' ), 'Weights must be integer-scaled upward to hundredths of kg.' );
pek_shipment_create_assert( array( 0.07, 0.55, 0.14, 0.28, 1 ) === array_column( $measures['cargoPlaceList'], 'length' ), 'Dimensions must map integer cm directly to hundredths of meters.' );
pek_shipment_create_assert( 2.41 === $measures['weight'], 'Aggregate weight must sum transmitted hundredths exactly.' );

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
$surcharge_policy = new PekLightCargoSurchargePolicy( $settings );
$money_zero = Money::from_kopecks( 0 );
$package_2999 = new Package( array(), $money_zero, $money_zero, 2999, 999, 3998, 20, 20, 20, 8000, 'cart' );
$package_3000 = new Package( array(), $money_zero, $money_zero, 3000, 999, 3999, 20, 20, 20, 8000, 'cart' );
$request_2999 = new ShipmentCreateRequest( 1, PekSettings::CARRIER_KEY, DeliveryType::PICKUP, 'pek:pickup', new Address( country_code: 'RU', city: 'Москва', raw_address: 'Москва' ), null, array( new ShipmentPlace( 1, 3998, 20, 20, 20, Money::from_kopecks( 0 ), array() ) ), Money::from_kopecks( 0 ), meta: array( 'calculation_data' => array( 'package' => array( 'products_weight_g' => 2999 ) ) ) );
$request_3000 = new ShipmentCreateRequest( 1, PekSettings::CARRIER_KEY, DeliveryType::PICKUP, 'pek:pickup', new Address( country_code: 'RU', city: 'Москва', raw_address: 'Москва' ), null, array( new ShipmentPlace( 1, 3999, 20, 20, 20, Money::from_kopecks( 0 ), array() ) ), Money::from_kopecks( 0 ), meta: array( 'calculation_data' => array( 'package' => array( 'products_weight_g' => 3000 ) ) ) );
pek_shipment_create_assert( $surcharge_policy->evaluate( $package_2999 )->eligible && $weight_resolver->sealing_required( $request_2999 ), '2999g product weight must make checkout light-cargo surcharge eligible and shipment sealing required.' );
pek_shipment_create_assert( ! $surcharge_policy->evaluate( $package_3000 )->eligible && ! $weight_resolver->sealing_required( $request_3000 ), '3000g product weight must disable checkout light-cargo surcharge eligibility and shipment sealing.' );

$receiver_order = new class {
	public function get_shipping_phone(): string { return '+79139134904'; }
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
$receiver = ( new PekShipmentRecipientBuilder( new PekShipmentCourierAddressResolver(), new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() ) )->build_physical_recipient( $receiver_order, $request, 'receiver-warehouse-guid' );
pek_shipment_create_assert( 'Петров Петр' === $receiver['title'] && 'Петров Петр' === $receiver['person'] && '+79139134904' === $receiver['personPhones'][0]['phone'], 'Receiver builder must produce title/person and one documented +7 phone.' );
pek_shipment_create_assert( 3 === (int) ( $receiver['legalForm'] ?? 0 ), 'Receiver builder must send physical legalForm=3.' );
pek_shipment_create_assert( 'Петр' === (string) ( $receiver['individual']['firstName'] ?? '' ) && 'Петров' === (string) ( $receiver['individual']['lastName'] ?? '' ), 'Receiver builder must send firstName and lastName inside individual.' );
pek_shipment_create_assert( 1 === count( is_array( $receiver['personPhones'] ?? null ) ? $receiver['personPhones'] : array() ), 'Receiver builder must send exactly one personPhones row.' );
pek_shipment_create_assert( 'receiver-warehouse-guid' === (string) ( $receiver['warehouseId'] ?? '' ), 'Pickup receiver builder must send selected receiver warehouseId.' );
pek_shipment_create_assert( ! isset( $receiver['phone'], $receiver['mobile'] ), 'Receiver builder must not duplicate receiver phone in scalar aliases.' );
pek_shipment_create_assert( ! isset( $receiver['identityCard'] ), 'SMS release receiver must not send identityCard.' );

$parser = new PekShipmentCreateResponseParser();
$parsed = $parser->parse( $response );
pek_shipment_create_assert( '136' === $parsed['document_id'] && '999940950644' === $parsed['cargo_code'], 'Create response parser must parse official preregistration identifiers.' );
foreach ( array(
	array( 'documentId' => array(), 'cargos' => array( array( 'cargoCode' => '1' ) ) ),
	array( 'documentId' => false, 'cargos' => array( array( 'cargoCode' => '1' ) ) ),
	array( 'documentId' => 0, 'cargos' => array( array( 'cargoCode' => '1' ) ) ),
	array( 'documentId' => 1, 'cargos' => array( 'cargoCode' => '1' ) ),
	array( 'documentId' => 1, 'cargos' => array( array( 'cargoCode' => array() ) ) ),
	array( 'documentId' => 1, 'cargos' => array( array( 'cargoCode' => true ) ) ),
	array( 'documentId' => 1, 'cargos' => array( array( 'cargoCode' => '1', 'positions' => 'bad' ) ) ),
	array( 'documentId' => 1, 'cargos' => array( array( 'cargoCode' => '1', 'positions' => array( array( 'barcode' => array() ) ) ) ) ),
) as $bad_response ) {
	try {
		$parser->parse( $bad_response );
		pek_shipment_create_assert( false, 'Malformed create response must fail parser.' );
	} catch ( RuntimeException ) {
		pek_shipment_create_assert( true, 'Malformed create response fails parser.' );
	}
}

$adapter_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentAdapter.php' ) ?: '';
$status_source = file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Pek/PekShipmentStatusService.php' ) ?: '';
/** @var PekShipmentAdapter $adapter_without_constructor */
$adapter_without_constructor = ( new ReflectionClass( PekShipmentAdapter::class ) )->newInstanceWithoutConstructor();
$missing_order_result = $adapter_without_constructor->create( new ShipmentCreateRequest( 999999, PekSettings::CARRIER_KEY, DeliveryType::PICKUP, 'pek:pickup', new Address( country_code: 'RU', city: 'Москва', raw_address: 'Москва' ), null, array( new ShipmentPlace( 1, 1000, 20, 20, 20, Money::from_kopecks( 0 ) ) ), Money::from_kopecks( 0 ) ) );
pek_shipment_create_assert( $missing_order_result instanceof ShipmentCreateResult && ! $missing_order_result->success && 'pek_order_not_found' === $missing_order_result->error_code, 'Direct adapter create must return failed result when Woo order is missing, not fatal.' );
pek_shipment_create_assert( str_contains( $adapter_source, "'method' => 'POST'" ) && str_contains( $adapter_source, "'path' => '/preregistration/submit/'" ) && str_contains( $adapter_source, "'body' => \$built['preview']" ), 'Preview must return canonical framework envelope.' );
pek_shipment_create_assert( str_contains( $adapter_source, '$submitted' ) && str_contains( $adapter_source, "error_code: 'pek_uncertain_submit'" ) && str_contains( $adapter_source, 'safe_summary' ), 'Post-submit parser failures must become uncertain with safe summary.' );
pek_shipment_create_assert( str_contains( $adapter_source, 'safe_create_failure_reference' ) && str_contains( $adapter_source, "'api_error_message'" ) && str_contains( $adapter_source, "'field_errors'" ) && str_contains( $adapter_source, "'response_shape'" ) && ! str_contains( $adapter_source, "'raw_response'" ), 'PEK create deterministic rejection must expose safe diagnostics without raw response.' );
pek_shipment_create_assert( ! str_contains( $adapter_source, 'order_stub' ), 'Adapter direct create must not call undefined order_stub.' );
pek_shipment_create_assert( strpos( $status_source, 'unset( $status[\'actual_cost_candidate\'] );' ) < strpos( $status_source, 'save_for_carrier' ), 'Status service must unset actual cost candidate before persistence.' );
pek_shipment_create_assert( PekShipmentAdapter::class !== '', 'PEK adapter class must be autoloadable.' );

echo "PEK shipment create smoke passed.\n";
