<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Domain;

use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

final class Rule {
	/**
	 * @param array<int,RuleCondition> $conditions
	 */
	public function __construct(
		public readonly ?int $id,
		public readonly string $name,
		public readonly bool $enabled,
		public readonly int $priority,
		public readonly string $target_type,
		public readonly string $target_value,
		public readonly string $action_type,
		public readonly string $operation_type,
		public readonly float $operation_value,
		public readonly string $operation_base,
		public readonly bool $promo_shipping,
		public readonly bool $stop_processing,
		public readonly array $conditions = array()
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'name'            => $this->name,
			'enabled'         => $this->enabled,
			'priority'        => $this->priority,
			'target_type'     => $this->target_type,
			'target_value'    => $this->target_value,
			'action_type'     => $this->action_type,
			'operation_type'  => $this->operation_type,
			'operation_value' => $this->operation_value,
			'operation_base'  => $this->operation_base,
			'promo_shipping'  => $this->promo_shipping,
			'stop_processing' => $this->stop_processing,
			'conditions'      => array_map( static fn ( RuleCondition $condition ): array => $condition->to_array(), $this->conditions ),
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		$conditions = array_map(
			static fn ( mixed $condition ): RuleCondition => $condition instanceof RuleCondition ? $condition : RuleCondition::from_array( is_array( $condition ) ? $condition : array() ),
			is_array( $data['conditions'] ?? null ) ? $data['conditions'] : array()
		);

		return new self(
			array_key_exists( 'id', $data ) && null !== $data['id'] ? (int) $data['id'] : null,
			(string) ( $data['name'] ?? '' ),
			(bool) ( $data['enabled'] ?? true ),
			(int) ( $data['priority'] ?? 100 ),
			(string) ( $data['target_type'] ?? '' ),
			(string) ( $data['target_value'] ?? '' ),
			(string) ( $data['action_type'] ?? '' ),
			(string) ( $data['operation_type'] ?? RuleOperationTypes::EQUALS ),
			(float) ( $data['operation_value'] ?? 0 ),
			(string) ( $data['operation_base'] ?? RuleOperationBases::RUBLES ),
			(bool) ( $data['promo_shipping'] ?? false ),
			(bool) ( $data['stop_processing'] ?? false ),
			$conditions
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( '' === trim( $this->name ) ) {
			$errors[] = 'name is required';
		}

		if ( ! RuleActionTypes::is_valid( $this->action_type ) ) {
			$errors[] = 'action_type is invalid';
		}

		if ( ! RuleOperationTypes::is_valid( $this->operation_type ) ) {
			$errors[] = 'operation_type is invalid';
		}

		if ( ! RuleOperationBases::is_valid( $this->operation_base ) ) {
			$errors[] = 'operation_base is invalid';
		}

		foreach ( $this->conditions as $condition ) {
			$errors = array_merge( $errors, $condition->validate() );
		}

		return $errors;
	}
}
