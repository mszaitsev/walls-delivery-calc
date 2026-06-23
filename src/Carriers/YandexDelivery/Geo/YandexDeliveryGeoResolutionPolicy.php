<?php
/**
 * Resolution policy for scored Yandex geo candidates.
 *
 * @package WallsShop\WDC\Carriers\YandexDelivery\Geo
 */

declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

use function defined;
use function is_array;
use function is_scalar;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class YandexDeliveryGeoResolutionPolicy {
	private const PRIMARY_CONFIDENCE_THRESHOLD = 95.0;
	private const PRIMARY_SECOND_GAP = 15.0;

	/**
	 * @param array<int,array<string,mixed>> $candidates
	 * @return array{resolution:string,primary_geo_id:int|null,reason:string,confidence:float}
	 */
	public function resolve( array $candidates ): array {
		$candidates = array_values( array_filter( $candidates, static fn ( mixed $candidate ): bool => is_array( $candidate ) ) );

		if ( array() === $candidates ) {
			return $this->decision( YandexDeliveryGeoMappingStatus::NOT_FOUND, null, 'no_candidates', 0.0 );
		}

		usort( $candidates, static fn ( array $left, array $right ): int => (float) ( $right['confidence'] ?? 0 ) <=> (float) ( $left['confidence'] ?? 0 ) );

		$best = $candidates[0];
		$best_confidence = (float) ( $best['confidence'] ?? 0 );
		$second_confidence = isset( $candidates[1] ) ? (float) ( $candidates[1]['confidence'] ?? 0 ) : null;
		$primary_geo_id = isset( $best['yandex_geo_id'] ) && is_numeric( $best['yandex_geo_id'] ) && (int) $best['yandex_geo_id'] > 0 ? (int) $best['yandex_geo_id'] : null;

		if ( null !== $primary_geo_id && $best_confidence >= self::PRIMARY_CONFIDENCE_THRESHOLD && ( null === $second_confidence || $best_confidence - $second_confidence >= self::PRIMARY_SECOND_GAP ) ) {
			return $this->decision( YandexDeliveryGeoMappingStatus::MAPPED, $primary_geo_id, 'confident_primary', $best_confidence );
		}

		if ( $this->has_signal( $candidates, 'locality_exact' ) ) {
			return $this->decision( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, null, 'locality_exact_without_confident_primary', $best_confidence );
		}

		if ( $this->has_close_coordinate_candidate( $candidates ) ) {
			return $this->decision( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, null, 'coordinate_candidate_requires_review', $best_confidence );
		}

		if ( $this->has_signal( $candidates, 'title_token' ) || $this->has_signal( $candidates, 'weak_substring' ) ) {
			return $this->decision( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, null, 'weak_textual_candidate', $best_confidence );
		}

		if ( $this->has_region_district_context_candidate( $candidates ) ) {
			return $this->decision( YandexDeliveryGeoMappingStatus::NEEDS_REVIEW, null, 'region_district_context_without_locality', $best_confidence );
		}

		return $this->decision( YandexDeliveryGeoMappingStatus::NOT_FOUND, null, 'no_relevant_candidates', $best_confidence );
	}

	/**
	 * Future coordinate fallback will be connected here. It should only promote a
	 * candidate from not_found to needs_review when saved WDC coordinates and
	 * Yandex pickup-point coordinates show that a low-confidence candidate is
	 * geographically close enough for manual/coordinate review.
	 *
	 * @param array<int,array<string,mixed>> $candidates
	 */
	private function has_close_coordinate_candidate( array $candidates ): bool {
		return false;
	}

	/** @param array<int,array<string,mixed>> $candidates */
	private function has_signal( array $candidates, string $signal ): bool {
		foreach ( $candidates as $candidate ) {
			if ( in_array( $signal, $this->matched_by( $candidate ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<int,array<string,mixed>> $candidates */
	private function has_region_district_context_candidate( array $candidates ): bool {
		foreach ( $candidates as $candidate ) {
			$matched_by = $this->matched_by( $candidate );
			if ( in_array( 'region_match', $matched_by, true ) && in_array( 'district_match', $matched_by, true ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,mixed> $candidate @return array<int,string> */
	private function matched_by( array $candidate ): array {
		$scoring = is_array( $candidate['scoring'] ?? null ) ? $candidate['scoring'] : array();
		if ( ! is_array( $scoring['matched_by'] ?? null ) ) {
			$raw = $this->raw_json( $candidate );
			$scoring = is_array( $raw['scoring'] ?? null ) ? $raw['scoring'] : array();
		}

		if ( ! is_array( $scoring['matched_by'] ?? null ) ) {
			return array();
		}

		$matched_by = array();
		foreach ( $scoring['matched_by'] as $value ) {
			if ( is_scalar( $value ) ) {
				$matched_by[] = (string) $value;
			}
		}

		return $matched_by;
	}

	/** @param array<string,mixed> $candidate @return array<string,mixed> */
	private function raw_json( array $candidate ): array {
		$raw_json = (string) ( $candidate['raw_json'] ?? '' );
		if ( '' === $raw_json ) {
			return array();
		}

		$decoded = json_decode( $raw_json, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/** @return array{resolution:string,primary_geo_id:int|null,reason:string,confidence:float} */
	private function decision( string $resolution, ?int $primary_geo_id, string $reason, float $confidence ): array {
		return array(
			'resolution' => YandexDeliveryGeoMappingStatus::normalize( $resolution ),
			'primary_geo_id' => $primary_geo_id,
			'reason' => $reason,
			'confidence' => max( 0.0, min( 100.0, $confidence ) ),
		);
	}
}