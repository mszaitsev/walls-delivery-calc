<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryEarliestOfferSelector {
	public function select( YandexDeliveryOfferCollection $offers, string $expected_policy ): ?YandexDeliveryOffer {
		$expected_policy = trim( $expected_policy );
		$filtered = array_values( array_filter(
			$offers->offers,
			static fn( YandexDeliveryOffer $offer ): bool => $expected_policy === $offer->last_mile_policy
		) );
		usort(
			$filtered,
			static fn( YandexDeliveryOffer $a, YandexDeliveryOffer $b ): int =>
				self::sort_time( $a->delivery_interval_min ) <=> self::sort_time( $b->delivery_interval_min )
				?: self::sort_time( $a->delivery_interval_max ) <=> self::sort_time( $b->delivery_interval_max )
				?: self::sort_time( $a->pickup_interval_max ) <=> self::sort_time( $b->pickup_interval_max )
				?: ( $a->pricing_total_kopecks <=> $b->pricing_total_kopecks )
				?: strcmp( $a->offer_id, $b->offer_id )
		);

		return $filtered[0] ?? null;
	}

	private static function sort_time( ?string $value ): float {
		if ( null === $value || '' === trim( $value ) ) {
			return INF;
		}
		try {
			return (float) ( new \DateTimeImmutable( trim( $value ) ) )->format( 'U.u' );
		} catch ( \Exception ) {
			return INF;
		}
	}
}
