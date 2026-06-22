<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryGeoMatchScorer {
	/** @param array<string,mixed> $variant @return array{confidence:float,matched_by:array<int,string>,reason:string} */
	public function score( Location $location, array $variant, int $variant_count ): array {
		$candidate = $this->candidate( $variant );
		$place = $this->normalize_text( $location->resolved_place_name() );
		$region = $this->normalize_text( $location->region_name );
		$candidate_locality = $this->normalize_text( $candidate['locality'] );
		$candidate_region = $this->normalize_text( $candidate['region'] );
		$candidate_title = $this->normalize_text( $candidate['title'] );
		$foreign_hint = $this->foreign_country_hint( $candidate['country_hint'] );
		$region_matches = '' !== $region && '' !== $candidate_region && $this->region_matches( $region, $candidate_region );
		$matched_by = array();

		if ( '' === $place ) {
			return $this->result( 0, array(), 'WDC locality is empty' );
		}
		if ( $foreign_hint ) {
			$matched_by[] = 'foreign_country_hint';
		}
		if ( '' !== $candidate_locality && $candidate_locality === $place ) {
			$matched_by[] = 'locality_exact';
			if ( $region_matches ) {
				$matched_by[] = 'region_match';
				return $this->result( $foreign_hint ? 10 : 100, $matched_by, $foreign_hint ? 'Exact locality match has a foreign country hint' : 'Exact locality and region match' );
			}
			if ( '' === $region || '' === $candidate_region ) {
				$matched_by[] = 'region_unverified';
				return $this->result( $foreign_hint ? 10 : 95, $matched_by, $foreign_hint ? 'Exact locality match has a foreign country hint' : 'Exact locality match with no region verification' );
			}
			$matched_by[] = 'region_mismatch';
			return $this->result( $foreign_hint ? 10 : 60, $matched_by, $foreign_hint ? 'Exact locality match has a foreign country hint' : 'Exact locality match but region differs' );
		}

		if ( '' !== $candidate_title && str_starts_with( $candidate_title, $place . ',' ) ) {
			$matched_by[] = 'title_prefix';
			if ( $region_matches ) {
				$matched_by[] = 'region_match';
				return $this->result( $foreign_hint ? 10 : 80, $matched_by, $foreign_hint ? 'Title prefix match has a foreign country hint' : 'Title starts with locality and region matches' );
			}
		}

		if ( '' !== $candidate_title && preg_match( '/(^|\s|,)'. preg_quote( $place, '/' ) .'($|\s|,)/u', $candidate_title ) ) {
			$matched_by[] = 'title_token';
			return $this->result( $foreign_hint ? 10 : 30, $matched_by, $foreign_hint ? 'Standalone locality token has a foreign country hint' : 'Candidate title contains locality as a standalone token' );
		}

		if ( ( '' !== $candidate_title && str_contains( $candidate_title, $place ) ) || ( '' !== $candidate_locality && str_contains( $candidate_locality, $place ) ) ) {
			$matched_by[] = 'weak_substring';
			return $this->result( 10, $matched_by, 'Weak substring locality match' );
		}

		return $this->result( 0, array(), 'No locality match' );
	}

	/** @param array<string,mixed> $variant @return array{title:string,locality:string,region:string,country_hint:string} */
	private function candidate( array $variant ): array {
		$title = $this->first_scalar( $variant, array( 'title', 'name', 'locality', 'city' ) );
		$locality = $this->first_scalar( $variant, array( 'locality', 'city', 'name', 'title' ) );
		$region = $this->first_scalar( $variant, array( 'region', 'region_name' ) );
		$country_hint = $this->first_scalar( $variant, array( 'country', 'country_name' ) );
		$address = $variant['address'] ?? null;

		if ( is_array( $address ) ) {
			$title = '' !== $title ? $title : $this->first_scalar( $address, array( 'title', 'formatted', 'full', 'address' ) );
			$locality = '' !== $locality ? $locality : $this->first_scalar( $address, array( 'locality', 'city', 'name' ) );
			$region = '' !== $region ? $region : $this->first_scalar( $address, array( 'region', 'region_name', 'province' ) );
			$country_hint = '' !== $country_hint ? $country_hint : $this->first_scalar( $address, array( 'country', 'country_name' ) );
		}

		if ( is_string( $address ) && '' !== trim( $address ) ) {
			$title = '' !== $title ? $title : trim( $address );
			$parts = array_values( array_filter( array_map( 'trim', explode( ',', $address ) ), static fn( string $part ): bool => '' !== $part ) );
			$locality = '' !== $locality ? $locality : (string) ( $parts[0] ?? '' );
			if ( '' === $region && count( $parts ) > 1 ) {
				$region = implode( ', ', array_slice( $parts, 1 ) );
			}
			$country_hint = '' !== $country_hint ? $country_hint : $this->country_hint_from_parts( $parts );
		}

		return array(
			'title' => $title,
			'locality' => $locality,
			'region' => $region,
			'country_hint' => $country_hint,
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
			$normalized = $this->normalize_text( $part );
			if ( in_array( $normalized, array( 'россия', 'рф', 'франция', 'италия' ), true ) ) {
				return $part;
			}
		}

		return '';
	}

	private function foreign_country_hint( string $country_hint ): bool {
		$country = $this->normalize_text( $country_hint );
		return '' !== $country && ! in_array( $country, array( 'россия', 'рф', 'russia', 'ru' ), true );
	}

	private function region_matches( string $wdc_region, string $candidate_region ): bool {
		if ( $wdc_region === $candidate_region ) {
			return true;
		}
		if ( str_contains( $candidate_region, $wdc_region ) || str_contains( $wdc_region, $candidate_region ) ) {
			return true;
		}

		return false;
	}

	private function normalize_text( string $value ): string {
		$value = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = str_replace( array( '(', ')', '.', ';', ':', '/', '\\', '-' ), ' ', $value );
		$value = preg_replace( '/\b(рабочий\s+поселок|муниципальный\s+округ|городской\s+округ)\b/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\b(город|село|деревня|поселок|снт|кп|район|область|обл|республика|край|р\s*н|рп|г|с|д|п)\b/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );

		return is_string( $value ) ? $value : '';
	}

	/** @param array<int,string> $matched_by @return array{confidence:float,matched_by:array<int,string>,reason:string} */
	private function result( float $confidence, array $matched_by, string $reason ): array {
		return array(
			'confidence' => max( 0.0, min( 100.0, $confidence ) ),
			'matched_by' => array_values( array_unique( $matched_by ) ),
			'reason' => $reason,
		);
	}
}