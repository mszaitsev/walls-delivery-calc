<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationResolver;
use WallsShop\WDC\Carriers\Pek\Pickup\PekPickupPointProvider;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

defined( 'ABSPATH' ) || exit;

final class PekShipmentDestinationResolver {
	public function __construct( private PekPickupPointProvider $pickup_points, private PekLocationResolver $locations ) {
	}

	/** @return array<string,mixed> */
	public function resolve( ShipmentCreateRequest $request ): array {
		if ( DeliveryType::PICKUP === $request->delivery_type ) {
			return $this->resolve_pickup( $request );
		}

		return $this->resolve_courier( $request );
	}

	/** @return array<string,mixed> */
	private function resolve_pickup( ShipmentCreateRequest $request ): array {
		$code = trim( (string) ( $request->pickup_point?->point_code ?? $request->meta['pickup_point_code'] ?? '' ) );
		if ( '' === $code ) {
			throw new \RuntimeException( 'Не выбран терминал ПЭК.' );
		}
		$query_data = is_array( $request->meta['pickup_provider_query'] ?? null ) ? $request->meta['pickup_provider_query'] : array();
		$query = new CarrierPickupPointQuery(
			PekSettings::CARRIER_KEY,
			(int) ( $request->meta['pek_destination_location_id'] ?? $query_data['location_id'] ?? 0 ),
			'RU',
			(string) ( $query_data['fallback_address'] ?? $request->recipient_address->raw_address ),
			$this->nullable_float( $query_data['latitude'] ?? null ),
			$this->nullable_float( $query_data['longitude'] ?? null ),
			$this->constraints( $request ),
			CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP,
			max( 1, (int) ( $query_data['radius_km'] ?? 50 ) ),
			max( 1, (int) ( $query_data['limit'] ?? 50 ) )
		);
		$point = $this->pickup_points->resolve_selection( new CarrierPickupPointSelectionQuery( $query, $code ) );
		if ( null === $point || $point->code !== $code || ! $point->active ) {
			throw new \RuntimeException( 'Терминал ПЭК не прошёл свежую проверку.' );
		}
		$raw = is_array( $point->raw_reference ) ? $point->raw_reference : array();
		$branch_id = trim( (string) ( $raw['branchId'] ?? $raw['branch_id'] ?? $request->meta['pek_receiver_branch_id'] ?? '' ) );
		if ( '' === $branch_id ) {
			throw new \RuntimeException( 'Не подтверждён филиал назначения ПЭК для SMS.' );
		}

		return array(
			'mode' => DeliveryType::PICKUP,
			'warehouse_id' => $code,
			'branch_id' => $branch_id,
			'title' => (string) ( $raw['title'] ?? $point->city ),
			'address' => $point->address,
			'source' => (string) ( $raw['source'] ?? 'fresh_provider' ),
			'location_id' => $query->location_id,
			'provider_destination_fingerprint' => (string) ( $raw['provider_destination_fingerprint'] ?? $request->meta['provider_destination_fingerprint'] ?? '' ),
		);
	}

	/** @return array<string,mixed> */
	private function resolve_courier( ShipmentCreateRequest $request ): array {
		if ( 'RU' !== strtoupper( $request->recipient_address->country_code ) ) {
			throw new \RuntimeException( 'Создание отправлений ПЭК поддерживает только RU.' );
		}
		$location_id = (int) ( $request->meta['pek_destination_location_id'] ?? 0 );
		if ( $location_id <= 0 ) {
			throw new \RuntimeException( 'Не удалось подтвердить филиал назначения ПЭК.' );
		}
		$mapping = $this->locations->resolve( $location_id );
		$branch_id = trim( (string) ( $mapping['branch_id'] ?? '' ) );
		$country = strtoupper( trim( (string) ( $mapping['country_code'] ?? 'RU' ) ) );
		$state = (string) ( $mapping['mapping_state'] ?? '' );
		if ( 'RU' !== $country || '' === $branch_id || ! in_array( $state, array( 'resolved', 'near' ), true ) ) {
			throw new \RuntimeException( 'Не удалось подтвердить филиал назначения ПЭК.' );
		}
		$saved_branch = trim( (string) ( $request->meta['pek_receiver_branch_id'] ?? $request->meta['pek_destination_branch_id'] ?? '' ) );

		return array(
			'mode' => DeliveryType::COURIER,
			'warehouse_id' => '',
			'branch_id' => $branch_id,
			'title' => '',
			'address' => $request->recipient_address->raw_address,
			'source' => 'fresh_location_mapping',
			'location_id' => $location_id,
			'provider_destination_fingerprint' => (string) ( $mapping['address_fingerprint'] ?? $request->meta['provider_destination_fingerprint'] ?? '' ),
			'saved_branch_id' => $saved_branch,
			'branch_mismatch' => '' !== $saved_branch && $saved_branch !== $branch_id,
		);
	}

	private function constraints( ShipmentCreateRequest $request ): PickupCargoConstraints {
		$weight = 0;
		$volume = 0;
		$max_dimension = 0;
		$max_place_weight = 0;
		foreach ( $request->places as $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				continue;
			}
			$weight += max( 0, $place->weight_g );
			$volume += max( 0, $place->length_cm ) * max( 0, $place->width_cm ) * max( 0, $place->height_cm );
			$max_dimension = max( $max_dimension, $place->length_cm, $place->width_cm, $place->height_cm );
			$max_place_weight = max( $max_place_weight, $place->weight_g );
		}

		return new PickupCargoConstraints( $weight, $volume, $max_dimension, $max_place_weight, max( 1, count( $request->places ) ) );
	}

	private function nullable_float( mixed $value ): ?float {
		return null === $value || '' === trim( (string) $value ) ? null : (float) $value;
	}
}
