<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Runtime;

use WallsShop\WDC\Calendar\Services\DeliveryDateCalculator;
use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class DeliveryLeadTimeNormalizer {
	public function __construct(
		private SettingsRepository $settings,
		private DeliveryServiceSettingsRepository $service_settings,
		private DeliveryDateCalculator $calculator,
		private DeliveryDateFormatter $formatter
	) {
	}

	public function normalize( DeliveryRate $rate, ?DeliveryService $service, QuoteRequest $request ): DeliveryRate {
		$processing_days = $this->settings->shop_processing_working_days();
		$carrier_working = $service instanceof DeliveryService && null !== $service->id
			? $this->service_settings->delivery_days_are_working( (int) $service->id )
			: false;
		$original_delivery_days = $rate->original_delivery_days ?? $rate->delivery_days;
		$normalized = $this->calculator->normalize_lead_time( $request->calculation_date, $processing_days, $rate->delivery_days, $carrier_working );
		$total_days = $normalized['total_calendar_days'];

		return $this->clone_rate(
			$rate,
			$total_days,
			'',
			'',
			array_merge(
				$rate->meta,
				array(
					'carrier_delivery_days_original' => $original_delivery_days->to_array(),
					'carrier_delivery_days_original_unit' => $original_delivery_days->unit,
					'shop_processing_working_days' => $processing_days,
					'shop_processing_calendar_days' => $normalized['processing_calendar_days'],
					'carrier_days_are_working' => $carrier_working,
					'carrier_delivery_calendar_min_days' => $normalized['carrier_calendar_days']->min_days,
					'carrier_delivery_calendar_max_days' => $normalized['carrier_calendar_days']->max_days,
					'handoff_date' => $normalized['handoff_date'],
					'calculation_date' => $normalized['calculation_date'],
				)
			),
			$original_delivery_days
		);
	}

	public function enrich_planned_date( DeliveryRate $rate, QuoteRequest $request ): DeliveryRate {
		if ( $rate->delivery_days->is_empty() ) {
			return $this->clone_rate( $rate, $rate->delivery_days, '', '', $rate->meta, $rate->original_delivery_days );
		}

		$calendar_days = DateRange::range( $rate->delivery_days->min_days, $rate->delivery_days->max_days, DateRange::UNIT_CALENDAR_DAYS );
		$planned_date = $this->calculator->planned_date_from_calendar_days( $request->calculation_date, $calendar_days );
		$comment = $this->formatter->format_checkout_comment( $planned_date );

		return $this->clone_rate(
			$rate,
			$calendar_days,
			$planned_date,
			$comment,
			array_merge(
				$rate->meta,
				array(
					'planned_delivery_date' => $planned_date,
					'planned_delivery_comment' => $comment,
				)
			),
			$rate->original_delivery_days
		);
	}

	/**
	 * @param array<string,mixed> $meta
	 */
	private function clone_rate( DeliveryRate $rate, DateRange $delivery_days, string $planned_date, string $planned_comment, array $meta, ?DateRange $original_delivery_days ): DeliveryRate {
		return new DeliveryRate(
			$rate->rate_id,
			$rate->carrier_key,
			$rate->carrier_name,
			$rate->service_key,
			$rate->service_name,
			$rate->tariff_key,
			$rate->tariff_name,
			$rate->delivery_type,
			$rate->title,
			$rate->price,
			$rate->original_price,
			$rate->crossed_price,
			$delivery_days,
			$planned_date,
			$planned_comment,
			$rate->comments,
			$rate->disabled,
			$rate->disabled_reason,
			$rate->requires_pickup_point,
			$rate->requires_courier_address,
			$meta,
			$rate->original_cost,
			$original_delivery_days
		);
	}
}
