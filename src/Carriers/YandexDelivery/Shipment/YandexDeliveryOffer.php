<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryOffer {
	/** @param array<string,mixed> $raw */
	public function __construct(
		public readonly string $offer_id,
		public readonly string $last_mile_policy,
		public readonly ?string $delivery_interval_min,
		public readonly ?string $delivery_interval_max,
		public readonly ?string $pickup_interval_max,
		public readonly int $pricing_total_kopecks,
		public readonly array $raw = array()
	) {
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): self {
		return new self(
			trim( (string) ( $data['offer_id'] ?? $data['id'] ?? '' ) ),
			trim( (string) ( $data['last_mile_policy'] ?? $data['policy'] ?? '' ) ),
			self::string_or_null( $data['delivery_interval']['min'] ?? $data['delivery_interval_min'] ?? null ),
			self::string_or_null( $data['delivery_interval']['max'] ?? $data['delivery_interval_max'] ?? null ),
			self::string_or_null( $data['pickup_interval']['max'] ?? $data['pickup_interval_max'] ?? null ),
			self::price_kopecks( $data['pricing_total'] ?? $data['price'] ?? $data['total_price'] ?? 0 ),
			$data
		);
	}

	private static function string_or_null( mixed $value ): ?string {
		return is_scalar( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : null;
	}

	private static function price_kopecks( mixed $value ): int {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}
		if ( is_float( $value ) ) {
			return max( 0, (int) round( $value * 100 ) );
		}
		$text = trim( (string) $value );
		if ( '' === $text ) {
			return 0;
		}
		if ( preg_match( '/([0-9]+(?:[\.,][0-9]+)?)/', $text, $matches ) ) {
			return max( 0, (int) round( (float) str_replace( ',', '.', $matches[1] ) * 100 ) );
		}

		return 0;
	}
}
