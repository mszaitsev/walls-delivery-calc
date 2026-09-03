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
	 * @return array{query:string,tokens:array<int,string>,real_tokens:array<int,string>,markers:array<string,bool>,requested_types:array<string,array<int,string>>,has_markers:bool,region_alias_tokens:array<int,string>}
	 */
	public function parse( string $query ): array {
		$normalized = $this->normalize( $query );
		$tokens = $this->tokenize_normalized( $normalized );
		$markers = array();
		$requested_types = array();
		$real = array();
		$region_alias_tokens = array();

		for ( $i = 0; $i < count( $tokens ); ++$i ) {
			$token = $tokens[ $i ];
			if ( 'мо' === $token ) {
				$markers['region'] = true;
				$real[] = 'московская';
				$region_alias_tokens[] = 'московская';
				continue;
			}

			$phrase = $this->multi_token_marker( $tokens, $i );
			if ( array() !== $phrase ) {
				$scope = (string) $phrase['scope'];
				$type = (string) $phrase['type'];
				$markers[ $scope ] = true;
				$requested_types[ $scope ][ $type ] = true;
				$i += (int) $phrase['skip'];
				continue;
			}

			$scope = $this->marker_scope( $token );
			if ( '' !== $scope ) {
				$markers[ $scope ] = true;
				$type = $this->marker_type( $scope, $token );
				if ( '' !== $type ) {
					$requested_types[ $scope ][ $type ] = true;
				}
				continue;
			}
			$real[] = $token;
		}

		return array(
			'query'       => implode( ' ', $real ),
			'tokens'      => $tokens,
			'real_tokens' => array_values( array_unique( $real ) ),
			'markers'     => $markers,
			'requested_types' => array_map( static fn( array $types ): array => array_keys( $types ), $requested_types ),
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
					static fn( string $token ): bool => ( function_exists( 'mb_strlen' ) ? mb_strlen( $token, 'UTF-8' ) : strlen( $token ) ) > 1 || in_array( $token, array( 'г', 'п', 'с', 'д', 'х' ), true )
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

	public function canonical_type( string $scope, string $type ): string {
		$type = $this->normalize( $type );
		if ( '' === $type ) {
			return '';
		}
		$aliases = $this->type_alias_map();
		if ( isset( $aliases[ $scope ][ $type ] ) ) {
			return $aliases[ $scope ][ $type ];
		}
		if ( isset( $aliases['place'][ $type ] ) ) {
			return $aliases['place'][ $type ];
		}

		return $type;
	}

	private function marker_type( string $scope, string $token ): string {
		$aliases = $this->type_alias_map();
		return (string) ( $aliases[ $scope ][ $token ] ?? '' );
	}

	/**
	 * @param array<int,string> $tokens
	 * @return array{scope:string,type:string,skip:int}|array{}
	 */
	private function multi_token_marker( array $tokens, int $offset ): array {
		$phrases = array(
			array( 'tokens' => array( 'поселок', 'городского', 'типа' ), 'scope' => 'place', 'type' => 'пгт' ),
			array( 'tokens' => array( 'рабочий', 'поселок' ), 'scope' => 'place', 'type' => 'рп' ),
		);
		foreach ( $phrases as $phrase ) {
			$phrase_tokens = $phrase['tokens'];
			$slice = array_slice( $tokens, $offset, count( $phrase_tokens ) );
			if ( $slice === $phrase_tokens ) {
				return array(
					'scope' => (string) $phrase['scope'],
					'type'  => (string) $phrase['type'],
					'skip'  => count( $phrase_tokens ) - 1,
				);
			}
		}

		return array();
	}

	/**
	 * @return array<string,array<int,string>>
	 */
	private function marker_map(): array {
		$map = array(
			'region'   => array( 'область', 'обл', 'обл.', 'край', 'республика', 'респ' ),
			'district' => array( 'район', 'р-н', 'рн', 'муниципальный' ),
			'city'     => array( 'город', 'г', 'г.', 'го', 'округ' ),
			'place'    => array( 'село', 'с', 'с.', 'деревня', 'д', 'д.', 'поселок', 'посёлок', 'пос', 'п', 'п.', 'пгт', 'рп', 'аул', 'хутор', 'х', 'ст', 'станица' ),
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

	/**
	 * @return array<string,array<string,string>>
	 */
	private function type_alias_map(): array {
		$map = array(
			'city'  => array(
				'город' => 'г',
				'г' => 'г',
				'г.' => 'г',
			),
			'place' => array(
				'город' => 'г',
				'г' => 'г',
				'г.' => 'г',
				'село' => 'с',
				'с' => 'с',
				'с.' => 'с',
				'деревня' => 'д',
				'д' => 'д',
				'д.' => 'д',
				'поселок' => 'п',
				'посёлок' => 'п',
				'пос' => 'п',
				'п' => 'п',
				'п.' => 'п',
				'пгт' => 'пгт',
				'поселок городского типа' => 'пгт',
				'рабочий поселок' => 'рп',
				'рп' => 'рп',
				'аул' => 'аул',
				'станица' => 'ст',
				'ст' => 'ст',
				'хутор' => 'х',
				'х' => 'х',
			),
		);

		foreach ( $this->rules as $scope => $types ) {
			if ( ! is_array( $types ) ) {
				continue;
			}
			foreach ( $types as $type => $rule ) {
				$canonical = $this->canonical_builtin_type( (string) $type );
				foreach ( array( (string) $type, (string) ( $rule['display'] ?? '' ) ) as $alias ) {
					$alias = $this->normalize( $alias );
					if ( '' !== $alias ) {
						$map[ $scope ][ $alias ] = $canonical;
					}
				}
			}
		}

		return $map;
	}

	private function canonical_builtin_type( string $type ): string {
		$type = $this->normalize( $type );
		return match ( $type ) {
			'город', 'г' => 'г',
			'село', 'с' => 'с',
			'деревня', 'д' => 'д',
			'поселок', 'посёлок', 'пос', 'п' => 'п',
			'поселок городского типа', 'пгт' => 'пгт',
			'рабочий поселок', 'рп' => 'рп',
			'станица', 'ст' => 'ст',
			'хутор', 'х' => 'х',
			default => $type,
		};
	}
}
