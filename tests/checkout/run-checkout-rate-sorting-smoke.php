<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Quote\DeliveryRate;

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once ABSPATH . 'src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', ABSPATH . 'src' ) )->register();

function sorting_rate( string $id, int $price, ?int $min, ?int $max, string $group = '' ): DeliveryRate {
	return DeliveryRate::from_array( array(
		'rate_id' => $id,
		'tariff_key' => $id,
		'title' => $id,
		'price' => array( 'amount_kopecks' => $price * 100 ),
		'delivery_days' => array( 'min_days' => $min, 'max_days' => $max ),
		'meta' => array( 'tariff_selector_group' => '' !== $group, 'checkout_group_id' => $group ),
	) );
}

function sorting_expect( array $expected, array $actual, string $message ): void {
	$ids = array_map( static fn( DeliveryRate $rate ): string => $rate->rate_id, $actual );
	if ( $expected !== $ids ) {
		throw new RuntimeException( $message . ': ' . implode( ',', $ids ) );
	}
}

$sorter = new RateSorter();
$cases = array(
	'cheapest inner' => array( RateSorter::CHEAPEST, array( array( 500, 2, 3 ), array( 300, 5, 6 ), array( 300, 3, 7 ), array( 300, 3, 4 ), array( 0, 1, 1 ) ), array( 'D', 'C', 'B', 'A', 'E' ) ),
	'cheapest methods' => array( RateSorter::CHEAPEST, array( array( 300, 2, 5 ), array( 300, 2, 3 ), array( 300, 1, 8 ), array( 200, 10, 12 ), array( 0, 1, 1 ) ), array( 'D', 'C', 'B', 'A', 'E' ) ),
	'zero cohort' => array( RateSorter::CHEAPEST, array( array( 0, 3, 5 ), array( 0, 2, 7 ), array( 0, 2, 4 ) ), array( 'C', 'B', 'A' ) ),
	'fastest inner' => array( RateSorter::FASTEST, array( array( 500, 2, 5 ), array( 700, 2, 3 ), array( 300, 2, 3 ), array( 100, 3, 3 ) ), array( 'C', 'B', 'A', 'D' ) ),
	'fastest methods' => array( RateSorter::FASTEST, array( array( 600, 2, 4 ), array( 800, 2, 3 ), array( 500, 2, 3 ), array( 100, 3, 3 ) ), array( 'C', 'B', 'A', 'D' ) ),
	'fastest zero tie' => array( RateSorter::FASTEST, array( array( 500, 2, 3 ), array( 0, 2, 3 ) ), array( 'A', 'B' ) ),
	'max before price' => array( RateSorter::FASTEST, array( array( 500, 2, 5 ), array( 900, 2, 3 ) ), array( 'B', 'A' ) ),
	'full term price tie' => array( RateSorter::FASTEST, array( array( 500, 2, 3 ), array( 300, 2, 3 ) ), array( 'B', 'A' ) ),
	'missing terms and fallback' => array( RateSorter::FASTEST, array( array( 500, 2, 4 ), array( 300, 2, null ), array( 100, null, null ), array( 0, null, null ) ), array( 'A', 'B', 'C', 'D' ) ),
);
foreach ( $cases as $name => [ $mode, $values, $expected ] ) {
	foreach ( array( '', 'one-group' ) as $group ) {
		$rates = array();
		foreach ( $values as $index => [ $price, $min, $max ] ) {
			$rates[] = sorting_rate( chr( 65 + $index ), $price, $min, $max, $group );
		}
		foreach ( array( 'sort', 'sort_group_rates', 'sort_methods' ) as $method ) {
			sorting_expect( $expected, $sorter->$method( array_reverse( $rates ), $mode ), $name . ' / ' . $method . ' / ' . $group );
		}
	}
}

$a = sorting_rate( 'A', 100, 5, 6, 'group' );
$a = DeliveryRate::from_array( array_merge( $a->to_array(), array( 'original_cost' => array( 'amount_kopecks' => 50000 ), 'original_delivery_days' => ( new DateRange( 2, 3 ) )->to_array() ) ) );
$b = sorting_rate( 'B', 300, 2, 3, 'group' );
$b = DeliveryRate::from_array( array_merge( $b->to_array(), array( 'original_cost' => array( 'amount_kopecks' => 20000 ), 'original_delivery_days' => ( new DateRange( 5, 6 ) )->to_array() ) ) );
foreach ( array( 'sort', 'sort_group_rates', 'sort_methods' ) as $method ) {
	sorting_expect( array( 'A', 'B' ), $sorter->$method( array( $b, $a ), RateSorter::CHEAPEST ), 'Final price / ' . $method );
	sorting_expect( array( 'B', 'A' ), $sorter->$method( array( $a, $b ), RateSorter::FASTEST ), 'Final terms / ' . $method );
}

$rates = array( sorting_rate( 'A', 500, 1, 2, 'first' ), sorting_rate( 'B', 100, 5, 6, 'first' ), sorting_rate( 'C', 200, 3, 4, 'second' ), sorting_rate( 'D', 300, 2, 3, 'second' ) );
sorting_expect( array( 'B', 'A', 'C', 'D' ), $sorter->sort( $rates, RateSorter::CHEAPEST ), 'Methods follow cheapest active rate and stay contiguous' );
sorting_expect( array( 'A', 'B', 'D', 'C' ), $sorter->sort( $rates, RateSorter::FASTEST ), 'Methods follow fastest active rate and stay contiguous' );
$fixed = sorting_rate( 'fixed', 100, 3, 3 );
$fixed = DeliveryRate::from_array( array_merge( $fixed->to_array(), array( 'delivery_days' => DateRange::single( 3 )->to_array() ) ) );
sorting_expect( array( 'fixed', 'range' ), $sorter->sort( array( sorting_rate( 'range', 100, 3, 4 ), $fixed ), RateSorter::FASTEST ), 'Fixed term is min=max' );
foreach ( array( RateSorter::CHEAPEST, RateSorter::FASTEST ) as $mode ) {
	sorting_expect( array( 'A', 'B' ), $sorter->sort( array( sorting_rate( 'B', 100, 2, 3 ), sorting_rate( 'A', 100, 2, 3 ) ), $mode ), 'Deterministic ties' );
	$copy = clone $fixed;
	if ( array( $copy, $fixed ) !== $sorter->sort_group_rates( array( $copy, $fixed ), $mode ) ) {
		throw new RuntimeException( 'Exact ties must preserve input index.' );
	}
}
echo "Checkout rate sorting smoke passed.\n";
