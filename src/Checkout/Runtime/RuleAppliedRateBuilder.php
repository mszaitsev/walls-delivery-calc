<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Runtime;

use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Services\RuleEngine;

defined( 'ABSPATH' ) || exit;

final class RuleAppliedRateBuilder {
	public function __construct(
		private RuleEngine $rule_engine
	) {
	}

	/**
	 * @param array<int,Rule> $rules
	 * @return array{rate:DeliveryRate,audit:array<int,array<string,mixed>>}
	 */
	public function apply( DeliveryRate $rate, RuleEvaluationContext $context, array $rules ): array {
		if ( array() === $rules || ! empty( $rate->meta['skip_rules'] ) ) {
			return array( 'rate' => $rate, 'audit' => array() );
		}

		$result = $this->rule_engine->apply_rules( $rules, $context );

		$delivery_days = $result->final_delivery_days ?? $rate->delivery_days;
		$modified = new DeliveryRate(
			$rate->rate_id,
			$rate->carrier_key,
			$rate->carrier_name,
			$rate->service_key,
			$rate->service_name,
			$rate->tariff_key,
			$rate->tariff_name,
			$rate->delivery_type,
			$rate->title,
			$result->final_price ?? $rate->price,
			$result->original_price ?? $rate->original_price,
			$result->crossed_price ?? $rate->crossed_price,
			$delivery_days,
			$rate->planned_delivery_date,
			$result->final_delivery_days instanceof DateRange ? $this->delivery_comment( $delivery_days ) : $rate->planned_delivery_comment,
			array_values( array_merge( $rate->comments, $result->comments ) ),
			$rate->disabled || $result->disabled,
			$result->disabled_reason ?: $rate->disabled_reason,
			$rate->requires_pickup_point,
			$rate->requires_courier_address,
			array_merge( $rate->meta, array( 'rules_applied' => array() !== $result->audit ) )
		);

		return array(
			'rate'  => $modified,
			'audit' => array_map( static fn ( object $entry ): array => method_exists( $entry, 'to_array' ) ? $entry->to_array() : array(), $result->audit ),
		);
	}

	private function delivery_comment( DateRange $range ): string {
		if ( $range->is_empty() ) {
			return '';
		}
		if ( $range->min_days === $range->max_days ) {
			return (string) $range->min_days . ' дн.';
		}

		return (string) $range->min_days . '-' . (string) $range->max_days . ' дн.';
	}
}
