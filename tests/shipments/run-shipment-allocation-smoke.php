<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationItem;
use WallsShop\WDC\Shipments\Cdek\CdekShipmentAllocationAdapter;

function shipment_allocation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}

$places = array(
	new ShipmentPlace( 1, 1000, 20, 15, 10, Money::from_kopecks( 0 ) ),
	new ShipmentPlace( 2, 1200, 21, 16, 11, Money::from_kopecks( 0 ) ),
);
$rows = array(
	array( 'item_key' => 'order-item-a', 'place_number' => 1, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'order-item-a', 'place_number' => 2, 'name' => 'Item A', 'ware_key' => 'A', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'order-item-b', 'place_number' => 2, 'name' => 'Item B', 'ware_key' => 'B', 'amount' => 1, 'cost' => 200, 'weight' => 400 ),
);
$allocation = ( new CdekShipmentAllocationAdapter() )->from_cdek_rows( $places, $rows );
shipment_allocation_assert( 2 === count( $allocation->places ), 'CDEK source must keep two shipment places.' );
shipment_allocation_assert( 'order-item-a' === $allocation->places[0]->items[0]->source_item_id && 'order-item-a' === $allocation->places[1]->items[0]->identity['order_item_id'], 'Allocation identity must come from CDEK item_key, not SKU.' );
shipment_allocation_assert( 1 === $allocation->places[0]->items[0]->quantity && 1 === $allocation->places[1]->items[0]->quantity, 'Split quantity must remain allocated independently in each place.' );
shipment_allocation_assert( 10000 === $allocation->places[0]->items[0]->unit_price_kopecks, 'CDEK row unit cost must be adapted to kopecks.' );
shipment_allocation_assert( 10000 === $allocation->places[0]->items[0]->assessed_unit_price_kopecks, 'CDEK adapter must fill assessed price from the same current CDEK cost without changing CDEK behavior.' );

$same_sku = ( new CdekShipmentAllocationAdapter() )->from_cdek_rows( array( $places[0] ), array(
	array( 'item_key' => 'order-item-1', 'place_number' => 1, 'name' => 'Item A 1', 'ware_key' => 'SAME-SKU', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
	array( 'item_key' => 'order-item-2', 'place_number' => 1, 'name' => 'Item A 2', 'ware_key' => 'SAME-SKU', 'amount' => 1, 'cost' => 100, 'weight' => 300 ),
) );
shipment_allocation_assert( 'SAME-SKU' === $same_sku->places[0]->items[0]->sku && 'SAME-SKU' === $same_sku->places[0]->items[1]->sku, 'Same-SKU fixture must use identical SKU values.' );
shipment_allocation_assert( $same_sku->places[0]->items[0]->source_item_id !== $same_sku->places[0]->items[1]->source_item_id && $same_sku->places[0]->items[0]->identity['order_item_id'] !== $same_sku->places[0]->items[1]->identity['order_item_id'], 'Same SKU with different source identity must remain distinct allocation rows.' );

$manual_item = new ShipmentAllocationItem( 'order-item-priced', array( 'order_item_id' => 'order-item-priced' ), 'Priced item', 'PRICE', 1, 10000, 15000, 100 );
shipment_allocation_assert( 10000 === $manual_item->unit_price_kopecks && 15000 === $manual_item->assessed_unit_price_kopecks && array() === $manual_item->validate(), 'Neutral allocation item must support different unit and assessed prices.' );

try {
	( new CdekShipmentAllocationAdapter() )->from_cdek_rows( array( $places[0] ), array( array( 'item_key' => 'bad', 'place_number' => 99, 'name' => 'Bad', 'ware_key' => 'BAD', 'amount' => 1, 'cost' => 100, 'weight' => 300 ) ) );
	throw new RuntimeException( 'Invalid allocation row with unknown place must fail.' );
} catch ( InvalidArgumentException $exception ) {
	shipment_allocation_assert( str_contains( $exception->getMessage(), 'unknown shipment place' ), 'Unknown place must be reported instead of silently remapped.' );
}
try {
	( new CdekShipmentAllocationAdapter() )->from_cdek_rows( array( $places[0] ), array( array( 'item_key' => 'bad', 'place_number' => 1, 'name' => 'Bad', 'ware_key' => 'BAD', 'amount' => 0, 'cost' => 100, 'weight' => 300 ) ) );
	throw new RuntimeException( 'Invalid allocation row with zero amount must fail.' );
} catch ( InvalidArgumentException $exception ) {
	shipment_allocation_assert( str_contains( $exception->getMessage(), 'amount must be greater than 0' ), 'Zero amount must be reported instead of becoming quantity 1.' );
}

echo "Shipment allocation smoke test passed.\n";
