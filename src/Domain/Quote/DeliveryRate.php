<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Quote;

use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;

final class DeliveryRate {
	/**
	 * @param array<int,string> $comments
	 * @param array<string,mixed> $meta
	 */
	public function __construct(
		public readonly string $rate_id,
		public readonly string $carrier_key,
		public readonly string $carrier_name,
		public readonly string $service_key,
		public readonly string $service_name,
		public readonly string $tariff_key,
		public readonly string $tariff_name,
		public readonly string $delivery_type,
		public readonly string $title,
		public readonly Money $price,
		public readonly ?Money $original_price,
		public readonly ?Money $crossed_price,
		public readonly DateRange $delivery_days,
		public readonly string $planned_delivery_date = '',
		public readonly string $planned_delivery_comment = '',
		public readonly array $comments = array(),
		public readonly bool $disabled = false,
		public readonly string $disabled_reason = '',
		public readonly bool $requires_pickup_point = false,
		public readonly bool $requires_courier_address = false,
		public readonly array $meta = array()
	) {
	}

	public function is_available(): bool {
		return ! $this->disabled;
	}

	public function has_discount(): bool {
		return null !== $this->crossed_price && $this->crossed_price->get_kopecks() > $this->price->get_kopecks();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'rate_id'                  => $this->rate_id,
			'carrier_key'              => $this->carrier_key,
			'carrier_name'             => $this->carrier_name,
			'service_key'              => $this->service_key,
			'service_name'             => $this->service_name,
			'tariff_key'               => $this->tariff_key,
			'tariff_name'              => $this->tariff_name,
			'delivery_type'            => $this->delivery_type,
			'title'                    => $this->title,
			'price'                    => $this->price->to_array(),
			'original_price'           => $this->original_price?->to_array(),
			'crossed_price'            => $this->crossed_price?->to_array(),
			'delivery_days'            => $this->delivery_days->to_array(),
			'planned_delivery_date'    => $this->planned_delivery_date,
			'planned_delivery_comment' => $this->planned_delivery_comment,
			'comments'                 => $this->comments,
			'disabled'                 => $this->disabled,
			'disabled_reason'          => $this->disabled_reason,
			'requires_pickup_point'    => $this->requires_pickup_point,
			'requires_courier_address' => $this->requires_courier_address,
			'meta'                     => $this->meta,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(string) ( $data['rate_id'] ?? '' ),
			(string) ( $data['carrier_key'] ?? '' ),
			(string) ( $data['carrier_name'] ?? '' ),
			(string) ( $data['service_key'] ?? '' ),
			(string) ( $data['service_name'] ?? '' ),
			(string) ( $data['tariff_key'] ?? '' ),
			(string) ( $data['tariff_name'] ?? '' ),
			(string) ( $data['delivery_type'] ?? DeliveryType::UNKNOWN ),
			(string) ( $data['title'] ?? '' ),
			Money::from_array( is_array( $data['price'] ?? null ) ? $data['price'] : array() ),
			is_array( $data['original_price'] ?? null ) ? Money::from_array( $data['original_price'] ) : null,
			is_array( $data['crossed_price'] ?? null ) ? Money::from_array( $data['crossed_price'] ) : null,
			DateRange::from_array( is_array( $data['delivery_days'] ?? null ) ? $data['delivery_days'] : array() ),
			(string) ( $data['planned_delivery_date'] ?? '' ),
			(string) ( $data['planned_delivery_comment'] ?? '' ),
			is_array( $data['comments'] ?? null ) ? array_values( $data['comments'] ) : array(),
			(bool) ( $data['disabled'] ?? false ),
			(string) ( $data['disabled_reason'] ?? '' ),
			(bool) ( $data['requires_pickup_point'] ?? false ),
			(bool) ( $data['requires_courier_address'] ?? false ),
			is_array( $data['meta'] ?? null ) ? $data['meta'] : array()
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( '' === trim( $this->rate_id ) ) {
			$errors[] = 'rate_id is required';
		}

		if ( '' === trim( $this->carrier_key ) ) {
			$errors[] = 'carrier_key is required';
		}

		if ( ! DeliveryType::is_valid( $this->delivery_type ) ) {
			$errors[] = 'delivery_type is invalid';
		}

		return array_merge( $errors, $this->price->validate(), $this->delivery_days->validate() );
	}
}
