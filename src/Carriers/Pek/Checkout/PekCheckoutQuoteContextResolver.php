<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Checkout;

use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\Geography\PekAddressBuilder;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationResolver;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Pickup\PekCheckoutPickupPointFormatter;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteOptions;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuotePlannedDateTimeResolver;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

defined( 'ABSPATH' ) || exit;

final class PekCheckoutQuoteContextResolver {
	public function __construct(
		private PekSettings $settings,
		private LocationRepository $locations,
		private PekLocationResolver $location_resolver,
		private PekAddressBuilder $address_builder,
		private CarrierPickupPointProviderRegistry $pickup_providers,
		private PekQuotePlannedDateTimeResolver $planned_datetime,
		private PekCheckoutPickupPointFormatter $formatter
	) {
	}

	/** @return array<string,mixed> */
	public function resolve( QuoteRequest $request ): array {
		$location_id = $this->location_id( $request );
		$location = $location_id > 0 ? $this->locations->find_by_id( $location_id ) : null;
		if ( ! $location instanceof Location || ! $location->active ) {
			throw new PekApiException( 'Для расчёта ПЭК выберите населённый пункт.', array( 'error_code' => 'pek_checkout_location_missing', 'failure_stage' => 'checkout_context' ) );
		}
		if ( 'RU' !== strtoupper( trim( $request->country_code ?: $location->country_code ) ) || 'RU' !== strtoupper( trim( $location->country_code ) ) ) {
			throw new PekApiException( 'ПЭК checkout runtime поддерживает только RU.', array( 'error_code' => 'pek_checkout_country_not_supported', 'failure_stage' => 'checkout_context' ) );
		}
		$mapping = $this->location_resolver->resolve( $location_id );
		if ( ! in_array( (string) ( $mapping['mapping_state'] ?? '' ), array( 'resolved', 'near' ), true ) ) {
			throw new PekApiException( 'ПЭК не подтвердил направление доставки.', array( 'error_code' => 'pek_checkout_location_unresolved', 'failure_stage' => 'checkout_context' ) );
		}
		$fingerprint = $this->destination_fingerprint( $location, $mapping );
		$query = $this->pickup_query( $request, $location, $mapping, $fingerprint );
		$selection = $this->trusted_selection( $request, $fingerprint );
		$planned = $this->planned_datetime->resolve();
		$pickup_options = array();
		$pickup_error = array();
		try {
			$pickup_options = $this->pickup_options( $planned, $query, $mapping, $selection, $fingerprint );
		} catch ( PekApiException $exception ) {
			$pickup_error = array(
				'success' => false,
				'error_code' => (string) ( $exception->context()['error_code'] ?? 'pek_checkout_pickup_options_missing' ),
				'failure_stage' => (string) ( $exception->context()['failure_stage'] ?? 'checkout_context' ),
			);
		}

		return array(
			'location' => $location,
			'location_id' => $location_id,
			'location_mapping' => $mapping,
			'destination_fingerprint' => $fingerprint,
			'pickup_query' => $query,
			'pickup_provider_query' => $this->safe_query_snapshot( $query, $fingerprint ),
			'selection' => $selection,
			'plannedDateTime' => $planned,
			'pickup_options' => $pickup_options,
			'pickup_options_error' => $pickup_error,
			'courier_options' => $this->courier_options( $request, $location, $mapping, $planned ),
		);
	}

	/** @return array<string,mixed>|null */
	public function trusted_selection( QuoteRequest $request, string $destination_fingerprint ): ?array {
		$selections = is_array( $request->customer_context['pickup_selections'] ?? null ) ? $request->customer_context['pickup_selections'] : array();
		$selection = is_array( $selections[ PekSettings::PICKUP_FAMILY ] ?? null ) ? $selections[ PekSettings::PICKUP_FAMILY ] : array();
		if ( array() === $selection && is_array( $request->customer_context['pickup_selection'] ?? null ) ) {
			$selection = $request->customer_context['pickup_selection'];
		}
		if ( array() === $selection ) {
			return null;
		}
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$carrier = (string) ( $selection['carrier_key'] ?? $snapshot['carrier_key'] ?? '' );
		$service = (string) ( $selection['service_key'] ?? $snapshot['service_key'] ?? '' );
		$family = (string) ( $selection['pickup_family'] ?? $snapshot['pickup_family'] ?? '' );
		$code = trim( (string) ( $selection['point_code'] ?? $selection['point_id'] ?? $snapshot['point_code'] ?? '' ) );
		$stored_fingerprint = (string) ( $selection['provider_destination_fingerprint'] ?? $snapshot['provider_destination_fingerprint'] ?? '' );
		if ( '' === $stored_fingerprint ) {
			$legacy = (string) ( $selection['destination_fingerprint'] ?? $snapshot['destination_fingerprint'] ?? '' );
			$stored_fingerprint = $this->looks_like_provider_fingerprint( $legacy ) ? $legacy : '';
		}
		if ( PekSettings::CARRIER_KEY !== $carrier || PekSettings::SERVICE_KEY !== $service || PekSettings::PICKUP_FAMILY !== $family || '' === $code ) {
			return null;
		}
		if ( '' === $stored_fingerprint || ! hash_equals( $destination_fingerprint, $stored_fingerprint ) ) {
			return null;
		}
		$selection['point_code'] = $code;

		return $selection;
	}

