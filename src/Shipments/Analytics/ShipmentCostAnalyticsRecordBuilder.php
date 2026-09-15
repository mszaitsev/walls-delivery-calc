<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsRecord;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsRecordBuilder {
	public function __construct(
		private OrderShipmentRepository $shipments,
		private OrderAnalyticsShipmentSelector $shipment_selector,
		private ShipmentBaseApiCostResolver $base_costs,
		private ShipmentCostThresholdPolicy $threshold
	) {
	}

	public function build( object $order ): ?ShipmentCostAnalyticsRecord {
		$selected = $this->shipment_selector->select( $order, $this->shipments->all_for_order( $order ) );
		if ( null === $selected ) {
			return null;
		}
		$order_id = $this->order_id( $order );
		if ( $order_id <= 0 ) {
			return null;
		}

		$shipment = $selected->shipment;
		$base = $this->base_costs->resolve_from_order( $order );
		$actual = $this->positive_int_or_null( $shipment['actual_cost_kopecks'] ?? null );
		$currency = strtoupper( (string) ( $shipment['actual_cost_currency'] ?? 'RUB' ) );
		$comparable_actual = 'RUB' === $currency ? $actual : null;
		$difference = null !== $base && null !== $comparable_actual && $base > 0 ? $comparable_actual - $base : null;
		$percent = null !== $difference && null !== $base && $base > 0 ? intdiv( $difference * 10000, $base ) : null;

		return new ShipmentCostAnalyticsRecord(
			$order_id,
			$this->order_number( $order, $order_id ),
			$this->order_created_at_utc( $order ),
			$selected->carrier_key,
			$selected->service_key,
			(string) ( $shipment['service_title'] ?? '' ),
			$selected->shipment_key,
			$selected->created_identity->value,
			$base,
			$actual,
			'' !== $currency ? $currency : 'RUB',
			(string) ( $shipment['actual_cost_source'] ?? '' ),
			(string) ( $shipment['actual_cost_source_detail'] ?? '' ),
			$this->datetime_or_null( $shipment['actual_cost_updated_at'] ?? null ),
			$difference,
			$percent,
			$this->threshold->classify( $base, $comparable_actual ),
			$this->now_utc()
		);
	}

	private function order_id( object $order ): int {
		return method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
	}

	private function order_number( object $order, int $order_id ): string {
		return method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $order_id;
	}

	private function order_created_at_utc( object $order ): string {
		if ( method_exists( $order, 'get_date_created' ) ) {
			$date = $order->get_date_created();
			if ( $date instanceof \DateTimeInterface ) {
				return ( new \DateTimeImmutable( '@' . $date->getTimestamp() ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
			if ( is_object( $date ) && method_exists( $date, 'getTimestamp' ) ) {
				return ( new \DateTimeImmutable( '@' . (int) $date->getTimestamp() ) )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
			}
			if ( is_object( $date ) && method_exists( $date, 'date' ) ) {
				return (string) $date->date( 'Y-m-d H:i:s' );
			}
		}

		return $this->now_utc();
	}

	private function datetime_or_null( mixed $value ): ?string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		if ( '' === $value ) {
			return null;
		}
		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			return null;
		}

		return gmdate( 'Y-m-d H:i:s', $timestamp );
	}

	private function positive_int_or_null( mixed $value ): ?int {
		if ( is_int( $value ) ) {
			return $value > 0 ? $value : null;
		}
		if ( is_string( $value ) && 1 === preg_match( '/^\d+$/', $value ) ) {
			$integer = (int) $value;

			return $integer > 0 ? $integer : null;
		}

		return null;
	}

	private function now_utc(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
	}
}
