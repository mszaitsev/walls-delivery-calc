<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Address;

final class Address {
	public function __construct(
		public readonly string $country_code = '',
		public readonly string $country_name = '',
		public readonly string $region_name = '',
		public readonly string $region_code = '',
		public readonly string $city = '',
		public readonly string $settlement = '',
		public readonly string $postcode = '',
		public readonly string $street = '',
		public readonly string $house = '',
		public readonly string $apartment = '',
		public readonly string $raw_address = '',
		public readonly string $fias_id = '',
		public readonly string $gar_id = '',
		public readonly bool $normalized = false,
		public readonly bool $fallback = false
	) {
	}

	public function has_city(): bool {
		return '' !== trim( $this->city ) || '' !== trim( $this->settlement );
	}

	public function has_postcode(): bool {
		return '' !== trim( $this->postcode );
	}

	public function has_full_courier_address(): bool {
		return $this->has_city()
			&& ( '' !== trim( $this->raw_address ) || ( '' !== trim( $this->street ) && '' !== trim( $this->house ) ) );
	}

	public function with_postcode( string $postcode ): self {
		return new self( $this->country_code, $this->country_name, $this->region_name, $this->region_code, $this->city, $this->settlement, $postcode, $this->street, $this->house, $this->apartment, $this->raw_address, $this->fias_id, $this->gar_id, $this->normalized, $this->fallback );
	}

	public function with_normalized( bool $normalized ): self {
		return new self( $this->country_code, $this->country_name, $this->region_name, $this->region_code, $this->city, $this->settlement, $this->postcode, $this->street, $this->house, $this->apartment, $this->raw_address, $this->fias_id, $this->gar_id, $normalized, $this->fallback );
	}

	public function with_fallback( bool $fallback ): self {
		return new self( $this->country_code, $this->country_name, $this->region_name, $this->region_code, $this->city, $this->settlement, $this->postcode, $this->street, $this->house, $this->apartment, $this->raw_address, $this->fias_id, $this->gar_id, $this->normalized, $fallback );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'country_code' => $this->country_code,
			'country_name' => $this->country_name,
			'region_name'  => $this->region_name,
			'region_code'  => $this->region_code,
			'city'         => $this->city,
			'settlement'   => $this->settlement,
			'postcode'     => $this->postcode,
			'street'       => $this->street,
			'house'        => $this->house,
			'apartment'    => $this->apartment,
			'raw_address'  => $this->raw_address,
			'fias_id'      => $this->fias_id,
			'gar_id'       => $this->gar_id,
			'normalized'   => $this->normalized,
			'fallback'     => $this->fallback,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['country_code'] ?? '' ),
			(string) ( $data['country_name'] ?? '' ),
			(string) ( $data['region_name'] ?? '' ),
			(string) ( $data['region_code'] ?? '' ),
			(string) ( $data['city'] ?? '' ),
			(string) ( $data['settlement'] ?? '' ),
			(string) ( $data['postcode'] ?? '' ),
			(string) ( $data['street'] ?? '' ),
			(string) ( $data['house'] ?? '' ),
			(string) ( $data['apartment'] ?? '' ),
			(string) ( $data['raw_address'] ?? '' ),
			(string) ( $data['fias_id'] ?? '' ),
			(string) ( $data['gar_id'] ?? '' ),
			(bool) ( $data['normalized'] ?? false ),
			(bool) ( $data['fallback'] ?? false )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( '' === trim( $this->country_code ) ) {
			$errors[] = 'country_code is recommended';
		}

		if ( ! $this->has_city() ) {
			$errors[] = 'city or settlement is recommended';
		}

		if ( ! $this->has_full_courier_address() ) {
			$errors[] = 'street and house or raw_address are required for courier delivery';
		}

		return $errors;
	}
}