	/** @return array<string,mixed> */
	public function safe_query_snapshot( CarrierPickupPointQuery $query, string $destination_fingerprint ): array {
		return array(
			'carrier_key' => PekSettings::CARRIER_KEY,
			'purpose' => $query->purpose,
			'location_id' => $query->location_id,
			'country_code' => $query->normalized_country_code(),
			'fallback_address_fingerprint' => '' !== trim( $query->fallback_address ) ? hash( 'sha256', $query->fallback_address ) : '',
			'latitude' => $query->latitude,
			'longitude' => $query->longitude,
			'cargo' => $query->cargo->to_array(),
			'radius_km' => $query->radius_km,
			'limit' => $query->limit,
			'destination_fingerprint' => $destination_fingerprint,
			'provider_destination_fingerprint' => $destination_fingerprint,
		);
	}

	public function query_from_snapshot( array $snapshot ): ?CarrierPickupPointQuery {
		$cargo = is_array( $snapshot['cargo'] ?? null ) ? $snapshot['cargo'] : array();
		$query = new CarrierPickupPointQuery(
			PekSettings::CARRIER_KEY,
			(int) ( $snapshot['location_id'] ?? 0 ),
			(string) ( $snapshot['country_code'] ?? 'RU' ),
			'',
			is_numeric( $snapshot['latitude'] ?? null ) ? (float) $snapshot['latitude'] : null,
			is_numeric( $snapshot['longitude'] ?? null ) ? (float) $snapshot['longitude'] : null,
			new PickupCargoConstraints(
				(int) ( $cargo['weight_g'] ?? 0 ),
				(int) ( $cargo['volume_cm3'] ?? 0 ),
				(int) ( $cargo['max_dimension_cm'] ?? 0 ),
				(int) ( $cargo['max_place_weight_g'] ?? 0 ),
				max( 1, (int) ( $cargo['places_count'] ?? 1 ) )
			),
			CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP,
			max( 1, (int) ( $snapshot['radius_km'] ?? $this->settings->pek_destination_terminal_search_radius() ) ),
			max( 1, (int) ( $snapshot['limit'] ?? $this->settings->pek_destination_terminal_search_limit() ) )
		);

		return array() === $query->validate() ? $query : null;
	}

	/** @param array<string,mixed> $snapshot */
	public function destination_fingerprint_from_snapshot( array $snapshot ): string {
		return (string) ( $snapshot['destination_fingerprint'] ?? '' );
	}

	private function looks_like_provider_fingerprint( string $value ): bool {
		return 64 === strlen( $value ) && ctype_xdigit( $value );
	}

	/** @return array<string,mixed> */
	private function pickup_options( string $planned, CarrierPickupPointQuery $query, array $mapping, ?array $selection, string $fingerprint ): array {
		if ( is_array( $selection ) && '' !== trim( (string) ( $selection['point_code'] ?? '' ) ) ) {
			return array(
				'options' => new PekQuoteOptions( PekQuoteOptions::MODE_PICKUP, $planned, (string) $selection['point_code'] ),
				'warehouse_id' => (string) $selection['point_code'],
				'warehouse_source' => 'selection',
				'selected' => true,
			);
		}
		$provider = $this->pickup_providers->get( PekSettings::CARRIER_KEY );
		$points = null !== $provider ? $provider->search( $query ) : array();
		if ( array() === $points ) {
			throw new PekApiException( 'Подходящие терминалы ПЭК не найдены.', array( 'error_code' => 'pek_checkout_pickup_points_missing', 'failure_stage' => 'checkout_context' ) );
		}
		$main = trim( (string) ( $mapping['main_warehouse_id'] ?? '' ) );
		$chosen = null;
		if ( '' !== $main ) {
			foreach ( $points as $point ) {
				if ( $point instanceof PickupPoint && $point->code === $main ) {
					$chosen = $point;
					break;
				}
			}
		}
		if ( ! $chosen instanceof PickupPoint ) {
			foreach ( $points as $point ) {
				if ( $point instanceof PickupPoint && 'free' === (string) ( $point->raw_reference['source'] ?? '' ) ) {
					$chosen = $point;
					break;
				}
			}
		}
		$chosen = $chosen instanceof PickupPoint ? $chosen : $points[0];

		return array(
			'options' => new PekQuoteOptions( PekQuoteOptions::MODE_PICKUP, $planned, $chosen->code ),
			'warehouse_id' => $chosen->code,
			'warehouse_source' => $chosen->code === $main ? 'mapping_main_warehouse' : ( (string) ( $chosen->raw_reference['source'] ?? '' ) ?: 'provider_first' ),
			'selected' => false,
			'preliminary_point' => $this->formatter->format( $chosen, $fingerprint, $query->location_id, $query->country_code ),
		);
	}

