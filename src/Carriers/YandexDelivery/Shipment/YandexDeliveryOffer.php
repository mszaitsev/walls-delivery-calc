<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

use WallsShop\WDC\Domain\Common\MoneyParser;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryOffer {
	/** @param array<string,mixed> $raw */
	public function __construct(
		public readonly string $offer_id,
		public readonly string $last_mile_policy,
		public readonly ?string $delivery_interval_min,
		public readonly ?string $delivery_interval_max,
		public readonly ?string $pickup_interval_min,
		public readonly ?string $pickup_interval_max,
		public readonly int $pricing_total_kopecks,
		public readonly string $expires_at = '',
		public readonly string $pricing = '',
		public readonly string $station_id = '',
		public readonly array $features = array(),
		public readonly array $raw = array()
	) {
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): self {
		$details = is_array( $data['offer_details'] ?? null ) ? $data['offer_details'] : $data;
		$delivery = is_array( $details['delivery_interval'] ?? null ) ? $details['delivery_interval'] : array();
		$pickup = is_array( $details['pickup_interval'] ?? null ) ? $details['pickup_interval'] : array();

		return new self(
			trim( (string) ( $data['offer_id'] ?? $data['id'] ?? '' ) ),
			trim( (string) ( $delivery['policy'] ?? $details['last_mile_policy'] ?? $details['policy'] ?? $data['last_mile_policy'] ?? $data['policy'] ?? '' ) ),
			self::string_or_null( $delivery['min'] ?? $details['delivery_interval_min'] ?? $data['delivery_interval_min'] ?? null ),
			self::string_or_null( $delivery['max'] ?? $details['delivery_interval_max'] ?? $data['delivery_interval_max'] ?? null ),
			self::string_or_null( $pickup['min'] ?? $details['pickup_interval_min'] ?? $data['pickup_interval_min'] ?? null ),
			self::string_or_null( $pickup['max'] ?? $details['pickup_interval_max'] ?? $data['pickup_interval_max'] ?? null ),
			self::price_kopecks( $details['pricing_total'] ?? $data['pricing_total'] ?? $details['pricing'] ?? $data['price'] ?? $data['total_price'] ?? 0 ),
			trim( (string) ( $data['expires_at'] ?? '' ) ),
			trim( (string) ( $details['pricing'] ?? '' ) ),
			trim( (string) ( $data['station_id'] ?? $details['station_id'] ?? '' ) ),
			is_array( $details['features'] ?? null ) ? $details['features'] : array(),
			$data
		);
	}

	public function is_valid(): bool {
		return '' !== $this->offer_id
			&& '' !== $this->last_mile_policy
			&& null !== $this->delivery_interval_min
			&& null !== $this->delivery_interval_max;
	}

	private static function string_or_null( mixed $value ): ?string {
		return is_scalar( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : null;
	}

	private static function price_kopecks( mixed $value ): int {
		if ( is_int( $value ) ) {
			return max( 0, $value );
		}
		$kopecks = MoneyParser::first_decimal_to_kopecks( $value );

		return null !== $kopecks ? max( 0, $kopecks ) : 0;
	}
}
