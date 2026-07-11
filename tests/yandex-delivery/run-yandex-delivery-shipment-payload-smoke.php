<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentPayloadBuilder;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocation;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationItem;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationPlace;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentAllocationAdapter;

function yandex_shipment_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function yandex_shipment_expect_exception( callable $callback, string $message_part, string $message ): void {
	try {
		$callback();
		throw new RuntimeException( $message );
	} catch ( InvalidArgumentException $exception ) {
		yandex_shipment_assert( str_contains( $exception->getMessage(), $message_part ), $message );
	}
}
function yandex_shipment_payload( array $places, array $rows, array $destination ): array {
	$allocation = ( new CdekShipmentAllocationAdapter() )->from_cdek_rows( $places, $rows );
	return ( new YandexDeliveryShipmentPayloadBuilder() )->build( $allocation, array(
		'operator_request_id' => 'ORDER-123', 'source_platform_station_id' => 'SOURCE-1',
		'ready_from' => new DateTimeImmutable( '2026-07-12 12:00:00+07:00' ), 'ready_to' => new DateTimeImmutable( '2026-07-12 13:00:00+07:00' ),
		'recipient' => array( 'first_name' => 'Михаил', 'last_name' => 'Михайлов', 'phone' => '8 (913) 123-45-67', 'email' => 'buyer@example.test' ),
		'destination' => $destination,
	) );
}
function yandex_context_payload( ShipmentAllocation $allocation, array $overrides = array() ): array {
	$context = array_merge( array(
		'operator_request_id' => 'ORDER-123',
		'source_platform_station_id' => 'SOURCE-1',
		'ready_from' => new DateTimeImmutable( '2026-07-12 12:00:00+07:00' ),
		'ready_to' => new DateTimeImmutable( '2026-07-12 13:00:00+07:00' ),
		'recipient' => array( 'first_name' => 'Михаил', 'last_name' => 'Михайлов', 'phone' => '8 (913) 123-45-67', 'email' => 'buyer@example.test' ),
		'destination' => array( 'mode' => 'pickup', 'platform_station_id' => 'PVZ-1' ),
	), $overrides );

	return ( new YandexDeliveryShipmentPayloadBuilder() )->build( $allocation, $context );
}
$pickup = array( 'mode' => 'pickup', 'platform_station_id' => 'PVZ-1' );
$one = yandex_shipment_payload( array( new ShipmentPlace( 1, 1000, 20, 15, 10, Money::from_kopecks( 0 ) ) ), array(
	array( 'item_key' => 'A', 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 2, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'B', 'place_number' => 1, 'name' => 'Item B', 'ware_key' => 'B', 'amount' => 1, 'cost' => 200, 'weight' => 400 ),
), $pickup );
yandex_shipment_assert( 1 === count( $one['places'] ) && 2 === count( $one['items'] ) && 2 === $one['items'][0]['count'] && 3 === array_sum( array_column( $one['items'], 'count' ) ), 'One-place allocation must preserve two item rows and quantities.' );
yandex_shipment_assert( 'ORDER-123-1' === $one['places'][0]['barcode'] && 'ORDER-123-1' === $one['items'][0]['place_barcode'] && 'platform_station' === $one['destination']['type'] && 'self_pickup' === $one['last_mile_policy'], 'Pickup payload must use deterministic place barcode and pickup destination.' );
yandex_shipment_assert( 'Михайлов Михаил' === $one['recipient_info']['first_name'] && '' === $one['recipient_info']['last_name'] && '+79131234567' === $one['recipient_info']['phone'], 'Recipient must use surname and first name in first_name with normalized phone.' );
yandex_shipment_assert( isset( $one['items'][0]['billing_details'] ) && ! array_key_exists( 'unit_price', $one['items'][0] ) && ! array_key_exists( 'nds', $one['items'][0] ), 'Yandex item billing values must live inside billing_details.' );
yandex_shipment_assert( true === $one['forbid_unboxing'] && 'already_paid' === $one['billing_info']['payment_method'] && 0 === $one['billing_info']['delivery_cost'] && -1 === $one['items'][0]['billing_details']['nds'], 'Yandex defaults must keep prepaid, forbid unboxing and nds -1.' );
yandex_shipment_assert( '2026-07-12T05:00:00.000000Z' === $one['source']['interval_utc']['from'] && '2026-07-12T06:00:00.000000Z' === $one['source']['interval_utc']['to'], 'Ready interval must use production UTC format with microseconds and Z suffix.' );

