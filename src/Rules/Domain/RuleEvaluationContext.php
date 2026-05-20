<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Domain;

use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;

final class RuleEvaluationContext {
	/**
	 * @param array<string,mixed> $calendar_context
	 * @param array<string,mixed> $meta
	 */
	public function __construct(
		public readonly Money $order_total,
		public readonly Money $delivery_price,
		public readonly Package $package,
		public readonly Address $destination,
		public readonly string $delivery_type,
		public readonly string $payment_method,
		public readonly string $calculation_date,
		public readonly array $calendar_context = array(),
		public readonly array $meta = array()
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'order_total'      => $this->order_total->to_array(),
			'delivery_price'   => $this->delivery_price->to_array(),
			'package'          => $this->package->to_array(),
			'destination'      => $this->destination->to_array(),
			'delivery_type'    => $this->delivery_type,
			'payment_method'   => $this->payment_method,
			'calculation_date' => $this->calculation_date,
			'calendar_context' => $this->calendar_context,
			'meta'             => $this->meta,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			Money::from_array( is_array( $data['order_total'] ?? null ) ? $data['order_total'] : array() ),
			Money::from_array( is_array( $data['delivery_price'] ?? null ) ? $data['delivery_price'] : array() ),
			Package::from_array( is_array( $data['package'] ?? null ) ? $data['package'] : array() ),
			Address::from_array( is_array( $data['destination'] ?? null ) ? $data['destination'] : array() ),
			(string) ( $data['delivery_type'] ?? '' ),
			(string) ( $data['payment_method'] ?? '' ),
			(string) ( $data['calculation_date'] ?? '' ),
			is_array( $data['calendar_context'] ?? null ) ? $data['calendar_context'] : array(),
			is_array( $data['meta'] ?? null ) ? $data['meta'] : array()
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array_merge( $this->order_total->validate(), $this->delivery_price->validate(), $this->package->validate(), $this->destination->validate() );

		if ( '' === trim( $this->delivery_type ) ) {
			$errors[] = 'delivery_type is required';
		}

		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $this->calculation_date ) ) {
			$errors[] = 'calculation_date must be YYYY-MM-DD';
		}

		return $errors;
	}
}
