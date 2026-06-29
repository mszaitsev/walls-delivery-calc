<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Checkout\Cache\QuoteCache;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();
require_once dirname( __DIR__ ) . '/fixtures/TestDemoCarrier.php';

function checkout_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function checkout_request( string $country = 'RU', string $delivery_type = '' ): QuoteRequest {
	$total = Money::from_rubles( 1000 );
	$item  = new PackageItem( 'SKU', 'Item', 1, $total, $total, 1000, 10, 10, 10 );

	return new QuoteRequest(
		$country,
		new Address( country_code: $country, city: 'Moscow', street: 'Tverskaya', house: '1', raw_address: 'Moscow, Tverskaya 1' ),
		Package::from_items( array( $item ), 0, $total, $total ),
		'card',
		$total,
		'2026-05-21',
		array( 'delivery_type' => $delivery_type )
	);
}

function checkout_orchestrator( ?CarrierRegistry $registry = null, ?QuoteCache $cache = null ): CheckoutOrchestrator {
	$logger = new CheckoutLogger();
	$engine = new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) );

	if ( null === $registry ) {
		$registry = new CarrierRegistry();
		$registry->register( new TestDemoCarrier() );
	}

	return new CheckoutOrchestrator(
		$registry,
		new RuleAppliedRateBuilder( $engine ),
		new RateSorter(),
		new FallbackRateFactory(),
		new CarrierExecutionGuard( $logger ),
		$logger,
		$cache
	);
}

function checkout_promo_rule(): Rule {
	return new Rule( null, 'Demo promo -500', true, 10, 'rate', 'demo', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::DECREASE, 500, RuleOperationBases::RUBLES, true, false );
}

function checkout_sort_rate( string $carrier, int $original_price_rub, int $min_days, string $title, string $tariff_key, ?int $final_price_rub = null, ?int $final_min_days = null ): DeliveryRate {
	$final_price_rub ??= $original_price_rub;
	$final_min_days ??= $min_days;

	return new DeliveryRate(
		$carrier . ':' . $tariff_key,
		$carrier,
		$carrier,
		$carrier,
		$carrier,
		$tariff_key,
		$title,
		DeliveryType::PICKUP,
		$title,
		Money::from_rubles( $final_price_rub ),
		null,
		null,
		DateRange::single( $final_min_days ),
		'',
		$final_min_days . ' дн.',
		array(),
		false,
		'',
		false,
		false,
		array(),
		Money::from_rubles( $original_price_rub ),
		DateRange::single( $min_days )
	);
}
function checkout_increase_rule(): Rule {
	return new Rule( null, 'Demo +200', true, 10, 'rate', 'demo', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::INCREASE, 200, RuleOperationBases::RUBLES, false, false );
}

final class CheckoutFailingCarrier implements CarrierAdapterInterface {
	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( 'failing', 'Failing Carrier', 'fixed', true );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities( supports_quotes: true );
	}

	public function supports_country( string $countryCode ): bool {
		return true;
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		throw new RuntimeException( 'Intentional carrier failure.' );
	}
}

final class CheckoutExistingCrossedCarrier implements CarrierAdapterInterface {
	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( 'crossed', 'Existing Crossed Carrier', 'fixed', true );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities( supports_quotes: true );
	}

	public function supports_country( string $countryCode ): bool {
		return true;
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		$rate = new DeliveryRate(
			'crossed:pickup',
			'crossed',
			'Existing Crossed Carrier',
			DeliveryType::PICKUP,
			'Existing crossed pickup',
			DeliveryType::PICKUP,
			'Existing crossed pickup',
			DeliveryType::PICKUP,
			'Existing crossed pickup',
			Money::from_rubles( 400 ),
			null,
			Money::from_rubles( 500 ),
			DateRange::single( 4 )
		);

		return new DeliveryQuote( 'crossed-demo', 'crossed', $request->destination, $request->package, array( $rate ), true, '', '', false, 'manual' );
	}
}

$orchestrator = checkout_orchestrator();