$two_places = array( new ShipmentPlace( 1, 1000, 20, 15, 10, Money::from_kopecks( 0 ) ), new ShipmentPlace( 2, 1200, 21, 16, 11, Money::from_kopecks( 0 ) ) );
$split = yandex_shipment_payload( $two_places, array(
	array( 'item_key' => 'A', 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'A', 'place_number' => 2, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'B', 'place_number' => 2, 'name' => 'Item B', 'ware_key' => 'B', 'amount' => 1, 'cost' => 200, 'weight' => 400 ),
), array( 'mode' => 'courier', 'details' => array( 'country' => 'Россия', 'region' => 'Москва', 'locality' => 'Москва', 'street' => 'Ходынский бульвар', 'house' => '9', 'room' => '15', 'full_address' => '125252, Москва, Ходынский бульвар, 9, кв. 15', 'postal_code' => '125252' ) ) );
yandex_shipment_assert( 2 === count( $split['places'] ) && 'ORDER-123-1' !== $split['places'][1]['barcode'], 'Two places must have unique temporary barcodes.' );
yandex_shipment_assert( 3 === count( $split['items'] ) && 2 === array_sum( array_map( static fn( array $item ): int => 'A' === $item['article'] ? $item['count'] : 0, $split['items'] ) ) && $split['items'][0]['place_barcode'] !== $split['items'][1]['place_barcode'], 'Split item must never be merged across places.' );
yandex_shipment_assert( 'custom_location' === $split['destination']['type'] && 'Ходынский бульвар' === $split['destination']['custom_location']['details']['street'] && 'time_interval' === $split['last_mile_policy'], 'Courier payload must retain structured address without coordinates.' );
yandex_shipment_assert( ! array_key_exists( 'latitude', $split['destination']['custom_location']['details'] ) && ! array_key_exists( 'longitude', $split['destination']['custom_location']['details'] ), 'Courier structured address must not require coordinates.' );

$same_place = yandex_shipment_payload( array( new ShipmentPlace( 1, 1000, 20, 15, 10, Money::from_kopecks( 0 ) ) ), array(
	array( 'item_key' => 'A', 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'A', 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 2, 'cost' => 100, 'weight' => 300 ),
), $pickup );
yandex_shipment_assert( 1 === count( $same_place['items'] ) && 3 === $same_place['items'][0]['count'], 'Same-place duplicate allocation rows may aggregate only inside that barcode.' );

$same_identity_across_places = yandex_shipment_payload( $two_places, array(
	array( 'item_key' => 'A', 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'A', 'place_number' => 2, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 2, 'cost' => 100, 'weight' => 300 ),
), $pickup );
yandex_shipment_assert( 2 === count( $same_identity_across_places['items'] ) && $same_identity_across_places['items'][0]['place_barcode'] !== $same_identity_across_places['items'][1]['place_barcode'], 'Same item across different places must remain separate Yandex rows.' );

