<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoMatchScorer {
	/** @param array<string,mixed> $variant @return array{confidence:float,matched_by:array<int,string>,reason:string,components:array<string,float>} */
	public function score( Location $location, array $variant, int $variant_count ): array {
		$candidate = $this->candidate( $variant );
		$wdc_place = $this->split_typed_name( $location->resolved_place_type(), $location->resolved_place_name() );
		$wdc_region = $this->normalize_context_text( $location->region_name );
		$wdc_district = $this->normalize_context_text( trim( $location->district_name . ' ' . $location->district_type ) );
		$wdc_city = $this->normalize_context_text( trim( $location->city_name . ' ' . $location->city_type ) );
		$wdc_place_name = $this->normalize_name_text( $wdc_place['name'] );
		$candidate_place = $this->split_typed_name( '', $candidate['locality'] );
		$candidate_locality = $this->normalize_name_text( $candidate_place['name'] );
		$candidate_title = $this->normalize_name_text( $candidate['title'] );
		$candidate_title_with_commas = $this->normalize_title_text( $candidate['title'] );
		$candidate_context = $this->normalize_context_text( trim( $candidate['context'] . ' ' . $candidate['region'] ) );
		$matched_by = array();
		$components = array(
			'base' => 0.0,
			'region' => 0.0,
			'district' => 0.0,
			'city_context' => 0.0,
			'type' => 0.0,
			'penalty' => 0.0,
		);

		if ( '' === $wdc_place_name ) {
			return $this->result( 0, array(), 'WDC locality is empty', $components );
		}

		$locality_exact = '' !== $candidate_locality && $candidate_locality === $wdc_place_name;
		$title_prefix = '' !== $candidate_title_with_commas && str_starts_with( $candidate_title_with_commas, $wdc_place_name . ',' );
		$title_token = '' !== $candidate_title && (bool) preg_match( '/(^|\s)' . preg_quote( $wdc_place_name, '/' ) . '($|\s)/u', $candidate_title );
		$weak_substring = ! $locality_exact && ! $title_prefix && ! $title_token && ( ( '' !== $candidate_title && str_contains( $candidate_title, $wdc_place_name ) ) || ( '' !== $candidate_locality && str_contains( $candidate_locality, $wdc_place_name ) ) );

		if ( $locality_exact ) {
			$components['base'] = 50.0;
			$matched_by[] = 'locality_exact';
		} elseif ( $title_prefix ) {
			$components['base'] = 40.0;
			$matched_by[] = 'title_prefix';
		} elseif ( $title_token ) {
			$components['base'] = 20.0;
			$matched_by[] = 'title_token';
		} elseif ( $weak_substring ) {
			$components['base'] = 5.0;
			$matched_by[] = 'weak_substring';
		}

		$region_match = $this->context_matches( $wdc_region, $candidate_context );
		if ( ! $region_match && $locality_exact && '' !== $wdc_region && $wdc_region === $wdc_place_name && ( '' === $candidate_context || str_contains( $candidate_context, $wdc_place_name ) ) ) {
			$region_match = true;
		}
		if ( $region_match ) {
			$components['region'] = 30.0;
			$matched_by[] = 'region_match';
		}

		$district_match = '' !== $wdc_district && $this->context_matches( $wdc_district, $candidate_context );
		if ( $district_match ) {
			$components['district'] = 15.0;
			$matched_by[] = 'district_match';
		}

		$city_context_match = '' !== $wdc_city && $this->context_matches( $wdc_city, $candidate_context );
		if ( $city_context_match ) {
			$components['city_context'] = 10.0;
			$matched_by[] = 'city_context_match';
		}

		$type_match = '' !== $wdc_place['type'] && '' !== $candidate_place['type'] && $wdc_place['type'] === $candidate_place['type'];
		$type_mismatch = '' !== $wdc_place['type'] && '' !== $candidate_place['type'] && $wdc_place['type'] !== $candidate_place['type'];
		if ( $type_match ) {
			$components['type'] = 5.0;
			$matched_by[] = 'type_match';
		} elseif ( $type_mismatch ) {
			$matched_by[] = 'type_mismatch';
		}

		$score = (float) $components['base'] > 0.0 ? array_sum( array_intersect_key( $components, array( 'base' => true, 'region' => true, 'district' => true, 'city_context' => true, 'type' => true ) ) ) : 0.0;
		$caps = array();
		$foreign_hint = $this->foreign_country_hint( $candidate['country_hint'], $candidate['title'] );
		$has_candidate_region_context = '' !== $candidate_context && $this->looks_like_region_context( $candidate_context );
		$region_mismatch = '' !== $wdc_region && $has_candidate_region_context && ! $region_match;
		$admin_unit = $this->is_admin_unit_candidate( $candidate['first_part'], $candidate['title'], $locality_exact );

		if ( $foreign_hint ) {
			$matched_by[] = 'foreign_country_hint';
			$caps[] = 10.0;
		}
		if ( $region_mismatch ) {
			$matched_by[] = 'region_mismatch';
			$caps[] = 40.0;
		}
		if ( $locality_exact && $region_mismatch ) {
			$caps[] = 40.0;
		}
		if ( $weak_substring ) {
			$caps[] = 10.0;
		}
		if ( $admin_unit ) {
			$matched_by[] = 'admin_unit_candidate';
			$caps[] = 30.0;
		}
		if ( $type_mismatch && ! $region_match ) {
			$caps[] = 40.0;
		}
		if ( ! $locality_exact && $region_match && '' !== $wdc_region && $wdc_region === $wdc_place_name ) {
			$caps[] = 40.0;
		}

		if ( $locality_exact && $region_match ) {
			$score = max( $score, 95.0 );
		} elseif ( $locality_exact && '' === $candidate_context ) {
			$matched_by[] = 'region_unverified';
			$score = max( $score, 95.0 );
		}

		$score_before_caps = $score;
		if ( array() !== $caps ) {
			$score = min( $score, min( $caps ) );
		}
		$components['penalty'] = max( 0.0, $score_before_caps - $score );
		$reason = $this->reason( $matched_by, $components, $score );

		return $this->result( $score, $matched_by, $reason, $components );
	}

	/** @param array<string,mixed> $variant @return array{title:string,locality:string,region:string,context:string,country_hint:string,first_part:string} */
	private function candidate( array $variant ): array {
		$title = $this->first_scalar( $variant, array( 'title', 'name', 'locality', 'city' ) );
		$locality = $this->first_scalar( $variant, array( 'locality', 'city', 'name', 'title' ) );
		$region = $this->first_scalar( $variant, array( 'region', 'region_name' ) );
		$country_hint = $this->first_scalar( $variant, array( 'country', 'country_name' ) );
		$context = '';
		$first_part = '';
		$address = $variant['address'] ?? null;

		if ( is_array( $address ) ) {
			$title = '' !== $title ? $title : $this->first_scalar( $address, array( 'title', 'formatted', 'full', 'address' ) );
			$locality = '' !== $locality ? $locality : $this->first_scalar( $address, array( 'locality', 'city', 'name' ) );
			$region = '' !== $region ? $region : $this->first_scalar( $address, array( 'region', 'region_name', 'province' ) );
			$country_hint = '' !== $country_hint ? $country_hint : $this->first_scalar( $address, array( 'country', 'country_name' ) );
			$context = implode( ', ', array_filter( array( $region, $this->first_scalar( $address, array( 'district', 'area', 'subregion' ) ) ), static fn( string $part ): bool => '' !== $part ) );
		}

		if ( is_string( $address ) && '' !== trim( $address ) ) {
			$title = '' !== $title ? $title : trim( $address );
			$parts = array_values( array_filter( array_map( 'trim', explode( ',', $address ) ), static fn( string $part ): bool => '' !== $part ) );
			$first_part = (string) ( $parts[0] ?? '' );
			$locality = '' !== $locality ? $locality : $first_part;
			$context = count( $parts ) > 1 ? implode( ', ', array_slice( $parts, 1 ) ) : '';
			if ( '' === $region && '' !== $context ) {
				$region = $context;
			}
			$country_hint = '' !== $country_hint ? $country_hint : $this->country_hint_from_parts( $parts );
		}

		$first_part = '' !== $first_part ? $first_part : $locality;

		return array(
			'title' => $title,
			'locality' => $locality,
			'region' => $region,
			'context' => $context,
			'country_hint' => $country_hint,
			'first_part' => $first_part,
		);
	}

	/** @param array<string,mixed> $source @param array<int,string> $keys */
	private function first_scalar( array $source, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = $source[ $key ] ?? null;
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}

		return '';
	}

	/** @param array<int,string> $parts */
	private function country_hint_from_parts( array $parts ): string {
		foreach ( $parts as $part ) {
			$normalized = $this->normalize_context_text( $part );
			if ( in_array( $normalized, array( 'россия', 'рф', 'франция', 'италия' ), true ) ) {
				return $part;
			}
		}

		return '';
	}

	private function foreign_country_hint( string $country_hint, string $title ): bool {
		$country = $this->normalize_context_text( $country_hint );
		if ( '' !== $country ) {
			return ! in_array( $country, array( 'россия', 'рф', 'russia', 'ru' ), true );
		}
		$title = $this->normalize_context_text( $title );

		return (bool) preg_match( '/(^|\s)(франция|италия|france|italy)($|\s)/u', $title );
	}

	private function context_matches( string $needle, string $haystack ): bool {
		$needle = $this->normalize_context_text( $needle );
		$haystack = $this->normalize_context_text( $haystack );
		if ( '' === $needle || '' === $haystack ) {
			return false;
		}
		if ( str_contains( $haystack, $needle ) || str_contains( $needle, $haystack ) ) {
			return true;
		}
		$needle_stems = $this->stems( $needle );
		$haystack_stems = $this->stems( $haystack );
		foreach ( $needle_stems as $stem ) {
			if ( strlen( $stem ) < 4 ) {
				continue;
			}
			if ( in_array( $stem, $haystack_stems, true ) || str_contains( $haystack, $stem ) ) {
				return true;
			}
		}

		return false;
	}

	private function looks_like_region_context( string $candidate_context ): bool {
		return (bool) preg_match( '/(^|\s)(область|край|республика|марий|эл|татарстан|башкортостан|москва|санкт\s+петербург|свердловская|новосибирская|краснодарский)($|\s)/u', $candidate_context );
	}

	private function is_admin_unit_candidate( string $first_part, string $title, bool $locality_exact ): bool {
		$first = $this->normalize_context_text( $first_part );
		$full = $this->normalize_context_text( $title );
		if ( (bool) preg_match( '/(^|\s)(сельсовет|муниципальный\s+округ|городской\s+округ)($|\s)/u', $first ) ) {
			return true;
		}

		return ! $locality_exact && (bool) preg_match( '/(^|\s)(сельсовет|муниципальный\s+округ|городской\s+округ)($|\s)/u', $full );
	}

	/** @return array{name:string,type:string} */
	private function split_typed_name( string $type, string $name ): array {
		$canonical_type = $this->canonical_type( $type );
		$name = trim( $name );
		$normalized = $this->normalize_common( $name );
		$type_patterns = $this->type_patterns();
		foreach ( $type_patterns as $pattern => $canonical ) {
			if ( preg_match( '/^' . $pattern . '\s+/u', $normalized ) ) {
				$canonical_type = '' !== $canonical_type ? $canonical_type : $canonical;
				$normalized = preg_replace( '/^' . $pattern . '\s+/u', '', $normalized ) ?? $normalized;
				break;
			}
		}

		return array( 'name' => $normalized, 'type' => $canonical_type );
	}

	private function canonical_type( string $type ): string {
		$type = $this->normalize_common( $type );
		foreach ( $this->type_patterns() as $pattern => $canonical ) {
			if ( preg_match( '/^' . $pattern . '$/u', $type ) ) {
				return $canonical;
			}
		}

		return '';
	}

	/** @return array<string,string> */
	private function type_patterns(): array {
		return array(
			'рабочий\s+поселок|рп' => 'work_settlement',
			'поселок|посёлок|п' => 'settlement',
			'город|г' => 'city',
			'село|с' => 'selo',
			'деревня|д' => 'village',
			'хутор|х' => 'khutor',
			'станица|ст' => 'stanitsa',
			'аул' => 'aul',
			'слобода|сл' => 'sloboda',
			'снт' => 'snt',
			'кп' => 'cottage_settlement',
		);
	}

	private function normalize_name_text( string $value ): string {
		$value = $this->normalize_common( $value );
		$value = preg_replace( '/(^|\s)(рабочий\s+поселок|город|село|деревня|поселок|посёлок|хутор|станица|аул|слобода|снт|кп|рп|ст|сл|г|с|д|п|х)($|\s)/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );

		return is_string( $value ) ? $value : '';
	}

	private function normalize_title_text( string $value ): string {
		$value = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = str_replace( array( '(', ')', '.', ';', ':', '/', '\\', '-' ), ' ', $value );
		$value = preg_replace( '/(^|\s)(рабочий\s+поселок|город|село|деревня|поселок|посёлок|хутор|станица|аул|слобода|снт|кп|рп|ст|сл|г|с|д|п|х)($|\s)/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s*,\s*/u', ',', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );

		return is_string( $value ) ? $value : '';
	}

	private function normalize_context_text( string $value ): string {
		$value = $this->normalize_common( $value );
		$value = preg_replace( '/\b(муниципальный\s+округ|городской\s+округ|район|р\s*н|область|обл|республика|респ|край)\b/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );

		return is_string( $value ) ? $value : '';
	}

	private function normalize_common( string $value ): string {
		$value = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = str_replace( array( '(', ')', '.', ';', ':', '/', '\\', '-', '"', "'" ), ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );

		return is_string( $value ) ? $value : '';
	}

	/** @return array<int,string> */
	private function stems( string $value ): array {
		$tokens = preg_split( '/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		$stems = array();
		foreach ( $tokens as $token ) {
			$stem = (string) preg_replace( '/(ский|ская|ское|ского|скому|ском|ий|ый|ая|ое|ые|ой|а|я|о|е)$/u', '', $token );
			$stems[] = '' !== $stem ? $stem : $token;
		}

		return array_values( array_unique( $stems ) );
	}

	/** @param array<int,string> $matched_by @param array<string,float> $components */
	private function reason( array $matched_by, array $components, float $score ): string {
		if ( array() === $matched_by ) {
			return 'No locality or context match';
		}

		return sprintf( 'Score %.2f from %s', $score, implode( ', ', array_values( array_unique( $matched_by ) ) ) );
	}

	/** @param array<int,string> $matched_by @param array<string,float> $components @return array{confidence:float,matched_by:array<int,string>,reason:string,components:array<string,float>} */
	private function result( float $confidence, array $matched_by, string $reason, array $components ): array {
		return array(
			'confidence' => max( 0.0, min( 100.0, $confidence ) ),
			'matched_by' => array_values( array_unique( $matched_by ) ),
			'reason' => $reason,
			'components' => $components,
		);
	}
}
