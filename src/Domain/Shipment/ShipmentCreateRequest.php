<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Shipment;

use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Pickup\PickupPointSelection;
use WallsShop\WDC\Domain\Quote\DeliveryType;

final class ShipmentCreateRequest {
	/**
	 * @param array<int,ShipmentPlace> $places
	 * @param array<string,mixed> $services
	 * @param array<string,mixed> $recipient
	 * @param array<string,mixed> $meta
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly string $carrier_key,
		public readonly string $delivery_type,
		public readonly string $rate_id,
		public readonly Address $recipient_address,
		public readonly ?PickupPointSelection $pickup_point,
		public readonly array $places,
		public readonly Money $declared_value,
		public readonly bool $insurance_enabled = false,
		public readonly array $services = array(),
		public readonly array $recipient = array(),
		public readonly array $meta = array()
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'order_id'          => $this->order_id,
			'carrier_key'       => $this->carrier_key,
			'delivery_type'     => $this->delivery_type,
			'rate_id'           => $this->rate_id,
			'recipient_address' => $this->recipient_address->to_array(),
			'pickup_point'      => $this->pickup_point?->to_array(),
			'places'            => array_map( static fn ( ShipmentPlace $place ): array => $place->to_array(), $this->places ),
			'declared_value'    => $this->declared_value->to_array(),
			'insurance_enabled' => $this->insurance_enabled,
			'services'          => $this->services,
			'recipient'         => $this->recipient,
			'meta'              => $this->meta,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		$places = array_map(
			static fn ( mixed $place ): ShipmentPlace => ShipmentPlace::from_array( is_array( $place ) ? $place : array() ),
			is_array( $data['places'] ?? null ) ? $data['places'] : array()
		);

		return new self(
			(int) ( $data['order_id'] ?? 0 ),
			(string) ( $data['carrier_key'] ?? '' ),
			(string) ( $data['delivery_type'] ?? DeliveryType::UNKNOWN ),
			(string) ( $data['rate_id'] ?? '' ),
			Address::from_array( is_array( $data['recipient_address'] ?? null ) ? $data['recipient_address'] : array() ),
			is_array( $data['pickup_point'] ?? null ) ? PickupPointSelection::from_array( $data['pickup_point'] ) : null,
			$places,
			Money::from_array( is_array( $data['declared_value'] ?? null ) ? $data['declared_value'] : array() ),
			(bool) ( $data['insurance_enabled'] ?? false ),
			is_array( $data['services'] ?? null ) ? $data['services'] : array(),
			is_array( $data['recipient'] ?? null ) ? $data['recipient'] : array(),
			is_array( $data['meta'] ?? null ) ? $data['meta'] : array()
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( $this->order_id <= 0 ) {
			$errors[] = 'order_id must be greater than 0';
		}

		if ( '' === trim( $this->carrier_key ) ) {
			$errors[] = 'carrier_key is required';
		}

		if ( ! DeliveryType::is_valid( $this->delivery_type ) ) {
			$errors[] = 'delivery_type is invalid';
		}

		foreach ( $this->places as $place ) {
			$errors = array_merge( $errors, $place->validate() );
		}

		if ( null !== $this->pickup_point ) {
			$errors = array_merge( $errors, $this->pickup_point->validate() );
		}

		return array_merge( $errors, $this->recipient_address->validate(), $this->declared_value->validate() );
	}
}
