<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Address\Address;

defined( 'ABSPATH' ) || exit;

final class PekShipmentCourierAddressResolver {
	private const PUBLIC_ERROR = 'Для курьерской доставки ПЭК нужен полный адрес с улицей и номером дома.';

	/** @param array<string,mixed> $calculation_data */
	public function from_order( object $order, array $calculation_data = array() ): Address {
		$destination = is_array( $calculation_data['destination'] ?? null ) ? $calculation_data['destination'] : array();
		$structured = is_array( $destination['address'] ?? null ) ? $destination['address'] : array();
		$street = $this->first_non_empty( $structured['street'] ?? '', $structured['street_name'] ?? '', $structured['streetName'] ?? '' );
		$house = $this->first_non_empty( $structured['house'] ?? '', $structured['house_no'] ?? '', $structured['houseNo'] ?? '' );
		$apartment = $this->first_non_empty( $structured['apartment'] ?? '', $structured['flat'] ?? '', method_exists( $order, 'get_shipping_address_2' ) ? $order->get_shipping_address_2() : '' );
		$city = $this->first_non_empty( $structured['city'] ?? '', $structured['settlement'] ?? '', $destination['city'] ?? '', method_exists( $order, 'get_shipping_city' ) ? $order->get_shipping_city() : '' );
		$region = $this->first_non_empty( $structured['region'] ?? '', $structured['region_name'] ?? '', $destination['region'] ?? '', method_exists( $order, 'get_shipping_state' ) ? $order->get_shipping_state() : '' );
		$postcode = $this->first_non_empty( $structured['postcode'] ?? '', $structured['postal_code'] ?? '', method_exists( $order, 'get_shipping_postcode' ) ? $order->get_shipping_postcode() : '' );
		$raw = $this->first_non_empty( $structured['raw_address'] ?? '', $destination['address'] ?? '', method_exists( $order, 'get_shipping_address_1' ) ? $order->get_shipping_address_1() : '' );
		if ( '' === $street || '' === $house ) {
			$parsed = $this->parse_raw( $raw );
			$street = '' !== $street ? $street : $parsed['street'];
			$house = '' !== $house ? $house : $parsed['house'];
			$apartment = '' !== $apartment ? $apartment : $parsed['apartment'];
		}

		return $this->normalize(
			new Address(
				country_code: 'RU',
				region_name: $region,
				city: $city,
				postcode: $postcode,
				street: $street,
				house: $house,
				apartment: $apartment,
				raw_address: $raw,
				fias_id: method_exists( $order, 'get_meta' ) ? (string) $order->get_meta( '_wdc_platform_fias_id', true ) : '',
				gar_id: method_exists( $order, 'get_meta' ) ? (string) $order->get_meta( '_wdc_platform_gar_id', true ) : ''
			)
		);
	}

	public function normalize( Address $address ): Address {
		if ( 'RU' !== strtoupper( trim( $address->country_code ) ) ) {
			throw new \RuntimeException( 'Создание отправлений ПЭК поддерживает только RU.' );
		}
		$city = trim( $address->city ) ?: trim( $address->settlement );
		$street = trim( $address->street );
		$house = trim( $address->house );
		$apartment = trim( $address->apartment );
		$raw = trim( $address->raw_address );
		if ( '' === $street || '' === $house ) {
			$parsed = $this->parse_raw( '' !== $raw ? $raw : $street );
			$street = '' !== $street ? $street : $parsed['street'];
			$house = '' !== $house ? $house : $parsed['house'];
		}
		if ( '' === $city || '' === $street || '' === $house ) {
			throw new \RuntimeException( self::PUBLIC_ERROR );
		}
		$raw = $this->format( trim( $address->region_name ), $city, $street, $house, $apartment, trim( $address->postcode ) );

		return new Address(
			country_code: 'RU',
			region_name: $address->region_name,
			city: $city,
			postcode: $address->postcode,
			street: $street,
			house: $house,
			apartment: $apartment,
			raw_address: $raw,
			fias_id: $address->fias_id,
			gar_id: $address->gar_id,
			normalized: $address->normalized,
			fallback: $address->fallback
		);
	}

	public function address_stock( Address $address ): string {
		$normalized = $this->normalize( $address );

		return $normalized->raw_address;
	}

	/** @return array{street:string,house:string} */
	/** @return array{street:string,house:string,apartment:string} */
	private function parse_raw( string $value ): array {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		if ( '' === $value ) {
			return array( 'street' => '', 'house' => '', 'apartment' => '' );
		}
		$apartment = '';
		$value = preg_replace_callback(
			'/,\s*(?:кв\.?|квартира|офис)\s*(?<apartment>[А-Яа-яA-Za-z0-9\/-]+)\s*$/u',
			static function ( array $matches ) use ( &$apartment ): string {
				$apartment = trim( (string) $matches['apartment'] );
				return '';
			},
			$value
		) ?? $value;
		if ( 1 !== preg_match( '/^(?<street>.+?)(?:,\s*(?:д\.?\s*|дом\s*)?|(?:\s+д\.?\s*|\s+дом\s+))(?<house>\d+[А-Яа-яA-Za-z0-9\/-]*(?:\s*(?:к|корп|корпус|стр|строение)\.?\s*\d+[А-Яа-яA-Za-z0-9\/-]*)?)\s*$/u', $value, $matches ) ) {
			return array( 'street' => '', 'house' => '', 'apartment' => '' );
		}
		$street = trim( (string) $matches['street'], " \t\n\r\0\x0B," );
		$house = trim( (string) $matches['house'] );

		return array( 'street' => $street, 'house' => $house, 'apartment' => $apartment );
	}

	private function format( string $region, string $city, string $street, string $house, string $apartment, string $postcode ): string {
		$parts = array( 'Россия' );
		if ( '' !== $region && ! $this->same_location_name( $region, $city ) ) {
			$parts[] = $region;
		}
		$parts[] = $city;
		$parts[] = $street;
		$parts[] = 'дом ' . $house;
		if ( '' !== $apartment ) {
			$parts[] = 'кв. ' . $apartment;
		}
		if ( '' !== $postcode ) {
			$parts[] = $postcode;
		}

		return implode( ', ', $parts );
	}

	private function same_location_name( string $left, string $right ): bool {
		return '' !== $right && $this->normalize_location_name( $left ) === $this->normalize_location_name( $right );
	}

	private function normalize_location_name( string $value ): string {
		$value = str_replace( array( 'город федерального значения', 'г.', 'город' ), '', $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', '', $value ) ?? $value;

		return trim( $value );
	}

	private function first_non_empty( mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}

		return '';
	}
}
