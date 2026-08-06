<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Address\Address;

defined( 'ABSPATH' ) || exit;

final class PekShipmentCourierAddressResolver {
	private const PUBLIC_ERROR = 'Для курьерской доставки ПЭК нужен полный адрес с улицей и номером дома.';

	/** @param array<string,mixed> $calculation_data */
	public function from_order( object $order, array $calculation_data = array() ): Address {
		unset( $calculation_data );

		return $this->from_order_with_evidence( $order )['address'];
	}

	/** @return array{address:Address,evidence:array<string,mixed>} */
	public function from_order_with_evidence( object $order ): array {
		foreach ( array( 'shipping', 'billing' ) as $scope ) {
			$address = $this->dadata_address( $order, $scope );
			if ( $address instanceof Address ) {
				$normalized = $this->normalize( $address );
				return array( 'address' => $normalized, 'evidence' => $this->evidence( $normalized, $scope . '_dadata' ) );
			}
		}

		$woo = $this->woo_address( $order );
		try {
			$normalized = $this->normalize( $woo );
			return array( 'address' => $normalized, 'evidence' => $this->evidence( $normalized, 'woo_structured' ) );
		} catch ( \RuntimeException ) {
			$parsed = $this->parse_raw( $woo->raw_address );
			$parsed_address = new Address(
				country_code: 'RU',
				region_name: $woo->region_name,
				city: $woo->city,
				postcode: $woo->postcode,
				street: $parsed['street'],
				house: $parsed['house'],
				apartment: $this->first_non_empty( $woo->apartment, $parsed['apartment'] ),
				raw_address: $woo->raw_address,
				fias_id: $woo->fias_id,
				gar_id: $woo->gar_id
			);
			$normalized = $this->normalize( $parsed_address );
			return array( 'address' => $normalized, 'evidence' => $this->evidence( $normalized, 'parsed_address_1' ) );
		}
	}

	public function normalize( Address $address ): Address {
		if ( 'RU' !== strtoupper( trim( $address->country_code ) ) ) {
			throw new \RuntimeException( 'Создание отправлений ПЭК поддерживает только RU.' );
		}
		$city = trim( $address->city ) ?: trim( $address->settlement );
		$street = trim( $address->street );
		$house = $this->normalize_house( $address->house );
		$apartment = $this->normalize_apartment( $address->apartment );
		$raw = trim( $address->raw_address );
		if ( '' === $street || '' === $house ) {
			$parsed = $this->parse_raw( '' !== $raw ? $raw : $street );
			$street = '' !== $street ? $street : $parsed['street'];
			$house = '' !== $house ? $house : $this->normalize_house( $parsed['house'] );
			$apartment = '' !== $apartment ? $apartment : $this->normalize_apartment( $parsed['apartment'] );
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
			'/,\s*(?:кв\.?|квартира|офис|помещение)\s*(?<apartment>[А-Яа-яA-Za-z0-9\/-]+)\s*$/u',
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
		$house = $this->normalize_house( (string) $matches['house'] );

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
			$parts[] = 'кв. ' . $this->normalize_apartment( $apartment );
		}
		unset( $postcode );

		return implode( ', ', $parts );
	}

	private function same_location_name( string $left, string $right ): bool {
		return '' !== $right && $this->normalize_location_name( $left ) === $this->normalize_location_name( $right );
	}

	private function normalize_location_name( string $value ): string {
		$value = str_replace( array( 'город федерального значения', 'г.', 'город' ), '', $value );
		$value = $this->lower( $value );
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', '', $value ) ?? $value;

		return trim( $value );
	}

