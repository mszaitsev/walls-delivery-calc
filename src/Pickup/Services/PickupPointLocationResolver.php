<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Services;

use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class PickupPointLocationResolver {
	public function __construct( private LocationRepository $locations ) {
	}

	/**
	 * @param array<string,mixed> $point
	 * @param array<string,mixed> $checkout_context
	 * @return array{requires_location_change:bool,location:array<string,mixed>|null,message:string}
	 */
	public function resolve( array $point, array $checkout_context = array() ): array {
		$location = $this->location_for_point( $point );
		if ( ! $location instanceof Location ) {
			return array(
				'requires_location_change' => false,
				'location' => null,
				'message' => 'Pickup point location was not found in local locations table.',
			);
		}

		$location_data = $this->location_payload( $location );

		return array(
			'requires_location_change' => $this->is_different_location( $checkout_context, $point, $location_data ),
			'location' => $location_data,
			'message' => '',
		);
	}

	/**
	 * @param array<string,mixed> $point
	 */
	private function location_for_point( array $point ): ?Location {
		$location_id = (int) ( $point['location_id'] ?? 0 );
		if ( $location_id > 0 ) {
			$location = $this->locations->find_by_id( $location_id );
			if ( $location instanceof Location ) {
				return $location;
			}
		}

		$point_fias = $this->guid( $point['fias_location_guid'] ?? $point['fias_id'] ?? '' );
		if ( '' !== $point_fias ) {
			$location = $this->locations->find_by_fias_id( $point_fias );
			if ( $location instanceof Location ) {
				return $location;
			}
		}

		$postcode = $this->postcode( $point['postal_code'] ?? $point['postcode'] ?? '' );
		$city = $this->text( $point['city'] ?? $point['city_name'] ?? '' );
		$region = $this->text( $point['region'] ?? $point['region_name'] ?? '' );

		if ( '' !== $postcode ) {
			$location = $this->locations->find_first_by_postal_code( $postcode );
			if ( $location instanceof Location && $this->location_matches_city_region( $location, $city, $region ) ) {
				return $location;
			}
		}

		$tokens = array_values( array_filter( array( $city, $region ) ) );
		if ( array() !== $tokens ) {
			$candidates = $this->locations->search_by_tokens( $tokens, 50, true, '', 'RU' );
			$best = $this->best_candidate( $candidates, $postcode, $city, $region );
			if ( $best instanceof Location ) {
				return $best;
			}
		}

		return '' !== $postcode ? $this->locations->find_first_by_postal_code( $postcode ) : null;
	}

	/**
	 * @param array<int,Location> $candidates
	 */
	private function best_candidate( array $candidates, string $postcode, string $city, string $region ): ?Location {
		$best = null;
		$best_score = -1;
		foreach ( $candidates as $candidate ) {
			$score = 0;
			if ( '' !== $postcode && $postcode === $this->postcode( $candidate->postal_code ) ) {
				$score += 4;
			}
			if ( '' !== $city && $this->same_normalized( $city, $candidate->resolved_place_name() . ' ' . $candidate->city_name . ' ' . $candidate->display_name ) ) {
				$score += 3;
			}
			if ( '' !== $region && $this->same_normalized( $region, $candidate->region_name ) ) {
				$score += 2;
			}
			if ( $score > $best_score ) {
				$best = $candidate;
				$best_score = $score;
			}
		}

		return $best_score > 0 ? $best : null;
	}

	private function location_matches_city_region( Location $location, string $city, string $region ): bool {
		if ( '' === $city && '' === $region ) {
			return true;
		}
		if ( '' !== $city && ! $this->same_normalized( $city, $location->resolved_place_name() . ' ' . $location->city_name . ' ' . $location->display_name ) ) {
			return false;
		}
		if ( '' !== $region && ! $this->same_normalized( $region, $location->region_name ) ) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<string,mixed> $checkout_context
	 * @param array<string,mixed> $point
	 * @param array<string,mixed> $location
	 */
	private function is_different_location( array $checkout_context, array $point, array $location ): bool {
		$checkout_fias = $this->guid( $checkout_context['fias_id'] ?? $checkout_context['location_fias_id'] ?? '' );
		$point_fias = $this->guid( $point['fias_location_guid'] ?? $point['fias_id'] ?? '' );
		if ( '' !== $checkout_fias && '' !== $point_fias ) {
			return $checkout_fias !== $point_fias;
		}

		$checkout_region = $this->normalized( (string) ( $checkout_context['region_name'] ?? $checkout_context['region'] ?? '' ) );
		$checkout_city = $this->normalized( (string) ( $checkout_context['city_name'] ?? $checkout_context['city'] ?? $checkout_context['display_name'] ?? '' ) );
		$point_region = $this->normalized( (string) ( $point['region'] ?? $point['region_name'] ?? $location['region_name'] ?? '' ) );
		$point_city = $this->normalized( (string) ( $point['city'] ?? $point['city_name'] ?? $location['city_name'] ?? $location['display_name'] ?? '' ) );
		if ( '' !== $checkout_region && '' !== $checkout_city && '' !== $point_region && '' !== $point_city ) {
			return $checkout_region !== $point_region || $checkout_city !== $point_city;
		}

		$checkout_postcode = $this->postcode( $checkout_context['postcode'] ?? $checkout_context['postal_code'] ?? '' );
		$point_postcode = $this->postcode( $point['postal_code'] ?? $point['postcode'] ?? $location['postal_code'] ?? '' );

		return '' !== $checkout_postcode && '' !== $point_postcode && $checkout_postcode !== $point_postcode;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function location_payload( Location $location ): array {
		return array(
			'id' => $location->id,
			'location_id' => $location->id,
			'fias_id' => $location->fias_id,
			'country_code' => '' !== $location->country_code ? $location->country_code : 'RU',
			'display_name' => $location->resolved_display_name(),
			'region_name' => $location->region_name,
			'region_code' => $location->region_code,
			'city_name' => '' !== $location->resolved_place_name() ? $location->resolved_place_name() : $location->city_name,
			'postal_code' => $location->postal_code,
			'postcode' => $location->postal_code,
			'lat' => $location->latitude,
			'lng' => $location->longitude,
		);
	}

	private function same_normalized( string $needle, string $haystack ): bool {
		$needle = $this->normalized( $needle );
		$haystack = $this->normalized( $haystack );

		return '' !== $needle && '' !== $haystack && ( $needle === $haystack || str_contains( $haystack, $needle ) );
	}

	private function text( mixed $value ): string {
		return trim( (string) $value );
	}

	private function guid( mixed $value ): string {
		return strtolower( preg_replace( '/[^a-f0-9]/i', '', (string) $value ) ?? '' );
	}

	private function postcode( mixed $value ): string {
		return preg_replace( '/\D+/', '', (string) $value ) ?? '';
	}

	private function normalized( string $value ): string {
		$value = str_replace( array( 'ё', 'Ё' ), array( 'е', 'Е' ), $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/\b(г|город|п|поселок|посёлок|с|село|д|деревня|респ|республика|обл|область|край|ао)\b\.?/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/[^a-zа-я0-9]+/u', ' ', $value ) ?? $value;

		return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
	}
}
