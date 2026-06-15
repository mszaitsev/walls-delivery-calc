<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

defined( 'ABSPATH' ) || exit;

final class AddressLineParser {
	/**
	 * @return array{input:string,flat:string,flat_type:string,input_without_flat:string}
	 */
	public static function flat_context( string $input ): array {
		$input = self::normalize_punctuation( $input );
		$flat = '';
		$flat_type = '';
		$without = $input;
		if ( preg_match( '/(?:^|[,\s])(кв\.?|квартира|кв-ра|apt\.?|ап\.?|оф\.?|офис|пом\.?|помещение)\s*№?\s*([0-9A-Za-zА-Яа-яЁё\/-]+)\s*$/iu', $input, $matches, PREG_OFFSET_CAPTURE ) ) {
			$flat_type = self::flat_type( (string) $matches[1][0] );
			$flat = trim( (string) $matches[2][0] );
			$start = (int) $matches[0][1];
			$length = strlen( (string) $matches[0][0] );
			$without = trim( substr( $input, 0, $start ) . ' ' . substr( $input, $start + $length ) );
			$without = self::normalize_punctuation( $without );
		} else {
			$parts = preg_split( '/\s*,\s*/u', $input ) ?: array();
			if ( count( $parts ) >= 2 ) {
				$tail = trim( (string) end( $parts ) );
				if ( preg_match( '/^[0-9]+[A-Za-zА-Яа-яЁё0-9\/-]*$/u', $tail ) && ! preg_match( '/\b(г|город|ул|улица|пр|проспект|б-р|бульвар|пер|переулок|ш|шоссе|д|дом)\b\.?/iu', $tail ) ) {
					array_pop( $parts );
					$prefix = self::normalize_punctuation( implode( ', ', array_filter( array_map( 'trim', $parts ), static fn( string $part ): bool => '' !== $part ) ) );
					if ( '' !== $prefix ) {
						$flat = $tail;
						$flat_type = 'кв';
						$without = $prefix;
					}
				}
			}
		}

		return array(
			'input' => $input,
			'flat' => $flat,
			'flat_type' => $flat_type,
			'input_without_flat' => $without,
		);
	}

	public static function normalize_punctuation( string $input ): string {
		$input = trim( $input );
		$input = preg_replace( '/\s*,\s*/u', ', ', $input ) ?? $input;
		$input = preg_replace( '/\s+/u', ' ', $input ) ?? $input;

		return trim( $input, " \t\n\r\0\x0B," );
	}

	/**
	 * @param array<string,string> $context
	 * @return array<int,array{query:string,context:array<string,string>,variant:string}>
	 */
	public static function query_attempts( string $input, array $context = array(), int $limit = 5 ): array {
		$flat = self::flat_context( $input );
		$queries = array(
			'original' => trim( $input ),
			'normalized' => $flat['input'],
			'without_flat' => $flat['input_without_flat'],
		);
		foreach ( array( 'selected_display_name', 'city', 'city_name' ) as $key ) {
			$city = trim( (string) ( $context[ $key ] ?? '' ) );
			if ( '' === $city ) {
				continue;
			}
			$queries[ 'without_city_' . $key ] = self::remove_leading_place( $flat['input'], $city );
			$queries[ 'without_city_flat_' . $key ] = self::remove_leading_place( $flat['input_without_flat'], $city );
			break;
		}

		$attempts = array();
		$seen = array();
		foreach ( $queries as $variant => $query ) {
			$query = self::normalize_punctuation( $query );
			if ( '' === $query || isset( $seen[ $query ] ) ) {
				continue;
			}
			$seen[ $query ] = true;
			$attempts[] = array( 'query' => $query, 'context' => $context, 'variant' => $variant );
			if ( count( $attempts ) >= $limit ) {
				return $attempts;
			}
		}
		if ( array() !== $context && count( $attempts ) < $limit && '' !== $flat['input_without_flat'] && ! isset( $seen[ 'loose:' . $flat['input_without_flat'] ] ) ) {
			$attempts[] = array(
				'query' => $flat['input_without_flat'],
				'context' => array( 'country_code' => (string) ( $context['country_code'] ?? 'RU' ) ),
				'variant' => 'without_flat_loose_context',
			);
		}

		return array_slice( $attempts, 0, $limit );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function lower_address_line( array $data, string $restored_flat = '', string $restored_flat_type = 'кв' ): string {
		$parts = array();
		$street = trim( (string) ( $data['street_with_type'] ?? $data['street'] ?? '' ) );
		if ( '' !== $street ) {
			$parts[] = $street;
		}
		$house = trim( (string) ( $data['house'] ?? '' ) );
		if ( '' !== $house ) {
			$house_type = trim( (string) ( $data['house_type'] ?? $data['house_type_full'] ?? 'д' ) );
			$parts[] = trim( $house_type . ' ' . $house );
		}
		$block = trim( (string) ( $data['block'] ?? '' ) );
		if ( '' !== $block ) {
			$block_type = trim( (string) ( $data['block_type'] ?? $data['block_type_full'] ?? 'корп' ) );
			$parts[] = trim( $block_type . ' ' . $block );
		}
		$flat = trim( (string) ( $data['flat'] ?? '' ) );
		if ( '' === $flat ) {
			$flat = $restored_flat;
		}
		if ( '' !== $flat ) {
			$flat_type = trim( (string) ( $data['flat_type'] ?? $data['flat_type_full'] ?? $restored_flat_type ?: 'кв' ) );
			$parts[] = trim( $flat_type . ' ' . $flat );
		}
		$room = trim( (string) ( $data['room'] ?? $data['room_number'] ?? $data['premise'] ?? '' ) );
		if ( '' !== $room ) {
			$room_type = trim( (string) ( $data['room_type'] ?? $data['room_type_full'] ?? $data['premise_type'] ?? 'пом' ) );
			$parts[] = trim( $room_type . ' ' . $room );
		}

		return implode( ', ', array_values( array_filter( $parts, static fn( string $part ): bool => '' !== trim( $part ) ) ) );
	}

	private static function remove_leading_place( string $query, string $place ): string {
		$query = self::normalize_punctuation( $query );
		$place = self::normalize_punctuation( $place );
		if ( '' === $query || '' === $place ) {
			return $query;
		}
		$pattern = '/^' . preg_quote( $place, '/' ) . '\s*,?\s*/iu';
		$result = preg_replace( $pattern, '', $query ) ?? $query;

		return self::normalize_punctuation( $result );
	}

	private static function flat_type( string $type ): string {
		$type = trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( $type ) : strtolower( $type ) );
		if ( str_starts_with( $type, 'оф' ) ) {
			return 'оф';
		}
		if ( str_starts_with( $type, 'пом' ) ) {
			return 'пом';
		}
		if ( str_starts_with( $type, 'ап' ) || str_starts_with( $type, 'apt' ) ) {
			return 'ап';
		}

		return 'кв';
	}
}
