<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Pickup;

use WallsShop\WDC\Domain\Common\Money;

final class PickupPoint {
	/**
	 * @param array<string,mixed> $raw_reference
	 */
	public function __construct(
		public readonly string $carrier_key,
		public readonly string $code,
		public readonly string $address,
		public readonly string $city,
		public readonly string $region = '',
		public readonly string $postcode = '',
		public readonly ?float $latitude = null,
		public readonly ?float $longitude = null,
		public readonly string $type = 'unknown',
		public readonly string $work_time = '',
		public readonly string $comment = '',
		public readonly ?Money $extra_cost = null,
		public readonly bool $active = true,
		public readonly array $raw_reference = array()
	) {
	}

	public function has_coordinates(): bool {
		return null !== $this->latitude && null !== $this->longitude;
	}

	public function has_extra_cost(): bool {
		return null !== $this->extra_cost && ! $this->extra_cost->is_zero();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'carrier_key'   => $this->carrier_key,
			'code'          => $this->code,
			'address'       => $this->address,
			'city'          => $this->city,
			'region'        => $this->region,
			'postcode'      => $this->postcode,
			'latitude'      => $this->latitude,
			'longitude'     => $this->longitude,
			'type'          => $this->type,
			'work_time'     => $this->work_time,
			'comment'       => $this->comment,
			'extra_cost'    => $this->extra_cost?->to_array(),
			'active'        => $this->active,
			'raw_reference' => $this->raw_reference,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['carrier_key'] ?? '' ),
			(string) ( $data['code'] ?? '' ),
			(string) ( $data['address'] ?? '' ),
			(string) ( $data['city'] ?? '' ),
			(string) ( $data['region'] ?? '' ),
			(string) ( $data['postcode'] ?? '' ),
			array_key_exists( 'latitude', $data ) && null !== $data['latitude'] ? (float) $data['latitude'] : null,
			array_key_exists( 'longitude', $data ) && null !== $data['longitude'] ? (float) $data['longitude'] : null,
			(string) ( $data['type'] ?? 'unknown' ),
			(string) ( $data['work_time'] ?? '' ),
			(string) ( $data['comment'] ?? '' ),
			is_array( $data['extra_cost'] ?? null ) ? Money::from_array( $data['extra_cost'] ) : null,
			(bool) ( $data['active'] ?? true ),
			is_array( $data['raw_reference'] ?? null ) ? $data['raw_reference'] : array()
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( '' === trim( $this->carrier_key ) ) {
			$errors[] = 'carrier_key is required';
		}

		if ( '' === trim( $this->code ) ) {
			$errors[] = 'code is required';
		}

		if ( ! in_array( $this->type, array( 'pvz', 'postamat', 'terminal', 'warehouse', 'unknown' ), true ) ) {
			$errors[] = 'type is invalid';
		}

		return null === $this->extra_cost ? $errors : array_merge( $errors, $this->extra_cost->validate() );
	}
}
