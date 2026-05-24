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
		$tokens = $this->tokens( $query );
		$limit = max( 10, min( 300, $limit ) );
		$region_limit = max( 3, min( 50, $region_limit ) );
		if ( array() === $tokens ) {
			return array( 'items' => array(), 'groups' => array(), 'total' => 0 );
		}

		$locations = $this->search_service->search( $query, $limit );
		$meta = $this->search_service->last_search_meta();
		$score_tokens = ! empty( $meta['correction_used'] ) && ! empty( $meta['corrected_query'] ) ? $this->tokens( (string) $meta['corrected_query'] ) : $tokens;
		$score_tokens = array() !== $score_tokens ? $score_tokens : $tokens;
		if ( '' !== trim( $force_region_code ) ) {
			$locations = array_values( array_filter( $locations, static fn( Location $location ): bool => $location->region_code === $force_region_code ) );
		}
		$locations = array_merge( $locations, $this->search_service->search_by_tokens( $tokens, $limit * 4, true, $force_region_code ) );
		if ( count( $locations ) < $limit ) {
			$locations = array_merge( $locations, $this->search_service->search_by_tokens( $tokens, $limit * 4, false, $force_region_code ) );
		}
		$locations = $this->unique_locations( $locations );
		$scored = array_map(
			fn( Location $location ): array => array(
				'location' => $location,
				'score'    => $this->picker_score( $location, $score_tokens ),
			),
			$locations
		);
		$scored = array_values( array_filter( $scored, static fn( array $row ): bool => (int) $row['score']['matched_tokens'] > 0 ) );
		usort(
			$scored,
			fn( array $a, array $b ): int => $this->compare_scored_locations( $a, $b )
		);

		$items = array_map( static fn( array $row ): Location => $row['location'], array_slice( $scored, 0, $limit ) );
		return array(
			'items'  => $items,
			'groups' => $this->group_picker_items( $scored, $limit, $region_limit, $force_region_code ),
			'total'  => count( $scored ),
		);
	}

	/**
	 * @return array{status:string,location:?Location}
	 */
	public function resolve_checkout_fields( string $region_text, string $city_text ): array {
		$query = trim( $region_text . ' ' . $city_text );
		$tokens = $this->tokens( $query );
		if ( array() === $tokens ) {
			return array( 'status' => 'not_found', 'location' => null );
		}

		$result = $this->search_for_picker( $query, 20, 20 );
		$candidates = array_values(
			array_filter(
				$result['items'],
				fn( Location $location ): bool => $this->picker_score( $location, $tokens )['matched_tokens'] === count( $tokens )
			)
		);
		if ( array() === $candidates ) {
			return array( 'status' => 'not_found', 'location' => null );
		}

		usort( $candidates, fn( Location $a, Location $b ): int => $this->picker_score( $b, $tokens )['total'] <=> $this->picker_score( $a, $tokens )['total'] ?: strcmp( $a->display_name, $b->display_name ) );
		$best = $candidates[0];
		$best_score = $this->picker_score( $best, $tokens )['total'];
		$ties = array_filter( $candidates, fn( Location $location ): bool => $this->picker_score( $location, $tokens )['total'] === $best_score );

		return 1 === count( $ties ) ? array( 'status' => 'resolved', 'location' => $best ) : array( 'status' => 'ambiguous', 'location' => null );
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
	 * @param array<int,string> $tokens
	 * @return array{total:int,matched_tokens:int,all_tokens:bool,quality:int,exact_place:bool}
	 */
	private function picker_score( Location $location, array $tokens ): array {
		$formatter = $this->formatter();
		$fields = $this->search_fields( $location, $formatter );
		$matched = 0;
		$total = 0;
		$quality = 0;
		foreach ( $tokens as $token ) {
			$token_quality = 0;
			foreach ( $fields as $field ) {
				$text = $field['text'];
				if ( '' === $text ) {
					continue;
				}
				if ( $text === $token ) {
					$token_quality = max( $token_quality, (int) $field['weight'] + 300 );
				} elseif ( str_starts_with( $text, $token ) ) {
					$token_quality = max( $token_quality, (int) $field['weight'] + 180 );
				} elseif ( str_contains( $text, $token ) ) {
					$token_quality = max( $token_quality, (int) $field['weight'] + 60 );
				}
			}
			if ( $token_quality > 0 ) {
				++$matched;
				$total += $token_quality;
				$quality = max( $quality, $token_quality );
			}
		}

		$place = $this->normalize( $location->resolved_place_name() );
		$query = implode( ' ', $tokens );
		$exact_place = '' !== $place && in_array( $place, $tokens, true );
		$place_bonus = $exact_place ? 2500 : 0;
		foreach ( $tokens as $token ) {
			if ( '' !== $place && str_starts_with( $place, $token ) ) {
				$place_bonus = max( $place_bonus, 900 );
			}
		}
		return array(
			'total'          => $total + ( $matched === count( $tokens ) ? 10000 : 0 ) + ( $place === $query ? 3000 : 0 ) + $place_bonus,
			'matched_tokens' => $matched,
			'all_tokens'     => $matched === count( $tokens ),
			'quality'        => $quality,
			'exact_place'    => $exact_place,
		);
	}

	/**
	 * @return array<int,array{text:string,weight:int}>
	 */
	private function search_fields( Location $location, LocationDisplayNameFormatter $formatter ): array {
		$fields = array(
			array( 'text' => $location->resolved_place_name(), 'weight' => 900 ),
			array( 'text' => $location->city_name, 'weight' => 650 ),
			array( 'text' => $location->district_name, 'weight' => 520 ),
			array( 'text' => $location->region_name, 'weight' => 420 ),
			array( 'text' => $location->display_name, 'weight' => 260 ),
			array( 'text' => $location->get_searchable_text(), 'weight' => 120 ),
			array( 'text' => $location->fias_id, 'weight' => 1000 ),
			array( 'text' => $location->kladr_id, 'weight' => 1000 ),
			array( 'text' => (string) $location->gar_object_id, 'weight' => 1000 ),
			array( 'text' => $location->region_type, 'weight' => 180 ),
			array( 'text' => $location->district_type, 'weight' => 180 ),
			array( 'text' => $location->city_type, 'weight' => 180 ),
			array( 'text' => $location->resolved_place_type(), 'weight' => 180 ),
			array( 'text' => 'р-н' === $location->district_type ? 'район' : '', 'weight' => 180 ),
			array( 'text' => 'район' === $location->district_type ? 'р-н' : '', 'weight' => 180 ),
		);
		foreach ( array( 'region' => $location->region_type, 'city' => $location->city_type, 'place' => $location->resolved_place_type() ) as $scope => $type ) {
			foreach ( $formatter->display_variants( $scope, $type ) as $variant ) {
				$fields[] = array( 'text' => $variant, 'weight' => 180 );
			}
		}
		return array_map(
			fn( array $field ): array => array( 'text' => $this->normalize( (string) $field['text'] ), 'weight' => (int) $field['weight'] ),
			$fields
		);
	}

	private function compare_scored_locations( array $a, array $b ): int {
		return (int) $b['score']['total'] <=> (int) $a['score']['total']
			?: (int) $b['score']['matched_tokens'] <=> (int) $a['score']['matched_tokens']
			?: strcmp( $a['location']->display_name, $b['location']->display_name );
	}

	/**
	 * @param array<int,array{location:Location,score:array<string,mixed>}> $scored
	 * @return array<int,array<string,mixed>>
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
			$by_region[ $key ]['exact'] = ( $by_region[ $key ]['exact'] ?? false ) || ! empty( $row['score']['exact_place'] );
			$by_region[ $key ]['score'] = max( (int) ( $by_region[ $key ]['score'] ?? 0 ), (int) $row['score']['total'] );
		}

		uasort(
			$by_region,
			static fn( array $a, array $b ): int => ( (bool) $b['exact'] <=> (bool) $a['exact'] )
				?: ( ! empty( $a['exact'] ) && ! empty( $b['exact'] ) ? strcmp( (string) $a['sort'], (string) $b['sort'] ) : ( (int) $b['score'] <=> (int) $a['score'] ?: strcmp( (string) $a['sort'], (string) $b['sort'] ) ) )
		);

		$groups = array();
		$shown_total = 0;
		foreach ( $by_region as $key => $group ) {
			if ( $shown_total >= $limit ) {
				break;
			}
			$rows = array_slice( $group['rows'], 0, '' !== $force_region_code ? $limit : $region_limit );
			$rows = array_slice( $rows, 0, $limit - $shown_total );
			$shown_total += count( $rows );
			$groups[] = array(
				'region_key'       => (string) $key,
				'region_label'     => (string) $group['label'],
				'region_sort_name' => (string) $group['sort'],
				'total_in_region'  => count( $group['rows'] ),
				'shown_count'      => count( $rows ),
				'has_more'         => '' === $force_region_code && count( $group['rows'] ) > count( $rows ),
				'expand_query'     => (string) $group['label'],
				'items'            => array_map( static fn( array $row ): Location => $row['location'], $rows ),
			);
		}

		return $groups;
	}

	private function formatter(): LocationDisplayNameFormatter {
		$rules = function_exists( 'get_option' ) ? get_option( 'wdc_location_type_display_rules', array() ) : array();
		return LocationDisplayNameFormatter::from_rules( is_array( $rules ) ? $rules : array() );
	}
}
