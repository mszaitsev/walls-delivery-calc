<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2;

use WallsShop\WDC\Locations\Services\LocationDisplayNameFormatter;

defined( 'ABSPATH' ) || exit;

final class YandexLocationMappingV2NameNormalizer {
	private const MAX_SEARCH_TERMS = 30;

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
		$value = $this->without_parentheses( $value );
		$value = $this->normalize_text( $value );
		if ( '' === $value ) {
			return '';
		}

		$original = $value;
		foreach ( $this->type_aliases as $type ) {
			$quoted = preg_quote( $type, '/' );
			$value = preg_replace( '/(^|\s)' . $quoted . '($|\s)/u', ' ', $value ) ?? $value;
		}
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		$value = trim( $value );

		return '' !== $value ? $value : $original;
	}

	/** @return array<int,string> */
	public function search_terms_for_locality( string $value ): array {
		$raw = trim( $value );
		$raw_without_parentheses = $this->without_parentheses( $raw );
		$terms = array();
		$this->add_term( $terms, $raw );
		$this->add_term( $terms, $raw_without_parentheses );

		$bases = array();
		foreach ( array_unique( array( $raw, $raw_without_parentheses ) ) as $source ) {
			$base = $this->normalize_place( $source );
			$base_term = $this->base_search_term( $source, $base );
			if ( '' !== $base_term ) {
				$bases[ mb_strtolower( $base_term, 'UTF-8' ) ] = $base_term;
				$this->add_term( $terms, $base_term );
			}
		}

		$this->add_semantic_terms( $terms, $raw );
		$this->add_suffix_type_terms( $terms, $raw );

		foreach ( $bases as $base_term ) {
			foreach ( $this->preferred_type_aliases( $raw ) as $type ) {
				$this->add_term( $terms, $type . ' ' . $base_term );
				$this->add_term( $terms, $base_term . ' ' . $type );
			}
			foreach ( $this->type_aliases as $type ) {
				$this->add_term( $terms, $type . ' ' . $base_term );
				$this->add_term( $terms, $base_term . ' ' . $type );
				if ( count( $terms ) >= self::MAX_SEARCH_TERMS ) {
					break 2;
				}
			}
		}

		return array_slice( array_values( $terms ), 0, self::MAX_SEARCH_TERMS );
	}


	/** @param array<string,string> $terms */
	private function add_semantic_terms( array &$terms, string $raw ): void {
		$normalized = $this->normalize_text( $raw );
		$base = $this->base_search_term( $raw, $this->normalize_place( $raw ) );
		$base = '' !== $base ? $base : $this->normalize_place( $raw );
		if ( '' !== $base ) {
			if ( preg_match( '/(^|\s)(станица|ст ца|стца)(\s|$)/u', $normalized ) ) {
				$this->add_term( $terms, 'станица ' . $base );
				$this->add_term( $terms, 'ст-ца ' . $base );
			}
			if ( preg_match( '/(^|\s)(г|город)(\s|$)/u', $normalized ) ) {
				$this->add_term( $terms, 'г ' . $base );
				$this->add_term( $terms, 'город ' . $base );
			}
			if ( preg_match( '/городской поселок/u', $normalized ) ) {
				$this->add_term( $terms, 'городской поселок ' . $base );
				$this->add_term( $terms, 'гп ' . $base );
			}
			if ( preg_match( '/дачный поселок/u', $normalized ) ) {
				$this->add_term( $terms, 'дачный поселок ' . $base );
				$this->add_term( $terms, 'дп ' . $base );
			}
			if ( preg_match( '/коттеджный поселок/u', $normalized ) ) {
				$this->add_term( $terms, 'коттеджный поселок ' . $base );
				$this->add_term( $terms, 'кп ' . $base );
			}
			if ( preg_match( '/(поселок при железнодорожной станции|поселок станции|железнодорожная станция|ж д ст|п ст|станция|ст)/u', $normalized ) ) {
				$this->add_term( $terms, 'станция ' . $base );
				$this->add_term( $terms, 'ж/д станция ' . $base );
				$this->add_term( $terms, 'поселок станции ' . $base );
			}
		}
		if ( preg_match( '/(?:^|\s)(совхоза|санатория|фабрики|опытного хозяйства)\s+(.+)$/u', $normalized, $matches ) ) {
			$name = mb_convert_case( trim( $matches[2] ), MB_CASE_TITLE, 'UTF-8' );
			$prefix = mb_convert_case( trim( $matches[1] ), MB_CASE_TITLE, 'UTF-8' );
			$this->add_term( $terms, str_replace( 'Имени', 'имени', $prefix . ' ' . $name ) );
			$this->add_term( $terms, $prefix . ' ' . $name );
			$this->add_term( $terms, str_replace( 'Имени', 'имени', $name ) );
			$this->add_term( $terms, $name );
		}
		if ( preg_match( '/(?:^|\s)(?:им|имени)\s+(.+)$/u', $normalized, $matches ) ) {
			$name = mb_convert_case( trim( $matches[1] ), MB_CASE_TITLE, 'UTF-8' );
			$this->add_term( $terms, 'им. ' . $name );
			$this->add_term( $terms, 'имени ' . $name );
			$this->add_term( $terms, 'пгт имени ' . $name );
			$this->add_term( $terms, 'поселок городского типа имени ' . $name );
			$this->add_term( $terms, $name );
		}
	}
	/** @return array<int,string> */
	public function extract_locality_candidates_from_full_address( string $address ): array {
		$terms = array();
		$address = trim( $address );
		if ( '' === $address ) {
			return array();
		}
		$street_start = '(?:улица|ул\.?|проспект|пр-кт|переулок|пер\.?|шоссе|дом|д\.?|строение|стр\.?|б\/у|\S+\s+(?:улица|ул\.?|проспект|пр-кт|переулок|пер\.?|шоссе))';
		$patterns = array(
			array(
				'kind' => 'suffix',
				'pattern' => '/^\s*(.+?)\s+(г|г\.|д|д\.|с|с\.|п|п\.|рп|рп\.|гп|гп\.|пгт|ст|ст\.|ж\/д\s*ст|ж\/д_ст)\s+' . $street_start . '\b/iu',
			),
			array(
				'kind' => 'prefix',
				'pattern' => '/^\s*(производственно[-\s]+административная\s+зона)\s+(.+?)(?:\s+' . $street_start . '\b|,|$)/iu',
			),
			array(
				'kind' => 'prefix',
				'pattern' => '/^\s*(садоводческое\s+некоммерческое\s+товарищество|садоводческое\s+товарищество|садовое\s+товарищество)\s+(.+?)(?:\s+' . $street_start . '\b|,|$)/iu',
			),
			array(
				'kind' => 'prefix',
				'pattern' => '/^\s*(деревня|село|пос[её]лок|станица|слобода|СНТ|ДНП|КП|район|поселение|массив)\s+(.+?)(?:\s+' . $street_start . '\b|,|$)/iu',
			),
		);
		foreach ( $patterns as $entry ) {
			if ( preg_match( $entry['pattern'], $address, $matches ) ) {
				if ( 'suffix' === $entry['kind'] ) {
					$name = trim( (string) $matches[1] );
					$type = trim( (string) $matches[2] );
				} else {
					$type = trim( (string) $matches[1] );
					$name = trim( (string) $matches[2] );
				}
				$this->add_address_candidate_terms( $terms, $type, $name );
				break;
			}
		}

		return array_slice( array_values( $terms ), 0, 12 );
	}

	/** @param array<string,string> $terms */
	private function add_suffix_type_terms( array &$terms, string $raw ): void {
		$normalized = $this->normalize_text( $raw );
		if ( ! preg_match( '/^(.+?)\s+(г|д|с|п|рп|гп|пгт|ст|ж д ст)$/u', $normalized, $matches ) ) {
			return;
		}
		$base = trim( (string) $matches[1] );
		$type = trim( (string) $matches[2] );
		if ( '' === $base ) {
			return;
		}
		$base = mb_convert_case( $base, MB_CASE_TITLE, 'UTF-8' );
		$this->add_term( $terms, $base );
		foreach ( $this->suffix_type_variants( $type, $base ) as $term ) {
			$this->add_term( $terms, $term );
		}
	}

	/** @return array<int,string> */
	private function suffix_type_variants( string $type, string $base ): array {
		return match ( $type ) {
			'г' => array( 'г ' . $base, 'город ' . $base, $base . ' г', $base . ' город' ),
			'д' => array( 'д ' . $base, 'деревня ' . $base, $base . ' д', $base . ' деревня' ),
			'с' => array( 'с ' . $base, 'село ' . $base, $base . ' с', $base . ' село' ),
			'п' => array( 'п ' . $base, 'поселок ' . $base, $base . ' п', $base . ' поселок' ),
			'рп' => array( 'рп ' . $base, 'рабочий поселок ' . $base, $base . ' рп', $base . ' рабочий поселок' ),
			'гп' => array( 'гп ' . $base, 'городской поселок ' . $base, $base . ' гп', $base . ' городской поселок' ),
			'пгт' => array( 'пгт ' . $base, 'поселок городского типа ' . $base, $base . ' пгт' ),
			'ст', 'ж д ст' => array( 'ст ' . $base, 'станция ' . $base, 'ж/д станция ' . $base, $base . ' ст' ),
			default => array(),
		};
	}

	public function base_name_for_locality( string $value ): string {
		$without_parentheses = $this->without_parentheses( $value );
		$base = $this->normalize_place( $without_parentheses );
		$base_term = $this->base_search_term( $without_parentheses, $base );

		return '' !== $base_term ? $base_term : $base;
	}

	/** @param array<string,mixed> $location @return array{source:string,value:string,raw:string}|null */
	public function effective_location_locality( array $location ): ?array {
		foreach ( array( 'place_name', 'settlement_name', 'city_name', 'display_name' ) as $source ) {
			$raw = trim( (string) ( $location[ $source ] ?? '' ) );
			if ( '' === $raw ) {
				continue;
			}
			$value = $this->service_base_term( $raw );
			$value = '' !== $value ? $value : $this->normalize_place( $raw );
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

	public function detect_locality_type( string $value ): string {
		$value = $this->normalize_text( $value );
		foreach ( array(
			'поселок при железнодорожной станции' => 'station',
			'поселок станции' => 'station',
			'железнодорожная станция' => 'station',
			'ж д станция' => 'station',
			'ж д ст' => 'station',
			'поселок городского типа' => 'urban',
			'городской поселок' => 'urban',
			'рабочий поселок' => 'urban',
			'коттеджный поселок' => 'settlement',
			'дачный поселок' => 'urban',
			'сельский поселок' => 'village',
			'слобода' => 'village',
			'станица' => 'village',
			'ст ца' => 'village',
			'стца' => 'village',
			'местечко' => 'settlement',
			'массив' => 'area',
			'ж д ст' => 'station',
			'п ст' => 'station',
			'м в' => 'area',
			'пгт' => 'urban',
			'рп' => 'urban',
			'гп' => 'urban',
			'дп' => 'urban',
			'город' => 'city',
			'г' => 'city',
			'село' => 'village',
			'с' => 'village',
			'деревня' => 'hamlet',
			'д' => 'hamlet',
			'поселок' => 'settlement',
			'пос' => 'settlement',
			'п' => 'settlement',
			'хутор' => 'farm',
			'х' => 'farm',
			'ст' => 'station',
		) as $alias => $group ) {
			$quoted = preg_quote( $alias, '/' );
			if ( preg_match( '/(^|\s)' . $quoted . '($|\s)/u', $value ) ) {
				return $group;
			}
		}

		return 'other';
	}

	public function normalize_type( string $type ): string {
		$type = $this->normalize_text( $type );
		return match ( $type ) {
			'г', 'город' => 'city',
			'пгт', 'рп', 'гп', 'дп', 'рабочий поселок', 'городской поселок', 'дачный поселок', 'поселок городского типа' => 'urban',
			'с', 'село', 'сельский поселок', 'слобода', 'станица', 'ст ца', 'стца' => 'village',
			'д', 'деревня' => 'hamlet',
			'п', 'пос', 'поселок', 'местечко', 'кп', 'коттеджный поселок' => 'settlement',
			'п ст', 'ж д ст', 'ж д станция', 'железнодорожная станция', 'поселок станции', 'поселок при железнодорожной станции', 'станция', 'ст' => 'station',
			'х', 'хутор' => 'farm',
			'м в', 'массив' => 'area',
			default => '',
		};
	}

	/** @param array<string,mixed> $location */
	public function type_match_score( string $yandex_locality_raw, array $location ): int {
		$yandex_type = $this->detect_locality_type( $yandex_locality_raw );
		if ( 'other' === $yandex_type ) {
			return 0;
		}
		$wdc_type = $this->detect_location_type( $location );
		if ( '' === $wdc_type ) {
			return 0;
		}

		return $yandex_type === $wdc_type ? 20 : -20;
	}

	/** @param array<string,mixed> $location */
	public function detect_location_type( array $location ): string {
		$effective = $this->effective_location_locality( $location );
		$source = (string) ( $effective['source'] ?? '' );
		$type = match ( $source ) {
			'city_name' => (string) ( $location['city_type'] ?? '' ),
			'settlement_name' => (string) ( $location['settlement_type'] ?? ( $location['place_type'] ?? '' ) ),
			'place_name' => (string) ( $location['place_type'] ?? ( $location['settlement_type'] ?? '' ) ),
			default => (string) ( $location['place_type'] ?? ( $location['settlement_type'] ?? ( $location['city_type'] ?? '' ) ) ),
		};
		$normalized = $this->normalize_type( $type );
		if ( '' !== $normalized ) {
			return $normalized;
		}

		$detected = $this->detect_locality_type( (string) ( $effective['raw'] ?? '' ) );

		return 'other' === $detected ? '' : $detected;
	}

	public function is_territorial_like( string $locality ): bool {
		$value = $this->normalize_text( $locality );
		foreach ( array(
			'производственно административная зона',
			'садоводческое товарищество',
			'садовое товарищество',
			'административный округ',
			'муниципальный округ',
			'городское поселение',
			'городской округ',
			'сельское поселение',
			'территория',
			'район',
			'снт',
			'днп',
			'кп',
			'зона',
		) as $needle ) {
			if ( str_contains( $value, $needle ) ) {
				return true;
			}
		}

		return false;
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
		if ( preg_match( '/(^|\s)(гп|городской поселок)($|\s)/u', $value ) ) {
			$groups[] = array( 'городской поселок', 'гп' );
		}
		if ( preg_match( '/(^|\s)(дп|дачный поселок)($|\s)/u', $value ) ) {
			$groups[] = array( 'дачный поселок', 'дп' );
		}
		if ( preg_match( '/(^|\s)(пгт|поселок городского типа)($|\s)/u', $value ) ) {
			$groups[] = array( 'поселок городского типа', 'пгт' );
		}
		if ( preg_match( '/(^|\s)(д|деревня)($|\s)/u', $value ) ) {
			$groups[] = array( 'деревня', 'д' );
		}
		if ( preg_match( '/(^|\s)(с|село|сельский поселок|слобода|станица|ст ца|стца)($|\s)/u', $value ) ) {
			$groups[] = array( 'село', 'с', 'станица' );
		}
		if ( preg_match( '/(^|\s)(п|пос|поселок|местечко)($|\s)/u', $value ) ) {
			$groups[] = array( 'поселок', 'пос', 'п', 'местечко' );
		}
		if ( preg_match( '/(^|\s)(п ст|ж д ст|железнодорожная станция|ст)($|\s)/u', $value ) ) {
			$groups[] = array( 'железнодорожная станция', 'ж д ст', 'п ст', 'ст' );
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
			'поселок при железнодорожной станции', 'посёлок при железнодорожной станции', 'поселок станции', 'посёлок станции', 'железнодорожная станция', 'ж/д станция', 'ж/д ст', 'ж/д_ст', 'п/ст', 'станция',
			'поселок городского типа', 'посёлок городского типа', 'пгт',
			'городской поселок', 'городской посёлок', 'гп',
			'коттеджный поселок', 'коттеджный посёлок', 'кп', 'дачный поселок', 'дачный посёлок', 'дп',
			'рабочий поселок', 'рабочий посёлок', 'рп',
			'сельский поселок', 'сельский посёлок',
			'город', 'г', 'г.',
			'деревня', 'д', 'д.',
			'село', 'с', 'с.',
			'поселок', 'посёлок', 'пос', 'пос.', 'п', 'п.', 'слобода',
			'имени', 'им', 'им.', 'слобода', 'совхоза', 'санатория', 'фабрики', 'опытного хозяйства', 'хутор', 'х', 'х.', 'станица', 'ст-ца', 'стца', 'ст', 'ст.', 'местечко', 'м-в', 'массив', 'аул', 'снт', 'кп',
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
	private function add_address_candidate_terms( array &$terms, string $type, string $name ): void {
		$name = trim( preg_replace( '/\s+/u', ' ', $name ) ?? $name );
		$type = trim( preg_replace( '/\s+/u', ' ', $type ) ?? $type );
		if ( '' === $name ) {
			return;
		}
		$this->add_term( $terms, $type . ' ' . $name );
		$this->add_term( $terms, $name );
		$parts = preg_split( '/\s+/u', $name ) ?: array();
		if ( isset( $parts[0] ) ) {
			$this->add_term( $terms, (string) $parts[0] );
		}
		foreach ( $this->search_terms_for_locality( $type . ' ' . $name ) as $term ) {
			$this->add_term( $terms, $term );
		}
	}

	private function service_base_term( string $raw ): string {
		$clean = trim( str_replace( array( '"', '«', '»', '“', '”' ), ' ', $raw ) );
		$normalized = $this->normalize_text( $clean );
		if ( preg_match( '/^(?:поселок\s+|пос\s+|п\s+)?(?:совхоза|санатория|фабрики|опытного хозяйства|им|имени)\s+(.+)$/u', $normalized, $matches ) ) {
			return $this->normalize_place( (string) $matches[1] );
		}

		return '';
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
		$value = str_replace( array( 'ж/д_ст', 'ж/д ст', 'п/ст', 'ст-ца', 'м-в' ), array( 'ж д ст', 'ж д ст', 'п ст', 'ст ца', 'м в' ), $value );
		$value = preg_replace( '/[«»"\'`.,()\/_]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}

	private function without_parentheses( string $value ): string {
		$value = preg_replace( '/\s*\([^)]*\)\s*/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}
}
