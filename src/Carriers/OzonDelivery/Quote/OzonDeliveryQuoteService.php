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
		$packaging = $this->packaging->build( $request );
		$query = $this->pickup_query( $request, $packaging->to_array() );
		$selected_code = $this->selected_point_code( $request );
		$selected_point = '' !== $selected_code ? $this->selected_point( $query, $selected_code ) : null;
		if ( '' !== $selected_code && ! $selected_point instanceof PickupPoint ) {
			throw new OzonDeliveryQuoteException( 'ozon_selected_point_stale', 'order_checkout', 0, 'Выбранный ПВЗ Ozon больше недоступен.' );
		}
		$point = $selected_point ?? $this->representative_point( $query );
		if ( ! $point instanceof PickupPoint ) {
			throw new OzonDeliveryQuoteException( 'ozon_representative_point_missing', 'order_checkout', 0, 'Нет подходящего ПВЗ Ozon для расчета.' );
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
				array_merge( $result->meta, $built['diagnostics'], $packaging->to_array(), array( 'pickup_source' => $selected_point instanceof PickupPoint ? 'selected' : 'representative', 'pickup_provider_query' => $this->query_snapshot( $query ) ) )
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
		$parcel_dimensions = is_array( $packaging['parcel_dimensions'] ?? null ) ? $packaging['parcel_dimensions'] : array();
		$max_dimension = 0;
		$max_place_weight = 0;
		foreach ( $parcel_dimensions as $parcel ) {
			if ( is_array( $parcel ) ) {
				$max_dimension = max( $max_dimension, (int) ceil( max( (float) ( $parcel['length'] ?? 0 ), (float) ( $parcel['width'] ?? 0 ), (float) ( $parcel['height'] ?? 0 ) ) ) );
				$max_place_weight = max( $max_place_weight, (int) ( $parcel['weight_g'] ?? $parcel['final_weight_g'] ?? 0 ) );
			}
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
				max( 1, (int) ( $packaging['parcels_count'] ?? 1 ) )
			),
			CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP,
			50,
			100
		);
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
	private function query_snapshot( CarrierPickupPointQuery $query ): array {
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
		);
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