	/** @return array<string,mixed> */
	private function courier_options( QuoteRequest $request, Location $location, array $mapping, string $planned ): array {
		$full = $this->full_courier_address( $request );
		if ( '' !== $full ) {
			return array(
				'options' => new PekQuoteOptions( PekQuoteOptions::MODE_COURIER, $planned, '', $full ),
				'scope' => 'full_address',
				'address_fingerprint' => hash( 'sha256', $full ),
			);
		}
		$address = trim( (string) ( $mapping['normalized_address'] ?? '' ) );
		if ( '' === $address ) {
			$address = $this->address_builder->build( $location );
		}
		$lat = is_numeric( $mapping['latitude'] ?? null ) ? (float) $mapping['latitude'] : null;
		$lng = is_numeric( $mapping['longitude'] ?? null ) ? (float) $mapping['longitude'] : null;

		return array(
			'options' => new PekQuoteOptions( PekQuoteOptions::MODE_COURIER, $planned, '', $address, $lat, $lng ),
			'scope' => 'location',
			'address_fingerprint' => hash( 'sha256', $address ),
		);
	}

	private function location_id( QuoteRequest $request ): int {
		foreach ( array( 'selected_location_id', 'location_id' ) as $key ) {
			$value = $request->customer_context[ $key ] ?? null;
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}
		}

		return 0;
	}

	private function pickup_query( QuoteRequest $request, Location $location, array $mapping, string $fingerprint ): CarrierPickupPointQuery {
		unset( $fingerprint );
		return new CarrierPickupPointQuery(
			PekSettings::CARRIER_KEY,
			(int) $location->id,
			'RU',
			(string) ( $mapping['normalized_address'] ?? $this->address_builder->build( $location ) ),
			is_numeric( $mapping['latitude'] ?? null ) ? (float) $mapping['latitude'] : null,
			is_numeric( $mapping['longitude'] ?? null ) ? (float) $mapping['longitude'] : null,
			$this->cargo_constraints( $request->package ),
			CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP,
			$this->settings->pek_destination_terminal_search_radius(),
			$this->settings->pek_destination_terminal_search_limit()
		);
	}

	private function cargo_constraints( Package $package ): PickupCargoConstraints {
		$weight = max( 0, $package->total_weight_g > 0 ? $package->total_weight_g : $package->get_total_weight_g() );
		$volume = max( 0, $package->get_total_volume_cm3() );
		$max_dimension = max( 0, (int) ( $package->length_cm ?? 0 ), (int) ( $package->width_cm ?? 0 ), (int) ( $package->height_cm ?? 0 ) );

		return new PickupCargoConstraints( $weight, $volume, $max_dimension, $weight, 1 );
	}

	private function destination_fingerprint( Location $location, array $mapping ): string {
		return hash( 'sha256', implode( '|', array(
			'country=' . strtoupper( $location->country_code ),
			'id=' . (string) $location->id,
			'fias=' . $location->fias_id,
			'gar=' . (string) $location->gar_object_id,
			'mapping=' . (string) ( $mapping['address_fingerprint'] ?? '' ),
		) ) );
	}

	private function full_courier_address( QuoteRequest $request ): string {
		$destination = $request->destination;
		$street = trim( $destination->street );
		$house = trim( $destination->house );
		if ( '' !== $street && '' !== $house ) {
			return trim( implode( ', ', array_filter( array(
				'Россия',
				$destination->region_name,
				$destination->city ?: $destination->settlement,
				$street,
				$house,
				$destination->apartment,
			), static fn( string $part ): bool => '' !== trim( $part ) ) ) );
		}
		$raw = trim( $destination->raw_address );
		return strlen( $raw ) >= 12 ? $raw : '';
	}
}
