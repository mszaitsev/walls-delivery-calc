<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Locations;

use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class CheckoutLocationSearchParser {
	/**
	 * @param array<string,array<string,array{display?:string,position?:string}>> $rules
	 */
	public function __construct( private array $rules = array() ) {
	}

	/**
	 * @return array{query:string,tokens:array<int,string>,real_tokens:array<int,string>,markers:array<string,bool>,has_markers:bool,region_alias_tokens:array<int,string>}
	 */
	public function parse( string $query ): array {
		$normalized = $this->normalize( $query );
		$tokens = $this->tokenize_normalized( $normalized );
		$markers = array();
		$real = array();
		$region_alias_tokens = array();

		foreach ( $tokens as $token ) {
			if ( 'мо' === $token ) {
				$markers['region'] = true;
				$real[] = 'московская';
				$region_alias_tokens[] = 'московская';
				continue;
			}

			$scope = $this->marker_scope( $token );
			if ( '' !== $scope ) {
				$markers[ $scope ] = true;
				continue;
			}
			$real[] = $token;
		}

		return array(
			'query'       => implode( ' ', $real ),
			'tokens'      => $tokens,
			'real_tokens' => array_values( array_unique( $real ) ),
			'markers'     => $markers,
			'has_markers' => array() !== $markers,
			'region_alias_tokens' => array_values( array_unique( $region_alias_tokens ) ),
		);
	}

	public function normalize( string $value ): string {
		$value = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', is_string( $value ) ? trim( $value ) : '' );
		return is_string( $value ) ? $value : '';
	}

	/**
	 * @return array<int,string>
	 */
	private function tokenize_normalized( string $query ): array {
		$tokens = preg_split( '/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY );
		$tokens = is_array( $tokens ) ? $tokens : array();
		return array_values(
			array_unique(
				array_filter(
					$tokens,
					static fn( string $token ): bool => ( function_exists( 'mb_strlen' ) ? mb_strlen( $token, 'UTF-8' ) : strlen( $token ) ) > 1 || 'г' === $token
				)
			)
		);
	}

	private function marker_scope( string $token ): string {
		foreach ( $this->marker_map() as $scope => $markers ) {
			if ( in_array( $token, $markers, true ) ) {
				return $scope;
			}
		}
		return '';
	}

	/**
	 * @return array<string,array<int,string>>
	 */
	private function marker_map(): array {
		$map = array(
			'region'   => array( 'область', 'обл', 'обл.', 'край', 'республика', 'респ' ),
			'district' => array( 'район', 'р-н', 'рн', 'муниципальный' ),
			'city'     => array( 'город', 'г', 'г.', 'го', 'округ' ),
			'place'    => array( 'село', 'с', 'с.', 'деревня', 'д', 'д.', 'поселок', 'посёлок', 'пос', 'п', 'п.', 'хутор', 'х', 'ст', 'станица' ),
		);

		foreach ( $this->rules as $scope => $types ) {
			if ( ! isset( $map[ $scope ] ) || ! is_array( $types ) ) {
				continue;
			}
			foreach ( $types as $type => $rule ) {
				$map[ $scope ][] = (string) $type;
				$map[ $scope ][] = (string) ( $rule['display'] ?? '' );
			}
		}

		foreach ( $map as $scope => $markers ) {
			$normalized = array();
			foreach ( $markers as $marker ) {
				$marker = $this->normalize( $marker );
				if ( '' !== $marker ) {
					$normalized[] = $marker;
				}
			}
			$map[ $scope ] = array_values( array_unique( $normalized ) );
		}

		return $map;
	}
}
