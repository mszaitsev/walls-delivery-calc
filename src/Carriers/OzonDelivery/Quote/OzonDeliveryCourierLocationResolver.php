<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryCourierLocationResolver {
	public const ERROR_CODE = 'ozon_courier_location_coordinates_missing';

	public function __construct( private LocationRepository $locations ) {}

	public function resolve( QuoteRequest $request ): OzonDeliveryCourierLocation {
		$location_id = max( 0, (int) ( $request->customer_context['selected_location_id'] ?? 0 ) );
		if ( $location_id <= 0 ) {
			throw $this->missing( $location_id );
		}

		$location = $this->locations->find_by_id( $location_id );
		if ( ! $location instanceof Location || ! $location->active || 'RU' !== strtoupper( trim( $location->country_code ) ) ) {
			throw $this->missing( $location_id );
		}

		$latitude = $location->latitude;
		$longitude = $location->longitude;
		if (
			null === $latitude
			|| null === $longitude
			|| $latitude < -90.0
			|| $latitude > 90.0
			|| $longitude < -180.0
			|| $longitude > 180.0
			|| ( 0.0 === $latitude && 0.0 === $longitude )
		) {
			throw $this->missing( $location_id );
		}

		return new OzonDeliveryCourierLocation( $location_id, $latitude, $longitude );
	}

	private function missing( int $location_id ): OzonDeliveryQuoteException {
		return new OzonDeliveryQuoteException(
			self::ERROR_CODE,
			'order_checkout',
			0,
			'Для расчета Ozon курьером нужны координаты выбранного населенного пункта.',
			array_filter(
				array(
					'courier_coordinate_source' => 'location_repository',
					'courier_location_id' => $location_id,
				),
				static fn( mixed $value ): bool => 0 !== $value && '' !== $value
			)
		);
	}
}
