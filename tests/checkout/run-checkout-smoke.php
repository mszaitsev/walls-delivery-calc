<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\Runtime\DemoCarrier;
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
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
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
		$registry->register( new DemoCarrier() );
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

$result = $orchestrator->calculate( checkout_request( 'RU', 'pickup' ), array( checkout_promo_rule() ) );
checkout_smoke_assert( 100 === $result->rates[0]->price->get_kopecks(), 'Rule engine must modify demo pickup rate.' );
checkout_smoke_assert( 35000 === $result->rates[0]->crossed_price?->get_kopecks(), 'Crossed price must survive rule application.' );

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

echo "Checkout smoke test passed.\n";
