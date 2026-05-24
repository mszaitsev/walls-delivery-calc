<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Services;

use DateTimeImmutable;
use WallsShop\WDC\Rules\Domain\RuleCondition;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\ValueObjects\RuleConditionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperators;

final class ConditionEvaluator {
	public function evaluate( RuleCondition $condition, RuleEvaluationContext $context ): bool {
		if ( RuleConditionTypes::DIMENSIONS === $condition->condition_type ) {
			return $this->compare_dimensions( $context, $condition );
		}

		$left = $this->context_value( $condition->condition_type, $context );

		if ( RuleConditionTypes::DATE === $condition->condition_type ) {
			return $this->compare_dates( (string) $left, $condition );
		}

		if ( RuleConditionTypes::CITY === $condition->condition_type ) {
			return $this->compare_city( (string) $left, $condition, $context );
		}

		if ( is_int( $left ) || is_float( $left ) ) {
			return $this->compare_numbers( (float) $left, $condition );
		}

		return $this->compare_strings( (string) $left, $condition );
	}

	private function context_value( string $type, RuleEvaluationContext $context ): int|float|string {
		return match ( $type ) {
			RuleConditionTypes::ORDER_TOTAL    => $context->order_total->get_rubles(),
			RuleConditionTypes::ITEMS_COUNT    => $context->package->get_total_quantity(),
			RuleConditionTypes::PAYMENT_METHOD => $context->payment_method,
			RuleConditionTypes::CITY           => '' !== $context->destination->city ? $context->destination->city : $context->destination->settlement,
			RuleConditionTypes::COUNTRY        => $context->destination->country_code,
			RuleConditionTypes::DELIVERY_TYPE  => $context->delivery_type,
			RuleConditionTypes::DELIVERY_PRICE => $context->delivery_price->get_rubles(),
			RuleConditionTypes::WEIGHT         => $context->package->get_total_weight_g(),
			RuleConditionTypes::VOLUME         => $context->package->get_total_volume_cm3() / 1000000,
			RuleConditionTypes::DAY_OF_WEEK    => (int) ( new DateTimeImmutable( $context->calculation_date ) )->format( 'N' ),
			RuleConditionTypes::DAY_OF_MONTH   => (int) ( new DateTimeImmutable( $context->calculation_date ) )->format( 'j' ),
			RuleConditionTypes::MONTH          => (int) ( new DateTimeImmutable( $context->calculation_date ) )->format( 'n' ),
			RuleConditionTypes::DATE           => $context->calculation_date,
			default                            => '',
		};
	}

	private function compare_numbers( float $left, RuleCondition $condition ): bool {
		$right = $condition->value_number ?? (float) $condition->value_text;

		return match ( $condition->operator ) {
			RuleOperators::EQ     => $left === $right,
			RuleOperators::NEQ    => $left !== $right,
			RuleOperators::GT     => $left > $right,
			RuleOperators::GTE    => $left >= $right,
			RuleOperators::LT     => $left < $right,
			RuleOperators::LTE    => $left <= $right,
			RuleOperators::IN     => in_array( $left, $this->number_list( $condition ), true ),
			RuleOperators::NOT_IN => ! in_array( $left, $this->number_list( $condition ), true ),
			default               => false,
		};
	}

	private function compare_strings( string $left, RuleCondition $condition ): bool {
		$left_normalized = strtolower( trim( $left ) );
		$right           = strtolower( trim( $condition->value_text ) );
		$list            = array_map( static fn ( string $value ): string => strtolower( trim( $value ) ), $this->string_list( $condition ) );

		return match ( $condition->operator ) {
			RuleOperators::EQ           => $left_normalized === $right,
			RuleOperators::NEQ          => $left_normalized !== $right,
			RuleOperators::IN           => in_array( $left_normalized, $list, true ),
			RuleOperators::NOT_IN       => ! in_array( $left_normalized, $list, true ),
			RuleOperators::CONTAINS     => '' !== $right && str_contains( $left_normalized, $right ),
			RuleOperators::NOT_CONTAINS => '' === $right || ! str_contains( $left_normalized, $right ),
			default                     => false,
		};
	}

	private function compare_dates( string $left, RuleCondition $condition ): bool {
		$left_date  = strtotime( $left );
		$right_date = strtotime( $condition->value_text );

		if ( false === $left_date || false === $right_date ) {
			return false;
		}

		return match ( $condition->operator ) {
			RuleOperators::EQ  => $left_date === $right_date,
			RuleOperators::NEQ => $left_date !== $right_date,
			RuleOperators::GT  => $left_date > $right_date,
			RuleOperators::GTE => $left_date >= $right_date,
			RuleOperators::LT  => $left_date < $right_date,
			RuleOperators::LTE => $left_date <= $right_date,
			default            => false,
		};
	}

	private function compare_city( string $left, RuleCondition $condition, RuleEvaluationContext $context ): bool {
		$fias_id = trim( (string) ( $context->meta['selected_location_fias_id'] ?? $context->meta['location_fias_id'] ?? $context->meta['_wdc_platform_location_fias_id'] ?? $context->destination->fias_id ) );
		$condition_fias_id = trim( $condition->value_text );
		if ( '' === $fias_id || '' === $condition_fias_id ) {
			return false;
		}

		$matches = strtolower( $fias_id ) === strtolower( $condition_fias_id );

		return RuleOperators::NEQ === $condition->operator ? ! $matches : $matches;
	}

	private function compare_dimensions( RuleEvaluationContext $context, RuleCondition $condition ): bool {
		$checks = array();
		$left = array(
			'length_cm' => $context->package->length_cm,
			'width_cm'  => $context->package->width_cm,
			'height_cm' => $context->package->height_cm,
		);

		foreach ( array( 'length_cm', 'width_cm', 'height_cm' ) as $key ) {
			if ( ! isset( $condition->value_json[ $key ] ) || '' === (string) $condition->value_json[ $key ] ) {
				continue;
			}

			$actual = $left[ $key ];
			if ( null === $actual ) {
				return false;
			}

			$checks[] = $this->compare_number_pair( (float) $actual, (float) $condition->value_json[ $key ], $condition->operator );
		}

		return array() !== $checks && ! in_array( false, $checks, true );
	}

	private function compare_number_pair( float $left, float $right, string $operator ): bool {
		return match ( $operator ) {
			RuleOperators::EQ  => $left === $right,
			RuleOperators::NEQ => $left !== $right,
			RuleOperators::GT  => $left > $right,
			RuleOperators::GTE => $left >= $right,
			RuleOperators::LT  => $left < $right,
			RuleOperators::LTE => $left <= $right,
			default            => false,
		};
	}

	/**
	 * @return array<int,float>
	 */
	private function number_list( RuleCondition $condition ): array {
		$values = array() !== $condition->value_json ? $condition->value_json : explode( ',', $condition->value_text );

		return array_map( 'floatval', $values );
	}

	/**
	 * @return array<int,string>
	 */
	private function string_list( RuleCondition $condition ): array {
		$values = array() !== $condition->value_json ? $condition->value_json : explode( ',', $condition->value_text );

		return array_values( array_filter( array_map( 'strval', $values ), static fn ( string $value ): bool => '' !== trim( $value ) ) );
	}
}
