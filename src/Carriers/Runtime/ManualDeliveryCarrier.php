<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\Manual\ManualDeliveryGeographyMatcher;
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
		private ManualDeliveryPricingService $pricing
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
			'order_recalculation_requires_address' => false,
		);
		if ( null !== $pricing->matched_range ) {
			$pricing_meta['manual_weight_range'] = $pricing->matched_range->to_array();
		}
		$rate = new DeliveryRate(
			ManualDeliverySettings::CARRIER_KEY . ':' . $service->service_key,
			ManualDeliverySettings::CARRIER_KEY,
			ManualDeliverySettings::CARRIER_TITLE,
			$service->service_key,
			$service->title,
			$service->service_key,
			$service->title,
			DeliveryType::COURIER,
			$service->title,
			$price,
			null,
			null,
			DateRange::range( $days['min_days'], $days['max_days'] ),
			'',
			'',
			array(),
			false,
			'',
			false,
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

	private function quote_id( QuoteRequest $request, string $suffix ): string {
		return 'manual_' . md5( $request->country_code . '|' . (string) ( $request->customer_context['service_key'] ?? '' ) . '|' . $suffix );
	}

	private function normalize_service_key( string $service_key ): string {
		return substr( preg_replace( '/[^a-z0-9_-]+/', '', strtolower( trim( $service_key ) ) ) ?? '', 0, 120 );
	}
}
