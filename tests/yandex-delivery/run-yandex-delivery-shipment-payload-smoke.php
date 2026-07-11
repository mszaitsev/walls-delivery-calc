<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentPayloadBuilder;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentAllocationAdapter;

function yandex_shipment_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
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
$pickup = array( 'mode' => 'pickup', 'platform_station_id' => 'PVZ-1' );
$one = yandex_shipment_payload( array( new ShipmentPlace( 1, 1000, 20, 15, 10, Money::from_kopecks( 0 ) ) ), array(
	array( 'item_key' => 'A', 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 2, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'B', 'place_number' => 1, 'name' => 'Item B', 'ware_key' => 'B', 'amount' => 1, 'cost' => 200, 'weight' => 400 ),
), $pickup );
yandex_shipment_assert( 1 === count( $one['places'] ) && 2 === count( $one['items'] ) && 2 === $one['items'][0]['count'] && 3 === array_sum( array_column( $one['items'], 'count' ) ), 'One-place allocation must preserve two item rows and quantities.' );
yandex_shipment_assert( 'ORDER-123-1' === $one['places'][0]['barcode'] && 'ORDER-123-1' === $one['items'][0]['place_barcode'] && 'platform_station' === $one['destination']['type'] && 'self_pickup' === $one['last_mile_policy'], 'Pickup payload must use deterministic place barcode and pickup destination.' );
yandex_shipment_assert( 'Михайлов Михаил' === $one['recipient_info']['first_name'] && '' === $one['recipient_info']['last_name'] && '+79131234567' === $one['recipient_info']['phone'], 'Recipient must use surname and first name in first_name with normalized phone.' );
yandex_shipment_assert( true === $one['forbid_unboxing'] && 'already_paid' === $one['billing_info']['payment_method'] && 0 === $one['billing_info']['delivery_cost'] && -1 === $one['items'][0]['nds'], 'Yandex defaults must keep prepaid, forbid unboxing and nds -1.' );

$two_places = array( new ShipmentPlace( 1, 1000, 20, 15, 10, Money::from_kopecks( 0 ) ), new ShipmentPlace( 2, 1200, 21, 16, 11, Money::from_kopecks( 0 ) ) );
$split = yandex_shipment_payload( $two_places, array(
	array( 'item_key' => 'A', 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'A', 'place_number' => 2, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'B', 'place_number' => 2, 'name' => 'Item B', 'ware_key' => 'B', 'amount' => 1, 'cost' => 200, 'weight' => 400 ),
), array( 'mode' => 'courier', 'details' => array( 'country' => 'Россия', 'region' => 'Москва', 'locality' => 'Москва', 'street' => 'Ходынский бульвар', 'house' => '9', 'room' => '15', 'full_address' => '125252, Москва, Ходынский бульвар, 9, кв. 15', 'postal_code' => '125252' ) ) );
yandex_shipment_assert( 2 === count( $split['places'] ) && 'ORDER-123-1' !== $split['places'][1]['barcode'], 'Two places must have unique temporary barcodes.' );
yandex_shipment_assert( 3 === count( $split['items'] ) && 2 === array_sum( array_map( static fn( array $item ): int => 'A' === $item['article'] ? $item['count'] : 0, $split['items'] ) ) && $split['items'][0]['place_barcode'] !== $split['items'][1]['place_barcode'], 'Split item must never be merged across places.' );
yandex_shipment_assert( 'custom_location' === $split['destination']['type'] && 'Ходынский бульвар' === $split['destination']['custom_location']['details']['street'] && 'time_interval' === $split['last_mile_policy'], 'Courier payload must retain structured address without coordinates.' );

$same_place = yandex_shipment_payload( array( new ShipmentPlace( 1, 1000, 20, 15, 10, Money::from_kopecks( 0 ) ) ), array(
	array( 'item_key' => 'A', 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'A', 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
), $pickup );
yandex_shipment_assert( 1 === count( $same_place['items'] ) && 2 === $same_place['items'][0]['count'], 'Same-place duplicate allocation rows may aggregate only inside that barcode.' );
$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/YandexDelivery/Shipment/YandexDeliveryShipmentPayloadBuilder.php' );
yandex_shipment_assert( ! str_contains( $source, 'wp_remote_request' ) && ! str_contains( $source, 'curl' ) && ! str_contains( $source, 'HttpClient' ), 'Yandex shipment payload builder must not use HTTP.' );

echo "Yandex delivery shipment payload smoke test passed.\n";
