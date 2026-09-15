<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Domain;

use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;

final class RuleEngineResult {
	/**
	 * @param array<int,string> $comments
	 * @param array<int,RuleAuditEntry> $audit
	 */
	public function __construct(
		public readonly ?Money $final_price,
		public readonly ?Money $original_price,
		public readonly ?Money $crossed_price,
		public readonly ?DateRange $final_delivery_days,
		public readonly array $comments,
		public readonly bool $disabled,
		public readonly string $disabled_reason,
		public readonly array $audit
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'final_price'         => $this->final_price?->to_array(),
			'original_price'      => $this->original_price?->to_array(),
			'crossed_price'       => $this->crossed_price?->to_array(),
			'final_delivery_days' => $this->final_delivery_days?->to_array(),
			'comments'            => $this->comments,
			'disabled'            => $this->disabled,
			'disabled_reason'     => $this->disabled_reason,
			'audit'               => array_map( static fn ( RuleAuditEntry $entry ): array => $entry->to_array(), $this->audit ),
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
			is_array( $data['final_price'] ?? null ) ? Money::from_array( $data['final_price'] ) : null,
			is_array( $data['original_price'] ?? null ) ? Money::from_array( $data['original_price'] ) : null,
			is_array( $data['crossed_price'] ?? null ) ? Money::from_array( $data['crossed_price'] ) : null,
			is_array( $data['final_delivery_days'] ?? null ) ? DateRange::from_array( $data['final_delivery_days'] ) : null,
			is_array( $data['comments'] ?? null ) ? array_map( 'strval', $data['comments'] ) : array(),
			(bool) ( $data['disabled'] ?? false ),
			(string) ( $data['disabled_reason'] ?? '' ),
			$audit
		);
	}
}
