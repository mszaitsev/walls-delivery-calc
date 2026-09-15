<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Carrier;

final class CarrierCapabilities {
	public function __construct(
		public readonly bool $supports_quotes = false,
		public readonly bool $supports_pickup_points = false,
		public readonly bool $supports_courier_delivery = false,
		public readonly bool $supports_pickup_delivery = false,
		public readonly bool $supports_shipment_creation = false,
		public readonly bool $supports_status_sync = false,
		public readonly bool $supports_documents = false,
		public readonly bool $supports_multi_place = false,
		public readonly bool $supports_itemized_places = false,
		public readonly bool $supports_place_declared_value = false,
		public readonly bool $supports_place_dimensions = false,
		public readonly bool $supports_place_items = false,
		public readonly bool $supports_international = false
	) {
	}

	/**
	 * @return array<string,bool>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			(bool) ( $data['supports_quotes'] ?? false ),
			(bool) ( $data['supports_pickup_points'] ?? false ),
			(bool) ( $data['supports_courier_delivery'] ?? false ),
			(bool) ( $data['supports_pickup_delivery'] ?? false ),
			(bool) ( $data['supports_shipment_creation'] ?? false ),
			(bool) ( $data['supports_status_sync'] ?? false ),
			(bool) ( $data['supports_documents'] ?? false ),
			(bool) ( $data['supports_multi_place'] ?? false ),
			(bool) ( $data['supports_itemized_places'] ?? false ),
			(bool) ( $data['supports_place_declared_value'] ?? false ),
			(bool) ( $data['supports_place_dimensions'] ?? false ),
			(bool) ( $data['supports_place_items'] ?? false ),
			(bool) ( $data['supports_international'] ?? false )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		return array();
	}
}
