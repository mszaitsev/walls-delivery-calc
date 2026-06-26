<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

use WallsShop\WDC\Locations\Services\LocationDisplayNameFormatter;

defined( 'ABSPATH' ) || exit;

final class YandexLocationMappingV2NameNormalizer {
	private const MAX_SEARCH_TERMS = 8;

	private LocationDisplayNameFormatter $formatter;

	/** @var array<int,string> */
	private array $type_aliases;

	/** @param array<string,array<string,array{display?:string,position?:string}>>|null $type_rules */
	public function __construct( ?array $type_rules = null ) {
		if ( null === $type_rules ) {
			$raw_rules = function_exists( 'get_option' ) ? get_option( 'wdc_location_type_display_rules', array() ) : array();
			$type_rules = is_array( $raw_rules ) ? $raw_rules : array();
		}
		$this->formatter = LocationDisplayNameFormatter::from_rules( $type_rules );
		$this->type_aliases = $this->build_type_aliases( $type_rules );
	}

	public function normalize_place( string $value ): string {
		$value = $this->normalize_text( $value );
		if ( '' === $value ) {
			return '';
		}

		foreach ( $this->type_aliases as $type ) {
			$quoted = preg_quote( $type, '/' );
			$value = preg_replace( '/(^|\s)' . $quoted . '($|\s)/u', ' ', $value ) ?? $value;
		}
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}

	/** @return array<int,string> */
	public function search_terms_for_locality( string $value ): array {
		$raw = trim( $value );
		$base = $this->normalize_place( $raw );
		$base_term = $this->base_search_term( $raw, $base );
		$terms = array();
		$this->add_term( $terms, $raw );
		$this->add_term( $terms, $base_term );

		if ( '' !== $base_term ) {
			foreach ( $this->preferred_type_aliases( $raw ) as $type ) {
				$this->add_term( $terms, $type . ' ' . $base_term );
				$this->add_term( $terms, $base_term . ' ' . $type );
			}
			foreach ( $this->type_aliases as $type ) {
				$this->add_term( $terms, $type . ' ' . $base_term );
				$this->add_term( $terms, $base_term . ' ' . $type );
				if ( count( $terms ) >= self::MAX_SEARCH_TERMS ) {
					break;
				}
			}
		}

		return array_slice( array_values( $terms ), 0, self::MAX_SEARCH_TERMS );
	}

	/** @param array<string,mixed> $location @return array{source:string,value:string,raw:string}|null */
	public function effective_location_locality( array $location ): ?array {
		foreach ( array( 'place_name', 'settlement_name', 'city_name', 'display_name' ) as $source ) {
			$raw = trim( (string) ( $location[ $source ] ?? '' ) );
			if ( '' === $raw ) {
				continue;
			}
			$value = $this->normalize_place( $raw );
			if ( '' !== $value ) {
				return array( 'source' => $source, 'value' => $value, 'raw' => $raw );
			}
		}

		return null;
	}
	/** @param array<string,mixed> $location @return array<int,array{source:string,value:string}> */
	public function location_locality_variants( array $location ): array {
		$variants = array();
		foreach ( array( 'city_name' => 'city_type', 'settlement_name' => 'settlement_type', 'place_name' => 'place_type', 'display_name' => '' ) as $name_key => $type_key ) {
			$name = trim( (string) ( $location[ $name_key ] ?? '' ) );
			if ( '' === $name ) {
				continue;
			}
			$this->add_variant( $variants, $name_key, $name );
			$type = '' !== $type_key ? trim( (string) ( $location[ $type_key ] ?? '' ) ) : '';
			if ( '' !== $type ) {
				foreach ( array_unique( array_merge( array( $type ), $this->formatter->display_variants( 'city_name' === $name_key ? 'city' : 'place', $type ) ) ) as $type_variant ) {
					$type_variant = trim( (string) $type_variant );
					if ( '' === $type_variant ) {
						continue;
					}
					$this->add_variant( $variants, $name_key, $type_variant . ' ' . $name );
					$this->add_variant( $variants, $name_key, $name . ' ' . $type_variant );
				}
			}
		}

		return array_values( $variants );
	}

