<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryCourierAddressMapper {
	/** @return array{courier:array{coordinates:array{latitude:float,longitude:float}}} */
	public function delivery( QuoteRequest $request ): array {
		$lat = $this->number_context( $request, array( 'destination_latitude', 'selected_location_latitude', 'latitude', 'lat' ), -90, 90 );
		$lng = $this->number_context( $request, array( 'destination_longitude', 'selected_location_longitude', 'longitude', 'lng', 'lon' ), -180, 180 );
		if ( null === $lat || null === $lng ) {
			throw new OzonDeliveryQuoteException( 'ozon_courier_coordinates_missing', 'order_checkout', 0, 'Для расчета Ozon курьером нужны координаты адреса доставки.' );
		}

		return array(
			'courier' => array(
				'coordinates' => array(
					'latitude'  => $lat,
					'longitude' => $lng,
				),
			),
		);
	}

	public function fingerprint( QuoteRequest $request ): string {
		$context = $request->customer_context;
		$parts = array();
		foreach ( array(
			'country_code',
			'selected_location_id',
			'fias_id',
			'gar_id',
			'region_name',
			'city_name',
			'settlement_name',
			'postcode',
			'street',
			'house',
			'apartment',
			'destination_latitude',
			'destination_longitude',
		) as $key ) {
			$value = $this->normalized_value( $context[ $key ] ?? '' );
			if ( '' !== $value ) {
				$parts[] = $key . '=' . $value;
			}
		}

		return implode( '|', $parts );
	}

	/**
	 * @param array<int,string> $keys
	 */
	private function number_context( QuoteRequest $request, array $keys, float $min, float $max ): ?float {
		foreach ( $keys as $key ) {
			$value = $request->customer_context[ $key ] ?? null;
			if ( is_string( $value ) ) {
				$value = str_replace( ',', '.', trim( $value ) );
			}
			if ( is_numeric( $value ) ) {
				$number = (float) $value;
				if ( $number >= $min && $number <= $max ) {
					return $number;
				}
			}
		}

		return null;
	}

	private function normalized_value( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$text = strtolower( trim( (string) $value ) );
		return preg_replace( '/\s+/u', ' ', $text ) ?? $text;
	}
}
