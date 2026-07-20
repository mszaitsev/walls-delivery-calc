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

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['wdc_checkout_smoke_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, bool|string $autoload = false ): bool {
		$GLOBALS['wdc_checkout_smoke_options'][ $key ] = $value;

		return true;
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%d/', (string) (int) $arg, $query, 1 );
				$query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
			}

			return $query;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			return null;
		}

		public function get_var( string $query ): mixed {
			return 0;
		}
	}
}

$GLOBALS['wpdb'] = $GLOBALS['wpdb'] ?? new wpdb();

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

function checkout_lead_time_normalizer( int $processing_days = 0 ): \WallsShop\WDC\Checkout\Runtime\DeliveryLeadTimeNormalizer {
	$settings = new \WallsShop\WDC\Infrastructure\Settings\SettingsRepository();
	$settings->set( \WallsShop\WDC\Infrastructure\Settings\SettingsRepository::SHOP_PROCESSING_WORKING_DAYS_KEY, $processing_days );
	$timezone = new \WallsShop\WDC\Calendar\Services\TimezoneService();
	$formatter = new \WallsShop\WDC\Calendar\Services\DeliveryDateFormatter();

	return new \WallsShop\WDC\Checkout\Runtime\DeliveryLeadTimeNormalizer(
		$settings,
		new \WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository(),
		new \WallsShop\WDC\Calendar\Services\DeliveryDateCalculator( new \WallsShop\WDC\Calendar\Services\CalendarService( new \WallsShop\WDC\Calendar\Storage\CalendarRepository(), new \WallsShop\WDC\Calendar\Services\YearGenerator(), $settings, $timezone ), $timezone, $formatter ),
		$formatter
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
		checkout_lead_time_normalizer( 0 ),
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
$dpd_group = static fn( DeliveryRate $rate ): DeliveryRate => DeliveryRate::from_array(
	array_merge(
		$rate->to_array(),
		array( 'meta' => array_merge( $rate->meta, array( 'tariff_selector_group' => true, 'checkout_group_id' => 'dpd:pickup' ) ) )
	)
);
$yandex_pickup = checkout_sort_rate( 'yandex', 100, 3, 'Yandex pickup', 'pickup', 100, 3 );
$yandex_courier = checkout_sort_rate( 'yandex', 120, 2, 'Yandex courier', 'courier', 120, 2 );
$sorted = $sorter->sort( array( $yandex_pickup, $yandex_courier ), RateSorter::CHEAPEST );
checkout_smoke_assert( array( 'yandex:pickup', 'yandex:courier' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->rate_id, $sorted ), 'Rates with the same service_key but no tariff_selector_group must stay separate checkout methods.' );

$sorted = $sorter->sort(
	array(
		$dpd_group( checkout_sort_rate( 'dpd', 300, 5, 'DPD 300', '300' ) ),
		$dpd_group( checkout_sort_rate( 'dpd', 100, 5, 'DPD 100', '100' ) ),
		$dpd_group( checkout_sort_rate( 'dpd', 200, 5, 'DPD 200', '200' ) ),
	),
	RateSorter::CHEAPEST
);
checkout_smoke_assert( array( '100', '200', '300' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->tariff_key, $sorted ), 'Tariff selector rates must group by checkout_group_id and sort by original cost ascending.' );

$sorted = $sorter->sort(
	array(
		$dpd_group( checkout_sort_rate( 'dpd', 100, 5, 'DPD 100', '100', 95 ) ),
		$dpd_group( checkout_sort_rate( 'dpd', 200, 5, 'DPD 200', '200', 170 ) ),
		$dpd_group( checkout_sort_rate( 'dpd', 350, 5, 'DPD 350', '350', 150 ) ),
	),
	RateSorter::CHEAPEST
);
checkout_smoke_assert( array( '100', '200', '350' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->tariff_key, $sorted ), 'Selector price sorting must ignore final discounted prices.' );

$sorted = $sorter->sort(
	array(
		checkout_sort_rate( 'yandex', 100, 3, 'Yandex pickup', 'pickup', 100, 3 ),
		$dpd_group( checkout_sort_rate( 'dpd', 100, 4, 'DPD A', 'A', 110, 4 ) ),
		$dpd_group( checkout_sort_rate( 'dpd', 200, 5, 'DPD B', 'B', 50, 5 ) ),
		checkout_sort_rate( 'yandex', 120, 2, 'Yandex courier', 'courier', 120, 2 ),
	),
	RateSorter::CHEAPEST
);
checkout_smoke_assert( array( 'yandex:pickup', 'dpd:A', 'dpd:B', 'yandex:courier' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->rate_id, $sorted ), 'Only selector rates must be grouped; ordinary Yandex pickup/courier rates must remain separate methods.' );

$sorted = $sorter->sort(
	array(
		$dpd_group( checkout_sort_rate( 'dpd', 100, 5, 'DPD 5', '5' ) ),
		$dpd_group( checkout_sort_rate( 'dpd', 100, 2, 'DPD 2', '2' ) ),
		$dpd_group( checkout_sort_rate( 'dpd', 100, 7, 'DPD 7', '7' ) ),
	),
	RateSorter::FASTEST
);
checkout_smoke_assert( array( '2', '5', '7' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->tariff_key, $sorted ), 'Tariff selector delivery-days sorting must use original min days ascending.' );

$sorted = $sorter->sort(
	array(
		checkout_sort_rate( 'dpd', 100, 2, 'DPD method', 'dpd-method', 100, 5 ),
		checkout_sort_rate( 'yandex', 100, 4, 'Yandex method', 'yandex-method', 100, 2 ),
	),
	RateSorter::FASTEST
);
checkout_smoke_assert( array( 'yandex', 'dpd' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->carrier_key, $sorted ), 'Method ordering by delivery days must use active final delivery days, not original carrier days.' );

$sorted = $sorter->sort_group_rates(
	array(
		checkout_sort_rate( 'dpd', 100, 5, 'Beta', 'b' ),
		checkout_sort_rate( 'dpd', 100, 2, 'Zulu', 'z' ),
		checkout_sort_rate( 'dpd', 100, 2, 'Alpha', 'c' ),
		checkout_sort_rate( 'dpd', 100, 2, 'Alpha', 'a' ),
	),
	RateSorter::CHEAPEST
);
checkout_smoke_assert( array( 'a', 'c', 'z', 'b' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->tariff_key, $sorted ), 'Equal selector price sorting must use days, title and tariff_key as stable tie-breakers.' );

$sorted = $sorter->sort_group_rates(
	array(
		checkout_sort_rate( 'dpd', 300, 2, 'Beta', 'b' ),
		checkout_sort_rate( 'dpd', 100, 2, 'Zulu', 'z' ),
		checkout_sort_rate( 'dpd', 100, 2, 'Alpha', 'c' ),
		checkout_sort_rate( 'dpd', 100, 2, 'Alpha', 'a' ),
	),
	RateSorter::FASTEST
);
checkout_smoke_assert( array( 'a', 'c', 'z', 'b' ) === array_map( static fn( DeliveryRate $rate ): string => $rate->tariff_key, $sorted ), 'Equal selector delivery-days sorting must use cost, title and tariff_key as stable tie-breakers.' );
echo "Checkout smoke test passed.\n";
