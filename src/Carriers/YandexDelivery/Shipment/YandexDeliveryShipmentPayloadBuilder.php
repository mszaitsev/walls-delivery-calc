<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

use DateTimeInterface;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocation;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocationItem;

/** Pure offers/create payload builder. It performs no transport or persistence. */
final class YandexDeliveryShipmentPayloadBuilder {
	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	public function build( ShipmentAllocation $allocation, array $context ): array {
		$operator_request_id = trim( (string) ( $context['operator_request_id'] ?? '' ) );
		if ( '' === $operator_request_id ) {
			throw new \InvalidArgumentException( 'operator_request_id is required.' );
		}
		$source_platform_id = trim( (string) ( $context['source_platform_station_id'] ?? '' ) );
		if ( '' === $source_platform_id ) {
			throw new \InvalidArgumentException( 'source_platform_station_id is required.' );
		}
		$from = $this->utc( $context['ready_from'] ?? null );
		$to = $this->utc( $context['ready_to'] ?? null );
		if ( null === $from || null === $to ) {
			throw new \InvalidArgumentException( 'ready_from and ready_to must be DateTimeInterface values.' );
		}

		$barcodes = array();
		$places = array();
		$items = array();
		foreach ( $allocation->places as $place ) {
			$barcode = $operator_request_id . '-' . $place->place_number;
			$barcodes[ $place->place_number ] = $barcode;
			$places[] = array(
				'barcode' => $barcode,
				'physical_dims' => array( 'weight_gross' => $place->weight_g, 'dx' => $place->length_cm, 'dy' => $place->width_cm, 'dz' => $place->height_cm ),
			);
			foreach ( $this->aggregate_place_items( $place->items ) as $item ) {
				$items[] = array(
					'name' => $item->name,
					'article' => $item->sku,
					'count' => $item->quantity,
					'unit_price' => $item->unit_price_kopecks,
					'assessed_unit_price' => $item->unit_price_kopecks,
					'nds' => (int) ( $context['nds'] ?? -1 ),
					'inn' => (string) ( $context['inn'] ?? '540601021727' ),
					'refused_count' => 0,
					'fitting' => false,
					'place_barcode' => $barcode,
				);
			}
		}

		$payload = array(
			'info' => array( 'operator_request_id' => $operator_request_id ),
			'source' => array( 'platform_station' => array( 'platform_id' => $source_platform_id ), 'interval_utc' => array( 'from' => $from, 'to' => $to ) ),
			'items' => $items,
			'places' => $places,
			'billing_info' => array( 'payment_method' => 'already_paid', 'delivery_cost' => 0 ),
			'recipient_info' => $this->recipient( is_array( $context['recipient'] ?? null ) ? $context['recipient'] : array() ),
			'particular_items_refuse' => false,
			'forbid_unboxing' => true,
		);

		return array_merge( $payload, $this->destination( is_array( $context['destination'] ?? null ) ? $context['destination'] : array() ) );
	}

	/** @param array<int,ShipmentAllocationItem> $items @return array<int,ShipmentAllocationItem> */
	private function aggregate_place_items( array $items ): array {
		$aggregated = array();
		foreach ( $items as $item ) {
			$key = $item->source_item_id;
			if ( isset( $aggregated[ $key ] ) ) {
				$old = $aggregated[ $key ];
				$aggregated[ $key ] = new ShipmentAllocationItem( $old->source_item_id, $old->identity, $old->name, $old->sku, $old->quantity + $item->quantity, $old->unit_price_kopecks, $old->weight_g );
				continue;
			}
			$aggregated[ $key ] = $item;
		}
		return array_values( $aggregated );
	}

	/** @param array<string,mixed> $recipient @return array<string,mixed> */
	private function recipient( array $recipient ): array {
		$name = trim( preg_replace( '/\s+/', ' ', trim( (string) ( $recipient['last_name'] ?? '' ) . ' ' . (string) ( $recipient['first_name'] ?? '' ) ) ) ?? '' );
		return array( 'first_name' => $name, 'last_name' => '', 'patronymic' => '', 'phone' => $this->phone( (string) ( $recipient['phone'] ?? '' ) ), 'email' => trim( (string) ( $recipient['email'] ?? '' ) ) );
	}

	/** @param array<string,mixed> $destination @return array<string,mixed> */
	private function destination( array $destination ): array {
		if ( 'pickup' === (string) ( $destination['mode'] ?? '' ) ) {
			return array( 'destination' => array( 'type' => 'platform_station', 'platform_station' => array( 'platform_id' => (string) ( $destination['platform_station_id'] ?? '' ) ) ), 'last_mile_policy' => 'self_pickup' );
		}
		$details = is_array( $destination['details'] ?? null ) ? $destination['details'] : array();
		return array( 'destination' => array( 'type' => 'custom_location', 'custom_location' => array( 'details' => $details ) ), 'last_mile_policy' => 'time_interval' );
	}

	private function utc( mixed $value ): ?string {
		return $value instanceof DateTimeInterface ? $value->setTimezone( new \DateTimeZone( 'UTC' ) )->format( DATE_ATOM ) : null;
	}

	private function phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';
		if ( 11 === strlen( $digits ) && '8' === $digits[0] ) { return '+7' . substr( $digits, 1 ); }
		return 10 === strlen( $digits ) ? '+7' . $digits : ( '' !== $digits && '+' === $phone[0] ? '+' . $digits : $digits );
	}
}
