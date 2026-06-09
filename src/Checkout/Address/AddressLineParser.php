<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Address;

defined( 'ABSPATH' ) || exit;

final class AddressLineParser {
	/**
	 * @return array{input:string,flat:string,input_without_flat:string}
	 */
	public static function flat_context( string $input ): array {
		$input = self::normalize_punctuation( $input );
		$flat = '';
		$without = $input;
		if ( preg_match( '/(?:^|[,\s])(?:кв\.?|квартира|кв-ра|apt\.?|ап\.?)\s*№?\s*([0-9A-Za-zА-Яа-я\/-]+)/iu', $input, $matches, PREG_OFFSET_CAPTURE ) ) {
			$flat = trim( (string) $matches[1][0] );
			$start = (int) $matches[0][1];
			$length = strlen( (string) $matches[0][0] );
			$without = trim( substr( $input, 0, $start ) . ' ' . substr( $input, $start + $length ) );
			$without = self::normalize_punctuation( $without );
		}

		return array(
			'input' => $input,
			'flat' => $flat,
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
	public static function lower_address_line( array $data, string $restored_flat = '' ): string {
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
			$flat_type = trim( (string) ( $data['flat_type'] ?? $data['flat_type_full'] ?? 'кв' ) );
			$parts[] = trim( $flat_type . ' ' . $flat );
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
}