	private function base_search_term( string $raw, string $normalized_base ): string {
		if ( '' === $normalized_base ) {
			return '';
		}
		$raw_normalized = str_replace( 'ё', 'е', mb_strtolower( $raw, 'UTF-8' ) );
		$position = mb_stripos( $raw_normalized, $normalized_base, 0, 'UTF-8' );
		if ( false === $position ) {
			return $normalized_base;
		}

		return trim( mb_substr( $raw, $position, mb_strlen( $normalized_base, 'UTF-8' ), 'UTF-8' ) );
	}
	/** @return array<int,string> */
	private function preferred_type_aliases( string $value ): array {
		$value = $this->normalize_text( $value );
		$groups = array();
		if ( preg_match( '/(^|\s)(г|город)($|\s)/u', $value ) ) {
			$groups[] = array( 'г', 'город' );
		}
		if ( preg_match( '/(^|\s)(рп|рабочий поселок)($|\s)/u', $value ) ) {
			$groups[] = array( 'рабочий поселок', 'рп' );
		}
		if ( preg_match( '/(^|\s)(пгт|поселок городского типа)($|\s)/u', $value ) ) {
			$groups[] = array( 'поселок городского типа', 'пгт' );
		}
		if ( preg_match( '/(^|\s)(д|деревня)($|\s)/u', $value ) ) {
			$groups[] = array( 'деревня', 'д' );
		}
		if ( preg_match( '/(^|\s)(с|село)($|\s)/u', $value ) ) {
			$groups[] = array( 'село', 'с' );
		}
		if ( preg_match( '/(^|\s)(п|пос|поселок)($|\s)/u', $value ) ) {
			$groups[] = array( 'поселок', 'пос', 'п' );
		}
		$aliases = array();
		foreach ( $groups as $group ) {
			foreach ( $group as $alias ) {
				$aliases[ $alias ] = $alias;
			}
		}

		return array_values( $aliases );
	}
	/** @param array<string,array<string,array{display?:string,position?:string}>> $type_rules @return array<int,string> */
	private function build_type_aliases( array $type_rules ): array {
		$aliases = array(
			'поселок городского типа', 'посёлок городского типа', 'пгт',
			'рабочий поселок', 'рабочий посёлок', 'рп',
			'город', 'г', 'г.',
			'деревня', 'д', 'д.',
			'село', 'с', 'с.',
			'поселок', 'посёлок', 'пос', 'пос.', 'п', 'п.',
			'хутор', 'х', 'х.', 'станица', 'ст', 'ст.', 'аул', 'слобода', 'снт', 'кп',
		);
		foreach ( array( 'city', 'place' ) as $scope ) {
			foreach ( is_array( $type_rules[ $scope ] ?? null ) ? $type_rules[ $scope ] : array() as $source => $rule ) {
				$aliases[] = (string) $source;
				if ( is_array( $rule ) ) {
					$aliases[] = (string) ( $rule['display'] ?? '' );
				}
			}
		}
		$normalized = array();
		foreach ( $aliases as $alias ) {
			$alias = $this->normalize_text( $alias );
			if ( '' !== $alias ) {
				$normalized[ $alias ] = $alias;
			}
		}
		uasort( $normalized, static fn( string $a, string $b ): int => mb_strlen( $b, 'UTF-8' ) <=> mb_strlen( $a, 'UTF-8' ) );

		return array_values( $normalized );
	}

	/** @param array<string,string> $terms */
	private function add_term( array &$terms, string $term ): void {
		$term = trim( preg_replace( '/\s+/u', ' ', $term ) ?? $term );
		if ( '' === $term || mb_strlen( $term, 'UTF-8' ) < 3 ) {
			return;
		}
		$key = mb_strtolower( $term, 'UTF-8' );
		if ( '' !== $key && ! isset( $terms[ $key ] ) ) {
			$terms[ $key ] = $term;
		}
	}

	/** @param array<string,array{source:string,value:string}> $variants */
	private function add_variant( array &$variants, string $source, string $value ): void {
		$normalized = $this->normalize_place( $value );
		if ( '' !== $normalized && ! isset( $variants[ $normalized ] ) ) {
			$variants[ $normalized ] = array( 'source' => $source, 'value' => $normalized );
		}
	}

	private function normalize_text( string $value ): string {
		$value = str_replace( 'ё', 'е', mb_strtolower( trim( $value ), 'UTF-8' ) );
		$value = preg_replace( '/[«»"\'`.,()]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}
}