<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Phone\RussianPhoneNormalizer;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentCreateRequestBuilder {
	public function __construct(
		private OzonDeliverySettings $settings,
		private RussianPhoneNormalizer $phones,
		private OzonDeliveryShipmentDescriptionBuilder $descriptions,
		private OzonDeliveryShipmentAllocationValueResolver $values,
		private OzonDeliveryShipmentExternalIdResolver $external_ids
	) {}

	/** @return array<int,string> */
	public function validate( object $order, ShipmentCreateRequest $request ): array {
		return $this->prepare( $order, $request )['errors'];
	}

	/**
	 * @return array{body:array<string,mixed>,summary:array<string,mixed>,errors:array<int,string>}
	 */
	public function prepare( object $order, ShipmentCreateRequest $request ): array {
		$errors = $request->validate();
		if ( OzonDeliverySettings::CARRIER_KEY !== $request->carrier_key ) {
			$errors[] = 'Для отправления выбран не Ozon Delivery.';
		}
		$method_id = DeliveryType::COURIER === $request->delivery_type ? $this->settings->courier_shipment_method_id() : $this->settings->pickup_shipment_method_id();
		if ( $method_id <= 0 ) {
			$errors[] = 'Не указан shipment_method_id Ozon.';
		}
		$point_id = DeliveryType::PICKUP === $request->delivery_type ? $this->point_id( $request ) : 0;
		if ( DeliveryType::PICKUP === $request->delivery_type && $point_id <= 0 ) {
			$errors[] = 'Не выбран ПВЗ Ozon для отправления.';
		}
		$courier_delivery = DeliveryType::COURIER === $request->delivery_type ? $this->courier_delivery( $request ) : array();
		if ( DeliveryType::COURIER === $request->delivery_type && array() === $courier_delivery ) {
			$errors[] = 'Проверьте и подтвердите адрес курьерской доставки Ozon.';
		}
		$phone = $this->phones->normalize( $request->recipient['phone'] ?? '' );
		if ( '' === $phone ) {
			$errors[] = 'Укажите корректный телефон получателя для Ozon.';
		}
		$name = trim( (string) ( $request->recipient['name'] ?? '' ) );
		if ( '' === $name ) {
			$errors[] = 'Укажите ФИО получателя для Ozon.';
		}
		$item_rows = is_array( $request->meta['shipment_item_rows'] ?? null ) ? $request->meta['shipment_item_rows'] : array();
		$value_result = $this->values->resolve( $order, $item_rows, $request->places );
		$errors = array_merge( $errors, $value_result['errors'] );
		$total = count( $request->places );
		if ( $total < 1 || $total > 100 ) {
			$errors[] = 'Ozon поддерживает от 1 до 100 грузомест в одном заказе.';
		}
		$order_number = $this->order_number( $order, $request );
		$postings = array();
		$places_summary = array();
		foreach ( array_values( $request->places ) as $index => $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				continue;
			}
			$place_number = $place->place_number > 0 ? $place->place_number : ( $index + 1 );
			$declared = Money::from_kopecks( (int) ( $value_result['place_values'][ $place_number ] ?? 0 ) );
			$postings[] = array(
				'request_id' => $place_number,
				'posting_external_id' => $this->external_ids->posting_external_id( $order_number, $place_number, $total ),
				'shipment_method_id' => $method_id,
				'description' => $this->descriptions->build( $order_number, $place_number, $total ),
				'declared_value' => $this->money_object( $declared ),
				'dimensions' => array(
					'weight_g' => max( 1, $place->weight_g ),
					'length_mm' => max( 1, $place->length_cm * 10 ),
					'width_mm' => max( 1, $place->width_cm * 10 ),
					'height_mm' => max( 1, $place->height_cm * 10 ),
				),
			);
			$places_summary[] = array(
				'place_number' => $place_number,
				'weight_g' => max( 1, $place->weight_g ),
				'length_cm' => max( 1, $place->length_cm ),
				'width_cm' => max( 1, $place->width_cm ),
				'height_cm' => max( 1, $place->height_cm ),
				'declared_value_rub' => $this->rubles( $declared ),
			);
		}
		$body = array(
			'order_external_id' => $this->external_ids->order_external_id( $order_number ),
			'recipient' => array(
				'phone_number' => $phone,
				'full_name' => $name,
			),
			'delivery' => DeliveryType::COURIER === $request->delivery_type
				? array( 'courier' => $courier_delivery )
				: array(
					'delivery_point' => array(
						'delivery_point_id' => $point_id,
					),
				),
			'postings' => $postings,
		);

		return array(
			'body' => array() === $errors ? $body : array(),
			'summary' => array(
				'order_external_id' => $body['order_external_id'],
				'delivery_type' => $request->delivery_type,
				'delivery_point_id' => $point_id,
				'shipment_method_id' => $method_id,
				'courier_address_source' => (string) ( $request->meta['courier_address_source'] ?? $request->meta['courier_address_snapshot']['source'] ?? '' ),
				'courier_coordinates_present' => DeliveryType::COURIER === $request->delivery_type && isset( $courier_delivery['coordinates'] ),
				'places_count' => count( $postings ),
				'postings_count' => count( $postings ),
				'places' => $places_summary,
				'allocation' => $value_result['summary'],
				'idempotency_key_present' => '' !== (string) ( $request->meta['creation_attempt_id'] ?? '' ),
			),
			'errors' => array_values( array_unique( $errors ) ),
		);
	}

	private function point_id( ShipmentCreateRequest $request ): int {
		$code = $request->pickup_point?->point_code ?: (string) ( $request->meta['pickup_point_code'] ?? '' );
		$digits = preg_replace( '/\D+/', '', $code ) ?? '';

		return '' !== $digits ? (int) $digits : 0;
	}

	/** @return array<string,mixed> */
	private function courier_delivery( ShipmentCreateRequest $request ): array {
		$snapshot = is_array( $request->meta['courier_address_snapshot'] ?? null ) ? $request->meta['courier_address_snapshot'] : array();
		$lat = $snapshot['geo_lat'] ?? null;
		$lon = $snapshot['geo_lon'] ?? null;
		if ( ! $this->coordinate_pair_valid( $lat, $lon ) ) {
			return array();
		}
		$country = trim( (string) ( $snapshot['country'] ?? '' ) );
		if ( '' === $country ) {
			$country = trim( $request->recipient_address->country_name );
		}
		if ( '' === $country ) {
			$country = 'Россия';
		}
		$required = array(
			'zip_code' => preg_replace( '/\D+/', '', (string) ( $snapshot['postcode'] ?? $request->recipient_address->postcode ) ) ?: '',
			'country' => $country,
			'region' => (string) ( $snapshot['region'] ?? $request->recipient_address->region_name ),
			'city' => (string) ( $snapshot['city'] ?? $request->recipient_address->city ),
			'street' => (string) ( $snapshot['street'] ?? $request->recipient_address->street ),
		);
		foreach ( $required as $value ) {
			if ( '' === trim( (string) $value ) ) {
				return array();
			}
		}
		$courier = array(
			'coordinates' => array(
				'latitude' => (float) $lat,
				'longitude' => (float) $lon,
			),
			'zip_code' => $required['zip_code'],
			'country' => $required['country'],
			'region' => $required['region'],
			'city' => $required['city'],
			'street' => $required['street'],
		);
		foreach (
			array(
				'house_number' => (string) ( $snapshot['house'] ?? $snapshot['stead'] ?? $request->recipient_address->house ),
				'apartment' => (string) ( $request->meta['ozon_courier_apartment'] ?? $snapshot['flat'] ?? $request->recipient_address->apartment ),
				'entrance' => (string) ( $request->meta['ozon_courier_entrance'] ?? '' ),
				'floor' => (string) ( $request->meta['ozon_courier_floor'] ?? '' ),
				'intercom' => (string) ( $request->meta['ozon_courier_intercom'] ?? '' ),
			) as $key => $value
		) {
			$value = trim( $value );
			if ( '' !== $value ) {
				$courier[ $key ] = $value;
			}
		}

		return $courier;
	}

	private function coordinate_pair_valid( mixed $lat, mixed $lon ): bool {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lon ) ) {
			return false;
		}
		$lat = (float) $lat;
		$lon = (float) $lon;

		return $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180 && ! ( 0.0 === $lat && 0.0 === $lon );
	}

	private function order_number( object $order, ShipmentCreateRequest $request ): string {
		return method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) ( $request->meta['order_num'] ?? $request->order_id );
	}

	/** @return array{amount:string,currency_code:string} */
	private function money_object( Money $money ): array {
		return array( 'amount' => $this->rubles( $money ), 'currency_code' => 'RUB' );
	}

	private function rubles( Money $money ): string {
		return number_format( $money->get_kopecks() / 100, 2, '.', '' );
	}
}
