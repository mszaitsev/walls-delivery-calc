<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Domain\Address\Address;

defined( 'ABSPATH' ) || exit;

final class PekShipmentCourierAddressResolver {
	private const PUBLIC_ERROR = 'Для курьерской доставки ПЭК нужен подтверждённый полный адрес с улицей и номером дома.';

	/** @param array<string,mixed> $calculation_data */
	public function from_order( object $order, array $calculation_data = array() ): Address {
		unset( $calculation_data );

		return $this->from_order_with_evidence( $order )['address'];
	}

	/** @return array{address:Address,evidence:array<string,mixed>} */
	public function from_order_with_evidence( object $order ): array {
		$scope = $this->destination_scope( $order );
		if ( 'shipping' === $scope ) {
			$shipping_dadata = $this->dadata_address( $order, 'shipping' );
			if ( $shipping_dadata instanceof Address ) {
				$normalized = $this->normalize( $shipping_dadata );
				return array( 'address' => $normalized, 'evidence' => $this->evidence( $normalized, 'shipping_dadata', $order, 'shipping' ) );
			}
			$shipping = $this->woo_address( $order, 'shipping' );
			$billing = $this->woo_address( $order, 'billing' );
			if ( $this->same_destination( $shipping, $billing ) ) {
				$billing_dadata = $this->dadata_address( $order, 'billing' );
				if ( $billing_dadata instanceof Address ) {
					$normalized = $this->normalize( $billing_dadata );
					return array( 'address' => $normalized, 'evidence' => $this->evidence( $normalized, 'billing_dadata', $order, 'billing' ) );
				}
			}

			return $this->normalize_from_woo( $shipping, $order, 'shipping' );
		}

		$billing_dadata = $this->dadata_address( $order, 'billing' );
		if ( $billing_dadata instanceof Address ) {
			$normalized = $this->normalize( $billing_dadata );
			return array( 'address' => $normalized, 'evidence' => $this->evidence( $normalized, 'billing_dadata', $order, 'billing' ) );
		}

		return $this->normalize_from_woo( $this->woo_address( $order, 'billing' ), $order, 'billing' );
	}

	/** @return array{address:Address,evidence:array<string,mixed>} */
	private function normalize_from_woo( Address $woo, object $order, string $scope ): array {
		try {
			$normalized = $this->normalize( $woo );
			return array( 'address' => $normalized, 'evidence' => $this->evidence( $normalized, 'woo_structured', $order, $scope ) );
		} catch ( \RuntimeException ) {
			$parsed = $this->parse_raw( $woo->raw_address );
			$parsed_address = new Address(
				country_code: $woo->country_code,
				region_name: $woo->region_name,
				city: $woo->city,
				settlement: $woo->settlement,
				postcode: $woo->postcode,
				street: $parsed['street'],
				house: $parsed['house'],
				apartment: $this->first_non_empty( $woo->apartment, $parsed['apartment'] ),
				raw_address: $woo->raw_address,
				fias_id: $woo->fias_id,
				gar_id: $woo->gar_id
			);
			$normalized = $this->normalize( $parsed_address );
			return array( 'address' => $normalized, 'evidence' => $this->evidence( $normalized, 'parsed_address_1', $order, $scope ) );
		}
	}

