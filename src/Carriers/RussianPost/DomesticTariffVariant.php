<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

use WallsShop\WDC\Domain\Quote\DeliveryType;

defined( 'ABSPATH' ) || exit;

final class DomesticTariffVariant {
	public function __construct(
		public readonly int $object_code,
		public readonly string $title,
		public readonly bool $enabled,
		public readonly string $delivery_type,
		public readonly bool $requires_declared_value,
		public readonly bool $always_available = false,
		public readonly ?int $min_weight_g = null,
		public readonly ?int $max_weight_g = null,
		public readonly int $sort_order = 100,
		public readonly string $admin_comment = ''
	) {
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		$delivery_type = (string) ( $data['delivery_type'] ?? DeliveryType::PICKUP );

		return new self(
			max( 1, (int) ( $data['object_code'] ?? $data['object'] ?? 0 ) ),
			(string) ( $data['title'] ?? '' ),
			(bool) ( $data['enabled'] ?? true ),
			DeliveryType::COURIER === $delivery_type ? DeliveryType::COURIER : DeliveryType::PICKUP,
			(bool) ( $data['requires_declared_value'] ?? false ),
			(bool) ( $data['always_available'] ?? false ),
			isset( $data['min_weight_g'] ) && '' !== (string) $data['min_weight_g'] ? max( 0, (int) $data['min_weight_g'] ) : null,
			isset( $data['max_weight_g'] ) && '' !== (string) $data['max_weight_g'] ? max( 0, (int) $data['max_weight_g'] ) : null,
			(int) ( $data['sort_order'] ?? 100 ),
			(string) ( $data['admin_comment'] ?? '' )
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'object_code' => $this->object_code,
			'title' => $this->title,
			'enabled' => $this->enabled,
			'delivery_type' => $this->delivery_type,
			'requires_declared_value' => $this->requires_declared_value,
			'always_available' => $this->always_available,
			'min_weight_g' => $this->min_weight_g,
			'max_weight_g' => $this->max_weight_g,
			'sort_order' => $this->sort_order,
			'admin_comment' => $this->admin_comment,
		);
	}

	public function supports_weight( int $weight_g ): bool {
		if ( null !== $this->min_weight_g && $weight_g < $this->min_weight_g ) {
			return false;
		}

		return null === $this->max_weight_g || $weight_g <= $this->max_weight_g;
	}
}
