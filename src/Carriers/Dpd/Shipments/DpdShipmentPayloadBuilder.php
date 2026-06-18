<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Shipments;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentPayloadBuilder {
	/**
	 * @return array<int,string>
	 */
	public function validate( ShipmentCreateRequest $request ): array {
		$errors = array();
		if ( DpdSettings::CARRIER_KEY !== $request->carrier_key ) {
			$errors[] = 'Заказ не относится к DPD.';
		}
		if ( '' === trim( (string) ( $request->meta['service_code'] ?? '' ) ) ) {
			$errors[] = 'DPD serviceCode обязателен.';
		}
		if ( '' === trim( (string) ( $request->meta['pickup_city_id'] ?? '' ) ) ) {
			$errors[] = 'DPD pickup cityId обязателен.';
		}
		if ( '' === trim( (string) ( $request->meta['delivery_city_id'] ?? '' ) ) ) {
			$errors[] = 'DPD delivery cityId обязателен.';
		}
		if ( '' === trim( (string) ( $request->meta['pickup_terminal_code'] ?? '' ) ) ) {
			$errors[] = 'DPD pickup terminalCode отправителя обязателен.';
		}
		if ( DeliveryType::PICKUP === $request->delivery_type && '' === trim( (string) ( $request->meta['delivery_terminal_code'] ?? '' ) ) ) {
			$errors[] = 'DPD delivery terminalCode получателя обязателен для доставки до ПВЗ.';
		}
		if ( DeliveryType::COURIER === $request->delivery_type && '' === trim( $request->recipient_address->raw_address ) ) {
			$errors[] = 'Адрес получателя обязателен для курьерской доставки DPD.';
		}
		if ( DeliveryType::COURIER === $request->delivery_type && empty( $request->meta['normalization_valid'] ) ) {
			$errors[] = 'Адрес DPD курьер нужно обработать перед предпросмотром payload.';
		}
		if ( '' === trim( (string) ( $request->recipient['phone'] ?? '' ) ) ) {
			$errors[] = 'Телефон получателя обязателен.';
		}
		if ( array() === $request->places ) {
			$errors[] = 'Добавьте хотя бы одно грузоместо.';
		}
		foreach ( $request->places as $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				$errors[] = 'Грузоместо имеет неверный формат.';
				continue;
			}
			if ( $place->weight_g <= 0 || $place->length_cm <= 0 || $place->width_cm <= 0 || $place->height_cm <= 0 ) {
				$errors[] = 'Вес и габариты каждого грузоместа должны быть больше 0.';
				break;
			}
		}

		return array_values( array_unique( $errors ) );
	}

	/**
	 * @return array<int,string>
	 */
	public function warnings( ShipmentCreateRequest $request ): array {
		$warnings = array();
		if ( empty( $request->meta['default_sender_terminal_configured'] ) ) {
			$warnings[] = 'ПВЗ отправителя по умолчанию не задан.';
		}

		return $warnings;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build( ShipmentCreateRequest $request ): array {
		$delivery_type = DeliveryType::PICKUP === $request->delivery_type ? 'pickup' : 'courier';
		$payload = array(
			'operation' => 'createOrder',
			'request' => array(
				'order' => array(
					'orderNumberInternal' => (string) ( $request->meta['order_num'] ?? $request->order_id ),
					'serviceCode' => (string) ( $request->meta['service_code'] ?? '' ),
					'serviceName' => (string) ( $request->meta['tariff_title'] ?? '' ),
					'serviceVariant' => DeliveryType::PICKUP === $request->delivery_type ? 'ТТ' : 'ТД',
					'deliveryType' => $delivery_type,
					'pickup' => array(
						'cityId' => (string) ( $request->meta['pickup_city_id'] ?? '' ),
						'terminalCode' => (string) ( $request->meta['pickup_terminal_code'] ?? '' ),
					),
					'delivery' => $this->delivery_block( $request ),
					'sender' => array(
						'terminalCode' => (string) ( $request->meta['pickup_terminal_code'] ?? '' ),
						'terminal' => is_array( $request->meta['sender_terminal'] ?? null ) ? $request->meta['sender_terminal'] : array(),
					),
					'receiver' => array(
						'name' => (string) ( $request->recipient['name'] ?? '' ),
						'phone' => (string) ( $request->recipient['phone'] ?? '' ),
						'email' => (string) ( $request->recipient['email'] ?? '' ),
						'address' => $request->recipient_address->raw_address,
					),
					'cargoNumPack' => count( $request->places ),
					'cargoValue' => round( (float) ( $request->meta['declared_value_rub'] ?? 0 ), 2 ),
					'cargoRegistered' => false,
					'parcel' => $this->parcels( $request ),
					'comment' => (string) ( $request->meta['comment'] ?? '' ),
				),
			),
		);
		if ( '' === $payload['request']['order']['comment'] ) {
			unset( $payload['request']['order']['comment'] );
		}

		return $payload;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function delivery_block( ShipmentCreateRequest $request ): array {
		$delivery = array(
			'cityId' => (string) ( $request->meta['delivery_city_id'] ?? '' ),
		);
		if ( DeliveryType::PICKUP === $request->delivery_type ) {
			$delivery['terminalCode'] = (string) ( $request->meta['delivery_terminal_code'] ?? '' );
			$delivery['terminal'] = is_array( $request->meta['delivery_terminal'] ?? null ) ? $request->meta['delivery_terminal'] : array();
			return $delivery;
		}
		$delivery['address'] = $request->recipient_address->raw_address;

		return $delivery;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function parcels( ShipmentCreateRequest $request ): array {
		$parcels = array();
		foreach ( $request->places as $place ) {
			if ( ! $place instanceof ShipmentPlace ) {
				continue;
			}
			$parcels[] = array(
				'number' => $place->place_number,
				'weight' => round( $place->weight_g / 1000, 3 ),
				'length' => $place->length_cm,
				'width' => $place->width_cm,
				'height' => $place->height_cm,
				'quantity' => 1,
			);
		}

		return $parcels;
	}
}