	public function normalize( Address $address ): Address {
		if ( 'RU' !== strtoupper( trim( $address->country_code ) ) ) {
			throw new \RuntimeException( 'Создание отправлений ПЭК поддерживает только RU.' );
		}
		$city = trim( $address->city );
		$settlement = trim( $address->settlement );
		$street = trim( $address->street );
		$house = $this->normalize_house( $address->house );
		$apartment = $this->normalize_unit( $address->apartment, '' );
		$raw = trim( $address->raw_address );
		if ( '' === $street || '' === $house ) {
			$parsed = $this->parse_raw( '' !== $raw ? $raw : $street );
			$street = '' !== $street ? $street : $parsed['street'];
			$house = '' !== $house ? $house : $this->normalize_house( $parsed['house'] );
			$apartment = '' !== $apartment ? $apartment : $this->normalize_unit( $parsed['apartment'], '' );
		}
		if ( ( '' === $city && '' === $settlement ) || '' === $street || '' === $house ) {
			throw new \RuntimeException( self::PUBLIC_ERROR );
		}
		$raw = $this->format( trim( $address->region_name ), $city, $settlement, $street, $house, $apartment, trim( $address->postcode ) );

		return new Address(
			country_code: 'RU',
			region_name: $address->region_name,
			city: $city,
			settlement: $settlement,
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
			'/,\s*(?<unit_type>квартира|помещение|пом\.?|офис|кв\.?)\s*(?<unit>[А-Яа-яA-Za-z0-9\/-]+)\s*$/iu',
			function ( array $matches ) use ( &$apartment ): string {
				$apartment = $this->normalize_unit( (string) $matches['unit'], (string) $matches['unit_type'] );
				return '';
			},
			$value
		) ?? $value;
		$block = '';
		$block_type = '';
		$value = preg_replace_callback(
			'/,\s*(?<block_type>к|корп\.?|корпус|стр\.?|строение)\s*(?<block>[А-Яа-яA-Za-z0-9\/-]+)\s*$/iu',
			static function ( array $matches ) use ( &$block, &$block_type ): string {
				$block = trim( (string) $matches['block'] );
				$block_type = trim( (string) $matches['block_type'] );
				return '';
			},
			$value
		) ?? $value;
		if ( 1 !== preg_match( '/^(?<street>.+?)(?:,\s*(?:д\.?\s*|дом\s*)?|(?:\s+д\.?\s*|\s+дом\s+))(?<house>\d+[А-Яа-яA-Za-z0-9\/-]*)\s*$/u', $value, $matches ) ) {
			return array( 'street' => '', 'house' => '', 'apartment' => '' );
		}
		$street = trim( (string) $matches['street'], " \t\n\r\0\x0B," );
		$house = $this->normalize_house_component( (string) $matches['house'], '', $block, $block_type, '', '' );

		return array( 'street' => $street, 'house' => $house, 'apartment' => $apartment );
	}

	private function format( string $region, string $city, string $settlement, string $street, string $house, string $apartment, string $postcode ): string {
		$parts = array( 'Россия' );
		$locality = '' !== $city ? $city : $settlement;
		if ( '' !== $region && ! $this->same_location_name( $region, $locality ) ) {
			$parts[] = $region;
		}
		if ( '' !== $city ) {
			$parts[] = $city;
		}
		if ( '' !== $settlement && ! $this->same_location_name( $settlement, $city ) ) {
			$parts[] = $settlement;
		}
		$parts[] = $street;
		$parts[] = 'дом ' . $house;
		if ( '' !== $apartment ) {
			$parts[] = $this->format_unit( $apartment );
		}
		unset( $postcode );

		return implode( ', ', $parts );
	}

	private function same_location_name( string $left, string $right ): bool {
		return '' !== $right && $this->normalize_location_name( $left ) === $this->normalize_location_name( $right );
	}

	private function normalize_location_name( string $value ): string {
		$value = $this->lower( $value );
		$value = preg_replace( '/\b(?:город\s+федерального\s+значения|город|г)\b\.?\s*/u', '', $value ) ?? $value;
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', '', $value ) ?? $value;

		return trim( $value );
	}

	private function dadata_address( object $order, string $scope ): ?Address {
		$status = $this->meta_string( $order, '_' . $scope . '_dadata_status' );
		if ( ! in_array( $status, array( 'house_selected', 'resolved' ), true ) ) {
			return null;
		}
		$country = $this->order_method_string( $order, 'get_' . $scope . '_country' );
		$city = $this->first_non_empty( $this->meta_string( $order, '_' . $scope . '_dadata_city_with_type' ), $this->meta_string( $order, '_' . $scope . '_dadata_city' ) );
		$settlement = $this->first_non_empty( $this->meta_string( $order, '_' . $scope . '_dadata_settlement_with_type' ), $this->meta_string( $order, '_' . $scope . '_dadata_settlement' ) );
		$street = $this->first_non_empty( $this->meta_string( $order, '_' . $scope . '_dadata_street_with_type' ), $this->meta_string( $order, '_' . $scope . '_dadata_street' ) );
		$house = $this->normalize_house_component(
			$this->meta_string( $order, '_' . $scope . '_dadata_house' ),
			$this->first_non_empty( $this->meta_string( $order, '_' . $scope . '_dadata_house_type_full' ), $this->meta_string( $order, '_' . $scope . '_dadata_house_type' ) ),
			$this->meta_string( $order, '_' . $scope . '_dadata_block' ),
			$this->first_non_empty( $this->meta_string( $order, '_' . $scope . '_dadata_block_type_full' ), $this->meta_string( $order, '_' . $scope . '_dadata_block_type' ) ),
			$this->meta_string( $order, '_' . $scope . '_dadata_stead' ),
			$this->meta_string( $order, '_' . $scope . '_dadata_stead_type' )
		);
		if ( '' === $country || ( '' === $city && '' === $settlement ) || '' === $street || '' === $house ) {
			return null;
		}
		$region = $this->first_non_empty( $this->meta_string( $order, '_' . $scope . '_dadata_region_with_type' ), $this->meta_string( $order, '_' . $scope . '_dadata_region' ) );
		$apartment = $this->normalize_unit( $this->meta_string( $order, '_' . $scope . '_dadata_flat' ), $this->first_non_empty( $this->meta_string( $order, '_' . $scope . '_dadata_flat_type_full' ), $this->meta_string( $order, '_' . $scope . '_dadata_flat_type' ) ) );
		$postcode = $this->order_method_string( $order, 'get_' . $scope . '_postcode' );
		$candidate = new Address( country_code: $country, region_name: $region, city: $city, settlement: $settlement, postcode: $postcode, street: $street, house: $house, apartment: $apartment, raw_address: '', fias_id: $this->meta_string( $order, '_' . $scope . '_dadata_fias_id' ) );

		return $this->dadata_matches_current_woo( $candidate, $this->woo_address( $order, $scope ) ) ? $candidate : null;
	}

