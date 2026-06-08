<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Application;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
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
	 * @return array{success:bool,message:string,rates:array<int,array<string,mixed>>,request:array<string,mixed>,location:array<string,mixed>}
	 */
	public function preview( object $order, ?array $selected_location = null ): array {
		if ( $this->has_blocking_shipment( $order ) ) {
			return array(
				'success' => false,
				'message' => 'Пересчет доставки недоступен: по заказу уже создано отправление.',
				'rates'   => array(),
				'request' => array(),
				'location' => array(),
			);
		}

		$request = $this->mapper->map( $order, $selected_location );
		$result  = $this->orchestrator->calculate( $request, array(), RateSorter::CHEAPEST, true );

		return array(
			'success' => true,
			'message' => '',
			'rates'   => $this->normalize_rates( $result->rates ),
			'request' => $request->to_array(),
			'location' => $this->location_payload_from_request( $request->to_array() ),
		);
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
		$domestic_groups = array();

		foreach ( $rates as $rate ) {
			if ( ! $rate instanceof DeliveryRate || ! $rate->is_available() ) {
				continue;
			}
			if ( RussianPostDomesticSettings::CARRIER_KEY === $rate->carrier_key && ! empty( $rate->meta['tariff_selector_group'] ) ) {
				$domestic_groups[ RussianPostDomesticSettings::checkout_group_id( $rate->delivery_type ) ][] = $rate;
				continue;
			}

			$plain[] = $this->rate_payload( $rate );
		}

		foreach ( $domestic_groups as $group_id => $group_rates ) {
			$plain[] = $this->domestic_group_payload( $group_id, $group_rates );
		}

		return array_values( $plain );
	}

	/**
	 * @param array<int,DeliveryRate> $rates
	 * @return array<string,mixed>
	 */
	private function domestic_group_payload( string $group_id, array $rates ): array {
		$first = $rates[0];
		$delivery_type = $first->delivery_type;
		$title_key = DeliveryType::COURIER === $delivery_type ? 'courier_method_title' : 'pickup_method_title';
		$default = DeliveryType::COURIER === $delivery_type ? RussianPostDomesticSettings::COURIER_SERVICE_TITLE : RussianPostDomesticSettings::PICKUP_SERVICE_TITLE;
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
			'requires_pickup_point' => DeliveryType::PICKUP === $delivery_type,
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
			'comments'              => array_values( array_filter( array_map( 'strval', $rate->comments ) ) ),
			'requires_pickup_point' => $rate->requires_pickup_point,
			'selected'              => false,
			'is_grouped'            => false,
			'tariff_variants'       => array(),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function tariff_payload( DeliveryRate $rate ): array {
		return array(
			'rate_id'             => $rate->rate_id,
			'object_code'         => $rate->tariff_key,
			'title'               => $rate->tariff_name,
			'price_html'          => $this->format_rubles( $rate->price->get_rubles() ),
			'cost'                => $rate->price->get_rubles(),
			'crossed_price_html'  => $this->crossed_price( $rate ),
			'delivery_comment'    => $this->delivery_comment( $rate ),
			'comments'            => array_values( array_filter( array_map( 'strval', $rate->comments ) ) ),
			'selected'            => false,
		);
	}

	private function delivery_comment( DeliveryRate $rate ): string {
		return '' !== trim( $rate->planned_delivery_comment ) ? $rate->planned_delivery_comment : DeliveryDaysFormatter::format( $rate->delivery_days );
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
		$name = trim( (string) ( $context['selected_location_name'] ?? $context['display_name'] ?? $destination['city'] ?? $destination['settlement'] ?? '' ) );
		$label = trim( implode( ', ', array_values( array_filter( array( $region, $name ), static fn( string $part ): bool => '' !== $part ) ) ) );

		return array_filter(
			array(
				'id'          => $context['selected_location_id'] ?? null,
				'fias_id'     => $destination['fias_id'] ?? '',
				'name'        => $name,
				'postcode'    => $destination['postcode'] ?? '',
				'country'     => $destination['country_code'] ?? '',
				'region'      => $region,
				'label'       => '' !== $label ? $label : $name,
				'is_override' => ! empty( $context['location_override'] ),
			),
			static fn( mixed $value ): bool => null !== $value && '' !== $value
		);
	}
}
