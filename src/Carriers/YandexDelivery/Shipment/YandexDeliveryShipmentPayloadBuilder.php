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
		$interval = $this->ready_interval( $context['ready_from'] ?? null, $context['ready_to'] ?? null );
		$allocation_errors = $allocation->validate();
		if ( array() !== $allocation_errors ) {
			throw new \InvalidArgumentException( implode( "\n", $allocation_errors ) );
		}
		$recipient = $this->recipient( is_array( $context['recipient'] ?? null ) ? $context['recipient'] : array() );
		$destination = $this->destination( is_array( $context['destination'] ?? null ) ? $context['destination'] : array() );

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
					'billing_details' => array(
						'inn' => (string) ( $context['inn'] ?? '540601021727' ),
						'nds' => (int) ( $context['nds'] ?? -1 ),
						'unit_price' => $item->unit_price_kopecks,
						'assessed_unit_price' => $item->assessed_unit_price_kopecks,
					),
					'place_barcode' => $barcode,
					'refused_count' => 0,
					'fitting' => false,
				);
			}
		}
		if ( array() === $places ) {
			throw new \InvalidArgumentException( 'places must not be empty.' );
		}
		if ( array() === $items ) {
			throw new \InvalidArgumentException( 'items must not be empty.' );
		}

		$payload = array(
			'info' => array( 'operator_request_id' => $operator_request_id ),
			'source' => array( 'platform_station' => array( 'platform_id' => $source_platform_id ), 'interval_utc' => $interval ),
			'items' => $items,
			'places' => $places,
			'billing_info' => array( 'payment_method' => 'already_paid', 'delivery_cost' => 0 ),
			'recipient_info' => $recipient,
			'particular_items_refuse' => false,
			'forbid_unboxing' => true,
		);

		return array_merge( $payload, $destination );
	}

	/** @param array<int,ShipmentAllocationItem> $items @return array<int,ShipmentAllocationItem> */
	private function aggregate_place_items( array $items ): array {
		$aggregated = array();
		foreach ( $items as $item ) {
			$key = $item->source_item_id . "\0" . (string) $item->unit_price_kopecks . "\0" . (string) $item->assessed_unit_price_kopecks;
			if ( isset( $aggregated[ $key ] ) ) {
				$old = $aggregated[ $key ];
				$aggregated[ $key ] = new ShipmentAllocationItem( $old->source_item_id, $old->identity, $old->name, $old->sku, $old->quantity + $item->quantity, $old->unit_price_kopecks, $old->assessed_unit_price_kopecks, $old->weight_g );
				continue;
			}
			$aggregated[ $key ] = $item;
		}
		return array_values( $aggregated );
	}

	/** @param array<string,mixed> $recipient @return array<string,mixed> */
	private function recipient( array $recipient ): array {
		$name = trim( preg_replace( '/\s+/', ' ', trim( (string) ( $recipient['last_name'] ?? '' ) . ' ' . (string) ( $recipient['first_name'] ?? '' ) ) ) ?? '' );
		if ( '' === $name ) {
			throw new \InvalidArgumentException( 'recipient name is required' );
		}
		$phone = $this->phone( (string) ( $recipient['phone'] ?? '' ) );
		if ( 1 !== preg_match( '/^\+7\d{10}$/', $phone ) ) {
			throw new \InvalidArgumentException( 'recipient phone is invalid' );
		}
		$email = trim( (string) ( $recipient['email'] ?? '' ) );
		if ( '' !== $email && false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
			throw new \InvalidArgumentException( 'recipient email is invalid' );
		}

		return array( 'first_name' => $name, 'last_name' => '', 'patronymic' => '', 'phone' => $phone, 'email' => $email );
	}

	/** @param array<string,mixed> $destination @return array<string,mixed> */
	private function destination( array $destination ): array {
		$mode = trim( (string) ( $destination['mode'] ?? '' ) );
		if ( ! in_array( $mode, array( 'pickup', 'courier' ), true ) ) {
			throw new \InvalidArgumentException( 'destination.mode must be pickup or courier' );
		}
		if ( 'pickup' === $mode ) {
			$platform_station_id = trim( (string) ( $destination['platform_station_id'] ?? '' ) );
			if ( '' === $platform_station_id ) {
				throw new \InvalidArgumentException( 'destination.platform_station_id is required for pickup' );
			}

			return array( 'destination' => array( 'type' => 'platform_station', 'platform_station' => array( 'platform_id' => $platform_station_id ) ), 'last_mile_policy' => 'self_pickup' );
		}
		$details = is_array( $destination['details'] ?? null ) ? $destination['details'] : array();

		return array( 'destination' => array( 'type' => 'custom_location', 'custom_location' => array( 'details' => $this->courier_details( $details ) ) ), 'last_mile_policy' => 'time_interval' );
	}

	/** @return array{from:string,to:string} */
	private function ready_interval( mixed $from, mixed $to ): array {
		if ( ! $from instanceof DateTimeInterface || ! $to instanceof DateTimeInterface ) {
			throw new \InvalidArgumentException( 'ready_from and ready_to must be DateTimeInterface values.' );
		}
		$from_immutable = \DateTimeImmutable::createFromInterface( $from );
		$to_immutable = \DateTimeImmutable::createFromInterface( $to );
		if ( (float) $to_immutable->format( 'U.u' ) < (float) $from_immutable->format( 'U.u' ) ) {
			throw new \InvalidArgumentException( 'ready_to must be greater than or equal to ready_from' );
		}

		return array( 'from' => $this->utc( $from_immutable ), 'to' => $this->utc( $to_immutable ) );
	}

	/** @param array<string,mixed> $details @return array<string,mixed> */
	private function courier_details( array $details ): array {
		$required = array( 'locality', 'street', 'house', 'full_address' );
		foreach ( $required as $field ) {
			if ( ! is_scalar( $details[ $field ] ?? null ) || '' === trim( (string) $details[ $field ] ) ) {
				throw new \InvalidArgumentException( 'destination.details.' . $field . ' is required for courier' );
			}
		}

		$sanitized = array();
		foreach ( array( 'country', 'region', 'locality', 'street', 'house', 'room', 'full_address', 'postal_code' ) as $field ) {
			if ( ! is_scalar( $details[ $field ] ?? null ) ) {
				continue;
			}
			$value = trim( (string) $details[ $field ] );
			if ( '' !== $value ) {
				$sanitized[ $field ] = $value;
			}
		}
		foreach ( array( 'geoId', 'geo_id' ) as $field ) {
			if ( ! isset( $details[ $field ] ) || '' === trim( (string) $details[ $field ] ) || ! is_numeric( $details[ $field ] ) ) {
				continue;
			}
			$sanitized[ $field ] = is_int( $details[ $field ] ) ? $details[ $field ] : (string) $details[ $field ];
		}

		return $sanitized;
	}

	private function utc( DateTimeInterface $value ): string {
		return \DateTimeImmutable::createFromInterface( $value )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d\TH:i:s.u\Z' );
	}

	private function phone( string $phone ): string {
		$digits = preg_replace( '/\D+/', '', $phone ) ?? '';
		if ( 11 === strlen( $digits ) && '8' === $digits[0] ) { return '+7' . substr( $digits, 1 ); }
		return 10 === strlen( $digits ) ? '+7' . $digits : ( '' !== $digits && '+' === $phone[0] ? '+' . $digits : $digits );
	}
}