	private function woo_address( object $order, string $scope ): Address {
		return new Address(
			country_code: $this->order_method_string( $order, 'get_' . $scope . '_country' ),
			region_name: $this->order_method_string( $order, 'get_' . $scope . '_state' ),
			city: $this->order_method_string( $order, 'get_' . $scope . '_city' ),
			postcode: $this->order_method_string( $order, 'get_' . $scope . '_postcode' ),
			street: '',
			house: '',
			apartment: $this->normalize_unit( $this->order_method_string( $order, 'get_' . $scope . '_address_2' ), '' ),
			raw_address: $this->order_method_string( $order, 'get_' . $scope . '_address_1' ),
			fias_id: $this->meta_string( $order, '_wdc_platform_fias_id' ),
			gar_id: $this->meta_string( $order, '_wdc_platform_gar_id' )
		);
	}

	/** @return array<string,mixed> */
	private function evidence( Address $address, string $source, ?object $order = null, string $scope = '' ): array {
		$canonical = $this->format( trim( $address->region_name ), trim( $address->city ), trim( $address->settlement ), trim( $address->street ), trim( $address->house ), trim( $address->apartment ), '' );
		$use_dadata_locality_ids = in_array( $source, array( 'shipping_dadata', 'billing_dadata' ), true );
		$ids = null !== $order && '' !== $scope ? array(
			'courier_region_fias_id' => $use_dadata_locality_ids ? $this->meta_string( $order, '_' . $scope . '_dadata_region_fias_id' ) : '',
			'courier_city_fias_id' => $use_dadata_locality_ids ? $this->meta_string( $order, '_' . $scope . '_dadata_city_fias_id' ) : '',
			'courier_settlement_fias_id' => $use_dadata_locality_ids ? $this->meta_string( $order, '_' . $scope . '_dadata_settlement_fias_id' ) : '',
			'courier_selected_location_fias_id' => $this->meta_string( $order, '_wdc_platform_location_fias_id' ),
			'courier_order_city_fias_id' => $this->meta_string( $order, '_wdc_platform_city_fias_id' ),
		) : array();

		$evidence = array(
			'courier_address_source' => $source,
			'courier_region_present' => '' !== trim( $address->region_name ),
			'courier_city_present' => '' !== trim( $address->city ) || '' !== trim( $address->settlement ),
			'courier_settlement_present' => '' !== trim( $address->settlement ),
			'courier_street_present' => '' !== trim( $address->street ),
			'courier_house_present' => '' !== trim( $address->house ),
			'courier_apartment_present' => '' !== trim( $address->apartment ),
			'courier_unit_type' => $this->unit_type( $address->apartment ),
			'courier_block_present' => str_contains( $this->lower( $address->house ), ' к ' ) || str_contains( $this->lower( $address->house ), ' стр.' ),
			'courier_block_type_confirmed' => str_contains( $this->lower( $address->house ), ' к ' ) || str_contains( $this->lower( $address->house ), ' стр.' ),
			'courier_postcode_present' => '' !== trim( $address->postcode ),
			'courier_address_hash' => hash( 'sha256', $canonical ),
		);
		foreach ( $ids as $key => $value ) {
			$evidence[ $key ] = $value;
			$evidence[ $key . '_present' ] = '' !== $value;
			$evidence[ $key . '_hash' ] = '' !== $value ? hash( 'sha256', $value ) : '';
		}

		return $evidence;
	}

