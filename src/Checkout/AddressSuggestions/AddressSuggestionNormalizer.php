<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\AddressSuggestions;

use WallsShop\WDC\Checkout\Address\AddressLineParser;

defined( 'ABSPATH' ) || exit;

final class AddressSuggestionNormalizer {
	/**
	 * @param array<int,mixed> $suggestions
	 * @return array<int,array<string,mixed>>
	 */
	public function normalize_many( array $suggestions ): array {
		$items = array();
		foreach ( $suggestions as $index => $suggestion ) {
			if ( is_array( $suggestion ) ) {
				$items[] = $this->normalize( $suggestion, $index );
			}
		}

		return $items;
	}

	/**
	 * @param array<string,mixed> $suggestion
	 * @return array<string,mixed>
	 */
	public function normalize( array $suggestion, int $index = 0 ): array {
		$data = is_array( $suggestion['data'] ?? null ) ? $suggestion['data'] : array();
		$fias_level = (string) ( $data['fias_level'] ?? '' );
		$level = $this->level( $data, $fias_level );
		$street = (string) ( $data['street_with_type'] ?? $data['street'] ?? '' );
		$lower_address = AddressLineParser::lower_address_line( $data );
		$label = (string) ( $suggestion['value'] ?? $suggestion['unrestricted_value'] ?? '' );
		if ( '' !== $lower_address ) {
			$label = $lower_address;
		} elseif ( '' !== $street && 'street' === $level ) {
			$label = $street;
		}

		return array(
			'id'                => sha1( (string) ( $suggestion['unrestricted_value'] ?? $label ) . '|' . $index ),
			'label'             => $label,
			'subLabel'          => $this->sub_label( $data ),
			'value'             => (string) ( $suggestion['value'] ?? $label ),
			'unrestrictedValue' => (string) ( $suggestion['unrestricted_value'] ?? $label ),
			'level'             => $level,
			'fiasLevel'         => $fias_level,
			'isDeliverable'     => $this->is_deliverable( $data, $fias_level ),
			'data'              => $this->data( $data ),
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,string>
	 */
	private function data( array $data ): array {
		$keys = array(
			'region', 'region_with_type', 'region_fias_id', 'region_kladr_id',
			'city', 'city_with_type', 'city_fias_id', 'city_kladr_id',
			'settlement', 'settlement_with_type', 'settlement_fias_id', 'settlement_kladr_id',
			'street', 'street_with_type', 'street_fias_id', 'street_kladr_id',
			'house', 'house_type', 'house_type_full', 'house_fias_id', 'house_kladr_id', 'block', 'block_type', 'block_type_full', 'stead', 'stead_type', 'flat', 'flat_type', 'flat_type_full',
			'fias_id', 'kladr_id', 'postal_code',
		);

		$normalized = array();
		foreach ( $keys as $key ) {
			$normalized[ $key ] = (string) ( $data[ $key ] ?? '' );
		}

		return $normalized;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function level( array $data, string $fias_level ): string {
		if ( '9' === $fias_level || '' !== (string) ( $data['flat'] ?? '' ) ) {
			return 'flat';
		}
		if ( in_array( $fias_level, array( '8', '75' ), true ) || '' !== (string) ( $data['house'] ?? '' ) || '' !== (string) ( $data['house_fias_id'] ?? '' ) || '' !== (string) ( $data['house_kladr_id'] ?? '' ) ) {
			return 'house';
		}
		if ( '' !== (string) ( $data['street'] ?? '' ) || '' !== (string) ( $data['street_with_type'] ?? '' ) ) {
			return 'street';
		}
		if ( '' !== (string) ( $data['settlement'] ?? '' ) || '' !== (string) ( $data['settlement_with_type'] ?? '' ) ) {
			return 'settlement';
		}
		if ( '' !== (string) ( $data['city'] ?? '' ) || '' !== (string) ( $data['city_with_type'] ?? '' ) ) {
			return 'city';
		}

		return 'unknown';
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function is_deliverable( array $data, string $fias_level ): bool {
		$has_house = '' !== (string) ( $data['house'] ?? '' )
			|| '' !== (string) ( $data['house_fias_id'] ?? '' )
			|| '' !== (string) ( $data['house_kladr_id'] ?? '' )
			|| '' !== (string) ( $data['stead'] ?? '' );
		$has_street = '' !== (string) ( $data['street'] ?? '' ) || '' !== (string) ( $data['street_with_type'] ?? '' );

		return ( $has_house && $has_street ) || in_array( $fias_level, array( '8', '9', '75' ), true );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function sub_label( array $data ): string {
		$parts = array(
			(string) ( $data['region_with_type'] ?? $data['region'] ?? '' ),
			(string) ( $data['city_with_type'] ?? $data['city'] ?? '' ),
			(string) ( $data['settlement_with_type'] ?? $data['settlement'] ?? '' ),
		);

		return implode( ', ', array_values( array_filter( array_map( 'trim', $parts ) ) ) );
	}
}
