<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;

defined( 'ABSPATH' ) || exit;

final class PekShipmentRecipientBuilder {
	/** @return array<string,mixed> */
	public function build_physical_recipient( object $order, ShipmentCreateRequest $request, string $receiver_warehouse_id ): array {
		$phone = $this->phone( $order );
		if ( '' === $phone ) {
			throw new \RuntimeException( 'Для выдачи ПЭК по СМС нужен телефон получателя.' );
		}
		$name = $this->name_parts( $order );
		if ( '' === $name['lastName'] ) {
			throw new \RuntimeException( 'Для заявки ПЭК нужна фамилия получателя.' );
		}
		if ( '' === $name['firstName'] ) {
			throw new \RuntimeException( 'Для заявки ПЭК нужно имя получателя.' );
		}
		$title = trim( implode( ' ', array_filter( array( $name['lastName'], $name['firstName'], $name['patronymic'] ), static fn( string $value ): bool => '' !== trim( $value ) ) ) );
		$receiver = array(
			'legalForm' => 3,
			'title' => $title,
			'individual' => array_filter( $name, static fn( string $value ): bool => '' !== $value ),
			'person' => $title,
			'personPhones' => array( array( 'phone' => $phone ) ),
		);
		$email = method_exists( $order, 'get_billing_email' ) ? trim( (string) $order->get_billing_email() ) : '';
		if ( '' !== $email && function_exists( 'is_email' ) && false === is_email( $email ) ) {
			throw new \RuntimeException( 'Некорректный email получателя ПЭК.' );
		}
		if ( '' !== $email ) {
			$receiver['email'] = $email;
		}
		if ( DeliveryType::PICKUP === $request->delivery_type ) {
			$receiver['warehouseId'] = $receiver_warehouse_id;
		} else {
			$receiver['addressStock'] = $this->courier_address( $order, $request );
		}

		return $receiver;
	}

	/** @return array<string,mixed> */
	public function build_legal_recipient(): array {
		return array( 'unsupported' => true, 'message' => 'Юридический получатель ПЭК будет добавлен в отдельной версии.' );
	}

	private function phone( object $order ): string {
		foreach ( array( 'get_shipping_phone', 'get_billing_phone' ) as $method ) {
			if ( method_exists( $order, $method ) ) {
				$phone = $this->normalize_ru_phone( (string) $order->{$method}() );
				if ( '' !== $phone ) {
					return $phone;
				}
			}
		}

		return '';
	}

	/** @return array{lastName:string,firstName:string,patronymic:string} */
	private function name_parts( object $order ): array {
		$last = method_exists( $order, 'get_shipping_last_name' ) ? trim( (string) $order->get_shipping_last_name() ) : '';
		$first = method_exists( $order, 'get_shipping_first_name' ) ? trim( (string) $order->get_shipping_first_name() ) : '';
		if ( '' === $last && '' === $first ) {
			$last = method_exists( $order, 'get_billing_last_name' ) ? trim( (string) $order->get_billing_last_name() ) : '';
			$first = method_exists( $order, 'get_billing_first_name' ) ? trim( (string) $order->get_billing_first_name() ) : '';
		}
		$middle = method_exists( $order, 'get_meta' ) ? trim( (string) $order->get_meta( '_billing_patronymic', true ) ) : '';

		return array( 'lastName' => $last, 'firstName' => $first, 'patronymic' => $middle );
	}

	private function normalize_ru_phone( string $value ): string {
		$value = preg_replace( '/[^\d+]/', '', $value ) ?? '';
		if ( 1 === preg_match( '/^8(\d{10})$/', $value, $matches ) ) {
			return '+7' . $matches[1];
		}
		if ( 1 === preg_match( '/^7(\d{10})$/', $value, $matches ) ) {
			return '+7' . $matches[1];
		}
		if ( 1 === preg_match( '/^\+7\d{10}$/', $value ) ) {
			return $value;
		}

		return '';
	}

	private function courier_address( object $order, ShipmentCreateRequest $request ): string {
		if ( 'RU' !== strtoupper( $request->recipient_address->country_code ) ) {
			throw new \RuntimeException( 'Создание отправлений ПЭК поддерживает только RU.' );
		}
		$address_1 = method_exists( $order, 'get_shipping_address_1' ) ? trim( (string) $order->get_shipping_address_1() ) : '';
		$house = trim( $request->recipient_address->house );
		if ( '' === $address_1 || ( '' === $house && ! $this->contains_house_token( $address_1 ) ) ) {
			throw new \RuntimeException( 'Для курьерской доставки ПЭК нужен полный адрес с улицей и номером дома.' );
		}
		$city = method_exists( $order, 'get_shipping_city' ) ? trim( (string) $order->get_shipping_city() ) : trim( $request->recipient_address->city );
		if ( '' === $city ) {
			throw new \RuntimeException( 'Для курьерской доставки ПЭК нужен полный адрес с улицей и номером дома.' );
		}
		$parts = array(
			'Россия',
			method_exists( $order, 'get_shipping_state' ) ? (string) $order->get_shipping_state() : '',
			$city,
			$address_1,
			method_exists( $order, 'get_shipping_address_2' ) ? (string) $order->get_shipping_address_2() : '',
		);
		$address = trim( implode( ', ', array_filter( $parts, static fn( string $part ): bool => '' !== trim( $part ) ) ) );
		if ( '' === $address ) {
			$address = trim( $request->recipient_address->raw_address );
		}
		if ( strlen( $address ) < 8 ) {
			throw new \RuntimeException( 'Для курьерской доставки ПЭК нужен полный адрес получателя.' );
		}

		return $address;
	}

	private function contains_house_token( string $value ): bool {
		return 1 === preg_match( '/(?:^|[\s,])(?:д\.?|дом|house)?\s*\d+[А-Яа-яA-Za-z0-9\/-]*(?:\s*(?:к|корп|корпус|стр|строение)\.?\s*\d+[А-Яа-яA-Za-z0-9\/-]*)?(?:$|[\s,])/u', $value );
	}
}
