<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Runtime;

use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Checkout\Cache\QuoteCache;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRegistry;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Packaging\PackagingApplicationResult;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;
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
		private ?QuoteCache $quote_cache = null,
		private ?DeliveryServiceRegistry $service_registry = null,
		private ?DeliveryServiceManager $service_manager = null,
		private ?PackagingWeightCalculator $packaging_calculator = null
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
	public function calculate( QuoteRequest $request, array $rules = array(), string $sort = RateSorter::CHEAPEST, bool $cache_enabled = true, ?callable $rules_resolver = null ): CheckoutCalculationResult {
		$carrier_errors = array();
		$audit          = array();
		$cache_hits     = 0;
		$rates          = array();
		$service_entries = $this->service_entries_for_country( $request->country_code );

		if ( null === $service_entries ) {
			$carriers = $this->carrier_registry->for_country( $request->country_code );
			if ( array() === $carriers ) {
				$carriers = $this->carrier_registry->enabled();
			}
			foreach ( $carriers as $carrier ) {
				$service_entries[] = array( 'carrier' => $carrier, 'service' => null );
			}
		}

		foreach ( $service_entries as $entry ) {
			$carrier = $entry['carrier'];
			$service = $entry['service'];
			$carrier_key   = $carrier->get_identity()->key;
			$service_key   = $service instanceof DeliveryService ? $service->service_key : '';
			$delivery_type = (string) ( $entry['delivery_type'] ?? '' );
			$quote         = null;
			$service_request = $request;
			$packaging_result = null;
			if ( $service instanceof DeliveryService && $this->packaging_calculator instanceof PackagingWeightCalculator ) {
				$packaging_result = $this->packaging_calculator->apply_to_package( $request->package, $service );
				$service_request = $this->request_with_package( $request, $packaging_result->package, $packaging_result );
			}
			if ( $service instanceof DeliveryService ) {
				$service_request = $this->request_for_service( $service_request, $service, $delivery_type );
			}

			if ( $cache_enabled && $this->quote_cache instanceof QuoteCache ) {
				$quote = $this->quote_cache->get( $service_request, $carrier_key, $delivery_type, $service_key );
				if ( $quote instanceof DeliveryQuote ) {
					++$cache_hits;
					$this->logger->info( 'Quote cache hit.', array( 'carrier' => $carrier_key ) );
				} else {
					$this->logger->info( 'Quote cache miss.', array( 'carrier' => $carrier_key ) );
				}
			}

			if ( ! $quote instanceof DeliveryQuote ) {
				$quote = $this->execution_guard->quote( $carrier, $service_request, $carrier_errors );
				if ( $cache_enabled && $this->quote_cache instanceof QuoteCache && $quote->success ) {
					$this->quote_cache->set( $service_request, $carrier_key, $quote, $delivery_type, $service_key );
				}
			}

			foreach ( $quote->rates as $rate ) {
				if ( ! $rate instanceof DeliveryRate ) {
					continue;
				}

				if ( $packaging_result instanceof PackagingApplicationResult ) {
					$rate = $this->rate_with_meta( $rate, $packaging_result->to_meta() );
				}
				$rate = $service instanceof DeliveryService ? $this->rate_for_service( $rate, $service ) : $rate;
				$rules_source = 'none';
				if ( ! empty( $rate->meta['skip_rules'] ) ) {
					$rules_for_rate = array();
					$rules_source = 'skipped_fallback';
				} elseif ( $service instanceof DeliveryService && $this->service_manager instanceof DeliveryServiceManager ) {
					$rules_data = $this->service_manager->rules_for_service( $service );
					$rules_for_rate = $rules_data['rules'];
					$rules_source = $rules_data['source'];
				} else {
					$rules_for_rate = null !== $rules_resolver ? $rules_resolver( $rate->carrier_key ) : $rules;
					$rules_for_rate = is_array( $rules_for_rate ) ? $rules_for_rate : array();
					$rules_source = array() !== $rules_for_rate ? 'default' : 'none';
				}
				$applied = $this->rule_builder->apply( $rate, $this->context_for_rate( $service_request, $rate ), $rules_for_rate );
				$processed = $service instanceof DeliveryService && $this->service_manager instanceof DeliveryServiceManager && empty( $applied['rate']->meta['skip_service_post_processing'] )
					? $this->service_manager->post_process_rate( $applied['rate'], $service )
					: $applied['rate'];
				$processed = $this->rate_with_meta(
					$processed,
					array(
						'rules_source' => $rules_source,
						'rules_audit'  => $applied['audit'],
						'final_price_rub' => $processed->price->get_rubles(),
						'original_price_rub' => $rate->price->get_rubles(),
					)
				);
				$rates[] = $processed;
				$audit[] = array(
					'rate_id' => $rate->rate_id,
					'carrier' => $rate->carrier_key,
					'service' => $rate->service_key,
					'rules_source' => $rules_source,
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

	/**
	 * @return array<int,array{carrier:object,service:?DeliveryService,delivery_type?:string}>|null
	 */
	private function service_entries_for_country( string $country_code ): ?array {
		if ( ! $this->service_registry instanceof DeliveryServiceRegistry || ! $this->service_manager instanceof DeliveryServiceManager ) {
			return null;
		}

		$entries = array();
		$services = $this->service_registry->active_services();
		if ( array() === $services ) {
			return null;
		}

		foreach ( $services as $service ) {
			if ( ! $this->service_manager->service_available_for_country( $service, $country_code ) ) {
				continue;
			}
			$carrier = $this->service_registry->carrier_for( $service );
			if ( null !== $carrier ) {
				if ( RussianPostDomesticSettings::SERVICE_KEY === $service->service_key ) {
					$entries[] = array( 'carrier' => $carrier, 'service' => $service, 'delivery_type' => DeliveryType::PICKUP );
					$entries[] = array( 'carrier' => $carrier, 'service' => $service, 'delivery_type' => DeliveryType::COURIER );
					continue;
				}
				$entries[] = array( 'carrier' => $carrier, 'service' => $service );
			}
		}

		return $entries;
	}

	private function rate_for_service( DeliveryRate $rate, DeliveryService $service ): DeliveryRate {
		$comment_type = $rate->delivery_type;
		$comment = match ( $comment_type ) {
			DeliveryType::PICKUP => trim( $service->pickup_customer_comment ),
			DeliveryType::COURIER => trim( $service->courier_customer_comment ),
			default => '',
		};
		$is_fallback = ! empty( $rate->meta['fallback'] );
		$apply_service_comment = '' !== $comment && ! $is_fallback;
		$comments = $apply_service_comment ? array_values( array_filter( array_merge( array( $comment ), $rate->comments ), static fn ( mixed $item ): bool => '' !== trim( (string) $item ) ) ) : $rate->comments;

		return new DeliveryRate(
			$rate->rate_id,
			$service->carrier_key,
			$rate->carrier_name,
			$service->service_key,
			$service->title,
			$rate->tariff_key,
			$rate->tariff_name,
			$rate->delivery_type,
			$is_fallback ? $rate->title : $service->title,
			$rate->price,
			$rate->original_price,
			$rate->crossed_price,
			$rate->delivery_days,
			$rate->planned_delivery_date,
			$rate->planned_delivery_comment,
			$comments,
			$rate->disabled,
			$rate->disabled_reason,
			$rate->requires_pickup_point,
			$rate->requires_courier_address,
			array_merge(
				$rate->meta,
				array(
					'service_key' => $service->service_key,
					'service_title' => $service->title,
					'carrier_key' => $service->carrier_key,
					'service_customer_comment_applied' => $apply_service_comment ? 'yes' : 'no',
					'service_customer_comment_type' => in_array( $comment_type, array( DeliveryType::PICKUP, DeliveryType::COURIER ), true ) ? $comment_type : '',
				)
			)
		);
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function rate_with_meta( DeliveryRate $rate, array $meta ): DeliveryRate {
		return new DeliveryRate(
			$rate->rate_id,
			$rate->carrier_key,
			$rate->carrier_name,
			$rate->service_key,
			$rate->service_name,
			$rate->tariff_key,
			$rate->tariff_name,
			$rate->delivery_type,
			$rate->title,
			$rate->price,
			$rate->original_price,
			$rate->crossed_price,
			$rate->delivery_days,
			$rate->planned_delivery_date,
			$rate->planned_delivery_comment,
			$rate->comments,
			$rate->disabled,
			$rate->disabled_reason,
			$rate->requires_pickup_point,
			$rate->requires_courier_address,
			array_merge( $rate->meta, $meta )
		);
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
					'original_delivery_days' => $rate->delivery_days->min_days ?? $rate->delivery_days->max_days ?? null,
					'original_delivery_min_days' => $rate->delivery_days->min_days,
					'original_delivery_max_days' => $rate->delivery_days->max_days,
					'selected_location_fias_id' => (string) ( $request->customer_context['selected_location_fias_id'] ?? $request->destination->fias_id ),
				)
			)
		);
	}

	private function request_with_package( QuoteRequest $request, Package $package, PackagingApplicationResult $packaging ): QuoteRequest {
		return new QuoteRequest(
			$request->country_code,
			$request->destination,
			$package,
			$request->payment_method,
			$request->order_total,
			$request->calculation_date,
			array_merge( $request->customer_context, $packaging->to_meta() )
		);
	}

	private function request_for_service( QuoteRequest $request, DeliveryService $service, string $delivery_type = '' ): QuoteRequest {
		$context = array(
			'service_key' => $service->service_key,
			'service_title' => $service->title,
			'service_carrier_key' => $service->carrier_key,
		);
		if ( '' !== $delivery_type ) {
			$context['delivery_type'] = $delivery_type;
		}

		return new QuoteRequest(
			$request->country_code,
			$request->destination,
			$request->package,
			$request->payment_method,
			$request->order_total,
			$request->calculation_date,
			array_merge(
				$request->customer_context,
				$context
			)
		);
	}
}
