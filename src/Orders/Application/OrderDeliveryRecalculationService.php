<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Application;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\Runtime\CdekCarrier;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryRecalculationService {
	public function __construct(
		private OrderQuoteRequestMapper $mapper,
		private CheckoutOrchestrator $orchestrator,
		private OrderShipmentRepository $shipments
	) {
	}

	/**
	 * @param array<string,mixed>|null $selected_location
	 * @param array<string,mixed> $selected_pickup_point
	 * @return array{success:bool,message:string,rates:array<int,array<string,mixed>>,request:array<string,mixed>,location:array<string,mixed>,diagnostic:array<string,mixed>}
	 */
	public function preview( object $order, ?array $selected_location = null, array $selected_pickup_point = array() ): array {
		$request = $this->mapper->map( $order, $selected_location, $selected_pickup_point );
		$result  = $this->orchestrator->calculate( $request, array(), RateSorter::CHEAPEST, true );
		$rates = $this->normalize_rates( $result->rates );

		return array(
			'success' => true,
			'message' => '',
			'rates'   => $rates,
			'request' => $request->to_array(),
			'location' => $this->location_payload_from_request( $request->to_array() ),
			'diagnostic' => array(
				'pek' => $this->pek_diagnostic( $request->to_array(), $selected_location, $rates, $result->diagnostic, $result->carrier_errors, $result->cache_hits ),
			),
		);
	}

	/**
	 * @param array<string,mixed>|null $selected_location
	 * @return array<string,mixed>
	 */
	public function resolved_location_payload( object $order, ?array $selected_location = null ): array {
		$request = $this->mapper->map( $order, $selected_location );

		return $this->location_payload_from_request( $request->to_array() );
	}

	public function has_blocking_shipment( object $order ): bool {
		foreach ( $this->shipments->all_for_order( $order ) as $shipment ) {
			if ( ! is_array( $shipment ) ) {
				continue;
			}
			$status = (string) ( $shipment['status'] ?? '' );
			$tracking = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
			$backlog_order_id = trim( (string) ( $shipment['backlog_order_id'] ?? '' ) );
			if ( in_array( $status, array( 'created', 'registered' ), true ) || '' !== $tracking || '' !== $backlog_order_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,DeliveryRate> $rates
	 * @return array<int,array<string,mixed>>
	 */
	private function normalize_rates( array $rates ): array {
		$plain = array();
		$tariff_groups = array();

		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof DeliveryRate || ! $rate->is_available() ) {
				continue;
			}
			if ( ! empty( $rate->meta['tariff_selector_group'] ) ) {
				$group_id = (string) ( $rate->meta['checkout_group_id'] ?? '' );
				if ( '' === $group_id ) {
					$group_id = RussianPostDomesticSettings::CARRIER_KEY === $rate->carrier_key
						? RussianPostDomesticSettings::checkout_group_id( $rate->delivery_type )
						: $rate->service_key . ':' . $rate->delivery_type;
				}
				$tariff_groups[ $group_id ][] = $rate;
				continue;
			}

			$plain[] = $this->rate_payload( $rate );
		}

		foreach ( $tariff_groups as $group_id => $group_rates ) {
			$plain[] = $this->tariff_group_payload( $group_id, $group_rates );
		}

		return array_values( $plain );
	}

	/**
	 * @param array<int,DeliveryRate> $rates
	 * @return array<string,mixed>
	 */
	private function tariff_group_payload( string $group_id, array $rates ): array {
		$first = $rates[0];
		$delivery_type = $first->delivery_type;
		$title_key = DeliveryType::COURIER === $delivery_type ? 'courier_method_title' : 'pickup_method_title';
		$default = DeliveryType::COURIER === $delivery_type ? RussianPostDomesticSettings::COURIER_SERVICE_TITLE : RussianPostDomesticSettings::PICKUP_SERVICE_TITLE;
		if ( CdekCarrier::KEY === $first->carrier_key ) {
			$default = DeliveryType::COURIER === $delivery_type ? CdekCarrier::COURIER_TITLE : CdekCarrier::PICKUP_TITLE;
		} elseif ( DpdSettings::CARRIER_KEY === $first->carrier_key ) {
			$default = DeliveryType::COURIER === $delivery_type ? DpdSettings::DEFAULT_COURIER_METHOD_TITLE : DpdSettings::DEFAULT_PICKUP_METHOD_TITLE;
		}
		$title = trim( (string) ( $first->meta[ $title_key ] ?? '' ) ) ?: $default;
		usort( $rates, static fn( DeliveryRate $left, DeliveryRate $right ): int => $left->price->get_kopecks() <=> $right->price->get_kopecks() );

		return array(
			'id'                    => $group_id,
			'label'                 => $title,
			'carrier_key'           => $first->carrier_key,
			'service_key'           => $first->service_key,
			'service_title'         => $first->service_name,
			'delivery_type'         => $delivery_type,
			'delivery_type_label'   => $this->delivery_type_label( $delivery_type ),
			'cost'                  => $rates[0]->price->get_rubles(),
			'price_html'            => 'от ' . $this->format_rubles( $rates[0]->price->get_rubles() ),
			'crossed_price_html'    => '',
			'delivery_comment'      => '',
			'comments'              => array(),
			'requires_pickup_point' => ! empty( $first->requires_pickup_point ),
			'selected'              => false,
			'is_grouped'            => true,
			'tariff_variants'       => array_map( array( $this, 'tariff_payload' ), $rates ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function rate_payload( DeliveryRate $rate ): array {
		return array(
			'id'                    => $rate->rate_id,
			'label'                 => $rate->title,
			'carrier_key'           => $rate->carrier_key,
			'service_key'           => $rate->service_key,
			'service_title'         => $rate->service_name,
			'delivery_type'         => $rate->delivery_type,
			'delivery_type_label'   => $this->delivery_type_label( $rate->delivery_type ),
			'cost'                  => $rate->price->get_rubles(),
			'price_html'            => $this->format_rubles( $rate->price->get_rubles() ),
			'crossed_price_html'    => $this->crossed_price( $rate ),
			'delivery_comment'      => $this->delivery_comment( $rate ),
			'planned_delivery_date' => $rate->planned_delivery_date,
			'planned_delivery_comment' => $rate->planned_delivery_comment,
			'comments'              => array_values( array_filter( array_map( 'strval', $rate->comments ) ) ),
			'requires_pickup_point' => $rate->requires_pickup_point,
			'selected'              => false,
			'is_grouped'            => false,
			'tariff_variants'       => array(),
			'rate_meta'             => $rate->meta,
		);
	}

	/**
	 * @param array<string,mixed> $request
	 * @param array<string,mixed>|null $selected_location
	 * @param array<int,array<string,mixed>> $rates
	 * @param array<string,mixed> $calculation_diagnostic
	 * @param array<string,string> $carrier_errors
	 * @return array<string,mixed>
	 */
	private function pek_diagnostic( array $request, ?array $selected_location, array $rates, array $calculation_diagnostic, array $carrier_errors, int $cache_hits ): array {
		$destination = is_array( $request['destination'] ?? null ) ? $request['destination'] : array();
		$context = is_array( $request['customer_context'] ?? null ) ? $request['customer_context'] : array();
		$country = strtoupper( trim( (string) ( $request['country_code'] ?? $destination['country_code'] ?? '' ) ) );
		$service_scan = $this->pek_service_scan( $calculation_diagnostic );
		$quote = $this->pek_quote_diagnostic( $calculation_diagnostic );

		return array_filter(
			array(
				'request_country_code' => $country,
				'request_location_id' => $this->first_positive_int( array( $context['selected_location_id'] ?? null, $context['location_id'] ?? null, $destination['location_id'] ?? null ) ),
				'request_destination_city_present' => '' !== trim( (string) ( $destination['city'] ?? $destination['settlement'] ?? '' ) ),
				'request_destination_postcode_present' => '' !== trim( (string) ( $destination['postcode'] ?? '' ) ),
				'location_override' => ! empty( $context['location_override'] ),
				'selected_location_country' => is_array( $selected_location ) ? strtoupper( trim( (string) ( $selected_location['country_code'] ?? $selected_location['country'] ?? '' ) ) ) : '',
				'selected_location_id' => is_array( $selected_location ) ? $this->first_positive_int( array( $selected_location['id'] ?? null, $selected_location['location_id'] ?? null, $selected_location['selected_location_id'] ?? null ) ) : 0,
				'pek_service_found' => array() !== $service_scan,
				'pek_service_active' => (bool) ( $service_scan['enabled'] ?? false ),
				'pek_service_availability_mode' => (string) ( $service_scan['availability_mode'] ?? '' ),
				'pek_service_country_enabled' => (bool) ( $service_scan['country_enabled'] ?? false ),
				'pek_service_entry_created' => (bool) ( $service_scan['entry_created'] ?? false ),
				'pek_carrier_quote_attempted' => (bool) ( $quote['attempted'] ?? false ),
				'pek_quote_cache_hit' => (bool) ( $quote['cache_hit'] ?? false ),
				'quote_cache_hits' => $cache_hits,
				'pek_rates_count' => $this->rates_count_for_carrier( $rates, PekSettings::CARRIER_KEY ),
				'pek_carrier_error_code' => (string) ( $quote['error_code'] ?? '' ),
				'pek_carrier_reason' => (string) ( $quote['fallback_reason'] ?? $carrier_errors[ PekSettings::CARRIER_KEY ] ?? '' ),
				'failure_stage' => (string) ( $quote['failure_stage'] ?? '' ),
			),
			static fn( mixed $value ): bool => null !== $value && '' !== $value
		);
	}

	/** @param array<string,mixed> $diagnostic @return array<string,mixed> */
	private function pek_service_scan( array $diagnostic ): array {
		$scan = is_array( $diagnostic['service_scan'] ?? null ) ? $diagnostic['service_scan'] : array();
		foreach ( $scan as $entry ) {
			if ( is_array( $entry ) && PekSettings::CARRIER_KEY === (string) ( $entry['carrier_key'] ?? '' ) && PekSettings::SERVICE_KEY === (string) ( $entry['service_key'] ?? '' ) ) {
				return $entry;
			}
		}

		return array();
	}

	/** @param array<string,mixed> $diagnostic @return array<string,mixed> */
	private function pek_quote_diagnostic( array $diagnostic ): array {
		$quotes = is_array( $diagnostic['carrier_quotes'] ?? null ) ? $diagnostic['carrier_quotes'] : array();
		foreach ( $quotes as $entry ) {
			if ( is_array( $entry ) && PekSettings::CARRIER_KEY === (string) ( $entry['carrier_key'] ?? '' ) ) {
				return $entry;
			}
		}

		return array();
	}

	/** @param array<int,array<string,mixed>> $rates */
	private function rates_count_for_carrier( array $rates, string $carrier_key ): int {
		return count( array_filter( $rates, static fn( array $rate ): bool => $carrier_key === (string) ( $rate['carrier_key'] ?? '' ) ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function tariff_payload( DeliveryRate $rate ): array {
		return array(
			'rate_id'             => $rate->rate_id,
			'object_code'         => $rate->tariff_key,
			'title'               => $rate->tariff_name,
			'label'               => $this->method_title_from_parts( $this->service_method_title( $rate ), $rate->tariff_name, $this->delivery_comment( $rate ) ),
			'price_html'          => $this->format_rubles( $rate->price->get_rubles() ),
			'cost'                => $rate->price->get_rubles(),
			'crossed_price_html'  => $this->crossed_price( $rate ),
			'delivery_comment'    => $this->delivery_comment( $rate ),
			'delivery_days'       => $rate->delivery_days->to_array(),
			'delivery_days_label' => $this->delivery_comment( $rate ),
			'planned_delivery_date' => $rate->planned_delivery_date,
			'planned_delivery_comment' => $rate->planned_delivery_comment,
			'comments'            => array_values( array_filter( array_map( 'strval', $rate->comments ) ) ),
			'selected'            => false,
			'rate_meta'           => $rate->meta,
			'api_base_price_rub'  => $rate->meta['api_base_price_rub'] ?? null,
		);
	}

	private function delivery_comment( DeliveryRate $rate ): string {
		return DeliveryDaysFormatter::format( $rate->delivery_days );
	}

	private function service_method_title( DeliveryRate $rate ): string {
		$title_key = DeliveryType::COURIER === $rate->delivery_type ? 'courier_method_title' : 'pickup_method_title';
		$default = '';
		if ( RussianPostDomesticSettings::CARRIER_KEY === $rate->carrier_key ) {
			$default = DeliveryType::COURIER === $rate->delivery_type ? RussianPostDomesticSettings::COURIER_SERVICE_TITLE : RussianPostDomesticSettings::PICKUP_SERVICE_TITLE;
		} elseif ( CdekCarrier::KEY === $rate->carrier_key ) {
			$default = DeliveryType::COURIER === $rate->delivery_type ? CdekCarrier::COURIER_TITLE : CdekCarrier::PICKUP_TITLE;
		} elseif ( DpdSettings::CARRIER_KEY === $rate->carrier_key ) {
			$default = DeliveryType::COURIER === $rate->delivery_type ? DpdSettings::DEFAULT_COURIER_METHOD_TITLE : DpdSettings::DEFAULT_PICKUP_METHOD_TITLE;
		}

		return trim( (string) ( $rate->meta[ $title_key ] ?? '' ) ) ?: $default;
	}

	private function method_title_from_parts( string $service_title, string $tariff_title, string $delivery_days ): string {
		$title = trim( $service_title );
		$tariff_title = trim( $tariff_title );
		if ( '' !== $tariff_title && ! str_contains( $title, $tariff_title ) ) {
			$title = '' !== $title ? $title . ', ' . $tariff_title : $tariff_title;
		}

		$delivery_days = trim( $delivery_days );
		if ( '' !== $delivery_days && ! str_contains( $title, $delivery_days ) ) {
			$title = '' !== $title ? $title . ' - ' . $delivery_days : $delivery_days;
		}

		return $title;
	}

	private function crossed_price( DeliveryRate $rate ): string {
		$money = $rate->crossed_price ?? $rate->original_price;
		if ( null === $money || $money->get_kopecks() <= $rate->price->get_kopecks() ) {
			return '';
		}

		return $this->format_rubles( $money->get_rubles() );
	}

	private function format_rubles( float $rubles ): string {
		return rtrim( rtrim( number_format( $rubles, 2, '.', ' ' ), '0' ), '.' ) . ' руб.';
	}

	private function delivery_type_label( string $delivery_type ): string {
		return match ( $delivery_type ) {
			DeliveryType::PICKUP => 'Пункт выдачи',
			DeliveryType::COURIER => 'Курьер',
			default => $delivery_type,
		};
	}

	/**
	 * @param array<string,mixed> $request
	 * @return array<string,mixed>
	 */
	private function location_payload_from_request( array $request ): array {
		$destination = is_array( $request['destination'] ?? null ) ? $request['destination'] : array();
		$context = is_array( $request['customer_context'] ?? null ) ? $request['customer_context'] : array();
		$region = trim( (string) ( $destination['region_name'] ?? $context['selected_location_region'] ?? '' ) );
		$name = trim( (string) ( $context['display_name'] ?? $context['selected_location_name'] ?? $destination['display_name'] ?? $destination['city'] ?? $destination['settlement'] ?? '' ) );
		$resolved_location_id = $this->first_positive_int(
			array(
				$context['selected_location_id'] ?? null,
				$context['location_id'] ?? null,
				$destination['location_id'] ?? null,
			)
		);

		return array_filter(
			array(
				'id'            => $resolved_location_id > 0 ? $resolved_location_id : null,
				'location_id'   => $resolved_location_id > 0 ? $resolved_location_id : null,
				'fias_id'       => $destination['fias_id'] ?? '',
				'gar_id'        => $destination['gar_id'] ?? $context['selected_location_gar_id'] ?? '',
				'gar_object_id' => $destination['gar_object_id'] ?? $destination['gar_id'] ?? $context['selected_location_gar_object_id'] ?? $context['selected_location_gar_id'] ?? '',
				'name'          => $name,
				'display_name'  => $destination['display_name'] ?? $name,
				'postcode'      => $destination['postcode'] ?? '',
				'postal_code'   => $destination['postcode'] ?? '',
				'country'       => $destination['country_code'] ?? '',
				'country_code'  => $destination['country_code'] ?? '',
				'region'        => $region,
				'region_name'   => $region,
				'label'         => $name,
				'is_override'   => ! empty( $context['location_override'] ),
			),
			static fn( mixed $value ): bool => null !== $value && '' !== $value
		);
	}

	private function positive_int( mixed $value ): int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : 0;
		}
		if ( is_string( $value ) && ctype_digit( trim( $value ) ) ) {
			return max( 0, (int) trim( $value ) );
		}
		if ( is_float( $value ) && $value > 0 ) {
			return (int) $value;
		}

		return 0;
	}

	/** @param array<int,mixed> $values */
	private function first_positive_int( array $values ): int {
		foreach ( $values as $value ) {
			$int_value = $this->positive_int( $value );
			if ( $int_value > 0 ) {
				return $int_value;
			}
		}

		return 0;
	}
}
