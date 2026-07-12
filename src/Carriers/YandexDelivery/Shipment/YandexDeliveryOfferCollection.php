<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryOfferCollection {
	/**
	 * @param array<int,YandexDeliveryOffer> $offers
	 * @param array<string,mixed> $raw
	 */
	public function __construct(
		public readonly array $offers,
		public readonly array $raw = array()
	) {
	}

	/** @param array<string,mixed> $response */
	public static function from_api_response( array $response ): self {
		$body = is_array( $response['body'] ?? null ) ? $response['body'] : $response;
		$rows = is_array( $body['offers'] ?? null ) ? $body['offers'] : ( is_array( $body['available_offers'] ?? null ) ? $body['available_offers'] : array() );
		$offers = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$offer = YandexDeliveryOffer::from_array( $row );
				if ( $offer->is_valid() ) {
					$offers[] = $offer;
				}
			}
		}

		return new self( $offers, $body );
	}

	public function is_empty(): bool {
		return array() === $this->offers;
	}
}
