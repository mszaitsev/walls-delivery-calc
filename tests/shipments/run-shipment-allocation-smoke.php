<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationBuilder;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocation;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationItem;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationPlace;

function shipment_allocation_assert( bool $condition, string $message ): void {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function shipment_allocation_expect_exception( callable $callback, string $message_part, string $message ): void {
	try {
		$callback();
		throw new RuntimeException( $message );
	} catch ( InvalidArgumentException $exception ) {
		shipment_allocation_assert( str_contains( $exception->getMessage(), $message_part ), $message );
	}
}
function shipment_allocation_row( string $item_key, int $place_number, string $name, string $sku, int $amount, mixed $unit_kopecks, int $weight, mixed $assessed_kopecks = null ): array {
	return array(
		'item_key' => $item_key,
		'ordered_quantity' => $amount,
		'place_number' => $place_number,
		'name' => $name,
		'sku' => $sku,
		'amount' => $amount,
		'unit_price_kopecks' => $unit_kopecks,
		'assessed_unit_price_kopecks' => $assessed_kopecks ?? $unit_kopecks,
		'weight' => $weight,
	);
}

$places = array(
	new ShipmentPlace( 1, 1000, 20, 15, 10, Money::from_kopecks( 0 ) ),
	new ShipmentPlace( 2, 1200, 21, 16, 11, Money::from_kopecks( 0 ) ),
);
$rows = array(
	shipment_allocation_row( 'order-item-a', 1, 'Item A', 'A', 1, 10000, 300 ),
	shipment_allocation_row( 'order-item-a', 2, 'Item A', 'A', 1, 10000, 300 ),
	shipment_allocation_row( 'order-item-b', 2, 'Item B', 'B', 1, 20000, 400 ),
);
$allocation = ( new ShipmentAllocationBuilder() )->build( $rows, $places );
shipment_allocation_assert( 2 === count( $allocation->places ), 'Canonical source must keep two shipment places.' );
shipment_allocation_assert( 'order-item-a' === $allocation->places[0]->items[0]->source_item_id && 'order-item-a' === $allocation->places[1]->items[0]->identity['order_item_id'], 'Allocation identity must come from item_key, not SKU.' );
shipment_allocation_assert( 1 === $allocation->places[0]->items[0]->quantity && 1 === $allocation->places[1]->items[0]->quantity, 'Split quantity must remain allocated independently in each place.' );
shipment_allocation_assert( 10000 === $allocation->places[0]->items[0]->unit_price_kopecks, 'Canonical row unit price must be kept as kopecks.' );
shipment_allocation_assert( 10000 === $allocation->places[0]->items[0]->assessed_unit_price_kopecks, 'Absent assessed value must explicitly fall back to unit price before allocation.' );

$same_sku = ( new ShipmentAllocationBuilder() )->build( array(
	shipment_allocation_row( 'order-item-1', 1, 'Item A 1', 'SAME-SKU', 1, 10000, 300 ),
	shipment_allocation_row( 'order-item-2', 1, 'Item A 2', 'SAME-SKU', 1, 10000, 300 ),
) , array( $places[0] ) );
shipment_allocation_assert( 'SAME-SKU' === $same_sku->places[0]->items[0]->sku && 'SAME-SKU' === $same_sku->places[0]->items[1]->sku, 'Same-SKU fixture must use identical SKU values.' );
shipment_allocation_assert( $same_sku->places[0]->items[0]->source_item_id !== $same_sku->places[0]->items[1]->source_item_id && $same_sku->places[0]->items[0]->identity['order_item_id'] !== $same_sku->places[0]->items[1]->identity['order_item_id'], 'Same SKU with different source identity must remain distinct allocation rows.' );

$assessed = ( new ShipmentAllocationBuilder() )->build( array(
	shipment_allocation_row( 'assessed-item', 1, 'Assessed item', 'ASS', 1, 99500, 300, 120000 ),
), array( $places[0] ) );
shipment_allocation_assert( 99500 === $assessed->places[0]->items[0]->unit_price_kopecks && 120000 === $assessed->places[0]->items[0]->assessed_unit_price_kopecks, 'Builder must preserve different unit and assessed prices.' );

$manual_item = new ShipmentAllocationItem( 'order-item-priced', array( 'order_item_id' => 'order-item-priced' ), 'Priced item', 'PRICE', 1, 10000, 15000, 100 );
shipment_allocation_assert( 10000 === $manual_item->unit_price_kopecks && 15000 === $manual_item->assessed_unit_price_kopecks && array() === $manual_item->validate(), 'Neutral allocation item must support different unit and assessed prices.' );

foreach ( array( 0, '0', 100, '100' ) as $accepted_kopecks ) {
	$accepted = ( new ShipmentAllocationBuilder() )->build( array(
		shipment_allocation_row( 'accepted-' . (string) $accepted_kopecks, 1, 'Accepted', 'ACC', 1, $accepted_kopecks, 300, $accepted_kopecks ),
	), array( $places[0] ) );
	shipment_allocation_assert( (int) $accepted_kopecks === $accepted->places[0]->items[0]->unit_price_kopecks && (int) $accepted_kopecks === $accepted->places[0]->items[0]->assessed_unit_price_kopecks, 'Builder must accept integer kopecks and digit strings.' );
}

$invalid_kopecks = array( -1, '-1', 100.5, '100.5', '100,5', '1e3', '', null, true, false );
foreach ( $invalid_kopecks as $index => $invalid ) {
	shipment_allocation_expect_exception(
		static fn() => ( new ShipmentAllocationBuilder() )->build( array(
			shipment_allocation_row( 'bad-unit-' . (string) $index, 1, 'Bad unit', 'BAD-U', 1, $invalid, 300, 100 ),
		), array( $places[0] ) ),
		'unit_price_kopecks must be a non-negative integer',
		'Builder must reject invalid unit_price_kopecks without silent cast.'
	);
	shipment_allocation_expect_exception(
		static function () use ( $index, $invalid, $places ): void {
			$row = shipment_allocation_row( 'bad-assessed-' . (string) $index, 1, 'Bad assessed', 'BAD-A', 1, 100, 300, 100 );
			$row['assessed_unit_price_kopecks'] = $invalid;
			( new ShipmentAllocationBuilder() )->build( array( $row ), array( $places[0] ) );
		},
		'assessed_unit_price_kopecks must be a non-negative integer',
		'Builder must reject invalid assessed_unit_price_kopecks without silent cast.'
	);
}
shipment_allocation_expect_exception(
	static fn() => ( new ShipmentAllocationBuilder() )->build( array(
		shipment_allocation_row( 'decimal-cast', 1, 'Decimal cast', 'DEC', 1, '100.9', 300, 100 ),
	), array( $places[0] ) ),
	'unit_price_kopecks must be a non-negative integer',
	'Decimal kopecks must not be converted to integer 100.'
);

$empty_allocation = new ShipmentAllocation( array() );
shipment_allocation_assert( in_array( 'places must not be empty', $empty_allocation->validate(), true ) && in_array( 'allocation must contain at least one item', $empty_allocation->validate(), true ), 'Allocation without places and items must be invalid.' );
$empty_place_allocation = new ShipmentAllocation( array( new ShipmentAllocationPlace( 1, 1000, 20, 15, 10, array() ) ) );
shipment_allocation_assert( in_array( 'shipment place must contain at least one item', $empty_place_allocation->validate(), true ) && in_array( 'allocation must contain at least one item', $empty_place_allocation->validate(), true ), 'Shipment place without items must be invalid.' );

shipment_allocation_expect_exception(
	static fn() => ( new ShipmentAllocationBuilder() )->build( array(), array( $places[0] ) ),
	'Shipment allocation rows must not be empty',
	'Builder must reject empty allocation rows.'
);
shipment_allocation_expect_exception(
	static fn() => ( new ShipmentAllocationBuilder() )->build( array( shipment_allocation_row( 'only-place-1', 1, 'Only place 1', 'P1', 1, 10000, 300 ) ), $places ),
	'Shipment place 2 must contain at least one allocation row',
	'Builder must reject partially empty places.'
);
shipment_allocation_expect_exception(
	static fn() => ( new ShipmentAllocationBuilder() )->build( array( shipment_allocation_row( 'bad', 99, 'Bad', 'BAD', 1, 10000, 300 ) ), array( $places[0] ) ),
	'unknown shipment place',
	'Unknown place must be reported instead of silently remapped.'
);
shipment_allocation_expect_exception(
	static fn() => ( new ShipmentAllocationBuilder() )->build( array( shipment_allocation_row( 'bad', 1, 'Bad', 'BAD', 0, 10000, 300 ) ), array( $places[0] ) ),
	'amount must be greater than 0',
	'Zero amount must be reported instead of becoming quantity 1.'
);

echo "Shipment allocation smoke test passed.\n";