	private function dadata_address( object $order, string $scope ): ?Address {
		$status = $this->meta_string( $order, '_' . $scope . '_dadata_status' );
		if ( ! in_array( $status, array( 'house_selected', 'resolved' ), true ) ) {
			return null;
		}
		$city = $this->first_non_empty(
			$this->meta_string( $order, '_' . $scope . '_dadata_city_with_type' ),
			$this->meta_string( $order, '_' . $scope . '_dadata_city' ),
			$this->meta_string( $order, '_' . $scope . '_dadata_settlement_with_type' ),
			$this->meta_string( $order, '_' . $scope . '_dadata_settlement' )
		);
		$street = $this->first_non_empty( $this->meta_string( $order, '_' . $scope . '_dadata_street_with_type' ), $this->meta_string( $order, '_' . $scope . '_dadata_street' ) );
		$house = $this->normalize_house( $this->meta_string( $order, '_' . $scope . '_dadata_house' ) );
		if ( '' === $city || '' === $street || '' === $house ) {
			return null;
		}
		$region = $this->first_non_empty( $this->meta_string( $order, '_' . $scope . '_dadata_region_with_type' ), $this->meta_string( $order, '_' . $scope . '_dadata_region' ) );
		$block = $this->normalize_block( $this->meta_string( $order, '_' . $scope . '_dadata_block' ) );
		if ( '' !== $block && ! str_contains( $this->lower( $house ), $this->lower( $block ) ) ) {
			$house .= ' ' . $block;
		}
		$apartment = $this->normalize_apartment( $this->meta_string( $order, '_' . $scope . '_dadata_flat' ) );
		$postcode = $this->order_method_string( $order, 'get_' . $scope . '_postcode' );

		return new Address( country_code: 'RU', region_name: $region, city: $city, postcode: $postcode, street: $street, house: $house, apartment: $apartment, raw_address: '' );
	}

	private function woo_address( object $order ): Address {
		return new Address(
			country_code: $this->first_non_empty( $this->order_method_string( $order, 'get_shipping_country' ), 'RU' ),
			region_name: $this->order_method_string( $order, 'get_shipping_state' ),
			city: $this->order_method_string( $order, 'get_shipping_city' ),
			postcode: $this->order_method_string( $order, 'get_shipping_postcode' ),
			street: '',
			house: '',
			apartment: $this->normalize_apartment( $this->order_method_string( $order, 'get_shipping_address_2' ) ),
			raw_address: $this->order_method_string( $order, 'get_shipping_address_1' ),
			fias_id: $this->meta_string( $order, '_wdc_platform_fias_id' ),
			gar_id: $this->meta_string( $order, '_wdc_platform_gar_id' )
		);
	}

	/** @return array<string,mixed> */
	private function evidence( Address $address, string $source ): array {
		$canonical = $this->format( trim( $address->region_name ), trim( $address->city ) ?: trim( $address->settlement ), trim( $address->street ), trim( $address->house ), trim( $address->apartment ), '' );

		return array(
			'courier_address_source' => $source,
			'courier_region_present' => '' !== trim( $address->region_name ),
			'courier_city_present' => '' !== trim( $address->city ) || '' !== trim( $address->settlement ),
			'courier_street_present' => '' !== trim( $address->street ),
			'courier_house_present' => '' !== trim( $address->house ),
			'courier_apartment_present' => '' !== trim( $address->apartment ),
			'courier_postcode_present' => '' !== trim( $address->postcode ),
			'courier_address_hash' => hash( 'sha256', $canonical ),
		);
	}

	private function normalize_house( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		$value = preg_replace( '/^(?:д\.?|дом)\s*/iu', '', $value ) ?? $value;

		return trim( $value );
	}

	private function normalize_apartment( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		$value = preg_replace( '/^(?:квартира|помещение|офис|кв\.?)\s*/iu', '', $value ) ?? $value;

		return trim( $value );
	}

	private function normalize_block( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 1 === preg_match( '/^(?:к|корп\.?|корпус|стр\.?|строение)\s*/iu', $value ) ) {
			return $value;
		}

		return $value;
	}

	private function order_method_string( object $order, string $method ): string {
		return method_exists( $order, $method ) ? trim( (string) $order->{$method}() ) : '';
	}

	private function meta_string( object $order, string $key ): string {
		return method_exists( $order, 'get_meta' ) ? trim( (string) $order->get_meta( $key, true ) ) : '';
	}

	private function lower( string $value ): string {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $value, 'UTF-8' );
		}

		return strtr(
			strtolower( $value ),
			'АБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ',
			'абвгдеёжзийклмнопрстуфхцчшщъыьэюя'
		);
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
