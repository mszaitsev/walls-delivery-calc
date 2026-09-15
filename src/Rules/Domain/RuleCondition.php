<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Domain;

use WallsShop\WDC\Rules\ValueObjects\RuleConditionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperators;

final class RuleCondition {
	/**
	 * @param array<string|int,mixed> $value_json
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly ?int $rule_id,
		public readonly int $condition_group,
		public readonly string $condition_type,
		public readonly string $operator,
		public readonly string $value_text = '',
		public readonly ?float $value_number = null,
		public readonly array $value_json = array()
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'rule_id'         => $this->rule_id,
			'condition_group' => $this->condition_group,
			'condition_type'  => $this->condition_type,
			'operator'        => $this->operator,
			'value_text'      => $this->value_text,
			'value_number'    => $this->value_number,
			'value_json'      => $this->value_json,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		$value_json = $data['value_json'] ?? array();
		if ( is_string( $value_json ) && '' !== trim( $value_json ) ) {
			$decoded    = json_decode( $value_json, true );
			$value_json = is_array( $decoded ) ? $decoded : array();
		}

		return new self(
			array_key_exists( 'id', $data ) && null !== $data['id'] ? (int) $data['id'] : null,
			array_key_exists( 'rule_id', $data ) && null !== $data['rule_id'] ? (int) $data['rule_id'] : null,
			(int) ( $data['condition_group'] ?? 1 ),
			(string) ( $data['condition_type'] ?? '' ),
			(string) ( $data['operator'] ?? '' ),
			(string) ( $data['value_text'] ?? '' ),
			array_key_exists( 'value_number', $data ) && null !== $data['value_number'] && '' !== $data['value_number'] ? (float) $data['value_number'] : null,
			is_array( $value_json ) ? $value_json : array()
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( $this->condition_group < 1 ) {
			$errors[] = 'condition_group must be greater than 0';
		}

		if ( ! RuleConditionTypes::is_valid( $this->condition_type ) ) {
			$errors[] = 'condition_type is invalid';
		}

		if ( ! RuleOperators::is_valid( $this->operator ) ) {
			$errors[] = 'operator is invalid';
		}

		return $errors;
	}
}
