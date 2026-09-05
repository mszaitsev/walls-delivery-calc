<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Manual\ManualDeliveryGeographyMatcher;
use WallsShop\WDC\Carriers\Manual\ManualPickupPointRepository;
use WallsShop\WDC\Carriers\Manual\ManualDeliveryPricingService;
use WallsShop\WDC\Carriers\Manual\ManualDeliverySettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class ManualDeliveryCarrier implements CarrierAdapterInterface {
	public function __construct(
		private DeliveryServiceRepository $services,
		private ManualDeliverySettings $settings,
		private ManualDeliveryGeographyMatcher $geography,
		private ManualDeliveryPricingService $pricing,
		private ManualPickupPointRepository $pickup_points
	) {
	}

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( ManualDeliverySettings::CARRIER_KEY, ManualDeliverySettings::CARRIER_TITLE, 'manual', true );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities( supports_quotes: true, supports_courier_delivery: true, supports_international: true );
	}

	public function supports_country( string $countryCode ): bool {
		return '' !== strtoupper( trim( $countryCode ) );
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		$service_key = $this->normalize_service_key( (string) ( $request->customer_context['service_key'] ?? '' ) );
		$service = '' !== $service_key ? $this->services->find_by_service_key( $service_key ) : null;
		if ( ! $this->service_can_quote( $service ) ) {
			return $this->failed_quote( $request, 'manual_service_unavailable' );
		}
		$geography = $this->geography->match( $service, $request );
		if ( ! $geography['available'] ) {
			return $this->failed_quote( $request, $geography['reason'] );
		}
		$manual_delivery_type = $this->settings->delivery_type( (int) $service->id );
		$delivery_type = $this->delivery_type_for_setting( $manual_delivery_type['type'] );
		$requires_pickup_point = ManualDeliverySettings::DELIVERY_TYPE_PICKUP === $manual_delivery_type['type'];
		if ( $requires_pickup_point && ! $this->has_eligible_pickup_point( $service, $request ) ) {
			return $this->failed_quote( $request, 'manual_pickup_points_unavailable' );
		}

		$pricing = $this->pricing->calculate_for_service( (int) $service->id, max( 0, $request->package->get_total_weight_g() ) );
		if ( ! $pricing->available || ! $pricing->price instanceof Money ) {
			return $this->failed_quote( $request, $pricing->reason ?: 'manual_pricing_invalid' );
		}

		$price = $pricing->price;
		$days = $this->settings->delivery_days( (int) $service->id );
		$pricing_meta = array(
			'api_base_price_rub' => $price->get_rubles(),
			'manual_pricing_mode' => $pricing->pricing_mode,
			'manual_chargeable_weight_g' => $pricing->chargeable_weight_g,
			'manual_billing_weight_g' => $pricing->billing_weight_g,
			'manual_geography_match' => $geography['reason'],
			'manual_delivery_type' => $manual_delivery_type['type'],
			'manual_delivery_type_label' => $manual_delivery_type['label'],
			'order_recalculation_requires_address' => false,
		);
		if ( ManualDeliverySettings::DELIVERY_TYPE_CUSTOM === $manual_delivery_type['type'] ) {
			$pricing_meta['preserve_rate_title'] = true;
		}
		if ( $requires_pickup_point ) {
			$pricing_meta = array_merge( $pricing_meta, $this->pickup_rate_meta( $service, $request ) );
		}
		if ( null !== $pricing->matched_range ) {
			$pricing_meta['manual_weight_range'] = $pricing->matched_range->to_array();
		}
		$title = ManualDeliverySettings::DELIVERY_TYPE_CUSTOM === $manual_delivery_type['type'] && '' !== trim( $manual_delivery_type['label'] )
			? trim( $service->title . ' - ' . $manual_delivery_type['label'] )
			: $service->title;
		$rate = new DeliveryRate(
			ManualDeliverySettings::CARRIER_KEY . ':' . $service->service_key,
			ManualDeliverySettings::CARRIER_KEY,
			ManualDeliverySettings::CARRIER_TITLE,
			$service->service_key,
			$service->title,
			$service->service_key,
			$service->title,
			$delivery_type,
			$title,
			$price,
			null,
			null,
			DateRange::range( $days['min_days'], $days['max_days'] ),
			'',
			'',
			array(),
			false,
			'',
			$requires_pickup_point,
			false,
			$pricing_meta,
			$price,
			DateRange::range( $days['min_days'], $days['max_days'] )
		);

		return new DeliveryQuote( $this->quote_id( $request, $service->service_key ), ManualDeliverySettings::CARRIER_KEY, $request->destination, $request->package, array( $rate ), true, '', '', false, 'manual' );
	}

	private function service_can_quote( ?DeliveryService $service ): bool {
		return $service instanceof DeliveryService
			&& null !== $service->id
			&& $service->enabled
			&& ! $service->deleted
			&& ManualDeliverySettings::CARRIER_KEY === $service->carrier_key
			&& DeliveryService::TYPE_MANUAL === $service->service_type;
	}

	private function failed_quote( QuoteRequest $request, string $code ): DeliveryQuote {
		return new DeliveryQuote( $this->quote_id( $request, $code ), ManualDeliverySettings::CARRIER_KEY, $request->destination, $request->package, array(), false, $code, 'Manual delivery service unavailable.', false, 'manual' );
	}

	private function delivery_type_for_setting( string $type ): string {
		return match ( $type ) {
			ManualDeliverySettings::DELIVERY_TYPE_PICKUP => DeliveryType::PICKUP,
			ManualDeliverySettings::DELIVERY_TYPE_CUSTOM => DeliveryType::UNKNOWN,
			default => DeliveryType::COURIER,
		};
	}

	private function has_eligible_pickup_point( DeliveryService $service, QuoteRequest $request ): bool {
		$destination = $this->destination_identity( $request );
		if ( null === $destination ) {
			return false;
		}

		return $this->pickup_points->has_active_for_destination( (int) $service->id, $destination['country_code'], $destination['region_name'], $destination['location_name'] );
	}

	/** @return array<string,mixed> */
	private function pickup_rate_meta( DeliveryService $service, QuoteRequest $request ): array {
		$destination = $this->destination_identity( $request );
		$fingerprint = $this->destination_fingerprint( $request, $destination );
		$family = ManualDeliverySettings::CARRIER_KEY . ':' . $service->service_key . ':pickup';
		$snapshot = array(
			'carrier_key' => ManualDeliverySettings::CARRIER_KEY,
			'service_key' => $service->service_key,
			'pickup_family' => $family,
			'purpose' => 'destination_pickup',
			'location_id' => max( 0, (int) ( $request->customer_context['location_id'] ?? 0 ) ),
			'country_code' => (string) ( $destination['country_code'] ?? $request->country_code ),
			'region_name' => (string) ( $destination['region_name'] ?? '' ),
			'location_name' => (string) ( $destination['location_name'] ?? '' ),
			'latitude' => is_numeric( $request->customer_context['latitude'] ?? null ) ? (float) $request->customer_context['latitude'] : null,
			'longitude' => is_numeric( $request->customer_context['longitude'] ?? null ) ? (float) $request->customer_context['longitude'] : null,
			'radius_km' => 50,
			'limit' => 50,
			'destination_fingerprint' => $fingerprint,
			'provider_destination_fingerprint' => $fingerprint,
			'reload_on_viewport_change' => false,
			'cargo' => array(
				'weight_g' => max( 0, $request->package->get_total_weight_g() ),
				'volume_cm3' => max( 0, $request->package->get_total_volume_cm3() ),
				'max_dimension_cm' => max( 0, max( (int) ( $request->package->length_cm ?? 0 ), (int) ( $request->package->width_cm ?? 0 ), (int) ( $request->package->height_cm ?? 0 ) ) ),
				'max_place_weight_g' => max( 0, $request->package->get_total_weight_g() ),
				'places_count' => max( 1, $request->package->get_total_quantity() ),
			),
		);

		return array(
			'pickup_family' => $family,
			'pickup_provider_query' => $snapshot,
			'destination_fingerprint' => $fingerprint,
			'provider_destination_fingerprint' => $fingerprint,
		);
	}

	/** @return array{country_code:string,region_name:string,location_name:string}|null */
	private function destination_identity( QuoteRequest $request ): ?array {
		$country = strtoupper( trim( $request->country_code ?: $request->destination->country_code ) );
		$region = trim( (string) ( $request->customer_context['region_name'] ?? $request->destination->region_name ) );
		$location = trim( (string) ( $request->customer_context['place_name'] ?? $request->customer_context['location_name'] ?? '' ) );
		if ( '' === $location ) {
			$location = trim( $request->destination->settlement ?: $request->destination->city );
		}
		if ( '' === $country || '' === $region || '' === $location ) {
			return null;
		}

		return array( 'country_code' => $country, 'region_name' => $region, 'location_name' => $location );
	}

	private function destination_fingerprint( QuoteRequest $request, ?array $destination ): string {
		return md5( implode( '|', array( $request->country_code, (string) ( $request->customer_context['location_id'] ?? 0 ), (string) ( $destination['region_name'] ?? '' ), (string) ( $destination['location_name'] ?? '' ) ) ) );
	}

	private function quote_id( QuoteRequest $request, string $suffix ): string {
		return 'manual_' . md5( $request->country_code . '|' . (string) ( $request->customer_context['service_key'] ?? '' ) . '|' . $suffix );
	}

	private function normalize_service_key( string $service_key ): string {
		return substr( preg_replace( '/[^a-z0-9_-]+/', '', strtolower( trim( $service_key ) ) ) ?? '', 0, 120 );
	}
}
