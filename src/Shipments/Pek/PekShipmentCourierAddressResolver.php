<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Address\Address;

defined( 'ABSPATH' ) || exit;

final class PekShipmentCourierAddressResolver {
	private const PUBLIC_ERROR = 'Для курьерской доставки ПЭК нужен полный адрес с улицей и номером дома.';

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
		$raw = $this->format( $city, $street, $house, $apartment );

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
	private function parse_raw( string $value ): array {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		if ( '' === $value ) {
			return array( 'street' => '', 'house' => '' );
		}
		if ( 1 !== preg_match( '/^(?<street>.+?)(?:,\s*(?:д\.?\s*|дом\s*)?|(?:\s+д\.?\s*|\s+дом\s+))(?<house>\d+[А-Яа-яA-Za-z0-9\/-]*(?:\s*(?:к|корп|корпус|стр|строение)\.?\s*\d+[А-Яа-яA-Za-z0-9\/-]*)?)\s*$/u', $value, $matches ) ) {
			return array( 'street' => '', 'house' => '' );
		}
		$street = trim( (string) $matches['street'], " \t\n\r\0\x0B," );
		$house = trim( (string) $matches['house'] );

		return array( 'street' => $street, 'house' => $house );
	}

	private function format( string $city, string $street, string $house, string $apartment ): string {
		$parts = array( 'Россия', $city, $street, 'дом ' . $house );
		if ( '' !== $apartment ) {
			$parts[] = 'кв. ' . $apartment;
		}

		return implode( ', ', $parts );
	}
}
