<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Quote;

use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;

final class QuoteRequest {
	/**
	 * @param array<string,mixed> $customer_context
	 */
	public function __construct(
		public readonly string $country_code,
		public readonly Address $destination,
		public readonly Package $package,
		public readonly string $payment_method,
		public readonly Money $order_total,
		public readonly string $calculation_date,
		public readonly array $customer_context = array()
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'country_code'     => $this->country_code,
			'destination'      => $this->destination->to_array(),
			'package'          => $this->package->to_array(),
			'payment_method'   => $this->payment_method,
			'order_total'      => $this->order_total->to_array(),
			'calculation_date' => $this->calculation_date,
			'customer_context' => $this->customer_context,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['country_code'] ?? '' ),
			Address::from_array( is_array( $data['destination'] ?? null ) ? $data['destination'] : array() ),
			Package::from_array( is_array( $data['package'] ?? null ) ? $data['package'] : array() ),
			(string) ( $data['payment_method'] ?? '' ),
			Money::from_array( is_array( $data['order_total'] ?? null ) ? $data['order_total'] : array() ),
			(string) ( $data['calculation_date'] ?? '' ),
			is_array( $data['customer_context'] ?? null ) ? $data['customer_context'] : array()
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( '' === trim( $this->country_code ) ) {
			$errors[] = 'country_code is required';
		}

		if ( '' === trim( $this->calculation_date ) ) {
			$errors[] = 'calculation_date is required';
		}

		return array_merge( $errors, $this->destination->validate(), $this->package->validate(), $this->order_total->validate() );
	}
}
