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
		if ( '' === trim( (string) ( $request->meta['date_pickup'] ?? '' ) ) ) {
			$errors[] = 'Дата отправки DPD обязательна.';
		}
		foreach ( is_array( $request->meta['date_pickup_errors'] ?? null ) ? $request->meta['date_pickup_errors'] : array() as $error ) {
			if ( is_string( $error ) && '' !== trim( $error ) ) {
				$errors[] = trim( $error );
			}
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
		if ( '' === trim( (string) ( $request->recipient['name'] ?? '' ) ) ) {
			$errors[] = 'ФИО получателя обязательно.';
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
	 * Business payload passed to DpdSoapRequest; auth and the external orders wrapper are added by DpdApiClient.
	 *
	 * @return array<string,mixed>
	 */
	public function build( ShipmentCreateRequest $request ): array {
		return array(
			'header' => array(
				'datePickup' => (string) ( $request->meta['date_pickup'] ?? '' ),
				'senderAddress' => $this->sender_address( $request ),
				'pickupTimePeriod' => (string) ( $request->meta['pickup_time_period'] ?? '9-18' ),
			),
			'order' => array(
				'orderNumberInternal' => substr( (string) ( $request->meta['order_num'] ?? $request->order_id ), 0, 20 ),
				'serviceCode' => (string) ( $request->meta['service_code'] ?? '' ),
				'serviceVariant' => DeliveryType::PICKUP === $request->delivery_type ? 'ТТ' : 'ТД',
				'cargoNumPack' => count( $request->places ),
				'cargoWeight' => $this->cargo_weight_kg( $request ),
				'cargoVolume' => $this->cargo_volume_m3( $request ),
				'cargoRegistered' => false,
				'cargoValue' => round( (float) ( $request->meta['declared_value_rub'] ?? 0 ), 2 ),
				'cargoCategory' => (string) ( $request->meta['cargo_category'] ?? 'Товары' ),
				'receiverAddress' => $this->receiver_address( $request ),
				'parcel' => $this->parcels( $request ),
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function build_preview_body( ShipmentCreateRequest $request ): array {
		return array(
			'operation' => 'createOrder2',
			'service' => 'order2',
			'request' => $this->build( $request ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function sender_address( ShipmentCreateRequest $request ): array {
		$terminal = is_array( $request->meta['sender_terminal'] ?? null ) ? $request->meta['sender_terminal'] : array();

		return $this->clean_array(
			array(
				'name' => (string) ( $request->meta['sender_name'] ?? 'Walls' ),
				'terminalCode' => (string) ( $request->meta['pickup_terminal_code'] ?? '' ),
				'countryName' => 'Россия',
				'cityId' => (string) ( $request->meta['pickup_city_id'] ?? '' ),
				'city' => (string) ( $terminal['city_name'] ?? $request->meta['sender_city_name'] ?? '' ),
				'addressString' => (string) ( $terminal['address'] ?? $request->meta['shipment_point_address'] ?? '' ),
				'contactFio' => (string) ( $request->meta['sender_contact_name'] ?? 'Менеджер' ),
				'contactPhone' => (string) ( $request->meta['sender_phone'] ?? $request->recipient['phone'] ?? '' ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function receiver_address( ShipmentCreateRequest $request ): array {
		$base = array(
			'name' => (string) ( $request->recipient['name'] ?? '' ),
			'countryName' => 'Россия',
			'cityId' => (string) ( $request->meta['delivery_city_id'] ?? '' ),
			'city' => $request->recipient_address->city,
			'contactFio' => (string) ( $request->recipient['name'] ?? '' ),
			'contactPhone' => (string) ( $request->recipient['phone'] ?? '' ),
			'contactEmail' => (string) ( $request->recipient['email'] ?? '' ),
		);
		if ( DeliveryType::PICKUP === $request->delivery_type ) {
			$terminal = is_array( $request->meta['delivery_terminal'] ?? null ) ? $request->meta['delivery_terminal'] : array();
			$base['terminalCode'] = (string) ( $request->meta['delivery_terminal_code'] ?? '' );
			$base['addressString'] = (string) ( $terminal['address'] ?? $request->recipient_address->raw_address );
			$base['city'] = (string) ( $terminal['city_name'] ?? $request->recipient_address->city );
			return $this->clean_array( $base );
		}

		$base['index'] = preg_replace( '/\D+/', '', $request->recipient_address->postcode ) ?: '';
		$base['region'] = $request->recipient_address->region_name;
		$base['addressString'] = $request->recipient_address->raw_address;

		return $this->clean_array( $base );
	}

	private function cargo_weight_kg( ShipmentCreateRequest $request ): float {
		$total = 0;
		foreach ( $request->places as $place ) {
			if ( $place instanceof ShipmentPlace ) {
				$total += max( 0, $place->weight_g );
			}
		}

		return round( $total / 1000, 3 );
	}

	private function cargo_volume_m3( ShipmentCreateRequest $request ): float {
		$total = 0.0;
		foreach ( $request->places as $place ) {
			if ( $place instanceof ShipmentPlace ) {
				$total += max( 0, $place->length_cm ) * max( 0, $place->width_cm ) * max( 0, $place->height_cm ) / 1000000;
			}
		}

		return round( $total, 4 );
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
				'number' => (string) $place->place_number,
				'weight' => round( $place->weight_g / 1000, 3 ),
				'length' => $place->length_cm,
				'width' => $place->width_cm,
				'height' => $place->height_cm,
			);
		}

		return $parcels;
	}

	/**
	 * @param array<string,mixed> $value
	 * @return array<string,mixed>
	 */
	private function clean_array( array $value ): array {
		return array_filter(
			$value,
			static fn ( mixed $item ): bool => ! ( null === $item || '' === $item || array() === $item )
		);
	}
}