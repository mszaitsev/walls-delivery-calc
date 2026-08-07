<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;

defined( 'ABSPATH' ) || exit;

final class PekShipmentRecipientBuilder {
	private PekRuPhoneNormalizer $phones;

	public function __construct( private PekShipmentCourierAddressResolver $courier_addresses, ?PekRuPhoneNormalizer $phones = null ) {
		$this->phones = $phones ?? new PekRuPhoneNormalizer();
	}

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
			$receiver['addressStock'] = $this->courier_addresses->address_stock( $request->recipient_address );
		}

		return $receiver;
	}

	/** @return array<string,mixed> */
	public function build_legal_recipient(): array {
		return array( 'unsupported' => true, 'message' => 'Юридический получатель ПЭК будет добавлен в отдельной версии.' );
	}

	private function phone( object $order ): string {
		if ( method_exists( $order, 'get_shipping_phone' ) ) {
			$shipping = trim( (string) $order->get_shipping_phone() );
			if ( '' !== $shipping ) {
				try {
					return $this->phones->normalize( $shipping );
				} catch ( \InvalidArgumentException ) {
					throw new \RuntimeException( 'Для выдачи ПЭК по СМС нужен корректный телефон получателя.' );
				}
			}
		}
		if ( method_exists( $order, 'get_billing_phone' ) ) {
			$billing = trim( (string) $order->get_billing_phone() );
			if ( '' !== $billing ) {
				try {
					return $this->phones->normalize( $billing );
				} catch ( \InvalidArgumentException ) {
					throw new \RuntimeException( 'Для выдачи ПЭК по СМС нужен корректный телефон получателя.' );
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

}
