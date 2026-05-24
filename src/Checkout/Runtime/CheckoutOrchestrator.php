<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Runtime;

use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Checkout\Cache\QuoteCache;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;

defined( 'ABSPATH' ) || exit;

final class CheckoutOrchestrator {
	public function __construct(
		private CarrierRegistry $carrier_registry,
		private RuleAppliedRateBuilder $rule_builder,
		private RateSorter $sorter,
		private FallbackRateFactory $fallback_factory,
		private CarrierExecutionGuard $execution_guard,
		private CheckoutLogger $logger,
		private ?QuoteCache $quote_cache = null
	) {
	}

	/**
	 * @return array<int,DeliveryRate>
	 */
	public function calculate_rates( QuoteRequest $request ): array {
		return $this->calculate( $request )->rates;
	}

	/**
	 * @param array<int,Rule> $rules
	 */
	public function calculate( QuoteRequest $request, array $rules = array(), string $sort = RateSorter::CHEAPEST, bool $cache_enabled = true ): CheckoutCalculationResult {
		$carrier_errors = array();
		$audit          = array();
		$cache_hits     = 0;
		$rates          = array();
		$carriers       = $this->carrier_registry->for_country( $request->country_code );

		if ( array() === $carriers ) {
			$carriers = $this->carrier_registry->enabled();
		}

		foreach ( $carriers as $carrier ) {
			$carrier_key   = $carrier->get_identity()->key;
			$delivery_type = '';
			$quote         = null;

			if ( $cache_enabled && $this->quote_cache instanceof QuoteCache ) {
				$quote = $this->quote_cache->get( $request, $carrier_key, $delivery_type );
				if ( $quote instanceof DeliveryQuote ) {
					++$cache_hits;
					$this->logger->info( 'Quote cache hit.', array( 'carrier' => $carrier_key ) );
				} else {
					$this->logger->info( 'Quote cache miss.', array( 'carrier' => $carrier_key ) );
				}
			}

			if ( ! $quote instanceof DeliveryQuote ) {
				$quote = $this->execution_guard->quote( $carrier, $request, $carrier_errors );
				if ( $cache_enabled && $this->quote_cache instanceof QuoteCache && $quote->success ) {
					$this->quote_cache->set( $request, $carrier_key, $quote, $delivery_type );
				}
			}

			foreach ( $quote->rates as $rate ) {
				if ( ! $rate instanceof DeliveryRate ) {
					continue;
				}

				$applied = $this->rule_builder->apply( $rate, $this->context_for_rate( $request, $rate ), $rules );
				$rates[] = $applied['rate'];
				$audit[] = array(
					'rate_id' => $rate->rate_id,
					'carrier' => $rate->carrier_key,
					'entries' => $applied['audit'],
				);
			}
		}

		$visible       = array_values( array_filter( $rates, static fn ( DeliveryRate $rate ): bool => $rate->is_available() ) );
		$fallback_used = array() === $visible;
		if ( $fallback_used ) {
			$visible[] = $this->fallback_factory->create();
			$this->logger->warning( 'Fallback rate used.', array( 'rates_count' => count( $rates ) ) );
		}

		$final = $this->sorter->sort( $visible, $sort );
		$this->logger->info( 'Checkout rates calculated.', array( 'rates_count' => count( $final ), 'fallback_used' => $fallback_used ) );

		return new CheckoutCalculationResult( $final, $fallback_used, $cache_hits, $audit, $carrier_errors );
	}

	private function context_for_rate( QuoteRequest $request, DeliveryRate $rate ): RuleEvaluationContext {
		return new RuleEvaluationContext(
			$request->order_total,
			$rate->price,
			$request->package,
			$request->destination,
			$rate->delivery_type,
			$request->payment_method,
			$request->calculation_date,
			array(),
			array_merge(
				$request->customer_context,
				$rate->meta,
				array(
					'carrier_key' => $rate->carrier_key,
					'rate_id'     => $rate->rate_id,
					'selected_location_fias_id' => (string) ( $request->customer_context['selected_location_fias_id'] ?? $request->destination->fias_id ),
				)
			)
		);
	}
}
