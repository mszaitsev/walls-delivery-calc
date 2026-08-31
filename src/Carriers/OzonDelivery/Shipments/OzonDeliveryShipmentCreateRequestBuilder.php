<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
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
		$method_id = $this->settings->shipment_method_id();
		if ( $method_id <= 0 ) {
			$errors[] = 'Не указан shipment_method_id Ozon.';
		}
		$point_id = $this->point_id( $request );
		if ( $point_id <= 0 ) {
			$errors[] = 'Не выбран ПВЗ Ozon для отправления.';
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
			'delivery' => array(
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
				'delivery_point_id' => $point_id,
				'shipment_method_id' => $method_id,
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
