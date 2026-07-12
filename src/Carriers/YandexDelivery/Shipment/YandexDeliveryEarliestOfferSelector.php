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
				strcmp( self::sort_date( $a->delivery_interval_min ), self::sort_date( $b->delivery_interval_min ) )
				?: strcmp( self::sort_date( $a->delivery_interval_max ), self::sort_date( $b->delivery_interval_max ) )
				?: strcmp( self::sort_date( $a->pickup_interval_max ), self::sort_date( $b->pickup_interval_max ) )
				?: ( $a->pricing_total_kopecks <=> $b->pricing_total_kopecks )
				?: strcmp( $a->offer_id, $b->offer_id )
		);

		return $filtered[0] ?? null;
	}

	private static function sort_date( ?string $value ): string {
		return null !== $value && '' !== trim( $value ) ? trim( $value ) : '9999-12-31T23:59:59.999999Z';
	}
}
