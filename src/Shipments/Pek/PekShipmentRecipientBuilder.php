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
		$name = trim( implode( ' ', array_filter( array(
			method_exists( $order, 'get_shipping_last_name' ) ? (string) $order->get_shipping_last_name() : '',
			method_exists( $order, 'get_shipping_first_name' ) ? (string) $order->get_shipping_first_name() : '',
		) ) ) );
		if ( '' === $name ) {
			$name = trim( implode( ' ', array_filter( array(
				method_exists( $order, 'get_billing_last_name' ) ? (string) $order->get_billing_last_name() : '',
				method_exists( $order, 'get_billing_first_name' ) ? (string) $order->get_billing_first_name() : '',
			) ) ) );
		}
		if ( '' === $name ) {
			throw new \RuntimeException( 'Для заявки ПЭК нужно ФИО получателя.' );
		}
		$receiver = array(
			'legalForm' => 3,
			'individual' => array( 'name' => $name ),
			'person' => $name,
			'phone' => $phone,
			'email' => method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '',
		);
		if ( DeliveryType::PICKUP === $request->delivery_type ) {
			$receiver['warehouseId'] = $receiver_warehouse_id;
		} else {
			$receiver['address'] = $this->courier_address( $order, $request );
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
				$phone = preg_replace( '/[^\d+]/', '', (string) $order->{$method}() ) ?? '';
				if ( strlen( $phone ) >= 10 ) {
					return $phone;
				}
			}
		}

		return '';
	}

	private function courier_address( object $order, ShipmentCreateRequest $request ): string {
		$parts = array(
			'Россия',
			method_exists( $order, 'get_shipping_state' ) ? (string) $order->get_shipping_state() : '',
			method_exists( $order, 'get_shipping_city' ) ? (string) $order->get_shipping_city() : '',
			method_exists( $order, 'get_shipping_address_1' ) ? (string) $order->get_shipping_address_1() : '',
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
}