	private function normalize_house( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		$value = preg_replace( '/^(?:д\.?|дом)\s*/iu', '', $value ) ?? $value;

		return trim( $value );
	}

	private function normalize_unit( string $value, string $type ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		if ( '' === $value ) {
			return '';
		}
		$type = $this->canonical_unit_type( $type );
		if ( '' === $type ) {
			if ( 1 === preg_match( '/^(кв\.?|квартира)\s+(?<value>.+)$/iu', $value, $matches ) ) {
				$type = 'кв.';
				$value = trim( (string) $matches['value'] );
			} elseif ( 1 === preg_match( '/^офис\s+(?<value>.+)$/iu', $value, $matches ) ) {
				$type = 'офис';
				$value = trim( (string) $matches['value'] );
			} elseif ( 1 === preg_match( '/^(помещение|пом\.?)\s+(?<value>.+)$/iu', $value, $matches ) ) {
				$type = 'помещение';
				$value = trim( (string) $matches['value'] );
			} elseif ( 1 === preg_match( '/^\d+[А-Яа-яA-Za-z0-9\/-]*$/u', $value ) ) {
				$type = 'кв.';
			}
		}
		if ( '' === $type ) {
			throw new \RuntimeException( self::PUBLIC_ERROR );
		}
		$value = preg_replace( '/^(?:квартира|помещение|офис|пом\.?|кв\.?)\s*/iu', '', $value ) ?? $value;
		$value = trim( $value );
		if ( '' === $value ) {
			throw new \RuntimeException( self::PUBLIC_ERROR );
		}

		return trim( $type . ' ' . $value );
	}

	private function normalize_block( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 1 === preg_match( '/^(?:к|корп\.?|корпус|стр\.?|строение)\s*/iu', $value ) ) {
			return $value;
		}

