<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Domain\Address\Address;

defined( 'ABSPATH' ) || exit;

final class OrderStructuredAddressReader {
	public function trusted_snapshot( object $order ): ?OrderStructuredAddress {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return null;
		}
		$value = $order->get_meta( OrderStructuredAddress::META_KEY, true );
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $value ) ) {
			return null;
		}

		return OrderStructuredAddress::from_array( $value );
	}

	public function recipient_role( object $order ): string {
		if ( $this->shipping_address_filled( $order ) ) {
			return 'shipping';
		}

		return 'billing';
	}

	public function legacy_recipient_address( object $order ): Address {
		$role = $this->recipient_role( $order );
		return new Address(
			country_code: $this->order_value( $order, $role, 'country' ) ?: 'RU',
			region_name: $this->order_value( $order, $role, 'state' ),
			city: $this->order_value( $order, $role, 'city' ),
			postcode: preg_replace( '/\D+/', '', $this->order_value( $order, $role, 'postcode' ) ) ?: '',
			street: $this->order_value( $order, $role, 'address_1' ),
			apartment: $this->order_value( $order, $role, 'address_2' ),
			raw_address: $this->legacy_recipient_address_line( $order )
		);
	}

	public function legacy_recipient_address_line( object $order ): string {
		$role = $this->recipient_role( $order );
		return trim(
			implode(
				' ',
				array_filter(
					array(
						$this->order_value( $order, $role, 'postcode' ),
						$this->order_value( $order, $role, 'state' ),
						$this->order_value( $order, $role, 'city' ),
						$this->order_value( $order, $role, 'address_1' ),
						$this->order_value( $order, $role, 'address_2' ),
					),
					static fn( string $value ): bool => '' !== trim( $value )
				)
			)
		);
	}

	private function shipping_address_filled( object $order ): bool {
		foreach ( array( 'address_1', 'city', 'postcode' ) as $field ) {
			if ( '' !== $this->order_value( $order, 'shipping', $field ) ) {
				return true;
			}
		}

		return false;
	}

	private function order_value( object $order, string $role, string $field ): string {
		$method = 'get_' . $role . '_' . $field;
		if ( method_exists( $order, $method ) ) {
			$value = $order->{$method}();

			return is_scalar( $value ) ? trim( (string) $value ) : '';
		}

		return '';
	}
}