$same_sku_payload = yandex_shipment_payload( array( new ShipmentPlace( 1, 1000, 20, 15, 10, Money::from_kopecks( 0 ) ) ), array(
	array( 'item_key' => 'order-item-1', 'place_number' => 1, 'name' => 'Item 1', 'ware_key' => 'SAME-SKU', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'order-item-2', 'place_number' => 1, 'name' => 'Item 2', 'ware_key' => 'SAME-SKU', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
), $pickup );
yandex_shipment_assert( 2 === count( $same_sku_payload['items'] ) && 'SAME-SKU' === $same_sku_payload['items'][0]['article'] && 'SAME-SKU' === $same_sku_payload['items'][1]['article'], 'Different source identities with the same SKU must not be merged.' );

$priced_payload = ( new YandexDeliveryShipmentPayloadBuilder() )->build( new ShipmentAllocation( array( new ShipmentAllocationPlace( 1, 1000, 20, 15, 10, array( new ShipmentAllocationItem( 'priced', array( 'order_item_id' => 'priced' ), 'Priced item', 'PRICE', 1, 10000, 15000, 300 ) ) ) ) ), array(
	'operator_request_id' => 'ORDER-123', 'source_platform_station_id' => 'SOURCE-1',
	'ready_from' => new DateTimeImmutable( '2026-07-12 12:00:00+07:00' ), 'ready_to' => new DateTimeImmutable( '2026-07-12 13:00:00+07:00' ),
	'recipient' => array( 'first_name' => 'Михаил', 'last_name' => 'Михайлов', 'phone' => '9131234567', 'email' => 'buyer@example.test' ),
	'destination' => $pickup,
) );
yandex_shipment_assert( 10000 === $priced_payload['items'][0]['billing_details']['unit_price'] && 15000 === $priced_payload['items'][0]['billing_details']['assessed_unit_price'], 'Yandex billing_details must preserve different unit and assessed prices.' );

$builder = new YandexDeliveryShipmentPayloadBuilder();
$recipient_base = array(
	'operator_request_id' => 'ORDER-123', 'source_platform_station_id' => 'SOURCE-1',
	'ready_from' => new DateTimeImmutable( '2026-07-12 12:00:00+07:00' ), 'ready_to' => new DateTimeImmutable( '2026-07-12 13:00:00+07:00' ),
	'destination' => $pickup,
);
$recipient_allocation = new ShipmentAllocation( array( new ShipmentAllocationPlace( 1, 1000, 20, 15, 10, array( new ShipmentAllocationItem( 'recipient', array( 'order_item_id' => 'recipient' ), 'Recipient item', 'R', 1, 100, 100, 100 ) ) ) ) );
$no_last_name = $builder->build( $recipient_allocation, array_merge( $recipient_base, array( 'recipient' => array( 'first_name' => ' Михаил ', 'last_name' => '', 'phone' => '9131234567', 'email' => 'buyer@example.test' ) ) ) );
$no_first_name = $builder->build( $recipient_allocation, array_merge( $recipient_base, array( 'recipient' => array( 'first_name' => '', 'last_name' => ' Михайлов ', 'phone' => '9131234567', 'email' => 'buyer@example.test' ) ) ) );
$double_spaces = $builder->build( $recipient_allocation, array_merge( $recipient_base, array( 'recipient' => array( 'first_name' => '  Михаил  ', 'last_name' => '  Михайлов  ', 'phone' => '9131234567', 'email' => 'buyer@example.test' ) ) ) );
yandex_shipment_assert( 'Михаил' === $no_last_name['recipient_info']['first_name'] && 'Михайлов' === $no_first_name['recipient_info']['first_name'] && 'Михайлов Михаил' === $double_spaces['recipient_info']['first_name'], 'Recipient edge cases must trim missing names and collapse double spaces.' );

yandex_shipment_expect_exception( static fn() => yandex_context_payload( $recipient_allocation, array( 'destination' => array() ) ), 'destination.mode must be pickup or courier', 'Missing destination mode must fail.' );
yandex_shipment_expect_exception( static fn() => yandex_context_payload( $recipient_allocation, array( 'destination' => array( 'mode' => 'unknown' ) ) ), 'destination.mode must be pickup or courier', 'Unknown destination mode must fail.' );
yandex_shipment_expect_exception( static fn() => yandex_context_payload( $recipient_allocation, array( 'destination' => array( 'mode' => 'self_pickup', 'platform_station_id' => 'PVZ-1' ) ) ), 'destination.mode must be pickup or courier', 'Carrier policy names must not be accepted as destination mode.' );
yandex_shipment_expect_exception( static fn() => yandex_context_payload( $recipient_allocation, array( 'destination' => array( 'mode' => 'pickup', 'platform_station_id' => ' ' ) ) ), 'destination.platform_station_id is required for pickup', 'Pickup destination must require platform station id.' );
$trimmed_pickup = yandex_context_payload( $recipient_allocation, array( 'destination' => array( 'mode' => 'pickup', 'platform_station_id' => ' PVZ-TRIM ' ) ) );
yandex_shipment_assert( 'PVZ-TRIM' === $trimmed_pickup['destination']['platform_station']['platform_id'], 'Pickup platform station id must be trimmed.' );

$valid_courier_details = array( 'country' => ' Россия ', 'region' => ' Москва ', 'locality' => ' Москва ', 'street' => ' Ходынский бульвар ', 'house' => ' 9 ', 'room' => ' 15 ', 'full_address' => ' 125252, Москва, Ходынский бульвар, 9, кв. 15 ', 'postal_code' => '', 'geoId' => 213, 'latitude' => 55.1 );
foreach ( array( 'locality', 'street', 'house', 'full_address' ) as $required_field ) {
	$broken_details = $valid_courier_details;
	$broken_details[ $required_field ] = ' ';
	yandex_shipment_expect_exception(
		static fn() => yandex_context_payload( $recipient_allocation, array( 'destination' => array( 'mode' => 'courier', 'details' => $broken_details ) ) ),
		'destination.details.' . $required_field . ' is required for courier',
		'Courier destination must require ' . $required_field . '.'
	);
}
$sanitized_courier = yandex_context_payload( $recipient_allocation, array( 'destination' => array( 'mode' => 'courier', 'details' => $valid_courier_details ) ) );
$sanitized_details = $sanitized_courier['destination']['custom_location']['details'];
yandex_shipment_assert( 'Москва' === $sanitized_details['locality'] && 'Ходынский бульвар' === $sanitized_details['street'] && '9' === $sanitized_details['house'] && '125252, Москва, Ходынский бульвар, 9, кв. 15' === $sanitized_details['full_address'], 'Courier details must be trimmed.' );
yandex_shipment_assert( isset( $sanitized_details['geoId'] ) && ! array_key_exists( 'latitude', $sanitized_details ) && ! array_key_exists( 'longitude', $sanitized_details ) && ! array_key_exists( 'postal_code', $sanitized_details ), 'Courier details must keep supported scalar fields and omit coordinates/empty optionals.' );

yandex_shipment_expect_exception( static fn() => yandex_context_payload( $recipient_allocation, array( 'recipient' => array( 'first_name' => ' ', 'last_name' => ' ', 'phone' => '9131234567', 'email' => '' ) ) ), 'recipient name is required', 'Empty recipient name must fail.' );
yandex_shipment_expect_exception( static fn() => yandex_context_payload( $recipient_allocation, array( 'recipient' => array( 'first_name' => 'Михаил', 'last_name' => '', 'phone' => '', 'email' => '' ) ) ), 'recipient phone is invalid', 'Empty recipient phone must fail.' );
yandex_shipment_expect_exception( static fn() => yandex_context_payload( $recipient_allocation, array( 'recipient' => array( 'first_name' => 'Михаил', 'last_name' => '', 'phone' => '123', 'email' => '' ) ) ), 'recipient phone is invalid', 'Short recipient phone must fail.' );
yandex_shipment_expect_exception( static fn() => yandex_context_payload( $recipient_allocation, array( 'recipient' => array( 'first_name' => 'Михаил', 'last_name' => '', 'phone' => 'phone', 'email' => '' ) ) ), 'recipient phone is invalid', 'Non-numeric recipient phone must fail.' );
$phone_from_eight = yandex_context_payload( $recipient_allocation, array( 'recipient' => array( 'first_name' => 'Михаил', 'last_name' => '', 'phone' => '8 (913) 123-45-67', 'email' => '' ) ) );
$phone_from_ten = yandex_context_payload( $recipient_allocation, array( 'recipient' => array( 'first_name' => 'Михаил', 'last_name' => '', 'phone' => '9131234567', 'email' => '' ) ) );
yandex_shipment_assert( '+79131234567' === $phone_from_eight['recipient_info']['phone'] && '+79131234567' === $phone_from_ten['recipient_info']['phone'] && '' === $phone_from_ten['recipient_info']['email'], 'Russian recipient phones must normalize and empty email must pass.' );
yandex_shipment_expect_exception( static fn() => yandex_context_payload( $recipient_allocation, array( 'recipient' => array( 'first_name' => 'Михаил', 'last_name' => '', 'phone' => '9131234567', 'email' => 'not-an-email' ) ) ), 'recipient email is invalid', 'Invalid recipient email must fail.' );

yandex_shipment_expect_exception( static fn() => yandex_context_payload( $recipient_allocation, array( 'ready_from' => new DateTimeImmutable( '2026-07-12 13:00:00+07:00' ), 'ready_to' => new DateTimeImmutable( '2026-07-12 12:00:00+07:00' ) ) ), 'ready_to must be greater than or equal to ready_from', 'ready_to earlier than ready_from must fail.' );
$equal_ready = yandex_context_payload( $recipient_allocation, array( 'ready_from' => new DateTimeImmutable( '2026-07-12 12:00:00.123456+07:00' ), 'ready_to' => new DateTimeImmutable( '2026-07-12 12:00:00.123456+07:00' ) ) );
yandex_shipment_assert( '2026-07-12T05:00:00.123456Z' === $equal_ready['source']['interval_utc']['from'] && $equal_ready['source']['interval_utc']['from'] === $equal_ready['source']['interval_utc']['to'], 'Equal ready interval must pass and preserve UTC microseconds with Z suffix.' );

yandex_shipment_expect_exception(
	static fn() => yandex_context_payload( new ShipmentAllocation( array( new ShipmentAllocationPlace( 1, 1000, 20, 15, 10, array() ) ) ) ),
	'shipment place must contain at least one item',
	'Yandex builder must reject allocation places without items.'
);
$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Shipment/YandexDeliveryShipmentPayloadBuilder.php' );
yandex_shipment_assert( ! str_contains( $source, 'wp_remote_request' ) && ! str_contains( $source, 'curl' ) && ! str_contains( $source, 'HttpClient' ), 'Yandex shipment payload builder must not use HTTP.' );

echo "Yandex delivery shipment payload smoke test passed.\n";
