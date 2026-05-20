<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Shipment;

use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;

final class Shipment {
	/**
	 * @param array<int,ShipmentPlace> $places
	 * @param array<string,mixed> $raw_reference
	 */
	public function __construct(
		public readonly int $order_id,
		public readonly string $carrier_key,
		public readonly string $external_id,
		public readonly string $tracking_number,
		public readonly string $delivery_type,
		public readonly string $rate_id,
		public readonly string $status,
		public readonly string $status_title,
		public readonly array $places,
		public readonly string $pickup_point_code,
		public readonly string $pickup_point_address,
		public readonly Address $courier_address,
		public readonly string $created_at,
		public readonly string $updated_at,
		public readonly array $raw_reference = array()
	) {
	}

	public function has_external_id(): bool {
		return '' !== trim( $this->external_id );
	}

	public function has_tracking_number(): bool {
		return '' !== trim( $this->tracking_number );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'order_id'             => $this->order_id,
			'carrier_key'          => $this->carrier_key,
			'external_id'          => $this->external_id,
			'tracking_number'      => $this->tracking_number,
			'delivery_type'        => $this->delivery_type,
			'rate_id'              => $this->rate_id,
			'status'               => $this->status,
			'status_title'         => $this->status_title,
			'places'               => array_map( static fn ( ShipmentPlace $place ): array => $place->to_array(), $this->places ),
			'pickup_point_code'    => $this->pickup_point_code,
			'pickup_point_address' => $this->pickup_point_address,
			'courier_address'      => $this->courier_address->to_array(),
			'created_at'           => $this->created_at,
			'updated_at'           => $this->updated_at,
			'raw_reference'        => $this->raw_reference,
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
			(string) ( $data['external_id'] ?? '' ),
			(string) ( $data['tracking_number'] ?? '' ),
			(string) ( $data['delivery_type'] ?? DeliveryType::UNKNOWN ),
			(string) ( $data['rate_id'] ?? '' ),
			(string) ( $data['status'] ?? '' ),
			(string) ( $data['status_title'] ?? '' ),
			$places,
			(string) ( $data['pickup_point_code'] ?? '' ),
			(string) ( $data['pickup_point_address'] ?? '' ),
			Address::from_array( is_array( $data['courier_address'] ?? null ) ? $data['courier_address'] : array() ),
			(string) ( $data['created_at'] ?? '' ),
			(string) ( $data['updated_at'] ?? '' ),
			is_array( $data['raw_reference'] ?? null ) ? $data['raw_reference'] : array()
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

		return $errors;
	}
}
