<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Domain;

final class RuleAuditEntry {
	/**
	 * @param mixed $before_value
	 * @param mixed $after_value
	 */
	public function __construct(
		public readonly ?int $rule_id,
		public readonly string $rule_name,
		public readonly string $action_type,
		public readonly mixed $before_value,
		public readonly mixed $after_value,
		public readonly string $operation,
		public readonly bool $applied,
		public readonly string $reason = ''
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'rule_id'      => $this->rule_id,
			'rule_name'    => $this->rule_name,
			'action_type'  => $this->action_type,
			'before_value' => $this->before_value,
			'after_value'  => $this->after_value,
			'operation'    => $this->operation,
			'applied'      => $this->applied,
			'reason'       => $this->reason,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			array_key_exists( 'rule_id', $data ) && null !== $data['rule_id'] ? (int) $data['rule_id'] : null,
			(string) ( $data['rule_name'] ?? '' ),
			(string) ( $data['action_type'] ?? '' ),
			$data['before_value'] ?? null,
			$data['after_value'] ?? null,
			(string) ( $data['operation'] ?? '' ),
			(bool) ( $data['applied'] ?? false ),
			(string) ( $data['reason'] ?? '' )
		);
	}
}