$result = $orchestrator->calculate( checkout_request() );
checkout_smoke_assert( count( $result->rates ) >= 2, 'RU quote must return demo rates.' );
checkout_smoke_assert( ! $result->fallback_used, 'RU quote must not use fallback.' );

$result = $orchestrator->calculate( checkout_request( 'US' ) );
checkout_smoke_assert( $result->fallback_used, 'Non-RU quote must use fallback.' );
checkout_smoke_assert( 'fallback' === $result->rates[0]->carrier_key, 'Fallback rate must be returned for non-RU quote.' );

$result = $orchestrator->calculate( checkout_request(), array(), RateSorter::CHEAPEST );
checkout_smoke_assert( 35000 === $result->rates[0]->price->get_kopecks(), 'Cheapest sorting must put pickup first.' );

$result = $orchestrator->calculate( checkout_request(), array(), RateSorter::FASTEST );
checkout_smoke_assert( 3 === $result->rates[0]->delivery_days->min_days, 'Fastest sorting must put courier first.' );

$result = $orchestrator->calculate( checkout_request( 'RU', 'pickup' ), array( checkout_increase_rule() ) );
checkout_smoke_assert( 55000 === $result->rates[0]->price->get_kopecks(), 'Regular +200 rule must modify pickup price.' );
checkout_smoke_assert( null === $result->rates[0]->crossed_price, 'Regular price rule must not create crossed price.' );
checkout_smoke_assert( ! $result->rates[0]->has_discount(), 'Regular price rule must not be treated as a promo discount.' );

$result = $orchestrator->calculate( checkout_request( 'RU', 'pickup' ), array( checkout_promo_rule() ) );
checkout_smoke_assert( 100 === $result->rates[0]->price->get_kopecks(), 'Rule engine must modify demo pickup rate.' );
checkout_smoke_assert( 35000 === $result->rates[0]->crossed_price?->get_kopecks(), 'Crossed price must survive rule application.' );
checkout_smoke_assert( $result->rates[0]->has_discount(), 'Promo rule must be treated as a discount when crossed price is present.' );

$registry = new CarrierRegistry();
$registry->register( new CheckoutExistingCrossedCarrier() );
$result = checkout_orchestrator( $registry )->calculate( checkout_request(), array( checkout_increase_rule() ) );
checkout_smoke_assert( 60000 === $result->rates[0]->price->get_kopecks(), 'Regular rule must modify rate with existing crossed price.' );
checkout_smoke_assert( 50000 === $result->rates[0]->crossed_price?->get_kopecks(), 'Existing crossed price must be preserved when no promo rule is applied.' );

$registry = new CarrierRegistry();
$registry->register( new CheckoutFailingCarrier() );
$result = checkout_orchestrator( $registry )->calculate( checkout_request() );
checkout_smoke_assert( $result->fallback_used, 'Fallback must be used when all carriers fail.' );
checkout_smoke_assert( isset( $result->carrier_errors['failing'] ), 'Carrier exception must be captured.' );

$cache        = new QuoteCache();
$orchestrator = checkout_orchestrator( null, $cache );
$first        = $orchestrator->calculate( checkout_request(), array(), RateSorter::CHEAPEST, true );
$second       = $orchestrator->calculate( checkout_request(), array(), RateSorter::CHEAPEST, true );
checkout_smoke_assert( 0 === $first->cache_hits, 'First cached calculation must miss cache.' );
checkout_smoke_assert( $second->cache_hits > 0, 'Second cached calculation must hit cache.' );


$sorter = new RateSorter();
$sorted = $sorter->sort(
	array(
		checkout_sort_rate( 'carrier_a', 300, 5, 'A 300', '300' ),
		checkout_sort_rate( 'carrier_a', 100, 5, 'A 100', '100' ),
		checkout_sort_rate( 'carrier_a', 200, 5, 'A 200', '200' ),
	),
	RateSorter::CHEAPEST
);
checkout_smoke_assert( array( '100', '200', '300' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->tariff_key, $sorted ), 'Price sorting inside one carrier must use original cost ascending.' );

