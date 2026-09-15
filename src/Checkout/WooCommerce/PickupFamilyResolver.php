<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\Runtime\YandexDeliveryCarrier;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;

defined( 'ABSPATH' ) || exit;

final class PickupFamilyResolver {
	public static function from_delivery_rate( DeliveryRate $rate ): string {
		$meta = array_merge(
			$rate->meta,
			array(
				'carrier_key' => $rate->carrier_key,
				'service_key' => $rate->service_key,
				'delivery_type' => $rate->delivery_type,
				'requires_pickup_point' => $rate->requires_pickup_point,
			)
		);

		return self::from_meta( $meta, $rate->rate_id );
	}

	/** @param array<string,mixed> $meta */
	public static function from_meta( array $meta, string $rate_id = '' ): string {
		$rate_meta = is_array( $meta['rate_meta'] ?? null ) ? $meta['rate_meta'] : array();
		$nested_meta = is_array( $meta['meta'] ?? null ) ? $meta['meta'] : array();
		$merged = array_replace( $nested_meta, $rate_meta, $meta );

		if ( DeliveryType::PICKUP !== (string) ( $merged['delivery_type'] ?? '' ) ) {
			return '';
		}

		$explicit = self::normalize_family( (string) ( $merged['pickup_family'] ?? '' ) );
		if ( '' !== $explicit ) {
			return $explicit;
		}

		if ( ! self::truthy( $merged['requires_pickup_point'] ?? false ) ) {
			return '';
		}

		$carrier = self::normalize_carrier( (string) ( $merged['carrier_key'] ?? $merged['carrier'] ?? '' ) );
		$service = trim( (string) ( $merged['service_key'] ?? '' ) );
		if ( '' !== $carrier ) {
			if ( '' !== $service && $service !== $carrier && DeliveryType::PICKUP !== $service ) {
				return self::normalize_family( $carrier . ':' . $service . ':pickup' );
			}

			return self::normalize_family( $carrier . ':pickup' );
		}

		return self::legacy_from_rate_id( $rate_id );
	}

	public static function legacy_from_rate_id( string $rate_id ): string {
		$rate_id = self::normalize_rate_id( $rate_id );
		if ( YandexDeliveryCarrier::PICKUP_RATE_ID === $rate_id ) {
			return YandexDeliverySettings::CARRIER_KEY . ':pickup';
		}

		$parts = explode( ':', $rate_id );
		$pickup_index = array_search( 'pickup', $parts, true );
		if ( false !== $pickup_index && $pickup_index > 0 ) {
			return self::normalize_family( (string) $parts[0] . ':pickup' );
		}

		return self::normalize_family( $rate_id );
	}

	public static function normalize_family( string $family ): string {
		$family = trim( $family );
		if ( '' === $family ) {
			return '';
		}
		$parts = explode( ':', $family );
		$carrier = self::normalize_carrier( (string) ( $parts[0] ?? '' ) );
		if ( '' !== $carrier ) {
			$parts[0] = $carrier;
		}

		return implode( ':', $parts );
	}

	public static function normalize_carrier( string $carrier ): string {
		$carrier = trim( $carrier );
		if ( 'russian_post' === $carrier ) {
			return RussianPostDomesticSettings::CARRIER_KEY;
		}

		return $carrier;
	}

	private static function normalize_rate_id( string $rate_id ): string {
		$rate_id = trim( $rate_id );
		$prefix = NewShippingMethod::METHOD_ID . ':';
		if ( str_starts_with( $rate_id, $prefix ) ) {
			$rate_id = substr( $rate_id, strlen( $prefix ) );
		}

		$platform_prefix = 'wdc_platform:';
		if ( str_starts_with( $rate_id, $platform_prefix ) ) {
			return substr( $rate_id, strlen( $platform_prefix ) );
		}

		return $rate_id;
	}

	private static function truthy( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'true' === $value;
	}
}
