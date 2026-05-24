<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Locations;

use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Services\LocationDisplayNameFormatter;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class CheckoutLocationSearch {
	public function __construct(
		private LocationSearchService $search_service
	) {
	}

	/**
	 * @return array<int,Location>
	 */
	public function search( string $query, int $limit = 100 ): array {
		return $this->search_service->search( $query, $limit );
	}

	/**
	 * @return array<string,array<int,Location>>
	 */
	public function grouped( string $query, int $limit = 100 ): array {
		return $this->search_service->grouped( $query, $limit );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function search_for_picker( string $query, int $limit = 100, int $region_limit = 10, string $force_region_code = '' ): array {
		$parser = $this->parser();
		$parsed = $parser->parse( $query );
		if ( '' !== trim( $force_region_code ) ) {
			$parsed = $this->remove_forced_region_tokens( $parsed, $force_region_code, $parser );
		}
		$tokens = $parsed['real_tokens'];
		$limit = max( 10, min( 500, $limit ) );
		$region_limit = max( 3, min( 50, $region_limit ) );
		if ( array() === $tokens && '' === trim( $force_region_code ) ) {
			return array( 'items' => array(), 'groups' => array(), 'total' => 0 );
		}

		$scored = $this->scored_hierarchy_candidates( $parsed, $parser, $limit, $force_region_code );
		if ( array() === $scored ) {
			$this->search_service->search( $query, 1 );
			$meta = $this->search_service->last_search_meta();
			if ( ! empty( $meta['correction_used'] ) && ! empty( $meta['corrected_query'] ) ) {
				$corrected = $parser->parse( (string) $meta['corrected_query'] );
				if ( array() !== $corrected['real_tokens'] ) {
					$parsed = $corrected;
					$tokens = $parsed['real_tokens'];
					$scored = $this->scored_hierarchy_candidates( $parsed, $parser, $limit, $force_region_code );
				}
			}
		}
		usort(
			$scored,
			fn( array $a, array $b ): int => $this->compare_scored_locations( $a, $b )
		);

		$groups = $this->group_picker_items( $scored, $limit, $region_limit, $force_region_code );
		$items = array_map( static fn( array $row ): Location => $row['location'], array_slice( $scored, 0, $limit ) );
		return array(
			'items'          => $items,
			'groups'         => $groups['groups'],
			'total'          => count( $scored ),
			'shown_total'    => $groups['shown_total'],
			'has_more_total' => $groups['has_more_total'],
		);
	}

	/**
	 * @return array{status:string,location:?Location}
	 */
	public function resolve_checkout_fields( string $region_text, string $city_text ): array {
		$query = trim( $region_text . ' ' . $city_text );
		$parsed = $this->parser()->parse( $query );
		$tokens = $parsed['real_tokens'];
		if ( array() === $tokens ) {
			return array( 'status' => 'not_found', 'location' => null );
		}

		$result = $this->search_for_picker( $query, 20, 20 );
		$candidates = array_values(
			array_filter(
				$result['items'],
				fn( Location $location ): bool => $this->hierarchy_score( $location, $parsed, $this->parser() )['matched_tokens'] === count( $tokens )
			)
		);
		if ( array() === $candidates ) {
			return array( 'status' => 'not_found', 'location' => null );
		}

		usort( $candidates, fn( Location $a, Location $b ): int => $this->hierarchy_score( $b, $parsed, $this->parser() )['total'] <=> $this->hierarchy_score( $a, $parsed, $this->parser() )['total'] ?: strcmp( $a->display_name, $b->display_name ) );
		$best = $candidates[0];
		$best_score = $this->hierarchy_score( $best, $parsed, $this->parser() )['total'];
		$ties = array_filter( $candidates, fn( Location $location ): bool => $this->hierarchy_score( $location, $parsed, $this->parser() )['total'] === $best_score );

		return 1 === count( $ties ) ? array( 'status' => 'resolved', 'location' => $best ) : array( 'status' => 'ambiguous', 'location' => null );
	}

	/**
	 * @return array{items:array<int,Location>, total:int, page:int, per_page:int, total_pages:int}
	 */
	public function search_paginated( string $query, int $page = 1, int $per_page = 20 ): array {
		$per_page = in_array( $per_page, array( 10, 20, 50, 100 ), true ) ? $per_page : 20;
		$page = max( 1, $page );
		$query = trim( $query );
		if ( '' === $query ) {
			return array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => $per_page, 'total_pages' => 0 );
		}

		$exact = $this->search_service->find_exact_admin_identifier_matches( $query );
		if ( array() !== $exact ) {
			$total = count( $exact );
			$total_pages = (int) ceil( $total / $per_page );
			$page = min( $page, max( 1, $total_pages ) );
			$offset = ( $page - 1 ) * $per_page;
			return array(
				'items'       => array_slice( $exact, $offset, $per_page ),
				'total'       => $total,
				'page'        => $page,
				'per_page'    => $per_page,
				'total_pages' => $total_pages,
			);
		}

		$parser = $this->parser();
		$parsed = $parser->parse( $query );
		if ( array() === $parsed['real_tokens'] ) {
			return array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => $per_page, 'total_pages' => 0 );
		}

		$scored = $this->scored_hierarchy_candidates( $parsed, $parser, 250, '' );
		usort( $scored, fn( array $a, array $b ): int => $this->compare_scored_locations( $a, $b ) );
		$items = array_map( static fn( array $row ): Location => $row['location'], $scored );
		$total = count( $items );
		$total_pages = (int) ceil( $total / $per_page );
		$page = min( $page, max( 1, $total_pages ) );
		$offset = ( $page - 1 ) * $per_page;

		return array(
			'items'       => array_slice( $items, $offset, $per_page ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total_pages,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function last_search_meta(): array {
		return $this->search_service->last_search_meta();
	}

	public function best_match( string $query ): ?Location {
		$normalized = $this->search_service->normalize( $query );
		if ( '' === $normalized ) {
			return null;
		}

		$locations = $this->search_service->search( $query, 50 );
		if ( array() === $locations ) {
			return null;
		}

		usort(
			$locations,
			fn ( Location $a, Location $b ): int => $this->score( $b, $normalized ) <=> $this->score( $a, $normalized )
				?: strcmp( $a->display_name, $b->display_name )
		);

		return $locations[0] ?? null;
	}

	private function score( Location $location, string $query ): int {
		$settlement = $this->normalize( $location->resolved_place_name() );
		$city       = $this->normalize( $location->city_name );
		$region     = $this->normalize( $location->region_name );
		$display    = $this->normalize( $location->display_name );
		$score      = 0;

		if ( '' !== $settlement && $settlement === $query ) {
			$score += 1200;
		}

		if ( '' !== $city && $city === $query ) {
			$score += 1000;
		}

		if ( '' !== $region && $region === $query ) {
			$score += 650;
		}

		foreach (
			array(
				$settlement => 320,
				$city       => 280,
				$region     => 160,
				$display    => 120,
			) as $field => $prefix_score
		) {
			if ( '' === $field ) {
				continue;
			}

			if ( str_starts_with( $field, $query ) ) {
				$score += $prefix_score;
				continue;
			}

			if ( str_contains( $field, $query ) ) {
				$score += (int) floor( $prefix_score / 3 );
			}
		}

		return $score;
	}

	private function normalize( string $value ): string {
		return $this->search_service->normalize( $value );
	}

	/**
	 * @return array<int,string>
	 */
	public function tokens( string $query ): array {
		$query = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), $query );
		$query = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', $query );
		$query = $this->normalize( is_string( $query ) ? $query : '' );
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

	/**
	 * @param array<int,Location> $locations
	 * @return array<int,Location>
	 */
	private function unique_locations( array $locations ): array {
		$unique = array();
		foreach ( $locations as $location ) {
			$key = (string) ( $location->id ?? $location->fias_id ?: $location->gar_object_id );
			$unique[ $key ] = $location;
		}
		return array_values( $unique );
	}

	/**
	 * @param array{query:string,real_tokens:array<int,string>,markers:array<string,bool>,region_alias_tokens?:array<int,string>} $parsed
	 * @return array{total:int,matched_tokens:int,matched_levels:int,all_tokens:bool,place_match:int,city_match:int,district_match:int,region_match:int,group_strength:int,depth:int}
	 */
	private function hierarchy_score( Location $location, array $parsed, CheckoutLocationSearchParser $parser ): array {
		$tokens = $parsed['real_tokens'];
		$query = (string) $parsed['query'];
		$fields = array(
			'region'   => $parser->normalize( $location->region_name ),
			'district' => $parser->normalize( $location->district_name ),
			'city'     => $parser->normalize( $location->city_name ),
			'place'    => $parser->normalize( $location->resolved_place_name() ),
		);

		$region_alias_tokens = $parsed['region_alias_tokens'] ?? array();
		$region_alias_query = in_array( $query, $region_alias_tokens, true );
		$phrase = array(
			'place'    => $region_alias_query ? 0 : $this->phrase_match_strength( $fields['place'], $query ),
			'city'     => $region_alias_query ? 0 : $this->phrase_match_strength( $fields['city'], $query ),
			'district' => $region_alias_query ? 0 : $this->phrase_match_strength( $fields['district'], $query ),
			'region'   => $this->phrase_match_strength( $fields['region'], $query ),
		);

		$level_matches = array( 'region' => 0, 'district' => 0, 'city' => 0, 'place' => 0 );
		$matched_tokens = 0;
		foreach ( $tokens as $token ) {
			$matched = false;
			$search_fields = in_array( $token, $region_alias_tokens, true ) ? array( 'region' => $fields['region'] ) : $fields;
			foreach ( $search_fields as $level => $value ) {
				$strength = $this->token_match_strength( $value, $token );
				if ( $strength > 0 ) {
					$matched = true;
					$level_matches[ $level ] = max( $level_matches[ $level ], $strength );
				}
			}
			if ( $matched ) {
				++$matched_tokens;
			}
		}

		foreach ( $phrase as $level => $strength ) {
			$level_matches[ $level ] = max( $level_matches[ $level ], $strength );
		}

		$exact_city = 3 === $phrase['city'] || 3 === $level_matches['city'];
		$prefix_city = ! $exact_city && ( 2 === $phrase['city'] || 2 === $level_matches['city'] );
		$exact_place = 3 === $phrase['place'] || 3 === $level_matches['place'];
		$prefix_place = ! $exact_place && ( 2 === $phrase['place'] || 2 === $level_matches['place'] );
		$matched_levels = count( array_filter( $level_matches, static fn( int $strength ): bool => $strength > 0 ) );
		$all_tokens = $matched_tokens === count( $tokens );
		$depth = ( '' !== $fields['place'] ? 4 : 0 ) + ( '' !== $fields['city'] ? 3 : 0 ) + ( '' !== $fields['district'] ? 2 : 0 ) + ( '' !== $fields['region'] ? 1 : 0 );
		$district_place = $level_matches['district'] > 0 && $level_matches['place'] > 0;
		$region_only_match = $level_matches['region'] > 0 && 0 === $level_matches['place'] && 0 === $level_matches['city'] && ! $district_place;
		$group_rank_bucket = match ( true ) {
			$exact_city || $exact_place => 1,
			$prefix_city || $prefix_place => 2,
			$district_place => 3,
			$region_only_match => 4,
			default => 5,
		};
		$matched_hierarchy_rank = match ( $group_rank_bucket ) {
			1 => $exact_city ? 3 : 2,
			2 => $prefix_city ? 3 : 2,
			3 => 1,
			default => 0,
		};
		$group_strength = 800 - ( $group_rank_bucket * 100 );

		$total = ( $all_tokens ? 100000 : 0 )
			+ ( $matched_levels * 10000 )
			+ ( $phrase['place'] * 5000 )
			+ ( $level_matches['place'] * 4000 )
			+ ( $phrase['city'] * 3000 )
			+ ( $level_matches['city'] * 2500 )
			+ ( $level_matches['district'] * 1500 )
			+ ( $level_matches['region'] * 1000 )
			+ $depth;

		if ( isset( $parsed['markers']['place'] ) && $level_matches['place'] > 0 ) {
			$total += 1200;
		}
		if ( isset( $parsed['markers']['city'] ) && $level_matches['city'] > 0 ) {
			$total += 900;
		}
		if ( isset( $parsed['markers']['district'] ) && $level_matches['district'] > 0 ) {
			$total += 700;
		}
		if ( isset( $parsed['markers']['region'] ) && $level_matches['region'] > 0 ) {
			$total += 500;
		}

		return array(
			'total'          => $total,
			'matched_tokens' => $matched_tokens,
			'matched_levels' => $matched_levels,
			'all_tokens'     => $all_tokens,
			'place_match'    => $level_matches['place'],
			'city_match'     => $level_matches['city'],
			'district_match' => $level_matches['district'],
			'region_match'   => $level_matches['region'],
			'region_only_match' => $region_only_match,
			'group_rank_bucket' => $group_rank_bucket,
			'matched_hierarchy_rank' => $matched_hierarchy_rank,
			'group_strength' => $group_strength,
			'depth'          => $depth,
		);
	}

	private function compare_scored_locations( array $a, array $b ): int {
		return (int) $a['score']['group_rank_bucket'] <=> (int) $b['score']['group_rank_bucket']
			?: (int) $b['score']['matched_hierarchy_rank'] <=> (int) $a['score']['matched_hierarchy_rank']
			?: (int) $b['score']['matched_levels'] <=> (int) $a['score']['matched_levels']
			?: (int) $b['score']['place_match'] <=> (int) $a['score']['place_match']
			?: (int) $b['score']['city_match'] <=> (int) $a['score']['city_match']
			?: (int) $b['score']['district_match'] <=> (int) $a['score']['district_match']
			?: (int) $b['score']['region_match'] <=> (int) $a['score']['region_match']
			?: (int) $b['score']['total'] <=> (int) $a['score']['total']
			?: (int) $b['score']['matched_tokens'] <=> (int) $a['score']['matched_tokens']
			?: strcmp( $a['location']->display_name, $b['location']->display_name );
	}

	/**
	 * @param array{real_tokens:array<int,string>} $parsed
	 * @return array<int,array{location:Location,score:array<string,mixed>}>
	 */
	private function scored_hierarchy_candidates( array $parsed, CheckoutLocationSearchParser $parser, int $limit, string $force_region_code ): array {
		$tokens = $parsed['real_tokens'];
		$locations = $this->search_service->checkout_hierarchy_candidates( $tokens, max( $limit * 8, 500 ), $force_region_code );
		$locations = $this->unique_locations( $locations );
		$scored = array_map(
			fn( Location $location ): array => array(
				'location' => $location,
				'score'    => $this->hierarchy_score( $location, $parsed, $parser ),
			),
			$locations
		);

		return $this->filter_hierarchy_matches( $scored, count( $tokens ) );
	}

	/**
	 * @param array<int,array{location:Location,score:array<string,mixed>}> $scored
	 * @return array<int,array{location:Location,score:array<string,mixed>}>
	 */
	private function filter_hierarchy_matches( array $scored, int $token_count ): array {
		$scored = array_values(
			array_filter(
				$scored,
				static fn( array $row ): bool => (int) $row['score']['matched_tokens'] === $token_count
			)
		);
		if ( array() === $scored ) {
			return array();
		}

		$has_strong_place = count(
			array_filter( $scored, static fn( array $row ): bool => (int) $row['score']['place_match'] > 0 )
		) > 0;
		if ( ! $has_strong_place ) {
			return $scored;
		}

		return array_values(
			array_filter(
				$scored,
				static fn( array $row ): bool => (int) $row['score']['place_match'] > 0 || (int) $row['score']['city_match'] > 0 || ! empty( $row['score']['region_only_match'] )
			)
		);
	}

	private function phrase_match_strength( string $field, string $query ): int {
		if ( '' === $field || '' === $query ) {
			return 0;
		}
		if ( $field === $query ) {
			return 3;
		}
		return str_starts_with( $field, $query ) ? 2 : 0;
	}

	private function token_match_strength( string $field, string $token ): int {
		if ( '' === $field || '' === $token ) {
			return 0;
		}
		if ( $field === $token ) {
			return 3;
		}
		foreach ( preg_split( '/\s+/u', $field, -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $word ) {
			if ( $word === $token ) {
				return 3;
			}
			if ( str_starts_with( $word, $token ) ) {
				return 2;
			}
		}
		return str_starts_with( $field, $token ) ? 2 : 0;
	}

	/**
	 * @param array{query:string,tokens:array<int,string>,real_tokens:array<int,string>,markers:array<string,bool>,has_markers:bool,region_alias_tokens?:array<int,string>} $parsed
	 * @return array{query:string,tokens:array<int,string>,real_tokens:array<int,string>,markers:array<string,bool>,has_markers:bool,region_alias_tokens?:array<int,string>}
	 */
	private function remove_forced_region_tokens( array $parsed, string $force_region_code, CheckoutLocationSearchParser $parser ): array {
		$candidates = $this->search_service->checkout_hierarchy_candidates( array(), 1, $force_region_code );
		$region = $candidates[0]->region_name ?? '';
		$region = $parser->normalize( $region );
		if ( '' === $region ) {
			return $parsed;
		}

		$tokens = array_values(
			array_filter(
				$parsed['real_tokens'],
				fn( string $token ): bool => 0 === $this->token_match_strength( $region, $token )
			)
		);
		$parsed['real_tokens'] = $tokens;
		$parsed['query'] = implode( ' ', $tokens );

		return $parsed;
	}

	/**
	 * @param array<int,array{location:Location,score:array<string,mixed>}> $scored
	 * @return array{groups:array<int,array<string,mixed>>,shown_total:int,has_more_total:bool}
	 */
	private function group_picker_items( array $scored, int $limit, int $region_limit, string $force_region_code ): array {
		$formatter = $this->formatter();
		$by_region = array();
		foreach ( $scored as $row ) {
			$location = $row['location'];
			$key = '' !== $location->region_code ? $location->region_code : $location->region_name;
			if ( '' === $key ) {
				$key = 'unknown';
			}
			$by_region[ $key ]['rows'][] = $row;
			$by_region[ $key ]['label'] = $formatter->format_checkout_region_header( $location );
			$by_region[ $key ]['sort'] = $location->region_name;
			$row_bucket = (int) $row['score']['group_rank_bucket'];
			$current_bucket = (int) ( $by_region[ $key ]['bucket'] ?? 5 );
			if ( $row_bucket < $current_bucket ) {
				$by_region[ $key ]['bucket'] = $row_bucket;
				$by_region[ $key ]['matched_hierarchy_rank'] = (int) $row['score']['matched_hierarchy_rank'];
			} elseif ( $row_bucket === $current_bucket ) {
				$by_region[ $key ]['matched_hierarchy_rank'] = max( (int) ( $by_region[ $key ]['matched_hierarchy_rank'] ?? 0 ), (int) $row['score']['matched_hierarchy_rank'] );
			} else {
				$by_region[ $key ]['bucket'] = $current_bucket;
			}
			$by_region[ $key ]['group_strength'] = max( (int) ( $by_region[ $key ]['group_strength'] ?? 0 ), (int) $row['score']['group_strength'] );
			$by_region[ $key ]['score'] = max( (int) ( $by_region[ $key ]['score'] ?? 0 ), (int) $row['score']['total'] );
		}
		$effective_region_limit = 1 === count( $by_region ) ? min( $limit, $region_limit * 3 ) : $region_limit;

		uasort(
			$by_region,
			static fn( array $a, array $b ): int => (int) $a['bucket'] <=> (int) $b['bucket']
				?: (int) $b['matched_hierarchy_rank'] <=> (int) $a['matched_hierarchy_rank']
				?: strcmp( (string) $a['sort'], (string) $b['sort'] )
				?: (int) $b['score'] <=> (int) $a['score']
		);

		$groups = array();
		$shown_total = 0;
		$has_more_total = false;
		foreach ( $by_region as $key => $group ) {
			if ( $shown_total >= $limit ) {
				$has_more_total = true;
				break;
			}
			$group_rows = $group['rows'];
			usort( $group_rows, fn( array $a, array $b ): int => $this->compare_scored_locations( $a, $b ) );
			$rows = array_slice( $group_rows, 0, '' !== $force_region_code ? $limit : $effective_region_limit );
			$available_slots = $limit - $shown_total;
			if ( count( $rows ) > $available_slots ) {
				$has_more_total = true;
			}
			$rows = array_slice( $rows, 0, $available_slots );
			$shown_total += count( $rows );
			$group_has_more = count( $group_rows ) > count( $rows );
			$groups[] = array(
				'region_key'       => (string) $key,
				'region_label'     => (string) $group['label'],
				'region_sort_name' => (string) $group['sort'],
				'total_in_region'  => count( $group['rows'] ),
				'shown_count'      => count( $rows ),
				'has_more'         => '' === $force_region_code && $group_has_more,
				'expand_query'     => (string) $group['label'],
				'items'            => array_map( static fn( array $row ): Location => $row['location'], $rows ),
			);
		}

		return array(
			'groups'         => $groups,
			'shown_total'    => $shown_total,
			'has_more_total' => $has_more_total,
		);
	}

	private function formatter(): LocationDisplayNameFormatter {
		$rules = function_exists( 'get_option' ) ? get_option( 'wdc_location_type_display_rules', array() ) : array();
		return LocationDisplayNameFormatter::from_rules( is_array( $rules ) ? $rules : array() );
	}

	private function parser(): CheckoutLocationSearchParser {
		$rules = function_exists( 'get_option' ) ? get_option( 'wdc_location_type_display_rules', array() ) : array();
		return new CheckoutLocationSearchParser( is_array( $rules ) ? $rules : array() );
	}
}
