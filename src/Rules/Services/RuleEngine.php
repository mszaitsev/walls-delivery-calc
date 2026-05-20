<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Services;

use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleEngineResult;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;

final class RuleEngine {
	public function __construct(
		private RuleEvaluator $rule_evaluator
	) {
	}

	/**
	 * @param array<int,Rule> $rules
	 */
	public function apply_rules( array $rules, RuleEvaluationContext $context ): RuleEngineResult {
		$original_price      = $context->delivery_price;
		$current_price       = $context->delivery_price;
		$crossed_price       = null;
		$final_delivery_days = null;
		$comments            = array();
		$disabled            = false;
		$disabled_reason     = '';
		$audit               = array();

		foreach ( $rules as $rule ) {
			if ( ! $rule instanceof Rule ) {
				continue;
			}

			$runtime_context = new RuleEvaluationContext(
				$context->order_total,
				$current_price,
				$context->package,
				$context->destination,
				$context->delivery_type,
				$context->payment_method,
				$context->calculation_date,
				$context->calendar_context,
				$context->meta
			);
			$result          = $this->rule_evaluator->evaluate( $rule, $runtime_context );
			$audit           = array_merge( $audit, $result->audit );

			if ( $result->applied ) {
				if ( null !== $result->modified_price ) {
					if ( $rule->promo_shipping && null === $crossed_price ) {
						$crossed_price = $current_price;
					}

					$current_price = $rule->promo_shipping ? $this->clamp_min_price( $result->modified_price ) : $result->modified_price;
				}

				if ( null !== $result->modified_delivery_days ) {
					$final_delivery_days = $result->modified_delivery_days;
				}

				$comments = array_merge( $comments, $result->added_comments );

				if ( $result->disabled ) {
					$disabled        = true;
					$disabled_reason = $result->disabled_reason;
				}

				if ( $result->stop_processing ) {
					break;
				}
			}
		}

		return new RuleEngineResult( $current_price, $original_price, $crossed_price, $final_delivery_days, $comments, $disabled, $disabled_reason, $audit );
	}

	private function clamp_min_price( Money $price ): Money {
		return Money::from_kopecks( max( 100, $price->get_kopecks() ), $price->get_currency() );
	}
}
