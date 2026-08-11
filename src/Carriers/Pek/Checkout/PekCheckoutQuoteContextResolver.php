<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Checkout;

use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\Geography\PekAddressBuilder;
use WallsShop\WDC\Carriers\Pek\Geography\PekLocationResolver;
use WallsShop\WDC\Carriers\Pek\PekCountryPolicy;
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
		private PekCheckoutPickupPointFormatter $formatter,
		private PekCountryPolicy $countries
	) {
	}

	/** @return array<string,mixed> */
	public function resolve( QuoteRequest $request ): array {
		$location_id = $this->location_id( $request );
		$location = $location_id > 0 ? $this->locations->find_by_id( $location_id ) : null;
		if ( ! $location instanceof Location || ! $location->active ) {
			throw new PekApiException( 'Для расчёта ПЭК выберите населённый пункт.', array( 'error_code' => 'pek_checkout_location_missing', 'failure_stage' => 'checkout_context' ) );
		}
		$receiver_country = strtoupper( trim( $request->country_code ?: $location->country_code ) );
		if ( ! $this->countries->supports_calculation_direction( $this->countries->sender_country(), $receiver_country ) || $receiver_country !== strtoupper( trim( $location->country_code ) ) ) {
			throw new PekApiException( 'ПЭК не поддерживает выбранное направление.', array( 'error_code' => 'pek_checkout_country_not_supported', 'failure_stage' => 'checkout_context', 'country_code' => $receiver_country, 'direction_supported' => false ) );
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
		$pickup_preliminary_options = array();
		$pickup_error = array();
		$pickup_preliminary_error = array();
		try {
			$pickup_preliminary_options = $this->preliminary_pickup_options( $planned, $query, $mapping, $fingerprint );
		} catch ( PekApiException $exception ) {
			$pickup_preliminary_error = $this->pickup_options_error_from_exception( $exception );
		}
		if ( is_array( $selection ) && '' !== trim( (string) ( $selection['point_code'] ?? '' ) ) ) {
			$pickup_options = $this->selected_pickup_options( $planned, $selection );
		} else {
			$pickup_options = $pickup_preliminary_options;
			$pickup_error = $pickup_preliminary_error;
		}

		return array(
			'location' => $location,
			'country_code' => $receiver_country,
			'location_id' => $location_id,
			'location_mapping' => $mapping,
			'destination_fingerprint' => $fingerprint,
			'pickup_query' => $query,
			'pickup_provider_query' => $this->safe_query_snapshot( $query, $fingerprint ),
			'selection' => $selection,
			'plannedDateTime' => $planned,
			'pickup_options' => $pickup_options,
			'pickup_options_error' => $pickup_error,
			'pickup_preliminary_options' => $pickup_preliminary_options,
			'pickup_preliminary_options_error' => $pickup_preliminary_error,
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
			(string) ( $snapshot['country_code'] ?? '' ),
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

	/** @param array<string,mixed> $selection @return array<string,mixed> */
	private function selected_pickup_options( string $planned, array $selection ): array {
		return array(
			'options' => new PekQuoteOptions( PekQuoteOptions::MODE_PICKUP, $planned, (string) $selection['point_code'] ),
			'warehouse_id' => (string) $selection['point_code'],
			'warehouse_source' => 'selection',
			'selected' => true,
		);
	}

	/** @return array<string,mixed> */
	private function preliminary_pickup_options( string $planned, CarrierPickupPointQuery $query, array $mapping, string $fingerprint ): array {
		$provider = $this->pickup_providers->get( PekSettings::CARRIER_KEY );
		try {
			$points = null !== $provider ? $provider->search( $query ) : array();
		} catch ( PekApiException $exception ) {
			throw new PekApiException(
				$exception->getMessage(),
				array_merge( $exception->context(), $this->pickup_provider_context( $provider ) )
			);
		}
		if ( array() === $points ) {
			throw new PekApiException(
				'Подходящие терминалы ПЭК не найдены.',
				array_merge(
					$this->pickup_provider_context( $provider ),
					array(
						'error_code' => 'pek_checkout_pickup_points_missing',
						'failure_stage' => 'checkout_context',
					)
				)
			);
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
			strtoupper( trim( $location->country_code ) ),
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
				$this->country_name( $destination->country_code ),
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

	private function country_name( string $country_code ): string {
		return array(
			'RU' => 'Россия',
			'AM' => 'Армения',
			'BY' => 'Беларусь',
			'KG' => 'Кыргызстан',
			'KZ' => 'Казахстан',
		)[ strtoupper( trim( $country_code ) ) ] ?? strtoupper( trim( $country_code ) );
	}

	/** @return array<string,mixed> */
	private function pickup_options_error_from_exception( PekApiException $exception ): array {
		$context = $exception->context();
		$error = array(
			'success' => false,
			'error_code' => $this->safe_token( (string) ( $context['error_code'] ?? 'pek_checkout_pickup_options_missing' ) ),
			'failure_stage' => $this->safe_token( (string) ( $context['failure_stage'] ?? 'checkout_context' ) ),
		);
		foreach ( array( 'endpoint', 'method', 'http_status', 'api_error_message', 'cache_hit', 'api_source', 'response_shape', 'field_errors' ) as $key ) {
			if ( array_key_exists( $key, $context ) ) {
				$error[ $key ] = $context[ $key ];
			}
		}

		return $this->safe_pickup_diagnostic( $error );
	}

	/** @return array<string,mixed> */
	private function pickup_provider_context( mixed $provider ): array {
		if ( ! is_object( $provider ) || ! method_exists( $provider, 'last_report' ) ) {
			return array();
		}
		$report = $provider->last_report();
		return is_array( $report ) ? $this->safe_pickup_diagnostic( $report ) : array();
	}

	/** @param array<string,mixed> $diagnostic @return array<string,mixed> */
	private function safe_pickup_diagnostic( array $diagnostic ): array {
		$result = array();
		foreach ( array( 'success', 'cache_hit' ) as $key ) {
			if ( array_key_exists( $key, $diagnostic ) ) {
				$result[ $key ] = (bool) $diagnostic[ $key ];
			}
		}
		foreach ( array( 'error_code', 'failure_stage', 'api_source' ) as $key ) {
			if ( array_key_exists( $key, $diagnostic ) ) {
				$result[ $key ] = $this->safe_token( (string) $diagnostic[ $key ] );
			}
		}
		if ( array_key_exists( 'method', $diagnostic ) ) {
			$method = strtoupper( trim( (string) $diagnostic['method'] ) );
			$result['method'] = in_array( $method, array( 'GET', 'POST' ), true ) ? $method : '';
		}
		if ( array_key_exists( 'endpoint', $diagnostic ) ) {
			$result['endpoint'] = $this->safe_endpoint( (string) $diagnostic['endpoint'] );
		}
		if ( array_key_exists( 'http_status', $diagnostic ) ) {
			$result['http_status'] = is_numeric( $diagnostic['http_status'] ) ? max( 0, min( 599, (int) $diagnostic['http_status'] ) ) : '';
		}
		if ( array_key_exists( 'api_error_message', $diagnostic ) ) {
			$result['api_error_message'] = $this->safe_message( (string) $diagnostic['api_error_message'] );
		}
		if ( is_array( $diagnostic['response_shape'] ?? null ) ) {
			$result['response_shape'] = $diagnostic['response_shape'];
		}
		if ( is_array( $diagnostic['field_errors'] ?? null ) ) {
			$result['field_errors'] = $diagnostic['field_errors'];
		}

		return $result;
	}

	private function safe_token( string $value ): string {
		$value = strtolower( trim( $value ) );
		return 1 === preg_match( '/^[a-z0-9_:-]{0,100}$/', $value ) ? $value : '';
	}

	private function safe_endpoint( string $value ): string {
		$value = trim( $value );
		return 1 === preg_match( '#^/[a-z0-9/_-]{1,180}$#i', $value ) ? $value : '';
	}

	private function safe_message( string $value ): string {
		$value = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/Basic\s+[A-Za-z0-9+\/=]+/i', 'Basic [redacted]', $value ) ?? $value;
		$value = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[redacted]', $value ) ?? $value;
		$value = preg_replace( '/(?<!\w)\+?7[\s().-]*\d[\d\s().-]{8,}\d(?!\w)/u', '[redacted]', $value ) ?? $value;
		$value = trim( $value );
		if ( function_exists( 'mb_substr' ) ) {
			$value = mb_substr( $value, 0, 500 );
		} else {
			$value = substr( $value, 0, 500 );
		}

		return trim( $value );
	}
}
