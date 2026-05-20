<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Domain;

use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;

final class RuleEvaluationResult {
	/**
	 * @param array<int,string> $added_comments
	 * @param array<int,RuleAuditEntry> $audit
	 */
	public function __construct(
		public readonly bool $success,
		public readonly bool $applied,
		public readonly ?Money $modified_price = null,
		public readonly ?DateRange $modified_delivery_days = null,
		public readonly array $added_comments = array(),
		public readonly bool $disabled = false,
		public readonly string $disabled_reason = '',
		public readonly array $audit = array(),
		public readonly bool $stop_processing = false
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'success'                => $this->success,
			'applied'                => $this->applied,
			'modified_price'         => $this->modified_price?->to_array(),
			'modified_delivery_days' => $this->modified_delivery_days?->to_array(),
			'added_comments'         => $this->added_comments,
			'disabled'               => $this->disabled,
			'disabled_reason'        => $this->disabled_reason,
			'audit'                  => array_map( static fn ( RuleAuditEntry $entry ): array => $entry->to_array(), $this->audit ),
			'stop_processing'        => $this->stop_processing,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		$audit = array_map(
			static fn ( mixed $entry ): RuleAuditEntry => RuleAuditEntry::from_array( is_array( $entry ) ? $entry : array() ),
			is_array( $data['audit'] ?? null ) ? $data['audit'] : array()
		);

		return new self(
			(bool) ( $data['success'] ?? false ),
			(bool) ( $data['applied'] ?? false ),
			is_array( $data['modified_price'] ?? null ) ? Money::from_array( $data['modified_price'] ) : null,
			is_array( $data['modified_delivery_days'] ?? null ) ? DateRange::from_array( $data['modified_delivery_days'] ) : null,
			is_array( $data['added_comments'] ?? null ) ? array_map( 'strval', $data['added_comments'] ) : array(),
			(bool) ( $data['disabled'] ?? false ),
			(string) ( $data['disabled_reason'] ?? '' ),
			$audit,
			(bool) ( $data['stop_processing'] ?? false )
		);
	}
}