		return '';
	}

	private function normalize_house_component( string $house, string $house_type, mixed $block, string $block_type, mixed $stead, string $stead_type ): string {
		$raw_house_type = trim( $house_type );
		$house_type = $this->canonical_house_type( $house_type );
		if ( '' === $house_type && '' !== $raw_house_type ) {
			throw new \RuntimeException( 'Не удалось однозначно определить тип дома в адресе курьерской доставки ПЭК.' );
		}
		$house = $this->normalize_house( $house );
		if ( '' === $house ) {
			$house = $this->normalize_house( is_scalar( $stead ) ? (string) $stead : '' );
			if ( '' !== $house && '' === $this->canonical_block_type( $stead_type ) ) {
				return '';
			}
		}
		if ( '' === $house ) {
			return '';
		}
		if ( ! is_scalar( $block ) ) {
			throw new \RuntimeException( 'Не удалось однозначно определить корпус или строение в адресе курьерской доставки ПЭК.' );
		}
		$block_value = trim( preg_replace( '/\s+/u', ' ', (string) $block ) ?? (string) $block );
		if ( '' === $block_value ) {
			return $house;
		}
		$type = $this->canonical_block_type( $block_type );
		if ( '' === $type ) {
			throw new \RuntimeException( 'Не удалось однозначно определить корпус или строение в адресе курьерской доставки ПЭК.' );
		}
		$component = trim( $type . ' ' . $block_value );
		if ( str_contains( $this->normalize_location_name( $house ), $this->normalize_location_name( $component ) ) ) {
			return $house;
		}

		return trim( $house . ' ' . $component );
	}

	private function canonical_house_type( string $value ): string {
		$value = $this->lower( trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value ) );
		$value = rtrim( $value, '.' );
		return match ( $value ) {
			'', 'д', 'дом' => 'дом',
			default => '',
		};
	}

	private function canonical_block_type( string $value ): string {
		$value = $this->lower( trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value ) );
		$value = rtrim( $value, '.' );
		return match ( $value ) {
			'к', 'корп', 'корпус' => 'к',
			'стр', 'строение' => 'стр.',
			default => '',
		};
	}

	private function canonical_unit_type( string $value ): string {
		$value = $this->lower( trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value ) );
		$value = rtrim( $value, '.' );
		return match ( $value ) {
			'кв', 'квартира' => 'кв.',
			'офис' => 'офис',
			'пом', 'помещение' => 'помещение',
			default => '',
		};
	}

	private function format_unit( string $value ): string {
		return $this->normalize_unit( $value, '' );
	}

	private function unit_type( string $value ): string {
		$value = $this->lower( trim( $value ) );
		if ( str_starts_with( $value, 'офис ' ) ) {
			return 'office';
		}
		if ( str_starts_with( $value, 'помещение ' ) ) {
			return 'premise';
		}
		if ( str_starts_with( $value, 'кв. ' ) ) {
			return 'apartment';
		}

		return '';
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

		return preg_replace_callback(
			'/[А-ЯЁ]/u',
			static function ( array $m ): string {
				$map = array( 'А' => 'а', 'Б' => 'б', 'В' => 'в', 'Г' => 'г', 'Д' => 'д', 'Е' => 'е', 'Ё' => 'ё', 'Ж' => 'ж', 'З' => 'з', 'И' => 'и', 'Й' => 'й', 'К' => 'к', 'Л' => 'л', 'М' => 'м', 'Н' => 'н', 'О' => 'о', 'П' => 'п', 'Р' => 'р', 'С' => 'с', 'Т' => 'т', 'У' => 'у', 'Ф' => 'ф', 'Х' => 'х', 'Ц' => 'ц', 'Ч' => 'ч', 'Ш' => 'ш', 'Щ' => 'щ', 'Ъ' => 'ъ', 'Ы' => 'ы', 'Ь' => 'ь', 'Э' => 'э', 'Ю' => 'ю', 'Я' => 'я' );
				return $map[ $m[0] ] ?? $m[0];
			},
			strtolower( $value )
		) ?? strtolower( $value );
	}

	private function destination_scope( object $order ): string {
		$shipping = $this->woo_address( $order, 'shipping' );
		if ( ! $this->has_meaningful_shipping_destination( $order, $shipping ) ) {
			return 'billing';
		}

		return 'shipping';
	}

	private function has_meaningful_shipping_destination( object $order, Address $shipping ): bool {
		unset( $order );
		if ( '' !== trim( $shipping->country_code ) && '' !== trim( $shipping->city ) && '' !== trim( $shipping->raw_address ) ) {
			return true;
		}

		return false;
	}

	private function same_destination( Address $left, Address $right ): bool {
		$left_key = implode( '|', array_map( fn( string $v ): string => $this->normalize_location_name( $v ), array( $left->country_code, $left->region_name, $left->city, $left->raw_address, $left->apartment ) ) );
		$right_key = implode( '|', array_map( fn( string $v ): string => $this->normalize_location_name( $v ), array( $right->country_code, $right->region_name, $right->city, $right->raw_address, $right->apartment ) ) );

		return '' !== trim( str_replace( '|', '', $left_key ) ) && $left_key === $right_key;
	}

	private function dadata_matches_current_woo( Address $candidate, Address $woo ): bool {
		if ( strtoupper( trim( $candidate->country_code ) ) !== strtoupper( trim( $woo->country_code ) ) ) {
			return false;
		}
		if ( '' !== trim( $woo->region_name ) && ! $this->same_region_name( $woo->region_name, $candidate->region_name ) ) {
			return false;
		}
		if ( '' !== trim( $woo->city ) && ! $this->matches_candidate_locality( trim( $woo->city ), $candidate ) ) {
			return false;
		}
		$parsed = $this->parse_raw( $woo->raw_address );
		if ( '' !== trim( $woo->raw_address ) ) {
			if ( '' === $parsed['street'] || '' === $parsed['house'] ) {
				return false;
			}
			if ( ! $this->same_location_name( $parsed['street'], $candidate->street ) ) {
				return false;
			}
			if ( $this->normalize_location_name( $parsed['house'] ) !== $this->normalize_location_name( $candidate->house ) ) {
				return false;
			}
		}
		if ( '' !== trim( $woo->apartment ) && ( '' === trim( $candidate->apartment ) || $this->normalize_location_name( $woo->apartment ) !== $this->normalize_location_name( $candidate->apartment ) ) ) {
			return false;
		}

		return true;
	}

	private function matches_candidate_locality( string $current_city, Address $candidate ): bool {
		foreach ( array( $candidate->city, $candidate->settlement ) as $candidate_value ) {
			if ( '' !== trim( $candidate_value ) && $this->same_location_name( $current_city, $candidate_value ) ) {
				return true;
			}
		}

		return false;
	}

	private function same_region_name( string $left, string $right ): bool {
		return '' !== trim( $right ) && $this->normalize_region_name( $left ) === $this->normalize_region_name( $right );
	}

	private function normalize_region_name( string $value ): string {
		$value = $this->lower( $value );
		$value = preg_replace( '/\b(?:город\s+федерального\s+значения|город|г|область|обл|край|республика|респ)\b\.?\s*/u', '', $value ) ?? $value;
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
