<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Manual;

use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderInterface;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;

defined( 'ABSPATH' ) || exit;

final class ManualPickupPointProvider implements CarrierPickupPointProviderInterface {
	public function __construct(
		private DeliveryServiceRepository $services,
		private ManualPickupPointRepository $points
	) {
	}

	public function carrier_key(): string {
		return ManualDeliverySettings::CARRIER_KEY;
	}

	/** @return array<int,PickupPoint> */
	public function search( CarrierPickupPointQuery $query ): array {
		$service = $this->service_for_query( $query );
		if ( ! $service instanceof DeliveryService ) {
			return array();
		}
		$destination = $this->destination_from_query( $query );
		if ( null === $destination ) {
			return array();
		}

		$result = array();
		foreach ( $this->points->active_points_for_destination( (int) $service->id, $destination['country_code'], $destination['region_name'], $destination['location_name'], $query->limit ) as $row ) {
			$point = $this->points->to_pickup_point( $row, $service );
			if ( $point instanceof PickupPoint ) {
				$result[] = $point;
			}
		}

		return $result;
	}

	public function resolve_selection( CarrierPickupPointSelectionQuery $query ): ?PickupPoint {
		$service = $this->service_for_query( $query->query );
		if ( ! $service instanceof DeliveryService ) {
			return null;
		}
		$destination = $this->destination_from_query( $query->query );
		if ( null === $destination ) {
			return null;
		}
		$row = $this->points->find_active_by_code( (int) $service->id, $query->point_code );
		if ( ! is_array( $row ) ) {
			return null;
		}
		if (
			$row['country_code'] !== $destination['country_code']
			|| $this->geography_key( $row['region_name'] ) !== $this->geography_key( $destination['region_name'] )
			|| $this->geography_key( $row['location_name'] ) !== $this->geography_key( $destination['location_name'] )
		) {
			return null;
		}

		return $this->points->to_pickup_point( $row, $service );
	}

	private function service_for_query( CarrierPickupPointQuery $query ): ?DeliveryService {
		if ( ManualDeliverySettings::CARRIER_KEY !== $query->normalized_carrier_key() || array() !== $query->validate() ) {
			return null;
		}
		$service_key = $query->normalized_service_key();
		$service = '' !== $service_key ? $this->services->find_by_service_key( $service_key ) : null;

		return $service instanceof DeliveryService
			&& null !== $service->id
			&& $service->enabled
			&& ! $service->deleted
			&& ManualDeliverySettings::CARRIER_KEY === $service->carrier_key
			&& DeliveryService::TYPE_MANUAL === $service->service_type
				? $service
				: null;
	}

	/** @return array{country_code:string,region_name:string,location_name:string}|null */
	private function destination_from_query( CarrierPickupPointQuery $query ): ?array {
		$country = $query->normalized_country_code();
		$region = trim( $query->region_name );
		$location = trim( $query->location_name );
		if ( '' === $country || '' === $region || '' === $location ) {
			return null;
		}

		return array(
			'country_code' => $country,
			'region_name' => $region,
			'location_name' => $location,
		);
	}

	private function geography_key( string $value ): string {
		$value = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), trim( $value ) );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/\s+/u', ' ', $value );

		return is_string( $value ) ? $value : '';
	}
}
