<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiException;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryMessageSanitizer;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupPointProvider;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Packaging\PackagingBuilder;
use WallsShop\WDC\Packaging\PackagingException;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryQuoteService {
	public function __construct(
		private OzonDeliveryApiClient $api,
		private OzonDeliveryQuoteRequestBuilder $builder,
		private OzonDeliveryQuoteParser $parser,
		private PackagingBuilder $packaging,
		private OzonDeliveryPickupPointProvider $pickup_provider,
		private OzonDeliveryMessageSanitizer $sanitizer
	) {}

	public function quote_pickup( QuoteRequest $request ): OzonDeliveryQuoteResult {
		try {
			$packaging = $this->packaging->build( $request );
		} catch ( PackagingException $exception ) {
			throw new OzonDeliveryQuoteException( 'ozon_package_item_oversize', 'order_checkout', 0, 'Товары не помещаются в допустимое грузоместо Ozon.' );
		}
		$query = $this->pickup_query( $request, $packaging->to_array() );
		$selected_code = $this->selected_point_code( $request );
		$selected_point = '' !== $selected_code ? $this->selected_point( $query, $selected_code ) : null;
		if ( '' !== $selected_code && ! $selected_point instanceof PickupPoint ) {
			throw new OzonDeliveryQuoteException( 'ozon_selected_point_stale', 'order_checkout', 0, 'Выбранный ПВЗ Ozon больше недоступен.' );
		}
		$point = $selected_point ?? $this->representative_point( $query );
		if ( ! $point instanceof PickupPoint ) {
			throw new OzonDeliveryQuoteException( 'ozon_representative_point_missing', 'order_checkout', 0, 'Нет подходящего ПВЗ Ozon для расчета.', $this->representative_missing_details( $query ) );
		}
		$built = $this->builder->build( $request, $packaging, $point->code );
		try {
			$data = $this->api->order_checkout( $built['body'] );
			$result = $this->parser->parse( $data, $built['request_ids'], $point->code, (int) $built['diagnostics']['shipment_method_id'] );
			return new OzonDeliveryQuoteResult(
				$result->price,
				$result->delivery_days,
				$result->destination_point_id,
				$result->package_count,
				$result->shipment_method_id,
				$result->endpoint,
				$result->http_status,
				array_merge( $result->meta, $built['diagnostics'], $packaging->to_array(), array( 'pickup_source' => $selected_point instanceof PickupPoint ? 'selected' : 'representative', 'ozon_delivery_places' => $query->cargo->places, 'pickup_provider_query' => $this->query_snapshot( $query, $request ) ) )
			);
		} catch ( OzonDeliveryApiException $exception ) {
			throw new OzonDeliveryQuoteException( $exception->safe_code ?: 'ozon_api_error', $exception->operation, $exception->http_status, $this->sanitizer->sanitize( $exception->getMessage(), 'Ozon Delivery не рассчитал доставку.' ), $exception->metadata );
		}
	}

	public function quote_courier( QuoteRequest $request ): OzonDeliveryQuoteResult {
		try {
			$packaging = $this->packaging->build( $request );
		} catch ( PackagingException $exception ) {
			throw new OzonDeliveryQuoteException( 'ozon_package_item_oversize', 'order_checkout', 0, 'Товары не помещаются в допустимое грузоместо Ozon.' );
		}
		$built = $this->builder->build_courier( $request, $packaging );
		try {
			$data = $this->api->order_checkout( $built['body'] );
			$result = $this->parser->parse( $data, $built['request_ids'], '', (int) $built['diagnostics']['shipment_method_id'] );
			return new OzonDeliveryQuoteResult(
				$result->price,
				$result->delivery_days,
				$result->destination_point_id,
				$result->package_count,
				$result->shipment_method_id,
				$result->endpoint,
				$result->http_status,
				array_merge( $result->meta, $built['diagnostics'], $packaging->to_array(), array( 'ozon_delivery_places' => $this->cargo_places( $packaging->to_array() ) ) )
			);
		} catch ( OzonDeliveryApiException $exception ) {
			throw new OzonDeliveryQuoteException( $exception->safe_code ?: 'ozon_api_error', $exception->operation, $exception->http_status, $this->sanitizer->sanitize( $exception->getMessage(), 'Ozon Delivery не рассчитал доставку.' ), $exception->metadata );
		}
	}

	/** @param array<string,mixed> $packaging */
	public function pickup_query( QuoteRequest $request, array $packaging ): CarrierPickupPointQuery {
		$lat = $this->number_context( $request, array( 'destination_latitude', 'selected_location_latitude', 'latitude', 'lat' ), -90, 90 );
		$lng = $this->number_context( $request, array( 'destination_longitude', 'selected_location_longitude', 'longitude', 'lng', 'lon' ), -180, 180 );
		$dimensions = is_array( $packaging['dimensions'] ?? null ) ? $packaging['dimensions'] : array();
		$places = $this->cargo_places( $packaging );
		$max_dimension = 0;
		$max_place_weight = 0;
		foreach ( $places as $place ) {
			$max_dimension = max( $max_dimension, (int) ceil( max( (float) $place['length_cm'], (float) $place['width_cm'], (float) $place['height_cm'] ) ) );
			$max_place_weight = max( $max_place_weight, (int) $place['weight_g'] );
		}

		return new CarrierPickupPointQuery(
			'ozon_delivery',
			(int) ( $request->customer_context['selected_location_id'] ?? $request->customer_context['location_id'] ?? 0 ),
			$request->country_code ?: $request->destination->country_code,
			$request->destination->raw_address,
			$lat,
			$lng,
			new PickupCargoConstraints(
				(int) ( $packaging['total_weight_g'] ?? $request->package->get_total_weight_g() ),
				(int) ( $request->package->get_total_volume_cm3() ),
				$max_dimension > 0 ? $max_dimension : (int) max( (float) ( $dimensions['length'] ?? 0 ), (float) ( $dimensions['width'] ?? 0 ), (float) ( $dimensions['height'] ?? 0 ) ),
				$max_place_weight > 0 ? $max_place_weight : (int) ( $packaging['final_weight_g'] ?? $request->package->get_total_weight_g() ),
				max( 1, count( $places ) ),
				$places
			),
			CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP,
			60,
			100
		);
	}

	/** @param array<string,mixed> $packaging @return array<int,array{weight_g:int,length_cm:float,width_cm:float,height_cm:float}> */
	private function cargo_places( array $packaging ): array {
		$parcels = is_array( $packaging['parcel_dimensions'] ?? null ) ? $packaging['parcel_dimensions'] : array();
		if ( array() === $parcels ) {
			$parcels = is_array( $packaging['parcels'] ?? null ) ? $packaging['parcels'] : array();
		}
		$places = array();
		foreach ( $parcels as $parcel ) {
			if ( ! is_array( $parcel ) ) {
				continue;
			}
			$weight = max( 1, (int) ( $parcel['weight_g'] ?? $parcel['final_weight_g'] ?? 0 ) );
			$length = max( 0.1, (float) ( $parcel['length_cm'] ?? $parcel['length'] ?? 0 ) );
			$width = max( 0.1, (float) ( $parcel['width_cm'] ?? $parcel['width'] ?? 0 ) );
			$height = max( 0.1, (float) ( $parcel['height_cm'] ?? $parcel['height'] ?? 0 ) );
			for ( $index = 0; $index < max( 1, (int) ( $parcel['quantity'] ?? 1 ) ); ++$index ) {
				$places[] = array(
					'weight_g' => $weight,
					'length_cm' => $length,
					'width_cm' => $width,
					'height_cm' => $height,
				);
			}
		}

		return $places;
	}

	private function selected_point_code( QuoteRequest $request ): string {
		$selections = is_array( $request->customer_context['pickup_selections'] ?? null ) ? $request->customer_context['pickup_selections'] : array();
		$selection = is_array( $selections['ozon_delivery:pickup'] ?? null ) ? $selections['ozon_delivery:pickup'] : array();
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		return trim( (string) ( $selection['point_code'] ?? $selection['point_id'] ?? $snapshot['point_code'] ?? '' ) );
	}

	private function selected_point( CarrierPickupPointQuery $query, string $code ): ?PickupPoint {
		return $this->pickup_provider->resolve_selection( new CarrierPickupPointSelectionQuery( $query, $code ) );
	}

	private function representative_point( CarrierPickupPointQuery $query ): ?PickupPoint {
		$points = $this->pickup_provider->search( $query );
		usort( $points, fn( PickupPoint $left, PickupPoint $right ): int => $this->distance_score( $left, $query ) <=> $this->distance_score( $right, $query ) );

		return $points[0] ?? null;
	}

	/** @return array<string,mixed> */
	private function representative_missing_details( CarrierPickupPointQuery $query ): array {
		$places = array_slice( $query->cargo->places, 0, 20 );

		return array(
			'places_count' => $query->cargo->places_count,
			'total_weight_g' => $query->cargo->weight_g,
			'max_place_weight_g' => $query->cargo->max_place_weight_g,
			'places' => $places,
			'places_truncated' => count( $query->cargo->places ) > count( $places ),
			'pickup_diagnostics' => $this->pickup_provider->last_search_diagnostics(),
		);
	}

	/** @return array<string,mixed> */
	private function query_snapshot( CarrierPickupPointQuery $query, QuoteRequest $request ): array {
		$fingerprint = $this->destination_fingerprint( $request, $query );

		return array(
			'carrier_key' => $query->carrier_key,
			'location_id' => $query->location_id,
			'country_code' => $query->country_code,
			'fallback_address' => $query->fallback_address,
			'latitude' => $query->latitude,
			'longitude' => $query->longitude,
			'cargo' => $query->cargo->to_array(),
			'purpose' => $query->purpose,
			'radius_km' => $query->radius_km,
			'limit' => $query->limit,
			'destination_fingerprint' => $fingerprint,
			'provider_destination_fingerprint' => $fingerprint,
			'reload_on_viewport_change' => false,
			'prefetch_points' => false,
		);
	}

	private function destination_fingerprint( QuoteRequest $request, CarrierPickupPointQuery $query ): string {
		$context = $request->customer_context;
		$raw_country = $context['country_code'] ?? '';
		if ( '' === $this->normalized_location_value( $raw_country ) ) {
			$raw_country = $query->country_code;
		}
		if ( '' === $this->normalized_location_value( $raw_country ) ) {
			$raw_country = $request->country_code;
		}
		if ( '' === $this->normalized_location_value( $raw_country ) ) {
			$raw_country = $request->destination->country_code;
		}
		$country = $this->normalized_location_value( $raw_country );
		$prefix = '' !== $country ? 'country=' . strtoupper( $country ) . '|' : '';
		$location_id = $query->location_id > 0 ? (string) $query->location_id : $this->normalized_location_value( $context['selected_location_id'] ?? $context['location_id'] ?? '' );
		if ( '' !== $location_id && is_numeric( $location_id ) && (int) $location_id > 0 ) {
			return $prefix . 'location_id=' . (string) (int) $location_id;
		}
		foreach ( array( 'fias_id', 'city_fias_id', 'fias_location_guid' ) as $key ) {
			$value = $this->normalized_location_value( $context[ $key ] ?? '' );
			if ( '' !== $value ) {
				return $prefix . 'fias_id=' . $value;
			}
		}
		foreach ( array( 'gar_object_id', 'gar_id' ) as $key ) {
			$value = $this->normalized_location_value( $context[ $key ] ?? '' );
			if ( '' !== $value ) {
				return $prefix . 'gar_object_id=' . $value;
			}
		}
		$city = '';
		foreach ( array( 'city_name', 'settlement_name', 'place_name', 'city' ) as $key ) {
			$city = $this->normalized_location_value( $context[ $key ] ?? '' );
			if ( '' !== $city ) {
				break;
			}
		}
		if ( '' === $city ) {
			$city = $this->normalized_location_value( $request->destination->city ?: $request->destination->settlement );
		}
		$region = '';
		foreach ( array( 'region_name', 'state_value', 'region' ) as $key ) {
			$region = $this->normalized_location_value( $context[ $key ] ?? '' );
			if ( '' !== $region ) {
				break;
			}
		}
		if ( '' !== $city || '' !== $region ) {
			return $prefix . 'place=' . $region . '|' . $city;
		}
		foreach ( array( 'postcode', 'postal_code' ) as $key ) {
			$postcode = $this->normalized_location_value( $context[ $key ] ?? '' );
			if ( '' !== $postcode ) {
				return $prefix . 'postcode=' . $postcode;
			}
		}

		return '';
	}

	private function normalized_location_value( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );

		return preg_replace( '/\s+/u', ' ', $value ) ?: $value;
	}

	private function distance_score( PickupPoint $point, CarrierPickupPointQuery $query ): float {
		if ( null === $query->latitude || null === $query->longitude || null === $point->latitude || null === $point->longitude ) {
			return INF;
		}

		return ( $point->latitude - $query->latitude ) ** 2 + ( $point->longitude - $query->longitude ) ** 2;
	}

	/** @param array<int,string> $keys */
	private function number_context( QuoteRequest $request, array $keys, float $min, float $max ): ?float {
		foreach ( $keys as $key ) {
			$value = $request->customer_context[ $key ] ?? null;
			if ( is_numeric( $value ) ) {
				$number = (float) $value;
				if ( is_finite( $number ) && $number >= $min && $number <= $max ) {
					return $number;
				}
			}
		}

		return null;
	}
}
