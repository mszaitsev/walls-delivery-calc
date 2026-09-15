<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Common\MoneyParser;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Domain\Pickup\PickupPointSelection;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$money = Money::from_rubles( '123.45' );
smoke_assert( 12345 === $money->get_kopecks(), 'Money kopecks must round-trip from rubles.' );
smoke_assert( array() === $money->validate(), 'Money must validate.' );
smoke_assert( Money::from_array( $money->to_array() )->get_kopecks() === $money->get_kopecks(), 'Money array round-trip failed.' );
smoke_assert( 123450 === MoneyParser::rubles_to_kopecks( '1234,5' ), 'MoneyParser must parse comma rubles exactly.' );
smoke_assert( 123456 === MoneyParser::rubles_to_kopecks( '1 234.56' ), 'MoneyParser must ignore grouped spaces.' );
smoke_assert( 123457 === MoneyParser::rubles_to_kopecks( '1234.565' ), 'MoneyParser must round the third fractional digit.' );
smoke_assert( 29880 === MoneyParser::first_decimal_to_kopecks( '298.8 RUB' ), 'MoneyParser must extract Yandex-style currency strings.' );
smoke_assert( null === MoneyParser::numeric_to_kopecks( 'bad' ), 'MoneyParser numeric conversion must return null for invalid values.' );

$address = new Address(
	country_code: 'RU',
	country_name: 'Russia',
	region_name: 'Moscow',
	city: 'Moscow',
	postcode: '101000',
	street: 'Tverskaya',
	house: '1',
	raw_address: 'Moscow, Tverskaya 1',
	normalized: true
);
smoke_assert( $address->has_city(), 'Address must have city.' );
smoke_assert( $address->has_postcode(), 'Address must have postcode.' );
smoke_assert( $address->has_full_courier_address(), 'Address must be courier-full.' );
smoke_assert( Address::from_array( $address->to_array() )->city === 'Moscow', 'Address array round-trip failed.' );

$item = new PackageItem(
	sku: 'SKU-1',
	name: 'Wallpaper roll',
	quantity: 2,
	unit_price: Money::from_rubles( 500 ),
	total_price: Money::from_rubles( 1000 ),
	weight_g: 700,
	length_cm: 10,
	width_cm: 10,
	height_cm: 50
);
smoke_assert( 1400 === $item->get_total_weight_g(), 'PackageItem total weight failed.' );

$package = Package::from_items( array( $item ), 100, Money::from_rubles( 1000 ), Money::from_rubles( 900 ) );
smoke_assert( 1500 === $package->get_total_weight_g(), 'Package total weight failed.' );
smoke_assert( 2 === $package->get_total_quantity(), 'Package total quantity failed.' );
smoke_assert( Package::from_array( $package->to_array() )->get_total_weight_g() === 1500, 'Package array round-trip failed.' );

$rate = new DeliveryRate(
	rate_id: 'rate-1',
	carrier_key: 'manual',
	carrier_name: 'Manual Carrier',
	service_key: 'standard',
	service_name: 'Standard',
	tariff_key: 'base',
	tariff_name: 'Base',
	delivery_type: DeliveryType::PICKUP,
	title: 'Pickup delivery',
	price: Money::from_rubles( 300 ),
	original_price: Money::from_rubles( 400 ),
	crossed_price: null,
	delivery_days: DateRange::range( 2, 4 ),
	requires_pickup_point: true
);
smoke_assert( $rate->is_available(), 'DeliveryRate must be available.' );
smoke_assert( $rate->has_discount(), 'DeliveryRate discount detection failed.' );
smoke_assert( DeliveryRate::from_array( $rate->to_array() )->price->get_kopecks() === 30000, 'DeliveryRate array round-trip failed.' );

$quote = new DeliveryQuote(
	quote_id: 'quote-1',
	carrier_key: 'manual',
	destination: $address,
	package: $package,
	rates: array( $rate ),
	source: 'manual'
);
smoke_assert( $quote->has_available_rates(), 'DeliveryQuote must have available rates.' );
smoke_assert( 1 === count( DeliveryQuote::from_array( $quote->to_array() )->get_available_rates() ), 'DeliveryQuote array round-trip failed.' );

$pickup_point = new PickupPoint(
	carrier_key: 'manual',
	code: 'PVZ-1',
	address: 'Moscow, Tverskaya 1',
	city: 'Moscow',
	latitude: 55.7558,
	longitude: 37.6173,
	type: 'pvz',
	extra_cost: Money::from_rubles( 50 )
);
smoke_assert( $pickup_point->has_coordinates(), 'PickupPoint coordinates detection failed.' );
smoke_assert( $pickup_point->has_extra_cost(), 'PickupPoint extra cost detection failed.' );
smoke_assert( PickupPoint::from_array( $pickup_point->to_array() )->code === 'PVZ-1', 'PickupPoint array round-trip failed.' );

$place = new ShipmentPlace(
	place_number: 1,
	weight_g: 1500,
	length_cm: 10,
	width_cm: 10,
	height_cm: 50,
	declared_value: Money::from_rubles( 900 ),
	items: array( $item )
);
smoke_assert( 5000 === $place->get_volume_cm3(), 'ShipmentPlace volume failed.' );
smoke_assert( array() === $place->validate(), 'ShipmentPlace must validate.' );

$selection = new PickupPointSelection(
	carrier_key: 'manual',
	rate_id: 'rate-1',
	point_code: 'PVZ-1',
	point_address: 'Moscow, Tverskaya 1',
	selected_at: '2026-05-20T12:00:00+00:00'
);

$shipment_request = new ShipmentCreateRequest(
	order_id: 123,
	carrier_key: 'manual',
	delivery_type: DeliveryType::PICKUP,
	rate_id: 'rate-1',
	recipient_address: $address,
	pickup_point: $selection,
	places: array( $place ),
	declared_value: Money::from_rubles( 900 ),
	recipient: array( 'name' => 'Test Customer' )
);
smoke_assert( ShipmentCreateRequest::from_array( $shipment_request->to_array() )->order_id === 123, 'ShipmentCreateRequest array round-trip failed.' );
smoke_assert( array() === $shipment_request->validate(), 'ShipmentCreateRequest must validate.' );

echo "Domain smoke test passed.\n";