$sorted = $sorter->sort(
	array(
		checkout_sort_rate( 'carrier_a', 100, 5, 'A 100', '100', 95 ),
		checkout_sort_rate( 'carrier_a', 200, 5, 'A 200', '200', 170 ),
		checkout_sort_rate( 'carrier_a', 350, 5, 'A 350', '350', 150 ),
	),
	RateSorter::CHEAPEST
);
checkout_smoke_assert( array( '100', '200', '350' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->tariff_key, $sorted ), 'Price sorting must ignore final discounted prices.' );

$sorted = $sorter->sort(
	array(
		checkout_sort_rate( 'carrier_a', 300, 4, 'A 300', '300' ),
		checkout_sort_rate( 'carrier_a', 100, 4, 'A 100', '100' ),
		checkout_sort_rate( 'carrier_a', 200, 4, 'A 200', '200' ),
		checkout_sort_rate( 'carrier_b', 120, 4, 'B 120', '120' ),
		checkout_sort_rate( 'carrier_c', 90, 4, 'C 90', '090' ),
	),
	RateSorter::CHEAPEST
);
checkout_smoke_assert( array( 'carrier_c', 'carrier_a', 'carrier_a', 'carrier_a', 'carrier_b' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->carrier_key, $sorted ), 'Carrier ordering by price must use each carrier minimum original cost.' );
checkout_smoke_assert( array( '090', '100', '200', '300', '120' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->tariff_key, $sorted ), 'Carrier price ordering must keep rates sorted inside each carrier group.' );

$sorted = $sorter->sort(
	array(
		checkout_sort_rate( 'carrier_a', 100, 5, 'A 5', '5' ),
		checkout_sort_rate( 'carrier_a', 100, 2, 'A 2', '2' ),
		checkout_sort_rate( 'carrier_a', 100, 7, 'A 7', '7' ),
	),
	RateSorter::FASTEST
);
checkout_smoke_assert( array( '2', '5', '7' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->tariff_key, $sorted ), 'Delivery-days sorting inside one carrier must use original min days ascending.' );

$sorted = $sorter->sort(
	array(
		checkout_sort_rate( 'carrier_a', 100, 4, 'A 4', '4' ),
		checkout_sort_rate( 'carrier_b', 100, 1, 'B 1', '1' ),
		checkout_sort_rate( 'carrier_c', 100, 3, 'C 3', '3' ),
	),
	RateSorter::FASTEST
);
checkout_smoke_assert( array( 'carrier_b', 'carrier_c', 'carrier_a' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->carrier_key, $sorted ), 'Carrier ordering by delivery days must use each carrier fastest original tariff.' );

$sorted = $sorter->sort(
	array(
		checkout_sort_rate( 'carrier_a', 100, 5, 'Beta', 'b' ),
		checkout_sort_rate( 'carrier_a', 100, 2, 'Zulu', 'z' ),
		checkout_sort_rate( 'carrier_a', 100, 2, 'Alpha', 'c' ),
		checkout_sort_rate( 'carrier_a', 100, 2, 'Alpha', 'a' ),
	),
	RateSorter::CHEAPEST
);
checkout_smoke_assert( array( 'a', 'c', 'z', 'b' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->tariff_key, $sorted ), 'Equal-price sorting must use days, title and tariff_key as stable tie-breakers.' );

$sorted = $sorter->sort(
	array(
		checkout_sort_rate( 'carrier_a', 300, 2, 'Beta', 'b' ),
		checkout_sort_rate( 'carrier_a', 100, 2, 'Zulu', 'z' ),
		checkout_sort_rate( 'carrier_a', 100, 2, 'Alpha', 'c' ),
		checkout_sort_rate( 'carrier_a', 100, 2, 'Alpha', 'a' ),
	),
	RateSorter::FASTEST
);
checkout_smoke_assert( array( 'a', 'c', 'z', 'b' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->tariff_key, $sorted ), 'Equal-delivery-days sorting must use cost, title and tariff_key as stable tie-breakers.' );
echo "Checkout smoke test passed.\n";
