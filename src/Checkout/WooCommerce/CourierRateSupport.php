<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;

defined( 'ABSPATH' ) || exit;

final class CourierRateSupport {
	/**
	 * @param array<string,mixed> $meta
	 */
	public static function delivery_kind_from_meta( array $meta ): string {
		foreach ( array( 'wdc_delivery_kind', 'delivery_kind', 'delivery_type', 'fulfillment_type', 'service_kind' ) as $key ) {
			$value = strtolower( trim( (string) ( $meta[ $key ] ?? '' ) ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		$rate_meta = $meta['rate_meta'] ?? array();
		if ( is_array( $rate_meta ) ) {
			foreach ( array( 'wdc_delivery_kind', 'delivery_kind', 'delivery_type', 'fulfillment_type', 'service_kind' ) as $key ) {
				$value = strtolower( trim( (string) ( $rate_meta[ $key ] ?? '' ) ) );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		return DeliveryType::UNKNOWN;
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	public static function is_courier_meta( array $meta ): bool {
		if ( ! empty( $meta['is_courier'] ) ) {
			return true;
		}

		$rate_meta = $meta['rate_meta'] ?? array();
		if ( is_array( $rate_meta ) && ! empty( $rate_meta['is_courier'] ) ) {
			return true;
		}

		return DeliveryType::COURIER === self::delivery_kind_from_meta( $meta );
	}

	public static function delivery_kind_for_rate( DeliveryRate $rate ): string {
		$meta_kind = self::delivery_kind_from_meta( $rate->meta );
		if ( DeliveryType::UNKNOWN !== $meta_kind ) {
			return $meta_kind;
		}

		return DeliveryType::COURIER === $rate->delivery_type ? DeliveryType::COURIER : $rate->delivery_type;
	}

	public static function is_courier_rate( DeliveryRate $rate ): bool {
		return DeliveryType::COURIER === $rate->delivery_type
			|| $rate->requires_courier_address
			|| self::is_courier_meta( $rate->meta );
	}
}
