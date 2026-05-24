<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Services;

use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleAuditEntry;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Domain\RuleEvaluationResult;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

final class RuleEvaluator {
	public function __construct(
		private ConditionEvaluator $condition_evaluator
	) {
	}

	public function evaluate( Rule $rule, RuleEvaluationContext $context ): RuleEvaluationResult {
		if ( ! $rule->enabled ) {
			return $this->not_applied( $rule, 'Rule is disabled.' );
		}

		if ( ! $this->matches_conditions( $rule, $context ) ) {
			return $this->not_applied( $rule, 'Conditions did not match.' );
		}

		$audit = array();

		if ( RuleActionTypes::CHANGE_PRICE === $rule->action_type ) {
			$modified = $this->apply_price_operation( $context->delivery_price, $context, $rule );
			$audit[]  = new RuleAuditEntry( $rule->id, $rule->name, $rule->action_type, $context->delivery_price->to_array(), $modified->to_array(), $rule->operation_type, true, 'Price changed.' );

			return new RuleEvaluationResult( true, true, $modified, null, array(), false, '', $audit, $rule->stop_processing );
		}

		if ( RuleActionTypes::CHANGE_DELIVERY_DAYS === $rule->action_type ) {
			$unit    = RuleOperationBases::BUSINESS_DAYS === $rule->operation_base ? DateRange::UNIT_BUSINESS_DAYS : DateRange::UNIT_CALENDAR_DAYS;
			$days    = $this->apply_delivery_days_operation( $context, $rule );
			$range   = DateRange::single( $days, $unit );
			$audit[] = new RuleAuditEntry( $rule->id, $rule->name, $rule->action_type, null, $range->to_array(), $rule->operation_type, true, 'Delivery days changed.' );

			return new RuleEvaluationResult( true, true, null, $range, array(), false, '', $audit, $rule->stop_processing );
		}

		if ( RuleActionTypes::ADD_COMMENT === $rule->action_type ) {
			$comment = '' !== trim( $rule->operation_text ) ? $rule->operation_text : $rule->name;
			$audit[] = new RuleAuditEntry( $rule->id, $rule->name, $rule->action_type, null, $comment, $rule->operation_type, true, 'Comment added.' );

			return new RuleEvaluationResult( true, true, null, null, array( $comment ), false, '', $audit, $rule->stop_processing );
		}

		if ( RuleActionTypes::DISABLE_RATE === $rule->action_type ) {
			$reason  = '' !== trim( $rule->target_value ) ? $rule->target_value : $rule->name;
			$audit[] = new RuleAuditEntry( $rule->id, $rule->name, $rule->action_type, false, true, $rule->operation_type, true, $reason );

			return new RuleEvaluationResult( true, true, null, null, array(), true, $reason, $audit, $rule->stop_processing );
		}

		return $this->not_applied( $rule, 'Unsupported action type.' );
	}

	private function matches_conditions( Rule $rule, RuleEvaluationContext $context ): bool {
		if ( array() === $rule->conditions ) {
			return true;
		}

		$groups = array();
		foreach ( $rule->conditions as $condition ) {
			$groups[ $condition->condition_group ][] = $condition;
		}

		$logic = Rule::normalized_group_logic( $rule->condition_group_logic );
		foreach ( $groups as $group => $conditions ) {
			$mode = $logic[ (int) $group ] ?? 'and';
			$group_matches = 'and' === $mode;
			foreach ( $conditions as $condition ) {
				$matches = $this->condition_evaluator->evaluate( $condition, $context );
				if ( 'or' === $mode && $matches ) {
					$group_matches = true;
					break;
				}
				if ( 'and' === $mode && ! $matches ) {
					$group_matches = false;
					break;
				}
			}

			if ( $group_matches ) {
				return true;
			}
		}

		return false;
	}

	private function apply_price_operation( Money $current_price, RuleEvaluationContext $context, Rule $rule ): Money {
		$delta = $this->operation_delta( $current_price, $context, $rule );

		return match ( $rule->operation_type ) {
			RuleOperationTypes::INCREASE => $current_price->add( $delta ),
			RuleOperationTypes::DECREASE => $current_price->subtract( $delta ),
			RuleOperationTypes::EQUALS   => $delta,
			default                      => $current_price,
		};
	}

	private function apply_delivery_days_operation( RuleEvaluationContext $context, Rule $rule ): int {
		$value = max( 0, (int) round( $rule->operation_value ) );
		$base  = isset( $context->meta['current_delivery_days'] )
			? max( 0, (int) $context->meta['current_delivery_days'] )
			: ( isset( $context->meta['original_delivery_days'] ) ? max( 0, (int) $context->meta['original_delivery_days'] ) : 0 );

		return match ( $rule->operation_type ) {
			RuleOperationTypes::INCREASE => $base + $value,
			RuleOperationTypes::DECREASE => max( 0, $base - $value ),
			default                      => $value,
		};
	}

	private function operation_delta( Money $current_price, RuleEvaluationContext $context, Rule $rule ): Money {
		if ( RuleOperationBases::RUBLES === $rule->operation_base ) {
			return Money::from_rubles( $rule->operation_value, $current_price->get_currency() );
		}

		$base = match ( $rule->operation_base ) {
			RuleOperationBases::PERCENT_OF_ORDER              => $context->order_total,
			RuleOperationBases::PERCENT_OF_ORDER_AND_DELIVERY => $context->order_total->add( $current_price ),
			default                                           => $current_price,
		};

		return $base->multiply( $rule->operation_value / 100 );
	}

	private function not_applied( Rule $rule, string $reason ): RuleEvaluationResult {
		return new RuleEvaluationResult(
			true,
			false,
			null,
			null,
			array(),
			false,
			'',
			array( new RuleAuditEntry( $rule->id, $rule->name, $rule->action_type, null, null, $rule->operation_type, false, $reason ) ),
			false
		);
	}
}
