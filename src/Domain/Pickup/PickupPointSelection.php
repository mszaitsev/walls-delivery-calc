<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Pickup;

final class PickupPointSelection {
	public function __construct(
		public readonly string $carrier_key,
		public readonly string $rate_id,
		public readonly string $point_code,
		public readonly string $point_address,
		public readonly string $selected_at,
		public readonly bool $price_changed = false,
		public readonly string $price_change_comment = ''
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'carrier_key'          => $this->carrier_key,
			'rate_id'              => $this->rate_id,
			'point_code'           => $this->point_code,
			'point_address'        => $this->point_address,
			'selected_at'          => $this->selected_at,
			'price_changed'        => $this->price_changed,
			'price_change_comment' => $this->price_change_comment,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['carrier_key'] ?? '' ),
			(string) ( $data['rate_id'] ?? '' ),
			(string) ( $data['point_code'] ?? '' ),
			(string) ( $data['point_address'] ?? '' ),
			(string) ( $data['selected_at'] ?? '' ),
			(bool) ( $data['price_changed'] ?? false ),
			(string) ( $data['price_change_comment'] ?? '' )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		foreach ( array( 'carrier_key' => $this->carrier_key, 'rate_id' => $this->rate_id, 'point_code' => $this->point_code ) as $field => $value ) {
			if ( '' === trim( $value ) ) {
				$errors[] = $field . ' is required';
			}
		}

		return $errors;
	}
}
