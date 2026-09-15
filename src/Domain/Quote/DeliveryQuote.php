<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Quote;

use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Package\Package;

final class DeliveryQuote {
	/**
	 * @param array<int,DeliveryRate> $rates
	 * @param array<string,mixed> $raw_reference
	 */
	public function __construct(
		public readonly string $quote_id,
		public readonly string $carrier_key,
		public readonly Address $destination,
		public readonly Package $package,
		public readonly array $rates = array(),
		public readonly bool $success = true,
		public readonly string $error_code = '',
		public readonly string $error_message = '',
		public readonly bool $cache_hit = false,
		public readonly string $source = 'api',
		public readonly array $raw_reference = array()
	) {
	}

	/**
	 * @return array<int,DeliveryRate>
	 */
	public function get_available_rates(): array {
		return array_values( array_filter( $this->rates, static fn ( DeliveryRate $rate ): bool => $rate->is_available() ) );
	}

	public function has_available_rates(): bool {
		return array() !== $this->get_available_rates();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'quote_id'      => $this->quote_id,
			'carrier_key'   => $this->carrier_key,
			'destination'   => $this->destination->to_array(),
			'package'       => $this->package->to_array(),
			'rates'         => array_map( static fn ( DeliveryRate $rate ): array => $rate->to_array(), $this->rates ),
			'success'       => $this->success,
			'error_code'    => $this->error_code,
			'error_message' => $this->error_message,
			'cache_hit'     => $this->cache_hit,
			'source'        => $this->source,
			'raw_reference' => $this->raw_reference,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		$rates = array_map(
			static fn ( mixed $rate ): DeliveryRate => DeliveryRate::from_array( is_array( $rate ) ? $rate : array() ),
			is_array( $data['rates'] ?? null ) ? $data['rates'] : array()
		);

		return new self(
			(string) ( $data['quote_id'] ?? '' ),
			(string) ( $data['carrier_key'] ?? '' ),
			Address::from_array( is_array( $data['destination'] ?? null ) ? $data['destination'] : array() ),
			Package::from_array( is_array( $data['package'] ?? null ) ? $data['package'] : array() ),
			$rates,
			(bool) ( $data['success'] ?? true ),
			(string) ( $data['error_code'] ?? '' ),
			(string) ( $data['error_message'] ?? '' ),
			(bool) ( $data['cache_hit'] ?? false ),
			(string) ( $data['source'] ?? 'api' ),
			is_array( $data['raw_reference'] ?? null ) ? $data['raw_reference'] : array()
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( '' === trim( $this->quote_id ) ) {
			$errors[] = 'quote_id is required';
		}

		if ( '' === trim( $this->carrier_key ) ) {
			$errors[] = 'carrier_key is required';
		}

		if ( ! in_array( $this->source, array( 'api', 'manual', 'cache', 'fallback' ), true ) ) {
			$errors[] = 'source must be api, manual, cache, or fallback';
		}

		foreach ( $this->rates as $rate ) {
			$errors = array_merge( $errors, $rate->validate() );
		}

		return array_merge( $errors, $this->destination->validate(), $this->package->validate() );
	}
}
